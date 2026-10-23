<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Deal;
use App\Models\Dealer;
use App\Models\DealerGroup;
use App\Models\Lead;
use App\Models\Rooftop;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\CreatesDealers;
use Tests\TestCase;

class StoreAccessTest extends TestCase
{
    use CreatesDealers;
    use RefreshDatabase;

    private Dealer $dealer;

    private Rooftop $kelowna;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dealer = $this->makeDealer();
        $this->kelowna = $this->store($this->dealer, 'Kelowna store');
    }

    /* ---- the lead leak ------------------------------------------------- */

    public function test_staff_never_see_another_dealers_enquiries(): void
    {
        $rival = $this->makeDealer(['name' => 'RIVAL MOTORS']);
        $theirs = Lead::create(['dealer_id' => $rival->id, 'vehicle_id' => $this->car($rival)->id, 'name' => 'Rival Buyer', 'email' => 'rival@example.com', 'phone' => '1']);
        Lead::create(['dealer_id' => $this->dealer->id, 'vehicle_id' => $this->car($this->dealer)->id, 'name' => 'Our Buyer', 'email' => 'ours@example.com', 'phone' => '1']);

        $this->actingAs($this->staff($this->dealer));

        $this->assertSame(['Our Buyer'], Lead::pluck('name')->all());
        $this->get(route('dealer.lead', $theirs))->assertNotFound();
    }

    /* ---- store-limited staff ------------------------------------------- */

    public function test_staff_with_no_chosen_stores_see_every_store_of_their_dealer(): void
    {
        $this->deal($this->car($this->dealer));
        $this->deal($this->car($this->dealer, $this->kelowna));

        $this->actingAs($this->staff($this->dealer));

        $this->assertSame(2, Deal::count());
    }

    public function test_store_limited_staff_see_only_their_stores_deals_enquiries_and_cars(): void
    {
        $mainCar = $this->car($this->dealer);
        $kelownaCar = $this->car($this->dealer, $this->kelowna);
        $this->deal($mainCar);
        $kelownaDeal = $this->deal($kelownaCar);
        Lead::create(['dealer_id' => $this->dealer->id, 'vehicle_id' => $mainCar->id, 'name' => 'Main', 'email' => 'm@example.com', 'phone' => '1']);
        Lead::create(['dealer_id' => $this->dealer->id, 'vehicle_id' => $kelownaCar->id, 'name' => 'Kelowna', 'email' => 'k@example.com', 'phone' => '1']);

        $staff = $this->staff($this->dealer, [$this->kelowna]);
        $this->actingAs($staff);

        $this->assertSame([$kelownaDeal->id], Deal::pluck('id')->all());
        $this->assertSame(['Kelowna'], Lead::pluck('name')->all());
        $this->get(route('dealer.deal', Deal::withoutGlobalScopes()->where('vehicle_id', $mainCar->id)->first()))->assertNotFound();

        Livewire::test('pages::dealer-inventory')
            ->assertSee($kelownaCar->vin)
            ->assertDontSee($mainCar->vin);
    }

    /* ---- group staff --------------------------------------------------- */

    public function test_group_staff_switch_to_a_sibling_dealer_and_see_only_the_store_they_were_given(): void
    {
        [$sibling, $siblingStore] = $this->sibling();
        $siblingOther = $this->store($sibling, 'Abbotsford annex');
        $given = $this->deal($this->car($sibling, $siblingStore));
        $this->deal($this->car($sibling, $siblingOther));

        $staff = $this->staff($this->dealer, [$this->dealer->primaryRooftop, $siblingStore]);
        $this->actingAs($staff);

        $this->assertEqualsCanonicalizing([$this->dealer->id, $sibling->id], $staff->accessibleDealerIds());

        $this->post(route('dealer.switch'), ['dealer_id' => $sibling->id])->assertRedirect(route('dealer.reservations'));

        $this->assertSame($sibling->id, $staff->fresh()->portalDealerId());
        $this->assertSame([$given->id], Deal::pluck('id')->all());
    }

    public function test_a_store_outside_the_group_is_never_reachable(): void
    {
        $outsider = $this->makeDealer(['name' => 'OUTSIDE MOTORS']);
        $staff = $this->staff($this->dealer, [$this->dealer->primaryRooftop, $outsider->primaryRooftop]);
        $this->actingAs($staff);

        $this->assertSame([$this->dealer->id], $staff->accessibleDealerIds());
        $this->post(route('dealer.switch'), ['dealer_id' => $outsider->id])->assertForbidden();
    }

    public function test_staff_whose_stores_were_all_taken_away_are_shut_out(): void
    {
        $outsider = $this->makeDealer(['name' => 'OUTSIDE MOTORS']);
        $this->actingAs($this->staff($this->dealer, [$outsider->primaryRooftop]));

        $this->get(route('dealer.reservations'))->assertForbidden();
    }

    public function test_the_all_stores_page_rolls_up_only_granted_stores(): void
    {
        [$sibling, $siblingStore] = $this->sibling();
        $hidden = $this->store($sibling, 'Hidden annex');
        $this->deal($this->car($sibling, $siblingStore));
        $this->deal($this->car($sibling, $hidden));

        $this->actingAs($this->staff($this->dealer, [$this->dealer->primaryRooftop, $siblingStore]));

        $this->get(route('dealer.group'))->assertOk()
            ->assertSee('Abbotsford Store')
            ->assertSee('All 2 stores')
            ->assertDontSee('Hidden Annex');
    }

    public function test_admin_can_only_grant_stores_inside_the_group(): void
    {
        [$sibling, $siblingStore] = $this->sibling();
        $outsider = $this->makeDealer(['name' => 'OUTSIDE MOTORS']);
        $staff = $this->staff($this->dealer);
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test('pages::admin-dealer', ['dealer' => $this->dealer])
            ->set('tab', 'team')
            ->call('startStoreAccess', $staff->id)
            ->set('accessAllStores', false)
            ->set('accessStoreIds', [(string) $siblingStore->id, (string) $outsider->primaryRooftop->id])
            ->call('saveStoreAccess')
            ->assertHasNoErrors();

        $this->assertSame([$siblingStore->id], $staff->storeGrants()->pluck('rooftops.id')->all());
        $this->assertDatabaseHas('audit_logs', ['action' => 'dealer_user.store_access_changed']);

        Livewire::test('pages::admin-dealer', ['dealer' => $this->dealer])
            ->call('startStoreAccess', $staff->id)
            ->set('accessAllStores', true)
            ->call('saveStoreAccess');

        $this->assertSame(0, $staff->storeGrants()->count());
    }

    /* ---- single-store campaigns ---------------------------------------- */

    public function test_a_store_campaign_shows_only_that_stores_cars(): void
    {
        $main = $this->car($this->dealer, null, ['is_manufacturer_certified' => true]);
        $kelownaCar = $this->car($this->dealer, $this->kelowna, ['is_manufacturer_certified' => true]);
        $this->actingAs($this->staff($this->dealer));

        Livewire::test('pages::campaigns')
            ->call('startCreate')
            ->set('campaignName', 'Kelowna spring')
            ->set('campaignStoreId', (string) $this->kelowna->id)
            ->call('saveCampaign', 'activate')
            ->assertHasNoErrors();

        $campaign = Campaign::withoutGlobalScopes()->sole();
        $this->assertSame($this->kelowna->id, $campaign->rooftop_id);

        auth()->logout();
        $this->get($campaign->shopUrl())->assertOk()
            ->assertSee(route('campaign.vehicle', ['token' => $campaign->token, 'vehicle' => $kelownaCar->id]), false)
            ->assertDontSee(route('campaign.vehicle', ['token' => $campaign->token, 'vehicle' => $main->id]), false);
    }

    public function test_store_limited_staff_cannot_make_a_whole_dealer_campaign(): void
    {
        $this->car($this->dealer, $this->kelowna, ['is_manufacturer_certified' => true]);
        $this->actingAs($this->staff($this->dealer, [$this->kelowna]));

        Livewire::test('pages::campaigns')
            ->call('startCreate')
            ->assertSet('campaignStoreId', (string) $this->kelowna->id)
            ->set('campaignName', 'Everywhere')
            ->set('campaignStoreId', '')
            ->call('saveCampaign')
            ->assertHasErrors('campaignStoreId');

        $this->assertSame(0, Campaign::withoutGlobalScopes()->count());
    }

    /* ---- hours and pickup points --------------------------------------- */

    public function test_checkout_only_offers_windows_inside_store_hours(): void
    {
        // Wednesday 1 July 2026, noon UTC.
        Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:00', 'UTC'));
        $this->kelowna->update([
            'hours' => ['mon' => ['09:00', '18:00'], 'tue' => ['09:00', '18:00'], 'wed' => ['09:00', '18:00'], 'thu' => null,
                'fri' => ['11:00', '15:00'], 'sat' => ['09:00', '18:00'], 'sun' => null],
            'pickup_points' => [['name' => 'Airport lot', 'address' => '5533 Airport Way, Kelowna']],
        ]);
        $car = $this->car($this->dealer, $this->kelowna);
        $this->actingAs(User::factory()->create());

        $checkout = Livewire::test('pages::checkout', ['vehicle' => $car]);
        $page = $checkout->instance();

        // Thursday is closed, so the first day offered is Friday.
        $this->assertSame('2026-07-03', $checkout->get('handoverDate'));
        $this->assertNotContains('2026-07-02', array_column($page->handoverDateOptions(), 'date'));
        $this->assertNotContains('2026-07-05', array_column($page->handoverDateOptions(), 'date'));
        // Friday 11–3 fits only the 12–2 window.
        $this->assertSame(['12:00'], array_keys($page->availableTimeSlots('2026-07-03')));
        $this->assertSame('12:00', $checkout->get('handoverTimeSlot'));

        $this->assertSame(['Kelowna Store', 'Airport lot'], array_column($page->pickupSpots(), 'name'));

        Carbon::setTestNow();
    }

    public function test_without_hours_every_window_is_offered(): void
    {
        $this->actingAs(User::factory()->create());
        $checkout = Livewire::test('pages::checkout', ['vehicle' => $this->car($this->dealer, $this->kelowna)]);

        $this->assertCount(6, $checkout->instance()->handoverDateOptions());
        $this->assertCount(4, $checkout->instance()->availableTimeSlots($checkout->get('handoverDate')));
    }

    public function test_admin_sets_hours_and_pickup_points_for_the_main_store(): void
    {
        $main = $this->dealer->primaryRooftop;
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test('pages::admin-dealer', ['dealer' => $this->dealer])
            ->set('tab', 'location')
            ->call('startHours', $main->id)
            ->set('hoursKnown', true)
            ->set('hoursForm.sun.open', false)
            ->set('hoursForm.mon.until', '08:00')
            ->call('saveHours')
            ->assertHasErrors('hoursForm.mon.until')
            ->set('hoursForm.mon.until', '17:30')
            ->set('pickupForm.0.name', 'Downtown lot')
            ->set('pickupForm.0.address', '1 Main St')
            ->call('saveHours')
            ->assertHasNoErrors();

        $main->refresh();
        $this->assertSame(['09:00', '17:30'], $main->hours['mon']);
        $this->assertNull($main->hours['sun']);
        $this->assertSame([['name' => 'Downtown lot', 'address' => '1 Main St']], $main->pickupPoints());

        // Saving the dealer's address doesn't wipe them.
        $this->dealer->update(['street_address' => '2 Other Rd']);
        $this->assertSame(['09:00', '17:30'], $main->fresh()->hours['mon']);
    }

    /* ---- helpers -------------------------------------------------------- */

    /** @return array{0: Dealer, 1: Rooftop} a second dealer in the same group, and its main store */
    private function sibling(): array
    {
        $group = DealerGroup::create(['name' => 'Fraser Valley Auto Group']);
        $this->dealer->update(['dealer_group_id' => $group->id]);
        $sibling = $this->makeDealer(['name' => 'ABBOTSFORD STORE', 'dealer_group_id' => $group->id]);

        return [$sibling, $sibling->primaryRooftop];
    }

    private function store(Dealer $dealer, string $name): Rooftop
    {
        return $dealer->rooftops()->create([
            'name' => $name, 'street_address' => '1 Store Rd', 'city' => 'Kelowna', 'province' => 'BC',
            'postal_code' => 'V1Y 1A1', 'country' => 'CA', 'timezone' => 'America/Vancouver',
        ]);
    }

    private function staff(Dealer $dealer, array $stores = []): User
    {
        $staff = User::factory()->staffOf($dealer)->create();
        $staff->storeGrants()->sync(collect($stores)->pluck('id'));

        return $staff;
    }

    private function car(Dealer $dealer, ?Rooftop $store = null, array $overrides = []): Vehicle
    {
        return $dealer->vehicles()->create($overrides + [
            'rooftop_id' => $store?->id,
            'vin' => Str::upper(Str::random(17)), 'model_year' => 2022, 'make' => 'Mitsubishi', 'model' => 'Outlander',
            'kilometres' => 30000, 'price_in_cents' => 2999000, 'condition' => 'USED', 'is_published' => true,
        ]);
    }

    private function deal(Vehicle $car): Deal
    {
        return Deal::withoutGlobalScopes()->create([
            'dealer_id' => $car->dealer_id, 'vehicle_id' => $car->id, 'user_id' => User::factory()->create()->id,
            'first_name' => 'Pat', 'last_name' => 'Lee', 'email' => 'pat@example.com', 'phone' => '6045550199', 'purchase_type' => 'cash',
        ]);
    }
}
