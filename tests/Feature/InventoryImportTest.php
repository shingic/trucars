<?php

namespace Tests\Feature;

use App\Models\Dealer;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesDealers;
use Tests\TestCase;

class InventoryImportTest extends TestCase
{
    use CreatesDealers;
    use RefreshDatabase;

    private const HEADER = ['rooftop_id', 'dealer_name', 'dealer_email', 'dealer_phone', 'dealer_address', 'dealer_city', 'dealer_region_code', 'dealer_postal_code', 'dealer_latitude', 'dealer_longitude', 'vin', 'stockNumber', 'condition', 'year', 'make', 'model', 'trim', 'price', 'mileage', 'body_type', 'exterior_color', 'transmission', 'drivetrain', 'fuel_type', 'photos', 'certified'];

    public function test_import_creates_a_draft_dealer_keyed_by_rooftop_with_its_feed_address(): void
    {
        $this->import([$this->row('ROOF-A', 'VIN00000000000001')])->assertSuccessful();

        $dealer = Dealer::sole();
        $this->assertSame('ROOF-A', $dealer->feed_rooftop_id);
        $this->assertSame('45510 Yale Rd', $dealer->street_address);
        $this->assertSame('Chilliwack', $dealer->city);
        $this->assertSame('BC', $dealer->province);
        $this->assertSame('V2P 2M9', $dealer->postal_code);
        $this->assertSame('CA', $dealer->country);
        $this->assertEqualsWithDelta(49.151131, $dealer->latitude, 0.000001);
        $this->assertSame('America/Vancouver', $dealer->timezone);
        $this->assertSame(Dealer::STATUS_DRAFT, $dealer->status);
        $this->assertSame(1, $dealer->vehicles()->count());
    }

    public function test_reimport_updates_the_same_dealer_without_overwriting_admin_corrections(): void
    {
        $this->import([$this->row('ROOF-A', 'VIN00000000000001')]);
        Dealer::sole()->update(['street_address' => '45510 Yale Road, Bldg B', 'timezone' => 'America/Vancouver']);

        $this->import([$this->row('ROOF-A', 'VIN00000000000001'), $this->row('ROOF-A', 'VIN00000000000002')])->assertSuccessful();

        $dealer = Dealer::sole();
        $this->assertSame('45510 Yale Road, Bldg B', $dealer->street_address);
        $this->assertSame(2, $dealer->vehicles()->count());
    }

    public function test_reimport_keeps_live_cars_live_and_new_cars_unpublished(): void
    {
        $this->import([$this->row('ROOF-A', 'VIN00000000000001')]);
        Vehicle::where('vin', 'VIN00000000000001')->update(['is_published' => true]);

        $this->import([$this->row('ROOF-A', 'VIN00000000000001', ['price' => '27999']), $this->row('ROOF-A', 'VIN00000000000002')])
            ->expectsOutputToContain('(1 new, unpublished until reviewed)')
            ->assertSuccessful();

        $this->assertTrue(Vehicle::where('vin', 'VIN00000000000001')->sole()->is_published);
        $this->assertSame(2799900, Vehicle::where('vin', 'VIN00000000000001')->sole()->price_in_cents);
        $this->assertFalse(Vehicle::where('vin', 'VIN00000000000002')->sole()->is_published);
    }

    public function test_cars_no_longer_in_the_feed_are_unpublished_not_deleted(): void
    {
        $this->import([$this->row('ROOF-A', 'VIN00000000000001'), $this->row('ROOF-A', 'VIN00000000000002')]);
        Vehicle::query()->update(['is_published' => true]);

        $this->import([$this->row('ROOF-A', 'VIN00000000000001')])
            ->expectsOutputToContain('Unpublished (no longer in the feed): 1')
            ->assertSuccessful();

        $this->assertTrue(Vehicle::where('vin', 'VIN00000000000001')->sole()->is_published);
        $this->assertFalse(Vehicle::where('vin', 'VIN00000000000002')->sole()->is_published);
        $this->assertSame(2, Vehicle::count());
    }

