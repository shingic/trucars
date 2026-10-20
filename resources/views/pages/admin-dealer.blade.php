<?php

use App\Mail\DealerInvitationMail;
use App\Models\AuditLog;
use App\Models\Dealer;
use App\Models\DealerInvitation;
use App\Models\User;
use App\Support\CanadianAddress;
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
| TruCars admin — create or edit one dealer: one selling rooftop at one address.
|
| A draft can be saved with gaps. Going live (draft → active) needs the full
| address and a timezone, and cars can only be published once the address is
| complete (enforced on Vehicle itself, not just here). Coordinates are
| optional: there's no geocoder yet, so they come from the inventory feed or
| are typed in.
|
| Command Centre. Viewing needs dealers.view; saving the profile needs
| dealers.manage; staff and invitations need dealer_users.manage. Route
| middleware checks on load and every action, Gate::authorize re-checks before
| each write, staff/invitation lookups are fenced to this dealer, and every
| change lands in the audit log.
*/
new #[Layout('layouts.dealer')] #[Title('Dealer')] class extends Component {
    #[Locked]
    public ?int $dealerId = null;

    #[Locked]
    public ?string $feedRooftopId = null;

    #[Locked]
    public string $savedStatus = Dealer::STATUS_DRAFT;

    /** The province the current timezone suggestion was based on. */
    #[Locked]
    public string $provinceAtLastSuggestion = '';

    public string $name = '';
    public string $registrationNumber = '';
    public string $franchiseMake = '';

    public string $streetAddress = '';
    public string $unit = '';
    public string $city = '';
    public string $province = '';
    public string $postalCode = '';
    public string $country = CanadianAddress::COUNTRY;
    public $latitude = '';
    public $longitude = '';
    public string $timezone = '';

    public string $phone = '';
    public string $email = '';
    public string $website = '';

    public string $status = Dealer::STATUS_DRAFT;

    public string $statusMessage = '';

    // Invite form for the dealer users card.
    public string $inviteName = '';
    public string $inviteEmail = '';

    public function mount(?Dealer $dealer = null): void
    {
        Gate::authorize($dealer?->exists ? Permission::DEALERS_VIEW : Permission::DEALERS_MANAGE);

        if ($dealer === null || ! $dealer->exists) {
            return;
        }

        $this->dealerId = $dealer->id;
        $this->feedRooftopId = $dealer->feed_rooftop_id;
        $this->savedStatus = $dealer->status;

        $this->name = $dealer->name;
        $this->registrationNumber = (string) $dealer->registration_number;
        $this->franchiseMake = (string) $dealer->franchise_make;
        $this->streetAddress = (string) $dealer->street_address;
        $this->unit = (string) $dealer->unit;
        $this->city = (string) $dealer->city;
        $this->province = (string) $dealer->province;
        $this->provinceAtLastSuggestion = $this->province;
        $this->postalCode = (string) $dealer->postal_code;
        // Existing records keep what they have — an empty country stays empty
        // until an admin sets it, rather than being assumed.
        $this->country = (string) $dealer->country;
        $this->latitude = $dealer->latitude ?? '';
        $this->longitude = $dealer->longitude ?? '';
        $this->timezone = (string) $dealer->timezone;
        $this->phone = (string) $dealer->phone;
        $this->email = (string) $dealer->email;
        $this->website = (string) $dealer->website;
        $this->status = $dealer->status;
    }

    /**
     * Picking a province fills in its usual timezone when none is set yet, or
     * when the current one was only the previous province's default. A zone an
     * admin chose by hand is left alone.
     */
    public function updatedProvince(string $value): void
    {
        $previousDefault = CanadianAddress::defaultTimezoneFor($this->provinceAtLastSuggestion);

        if ($this->timezone === '' || $this->timezone === $previousDefault) {
            $this->timezone = CanadianAddress::defaultTimezoneFor($value) ?? '';
        }

        $this->provinceAtLastSuggestion = $value;
    }

    public function canManage(): bool
    {
        return Gate::allows(Permission::DEALERS_MANAGE);
    }

    public function canManageUsers(): bool
    {
        return Gate::allows(Permission::DEALER_USERS_MANAGE);
    }

    public function save(): void
    {
        Gate::authorize(Permission::DEALERS_MANAGE);

        // Normalise first so "bc" and "v2p2m9" validate as what they mean.
        $this->province = CanadianAddress::normalizeProvince($this->province) ?? trim($this->province);
        $this->postalCode = CanadianAddress::normalizePostalCode($this->postalCode) ?? strtoupper(trim($this->postalCode));
        $this->country = strtoupper(trim($this->country));
        $this->website = trim($this->website);

        if ($this->website !== '' && ! preg_match('#^https?://#i', $this->website)) {
            $this->website = 'https://' . $this->website;
        }

        // Going live needs the whole address and a timezone. Staying live
        // doesn't re-demand them, so a legacy dealer awaiting backfill can still
        // have its phone number fixed.
        $goingLive = $this->status === Dealer::STATUS_ACTIVE && $this->savedStatus !== Dealer::STATUS_ACTIVE;
        $addressRule = $goingLive ? 'required' : 'nullable';

        $this->validate([
            'name'               => ['required', 'string', 'max:120'],
            'registrationNumber' => ['nullable', 'string', 'max:40'],
            'franchiseMake'      => ['nullable', 'string', 'max:40'],
            'streetAddress'      => [$addressRule, 'string', 'max:160'],
            'unit'               => ['nullable', 'string', 'max:30'],
            'city'               => ['required', 'string', 'max:80'],
            'province'           => [$addressRule, Rule::in(array_keys(CanadianAddress::PROVINCES))],
            'postalCode'         => [$addressRule, 'regex:/^[A-Z]\d[A-Z] \d[A-Z]\d$/'],
            'country'            => [$addressRule, Rule::in([CanadianAddress::COUNTRY])],
            'latitude'           => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'],
            'longitude'          => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
            'timezone'           => [$goingLive ? 'required' : 'nullable', Rule::in(CanadianAddress::timezones())],
            'phone'              => ['nullable', 'string', 'max:30', 'regex:/^[0-9+().\s-]{7,30}$/'],
            'email'              => ['nullable', 'email', 'max:160'],
            'website'            => ['nullable', 'url', 'max:255'],
            'status'             => ['required', Rule::in(array_keys(Dealer::STATUS_LABELS))],
        ], [
            'postalCode.regex' => 'Enter a Canadian postal code like V2P 2M9.',
            'country.in'       => 'Only Canadian dealers are supported for now.',
            'phone.regex'      => 'Enter a phone number using digits, spaces, dashes or brackets.',
            'streetAddress.required' => 'A street address is needed before this dealer can go live.',
            'timezone.required'      => 'Choose the dealer’s timezone before it goes live.',
        ], [
            'registrationNumber' => 'registration number',
            'franchiseMake'      => 'franchise make',
            'streetAddress'      => 'street address',
            'postalCode'         => 'postal code',
        ]);

        // A postal code from another province is almost always a typo.
        $postalProvince = CanadianAddress::provinceForPostalCode($this->postalCode);

        if ($postalProvince !== null && $this->province !== '' && $postalProvince !== $this->province) {
            $this->addError('postalCode', "That postal code is in {$postalProvince}, not {$this->province}.");

            return;
        }

        $dealer = $this->dealerId === null ? new Dealer : Dealer::findOrFail($this->dealerId);
        $wasNew = ! $dealer->exists;
        $previousStatus = $dealer->status;

        $dealer->fill([
            'name'                => trim($this->name),
            'registration_number' => trim($this->registrationNumber) ?: null,
            'franchise_make'      => trim($this->franchiseMake) ?: null,
            'street_address'      => trim($this->streetAddress) ?: null,
            'unit'                => trim($this->unit) ?: null,
            'city'                => trim($this->city),
            'province'            => $this->province ?: null,
            'postal_code'         => $this->postalCode ?: null,
            'country'             => $this->country ?: null,
            'latitude'            => $this->latitude === '' ? null : (float) $this->latitude,
            'longitude'           => $this->longitude === '' ? null : (float) $this->longitude,
            'timezone'            => $this->timezone ?: null,
            'phone'               => trim($this->phone) ?: null,
            'email'               => trim($this->email) ?: null,
            'website'             => $this->website ?: null,
            'status'              => $this->status,
        ]);

        $changes = $dealer->getDirty();
        $before = array_intersect_key($dealer->getOriginal(), $changes);
        $dealer->save();

        if ($wasNew) {
            AuditLog::record('dealer.created', $dealer, null, $changes);
        } elseif ($changes !== []) {
            AuditLog::record('dealer.updated', $dealer, $before, $changes);
        }

        if (! $wasNew && $previousStatus !== $dealer->status) {
            AuditLog::record('dealer.status_changed', $dealer, ['status' => $previousStatus], ['status' => $dealer->status]);
        }

        $this->dealerId = $dealer->id;
        $this->savedStatus = $dealer->status;
        $this->statusMessage = match (true) {
            $dealer->isSuspended()           => "Saved {$dealer->name}. It's suspended: its cars are unlisted and its staff can't sign in.",
            ! $dealer->hasCompleteAddress() => "Saved {$dealer->name}. Its vehicles can’t be published until the address is complete.",
            default                          => "Saved {$dealer->name}.",
        };
    }

    /* ---------------------------------------------------------------------
       Dealer users — staff accounts and invitations, for this dealer only
       --------------------------------------------------------------------- */
    protected function dealerOrFail(): Dealer
    {
        abort_if($this->dealerId === null, 404);

        return Dealer::findOrFail($this->dealerId);
    }

    public function staffMembers(): Collection
    {
        return $this->dealerId === null ? collect() : $this->dealerOrFail()->staff()->orderBy('name')->get();
    }

    public function openInvitations(): Collection
    {
        return $this->dealerId === null ? collect() : $this->dealerOrFail()->invitations()->open()->latest()->get();
    }

    /**
     * Invite someone to this dealer's portal. The account is only created when
     * they accept and choose a password. An email that already has any TruCars
     * account is refused — an existing customer is never quietly turned into staff.
     */
    public function inviteStaff(): void
    {
        Gate::authorize(Permission::DEALER_USERS_MANAGE);
        $dealer = $this->dealerOrFail();

        $this->inviteEmail = strtolower(trim($this->inviteEmail));

        $this->validate([
            'inviteName'  => ['required', 'string', 'max:120'],
            'inviteEmail' => ['required', 'email', 'max:160', Rule::unique('users', 'email')],
        ], [
            'inviteEmail.unique' => 'That email already has a TruCars account. Dealer staff need their own work email.',
        ], ['inviteName' => 'name', 'inviteEmail' => 'email']);

        if ($dealer->isSuspended()) {
            $this->addError('inviteEmail', 'This dealer is suspended. Reactivate it before inviting staff.');

            return;
        }

        if ($this->sendInvitation($dealer, $this->inviteName, $this->inviteEmail)) {
            $this->statusMessage = "Invitation sent to {$this->inviteEmail}.";
            $this->reset(['inviteName', 'inviteEmail']);
        }
    }

    public function resendInvitation(int $invitationId): void
    {
        Gate::authorize(Permission::DEALER_USERS_MANAGE);
        $invitation = $this->dealerOrFail()->invitations()->open()->whereKey($invitationId)->firstOrFail();

        if ($this->sendInvitation($invitation->dealer, $invitation->name, $invitation->email)) {
            $this->statusMessage = "Sent a fresh invitation to {$invitation->email}. The previous link no longer works.";
        }
    }

    public function revokeInvitation(int $invitationId): void
    {
        Gate::authorize(Permission::DEALER_USERS_MANAGE);
        $invitation = $this->dealerOrFail()->invitations()->open()->whereKey($invitationId)->firstOrFail();

        $invitation->update(['revoked_at' => now()]);
        AuditLog::record('dealer_user.invitation_revoked', $invitation->dealer, null, ['email' => $invitation->email]);
        $this->statusMessage = "Revoked the invitation for {$invitation->email}.";
    }

    public function suspendStaff(int $userId): void
    {
        Gate::authorize(Permission::DEALER_USERS_MANAGE);
        $member = $this->dealerOrFail()->staff()->whereKey($userId)->firstOrFail();

        $member->forceFill(['suspended_at' => now()])->save();
        AuditLog::record('dealer_user.suspended', $member, ['suspended' => false], ['suspended' => true, 'dealer_id' => $member->dealer_id]);
        $this->statusMessage = "Suspended {$member->email}. They can't sign in to the portal.";
    }

    public function reinstateStaff(int $userId): void
    {
        Gate::authorize(Permission::DEALER_USERS_MANAGE);
        $member = $this->dealerOrFail()->staff()->whereKey($userId)->firstOrFail();

        $member->forceFill(['suspended_at' => null])->save();
        AuditLog::record('dealer_user.reinstated', $member, ['suspended' => true], ['suspended' => false, 'dealer_id' => $member->dealer_id]);
        $this->statusMessage = "Reinstated {$member->email}.";
    }

    private function sendInvitation(Dealer $dealer, string $name, string $email): bool
    {
        [$invitation, $plainToken] = DealerInvitation::issue($dealer, $name, $email, auth()->user());

        try {
            Mail::to($invitation->email)->send(new DealerInvitationMail($invitation, route('dealer.invitation', ['token' => $plainToken])));
        } catch (\Throwable $exception) {
            // An invite nobody received is useless — withdraw it and say so.
            $invitation->update(['revoked_at' => now()]);
            Log::error('Dealer invitation email failed', ['invitation_id' => $invitation->id, 'error' => $exception->getMessage()]);
            $this->addError('inviteEmail', 'The invitation email couldn’t be sent. Please try again.');

            return false;
        }

        AuditLog::record('dealer_user.invited', $dealer, null, ['name' => $invitation->name, 'email' => $invitation->email]);

        return true;
    }

    public function timezoneOptions(): array
    {
        return CanadianAddress::timezones();
    }

    public function suggestedTimezone(): ?string
    {
        return CanadianAddress::defaultTimezoneFor($this->province);
    }
}; ?>

