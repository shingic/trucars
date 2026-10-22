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
        $this->car(['model' => 'Outlander', 'body_type' => 'SUV', 'photos' => ['https://example.test/outlander.jpg']]);
        $this->car(['model' => 'Hidden Mirage', 'is_published' => false, 'photos' => ['https://example.test/mirage.jpg']]);
        $this->car(['model' => 'Photoless Lancer']);

        $this->get('/')
            ->assertOk()
            ->assertDontSee('Photoless Lancer')
            ->assertSee('Your next car, at the real price.')
            ->assertSee('On the lot now')
            ->assertSee('Outlander')
            ->assertDontSee('Hidden Mirage')
            ->assertSee(route('shop'))
            ->assertSee(route('how-it-works'));
    }

    public function test_the_big_photo_rotates_through_every_dealer(): void
    {
        $other = $this->makeDealer(['name' => 'Valley Nissan']);
        $this->car(['model' => 'Outlander', 'photos' => ['https://example.test/outlander.jpg']]);
        $this->car(['model' => 'NoPhoto']);
        $other->vehicles()->create([
            'vin' => Str::upper(Str::random(17)), 'model_year' => 2022, 'make' => 'Nissan', 'model' => 'Rogue',
            'kilometres' => 30000, 'price_in_cents' => 2899000, 'condition' => 'USED', 'is_published' => true,
            'photos' => ['https://example.test/rogue.jpg'],
        ]);

        $seen = collect(range(1, 30))->map(fn () => Livewire::test('pages::home')->viewData('featured')?->model)->unique();

        $this->assertEqualsCanonicalizing(['Outlander', 'Rogue'], $seen->all());
    }

    public function test_one_dealers_big_upload_cannot_fill_the_row(): void
    {
        // The newest eight are all from one dealer, as after a feed import.
        $other = $this->makeDealer(['name' => 'Valley Nissan']);
        $other->vehicles()->create([
            'vin' => Str::upper(Str::random(17)), 'model_year' => 2020, 'make' => 'Nissan', 'model' => 'Rogue',
            'kilometres' => 30000, 'price_in_cents' => 2899000, 'condition' => 'USED', 'is_published' => true,
            'photos' => ['https://example.test/rogue.jpg'], 'created_at' => now()->subDay(),
        ]);
        foreach (range(1, 12) as $i) {
            $this->car(['model' => "Outlander {$i}", 'photos' => ["https://example.test/{$i}.jpg"]]);
        }

        foreach (range(1, 5) as $visit) {
            $mix = Livewire::test('pages::home')->viewData('mix');

            $this->assertCount(8, $mix);
            $this->assertSame(8, $mix->pluck('id')->unique()->count());
            $this->assertTrue($mix->contains('model', 'Rogue'));
        }
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