    public function test_keep_missing_leaves_absent_cars_alone(): void
    {
        $this->import([$this->row('ROOF-A', 'VIN00000000000001'), $this->row('ROOF-A', 'VIN00000000000002')]);
        Vehicle::query()->update(['is_published' => true]);

        $this->import([$this->row('ROOF-A', 'VIN00000000000001')], ['--keep-missing' => true])->assertSuccessful();

        $this->assertTrue(Vehicle::where('vin', 'VIN00000000000002')->sole()->is_published);
    }

    public function test_a_car_in_the_feed_but_skipped_is_not_treated_as_sold(): void
    {
        $this->import([$this->row('ROOF-A', 'VIN00000000000001')]);
        Vehicle::query()->update(['is_published' => true]);

        // Price missing this time — skipped, but the dealer still has it.
        $this->import([$this->row('ROOF-A', 'VIN00000000000001', ['price' => ''])])->assertSuccessful();

        $this->assertTrue(Vehicle::where('vin', 'VIN00000000000001')->sole()->is_published);
    }

    public function test_only_certified_pre_owned_cars_are_imported(): void
    {
        $this->import([
            $this->row('ROOF-A', 'VIN0000000000CPO1'),
            $this->row('ROOF-A', 'VIN0000000000CPO2', ['condition' => 'CPO', 'certified' => '']),
            $this->row('ROOF-A', 'VIN000000000USED1', ['certified' => 'FALSE']),
            $this->row('ROOF-A', 'VIN0000000000NEW1', ['condition' => 'NEW', 'certified' => 'TRUE']),
        ])->assertSuccessful()->expectsOutputToContain('Skipped (not certified pre-owned): 2');

        $this->assertEqualsCanonicalizing(['VIN0000000000CPO1', 'VIN0000000000CPO2'], Vehicle::pluck('vin')->all());
        $this->assertSame(['USED'], Vehicle::distinct()->pluck('condition')->all());
    }

    public function test_a_new_car_sent_as_used_and_certified_is_turned_away(): void
    {
        $this->import([
            $this->row('ROOF-A', 'VIN0000000000CPO1', ['mileage' => '6201']),
            $this->row('ROOF-A', 'VIN0000000000NEW1', ['condition' => 'Used', 'certified' => 'true', 'mileage' => '90']),
        ])->assertSuccessful()->expectsOutputToContain('Skipped (new car sent as used, under 500 km): 1');

        $this->assertSame(['VIN0000000000CPO1'], Vehicle::pluck('vin')->all());
    }

    public function test_a_listed_new_car_comes_off_once_the_rule_sees_it(): void
    {
        $this->import([$this->row('ROOF-A', 'VIN00000000000001'), $this->row('ROOF-A', 'VIN00000000000002')]);
        Vehicle::query()->update(['is_published' => true]);

        $this->import([$this->row('ROOF-A', 'VIN00000000000001'), $this->row('ROOF-A', 'VIN00000000000002', ['mileage' => '90'])])->assertSuccessful();

        $this->assertTrue(Vehicle::where('vin', 'VIN00000000000001')->sole()->is_published);
        $this->assertFalse(Vehicle::where('vin', 'VIN00000000000002')->sole()->is_published);
    }

    public function test_a_listed_car_the_feed_stops_calling_certified_comes_off_the_marketplace(): void
    {
        $this->import([$this->row('ROOF-A', 'VIN00000000000001')]);
        Vehicle::query()->update(['is_published' => true]);

        $this->import([$this->row('ROOF-A', 'VIN00000000000001', ['certified' => 'FALSE'])])->assertSuccessful();

        $this->assertFalse(Vehicle::where('vin', 'VIN00000000000001')->sole()->is_published);
    }

    public function test_two_rooftops_with_the_same_name_stay_separate_dealers(): void
    {
        $this->import([$this->row('ROOF-A', 'VIN00000000000001', ['dealer_name' => 'DOWNTOWN HONDA'])]);
        $this->import([$this->row('ROOF-B', 'VIN00000000000002', ['dealer_name' => 'DOWNTOWN HONDA', 'dealer_city' => 'Kelowna', 'dealer_postal_code' => 'V1Y 1A1'])])->assertSuccessful();

        $this->assertSame(2, Dealer::where('name', 'DOWNTOWN HONDA')->count());
        $this->assertSame(['Chilliwack', 'Kelowna'], Dealer::orderBy('id')->pluck('city')->all());
    }

