<?php

namespace Tests\Feature;

use App\Mail\DealerInvitationMail;
use App\Models\Deal;
use App\Models\Dealer;
use App\Models\DealerInvitation;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\CreatesDealers;
use Tests\TestCase;

class OwnerAccessTest extends TestCase
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

    /* ---- hosts -------------------------------------------------------- */

    public function test_admin_area_lives_on_its_configured_host_only(): void
    {
        $this->assertSame('admin.trucars.test', config('app.admin_domain'));
        $this->assertSame('admin.trucars.test', parse_url(route('admin.dealers'), PHP_URL_HOST));

        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->get('http://' . config('app.staff_domain') . '/dealers')->assertNotFound();
        $this->actingAs($owner)->get('http://trucars.test/dealers')->assertNotFound();
        $this->actingAs($owner)->get(route('admin.dealers'))->assertOk();
    }

    public function test_guests_are_sent_to_the_sign_in_page_for_the_host_they_tried(): void
    {
        $this->get(route('admin.dealers'))->assertRedirect(route('admin.login'));
        $this->get(route('dealer.reservations'))->assertRedirect(route('dealer.login'));
        $this->post(route('buyer.logout'))->assertRedirect(route('buyer.login'));
    }

    public function test_admin_pages_refuse_dealer_staff_and_customers(): void
    {
        foreach ([User::factory()->staffOf($this->dealer)->create(), User::factory()->create()] as $user) {
            $this->actingAs($user)->get(route('admin.dealers'))->assertForbidden();
            $this->actingAs($user)->get(route('admin.dealers.edit', $this->dealer))->assertForbidden();
            $this->actingAs($user)->get(route('admin.dealers.create'))->assertForbidden();
        }
    }

    public function test_admin_actions_are_checked_on_the_server_not_just_the_page(): void
    {
        $this->actingAs(User::factory()->staffOf($this->dealer)->create());

        Livewire::test('pages::admin-dealer', ['dealer' => $this->dealer])->assertForbidden();
        Livewire::test('pages::admin-dealers')->assertForbidden();

        // An owner suspended mid-session can't keep acting from an open page.
        $owner = User::factory()->owner()->create();
        $this->actingAs($owner);
        $page = Livewire::test('pages::admin-dealer', ['dealer' => $this->dealer]);

        $owner->forceFill(['suspended_at' => now()])->save();

        $page->set('name', 'Hijacked')->call('save')->assertForbidden();
        $this->assertNotSame('Hijacked', $this->dealer->fresh()->name);
    }

    /* ---- sign-in is per portal ---------------------------------------- */

    public function test_owner_signs_in_only_on_the_admin_host(): void
    {
        $owner = User::factory()->owner()->create(['email' => 'owner@trucars.ca']);

        $this->get(route('admin.login'))->assertOk()->assertSee('TruCars Command Centre');

        Livewire::test('pages::login', ['portal' => 'owner'])
            ->set('email', 'owner@trucars.ca')->set('password', 'password')
            ->call('signIn')
            ->assertRedirect(route('admin.home'));
        $this->assertAuthenticatedAs($owner);
        auth()->logout();

        Livewire::test('pages::login', ['portal' => 'dealer'])
            ->set('email', 'owner@trucars.ca')->set('password', 'password')
            ->call('signIn')
            ->assertHasErrors('email');
        $this->assertGuest();

        Livewire::test('pages::buyer-login')
            ->set('email', 'owner@trucars.ca')->set('password', 'password')
            ->call('signIn')
            ->assertHasErrors('email');
        $this->assertGuest();
    }

    public function test_staff_and_customers_cannot_sign_in_to_the_admin_host(): void
    {
        User::factory()->staffOf($this->dealer)->create(['email' => 'desk@dealer.ca']);
        User::factory()->create(['email' => 'buyer@example.com']);

        foreach (['desk@dealer.ca', 'buyer@example.com'] as $email) {
            Livewire::test('pages::login', ['portal' => 'owner'])
                ->set('email', $email)->set('password', 'password')
                ->call('signIn')
                ->assertHasErrors('email');
            $this->assertGuest();
        }

        // And a customer can't use the dealer portal either.
        Livewire::test('pages::login', ['portal' => 'dealer'])
            ->set('email', 'buyer@example.com')->set('password', 'password')
            ->call('signIn')
            ->assertHasErrors('email');
        $this->assertGuest();
    }

    public function test_staff_or_owner_session_is_a_guest_on_the_marketplace(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->get(route('garage'))
            ->assertRedirect(route('buyer.login'));
    }

    /* ---- accounts ---------------------------------------------------- */

    public function test_public_registration_only_creates_customers(): void
    {
        Livewire::test('pages::buyer-register')
            ->set('name', 'Sam Buyer')
            ->set('email', 'sam@example.com')
            ->set('password', 'password123')
            ->set('password_confirmation', 'password123')
            ->call('createAccount');

        $user = User::where('email', 'sam@example.com')->sole();
        $this->assertTrue($user->isCustomer());
        $this->assertNull($user->dealer_id);

        $sneaky = User::create(['name' => 'X', 'email' => 'x@example.com', 'password' => 'secret123', 'role' => 'owner', 'dealer_id' => $this->dealer->id]);
        $this->assertTrue($sneaky->fresh()->isCustomer());
        $this->assertNull($sneaky->fresh()->dealer_id);
    }

    public function test_make_owner_command_creates_an_owner_with_a_hidden_password(): void
    {
        $this->artisan('users:make-owner', ['email' => 'Ops@TruCars.ca', '--name' => 'Ops'])
            ->expectsQuestion('Password for the new owner (min 12 characters)', 'a-long-passphrase')
            ->assertSuccessful();

        $owner = User::where('email', 'ops@trucars.ca')->sole();
        $this->assertTrue($owner->isOwner());
        $this->assertNotSame('a-long-passphrase', $owner->password);

        $staff = User::factory()->staffOf($this->dealer)->create(['email' => 'desk@dealer.ca']);
        $this->artisan('users:make-owner', ['email' => 'desk@dealer.ca'])->assertFailed();
        $this->assertTrue($staff->fresh()->isDealerStaff());
    }

    /* ---- dealer suspension ------------------------------------------- */

    public function test_suspending_a_dealer_unlists_its_cars_and_locks_out_its_staff(): void
    {
        $vehicle = $this->dealer->vehicles()->create([
            'vin' => Str::upper(Str::random(17)), 'model_year' => 2023, 'make' => 'Mitsubishi', 'model' => 'Suspended Outlander',
            'kilometres' => 20000, 'price_in_cents' => 2999000, 'is_published' => true, 'is_certified' => true,
        ]);
        $staff = User::factory()->staffOf($this->dealer)->create(['email' => 'desk@dealer.ca']);

        $this->actingAs(User::factory()->owner()->create());
        Livewire::test('pages::admin-dealer', ['dealer' => $this->dealer])
            ->call('changeStatus', Dealer::STATUS_SUSPENDED)
            ->assertHasNoErrors();

        $this->assertTrue($this->dealer->fresh()->isSuspended());
        auth()->logout();

        $this->get('/shop')->assertDontSee('Suspended Outlander');
        $this->get('/cars/' . $vehicle->id)->assertNotFound();
        $this->actingAs($staff)->get(route('dealer.reservations'))->assertForbidden();
        auth()->logout();

        Livewire::test('pages::login', ['portal' => 'dealer'])
            ->set('email', 'desk@dealer.ca')->set('password', 'password')
            ->call('signIn')
            ->assertHasErrors('email');
    }

    public function test_reactivating_a_suspended_dealer_needs_a_complete_address(): void
    {
        $legacy = Dealer::create(['name' => 'Legacy Nissan', 'city' => 'Toronto', 'status' => Dealer::STATUS_SUSPENDED]);
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test('pages::admin-dealer', ['dealer' => $legacy])
            ->call('changeStatus', Dealer::STATUS_ACTIVE)
            ->assertHasErrors('status')
            ->assertSee('Complete the address and timezone');

        // Saving the profile can't change the status either.
        Livewire::test('pages::admin-dealer', ['dealer' => $legacy])
            ->set('status', Dealer::STATUS_ACTIVE)
            ->call('save');

        $this->assertTrue($legacy->fresh()->isSuspended());
    }

    /* ---- dealer users ------------------------------------------------ */

    public function test_owner_invites_staff_who_set_their_own_password(): void
    {
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test('pages::admin-dealer', ['dealer' => $this->dealer])
            ->set('inviteName', 'Dana Desk')
            ->set('inviteEmail', 'Dana@Dealer.ca')
            ->call('inviteStaff')
            ->assertHasNoErrors()
            ->assertSee('dana@dealer.ca');

        $acceptUrl = $this->sentInvitationUrl();
        $this->assertSame(config('app.staff_domain'), parse_url($acceptUrl, PHP_URL_HOST));
        $this->assertNull(User::where('email', 'dana@dealer.ca')->first(), 'No account until the invite is accepted.');

        // The plain token is only in the email.
        $token = basename(parse_url($acceptUrl, PHP_URL_PATH));
        $this->assertSame(hash('sha256', $token), DealerInvitation::sole()->token_hash);
        $this->assertDatabaseMissing('dealer_invitations', ['token_hash' => $token]);

        auth()->logout();
        $this->get($acceptUrl)->assertOk()->assertSee('Join Chilliwack Mitsubishi on TruCars');

        Livewire::test('pages::accept-invitation', ['token' => $token])
            ->set('password', 'a-strong-password')
            ->set('password_confirmation', 'a-strong-password')
            ->call('accept')
            ->assertRedirect(route('dealer.reservations'));

        $staff = User::where('email', 'dana@dealer.ca')->sole();
        $this->assertTrue($staff->isDealerStaff());
        $this->assertSame($this->dealer->id, $staff->dealer_id);
        $this->assertNotNull($staff->email_verified_at);
        $this->assertAuthenticatedAs($staff);
        auth()->logout();

        // Single use.
        Livewire::test('pages::accept-invitation', ['token' => $token])->assertSet('isUsable', false);
    }

    public function test_expired_or_revoked_invitations_cannot_be_used(): void
    {
        [$expired, $expiredToken] = DealerInvitation::issue($this->dealer, 'Old', 'old@dealer.ca', null);
        $expired->forceFill(['expires_at' => now()->subMinute()])->save();

        [, $firstToken] = DealerInvitation::issue($this->dealer, 'Twice', 'twice@dealer.ca', null);
        [, $secondToken] = DealerInvitation::issue($this->dealer, 'Twice', 'twice@dealer.ca', null);

        Livewire::test('pages::accept-invitation', ['token' => $expiredToken])->assertSet('isUsable', false);
        Livewire::test('pages::accept-invitation', ['token' => $firstToken])->assertSet('isUsable', false);
        Livewire::test('pages::accept-invitation', ['token' => $secondToken])->assertSet('isUsable', true);
    }

    public function test_invite_to_an_existing_account_is_refused(): void
    {
        User::factory()->create(['email' => 'buyer@example.com']);
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test('pages::admin-dealer', ['dealer' => $this->dealer])
            ->set('inviteName', 'Buyer')
            ->set('inviteEmail', 'buyer@example.com')
            ->call('inviteStaff')
            ->assertHasErrors('inviteEmail');

        Mail::assertNothingSent();
        $this->assertTrue(User::where('email', 'buyer@example.com')->sole()->isCustomer());
    }

    public function test_owner_can_suspend_only_this_dealers_staff(): void
    {
        $ours = User::factory()->staffOf($this->dealer)->create();
        $theirs = User::factory()->staffOf($this->makeDealer(['name' => 'Other Rooftop']))->create();
        $this->actingAs(User::factory()->owner()->create());

        $page = Livewire::test('pages::admin-dealer', ['dealer' => $this->dealer])->call('suspendStaff', $ours->id);
        $this->assertTrue($ours->fresh()->isSuspended());

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $page->call('suspendStaff', $theirs->id);
    }

    public function test_suspended_staff_lose_portal_access_immediately(): void
    {
        $staff = User::factory()->staffOf($this->dealer)->create();
        $staff->forceFill(['suspended_at' => now()])->save();

        $this->actingAs($staff)->get(route('dealer.reservations'))->assertForbidden();
    }

    /* ---- record ownership --------------------------------------------- */

    public function test_dealer_staff_cannot_open_another_dealers_deal(): void
    {
        $other = $this->makeDealer(['name' => 'Other Rooftop']);
        $theirDeal = $this->deal($other);

        $this->actingAs(User::factory()->staffOf($this->dealer)->create())
            ->get(route('dealer.deal', $theirDeal))
            ->assertNotFound();
    }

    public function test_customers_see_only_their_own_reservations(): void
    {
        $me = User::factory()->create();
        $someoneElse = User::factory()->create();
        $mine = $this->deal($this->dealer, $me);
        $theirs = $this->deal($this->dealer, $someoneElse);

        $this->actingAs($me)->get(route('garage'))
            ->assertOk()
            ->assertSee($mine->reference)
            ->assertDontSee($theirs->reference);
    }

    /* ---- helpers ----------------------------------------------------- */

    private function sentInvitationUrl(): string
    {
        $url = null;

        Mail::assertSent(DealerInvitationMail::class, function (DealerInvitationMail $mail) use (&$url) {
            $url = $mail->acceptUrl;

            return true;
        });

        return $url;
    }

    private function deal(Dealer $dealer, ?User $buyer = null): Deal
    {
        $vehicle = $dealer->vehicles()->create([
            'vin' => Str::upper(Str::random(17)), 'model_year' => 2023, 'make' => 'Mitsubishi', 'model' => 'RVR',
            'kilometres' => 20000, 'price_in_cents' => 2999000, 'is_published' => true,
        ]);

        return Deal::create([
            'dealer_id' => $dealer->id, 'vehicle_id' => $vehicle->id, 'user_id' => $buyer?->id,
            'first_name' => 'Pat', 'last_name' => 'Lee', 'email' => 'pat@example.com', 'phone' => '6045550199',
        ]);
    }
}
