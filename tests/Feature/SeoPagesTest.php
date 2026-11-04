<?php

namespace Tests\Feature;

use App\Models\Dealer;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesDealers;
use Tests\TestCase;

class SeoPagesTest extends TestCase
{
    use CreatesDealers;
    use RefreshDatabase;

    private Dealer $dealer;

    private int $vin = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dealer = $this->makeDealer(); // Chilliwack, BC
        foreach (range(1, 4) as $i) {
            $this->car(['make' => 'Honda', 'model' => 'Civic', 'body_type' => 'Sedan', 'fuel_type' => 'Gasoline', 'price_in_cents' => 1800000 + $i * 100000]);
        }
        $this->car(['make' => 'Honda', 'model' => 'CR-V', 'body_type' => 'SUV', 'fuel_type' => 'Hybrid', 'price_in_cents' => 3500000]);
        $this->car(['make' => 'Toyota', 'model' => 'RAV4', 'body_type' => 'SUV', 'fuel_type' => 'Hybrid', 'price_in_cents' => 3900000]);
    }

    public function test_a_model_page_lists_only_that_model_with_its_own_title(): void
    {
        $this->get('/used-cars/honda/civic')
            ->assertOk()
            ->assertSee('<title>Used Honda Civic for Sale | Certified Pre-owned | TruCars</title>', false)
            ->assertSee('<link rel="canonical" href="' . url('/used-cars/honda/civic') . '">', false)
            ->assertSee('4 Certified Pre-owned Honda Civics for Sale')
            ->assertSee('Shop 4 certified pre-owned honda civics, priced all-in from $19,000')
            ->assertDontSee('2021 Honda CR-V')
            ->assertDontSee('Toyota RAV4');
    }

    public function test_make_body_fuel_price_and_town_pages(): void
    {
        $this->get('/used-cars/honda')->assertOk()->assertSee('5 Certified Pre-owned Honda Cars for Sale');
        $this->get('/used-cars/body/suv')->assertOk()->assertSee('2 Certified Pre-owned SUVs for Sale');
        $this->get('/used-cars/fuel/hybrid')->assertOk()->assertSee('2 Certified Pre-owned Hybrid Cars for Sale');
        $this->get('/used-cars/under-20000')->assertOk()->assertSee('2 Certified Pre-owned Cars Under $20,000');
        $this->get('/used-cars/in/chilliwack-bc')->assertOk()
            ->assertSee('6 Certified Pre-owned Cars for Sale in Chilliwack, BC')
            ->assertSee('<title>Used Cars for Sale in Chilliwack, BC | Certified Pre-owned | TruCars</title>', false);
    }

    public function test_pages_that_dont_exist_are_not_found(): void
    {
        $this->get('/used-cars/lamborghini')->assertNotFound();
        $this->get('/used-cars/honda/accord')->assertNotFound();
        $this->get('/used-cars/body/limo')->assertNotFound();
        $this->get('/used-cars/under-12345')->assertNotFound();
        $this->get('/used-cars/in/atlantis-zz')->assertNotFound();
    }

    public function test_the_footer_links_only_pages_with_enough_cars(): void
    {
        $this->get(route('shop'))
            ->assertOk()
            ->assertSee('Popular models')
            ->assertSee('Used Honda Civic for Sale')
            ->assertSee(url('/used-cars/honda/civic'), false)
            ->assertSee('Used Cars for Sale in Chilliwack, BC')
            ->assertDontSee('Used Honda CR-V for Sale')   // one car: not linked
            ->assertDontSee('Used Toyota for Sale');
    }

    public function test_the_footer_never_names_a_dealership(): void
    {
        $this->get(route('shop'))->assertOk()->assertDontSee('Chilliwack Mitsubishi');
    }

    public function test_the_sitemap_lists_landing_pages_and_every_car(): void
    {
        $car = Vehicle::first();

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml')
            ->assertSee('<loc>' . url('/used-cars/honda/civic') . '</loc>', false)
            ->assertSee('<loc>' . url('/used-cars/honda/cr-v') . '</loc>', false)
            ->assertSee('<loc>' . url('/used-cars/in/chilliwack-bc') . '</loc>', false)
            ->assertSee('<loc>' . url('/cars/' . $car->id) . '</loc>', false);
    }

    public function test_robots_points_at_the_sitemap(): void
    {
        $this->get('/robots.txt')->assertOk()->assertSee('Sitemap: ' . url('/sitemap.xml'));
    }

    public function test_the_vehicle_page_has_a_title_and_car_structured_data_without_the_dealer(): void
    {
        $car = Vehicle::where('model', 'RAV4')->first();

        $response = $this->get('/cars/' . $car->id)->assertOk()
            ->assertSee('for Sale in Chilliwack, BC | TruCars</title>', false)
            ->assertSee('"@type":"Car"', false)
            ->assertSee('"priceCurrency":"CAD"', false)
            ->assertSee('<link rel="canonical" href="' . url('/cars/' . $car->id) . '">', false);

        preg_match('/<script type="application\/ld\+json">(.*?)<\/script>/s', $response->getContent(), $match);
        $this->assertStringNotContainsStringIgnoringCase('mitsubishi', $match[1]);
    }

    public function test_the_page_picker_links_to_the_landing_page_itself(): void
    {
        foreach (range(1, 40) as $i) {
            $this->car(['make' => 'Honda', 'model' => 'Civic', 'body_type' => 'Sedan', 'fuel_type' => 'Gasoline', 'price_in_cents' => 2500000]);
        }

        Livewire::test('pages::marketplace', ['first' => 'honda', 'second' => 'civic'])
            ->set('sort', 'price-high')
            ->assertSee('href="' . url('/used-cars/honda/civic') . '?page=2"', false);
    }

    private function car(array $attributes): Vehicle
    {
        $this->vin++;

        return $this->dealer->vehicles()->create($attributes + [
            'vin'          => 'JA4AZ2A31LZ6' . str_pad((string) $this->vin, 5, '0', STR_PAD_LEFT),
            'model_year'   => 2021,
            'kilometres'   => 40000,
            'condition'    => 'USED',
            'is_published' => true,
        ]);
    }
}