    public function test_a_legacy_dealer_with_the_same_name_is_never_merged_silently(): void
    {
        $legacy = Dealer::create(['name' => 'CHILLIWACK MITSUBISHI', 'city' => 'Chilliwack', 'status' => Dealer::STATUS_ACTIVE]);

        $this->import([$this->row('ROOF-A', 'VIN00000000000001')])->assertFailed();
        $this->assertSame(1, Dealer::count());
        $this->assertNull($legacy->fresh()->feed_rooftop_id);

        // Confirmed as the same rooftop: link it explicitly. Blanks fill from the feed.
        $this->import([$this->row('ROOF-A', 'VIN00000000000001')], ['--dealer' => $legacy->id])->assertSuccessful();
        $legacy->refresh();
        $this->assertSame('ROOF-A', $legacy->feed_rooftop_id);
        $this->assertSame('45510 Yale Rd', $legacy->street_address);
        $this->assertSame(Dealer::STATUS_ACTIVE, $legacy->status);
        $this->assertSame(1, Dealer::count());
    }

    public function test_a_same_named_new_rooftop_can_be_created_on_purpose(): void
    {
        Dealer::create(['name' => 'CHILLIWACK MITSUBISHI', 'city' => 'Chilliwack']);

        $this->import([$this->row('ROOF-A', 'VIN00000000000001')], ['--new-dealer' => true])->assertSuccessful();

        $this->assertSame(2, Dealer::count());
    }

    public function test_a_vin_on_another_dealer_is_not_moved(): void
    {
        $other = $this->makeDealer(['name' => 'Other Rooftop']);
        $other->vehicles()->create([
            'vin' => 'VIN00000000000001', 'model_year' => 2022, 'make' => 'Mitsubishi', 'model' => 'RVR',
            'kilometres' => 20000, 'price_in_cents' => 2500000, 'is_published' => true,
        ]);

        $this->import([$this->row('ROOF-A', 'VIN00000000000001')])
            ->expectsOutputToContain('Skipped (VIN belongs to another rooftop): 1')
            ->assertSuccessful();

        $this->assertSame($other->id, Vehicle::where('vin', 'VIN00000000000001')->value('dealer_id'));
    }

    public function test_rows_from_a_different_rooftop_in_the_same_file_are_skipped(): void
    {
        $this->import([$this->row('ROOF-A', 'VIN00000000000001'), $this->row('ROOF-B', 'VIN00000000000002')])
            ->expectsOutputToContain('Skipped (another rooftop in the file): 1')
            ->assertSuccessful();

        $this->assertSame(1, Vehicle::count());
    }

    public function test_double_encoded_feed_text_is_repaired(): void
    {
        $this->import([$this->row('ROOF-A', 'VIN00000000000001', ['drivetrain' => '4Ã—4', 'exterior_color' => 'BlÃ©', 'trim' => 'SE &amp; Tech'])])->assertSuccessful();

        $vehicle = Vehicle::sole();
        $this->assertSame('4×4', $vehicle->drivetrain);
        $this->assertSame('Blé', $vehicle->colour);
        $this->assertSame('SE & Tech', $vehicle->trim);
    }

    public function test_a_windows_1252_feed_is_read_as_utf8(): void
    {
        $this->import([$this->row('ROOF-A', 'VIN00000000000001', ['drivetrain' => '4×4'])], encode: fn ($csv) => mb_convert_encoding($csv, 'Windows-1252', 'UTF-8'))->assertSuccessful();

        $this->assertSame('4×4', Vehicle::sole()->drivetrain);
    }

    public function test_an_excel_unicode_text_export_is_read(): void
    {
        $this->import(
            [$this->row('ROOF-A', 'VIN00000000000001', ['drivetrain' => '4×4'])],
            encode: fn ($csv) => "\xFF\xFE" . mb_convert_encoding(str_replace(',', "\t", $csv), 'UTF-16LE', 'UTF-8'),
        )->assertSuccessful();

        $vehicle = Vehicle::sole();
        $this->assertSame('VIN00000000000001', $vehicle->vin);
        $this->assertSame('4×4', $vehicle->drivetrain);
    }

