<?php

use App\Mail\StaffInvitationMail;
use App\Models\AuditLog;
use App\Models\StaffInvitation;
use App\Models\User;
use App\Support\Permission;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
| Command Centre — TruCars' internal staff and what each may do.
|
| Needs staff.manage (the owner has it; staff only if granted). Rules that
| stop access from creeping upwards, all enforced here on the server:
|   - you can only grant permissions you hold yourself (owner: all of them);
|   - permissions you don't hold are left exactly as they were on edit;
|   - nobody changes their own access, and nobody here changes an owner's.
| Owners are only ever made from the console (users:make-owner). Every change
| is written to the audit log.
*/
new #[Layout('layouts.dealer')] #[Title('TruCars staff')] class extends Component {
    public string $inviteName = '';
    public string $inviteEmail = '';
    /** @var array<int, string> */
    public array $invitePermissions = Permission::PRESETS['customer_support']['permissions'];

    #[Locked]
    public ?int $editingStaffId = null;
    /** @var array<int, string> */
    public array $editPermissions = [];

    public string $statusMessage = '';

    public function mount(): void
    {
        Gate::authorize(Permission::STAFF_MANAGE);
    }

    /** What the signed-in manager may hand out. */
    public function grantablePermissions(): array
    {
        $actor = auth()->user();

        return $actor->isOwner() ? Permission::all() : $actor->effectivePermissions();
    }

    public function members(): Collection
    {
        return User::query()
            ->whereIn('role', [User::ROLE_OWNER, User::ROLE_INTERNAL_STAFF])
            ->orderByRaw("case when role = 'owner' then 0 else 1 end")
            ->orderBy('name')
            ->get();
    }

    public function openInvitations(): Collection
    {
        return StaffInvitation::query()->open()->latest()->get();
    }

    public function applyPreset(string $preset): void
    {
        $this->invitePermissions = array_values(array_intersect(
            Permission::PRESETS[$preset]['permissions'] ?? [],
            $this->grantablePermissions(),
        ));
    }

    public function invite(): void
    {
        Gate::authorize(Permission::STAFF_MANAGE);

        $this->inviteEmail = strtolower(trim($this->inviteEmail));

        $this->validate([
            'inviteName'          => ['required', 'string', 'max:120'],
            'inviteEmail'         => ['required', 'email', 'max:160', Rule::unique('users', 'email')],
            'invitePermissions'   => ['array'],
            'invitePermissions.*' => [Rule::in(Permission::all())],
        ], [
            'inviteEmail.unique' => 'That email already has a TruCars account. Staff need their own work email.',
        ], ['inviteName' => 'name', 'inviteEmail' => 'email']);

        $permissions = $this->withinGrantable($this->invitePermissions);

        if ($permissions === null) {
            return;
        }

        if ($this->sendInvitation($this->inviteName, $this->inviteEmail, $permissions)) {
            $this->statusMessage = "Invitation sent to {$this->inviteEmail}.";
            $this->reset(['inviteName', 'inviteEmail']);
            $this->invitePermissions = Permission::PRESETS['customer_support']['permissions'];
        }
    }

    public function resendInvitation(int $invitationId): void
    {
        Gate::authorize(Permission::STAFF_MANAGE);
        $invitation = StaffInvitation::query()->open()->whereKey($invitationId)->firstOrFail();

        $permissions = $this->withinGrantable($invitation->permissions ?? []);

        if ($permissions !== null && $this->sendInvitation($invitation->name, $invitation->email, $permissions)) {
            $this->statusMessage = "Sent a fresh invitation to {$invitation->email}. The previous link no longer works.";
        }
    }

    public function revokeInvitation(int $invitationId): void
    {
        Gate::authorize(Permission::STAFF_MANAGE);
        $invitation = StaffInvitation::query()->open()->whereKey($invitationId)->firstOrFail();

        $invitation->update(['revoked_at' => now()]);
        AuditLog::record('staff.invitation_revoked', $invitation, null, ['email' => $invitation->email]);
        $this->statusMessage = "Revoked the invitation for {$invitation->email}.";
    }

    public function startEdit(int $userId): void
    {
        Gate::authorize(Permission::STAFF_MANAGE);
        $member = $this->manageableMember($userId);

        $this->editingStaffId = $member->id;
        $this->editPermissions = $member->permissions ?? [];
        $this->resetValidation();
    }

    public function cancelEdit(): void
    {
        $this->editingStaffId = null;
        $this->editPermissions = [];
    }

    public function savePermissions(): void
    {
        Gate::authorize(Permission::STAFF_MANAGE);
        $member = $this->manageableMember((int) $this->editingStaffId);

        $this->validate([
            'editPermissions'   => ['array'],
            'editPermissions.*' => [Rule::in(Permission::all())],
        ]);

        // Only what this manager may grant is changed; anything else the member
        // holds stays exactly as it was.
        $grantable = $this->grantablePermissions();
        $before = Permission::normalize($member->permissions ?? []);
        $after = Permission::normalize([
            ...array_intersect($this->editPermissions, $grantable),
            ...array_diff($before, $grantable),
        ]);

        $member->forceFill(['permissions' => $after])->save();

        if ($before !== $after) {
            AuditLog::record('staff.permissions_changed', $member, ['permissions' => $before], ['permissions' => $after]);
        }

        $this->cancelEdit();
        $this->statusMessage = "Updated access for {$member->email}.";
    }

    public function revokeAccess(int $userId): void
    {
        Gate::authorize(Permission::STAFF_MANAGE);
        $member = $this->manageableMember($userId);

        $member->forceFill(['suspended_at' => now()])->save();
        AuditLog::record('staff.suspended', $member, ['suspended' => false], ['suspended' => true]);
        $this->statusMessage = "Revoked access for {$member->email}. They can't sign in to the Command Centre.";
    }

    public function restoreAccess(int $userId): void
    {
        Gate::authorize(Permission::STAFF_MANAGE);
        $member = $this->manageableMember($userId);

        $member->forceFill(['suspended_at' => null])->save();
        AuditLog::record('staff.reinstated', $member, ['suspended' => true], ['suspended' => false]);
        $this->statusMessage = "Restored access for {$member->email}.";
    }

    /** Internal staff other than yourself. Owners and your own account are off-limits. */
    private function manageableMember(int $userId): User
    {
        $member = User::query()->where('role', User::ROLE_INTERNAL_STAFF)->whereKey($userId)->firstOrFail();

        abort_if($member->is(auth()->user()), 403, 'You can’t change your own access.');

        return $member;
    }

    /** The normalised list, or null (with an error) if it asks for more than you hold. */
    private function withinGrantable(array $permissions): ?array
    {
        $permissions = Permission::normalize($permissions);
        $beyondYou = array_diff($permissions, $this->grantablePermissions());

        if ($beyondYou !== []) {
            $this->addError('invitePermissions', 'You can only grant permissions you hold yourself: ' . implode(', ', $beyondYou) . '.');

            return null;
        }

        return $permissions;
    }

    private function sendInvitation(string $name, string $email, array $permissions): bool
    {
        [$invitation, $plainToken] = StaffInvitation::issue($name, $email, $permissions, auth()->user());

        try {
            Mail::to($invitation->email)->send(new StaffInvitationMail($invitation, route('admin.invitation', ['token' => $plainToken])));
        } catch (\Throwable $exception) {
            $invitation->update(['revoked_at' => now()]);
            Log::error('Staff invitation email failed', ['invitation_id' => $invitation->id, 'error' => $exception->getMessage()]);
            $this->addError('inviteEmail', 'The invitation email couldn’t be sent. Please try again.');

            return false;
        }

        AuditLog::record('staff.invited', $invitation, null, ['name' => $invitation->name, 'email' => $invitation->email, 'permissions' => $invitation->permissions]);

        return true;
    }
}; ?>

