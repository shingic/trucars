<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Dealer;
use App\Models\DealerFee;
use App\Models\Vehicle;
use App\Support\CpoRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesDealers;
use Tests\TestCase;

class HomeNetImportTest extends TestCase
{
    use CreatesDealers;
    use RefreshDatabase;

    private const FILE = __DIR__ . '/../Fixtures/Trumo_TestMazda.csv';

    private function mazdaStore(bool $certifiesAtPickup = true): Dealer
    {
        $dealer = $this->makeDealer([
            'name' => 'Test Mazda', 'street_address' => '2525 Bowen Rd', 'city' => 'Nanaimo', 'postal_code' => 'V9T 3L2',
            'feed_rooftop_id' => 'homenet-testmazda', 'brand_id' => Brand::where('name', 'Mazda')->value('id'),
            'certifies_at_pickup' => $certifiesAtPickup, 'phone' => null,
        ]);
        $dealer->syncPrimaryRooftop();
        DealerFee::create(['dealer_id' => $dealer->id, 'label' => 'Documentation fee', 'kind' => DealerFee::KIND_INCLUDED, 'added_to_feed_price' => true, 'amount_in_cents' => 99500]);
        DealerFee::create(['dealer_id' => $dealer->id, 'label' => 'Freight', 'kind' => DealerFee::KIND_INCLUDED, 'added_to_feed_price' => false, 'amount_in_cents' => 200000]);

        return $dealer;
    }

    public function test_a_homenet_file_imports_the_certified_and_certifiable_cars_all_in(): void
    {
        $dealer = $this->mazdaStore();

        $this->artisan('vehicles:import', ['path' => self::FILE, '--publish' => true])->assertSuccessful();

        $cars = Vehicle::orderBy('vin')->get()->keyBy('stock_number');
        $this->assertSame(['M1', 'M2'], $cars->keys()->sort()->values()->all()); // not the 2018, the Toyota, the 90 km demo, the new car or the trailer

        $flagged = $cars['M1'];
        $this->assertSame([3000000, 3099500], [$flagged->feed_price_in_cents, $flagged->price_in_cents]); // + the $995 doc fee, not the freight
        $this->assertSame(['SUV', 'All Wheel Drive', 'Gasoline', 'Gray', 'Automatic', 30000], [$flagged->body_type, $flagged->drivetrain, $flagged->fuel_type, $flagged->colour, $flagged->transmission, $flagged->kilometres]);
        $this->assertTrue($flagged->is_manufacturer_certified);
        $this->assertFalse($flagged->certify_at_pickup);
        $this->assertCount(5, $flagged->photos);
        $this->assertTrue($flagged->is_published);

        $atPickup = $cars['M2'];
        $this->assertSame(['Sedan', 'Front Wheel Drive', 2299500], [$atPickup->body_type, $atPickup->drivetrain, $atPickup->price_in_cents]);
        $this->assertTrue($atPickup->is_manufacturer_certified);
        $this->assertTrue($atPickup->certify_at_pickup);
        $this->assertSame('Certified at pickup', $atPickup->certifiedBadge());

        // The placeholder phone in the feed isn't copied onto the store.
        $this->assertNull($dealer->fresh()->phone);
    }

    public function test_without_certify_at_pickup_only_flagged_cars_come_in(): void
    {
        $this->mazdaStore(certifiesAtPickup: false);

        $this->artisan('vehicles:import', ['path' => self::FILE, '--publish' => true])->assertSuccessful();

        $this->assertSame(['M1'], Vehicle::pluck('stock_number')->all());
    }

    public function test_a_fee_change_reprices_at_the_next_import(): void
    {
        $dealer = $this->mazdaStore();
        $this->artisan('vehicles:import', ['path' => self::FILE])->assertSuccessful();

        DealerFee::where('dealer_id', $dealer->id)->where('label', 'Documentation fee')->update(['amount_in_cents' => 69900]);
        $this->artisan('vehicles:import', ['path' => self::FILE])->assertSuccessful();

        $this->assertSame(3069900, Vehicle::where('stock_number', 'M1')->value('price_in_cents'));
    }

    public function test_the_car_page_says_the_dealer_certifies_it_before_pickup(): void
    {
        $this->mazdaStore();
        $this->artisan('vehicles:import', ['path' => self::FILE, '--publish' => true])->assertSuccessful();

        $car = Vehicle::where('stock_number', 'M2')->first();
        $this->get('/cars/' . $car->id)->assertOk()
            ->assertSee('Certified at pickup')
            ->assertSee('The dealership certifies this car under Mazda’s certified program before you pick it up.', false)
            ->assertSee('$22,995')
            ->assertDontSee('Test Mazda DL');
    }