    public function test_text_saved_any_other_way_is_cleaned_too(): void
    {
        $vehicle = $this->makeDealer()->vehicles()->create([
            'vin' => 'VIN00000000000009', 'model_year' => 2022, 'make' => 'Mitsubishi', 'model' => 'Outlander',
            'kilometres' => 1000, 'price_in_cents' => 100000, 'condition' => 'USED', 'drivetrain' => '4Ã—4',
        ]);

        $this->assertSame('4×4', $vehicle->fresh()->drivetrain);
    }

    public function test_one_command_onboards_a_store_live_with_its_brand(): void
    {
        $this->import([
            $this->row('ROOF-N', 'VIN00000000000001', ['dealer_name' => 'Newmarket Nissan', 'make' => 'Nissan', 'dealer_latitude' => '', 'dealer_longitude' => '', 'photos' => $this->photoList('a'),
                'dealer_address' => '17385 Leslie St', 'dealer_city' => 'Newmarket', 'dealer_region_code' => 'ON', 'dealer_postal_code' => 'L3Y 9A3']),
            $this->row('ROOF-N', 'VIN00000000000002', ['dealer_name' => 'Newmarket Nissan', 'make' => 'Hyundai', 'photos' => $this->photoList('b'), 'dealer_city' => 'Newmarket', 'dealer_region_code' => 'ON', 'dealer_postal_code' => 'L3Y 9A3']),
        ], ['--brand' => 'nissan', '--activate' => true, '--publish' => true])
            ->expectsOutputToContain('Activated: Newmarket Nissan is live.')
            ->expectsOutputToContain('Published: 2 new cars')
            ->assertSuccessful();

        $dealer = Dealer::sole();
        $this->assertSame('Nissan', $dealer->franchise_make);
        $this->assertTrue($dealer->isActive());
        $this->assertSame('America/Toronto', $dealer->timezone);
        // No coordinates in the feed: placed from its postal area.
        $this->assertEqualsWithDelta(44.06, $dealer->latitude, 0.1);
        $this->assertSame(2, Vehicle::listed()->count());
        $this->assertCount(4, Vehicle::where('make', 'Nissan')->sole()->photos);
        $this->assertTrue(Vehicle::where('make', 'Nissan')->sole()->is_manufacturer_certified);
        $this->assertFalse(Vehicle::where('make', 'Hyundai')->sole()->is_manufacturer_certified);

        // A brand that doesn't match the dealer's is refused, not switched.
        $this->import([$this->row('ROOF-N', 'VIN00000000000001', ['dealer_name' => 'Newmarket Nissan'])], ['--brand' => 'Infiniti'])->assertFailed();
        $this->assertSame('Nissan', $dealer->fresh()->franchise_make);
    }

    public function test_a_car_without_photos_is_never_shown_and_comes_back_with_them(): void
    {
        $this->import([$this->row('ROOF-A', 'VIN00000000000001', ['photos' => ''])], ['--publish' => true])->assertSuccessful();
        Dealer::sole()->update(['status' => Dealer::STATUS_ACTIVE]);

        $car = Vehicle::sole();
        $this->assertTrue($car->is_published);
        $this->assertSame(0, Vehicle::listed()->count());
        $this->assertFalse($car->isListed());
        $this->get('/cars/' . $car->id)->assertNotFound();

        // One or two pictures is a placeholder or a banner, not a listing.
        $this->import([$this->row('ROOF-A', 'VIN00000000000001', ['photos' => 'https://media.test/1.webp|https://media.test/2.webp'])])->assertSuccessful();
        $this->assertSame(0, Vehicle::listed()->count());

        $this->import([$this->row('ROOF-A', 'VIN00000000000001', ['photos' => $this->photoList('a')])])->assertSuccessful();
        $this->assertSame(1, Vehicle::listed()->count());
    }

