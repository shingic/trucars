<?php

namespace Tests\Feature;

use App\Models\Dealer;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\CertificationProgram;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\CreatesDealers;
use Tests\TestCase;

class VehiclePageTest extends TestCase
{
    use CreatesDealers;
    use RefreshDatabase;

    private Dealer $dealer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dealer = $this->makeDealer(['franchise_make' => 'Mitsubishi']);
    }

    public function test_unpublished_vehicle_page_is_not_found(): void
    {
        $vehicle = $this->vehicle(['is_published' => false]);

        $this->get('/cars/' . $vehicle->id)->assertNotFound();
    }

    public function test_published_vehicle_page_still_opens(): void
    {
        $vehicle = $this->vehicle(['model' => 'Outlander']);

        $this->get('/cars/' . $vehicle->id)->assertOk()->assertSee('Outlander');
    }

    public function test_recent_low_km_car_is_not_labelled_manufacturer_certified_without_the_flag(): void
    {
        $vehicle = $this->vehicle(['is_certified' => true, 'is_manufacturer_certified' => false]);

        $this->assertSame('Certified Pre-Owned', CertificationProgram::resolveFor($vehicle)?->name);

        $this->get('/cars/' . $vehicle->id)
            ->assertOk()
            ->assertDontSee('Manufacturer Certified Pre-Owned')
            ->assertDontSee('Manufacturer CPO');
    }

    public function test_manufacturer_flag_shows_the_manufacturer_program(): void
    {
        $vehicle = $this->vehicle(['is_certified' => true, 'is_manufacturer_certified' => true]);

        $this->assertSame('Manufacturer Certified Pre-Owned', CertificationProgram::resolveFor($vehicle)?->name);

        $this->get('/cars/' . $vehicle->id)->assertOk()->assertSee('Manufacturer Certified Pre-Owned');
    }

    public function test_uncertified_car_gets_no_program(): void
    {
        $vehicle = $this->vehicle(['is_certified' => false, 'is_manufacturer_certified' => false]);

        $this->assertNull(CertificationProgram::resolveFor($vehicle));
    }

    public function test_dealer_certified_program_still_respects_its_age_and_kilometre_limits(): void
    {
        $vehicle = $this->vehicle(['is_certified' => true, 'kilometres' => 200000]);

        $this->assertNull(CertificationProgram::resolveFor($vehicle));
    }

    public function test_saved_cars_hides_vehicles_that_were_unpublished(): void
    {
        $live = $this->vehicle(['model' => 'Live Outlander']);
        $pulled = $this->vehicle(['model' => 'Pulled Mirage']);
        $buyer = User::factory()->create();
        $buyer->favouriteVehicles()->attach([$live->id, $pulled->id]);

        $pulled->update(['is_published' => false]);

        $this->actingAs($buyer);

        Livewire::test('pages::saved')
            ->assertSee('Live Outlander')
            ->assertDontSee('Pulled Mirage');
    }

    private function vehicle(array $overrides = []): Vehicle
    {
        return $this->dealer->vehicles()->create($overrides + [
            'vin'                       => Str::upper(Str::random(17)),
            'model_year'                => (int) date('Y') - 2,
            'make'                      => 'Mitsubishi',
            'model'                     => 'Outlander',
            'kilometres'                => 30000,
            'price_in_cents'            => 3299000,
            'condition'                 => 'USED',
            'is_published'              => true,
            'is_certified'              => true,
            'is_manufacturer_certified' => false,
        ]);
    }
}
