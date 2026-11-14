<?php

namespace Tests\Feature;

use App\Models\Dealer;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\CreatesDealers;
use Tests\TestCase;

class MarketplaceRecommendedOrderTest extends TestCase
{
    use CreatesDealers;
    use RefreshDatabase;

    private Dealer $chilliwack;
    private Dealer $victoria;

    protected function setUp(): void
    {
        parent::setUp();

        $this->chilliwack = $this->makeDealer();
        $this->victoria = $this->makeDealer([
            'name' => 'Harris Victoria', 'street_address' => '721 Meaford Ave', 'city' => 'Victoria', 'province' => 'BC',
            'postal_code' => 'V8V 3M5', 'latitude' => 48.42, 'longitude' => -123.36, 'timezone' => 'America/Vancouver',
        ]);
    }

    public function test_dealers_take_turns_so_a_big_import_cannot_bury_everyone_else(): void
    {
        $this->car($this->chilliwack, 'Chilliwack 1', now()->subDays(3));
        $this->car($this->chilliwack, 'Chilliwack 2', now()->subDays(4));
        foreach (range(1, 10) as $i) {
            $this->car($this->victoria, "Victoria {$i}", now()->subMinutes($i)); // a fresh feed import
        }

        $shop = Livewire::test('pages::marketplace')->assertSet('sort', 'recommended');
        $order = $shop->instance()->publishedVehicles->pluck('model')->all();

        $this->assertSame(['Victoria 1', 'Chilliwack 1', 'Victoria 2', 'Chilliwack 2', 'Victoria 3'], array_slice($order, 0, 5));
        $this->assertCount(12, $order, 'nothing is hidden');

        // "Newest" is still there for shoppers who want it: the import fills the top.
        $newest = $shop->set('sort', 'newest')->instance()->publishedVehicles->pluck('model')->take(10)->all();
        $this->assertNotContains('Chilliwack 1', $newest);
    }

    public function test_with_a_location_closer_dealers_come_first(): void
    {
        $this->car($this->chilliwack, 'Chilliwack 1', now()->subDays(3));
        $this->car($this->victoria, 'Victoria 1', now());
        $this->car($this->victoria, 'Victoria 2', now()->subMinute());

        // Shopper in Abbotsford, ~25 km from Chilliwack and ~100 km+ from Victoria.
        $shop = Livewire::test('pages::marketplace')->call('useBrowserLocation', 49.0504, -122.3045)->set('sort', 'recommended');

        $this->assertSame(['Chilliwack 1', 'Victoria 1', 'Victoria 2'], $shop->instance()->publishedVehicles->pluck('model')->all());
    }

    private function car(Dealer $dealer, string $model, $createdAt): Vehicle
    {
        $vehicle = $dealer->vehicles()->create([
            'vin' => Str::upper(Str::random(17)), 'model_year' => (int) date('Y') - 2, 'make' => 'Test',
            'model' => $model, 'kilometres' => 30000, 'price_in_cents' => 2999000, 'condition' => 'USED',
            'is_published' => true, 'is_certified' => true,
        ]);
        $vehicle->forceFill(['created_at' => $createdAt])->save();

        return $vehicle;
    }
}