    public function test_every_feed_s_fuel_names_end_up_as_one(): void
    {
        foreach (['Gas/Electric Hybrid', 'Gasoline / Electric Hybrid', 'Hybrid Fuel', 'hybrid'] as $hybrid) {
            $this->assertSame('Hybrid', Vehicle::normalizeFuel($hybrid));
        }
        $this->assertSame('Plug-in Hybrid', Vehicle::normalizeFuel('Plug-In Hybrid Electric'));
        $this->assertSame('Gasoline', Vehicle::normalizeFuel('Gas'));
        $this->assertSame('Electric', Vehicle::normalizeFuel('Electric Fuel System'));
        $this->assertSame('Flex Fuel', Vehicle::normalizeFuel('Flex Fuel'));
        $this->assertNull(Vehicle::normalizeFuel(null));

        $car = $this->makeDealer()->vehicles()->create(['vin' => 'JA4AZ2A31LZ600001', 'make' => 'Mitsubishi', 'model' => 'Outlander PHEV', 'model_year' => 2023, 'kilometres' => 20000, 'condition' => 'USED', 'price_in_cents' => 3000000, 'fuel_type' => 'Gasoline / Electric Hybrid']);
        $this->assertSame('Hybrid', $car->fuel_type);
    }

    public function test_brand_families_and_program_limits(): void
    {
        $this->assertTrue(CpoRules::sameFranchise('Jeep', 'Ram'));
        $this->assertTrue(CpoRules::sameFranchise('GMC', 'Chevrolet'));
        $this->assertFalse(CpoRules::sameFranchise('Toyota', 'Mazda'));
        $this->assertFalse(CpoRules::sameFranchise('Mazda', null));

        $this->assertTrue(CpoRules::fitsRules('Mazda', 2021, 119999, 2026));
        $this->assertFalse(CpoRules::fitsRules('Mazda', 2020, 50000, 2026));   // 7th model year
        $this->assertFalse(CpoRules::fitsRules('Mazda', 2024, 120000, 2026));  // at the km cap
        $this->assertTrue(CpoRules::fitsRules('Ram', 2022, 170000, 2026));
        $this->assertFalse(CpoRules::fitsRules('Honda', 2021, 10000, 2026));   // Honda: 5 years
        $this->assertFalse(CpoRules::fitsRules('Nissan', 2025, 1000, 2026));   // no limits entered
    }

    public function test_setup_creates_draft_stores_and_links_one_already_at_that_address(): void
    {
        $existing = $this->makeDealer(['name' => 'Harris Victoria', 'street_address' => '721 Meaford Avenue', 'city' => 'Victoria', 'postal_code' => 'V8V 3M5', 'status' => Dealer::STATUS_DRAFT, 'franchise_make' => null]);
        $list = tempnam(sys_get_temp_dir(), 'stores') . '.php';
        file_put_contents($list, '<?php return ' . var_export([
            ['file' => 'Trumo_HarrisCDJR.csv', 'name' => 'Harris Chrysler Dodge Jeep Ram', 'address' => '721 Meaford Ave', 'city' => 'Victoria', 'province' => 'BC', 'postal_code' => 'V8V3M5', 'phone' => null, 'brand' => 'Ram', 'fees' => [['Documentation fee', 99700, true]]],
            ['file' => 'Trumo_HarrisKIA.csv', 'name' => 'Harris Kia', 'address' => '2575 Bowen Rd', 'city' => 'Nanaimo', 'province' => 'BC', 'postal_code' => 'V9T 3L4', 'phone' => '250-751-1168', 'brand' => 'Kia', 'fees' => [['Documentation fee', 99900, true]]],
        ], true) . ';');

        $this->artisan('feeds:setup-homenet', ['--file' => $list])->assertSuccessful();
        $this->artisan('feeds:setup-homenet', ['--file' => $list])->assertSuccessful(); // again: nothing doubles

        $this->assertSame(2, Dealer::count());

        $existing->refresh();
        $this->assertSame(['Harris Victoria', 'homenet-harriscdjr', 'Ram', true], [$existing->name, $existing->feed_rooftop_id, $existing->franchise_make, $existing->certifies_at_pickup]);
        $this->assertSame('Trumo_HarrisCDJR.csv', $existing->primaryRooftop->feed_file);

        $kia = Dealer::where('name', 'Harris Kia')->first();
        $this->assertSame([Dealer::STATUS_DRAFT, 'Kia', 'Nanaimo', 'BC', 'America/Vancouver', true], [$kia->status, $kia->franchise_make, $kia->city, $kia->province, $kia->timezone, $kia->certifies_at_pickup]);
        $this->assertSame([['Documentation fee', 99900, true]], $kia->fees->map(fn ($fee) => [$fee->label, $fee->amount_in_cents, $fee->added_to_feed_price])->all());
        $this->assertSame(1, DealerFee::where('dealer_id', $kia->id)->count());
        $this->assertSame('Trumo_HarrisKIA.csv', $kia->primaryRooftop->feed_file);
    }
}