@push('crumb')
    <span>Command Centre</span>
    <span>›</span>
    <span class="cur">TruCars staff</span>
@endpush

@push('styles')
    <style>
        .st-head h1 { font-family:var(--font-display); font-size:36px; font-weight:700; line-height:1; text-transform:uppercase; margin:0 0 10px; }
        .st-head p { margin:0 0 24px; font-size:15px; color:var(--ink-2); max-width:660px; }
        .btn { display:inline-flex; align-items:center; justify-content:center; font-size:14px; font-weight:700; border-radius:3px; padding:10px 18px; border:1.5px solid transparent; transition:background .15s ease, color .15s ease, border-color .15s ease; white-space:nowrap; }
        .btn-primary { background:var(--primary); border-color:var(--primary); color:#fff; font-family:var(--font-display); font-size:16px; text-transform:uppercase; }
        .btn-primary:hover { background:var(--primary-press); border-color:var(--primary-press); }
        .btn-primary:disabled { opacity:.6; }
        .btn-ghost { background:#fff; color:var(--ink); border-color:var(--ink); }
        .btn-ghost:hover { background:var(--ink); color:#fff; }
        .btn-sm { font-size:13px; padding:6px 12px; }
        .btn-primary.btn-sm { font-size:14px; }
        .st-status { display:flex; align-items:center; gap:9px; background:var(--good-soft); color:#0B7355; border-left:3px solid var(--good); border-radius:2px; padding:11px 15px; font-size:14px; font-weight:600; margin-bottom:22px; max-width:920px; }
        .card { max-width:920px; margin-bottom:34px; border-top:2px solid var(--ink); }
        .card.boxed { background:#fff; border:1.5px solid var(--ink); border-radius:4px; }
        .card-head { padding:12px 0 12px; border-bottom:1px solid var(--line-strong); }
        .card.boxed .card-head { padding:16px 20px 14px; }
        .card-head .ttl { font-family:var(--font-display); font-size:22px; font-weight:700; line-height:1.05; text-transform:uppercase; }
        .card-head .sub { font-size:14px; color:var(--ink-3); margin-top:4px; }
        .m-row { padding:14px 0; border-bottom:1px solid var(--line-strong); }
        .m-top { display:flex; align-items:center; gap:12px; }
        .m-top > div:first-child { flex:1; min-width:0; }
        .m-name { font-size:15px; font-weight:700; }
        .m-meta { font-size:13px; color:var(--ink-3); margin-top:2px; font-weight:400; }
        .m-perms { display:flex; flex-wrap:wrap; gap:6px; margin-top:8px; }
        .perm { font-size:13px; font-weight:600; color:var(--ink-2); background:#fff; border:1px solid var(--line-strong); border-radius:2px; padding:1px 8px; }
        .pill { font-family:var(--font-display); font-size:13px; font-weight:600; text-transform:uppercase; padding:2px 8px; border-radius:2px; background:var(--good-soft); color:#0B7355; white-space:nowrap; }
        .pill.off { background:#FFEEF3; color:#D81A48; }
        .pill.wait { background:#FFF8DD; color:#7A4E00; }
        .pill.owner { background:var(--ink); color:#fff; }
        .acts { display:flex; gap:6px; flex-wrap:wrap; }
        .perm-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px 18px; margin-top:12px; }
        .perm-opt { display:flex; gap:9px; align-items:flex-start; font-size:14px; font-weight:600; }
        .perm-opt input { margin-top:3px; width:16px; height:16px; accent-color:var(--ink); flex-shrink:0; }
        .perm-opt .d { display:block; font-size:13px; font-weight:400; color:var(--ink-3); }
        .perm-opt.locked { opacity:.5; }
        .form { padding:18px 20px 20px; display:grid; gap:16px; }
        .row2 { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
        .field { display:flex; flex-direction:column; gap:6px; }
        .field label, .lbl { font-size:14px; font-weight:700; color:var(--ink); }
        .field input { background:#fff; border:1.5px solid var(--line-strong); border-radius:3px; padding:10px 13px; font-size:15px; font-family:inherit; color:var(--ink); }
        .field input:focus { outline:none; border-color:var(--ink); box-shadow:0 0 0 3px var(--primary-soft); }
        .err { font-size:13px; color:#D81A48; font-weight:600; }
        .presets { display:flex; gap:8px; flex-wrap:wrap; }
        .end { display:flex; justify-content:flex-end; gap:8px; flex-wrap:wrap; }
        @media (max-width:760px){ .perm-grid, .row2 { grid-template-columns:1fr; } .m-top { flex-wrap:wrap; } .st-head h1 { font-size:30px; } }
    </style>
@endpush

<div>
    <div class="st-head">
        <h1>TruCars staff</h1>
        <p>People who can sign in to the Command Centre, and what each can do. Staff join by invitation and set their own password. Customer support access covers helping customers — not dealers, staff or platform settings.</p>
    </div>

    @if ($statusMessage !== '')
        <div class="st-status"><span>{{ $statusMessage }}</span></div>
    @endif

    <div class="card">
        <div class="card-head"><div class="ttl">Team</div></div>

        @foreach ($this->members() as $member)
            <div class="m-row" wire:key="member-{{ $member->id }}">
                <div class="m-top">
                    <div>
                        <div class="m-name">{{ $member->name }} @if ($member->is(auth()->user())) <span class="m-meta">(you)</span> @endif</div>
                        <div class="m-meta">{{ $member->email }}</div>
                    </div>
                    @if ($member->isOwner())
                        <span class="pill owner">Owner · all access</span>
                    @else
                        <span class="pill {{ $member->isSuspended() ? 'off' : '' }}">{{ $member->isSuspended() ? 'Access revoked' : 'Staff' }}</span>
                        @unless ($member->is(auth()->user()))
                            <span class="acts">
                                <button type="button" class="btn btn-ghost btn-sm" wire:click="startEdit({{ $member->id }})">Edit access</button>
                                @if ($member->isSuspended())
                                    <button type="button" class="btn btn-ghost btn-sm" wire:click="restoreAccess({{ $member->id }})">Restore</button>
                                @else
                                    <button type="button" class="btn btn-ghost btn-sm" wire:click="revokeAccess({{ $member->id }})" wire:confirm="Revoke {{ $member->email }}'s Command Centre access?">Revoke</button>
                                @endif
                            </span>
                        @endunless
                    @endif
                </div>

                @if (! $member->isOwner())
                    @if ($editingStaffId === $member->id)
                        <div class="perm-grid">
                            @foreach (\App\Support\Permission::DEFINITIONS as $key => [$label, $description])
                                <label class="perm-opt {{ in_array($key, $this->grantablePermissions(), true) ? '' : 'locked' }}">
                                    <input type="checkbox" value="{{ $key }}" wire:model="editPermissions" @disabled(! in_array($key, $this->grantablePermissions(), true))>
                                    <span>{{ $label }}<span class="d">{{ $description }}</span></span>
                                </label>
                            @endforeach
                        </div>
                        <div class="end" style="margin-top:12px">
                            <button type="button" class="btn btn-ghost btn-sm" wire:click="cancelEdit">Cancel</button>
                            <button type="button" class="btn btn-primary btn-sm" wire:click="savePermissions">Save access</button>
                        </div>
                    @else
                        <div class="m-perms">
                            @forelse ($member->permissions ?? [] as $key)
                                <span class="perm">{{ \App\Support\Permission::DEFINITIONS[$key][0] ?? $key }}</span>
                            @empty
                                <span class="m-meta">No permissions</span>
                            @endforelse
                        </div>
                    @endif
                @endif
            </div>
        @endforeach

        @foreach ($this->openInvitations() as $invitation)
            <div class="m-row" wire:key="sinv-{{ $invitation->id }}">
                <div class="m-top">
                    <div>
                        <div class="m-name">{{ $invitation->name }}</div>
                        <div class="m-meta">{{ $invitation->email }} · invited, expires {{ $invitation->expires_at->diffForHumans() }}</div>
                    </div>
                    <span class="pill wait">Invited</span>
                    <span class="acts">
                        <button type="button" class="btn btn-ghost btn-sm" wire:click="resendInvitation({{ $invitation->id }})">Resend</button>
                        <button type="button" class="btn btn-ghost btn-sm" wire:click="revokeInvitation({{ $invitation->id }})">Revoke</button>
                    </span>
                </div>
            </div>
        @endforeach
    </div>

    <div class="card boxed">
        <div class="card-head">
            <div class="ttl">Invite a staff member</div>
            <div class="sub">They'll get an email link to set their password. You can only grant access you hold yourself.</div>
        </div>
        <form class="form" wire:submit="invite">
            <div class="row2">
                <div class="field">
                    <label for="inviteName">Name</label>
                    <input id="inviteName" type="text" wire:model="inviteName" maxlength="120">
                    @error('inviteName') <span class="err">{{ $message }}</span> @enderror
                </div>
                <div class="field">
                    <label for="inviteEmail">Work email</label>
                    <input id="inviteEmail" type="email" wire:model="inviteEmail" maxlength="160">
                    @error('inviteEmail') <span class="err">{{ $message }}</span> @enderror
                </div>
            </div>
            <div>
                <span class="lbl">Start from</span>
                <div class="presets" style="margin-top:6px">
                    @foreach (\App\Support\Permission::PRESETS as $presetKey => $preset)
                        <button type="button" class="btn btn-ghost btn-sm" wire:click="applyPreset('{{ $presetKey }}')">{{ $preset['label'] }}</button>
                    @endforeach
                </div>
                <div class="perm-grid">
                    @foreach (\App\Support\Permission::DEFINITIONS as $key => [$label, $description])
                        <label class="perm-opt {{ in_array($key, $this->grantablePermissions(), true) ? '' : 'locked' }}">
                            <input type="checkbox" value="{{ $key }}" wire:model="invitePermissions" @disabled(! in_array($key, $this->grantablePermissions(), true))>
                            <span>{{ $label }}<span class="d">{{ $description }}</span></span>
                        </label>
                    @endforeach
                </div>
                @error('invitePermissions') <span class="err">{{ $message }}</span> @enderror
            </div>
            <div class="end">
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="invite">Send invitation</button>
            </div>
        </form>
    </div>
</div>
