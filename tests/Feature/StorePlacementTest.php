<?php

namespace Tests\Feature;

use App\Models\Dealer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\CreatesDealers;
use Tests\TestCase;

/** Stores are put on the map from their address, so every car gets a distance. */
class StorePlacementTest extends TestCase
{
    use CreatesDealers;
    use RefreshDatabase;

    private function fakeGeocoder(float $lat = 49.1577, float $lng = -121.9509): void
    {
        config(['services.google_places.key' => 'AIza-test']);
        Http::fake(['maps.googleapis.com/maps/api/geocode/*' => Http::response(['results' => [[
            'geometry' => ['location' => ['lat' => $lat, 'lng' => $lng]],
        ]]])]);
    }

    public function test_a_store_without_coordinates_is_placed_from_its_address(): void
    {
        $this->fakeGeocoder();

        $dealer = $this->makeDealer(['latitude' => null, 'longitude' => null]);

        $this->assertEqualsWithDelta(49.1577, $dealer->latitude, 0.0001);
        $this->assertTrue($dealer->primaryRooftop->hasCoordinates(), 'The main store gets the same point.');
        Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), '45510 Yale Rd'));
    }

    public function test_coordinates_typed_by_hand_are_kept(): void
    {
        $this->fakeGeocoder();

        $dealer = $this->makeDealer(['latitude' => 49.0, 'longitude' => -122.0]);

        $this->assertSame(49.0, $dealer->latitude);
        Http::assertNothingSent();
    }

    public function test_a_moved_store_is_placed_again(): void
    {
        $dealer = $this->makeDealer();
        $this->fakeGeocoder(43.65, -79.38);

        $dealer->update(['street_address' => '1 Yonge St', 'city' => 'Toronto', 'province' => 'ON', 'postal_code' => 'M5E 1W7']);

        $this->assertSame(43.65, $dealer->fresh()->latitude);
    }

    public function test_without_a_key_the_store_goes_to_the_centre_of_its_postal_area(): void
    {
        config(['services.google_places.key' => null]);
        Http::fake();

        $dealer = $this->makeDealer(['latitude' => null, 'longitude' => null]); // V2P 2M9

        $this->assertEqualsWithDelta(49.1838, $dealer->latitude, 0.0001);
        $this->assertEqualsWithDelta(-121.9046, $dealer->longitude, 0.0001);
        Http::assertNothingSent();
    }

    public function test_the_backfill_command_places_existing_stores(): void
    {
        $dealer = $this->makeDealer();
        $dealer->forceFill(['latitude' => null, 'longitude' => null])->saveQuietly();
        $dealer->primaryRooftop()->first()->forceFill(['latitude' => null, 'longitude' => null])->saveQuietly();
        $this->fakeGeocoder();

        $this->artisan('stores:place-on-map')->assertSuccessful()->expectsOutputToContain('Placed');

        $this->assertTrue($dealer->fresh()->hasCoordinates());
        $this->assertTrue($dealer->primaryRooftop()->first()->hasCoordinates());
    }

    public function test_checkout_only_offers_pickup(): void
    {
        $car = $this->makeDealer()->vehicles()->create([
            'vin' => Str::upper(Str::random(17)), 'model_year' => 2023, 'make' => 'Mitsubishi', 'model' => 'Outlander',
            'kilometres' => 30000, 'price_in_cents' => 3999500, 'condition' => 'USED', 'is_published' => true,
        ]);
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::checkout', ['vehicle' => $car])
            ->call('setHandoverMode', 'delivery')
            ->assertSet('handoverMode', 'pickup')
            ->assertDontSee('Bring it to me');
    }
}
