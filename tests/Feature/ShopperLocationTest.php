<?php

namespace Tests\Feature;

use App\Models\Dealer;
use App\Models\Vehicle;
use App\Support\ShopperLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\CreatesDealers;
use Tests\TestCase;

class ShopperLocationTest extends TestCase
{
    use CreatesDealers;
    use RefreshDatabase;

    private Dealer $chilliwack;
    private Dealer $toronto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->chilliwack = $this->makeDealer();
        $this->toronto = $this->makeDealer([
            'name' => 'Bayview Toyota', 'street_address' => '1 Bayview Ave', 'city' => 'Toronto', 'province' => 'ON',
            'postal_code' => 'M4G 3A1', 'latitude' => 43.7, 'longitude' => -79.37, 'timezone' => 'America/Toronto',
        ]);

        $this->vehicle($this->toronto, 'Toronto Corolla');
        $this->vehicle($this->chilliwack, 'Chilliwack Outlander');
    }

    public function test_browsing_works_with_no_location_and_shows_dealer_locations(): void
    {
        $this->get('/shop')
            ->assertOk()
            ->assertSee('Toronto Corolla')
            ->assertSee('Chilliwack Outlander')
            ->assertSee('Chilliwack, BC')
            ->assertSee('Use my location')
            ->assertDontSee('Thornhill');

        $this->assertNull(session(ShopperLocation::SESSION_KEY));
    }

    public function test_browser_location_sorts_by_distance_and_hides_nothing(): void
    {
        // Somewhere in Abbotsford, ~25 km from Chilliwack.
        Livewire::test('pages::marketplace')
            ->call('useBrowserLocation', 49.0504, -122.3045)
            ->assertSet('sort', 'nearest')
            ->assertSeeInOrder(['Chilliwack Outlander', 'Toronto Corolla'])
            ->assertSee('Near you')
            ->assertSee(' km');

        // Rounded to ~1 km before it's kept; session only.
        $this->assertSame(49.05, session(ShopperLocation::SESSION_KEY)['latitude']);
        $this->assertSame(-122.3, session(ShopperLocation::SESSION_KEY)['longitude']);
    }

    public function test_typed_postal_code_ranks_same_province_first(): void
    {
        Livewire::test('pages::marketplace')
            ->set('locationInput', 'm5v2t6')
            ->call('setManualLocation')
            ->assertHasNoErrors()
            ->assertSet('sort', 'nearest')
            ->assertSee('Nearest first to')
            ->assertSee('M5V 2T6')
            ->assertSeeInOrder(['Toronto Corolla', 'Chilliwack Outlander']);
    }

    public function test_the_shoppers_town_is_named_in_the_headline(): void
    {
        config(['services.google_places.key' => 'AIza-test']);
        Http::fake(['maps.googleapis.com/maps/api/geocode/*' => Http::response(['results' => [[
            'address_components' => [
                ['long_name' => 'Abbotsford', 'short_name' => 'Abbotsford', 'types' => ['locality', 'political']],
                ['long_name' => 'British Columbia', 'short_name' => 'BC', 'types' => ['administrative_area_level_1', 'political']],
            ],
        ]]])]);

        Livewire::test('pages::marketplace')
            ->call('useBrowserLocation', 49.0504, -122.3045)
            ->assertSee('Certified Pre-owned Cars for Sale in Abbotsford')
            ->assertSee('Abbotsford, BC')
            ->assertSeeInOrder(['Chilliwack Outlander', 'Toronto Corolla']);

        Livewire::test('pages::marketplace')
            ->set('locationInput', 'chilliwack')
            ->call('setManualLocation')
            ->assertSee('Certified Pre-owned Cars for Sale in Chilliwack');
    }

    public function test_typed_city_ranks_that_city_first(): void
    {
        Livewire::test('pages::marketplace')
            ->set('locationInput', 'chilliwack')
            ->call('setManualLocation')
            ->assertSeeInOrder(['Chilliwack Outlander', 'Toronto Corolla']);
    }

    public function test_unusable_input_is_rejected_and_browsing_carries_on(): void
    {
        Livewire::test('pages::marketplace')
            ->set('locationInput', '123 Main St Apt 4')
            ->call('setManualLocation')
            ->assertHasErrors('locationInput')
            ->assertSee('Toronto Corolla')
            ->assertSee('Chilliwack Outlander');

        Livewire::test('pages::marketplace')
            ->call('useBrowserLocation', 'not-a-number', 999)
            ->assertHasErrors('locationInput');

        $this->assertNull(session(ShopperLocation::SESSION_KEY));
    }

    public function test_location_can_be_cleared(): void
    {
        Livewire::test('pages::marketplace')
            ->call('useBrowserLocation', 49.05, -122.30)
            ->call('clearLocation')
            ->assertSet('sort', 'newest')
            ->assertSee('Use my location');

        $this->assertNull(session(ShopperLocation::SESSION_KEY));
    }

    private function vehicle(Dealer $dealer, string $model): Vehicle
    {
        return $dealer->vehicles()->create([
            'vin' => Str::upper(Str::random(17)), 'model_year' => (int) date('Y') - 2, 'make' => 'Test',
            'model' => $model, 'kilometres' => 30000, 'price_in_cents' => 2999000, 'condition' => 'USED',
            'is_published' => true, 'is_certified' => true,
        ]);
    }
}