@push('crumb')
    <a href="{{ route('admin.dealers') }}" wire:navigate>Dealers</a>
    <span>›</span>
    <span class="cur">{{ $dealerId === null ? 'New dealer' : $name }}</span>
@endpush

@push('styles')
    <style>
        .tabs { display:flex; gap:6px; margin-bottom:18px; border-bottom:1px solid var(--line); }
        .tabs a { padding:9px 14px; font-size:14px; font-weight:600; color:var(--ink-2); border-bottom:2px solid transparent; margin-bottom:-1px; }
        .tabs a.on { color:var(--ink); border-bottom-color:var(--primary); }
        .dl-head { margin-bottom:22px; }
        .dl-head h1 { font-size:24px; font-weight:800; letter-spacing:-.02em; margin:0 0 6px; }
        .dl-head p { margin:0; font-size:14px; color:var(--ink-2); max-width:640px; }

        .btn { font-size:14px; font-weight:700; border-radius:var(--radius-pill); padding:10px 18px; transition:all .15s ease; white-space:nowrap; }
        .btn-primary { background:var(--primary); color:#fff; box-shadow:0 8px 20px rgba(245,99,31,.24); }
        .btn-primary:hover { background:var(--primary-press); }
        .btn-ghost { background:transparent; color:var(--ink-2); border:1px solid var(--line-strong); display:inline-block; }
        .btn-ghost:hover { border-color:var(--ink-3); color:var(--ink); }

        .dl-status { display:flex; align-items:center; gap:9px; background:var(--good-soft); color:var(--good-ink); border:1px solid rgba(18,184,134,.25); border-radius:var(--radius-sm); padding:11px 15px; font-size:13.5px; font-weight:600; margin-bottom:20px; }

        .dl-grid { display:grid; gap:20px; max-width:880px; border:none; padding:0; margin:0; min-width:0; }
        .card { background:var(--card); border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow-sm); }
        .card-head { padding:16px 20px 14px; border-bottom:1px solid var(--line); }
        .card-head .ttl { font-size:15px; font-weight:700; }
        .card-head .sub { font-size:12.5px; color:var(--ink-3); margin-top:2px; }
        .fields { padding:20px; display:grid; grid-template-columns:repeat(6, minmax(0, 1fr)); gap:16px; }
        .field { display:flex; flex-direction:column; gap:6px; grid-column:span 3; }
        .field.w2 { grid-column:span 2; }
        .field.w4 { grid-column:span 4; }
        .field.w6 { grid-column:1 / -1; }
        .field label { font-size:12.5px; font-weight:700; color:var(--ink-2); }
        .field label .opt { font-weight:500; color:var(--ink-3); }
        .field input, .field select { border:1px solid var(--line-strong); border-radius:var(--radius-sm); padding:10px 13px; font-size:14px; color:var(--ink); background:var(--card); font-family:inherit; width:100%; }
        .field input:focus, .field select:focus { outline:none; border-color:var(--primary); }
        .field input[readonly] { background:var(--bg); color:var(--ink-3); }
        .field .err { font-size:12px; color:var(--primary-press); font-weight:600; }
        .field .hint { font-size:12px; color:var(--ink-3); }

        .status-toggle { display:flex; gap:8px; }
        .status-opt { flex:1; border:1px solid var(--line-strong); border-radius:var(--radius-sm); padding:11px 12px; text-align:left; transition:all .14s ease; }
        .status-opt .k { font-size:13.5px; font-weight:700; color:var(--ink); }
        .status-opt .d { font-size:11.5px; color:var(--ink-3); margin-top:2px; }
        .status-opt.on { border-color:var(--primary); background:var(--primary-soft); }
        .status-opt.on .k { color:var(--primary-press); }

        .btn-sm { font-size:12.5px; padding:6px 12px; }
        .dl-users { max-width:880px; margin-top:28px; }
        .u-row { display:flex; align-items:center; gap:12px; padding:13px 20px; border-bottom:1px solid var(--line); }
        .u-row > div:first-child { flex:1; min-width:0; }
        .u-name { font-size:14px; font-weight:700; }
        .u-meta { font-size:12px; color:var(--ink-3); margin-top:2px; overflow:hidden; text-overflow:ellipsis; }
        .u-pill { font-size:10.5px; font-weight:800; letter-spacing:.04em; text-transform:uppercase; padding:3px 9px; border-radius:var(--radius-pill); background:var(--good-soft); color:var(--good-ink); }
        .u-pill.u-off { background:var(--primary-soft); color:var(--primary-press); }
        .u-pill.u-wait { background:var(--amber-soft); color:var(--amber); }
        .u-acts { display:flex; gap:6px; }
        .u-empty { padding:14px 20px; font-size:13.5px; color:var(--ink-3); border-bottom:1px solid var(--line); }
        .u-invite { display:grid; grid-template-columns:1fr 1fr auto; gap:14px; align-items:end; padding:18px 20px; }
        @media (max-width:760px){ .u-invite { grid-template-columns:1fr; } .u-row { flex-wrap:wrap; } }
        .dl-actions { display:flex; justify-content:flex-end; gap:10px; max-width:880px; margin-top:20px; }

        @media (max-width:760px){
            .fields { grid-template-columns:1fr; }
            .field, .field.w2, .field.w4 { grid-column:1 / -1; }
            .status-toggle { flex-direction:column; }
        }
    </style>
