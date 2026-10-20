<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Deal;
use App\Models\Dealer;
use App\Models\InspectionProgram;
use App\Models\Offer;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Once;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\CreatesDealers;
use Tests\TestCase;

class OffersTest extends TestCase
{
    use CreatesDealers;
    use RefreshDatabase;

    private Brand $bmw;
    private Dealer $dealer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setDate(2026, 11, 15)->setTime(12, 0));
        $this->bmw = Brand::where('name', 'BMW')->sole();
        $this->dealer = $this->makeDealer(['name' => 'Valley BMW', 'franchise_make' => 'BMW']);
    }

    public function test_offers_switch_on_and_off_by_their_dates(): void
    {
        $car = $this->car();
        $this->offer(['starts_on' => '2026-12-01', 'ends_on' => '2026-12-31']);

        $this->assertTrue($this->fresh($car)->offers()->isEmpty(), 'scheduled offers stay hidden');

        $this->travelTo(now()->setDate(2026, 12, 1));
        $this->assertSame('3.99% APR', $this->fresh($car)->offers()->sole()->headline());

        $this->travelTo(now()->setDate(2027, 1, 1));
        $this->assertTrue($this->fresh($car)->offers()->isEmpty(), 'ended offers disappear');
        $this->assertSame(1, Offer::count(), 'and stay on record');
    }

    public function test_a_brand_offer_reaches_every_dealer_of_the_brand_with_no_opt_in(): void
    {
        $this->offer();
        $other = $this->makeDealer(['name' => 'Surrey BMW', 'franchise_make' => 'BMW']);

        $this->assertCount(1, $this->car()->offers());
        $this->assertCount(1, $this->car([], $other)->offers());

        // A BMW sitting at a Mitsubishi dealer isn't covered by BMW's offer.
        $this->assertCount(0, $this->car([], $this->makeDealer())->offers());
    }

    public function test_offers_only_reach_cars_that_match_every_condition(): void
    {
        $this->offer(['models' => ['330i', 'X3'], 'year_min' => 2021, 'year_max' => 2024, 'provinces' => ['BC']]);

        $this->assertCount(1, $this->car(['model' => '330i', 'model_year' => 2023])->offers());
        $this->assertCount(0, $this->car(['model' => 'X5', 'model_year' => 2023])->offers(), 'model');
        $this->assertCount(0, $this->car(['model' => 'X3', 'model_year' => 2019])->offers(), 'year');
        $this->assertCount(0, $this->car(['make' => 'Audi', 'model' => 'X3'])->offers(), 'make');
        $this->assertCount(0, $this->car(['model' => 'X3', 'condition' => 'NEW'])->offers(), 'new cars');

        $ontario = $this->makeDealer(['name' => 'Toronto BMW', 'franchise_make' => 'BMW', 'province' => 'ON', 'postal_code' => 'M5V 2T6', 'city' => 'Toronto']);
        $this->assertCount(0, $this->car(['model' => 'X3'], $ontario)->offers(), 'province');
    }

    public function test_certified_only_offers_need_the_brand_checklist(): void
    {
        $car = $this->car();
        $this->offer(['certified_only' => true]);

        $this->assertCount(0, $this->fresh($car)->offers());

        $program = InspectionProgram::create(['make' => 'BMW', 'name' => 'BMW Certified', 'applies_to' => 'all_used']);
        $program->points()->create(['position' => 1, 'label' => 'Brakes']);

        $this->assertCount(1, $this->fresh($car)->offers());
    }

    public function test_owner_creates_and_publishes_a_brand_offer(): void
    {
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test('pages::admin-offer', ['brand' => $this->bmw])
            ->set('apr', '3.99')
            ->set('termMonths', '48')
            ->call('publish')
            ->assertHasErrors('conditions')
            ->set('conditions', 'On approved credit. Eligible BMW Certified vehicles only.')
            ->call('publish')
            ->assertHasNoErrors();

        $offer = Offer::sole();
        $this->assertSame($this->bmw->id, $offer->brand_id);
        $this->assertSame('published', $offer->status);
        $this->assertSame('scheduled', $offer->state(), 'defaults to next month');
        $this->assertDatabaseHas('audit_logs', ['action' => 'offer.created', 'target_id' => $offer->id]);
    }

    public function test_copying_for_next_month_makes_a_draft(): void
    {
        $offer = $this->offer(['starts_on' => '2026-11-01', 'ends_on' => '2026-11-30']);
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test('pages::admin-offer', ['brand' => $this->bmw, 'offer' => $offer])->call('duplicateForNextMonth');

        $copy = Offer::latest('id')->first();
        $this->assertNotSame($offer->id, $copy->id);
        $this->assertSame('draft', $copy->status);
        $this->assertSame('2026-12-01', $copy->starts_on->toDateString());
        $this->assertSame('2026-12-30', $copy->ends_on->toDateString());
    }

    public function test_an_offer_is_only_editable_under_its_own_brand(): void
    {
        $offer = $this->offer();
        $this->actingAs(User::factory()->owner()->create());

        $this->get(route('admin.brands.offers.edit', [Brand::where('name', 'Audi')->value('id'), $offer->id]))->assertNotFound();
    }

    public function test_brand_offers_need_settings_manage(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_INTERNAL_STAFF, 'permissions' => [Permission::DEALERS_MANAGE]]))
            ->get(route('admin.brands.offers', $this->bmw))->assertForbidden();

        $this->actingAs(User::factory()->owner()->create())
            ->get(route('admin.brands.offers', $this->bmw))->assertOk()->assertSee('New BMW offer');
    }

    public function test_dealers_see_their_brands_offers_read_only(): void
    {
        $this->offer();
        $this->actingAs(User::factory()->staffOf($this->dealer)->create());

        $this->get('http://dealer.trucars.test/offers')->assertOk()->assertSee('BMW offers')->assertSee('3.99% APR')->assertDontSee('Participating');
    }

    public function test_the_car_page_shows_the_offer_and_uses_its_rate_for_the_payment(): void
    {
        $car = $this->car(['price_in_cents' => 3999500]);
        $this->offer();

        $this->get('/cars/' . $car->id)
            ->assertOk()
            ->assertSee('3.99% APR')
            ->assertSee('For up to 48 months')
            ->assertSee('On approved credit.')
            ->assertSee('Offer ends November 30, 2026')
            ->assertSee('est. 48 mo · 3.99% APR (offer, on approved credit)')
            ->assertSee('$' . number_format($car->biweeklyAt(3.99, 48)));
    }

    public function test_a_reservation_keeps_the_offer_exactly_as_shown(): void
    {
        Mail::fake();
        $car = $this->car();
        $offer = $this->offer();
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::checkout', ['vehicle' => $car])
            ->set('firstName', 'Sam')->set('lastName', 'Rivera')->set('phone', '6045550100')
            ->call('reserve');

        $offer->update(['apr' => 5.99]);

        $snapshot = Deal::sole()->offers_snapshot;
        $this->assertSame('3.99% APR', $snapshot[0]['headline']);
        $this->assertSame('BMW', $snapshot[0]['brand']);
        $this->assertStringContainsString('On approved credit', $snapshot[0]['conditions']);
    }

    private function offer(array $overrides = []): Offer
    {
        $offer = new Offer($overrides + [
            'brand_id' => $this->bmw->id, 'type' => 'apr', 'title' => 'Special CPO financing',
            'apr' => 3.99, 'term_months' => 48, 'certified_only' => false,
            'conditions' => 'On approved credit. Eligible BMW Certified vehicles only.',
            'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30',
        ]);
        $offer->status = 'published';
        $offer->save();

        return $offer;
    }

    private function car(array $overrides = [], ?Dealer $dealer = null): Vehicle
    {
        return ($dealer ?? $this->dealer)->vehicles()->create($overrides + [
            'vin' => Str::upper(Str::random(17)), 'model_year' => 2023, 'make' => 'BMW', 'model' => '330i',
            'kilometres' => 30000, 'price_in_cents' => 3999500, 'condition' => 'USED', 'is_published' => true,
        ]);
    }

    /** A fresh copy, with the once-per-request caches cleared. */
    private function fresh(Vehicle $vehicle): Vehicle
    {
        Once::flush();

        return $vehicle->fresh();
    }
}
