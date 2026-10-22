<?php

namespace Tests\Feature;

use App\Models\Dealer;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\CreatesDealers;
use Tests\TestCase;

class HomepageTest extends TestCase
{
    use CreatesDealers;
    use RefreshDatabase;

    private Dealer $dealer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dealer = $this->makeDealer();
    }

    public function test_visitors_land_on_the_homepage_not_the_shop(): void
    {
        $this->car(['model' => 'Outlander', 'body_type' => 'SUV']);
        $this->car(['model' => 'Hidden Mirage', 'is_published' => false]);

        $this->get('/')
            ->assertOk()
            ->assertSee('All-in prices.')
            ->assertSee('Just landed')
            ->assertSee('Outlander')
            ->assertDontSee('Hidden Mirage')
            ->assertSee(route('shop'))
            ->assertSee(route('how-it-works'));
    }

    public function test_the_site_no_longer_offers_selling_trading_or_financing(): void
    {
        foreach (['/', '/shop', '/how-it-works'] as $page) {
            $this->get($page)->assertOk()->assertDontSee('Sell / Trade')->assertDontSee('Sell or trade')->assertDontSee('>Financing<', false);
        }
    }

    public function test_homepage_search_opens_the_shop_filtered(): void
    {
        Livewire::test('pages::home')
            ->set('q', 'Outlander')
            ->call('search')
            ->assertRedirect(route('shop', ['q' => 'Outlander']));

        Livewire::test('pages::home')
            ->set('make', 'Mitsubishi')
            ->set('max', '25000')
            ->call('search')
            ->assertRedirect(route('shop', ['make' => ['Mitsubishi'], 'max' => 25000]));

        // A price that isn't one of the choices is dropped, not passed along.
        Livewire::test('pages::home')
            ->set('max', '1')
            ->call('search')
            ->assertRedirect(route('shop'));
    }

    public function test_the_shop_reads_its_filters_from_the_url(): void
    {
        $this->car(['model' => 'Outlander', 'body_type' => 'SUV']);
        $this->car(['model' => 'Lancer', 'body_type' => 'Sedan']);

        $this->get('/shop?body[0]=SUV')->assertOk()->assertSee('Outlander')->assertDontSee('Lancer');
        $this->get('/shop?q=Lancer')->assertOk()->assertSee('Lancer')->assertDontSee('Outlander');
    }

    public function test_how_it_works_page(): void
    {
        $this->get('/how-it-works')->assertOk()->assertSee('Hold it for $150')->assertSee('refundable any time before delivery');
    }

    private function car(array $overrides): Vehicle
    {
        return $this->dealer->vehicles()->create($overrides + [
            'vin' => Str::upper(Str::random(17)), 'model_year' => 2023, 'make' => 'Mitsubishi',
            'kilometres' => 20000, 'price_in_cents' => 2999000, 'condition' => 'USED', 'is_published' => true,
        ]);
    }
}