@endpush

<div>
    @if ($dealerId !== null)
        @include('partials.dealer-tabs', ['tabDealer' => $dealerId, 'activeTab' => 'profile'])
    @endif

    <div class="dl-head">
        <h1>{{ $dealerId === null ? 'New dealer' : $name }}</h1>
        <p>One selling rooftop at one physical address. Every vehicle on this dealer is shown at this address.</p>
    </div>

    @if ($statusMessage !== '')
        <div class="dl-status">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
            <span>{{ $statusMessage }}</span>
        </div>
    @endif

    <form wire:submit="save">
        {{-- Read-only for staff who can view dealers but not manage them. save() enforces it regardless. --}}
        <fieldset class="dl-grid" @disabled(! $this->canManage())>
            <div class="card">
                <div class="card-head">
                    <div class="ttl">Dealership</div>
                    <div class="sub">What shoppers see, and how the dealer is registered.</div>
                </div>
                <div class="fields">
                    <div class="field w4">
                        <label for="name">Public dealership name</label>
                        <input id="name" type="text" wire:model="name" maxlength="120">
                        @error('name') <span class="err">{{ $message }}</span> @enderror
                    </div>
                    <div class="field w2">
                        <label for="franchiseMake">Franchise make <span class="opt">(optional)</span></label>
                        <input id="franchiseMake" type="text" wire:model="franchiseMake" placeholder="e.g. Mitsubishi" maxlength="40">
                        @error('franchiseMake') <span class="err">{{ $message }}</span> @enderror
                    </div>
                    <div class="field">
                        <label for="registrationNumber">Dealer registration number <span class="opt">(OMVIC, VSA, AMVIC…)</span></label>
                        <input id="registrationNumber" type="text" wire:model="registrationNumber" maxlength="40">
                        @error('registrationNumber') <span class="err">{{ $message }}</span> @enderror
                    </div>
                    <div class="field">
                        <label for="feedRooftopId">Inventory feed rooftop id</label>
                        <input id="feedRooftopId" type="text" value="{{ $feedRooftopId ?? 'Set by the first inventory import' }}" readonly>
                    </div>
                    <div class="field w2">
                        <label for="phone">Public phone <span class="opt">(optional)</span></label>
                        <input id="phone" type="tel" wire:model="phone" maxlength="30">
                        @error('phone') <span class="err">{{ $message }}</span> @enderror
                    </div>
                    <div class="field w2">
                        <label for="email">Public email <span class="opt">(optional)</span></label>
                        <input id="email" type="email" wire:model="email" maxlength="160">
                        @error('email') <span class="err">{{ $message }}</span> @enderror
                    </div>
                    <div class="field w2">
                        <label for="website">Website <span class="opt">(optional)</span></label>
                        <input id="website" type="text" wire:model="website" placeholder="example.ca" maxlength="255">
                        @error('website') <span class="err">{{ $message }}</span> @enderror
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-head">
                    <div class="ttl">Rooftop address</div>
                    <div class="sub">Where this dealer's cars physically are. Required before any vehicle can be published.</div>
                </div>
                <div class="fields">
                    <div class="field w4">
                        <label for="streetAddress">Street address</label>
                        <input id="streetAddress" type="text" wire:model="streetAddress" placeholder="45510 Yale Rd" maxlength="160">
                        @error('streetAddress') <span class="err">{{ $message }}</span> @enderror
                    </div>
                    <div class="field w2">
                        <label for="unit">Unit <span class="opt">(optional)</span></label>
                        <input id="unit" type="text" wire:model="unit" maxlength="30">
                        @error('unit') <span class="err">{{ $message }}</span> @enderror
                    </div>
                    <div class="field w2">
                        <label for="city">City</label>
                        <input id="city" type="text" wire:model="city" maxlength="80">
                        @error('city') <span class="err">{{ $message }}</span> @enderror
                    </div>
                    <div class="field w2">
                        <label for="province">Province / territory</label>
                        <select id="province" wire:model.live="province">
                            <option value="">Choose…</option>
                            @foreach (\App\Support\CanadianAddress::PROVINCES as $code => $provinceName)
                                <option value="{{ $code }}">{{ $provinceName }}</option>
                            @endforeach
                        </select>
                        @error('province') <span class="err">{{ $message }}</span> @enderror
                    </div>
                    <div class="field w2">
                        <label for="postalCode">Postal code</label>
                        <input id="postalCode" type="text" wire:model="postalCode" placeholder="V2P 2M9" maxlength="7">
                        @error('postalCode') <span class="err">{{ $message }}</span> @enderror
                    </div>
                    <div class="field w2">
                        <label for="country">Country</label>
                        <select id="country" wire:model="country">
                            <option value="">Choose…</option>
                            <option value="CA">Canada</option>
                        </select>
                        @error('country') <span class="err">{{ $message }}</span> @enderror
                    </div>
                    <div class="field w2">
                        <label for="latitude">Latitude <span class="opt">(optional)</span></label>
                        <input id="latitude" type="text" inputmode="decimal" wire:model="latitude" placeholder="49.151131">
                        @error('latitude') <span class="err">{{ $message }}</span> @enderror
                    </div>
                    <div class="field w2">
                        <label for="longitude">Longitude <span class="opt">(optional)</span></label>
                        <input id="longitude" type="text" inputmode="decimal" wire:model="longitude" placeholder="-121.963743">
                        @error('longitude') <span class="err">{{ $message }}</span> @enderror
                    </div>
                    <div class="field w6">
                        <span class="hint">Coordinates power distance sorting for shoppers. There's no automatic geocoding yet — they come from the inventory feed or can be entered here.</span>
                    </div>
                    <div class="field w4">
                        <label for="timezone">Timezone</label>
                        <select id="timezone" wire:model="timezone">
                            <option value="">Choose…</option>
                            @foreach ($this->timezoneOptions() as $zone)
                                <option value="{{ $zone }}">{{ $zone }}</option>
                            @endforeach
                        </select>
                        @if ($this->suggestedTimezone() && $timezone !== '' && $timezone !== $this->suggestedTimezone())
                            <span class="hint">Most of {{ \App\Support\CanadianAddress::PROVINCES[$province] ?? $province }} uses {{ $this->suggestedTimezone() }} — keep this only if the rooftop is in an exception area.</span>
                        @elseif ($province === 'NU')
                            <span class="hint">Nunavut spans three zones — choose the one this rooftop observes.</span>
                        @else
                            <span class="hint">Used for handover windows and dealer-local times. Filled from the province; correct it for exception areas.</span>
                        @endif
                        @error('timezone') <span class="err">{{ $message }}</span> @enderror
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-head">
                    <div class="ttl">Status</div>
                </div>
                <div class="fields">
                    <div class="field w6">
                        <div class="status-toggle">
                            <button type="button" class="status-opt {{ $status === 'draft' ? 'on' : '' }}" wire:click="$set('status', 'draft')">
                                <div class="k">Draft</div>
                                <div class="d">Being set up — none of its cars are listed</div>
                            </button>
                            <button type="button" class="status-opt {{ $status === 'active' ? 'on' : '' }}" wire:click="$set('status', 'active')">
                                <div class="k">Active</div>
                                <div class="d">Live — its published cars appear on TruCars</div>
                            </button>
                            <button type="button" class="status-opt {{ $status === 'suspended' ? 'on' : '' }}" wire:click="$set('status', 'suspended')">
                                <div class="k">Suspended</div>
                                <div class="d">Cars unlisted and staff locked out — records kept</div>
                            </button>
                        </div>
                        @error('status') <span class="err">{{ $message }}</span> @enderror
                    </div>
                </div>
            </div>
        </fieldset>

        <div class="dl-actions">
            <a href="{{ route('admin.dealers') }}" wire:navigate class="btn btn-ghost">Back to dealers</a>
            @if ($this->canManage())
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">{{ $dealerId === null ? 'Create dealer' : 'Save changes' }}</span>
                    <span wire:loading wire:target="save">Saving…</span>
                </button>
            @endif
        </div>
    </form>

    @if ($dealerId !== null && $this->canManageUsers())
        <div class="card dl-users" id="users">
            <div class="card-head">
                <div class="ttl">Dealer users</div>
                <div class="sub">Staff who can sign in to this dealer's portal. They see this dealer's data only. New staff join by invitation and set their own password.</div>
            </div>

            @forelse ($this->staffMembers() as $member)
                <div class="u-row" wire:key="staff-{{ $member->id }}">
                    <div>
                        <div class="u-name">{{ $member->name }}</div>
                        <div class="u-meta">{{ $member->email }}</div>
                    </div>
                    @if ($member->isSuspended())
                        <span class="u-pill u-off">Suspended</span>
                        <button type="button" class="btn btn-ghost btn-sm" wire:click="reinstateStaff({{ $member->id }})">Reinstate</button>
                    @else
                        <span class="u-pill">Active</span>
                        <button type="button" class="btn btn-ghost btn-sm" wire:click="suspendStaff({{ $member->id }})" wire:confirm="Suspend {{ $member->email }}? They won't be able to sign in.">Suspend</button>
                    @endif
                </div>
            @empty
                <div class="u-empty">No staff yet.</div>
            @endforelse

            @foreach ($this->openInvitations() as $invitation)
                <div class="u-row" wire:key="invite-{{ $invitation->id }}">
                    <div>
                        <div class="u-name">{{ $invitation->name }}</div>
                        <div class="u-meta">{{ $invitation->email }} · invited, expires {{ $invitation->expires_at->diffForHumans() }}</div>
                    </div>
                    <span class="u-pill u-wait">Invited</span>
                    <span class="u-acts">
                        <button type="button" class="btn btn-ghost btn-sm" wire:click="resendInvitation({{ $invitation->id }})">Resend</button>
                        <button type="button" class="btn btn-ghost btn-sm" wire:click="revokeInvitation({{ $invitation->id }})">Revoke</button>
                    </span>
                </div>
            @endforeach

            <form class="u-invite" wire:submit="inviteStaff">
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
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="inviteStaff">Send invitation</button>
            </form>
        </div>
    @endif
</div>
