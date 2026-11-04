<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\Dealer;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\ShopperLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Concerns\CreatesDealers;
use Tests\TestCase;

/**
 * Shoppers judge a car by how far away it is, not by who sells it. The
 * dealership's name, address and registration are only shared once the
 * buyer's deposit is paid.
 */
class DealerPrivacyTest extends TestCase
{
    use CreatesDealers;
    use RefreshDatabase;

    private Dealer $dealer;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->dealer = $this->makeDealer(['registration_number' => '31234', 'phone' => '604-555-0101']);
    }

    public function test_the_vehicle_page_names_no_dealership(): void
    {
        $vehicle = $this->vehicle();

        $this->get('/cars/' . $vehicle->id)
            ->assertOk()
            ->assertSee('Distance from you')
            ->assertSee('In Chilliwack, BC')
            ->assertSee('Enter your postal code')
            ->assertDontSee('Mitsubishi Motors')
            ->assertDontSee('Chilliwack Mitsubishi')
            ->assertDontSee('45510 Yale Rd')
            ->assertDontSee('31234')
            ->assertDontSee('604-555-0101');
    }

    public function test_the_vehicle_page_shows_the_distance_from_the_shopper(): void
    {
        $vehicle = $this->vehicle();
        session([ShopperLocation::SESSION_KEY => ['source' => 'browser', 'label' => 'Vancouver, BC', 'latitude' => 49.28, 'longitude' => -123.12]]);

        $this->get('/cars/' . $vehicle->id)
            ->assertOk()
            ->assertSee('km away')
            ->assertSee('From Vancouver, BC')
            ->assertSee('km from Vancouver, BC')
            ->assertDontSee("That's a long way from you");
    }

    public function test_a_far_away_car_says_so(): void
    {
        $vehicle = $this->vehicle();
        session([ShopperLocation::SESSION_KEY => ['source' => 'browser', 'label' => 'Toronto, ON', 'latitude' => 43.65, 'longitude' => -79.38]]);

        $this->get('/cars/' . $vehicle->id)->assertOk()->assertSee("That's a long way from you", false);
    }

    public function test_a_postal_code_on_the_vehicle_page_gives_the_distance(): void
    {
        config(['services.google_places.key' => 'AIza-test']);
        Http::fake(['maps.googleapis.com/maps/api/geocode/*' => Http::response(['results' => [[
            'address_components' => [
                ['long_name' => 'Abbotsford', 'short_name' => 'Abbotsford', 'types' => ['locality', 'political']],
                ['long_name' => 'British Columbia', 'short_name' => 'BC', 'types' => ['administrative_area_level_1', 'political']],
            ],
            'geometry' => ['location' => ['lat' => 49.0504, 'lng' => -122.3045]],
        ]]])]);

        Livewire::test('pages::vehicle', ['vehicle' => $this->vehicle()])
            ->set('locationInput', 'v2t 1a1')
            ->call('setLocation')
            ->assertHasNoErrors()
            ->assertSee('km away')
            ->assertSee('From Abbotsford, BC');

        $this->assertSame(49.05, ShopperLocation::current()['latitude']);
        $this->assertSame('V2T 1A1', ShopperLocation::current()['postal_code']);
    }

    public function test_nonsense_location_input_is_refused(): void
    {
        Livewire::test('pages::vehicle', ['vehicle' => $this->vehicle()])
            ->set('locationInput', '!!!')
            ->call('setLocation')
            ->assertHasErrors(['locationInput']);
    }

    public function test_shop_cards_show_distance_and_town_not_the_dealer(): void
    {
        $this->vehicle();
        session([ShopperLocation::SESSION_KEY => ['source' => 'browser', 'label' => 'Vancouver, BC', 'latitude' => 49.28, 'longitude' => -123.12]]);

        $this->get(route('shop'))
            ->assertOk()
            ->assertSee('km away')
            ->assertSee('Chilliwack, BC')
            ->assertDontSee('Chilliwack Mitsubishi');
    }

    public function test_only_a_dealers_own_campaign_storefront_names_it_on_cards(): void
    {
        $vehicle = $this->vehicle();
        $card = fn (array $extra) => view('partials.vehicle-card', $extra + ['vehicle' => $vehicle, 'savedVehicleIds' => [], 'favouritesAreVisible' => false])->render();

        $this->assertStringNotContainsString('Chilliwack Mitsubishi', $card([]));
        $this->assertStringContainsString('Chilliwack Mitsubishi', $card(['showDealer' => true]));
    }

    public function test_the_buyer_gets_the_dealership_once_reserved(): void
    {
        $buyer = User::factory()->create(['email' => 'pat@example.com']);
        Deal::create([
            'dealer_id'  => $this->dealer->id,
            'vehicle_id' => $this->vehicle()->id,
            'user_id'    => $buyer->id,
            'first_name' => 'Pat',
            'last_name'  => 'Lee',
            'email'      => 'pat@example.com',
            'phone'      => '6045550199',
        ]);

        $this->actingAs($buyer)->get(route('garage'))
            ->assertOk()
            ->assertSee('Your dealership')
            ->assertSee('Chilliwack Mitsubishi')
            ->assertSee('45510 Yale Rd')
            ->assertSee('Chilliwack, BC V2P 2M9')
            ->assertSee('604-555-0101')
            ->assertSee('VSA #31234');
    }

    private function vehicle(array $overrides = []): Vehicle
    {
        return $this->dealer->vehicles()->create($overrides + [
            'vin'            => '1HGCM82633A00' . random_int(1000, 9999),
            'model_year'     => (int) date('Y') - 2,
            'make'           => 'Mitsubishi',
            'model'          => 'Outlander',
            'kilometres'     => 30000,
            'price_in_cents' => 3299000,
            'condition'      => 'USED',
            'is_published'   => true,
        ]);
    }
}
