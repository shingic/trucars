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
            ->expectsOutputToContain('Skipped (VIN belongs to another dealer): 1')
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

    private function import(array $rows, array $options = [])
    {
        $path = tempnam(sys_get_temp_dir(), 'feed') . '.csv';
        $handle = fopen($path, 'w');
        fputcsv($handle, self::HEADER);

        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn ($column) => $row[$column], self::HEADER));
        }

        fclose($handle);

        return $this->artisan('vehicles:import', ['path' => $path] + $options);
    }
}
