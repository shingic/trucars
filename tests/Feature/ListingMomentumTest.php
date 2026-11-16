<?php

namespace Tests\Feature;

use App\Models\Dealer;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesDealers;
use Tests\TestCase;

class ListingMomentumTest extends TestCase
{
    use CreatesDealers;
    use RefreshDatabase;

    private Dealer $dealer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dealer = $this->makeDealer();
    }

    public function test_a_car_is_just_listed_for_a_week_after_it_first_goes_live(): void
    {
        $car = $this->car();
        $this->assertNotNull($car->first_listed_at);
        $this->assertSame('Just listed', $car->momentumTag()['label']);

        $this->travel(8)->days();
        $this->assertNull($car->fresh()->momentumTag());
    }

    public function test_republishing_does_not_make_a_car_new_again(): void
    {
        $car = $this->car();
        $this->travel(10)->days();
        $car->update(['is_published' => false]);
        $car->update(['is_published' => true]);

        $this->assertNull($car->fresh()->momentumTag());
    }

    public function test_a_price_cut_shows_how_much_and_wins_over_just_listed(): void
    {
        $car = $this->car(['price_in_cents' => 2500000]);
        $car->update(['price_in_cents' => 2400000]);
        $car->update(['price_in_cents' => 2350000]); // a second cut adds to the first

        $this->assertSame(['kind' => 'drop', 'label' => 'Price dropped $1,500'], $car->fresh()->momentumTag());

        $this->travel(15)->days();
        $this->assertNull($car->fresh()->momentumTag());
    }

    public function test_tiny_cuts_and_price_rises_show_nothing(): void
    {
        $car = $this->car(['price_in_cents' => 2500000]);
        $this->travel(8)->days();

        $car->update(['price_in_cents' => 2490000]); // $100
        $this->assertNull($car->fresh()->momentumTag());

        $car->update(['price_in_cents' => 2300000]);
        $car->update(['price_in_cents' => 2600000]); // back up past the old price
        $this->assertNull($car->fresh()->momentumTag());
    }

    public function test_views_are_counted_once_per_visitor_per_day_without_staff_or_bots(): void
    {
        $car = $this->car();

        // The same browser (same session) twice in a day is one view.
        $request = \Illuminate\Http\Request::create('/cars/' . $car->id);
        $request->setLaravelSession($session = app('session')->driver());
        $session->start();
        $car->recordView($request);
        $car->recordView($request);
        $this->assertSame(1, DB::table('vehicle_views')->count());

        $this->get('/cars/' . $car->id)->assertOk(); // another visitor
        $this->assertSame(2, DB::table('vehicle_views')->count());

        $this->withHeader('User-Agent', 'Googlebot/2.1')->get('/cars/' . $car->id)->assertOk();
        $this->flushSession();
        $this->actingAs(User::factory()->create(['role' => User::ROLE_INTERNAL_STAFF]))->get('/cars/' . $car->id);

        $this->assertSame(2, DB::table('vehicle_views')->count());
    }

    public function test_popular_stays_off_until_a_threshold_is_set(): void
    {
        $car = $this->car();
        $this->travel(8)->days();
        foreach (range(1, 5) as $n) {
            DB::table('vehicle_views')->insert(['vehicle_id' => $car->id, 'visitor' => sha1("v{$n}"), 'viewed_on' => today()->toDateString()]);
        }

        $this->assertNull($car->fresh()->momentumTag());

        config(['site.hot_views_threshold' => 5]);
        $this->assertSame('Popular today', $car->fresh()->momentumTag()['label']);
    }

    public function test_the_tag_shows_on_the_card_and_the_car_page(): void
    {
        $car = $this->car();

        $this->get('/cars/' . $car->id)->assertOk()->assertSee('Just listed');
        $this->get('/shop')->assertOk()->assertSee('Just listed');
    }

    public function test_the_views_command_lists_cars_and_prunes_old_rows(): void
    {
        $car = $this->car();
        DB::table('vehicle_views')->insert([
            ['vehicle_id' => $car->id, 'visitor' => sha1('a'), 'viewed_on' => today()->toDateString()],
            ['vehicle_id' => $car->id, 'visitor' => sha1('b'), 'viewed_on' => today()->subDays(120)->toDateString()],
        ]);

        $this->artisan('vehicles:views')->assertSuccessful()->expectsOutputToContain('is off');
        $this->artisan('vehicles:views --prune')->assertSuccessful()->expectsOutputToContain('Deleted 1');
    }

    private function car(array $overrides = []): Vehicle
    {
        return $this->dealer->vehicles()->create($overrides + [
            'vin' => '1HGCM82633A004352',
            'model_year' => (int) date('Y') - 2,
            'make' => 'Mitsubishi',
            'model' => 'Outlander',
            'kilometres' => 30000,
            'price_in_cents' => 2500000,
            'condition' => 'USED',
            'is_published' => true,
        ]);
    }
}
