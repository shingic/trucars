<?php

namespace Tests\Feature;

use App\Models\Dealer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesDealers;
use Tests\TestCase;

class MarketplacePaginationTest extends TestCase
{
    use CreatesDealers;
    use RefreshDatabase;

    private Dealer $dealer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dealer = $this->makeDealer();
        foreach (range(1, 85) as $i) {
            $this->dealer->vehicles()->create([
                'vin'            => 'JA4AZ2A31LZ6' . str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                'model_year'     => 2020,
                'make'           => $i <= 45 ? 'Mitsubishi' : 'Honda',
                'model'          => 'Car' . str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'kilometres'     => 40000,
                'price_in_cents' => 2000000 + $i * 10000,
                'condition'      => 'USED',
                'is_published'   => true,
            ]);
        }
    }

    public function test_forty_cars_a_page_with_a_picker(): void
    {
        $shop = Livewire::test('pages::marketplace')->set('sort', 'price-low');

        $this->assertCount(40, $shop->get('pageVehicles'));
        $shop->assertSee('Car001')->assertSee('Car040')->assertDontSee('Car041')
            ->assertSee('Showing 1–40 of 85 cars')
            ->assertSee('aria-label="Page 3"', false);

        $shop->call('goToPage', 3)->assertSet('page', 3);
        $this->assertCount(5, $shop->get('pageVehicles'));
        $shop->assertSee('Car085')->assertSee('Showing 81–85 of 85 cars');
    }

    public function test_a_page_past_the_end_is_held_to_the_last_page(): void
    {
        Livewire::test('pages::marketplace')->call('goToPage', 99)->assertSet('page', 3);
    }

    public function test_changing_a_filter_or_the_sort_goes_back_to_page_one(): void
    {
        $shop = Livewire::test('pages::marketplace')->call('goToPage', 2);

        $shop->set('selectedMakes', ['Honda'])->assertSet('page', 1);
        $this->assertCount(40, $shop->get('pageVehicles'));

        $shop->call('goToPage', 2)->set('sort', 'price-high')->assertSet('page', 1);
    }

    public function test_one_page_of_results_shows_no_picker(): void
    {
        Livewire::test('pages::marketplace')
            ->set('selectedMakes', ['Honda'])
            ->set('priceMax', 3000)
            ->assertDontSee('Showing 1–');
    }

    public function test_the_page_is_in_the_url(): void
    {
        $this->get(route('shop', ['page' => 2]))->assertOk()->assertSee('Showing 41–80 of 85 cars');
    }
}
