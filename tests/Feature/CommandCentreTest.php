<?php

namespace Tests\Feature;

use App\Mail\StaffInvitationMail;
use App\Models\AuditLog;
use App\Models\Dealer;
use App\Models\StaffInvitation;
use App\Models\User;
use App\Support\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use LogicException;
use Tests\Concerns\CreatesDealers;
use Tests\TestCase;

class CommandCentreTest extends TestCase
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

    /* ---- permission model --------------------------------------------- */

    public function test_permissions_normalise_and_imply_their_view_permission(): void
    {
        $this->assertSame(
            [Permission::CUSTOMERS_VIEW, Permission::CUSTOMERS_ASSIST, Permission::DEALERS_VIEW, Permission::DEALERS_MANAGE],
            Permission::normalize([Permission::DEALERS_MANAGE, Permission::CUSTOMERS_ASSIST, 'root.everything', Permission::DEALERS_MANAGE]),
        );
    }

    public function test_customer_support_preset_holds_no_dealer_staff_or_settings_access(): void
    {
        $support = $this->staff(Permission::PRESETS['customer_support']['permissions']);

        $this->assertTrue($support->can(Permission::CUSTOMERS_VIEW));
        $this->assertTrue($support->can(Permission::RESERVATIONS_VIEW));

        foreach ([Permission::DEALERS_VIEW, Permission::DEALERS_MANAGE, Permission::DEALER_USERS_MANAGE, Permission::STAFF_MANAGE, Permission::SETTINGS_MANAGE, Permission::AUDIT_VIEW] as $permission) {
            $this->assertFalse($support->can($permission), $permission);
        }
    }

    public function test_owner_holds_everything_and_suspension_removes_everything(): void
    {
        $owner = User::factory()->owner()->create();
        $staff = $this->staff([Permission::DEALERS_VIEW]);

        foreach (Permission::all() as $permission) {
            $this->assertTrue($owner->can($permission), $permission);
        }

        $staff->forceFill(['suspended_at' => now()])->save();
        $this->assertFalse($staff->fresh()->can(Permission::DEALERS_VIEW));
        $this->assertFalse($staff->fresh()->canUseCommandCentre());
    }

    public function test_customers_and_dealer_staff_hold_no_command_centre_permissions(): void
    {
        $customer = User::factory()->create();
        $dealerStaff = User::factory()->staffOf($this->dealer)->create();

        // Even with a permissions list forced onto the row.
        $customer->forceFill(['permissions' => Permission::all()])->save();

        foreach (Permission::all() as $permission) {
            $this->assertFalse($customer->fresh()->can($permission));
            $this->assertFalse($dealerStaff->can($permission));
        }
    }

    /* ---- enforcement -------------------------------------------------- */

    public function test_support_staff_reach_the_command_centre_but_not_dealer_staff_or_audit_areas(): void
    {
        $this->actingAs($this->staff(Permission::PRESETS['customer_support']['permissions']));

        $this->get(route('admin.home'))->assertOk()
            ->assertSee(route('admin.customers'))
            ->assertSee(route('admin.reservations'))
            ->assertDontSee(route('admin.dealers'))
            ->assertDontSee(route('admin.staff'))
            ->assertDontSee(route('admin.settings'));
        $this->get(route('admin.dealers'))->assertForbidden();
        $this->get(route('admin.dealers.edit', $this->dealer))->assertForbidden();
        $this->get(route('admin.staff'))->assertForbidden();
        $this->get(route('admin.audit'))->assertForbidden();

        Livewire::test('pages::admin-dealer', ['dealer' => $this->dealer])->assertForbidden();
        Livewire::test('pages::admin-staff')->assertForbidden();
    }

    public function test_view_only_staff_can_read_a_dealer_but_not_change_it(): void
    {
        $this->actingAs($this->staff([Permission::DEALERS_VIEW]));

        $this->get(route('admin.dealers.edit', $this->dealer))
            ->assertOk()
            ->assertDontSee('Save changes')
            ->assertDontSee('Dealer users');
        $this->get(route('admin.dealers.create'))->assertForbidden();

        // Each forbidden action on a fresh component (a 403 ends the test component).
        Livewire::test('pages::admin-dealer', ['dealer' => $this->dealer])
            ->set('status', Dealer::STATUS_SUSPENDED)->call('save')->assertForbidden();
        Livewire::test('pages::admin-dealer', ['dealer' => $this->dealer])
            ->set('inviteName', 'X')->set('inviteEmail', 'x@dealer.ca')->call('inviteStaff')->assertForbidden();

        $this->assertTrue($this->dealer->fresh()->isActive());
        Mail::assertNothingSent();
    }

    public function test_internal_staff_cannot_use_the_dealer_portal_or_marketplace_account(): void
    {
        $staff = $this->staff([Permission::DEALERS_VIEW], ['email' => 'ops@trucars.ca']);

        $this->actingAs($staff)->get(route('dealer.reservations'))->assertForbidden();
        $this->actingAs($staff)->get(route('garage'))->assertRedirect(route('buyer.login'));
        auth()->logout();

        Livewire::test('pages::login', ['portal' => 'dealer'])
            ->set('email', 'ops@trucars.ca')->set('password', 'password')->call('signIn')->assertHasErrors('email');
        Livewire::test('pages::buyer-login')
            ->set('email', 'ops@trucars.ca')->set('password', 'password')->call('signIn')->assertHasErrors('email');
        $this->assertGuest();

        Livewire::test('pages::login', ['portal' => 'owner'])
            ->set('email', 'ops@trucars.ca')->set('password', 'password')->call('signIn')
            ->assertRedirect(route('admin.home'));
        $this->assertAuthenticatedAs($staff);
        $this->assertTrue(AuditLog::where('action', 'command_centre.signed_in')->where('actor_user_id', $staff->id)->exists());
    }

    public function test_revoked_staff_lose_access_mid_session(): void
    {
        $staff = $this->staff([Permission::DEALERS_MANAGE]);
        $this->actingAs($staff);
        $page = Livewire::test('pages::admin-dealer', ['dealer' => $this->dealer]);

        $staff->forceFill(['suspended_at' => now()])->save();

        $page->set('name', 'Changed after revocation')->call('save')->assertForbidden();
        $this->assertNotSame('Changed after revocation', $this->dealer->fresh()->name);
        $this->get(route('admin.home'))->assertForbidden();
    }

    /* ---- staff invitations ------------------------------------------- */

    public function test_owner_invites_support_staff_who_set_their_own_password(): void
    {
        $owner = User::factory()->owner()->create();
        $this->actingAs($owner);

        Livewire::test('pages::admin-staff')
            ->set('inviteName', 'Sam Support')
            ->set('inviteEmail', 'Sam@TruCars.ca')
            ->call('applyPreset', 'customer_support')
            ->call('invite')
            ->assertHasNoErrors()
            ->assertSee('sam@trucars.ca');

        $url = null;
        Mail::assertSent(StaffInvitationMail::class, function (StaffInvitationMail $mail) use (&$url) {
            $url = $mail->acceptUrl;

            return true;
        });

        $this->assertSame(config('app.admin_domain'), parse_url($url, PHP_URL_HOST));
        $token = basename(parse_url($url, PHP_URL_PATH));
        $this->assertNull(User::where('email', 'sam@trucars.ca')->first());

        auth()->logout();
        $this->get($url)->assertOk()->assertSee('Join the TruCars Command Centre');

        Livewire::test('pages::accept-staff-invitation', ['token' => $token])
            ->set('password', 'a-strong-password')
            ->set('password_confirmation', 'a-strong-password')
            ->call('accept')
            ->assertRedirect(route('admin.home'));

        $sam = User::where('email', 'sam@trucars.ca')->sole();
        $this->assertTrue($sam->isInternalStaff());
        $this->assertNull($sam->dealer_id);
        $this->assertSame(Permission::PRESETS['customer_support']['permissions'], $sam->permissions);
        $this->assertFalse($sam->can(Permission::DEALERS_VIEW));

        $this->assertDatabaseHas('audit_logs', ['action' => 'staff.invited', 'actor_user_id' => $owner->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'staff.joined', 'actor_user_id' => $sam->id]);

        auth()->logout();
        Livewire::test('pages::accept-staff-invitation', ['token' => $token])->assertSet('isUsable', false);
    }

    public function test_only_staff_managers_can_invite_and_never_beyond_their_own_access(): void
    {
        // No staff.manage: refused outright.
        $this->actingAs($this->staff([Permission::DEALERS_VIEW]));
        $this->get(route('admin.staff'))->assertForbidden();

        // staff.manage without dealers.manage: can't hand out dealers.manage.
        $this->actingAs($this->staff([Permission::STAFF_MANAGE, Permission::CUSTOMERS_VIEW]));

        Livewire::test('pages::admin-staff')
            ->set('inviteName', 'Climber')
            ->set('inviteEmail', 'climber@trucars.ca')
            ->set('invitePermissions', [Permission::CUSTOMERS_VIEW, Permission::DEALERS_MANAGE])
            ->call('invite')
            ->assertHasErrors('invitePermissions');

        $this->assertSame(0, StaffInvitation::count());
        Mail::assertNothingSent();
    }

    public function test_invite_to_an_existing_account_is_refused(): void
    {
        User::factory()->create(['email' => 'buyer@example.com']);
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test('pages::admin-staff')
            ->set('inviteName', 'Buyer')
            ->set('inviteEmail', 'buyer@example.com')
            ->call('invite')
            ->assertHasErrors('inviteEmail');

        $this->assertTrue(User::where('email', 'buyer@example.com')->sole()->isCustomer());
    }

    /* ---- changing access ----------------------------------------------- */

    public function test_owner_changes_staff_permissions_and_it_is_audited(): void
    {
        $owner = User::factory()->owner()->create();
        $staff = $this->staff([Permission::CUSTOMERS_VIEW]);
        $this->actingAs($owner);

        Livewire::test('pages::admin-staff')
            ->call('startEdit', $staff->id)
            ->set('editPermissions', [Permission::CUSTOMERS_VIEW, Permission::DEALERS_VIEW])
            ->call('savePermissions');

        $this->assertSame([Permission::CUSTOMERS_VIEW, Permission::DEALERS_VIEW], $staff->fresh()->permissions);

        $entry = AuditLog::where('action', 'staff.permissions_changed')->sole();
        $this->assertSame($owner->id, $entry->actor_user_id);
        $this->assertSame($staff->id, $entry->target_id);
        $this->assertSame([Permission::CUSTOMERS_VIEW], $entry->before['permissions']);
        $this->assertSame([Permission::CUSTOMERS_VIEW, Permission::DEALERS_VIEW], $entry->after['permissions']);
    }

    public function test_a_manager_cannot_strip_or_grant_access_beyond_their_own(): void
    {
        $member = $this->staff([Permission::CUSTOMERS_VIEW, Permission::DEALERS_VIEW, Permission::DEALERS_MANAGE]);
        $this->actingAs($this->staff([Permission::STAFF_MANAGE, Permission::CUSTOMERS_VIEW]));

        Livewire::test('pages::admin-staff')
            ->call('startEdit', $member->id)
            ->set('editPermissions', [Permission::SETTINGS_MANAGE]) // not theirs to give; drops customers.view
            ->call('savePermissions');

        // customers.view (theirs to manage) removed; dealers.* (not theirs) untouched; settings not granted.
        $this->assertSame([Permission::DEALERS_VIEW, Permission::DEALERS_MANAGE], $member->fresh()->permissions);
    }

    public function test_nobody_changes_their_own_access_or_an_owners(): void
    {
        $manager = $this->staff([Permission::STAFF_MANAGE]);
        $owner = User::factory()->owner()->create();
        $this->actingAs($manager);

        Livewire::test('pages::admin-staff')->call('startEdit', $manager->id)->assertForbidden();
        Livewire::test('pages::admin-staff')->call('revokeAccess', $manager->id)->assertForbidden();

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Livewire::test('pages::admin-staff')->call('revokeAccess', $owner->id);
    }

    public function test_owner_revokes_and_restores_staff_access(): void
    {
        $staff = $this->staff([Permission::DEALERS_VIEW]);
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test('pages::admin-staff')->call('revokeAccess', $staff->id);
        $this->assertFalse($staff->fresh()->canUseCommandCentre());
        $this->assertDatabaseHas('audit_logs', ['action' => 'staff.suspended', 'target_id' => $staff->id]);

        Livewire::test('pages::admin-staff')->call('restoreAccess', $staff->id);
        $this->assertTrue($staff->fresh()->canUseCommandCentre());
        $this->assertDatabaseHas('audit_logs', ['action' => 'staff.reinstated', 'target_id' => $staff->id]);
    }

    /* ---- audit log ---------------------------------------------------- */

    public function test_dealer_status_changes_and_dealer_user_management_are_audited(): void
    {
        $owner = User::factory()->owner()->create();
        $dealerUser = User::factory()->staffOf($this->dealer)->create();
        $this->actingAs($owner);

        Livewire::test('pages::admin-dealer', ['dealer' => $this->dealer])
            ->set('status', Dealer::STATUS_SUSPENDED)
            ->call('save')
            ->set('status', Dealer::STATUS_ACTIVE)
            ->call('save')
            ->call('suspendStaff', $dealerUser->id)
            ->set('inviteName', 'Dana')
            ->set('inviteEmail', 'dana@dealer.ca')
            ->call('inviteStaff');

        $statusChanges = AuditLog::where('action', 'dealer.status_changed')->orderBy('id')->get();
        $this->assertSame([['active', 'suspended'], ['suspended', 'active']], $statusChanges->map(fn ($entry) => [$entry->before['status'], $entry->after['status']])->all());
        $this->assertSame($owner->id, $statusChanges->first()->actor_user_id);
        $this->assertSame($this->dealer->id, $statusChanges->first()->target_id);

        $this->assertDatabaseHas('audit_logs', ['action' => 'dealer_user.suspended', 'target_id' => $dealerUser->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'dealer_user.invited', 'target_id' => $this->dealer->id]);
    }

    public function test_audit_entries_are_append_only(): void
    {
        $entry = AuditLog::record('test.entry');

        $this->expectException(LogicException::class);
        $entry->update(['action' => 'tampered']);
    }

    public function test_audit_log_is_readable_only_with_audit_view(): void
    {
        AuditLog::record('owner.granted');

        $this->actingAs(User::factory()->owner()->create())->get(route('admin.audit'))->assertOk()->assertSee('owner.granted');
        $this->actingAs($this->staff([Permission::STAFF_MANAGE]))->get(route('admin.audit'))->assertForbidden();
        $this->actingAs($this->staff([Permission::AUDIT_VIEW]))->get(route('admin.audit'))->assertOk();
    }

    public function test_granting_owner_from_the_console_is_audited(): void
    {
        User::factory()->create(['email' => 'boss@trucars.ca']);

        $this->artisan('users:make-owner', ['email' => 'boss@trucars.ca'])->assertSuccessful();

        $entry = AuditLog::where('action', 'owner.granted')->sole();
        $this->assertSame('console', $entry->actor_role);
        $this->assertSame('boss@trucars.ca', $entry->target_label);
    }

    /* ---- helpers ----------------------------------------------------- */

    private function staff(array $permissions, array $attributes = []): User
    {
        return User::factory()->create($attributes + [
            'role'        => User::ROLE_INTERNAL_STAFF,
            'permissions' => Permission::normalize($permissions),
        ]);
    }
}
