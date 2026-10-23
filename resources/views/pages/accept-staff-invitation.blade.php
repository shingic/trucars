<?php

use App\Models\AuditLog;
use App\Models\StaffInvitation;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
| Accepting a Command Centre staff invitation (admin host, guests only).
|
| Same rules as the dealer invitation: the emailed token is the only
| credential, hashed, single-use, expiring, re-checked on submit. The account
| is created here as internal staff with exactly the permissions on the
| invitation and the password the invitee chooses.
*/
new #[Layout('layouts.auth')] class extends Component {
    #[Locked]
    public string $token = '';

    #[Locked]
    public bool $isUsable = false;

    #[Locked]
    public string $email = '';

    public string $name = '';
    public string $password = '';
    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;
        $invitation = $this->usableInvitation();

        if ($invitation === null) {
            return;
        }

        $this->isUsable = true;
        $this->email = $invitation->email;
        $this->name = $invitation->name;
    }

    /** Open, unexpired, and the email still free. */
    private function usableInvitation(): ?StaffInvitation
    {
        $invitation = StaffInvitation::findUsable($this->token);

        if ($invitation === null || User::where('email', $invitation->email)->exists()) {
            return null;
        }

        return $invitation;
    }

    public function accept()
    {
        $this->validate([
            'name'     => ['required', 'string', 'max:120'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ], [
            'password.confirmed' => 'These passwords don’t match.',
        ]);

        $staffMember = DB::transaction(function () {
            // Re-checked under the transaction: the link may have been revoked,
            // used or expired since the page loaded.
            $invitation = $this->usableInvitation();

            if ($invitation === null) {
                return null;
            }

            $staffMember = new User([
                'name'     => trim($this->name),
                'email'    => $invitation->email,
                'password' => $this->password,
            ]);

            // Role and permissions come from the invitation — never from the form.
            $staffMember->forceFill([
                'role'              => User::ROLE_INTERNAL_STAFF,
                'permissions'       => \App\Support\Permission::normalize($invitation->permissions ?? []),
                'email_verified_at' => now(), // they received the link at this address
            ])->save();

            $invitation->forceFill(['accepted_at' => now()])->save();
            AuditLog::record('staff.joined', $staffMember, null, ['permissions' => $staffMember->permissions, 'invitation_id' => $invitation->id], $staffMember);

            return $staffMember;
        });

        if ($staffMember === null) {
            $this->isUsable = false;

            return;
        }

        Auth::login($staffMember);
        session()->regenerate();

        return redirect()->route('admin.home');
    }
}; ?>

@push('styles')
    <style>
        .inv-wrap { max-width:440px; margin:0 auto; padding:64px 24px 90px; }
        .inv-brand { display:flex; align-items:center; gap:9px; font-weight:800; font-size:17px; margin-bottom:36px; }
        .inv-brand .glyph { width:28px; height:28px; border-radius:9px; background:var(--primary); color:#fff; display:grid; place-items:center; font-size:15px; }
        .inv-title { font-size:26px; font-weight:800; letter-spacing:0; margin:0 0 8px; }
        .inv-lead { font-size:15px; color:var(--ink-2); margin:0 0 26px; }
        .inv-field { display:flex; flex-direction:column; gap:6px; margin-bottom:16px; }
        .inv-field label { font-size:13px; font-weight:700; color:var(--ink-2); }
        .inv-field input { border:1px solid var(--line-strong); border-radius:var(--radius-sm); padding:12px 14px; font-size:15px; font-family:inherit; background:var(--card); }
        .inv-field input:focus { outline:none; border-color:var(--primary); }
        .inv-field input[readonly] { background:var(--bg); color:var(--ink-3); }
        .inv-err { font-size:12.5px; color:var(--primary-press); font-weight:600; }
        .inv-btn { width:100%; margin-top:8px; background:var(--primary); color:#fff; font-size:15px; font-weight:700; border:none; border-radius:var(--radius-pill); padding:14px; cursor:pointer; font-family:inherit; }
        .inv-btn:hover { background:var(--primary-press); }
        .inv-note { background:var(--card); border:1px solid var(--line); border-radius:var(--radius); padding:22px; font-size:14.5px; color:var(--ink-2); }
    </style>
@endpush

<div class="inv-wrap">
    <div class="inv-brand"><span class="glyph">T</span> TruCars</div>

    @if (! $isUsable)
        <h1 class="inv-title">This invitation can't be used</h1>
        <div class="inv-note">
            It may have expired, been used already, or been replaced by a newer invitation. Ask your TruCars contact to send a fresh one.
            If you already set up your account, <a href="{{ route('admin.login') }}">sign in here</a>.
        </div>
    @else
        <h1 class="inv-title">Join the TruCars Command Centre</h1>
        <p class="inv-lead">Choose a password to finish setting up your staff account.</p>

        <form wire:submit="accept">
            <div class="inv-field">
                <label for="email">Email</label>
                <input id="email" type="email" value="{{ $email }}" readonly>
            </div>
            <div class="inv-field">
                <label for="name">Your name</label>
                <input id="name" type="text" wire:model="name" maxlength="120" autocomplete="name">
                @error('name') <span class="inv-err">{{ $message }}</span> @enderror
            </div>
            <div class="inv-field">
                <label for="password">Password (at least 12 characters)</label>
                <input id="password" type="password" wire:model="password" autocomplete="new-password">
                @error('password') <span class="inv-err">{{ $message }}</span> @enderror
            </div>
            <div class="inv-field">
                <label for="password_confirmation">Confirm password</label>
                <input id="password_confirmation" type="password" wire:model="password_confirmation" autocomplete="new-password">
            </div>
            <button type="submit" class="inv-btn" wire:loading.attr="disabled" wire:target="accept">Create my account</button>
        </form>
    @endif
</div>