    public function test_promo_images_shared_between_cars_are_dropped(): void
    {
        $promo = 'https://media.edealer.ca/w_1920,h_1440,q_75,c_l,v1/inventory/AUTOTRADER-BADGE.webp';
        $promoSmall = 'https://media.edealer.ca/w_640,h_480,q_75,c_l,v1/inventory/AUTOTRADER-BADGE.webp';

        $this->import([
            $this->row('ROOF-A', 'VIN00000000000001', ['photos' => $this->photoList('a') . '|' . $promo]),
            $this->row('ROOF-A', 'VIN00000000000002', ['photos' => $this->photoList('b', 2) . '|' . $promoSmall]),
            // A new car carrying the same badge still counts as "another car".
            $this->row('ROOF-A', 'VIN00000000000003', ['condition' => 'NEW', 'certified' => 'FALSE', 'photos' => $promo]),
        ])->assertSuccessful();

        $this->assertSame($this->photoListArray('a'), Vehicle::where('vin', 'VIN00000000000001')->sole()->photos);
        // Two real photos once the badge is gone: too few to show.
        $this->assertCount(2, Vehicle::where('vin', 'VIN00000000000002')->sole()->photos);
        $this->assertFalse(Vehicle::where('vin', 'VIN00000000000002')->sole()->hasPhotos());
    }

    public function test_photos_named_the_same_in_each_cars_folder_are_kept(): void
    {
        $this->import([
            $this->row('ROOF-A', 'VIN00000000000001', ['photos' => 'https://cdn.test/VIN1/1.jpg|https://cdn.test/VIN1/2.jpg|https://cdn.test/VIN1/3.jpg|https://cdn.test/VIN1/4.jpg']),
            $this->row('ROOF-A', 'VIN00000000000002', ['photos' => 'https://cdn.test/VIN2/1.jpg|https://cdn.test/VIN2/2.jpg|https://cdn.test/VIN2/3.jpg|https://cdn.test/VIN2/4.jpg']),
        ])->assertSuccessful();

        $this->assertSame(4, Vehicle::where('vin', 'VIN00000000000002')->sole()->photo_count);
    }

    private function photoList(string $car, int $count = 4): string
    {
        return implode('|', $this->photoListArray($car, $count));
    }

    private function photoListArray(string $car, int $count = 4): array
    {
        return array_map(fn ($n) => "https://media.edealer.ca/w_1920,h_1440,q_75,c_l,v1/inventory/{$car}-{$n}.webp", range(1, $count));
    }

    private function row(string $rooftopId, string $vin, array $overrides = []): array
    {
        return $overrides + [
            'rooftop_id' => $rooftopId, 'dealer_name' => 'CHILLIWACK MITSUBISHI', 'dealer_email' => '',
            'dealer_phone' => '+1 672-784-5040', 'dealer_address' => '45510 Yale Rd', 'dealer_city' => 'Chilliwack',
            'dealer_region_code' => 'BC', 'dealer_postal_code' => 'V2P 2M9', 'dealer_latitude' => '49.151131',
            'dealer_longitude' => '-121.963743', 'vin' => $vin, 'stockNumber' => 'S1', 'condition' => 'USED',
            'year' => '2022', 'make' => 'Mitsubishi', 'model' => 'Outlander', 'trim' => 'ES', 'price' => '29999',
            'mileage' => '30000', 'body_type' => 'SUV', 'exterior_color' => 'Blue', 'transmission' => 'Automatic',
            'drivetrain' => 'AWD', 'fuel_type' => 'Gasoline', 'photos' => '', 'certified' => 'TRUE',
        ];
    }

    /** @param  ?callable(string): string  $encode  re-encodes the file, like a dealer's export would */
    private function import(array $rows, array $options = [], ?callable $encode = null)
    {
        $path = tempnam(sys_get_temp_dir(), 'feed') . '.csv';
        $handle = fopen($path, 'w');
        fputcsv($handle, self::HEADER);

        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn ($column) => $row[$column], self::HEADER));
        }

        fclose($handle);

        if ($encode) {
            file_put_contents($path, $encode(file_get_contents($path)));
        }

        return $this->artisan('vehicles:import', ['path' => $path] + $options);
    }
}
