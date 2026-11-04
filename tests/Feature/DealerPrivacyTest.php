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
            ->assertSee('In Chilliwack, BC')
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
            ->assertSee('About a 1 hr 30 min drive')
            ->assertSee('drive to pick it up')
            ->assertDontSee("That's a long way from you");
    }

    public function test_a_far_away_car_says_so(): void
    {
        $vehicle = $this->vehicle();
        session([ShopperLocation::SESSION_KEY => ['source' => 'browser', 'label' => 'Toronto, ON', 'latitude' => 43.65, 'longitude' => -79.38]]);

        $this->get('/cars/' . $vehicle->id)->assertOk()->assertSee("That's a long way from you", false);
    }

    public function test_shoppers_are_asked_where_they_are_before_seeing_cars(): void
    {
        $vehicle = $this->vehicle();

        $this->get('/cars/' . $vehicle->id)->assertOk()->assertSee('Where are you shopping from?')->assertSee('open: true', false);
        $this->get(route('shop'))->assertOk()->assertSee('Where are you shopping from?');
        $this->get(route('home'))->assertOk()->assertDontSee('Where are you shopping from?');
    }

    public function test_once_located_the_prompt_stays_closed_until_asked_for(): void
    {
        session([ShopperLocation::SESSION_KEY => ['source' => 'browser', 'label' => 'Vancouver, BC', 'latitude' => 49.28, 'longitude' => -123.12]]);

        $this->get('/cars/' . $this->vehicle()->id)->assertOk()->assertSee('open: false', false)->assertSee('open-location-gate');
    }

    public function test_a_postal_code_places_the_shopper_and_reloads_the_page(): void
    {
        config(['services.google_places.key' => 'AIza-test']);
        Http::fake(['maps.googleapis.com/maps/api/geocode/*' => Http::response(['results' => [[
            'address_components' => [
                ['long_name' => 'Abbotsford', 'short_name' => 'Abbotsford', 'types' => ['locality', 'political']],
                ['long_name' => 'British Columbia', 'short_name' => 'BC', 'types' => ['administrative_area_level_1', 'political']],
            ],
            'geometry' => ['location' => ['lat' => 49.0504, 'lng' => -122.3045]],
        ]]])]);
        $vehicle = $this->vehicle();

        Livewire::test('pages::location-gate', ['returnUrl' => url('/cars/' . $vehicle->id)])
            ->set('postalCode', 'v2t 1a1')
            ->call('usePostalCode')
            ->assertHasNoErrors()
            ->assertRedirect(url('/cars/' . $vehicle->id));

        $this->assertSame(49.05, ShopperLocation::current()['latitude']);
        $this->assertSame('V2T 1A1', ShopperLocation::current()['postal_code']);

        $this->get('/cars/' . $vehicle->id)->assertSee('km away')->assertSee('From Abbotsford, BC');
    }

    public function test_without_google_a_postal_code_still_gives_town_and_kilometres(): void
    {
        config(['services.google_places.key' => null]);
        Http::fake();
        $vehicle = $this->vehicle();

        Livewire::test('pages::location-gate', ['returnUrl' => url('/cars/' . $vehicle->id)])
            ->set('postalCode', 'L3Y 4X1')
            ->call('usePostalCode')
            ->assertHasNoErrors();

        $this->assertSame('Newmarket, ON', ShopperLocation::current()['label']);
        $this->get('/cars/' . $vehicle->id)->assertSee('km away')->assertSee('From Newmarket, ON · L3Y 4X1');
        $this->get(route('shop'))->assertSee('for Sale in Newmarket, Ontario');
        Http::assertNothingSent();
    }

    public function test_without_google_a_browser_location_still_names_the_town(): void
    {
        config(['services.google_places.key' => null]);
        Http::fake();

        Livewire::test('pages::location-gate')->call('useBrowserLocation', 44.05, -79.46);

        $this->assertSame('Newmarket, ON', ShopperLocation::current()['label']);
        $this->assertSame('L3Y', ShopperLocation::current()['postal_area']);
    }

    public function test_the_prompt_wants_a_postal_code_not_a_city(): void
    {
        Livewire::test('pages::location-gate')
            ->set('postalCode', 'Toronto')
            ->call('usePostalCode')
            ->assertHasErrors(['postalCode']);

        $this->assertNull(ShopperLocation::current());
    }

    public function test_the_browser_location_works_too(): void
    {
        Livewire::test('pages::location-gate', ['returnUrl' => url('/shop')])
            ->call('useBrowserLocation', 49.28, -123.12)
            ->assertRedirect(url('/shop'));

        $this->assertSame(49.28, ShopperLocation::current()['latitude']);
    }

    public function test_the_prompt_only_ever_returns_to_this_site(): void
    {
        Livewire::test('pages::location-gate', ['returnUrl' => 'https://evil.example/phish'])
            ->call('useBrowserLocation', 49.28, -123.12)
            ->assertRedirect(url('/'));
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
