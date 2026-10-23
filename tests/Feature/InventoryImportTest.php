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

    private function row(string $rooftopId, string $vin, array $overrides = []): array
    {
        return $overrides + [
            'rooftop_id' => $rooftopId, 'dealer_name' => 'CHILLIWACK MITSUBISHI', 'dealer_email' => '',
            'dealer_phone' => '+1 672-784-5040', 'dealer_address' => '45510 Yale Rd', 'dealer_city' => 'Chilliwack',
            'dealer_region_code' => 'BC', 'dealer_postal_code' => 'V2P 2M9', 'dealer_latitude' => '49.151131',
            'dealer_longitude' => '-121.963743', 'vin' => $vin, 'stockNumber' => 'S1', 'condition' => 'USED',
            'year' => '2022', 'make' => 'Mitsubishi', 'model' => 'Outlander', 'trim' => 'ES', 'price' => '29999',
            'mileage' => '30000', 'body_type' => 'SUV', 'exterior_color' => 'Blue', 'transmission' => 'Automatic',
            'drivetrain' => 'AWD', 'fuel_type' => 'Gasoline', 'photos' => '', 'certified' => 'FALSE',
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
