<?php

namespace Tests\Feature;

use App\Mail\EmailVerificationCode;
use App\Models\AuditLog;
use App\Models\Deal;
use App\Models\Dealer;
use App\Models\User;
use App\Support\Permission;
use App\Support\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\CreatesDealers;
use Tests\TestCase;

class CommandCentreSupportTest extends TestCase
{
    use CreatesDealers;
    use RefreshDatabase;

    private Dealer $dealer;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->dealer = $this->makeDealer();
    }

    /* ---- customers ----------------------------------------------------- */

    public function test_support_finds_customers_only_by_searching_and_it_is_audited(): void
    {
        $support = $this->support();
        User::factory()->create(['name' => 'Priya Sharma', 'email' => 'priya@example.com']);
        User::factory()->staffOf($this->dealer)->create(['name' => 'Priya Dealer', 'email' => 'priya@dealer.ca']);
        $this->staff([Permission::DEALERS_VIEW], ['name' => 'Priya Staff', 'email' => 'priya@trucars.ca']);
        $this->actingAs($support);

        $this->get(route('admin.customers'))->assertOk()->assertDontSee('Priya Sharma');

        Livewire::test('pages::admin-customers')
            ->set('query', 'pr')
            ->call('search')
            ->assertHasErrors('query')
            ->set('query', 'priya')
            ->call('search')
            ->assertSee('priya@example.com')
            ->assertDontSee('priya@dealer.ca')
            ->assertDontSee('priya@trucars.ca');

        $entry = AuditLog::where('action', 'customer.searched')->sole();
        $this->assertSame($support->id, $entry->actor_user_id);
        $this->assertSame(['query' => 'priya', 'results' => 1], $entry->after);
    }

    public function test_opening_a_customer_is_audited_and_shows_their_reservations(): void
    {
        $support = $this->support();
        $customer = User::factory()->create(['email' => 'sam@example.com']);
        $deal = $this->deal($customer);
        $this->actingAs($support);

        $this->get(route('admin.customers.show', $customer))
            ->assertOk()
            ->assertSee('sam@example.com')
            ->assertSee($deal->reference)
            ->assertDontSee('password');

        $this->assertDatabaseHas('audit_logs', ['action' => 'customer.viewed', 'actor_user_id' => $support->id, 'target_id' => $customer->id]);
    }

    public function test_support_cannot_open_staff_or_dealer_accounts_as_customers(): void
    {
        $this->actingAs($this->support());

        $this->get(route('admin.customers.show', User::factory()->staffOf($this->dealer)->create()))->assertNotFound();
        $this->get(route('admin.customers.show', User::factory()->owner()->create()))->assertNotFound();
    }

    public function test_assist_actions_need_customers_assist(): void
    {
        $customer = User::factory()->unverified()->create();
        $this->actingAs($this->staff([Permission::CUSTOMERS_VIEW]));

        Livewire::test('pages::admin-customer', ['customer' => $customer])->call('resendVerificationCode')->assertForbidden();
        Livewire::test('pages::admin-customer', ['customer' => $customer])->set('noteBody', 'hi')->call('addNote')->assertForbidden();

        Mail::assertNothingSent();
        $this->assertSame(0, $customer->supportNotes()->count());
    }

    public function test_support_resends_a_verification_code_and_adds_a_note(): void
    {
        $support = $this->support();
        $customer = User::factory()->unverified()->create(['email' => 'new@example.com']);
        $this->actingAs($support);

        Livewire::test('pages::admin-customer', ['customer' => $customer])
            ->call('resendVerificationCode')
            ->assertSee('Sent a new verification code')
            ->set('noteBody', 'Called about financing; resent verification.')
            ->call('addNote')
            ->assertSee('Called about financing; resent verification.');

        Mail::assertSent(EmailVerificationCode::class, fn ($mail) => $mail->hasTo('new@example.com'));
        $this->assertNotNull($customer->fresh()->email_verification_code);
        $this->assertSame($support->id, $customer->supportNotes()->sole()->author_user_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'customer.verification_resent', 'target_id' => $customer->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'customer.note_added', 'target_id' => $customer->id]);

        // The code itself is never written to the audit log.
        $code = $customer->fresh()->email_verification_code;
        $this->assertFalse(AuditLog::all()->contains(fn ($entry) => str_contains(json_encode($entry->toArray()), $code)));
    }

    public function test_customer_area_is_closed_without_customers_view(): void
    {
        $this->actingAs($this->staff([Permission::DEALERS_VIEW]));

        $this->get(route('admin.customers'))->assertForbidden();
        $this->get(route('admin.customers.show', User::factory()->create()))->assertForbidden();
    }

    /* ---- reservations -------------------------------------------------- */

    public function test_reservations_are_visible_across_dealers_read_only_and_audited(): void
    {
        $support = $this->support();
        $other = $this->makeDealer(['name' => 'Other Rooftop']);
        $ours = $this->deal(User::factory()->create(), $this->dealer);
        $theirs = $this->deal(User::factory()->create(), $other);
        $this->actingAs($support);

        $this->get(route('admin.reservations'))->assertOk()->assertSee($ours->reference)->assertSee($theirs->reference);
        $this->get(route('admin.reservations.show', $theirs))->assertOk()->assertSee('Other Rooftop')->assertSee('45510 Yale Rd');

        $this->assertDatabaseHas('audit_logs', ['action' => 'reservation.listed', 'actor_user_id' => $support->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'reservation.viewed', 'target_id' => $theirs->id]);
    }

    public function test_reservation_area_is_closed_without_reservations_view(): void
    {
        $deal = $this->deal(User::factory()->create());

        $this->actingAs($this->staff([Permission::DEALERS_VIEW]))->get(route('admin.reservations'))->assertForbidden();
        $this->actingAs($this->staff([Permission::DEALERS_VIEW]))->get(route('admin.reservations.show', $deal))->assertForbidden();
        $this->actingAs(User::factory()->staffOf($this->dealer)->create())->get(route('admin.reservations'))->assertForbidden();
    }

    /* ---- settings ------------------------------------------------------ */

    public function test_settings_change_the_marketplace_and_are_audited(): void
    {
        $owner = User::factory()->owner()->create();

        $this->get('/shop')->assertSee('Certified Pre-Owned cars')->assertDontSee('in the GTA');

        $this->actingAs($owner);
        Livewire::test('pages::admin-settings')
            ->set('values.marketplace_headline', 'Certified cars across BC')
            ->set('values.marketplace_notice', 'Closed Monday for Thanksgiving')
            ->call('save')
            ->assertHasNoErrors();
        auth()->logout();

        $this->get('/shop')->assertSee('Certified cars across BC')->assertSee('Closed Monday for Thanksgiving');

        $entry = AuditLog::where('action', 'settings.updated')->sole();
        $this->assertSame('Certified Pre-Owned cars', $entry->before['marketplace_headline']);
        $this->assertSame('Certified cars across BC', $entry->after['marketplace_headline']);
        $this->assertArrayNotHasKey('marketplace_subheadline', $entry->after);
    }

    public function test_settings_need_settings_manage_and_validate(): void
    {
        $this->actingAs($this->support())->get(route('admin.settings'))->assertForbidden();

        $this->actingAs(User::factory()->owner()->create());
        Livewire::test('pages::admin-settings')
            ->set('values.marketplace_headline', '')
            ->call('save')
            ->assertHasErrors('values.marketplace_headline');

        $this->assertSame('Certified Pre-Owned cars', PlatformSettings::get('marketplace_headline'));
    }

    /* ---- the real Livewire request path -------------------------------- */

    public function test_a_live_component_action_goes_through_for_an_owner(): void
    {
        $owner = User::factory()->owner()->create();
        [$updateUri, $payload] = $this->liveDealersPage($owner);

        $this->actingAs($owner)->postJson($updateUri, $payload, ['X-Livewire' => '1'])->assertOk();
    }

    /**
     * Through Livewire's real update endpoint: the page's route middleware is
     * re-applied to the action request, so someone who loses access mid-session
     * is refused even on a component action that has no check of its own.
     * (Separate from the test above because Livewire only re-runs it once per
     * request, and the test harness doesn't reset that between requests.)
     */
    public function test_a_live_component_action_is_refused_once_access_is_removed(): void
    {
        $owner = User::factory()->owner()->create();
        [$updateUri, $payload] = $this->liveDealersPage($owner);

        $owner->forceFill(['role' => User::ROLE_CUSTOMER])->save();

        $this->actingAs($owner->fresh())->postJson($updateUri, $payload, ['X-Livewire' => '1'])->assertForbidden();
    }

    /** Load the dealers page and build an action request for its component. */
    private function liveDealersPage(User $owner): array
    {
        $page = $this->actingAs($owner)->get(route('admin.dealers'))->assertOk()->getContent();

        preg_match('/wire:snapshot="([^"]+)"/', $page, $snapshot);
        preg_match('/data-update-uri="([^"]+)"/', $page, $updateUri);
        $this->assertNotEmpty($snapshot, 'component snapshot on the page');

        return [$updateUri[1], ['components' => [[
            'snapshot' => html_entity_decode($snapshot[1]),
            'updates'  => ['onlyNeedingAddress' => true],
            'calls'    => [],
        ]]]];
    }

    /* ---- helpers ----------------------------------------------------- */

    private function support(): User
    {
        return $this->staff(Permission::PRESETS['customer_support']['permissions']);
    }

    public function test_a_dealers_customers_tab_lists_only_that_dealers_buyers_and_is_audited(): void
    {
        $other = $this->makeDealer(['name' => 'Other Rooftop']);
        $ours = User::factory()->create(['name' => 'Avery Ours']);
        $theirs = User::factory()->create(['name' => 'Blake Theirs']);
        $this->deal($ours);
        $this->deal($ours);
        $this->deal($theirs, $other);

        $this->actingAs($this->staff(Permission::PRESETS['customer_support']['permissions']));

        $this->get(route('admin.dealers.customers', $this->dealer))
            ->assertOk()
            ->assertSee('Avery Ours')
            ->assertSee('2 reservations here')
            ->assertDontSee('Blake Theirs');

        $this->assertDatabaseHas('audit_logs', ['action' => 'dealer.customers_viewed', 'target_id' => $this->dealer->id]);

        $this->actingAs($this->staff([Permission::DEALERS_VIEW]))
            ->get(route('admin.dealers.customers', $this->dealer))
            ->assertForbidden();
    }

    public function test_the_home_dashboard_shows_live_numbers_and_what_needs_attention(): void
    {
        $customer = User::factory()->create(['name' => 'Avery Buyer']);
        $deal = $this->deal($customer);
        $deal->forceFill(['created_at' => now()->subDays(2)])->save();
        Dealer::create(['name' => 'Half Set Up Motors', 'city' => 'Toronto']);

        $this->actingAs(User::factory()->owner()->create(['name' => 'Shingi Owner']));

        $this->get(route('admin.home'))
            ->assertOk()
            ->assertSee('Shingi')
            ->assertSee('Reservations')
            ->assertSee('Deposits held')
            ->assertSee('$150')
            ->assertSee('1 reservation not contacted')
            ->assertSee('1 draft dealer')
            ->assertSee('Pat Lee')
            ->assertSee('Top dealers');

        // Support staff get only their panels.
        $this->actingAs($this->staff(Permission::PRESETS['customer_support']['permissions']));
        $this->get(route('admin.home'))->assertOk()->assertSee('Latest reservations')->assertDontSee('Top dealers')->assertDontSee('draft dealer');
    }

    private function staff(array $permissions, array $attributes = []): User
    {
        return User::factory()->create($attributes + [
            'role'        => User::ROLE_INTERNAL_STAFF,
            'permissions' => Permission::normalize($permissions),
        ]);
    }

    private function deal(User $customer, ?Dealer $dealer = null): Deal
    {
        $dealer ??= $this->dealer;
        $vehicle = $dealer->vehicles()->create([
            'vin' => Str::upper(Str::random(17)), 'model_year' => 2023, 'make' => 'Mitsubishi', 'model' => 'RVR',
            'kilometres' => 20000, 'price_in_cents' => 2999000, 'is_published' => true,
        ]);

        return Deal::create([
            'dealer_id' => $dealer->id, 'vehicle_id' => $vehicle->id, 'user_id' => $customer->id,
            'first_name' => 'Pat', 'last_name' => 'Lee', 'email' => $customer->email, 'phone' => '6045550199',
        ]);
    }
}
