<?php

use App\Mail\DealerInvitationMail;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Dealer;
use App\Models\DealerGroup;
use App\Models\DealerInvitation;
use App\Models\Rooftop;
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
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Illuminate\Support\Facades\Storage;

/*
| TruCars admin — create or edit one selling dealer and its rooftops. The
| dealer's own address is its main store (mirrored onto the primary rooftop);
| further stores are added on the Location tab.
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
    use WithFileUploads;

    public const TABS = [
        'overview'     => 'Overview',
        'info'         => 'Dealer info',
        'location'     => 'Location',
        'team'         => 'Team & access',
        'cpo'          => 'CPO program',
        'offers'       => 'Offers & incentives',
        'integrations' => 'Integrations',
    ];

    #[Locked]
    public ?int $dealerId = null;

    #[Url(except: 'overview')]
    public string $tab = 'overview';

    /** A new storefront photo, stored as soon as it's picked. */
    public $coverUpload = null;

    #[Locked]
    public ?string $feedRooftopId = null;

    #[Locked]
    public string $savedStatus = Dealer::STATUS_DRAFT;

    /** The province the current timezone suggestion was based on. */
    #[Locked]
    public string $provinceAtLastSuggestion = '';

    public string $name = '';
    public string $registrationNumber = '';
    /** The dealer group, by name; a new name starts a new group. */
    public string $groupName = '';
    /**
     * The franchise brand — its CPO checklist and offers follow automatically.
     * Chosen when the dealer is created. After that save() never touches it:
     * changing it is its own confirmed, audited step (confirmBrandChange).
     */
    public ?string $brandId = null;

    #[Locked]
    public ?int $savedBrandId = null;

    // The "change brand" step
    public bool $changingBrand = false;
    public ?string $newBrandId = null;
    public string $brandChangeReason = '';
    public string $brandChangeConfirm = '';

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

    // The add / edit form for a rooftop other than the main store.
    public bool $rooftopFormOpen = false;
    #[Locked]
    public ?int $editingRooftopId = null;
    public array $rooftopForm = [];

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

        if (! array_key_exists($this->tab, self::TABS)) {
            $this->tab = 'overview';
        }
        $this->savedStatus = $dealer->status;

        $this->name = $dealer->name;
        $this->registrationNumber = (string) $dealer->registration_number;
        $this->groupName = (string) $dealer->group?->name;
        $this->brandId = $dealer->brand_id ? (string) $dealer->brand_id : null;
        $this->savedBrandId = $dealer->brand_id;
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

    /* ---------------------------------------------------------------------
       Rooftops — the dealer's physical stores beyond the main one
       --------------------------------------------------------------------- */
    public function rooftops(): Collection
    {
        return $this->dealerId === null ? collect() : $this->dealerOrFail()->rooftops()->withCount('vehicles')->get();
    }

    /** Group names already in use, for the field's suggestions. */
    public function groupNames(): Collection
    {
        return DealerGroup::orderBy('name')->pluck('name');
    }

    public function startRooftop(?int $rooftopId = null): void
    {
        Gate::authorize(Permission::DEALERS_MANAGE);

        $rooftop = $rooftopId === null ? null : $this->dealerOrFail()->rooftops()->where('is_primary', false)->findOrFail($rooftopId);

        $this->resetValidation();
        $this->editingRooftopId = $rooftop?->id;
        $this->rooftopForm = collect(['name', 'feed_rooftop_id', ...Rooftop::LOCATION_FIELDS])
            ->mapWithKeys(fn (string $field) => [$field => (string) ($rooftop?->{$field} ?? '')])
            ->all();
        $this->rooftopFormOpen = true;
    }

    public function cancelRooftop(): void
    {
        $this->rooftopFormOpen = false;
        $this->editingRooftopId = null;
    }

    public function saveRooftop(): void
    {
        Gate::authorize(Permission::DEALERS_MANAGE);
        $dealer = $this->dealerOrFail();

        $form = array_map(fn ($value) => trim((string) $value), $this->rooftopForm);
        $form['province'] = CanadianAddress::normalizeProvince($form['province'] ?? '') ?? ($form['province'] ?? '');
        $form['postal_code'] = CanadianAddress::normalizePostalCode($form['postal_code'] ?? '') ?? strtoupper($form['postal_code'] ?? '');
        $form['country'] = CanadianAddress::COUNTRY;
        $form['timezone'] = ($form['timezone'] ?? '') ?: (string) CanadianAddress::defaultTimezoneFor($form['province']);
        $this->rooftopForm = $form;

        $this->validate([
            'rooftopForm.name'            => ['required', 'string', 'max:120'],
            'rooftopForm.feed_rooftop_id' => ['nullable', 'string', 'max:64', Rule::unique('rooftops', 'feed_rooftop_id')->ignore($this->editingRooftopId)],
            'rooftopForm.street_address'  => ['required', 'string', 'max:160'],
            'rooftopForm.unit'            => ['nullable', 'string', 'max:30'],
            'rooftopForm.city'            => ['required', 'string', 'max:80'],
            'rooftopForm.province'        => ['required', Rule::in(array_keys(CanadianAddress::PROVINCES))],
            'rooftopForm.postal_code'     => ['required', 'regex:/^[A-Z]\d[A-Z] \d[A-Z]\d$/'],
            'rooftopForm.latitude'        => ['nullable', 'required_with:rooftopForm.longitude', 'numeric', 'between:-90,90'],
            'rooftopForm.longitude'       => ['nullable', 'required_with:rooftopForm.latitude', 'numeric', 'between:-180,180'],
            'rooftopForm.timezone'        => ['required', Rule::in(CanadianAddress::timezones())],
            'rooftopForm.phone'           => ['nullable', 'string', 'max:30', 'regex:/^[0-9+().\s-]{7,30}$/'],
            'rooftopForm.email'           => ['nullable', 'email', 'max:160'],
        ], [
            'rooftopForm.postal_code.regex'      => 'Enter a Canadian postal code like V2P 2M9.',
            'rooftopForm.phone.regex'            => 'Enter a phone number using digits, spaces, dashes or brackets.',
            'rooftopForm.feed_rooftop_id.unique' => 'Another rooftop already uses that feed id.',
        ], [
            'rooftopForm.name' => 'store name', 'rooftopForm.feed_rooftop_id' => 'feed id', 'rooftopForm.street_address' => 'street address',
            'rooftopForm.city' => 'city', 'rooftopForm.province' => 'province', 'rooftopForm.postal_code' => 'postal code',
            'rooftopForm.latitude' => 'latitude', 'rooftopForm.longitude' => 'longitude', 'rooftopForm.timezone' => 'timezone',
            'rooftopForm.phone' => 'phone', 'rooftopForm.email' => 'email',
        ]);

        $postalProvince = CanadianAddress::provinceForPostalCode($form['postal_code']);

        if ($postalProvince !== null && $postalProvince !== $form['province']) {
            $this->addError('rooftopForm.postal_code', "That postal code is in {$postalProvince}, not {$form['province']}.");

            return;
        }

        $rooftop = $this->editingRooftopId === null
            ? new Rooftop(['dealer_id' => $dealer->id])
            : $dealer->rooftops()->where('is_primary', false)->findOrFail($this->editingRooftopId);

        $rooftop->fill(array_map(fn (string $value) => $value === '' ? null : $value, $form));

        $changes = $rooftop->getDirty();
        $before = array_intersect_key($rooftop->getOriginal(), $changes);
        $wasNew = ! $rooftop->exists;
        $rooftop->save();

        if ($wasNew || $changes !== []) {
            AuditLog::record($wasNew ? 'rooftop.created' : 'rooftop.updated', $rooftop, $wasNew ? null : $before, $changes);
        }

        $this->cancelRooftop();
        $this->statusMessage = $wasNew ? "Added {$rooftop->name}. Import its feed with --dealer={$dealer->id} --new-rooftop, or match it by feed id." : "Saved {$rooftop->name}.";
    }

    /** Close or reopen a store. A closed store's cars come off the marketplace; the main store follows the dealer's status instead. */
    public function toggleRooftop(int $rooftopId): void
    {
        Gate::authorize(Permission::DEALERS_MANAGE);
        $rooftop = $this->dealerOrFail()->rooftops()->where('is_primary', false)->findOrFail($rooftopId);

        $rooftop->update(['is_active' => ! $rooftop->is_active]);
        AuditLog::record($rooftop->is_active ? 'rooftop.reopened' : 'rooftop.closed', $rooftop, ['is_active' => ! $rooftop->is_active], ['is_active' => $rooftop->is_active]);

        $this->statusMessage = $rooftop->is_active ? "{$rooftop->name} is open again." : "{$rooftop->name} is closed. Its cars are off the marketplace.";
    }

    public function brands()
    {
        return Brand::orderBy('name')->get(['id', 'name']);
    }

    /** What a brand brings: its live checklist and current offers. */
    public function brandSettings(?int $brandId = null): ?array
    {
        $brandId ??= $this->dealerId ? $this->savedBrandId : ($this->brandId ? (int) $this->brandId : null);
        $brand = $brandId ? Brand::find($brandId) : null;

        if ($brand === null) {
            return null;
        }

        $checklist = $brand->checklists()->where('is_active', true)->withCount('points')->get()->sortByDesc('points_count')->first();

        return [
            'brand'     => $brand,
            'checklist' => $checklist,
            'offers'    => $brand->offers()->where('status', 'published')->whereDate('ends_on', '>=', now()->toDateString())->count(),
            'dealers'   => $brand->dealers()->when($this->dealerId, fn ($dealers) => $dealers->whereKeyNot($this->dealerId))->count(),
        ];
    }

    /* ---- changing an existing dealer's brand ---------------------------- */

    public function startBrandChange(): void
    {
        Gate::authorize(Permission::DEALERS_MANAGE);
        abort_if($this->dealerId === null, 422);

        $this->resetValidation();
        $this->reset(['newBrandId', 'brandChangeReason', 'brandChangeConfirm']);
        $this->changingBrand = true;
    }

    public function cancelBrandChange(): void
    {
        $this->changingBrand = false;
    }

    /** What changes for this dealer if it moves to the chosen brand. */
    public function brandChangeImpact(): ?array
    {
        $new = $this->newBrandId ? $this->brandSettings((int) $this->newBrandId) : null;

        if ($new === null) {
            return null;
        }

        $dealer = Dealer::findOrFail($this->dealerId);
        $live = $dealer->vehicles()->where('is_published', true);

        return [
            'old'           => $this->brandSettings($this->savedBrandId),
            'new'           => $new,
            'oldBrandCars'  => $dealer->franchise_make ? (clone $live)->whereRaw('lower(make) = ?', [mb_strtolower($dealer->franchise_make)])->count() : 0,
            'newBrandCars'  => (clone $live)->whereRaw('lower(make) = ?', [mb_strtolower($new['brand']->name)])->count(),
            'liveCampaigns' => $dealer->campaigns()->where('status', 'active')->count(),
        ];
    }

    public function confirmBrandChange(): void
    {
        Gate::authorize(Permission::DEALERS_MANAGE);
        $dealer = Dealer::findOrFail($this->dealerId);

        $this->validate([
            'newBrandId'         => ['required', 'integer', Rule::exists('brands', 'id'), Rule::notIn(array_filter([$dealer->brand_id]))],
            'brandChangeReason'  => ['required', 'string', 'min:5', 'max:255'],
            'brandChangeConfirm' => ['required', function ($attribute, $value, $fail) use ($dealer) {
                if (mb_strtolower(trim($value)) !== mb_strtolower(trim($dealer->name))) {
                    $fail('Type the dealership’s name exactly to confirm.');
                }
            }],
        ], [
            'newBrandId.not_in'        => 'That’s already this dealer’s brand.',
            'brandChangeReason.required' => 'Say why the brand is changing — it’s kept in the audit log.',
        ], [
            'newBrandId' => 'new brand', 'brandChangeReason' => 'reason', 'brandChangeConfirm' => 'confirmation',
        ]);

        $before = $dealer->brand?->name;
        $dealer->update(['brand_id' => (int) $this->newBrandId]);
        $dealer->refresh();

        AuditLog::record('dealer.brand_changed', $dealer, ['brand' => $before], ['brand' => $dealer->brand->name, 'reason' => trim($this->brandChangeReason)]);

        $this->savedBrandId = $dealer->brand_id;
        $this->brandId = (string) $dealer->brand_id;
        $this->changingBrand = false;
        $this->statusMessage = "{$dealer->name} is now a {$dealer->brand->name} dealer and follows {$dealer->brand->name} settings."
            . ($before ? " It no longer follows {$before}’s." : '');
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

        // An existing dealer's status changes only through changeStatus().
        if ($this->dealerId !== null) {
            $this->status = $this->savedStatus;
        }

        // Going live needs the whole address and a timezone. Staying live
        // doesn't re-demand them, so a legacy dealer awaiting backfill can still
        // have its phone number fixed.
        $goingLive = $this->status === Dealer::STATUS_ACTIVE && $this->savedStatus !== Dealer::STATUS_ACTIVE;
        $addressRule = $goingLive ? 'required' : 'nullable';

        $this->validate([
            'name'               => ['required', 'string', 'max:120'],
            'registrationNumber' => ['nullable', 'string', 'max:40'],
            'groupName'          => ['nullable', 'string', 'max:120'],
            // Chosen once, at creation. An existing dealer's brand changes only
            // through confirmBrandChange(), never through this form.
            'brandId'            => [$this->dealerId === null ? 'required' : 'nullable', 'integer', Rule::exists('brands', 'id')],
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
            'groupName'          => 'dealer group',
            'brandId'            => 'brand',
            'streetAddress'      => 'street address',
            'postalCode'         => 'postal code',
        ]);

        // A postal code from another province is almost always a typo.
        $postalProvince = CanadianAddress::provinceForPostalCode($this->postalCode);

        if ($postalProvince !== null && $this->province !== '' && $postalProvince !== $this->province) {
            $this->addError('postalCode', "That postal code is in {$postalProvince}, not {$this->province}.");

            return;
        }

        $dealer = $this->dealerId === null ? new Dealer(['brand_id' => (int) $this->brandId]) : Dealer::findOrFail($this->dealerId);
        $wasNew = ! $dealer->exists;
        $previousStatus = $dealer->status;

        $groupName = trim($this->groupName);
        $group = $groupName === '' ? null
            : (DealerGroup::whereRaw('lower(name) = ?', [mb_strtolower($groupName)])->first() ?? DealerGroup::create(['name' => $groupName]));

        $dealer->fill([
            'name'                => trim($this->name),
            'dealer_group_id'     => $group?->id,
            'registration_number' => trim($this->registrationNumber) ?: null,
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
        $this->savedBrandId = $dealer->brand_id;
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

    /* ---- status: its own confirmed action --------------------------------- */

    public function changeStatus(string $status): void
    {
        Gate::authorize(Permission::DEALERS_MANAGE);
        abort_unless(array_key_exists($status, Dealer::STATUS_LABELS), 422);
        $dealer = $this->dealerOrFail();

        if ($status === $dealer->status) {
            return;
        }

        if ($status === Dealer::STATUS_ACTIVE && (! $dealer->hasCompleteAddress() || blank($dealer->timezone))) {
            $this->statusMessage = '';
            $this->addError('status', 'Complete the address and timezone (Location tab) before going live.');

            return;
        }

        $before = $dealer->status;
        $dealer->update(['status' => $status]);
        AuditLog::record('dealer.status_changed', $dealer, ['status' => $before], ['status' => $status]);

        $this->status = $this->savedStatus = $status;
        $this->resetErrorBag('status');
        $this->statusMessage = match ($status) {
            Dealer::STATUS_ACTIVE    => "{$dealer->name} is live. Its published cars appear on TruCars.",
            Dealer::STATUS_SUSPENDED => "{$dealer->name} is suspended. Its cars are unlisted and its staff can't sign in.",
            default                  => "{$dealer->name} is back to draft. None of its cars are listed.",
        };
    }

    /* ---- storefront photo ------------------------------------------------- */

    public function updatedCoverUpload(): void
    {
        Gate::authorize(Permission::DEALERS_MANAGE);
        $dealer = $this->dealerOrFail();
        $this->validate(['coverUpload' => ['image', 'max:8192']], attributes: ['coverUpload' => 'photo']);

        $old = $dealer->cover_photo_path;
        $dealer->update(['cover_photo_path' => $this->coverUpload->store('dealer-covers', 'local')]);
        if ($old) {
            Storage::disk('local')->delete($old);
        }

        AuditLog::record('dealer.cover_changed', $dealer);
        $this->coverUpload = null;
        $this->statusMessage = 'Storefront photo updated.';
    }

    public function removeCover(): void
    {
        Gate::authorize(Permission::DEALERS_MANAGE);
        $dealer = $this->dealerOrFail();

        if ($dealer->cover_photo_path) {
            Storage::disk('local')->delete($dealer->cover_photo_path);
            $dealer->update(['cover_photo_path' => null]);
            AuditLog::record('dealer.cover_changed', $dealer, null, ['removed' => true]);
        }
    }

    /* ---- the profile's read-only summaries --------------------------------- */

    /** Headline numbers for the stat cards. */
    public function stats(): array
    {
        $dealer = $this->dealerOrFail();
        $deals = fn () => \App\Models\Deal::withoutGlobalScope(\App\Models\Scopes\DealerScope::class)->where('dealer_id', $dealer->id);
        $from = now()->subDays(30);
        $current = $deals()->where('created_at', '>=', $from)->count();
        $prior = $deals()->whereBetween('created_at', [$from->copy()->subDays(30), $from])->count();

        return [
            'live'         => $dealer->vehicles()->where('is_published', true)->count(),
            'vehicles'     => $dealer->vehicles()->count(),
            'users'        => $dealer->staff()->whereNull('suspended_at')->count(),
            'invited'      => $dealer->invitations()->open()->count(),
            'reservations' => $current,
            'delta'        => $prior === 0 ? ($current > 0 ? 'new' : null) : (($current >= $prior ? '+' : '') . round(($current - $prior) / $prior * 100) . '%'),
            'deltaUp'      => $current >= $prior,
            'customers'    => $deals()->whereNotNull('user_id')->distinct()->count('user_id'),
        ];
    }

    /** The setup steps a dealer needs before it's fully running. */
    public function setupSteps(): array
    {
        $dealer = $this->dealerOrFail();

        return [
            ['label' => 'Dealer information', 'done' => filled($dealer->name) && filled($dealer->registration_number) && filled($dealer->phone), 'tab' => 'info'],
            ['label' => 'Location details',   'done' => $dealer->hasCompleteAddress() && filled($dealer->timezone), 'tab' => 'location'],
            ['label' => 'Franchise brand',    'done' => $dealer->brand_id !== null, 'tab' => 'cpo'],
            ['label' => 'Team access',        'done' => $dealer->staff()->whereNull('suspended_at')->exists(), 'tab' => 'team'],
            ['label' => 'Inventory feed connected', 'done' => filled($dealer->feed_rooftop_id), 'tab' => 'integrations'],
        ];
    }

    /** The brand's current and upcoming offers, as this dealer's cars get them. */
    public function brandOffers(): Collection
    {
        $dealer = $this->dealerOrFail();

        return $dealer->brand_id
            ? \App\Models\Offer::where('brand_id', $dealer->brand_id)->where('status', 'published')
                ->whereDate('ends_on', '>=', now()->toDateString())->orderBy('starts_on')->get()
                ->filter(fn ($offer) => ! $offer->provinces || in_array($dealer->province, $offer->provinces, true))->values()
            : collect();
    }

    public function lastImport(): ?AuditLog
    {
        return AuditLog::where('action', 'inventory.imported')->where('target_type', 'Dealer')->where('target_id', $this->dealerId)->latest('id')->first();
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
        .brand-fixed { display:flex; align-items:center; gap:10px; flex-wrap:wrap; padding:9px 10px 9px 12px; border:1px solid var(--line); border-radius:var(--radius-sm); background:var(--bg); }
        .brand-chip { font-size:13.5px; font-weight:800; padding:4px 11px; border-radius:var(--radius-pill); background:#14161B; color:#fff; }
        .brand-chip.missing { background:var(--amber-soft); color:var(--amber); }
        .brand-follows-text { font-size:12.5px; color:var(--ink-3); flex:1; min-width:200px; }
        .brand-follows-text a { color:var(--primary-press); font-weight:700; }
        .brand-change { font-size:12.5px; font-weight:700; color:var(--ink-2); padding:6px 12px; border-radius:var(--radius-pill); border:1px solid var(--line-strong); background:var(--card); }
        .brand-change:hover { border-color:var(--ink-3); color:var(--ink); }
        .bc-scrim { position:fixed; inset:0; background:rgba(22,24,29,.45); backdrop-filter:blur(2px); z-index:80; display:grid; place-items:center; padding:20px; }
        .bc { width:min(560px, 100%); max-height:calc(100vh - 40px); overflow:auto; background:var(--card); border-radius:22px; box-shadow:0 30px 80px rgba(22,24,29,.3); }
        .bc-head { padding:22px 24px 6px; }
        .bc-head .warn { display:inline-flex; align-items:center; gap:7px; font-size:11.5px; font-weight:800; letter-spacing:.06em; text-transform:uppercase; color:var(--primary-press); }
        .bc-head h2 { font-size:21px; font-weight:800; letter-spacing:-.02em; margin:8px 0 4px; }
        .bc-head p { margin:0; font-size:13.5px; color:var(--ink-2); }
        .bc-body { padding:14px 24px 6px; display:grid; gap:14px; }
        .bc-body label { font-size:12.5px; font-weight:700; color:var(--ink-2); display:block; margin-bottom:6px; }
        .bc-body select, .bc-body input, .bc-body textarea { width:100%; border:1px solid var(--line-strong); border-radius:var(--radius-sm); padding:10px 12px; font-size:14px; font-family:inherit; background:var(--card); }
        .bc-body select:focus, .bc-body input:focus, .bc-body textarea:focus { outline:none; border-color:var(--primary); }
        .impact { border-radius:14px; border:1px solid var(--line); overflow:hidden; }
        .impact-row { display:grid; grid-template-columns:110px minmax(0, 1fr) 18px minmax(0, 1fr); gap:10px; align-items:center; padding:10px 14px; border-bottom:1px solid var(--line); font-size:13px; }
        .impact-row:last-child { border-bottom:none; }
        .impact-row .k { font-size:11.5px; font-weight:800; color:var(--ink-3); text-transform:uppercase; letter-spacing:.04em; }
        .impact-row .from { color:var(--ink-3); text-decoration:line-through; }
        .impact-row .to { font-weight:700; }
        .impact-note { font-size:12.5px; color:var(--amber); font-weight:600; background:var(--amber-soft); border-radius:12px; padding:10px 12px; }
        .bc-foot { display:flex; justify-content:flex-end; gap:8px; padding:16px 24px 22px; }
        .bc-foot .btn-danger { background:var(--primary-press); color:#fff; border-radius:var(--radius-pill); padding:10px 18px; font-size:14px; font-weight:700; }
        .bc-foot .btn-danger:disabled { opacity:.4; cursor:not-allowed; }
        .bc-foot .btn-cancel { border:1px solid var(--line-strong); border-radius:var(--radius-pill); padding:10px 18px; font-size:14px; font-weight:700; color:var(--ink); background:var(--card); }
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


        /* ---- profile ---- */
        .hero { position:relative; border-radius:24px; overflow:hidden; min-height:200px; background:linear-gradient(120deg, #F6F7F7 0%, #FFF4EC 100%); border:1px solid var(--line); margin-bottom:18px; }
        .hero-photo { position:absolute; inset:0 0 0 38%; background:var(--hero-grad) center/cover; opacity:.9; }
        .hero:not(.has-photo) .hero-photo { background:radial-gradient(500px 260px at 80% 30%, rgba(245,99,31,.35), transparent 70%), repeating-linear-gradient(135deg, rgba(245,99,31,.06) 0 14px, transparent 14px 28px); opacity:1; }
        .hero-fade { position:absolute; inset:0; background:linear-gradient(90deg, #FAFAFA 0%, #FAFAFA 34%, rgba(250,250,250,.85) 46%, rgba(250,250,250,0) 66%); }
        .hero-body { position:relative; display:flex; align-items:center; gap:26px; padding:28px 30px; min-height:200px; }
        .hero-brand { display:flex; flex-direction:column; align-items:center; gap:6px; flex-shrink:0; width:96px; }
        .mark { width:72px; height:72px; border-radius:20px; background:#14161B; color:#fff; display:grid; place-items:center; font-size:24px; font-weight:800; letter-spacing:-.02em; box-shadow:0 10px 26px rgba(22,24,29,.18); }
        .mark.big { width:58px; height:58px; font-size:19px; border-radius:17px; box-shadow:none; }
        .mark-name { font-size:11px; font-weight:800; letter-spacing:.08em; text-transform:uppercase; color:var(--ink-2); text-align:center; }
        .hero-main { flex:1; min-width:0; }
        .hero-title { display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
        .hero-title h1 { font-size:30px; font-weight:800; letter-spacing:-.03em; margin:0; }
        .st { display:inline-flex; align-items:center; gap:6px; font-size:12.5px; font-weight:700; padding:5px 11px; border-radius:var(--radius-pill); background:var(--bg); color:var(--ink-2); }
        .st i, .st-dot { width:8px; height:8px; border-radius:50%; background:var(--ink-3); display:inline-block; }
        .st-active, .st.st-active { background:var(--good-soft); color:var(--good-ink); }
        .st.st-active i, .st-dot.st-active { background:var(--good); }
        .st.st-suspended { background:var(--primary-soft); color:var(--primary-press); }
        .st.st-suspended i, .st-dot.st-suspended { background:var(--primary); }
        .st-dot.st-draft { background:var(--amber); }
        .hero-line { display:flex; align-items:center; gap:7px; margin-top:10px; font-size:14px; color:var(--ink-2); }
        .hero-line svg, .hero-contacts svg { color:var(--ink-3); }
        .hero-contacts { display:flex; flex-wrap:wrap; gap:8px 22px; margin-top:14px; font-size:13.5px; color:var(--ink-2); }
        .hero-contacts > * { display:inline-flex; align-items:center; gap:7px; }
        .hero-contacts a:hover { color:var(--primary-press); }
        .hero-actions { align-self:flex-start; }
        .hbtn { display:inline-flex; align-items:center; gap:7px; font-size:13px; font-weight:700; padding:9px 14px; border-radius:var(--radius-pill); background:rgba(255,255,255,.92); border:1px solid var(--line); box-shadow:var(--shadow-sm); }
        .hbtn:hover { border-color:var(--primary-line); color:var(--primary-press); }
        .photo-btn { position:absolute; right:16px; bottom:16px; display:inline-flex; align-items:center; gap:7px; font-size:12.5px; font-weight:700; color:#fff; padding:8px 13px; border-radius:var(--radius-pill); background:rgba(22,24,29,.62); backdrop-filter:blur(6px); cursor:pointer; }
        .photo-btn:hover { background:rgba(22,24,29,.8); }

        .pf { display:grid; grid-template-columns:minmax(0, 1fr) 290px; gap:18px; align-items:start; }
        .pf-main { min-width:0; }
        .stats { display:grid; grid-template-columns:repeat(auto-fit, minmax(170px, 1fr)); gap:12px; margin-bottom:16px; }
        .stat { position:relative; display:flex; align-items:center; gap:14px; padding:16px; background:var(--card); border:1px solid var(--line); border-radius:18px; box-shadow:var(--shadow-sm); text-align:left; transition:border-color .15s ease, transform .15s ease; }
        a.stat:hover, button.stat:hover { border-color:var(--primary-line); transform:translateY(-1px); }
        .stat .ic { width:44px; height:44px; border-radius:13px; background:var(--primary-soft); color:var(--primary-press); display:grid; place-items:center; flex-shrink:0; }
        .stat b { display:block; font-size:24px; font-weight:800; letter-spacing:-.02em; line-height:1.1; font-variant-numeric:tabular-nums; }
        .stat small { font-size:12.5px; color:var(--ink-2); }
        .stat .go { position:absolute; right:12px; top:14px; color:var(--ink-3); }
        .delta { position:absolute; right:12px; top:12px; font-size:11px; font-weight:800; padding:2px 7px; border-radius:var(--radius-pill); }
        .delta.up { background:var(--good-soft); color:var(--good-ink); }
        .delta.down { background:var(--primary-soft); color:var(--primary-press); }

        .ptabs { display:flex; gap:4px; border-bottom:1px solid var(--line); margin-bottom:16px; overflow-x:auto; }
        .ptabs button { padding:10px 14px; font-size:14px; font-weight:600; color:var(--ink-2); border-bottom:2px solid transparent; margin-bottom:-1px; white-space:nowrap; }
        .ptabs button.on { color:var(--primary-press); border-bottom-color:var(--primary); font-weight:700; }

        .ov { display:grid; grid-template-columns:minmax(0, 1fr) minmax(0, 1fr); gap:16px; }
        .pcard { background:var(--card); border:1px solid var(--line); border-radius:20px; box-shadow:var(--shadow-sm); overflow:hidden; display:block; }
        .pcard-head { display:flex; align-items:center; gap:14px; padding:18px 22px; border-bottom:1px solid var(--line); }
        .pcard-head .t { font-size:16px; font-weight:800; letter-spacing:-.01em; }
        .pcard-head .s { font-size:12.5px; color:var(--ink-3); margin-top:2px; }
        .pcard-head > div { flex:1; min-width:0; }
        .pic { width:42px; height:42px; border-radius:12px; background:var(--primary-soft); color:var(--primary-press); display:grid; place-items:center; flex-shrink:0; }
        .ebtn { display:inline-flex; align-items:center; gap:6px; font-size:13px; font-weight:700; padding:8px 13px; border-radius:11px; border:1px solid var(--line-strong); background:var(--card); color:var(--ink); white-space:nowrap; }
        .ebtn:hover { border-color:var(--ink-3); }
        .kv { padding:16px 22px 20px; }
        .kv.two { display:grid; grid-template-columns:1fr 1fr; gap:14px 24px; }
        .kv span { display:block; font-size:12px; color:var(--ink-3); font-weight:600; }
        .kv b, .kv a { display:block; font-size:14.5px; font-weight:600; color:var(--ink); margin-top:3px; word-break:break-word; }
        .kv a { color:var(--primary-press); }
        .kv .row { display:grid; grid-template-columns:150px minmax(0, 1fr); gap:12px; padding:9px 0; border-bottom:1px solid var(--line); align-items:baseline; }
        .kv .row:last-child { border-bottom:none; }
        .kv .row span { font-size:13.5px; color:var(--ink-2); }
        .kv .row b { margin-top:0; }
        .kv b.ok { color:var(--good-ink); } .kv b.warn { color:var(--amber); }
        .mono { font-family:var(--mono); }
        .people { padding:6px 22px 14px; }
        .person { display:flex; align-items:center; gap:12px; padding:9px 0; border-bottom:1px solid var(--line); }
        .person .av { width:34px; height:34px; border-radius:50%; background:#5B6470; color:#fff; display:grid; place-items:center; font-size:12px; font-weight:800; flex-shrink:0; }
        .person .av.muted { background:var(--bg); color:var(--ink-3); border:1px solid var(--line); }
        .person b { display:block; font-size:13.5px; } .person small { font-size:12px; color:var(--ink-3); }
        .grow { flex:1; min-width:0; }
        .dot-st { display:inline-flex; align-items:center; gap:6px; font-size:12.5px; font-weight:700; }
        .dot-st::before { content:""; width:8px; height:8px; border-radius:50%; background:var(--ink-3); }
        .dot-st.on { color:var(--good-ink); } .dot-st.on::before { background:var(--good); }
        .dot-st.off { color:var(--primary-press); } .dot-st.off::before { background:var(--primary); }
        .dot-st.wait { color:var(--ink-3); }
        .add-line { margin-top:10px; font-size:13.5px; font-weight:700; color:var(--ink); }
        .add-line:hover { color:var(--primary-press); }
        .empty-line { font-size:13.5px; color:var(--ink-3); padding:8px 0; }
        .form-end { display:flex; justify-content:flex-end; padding:0 22px 20px; }
        .brand-panel { display:flex; align-items:center; gap:14px; padding:18px 22px; border-bottom:1px solid var(--line); background:var(--bg); }
        .bp-name { font-size:18px; font-weight:800; }
        .bp-sub { font-size:12.5px; color:var(--ink-3); }
        .offer-line { display:flex; align-items:center; gap:16px; padding:14px 22px; border-bottom:1px solid var(--line); }
        .offer-line:last-child { border-bottom:none; }
        .o-big { font-size:19px; font-weight:800; color:var(--primary-press); min-width:110px; }
        .offer-line b { display:block; font-size:14px; } .offer-line small { font-size:12px; color:var(--ink-3); }
        .o-st { font-size:10.5px; font-weight:800; letter-spacing:.05em; text-transform:uppercase; padding:3px 9px; border-radius:var(--radius-pill); background:var(--bg); color:var(--ink-2); }
        .o-st.live { background:var(--good-soft); color:var(--good-ink); }
        .dl-users { max-width:none; margin-top:0; border-radius:20px; }

        .pf-side { display:grid; gap:14px; position:sticky; top:84px; }
        .side-card { background:var(--card); border:1px solid var(--line); border-radius:20px; box-shadow:var(--shadow-sm); padding:16px 18px; }
        .side-t { font-size:15px; font-weight:800; margin-bottom:10px; }
        .side-note { font-size:12.5px; color:var(--ink-3); margin-top:8px; }
        .status-pick { position:relative; }
        .sp-current { display:flex; align-items:center; gap:9px; width:100%; padding:10px 12px; border-radius:12px; border:1px solid var(--line-strong); font-size:14px; font-weight:700; background:var(--card); text-align:left; }
        .sp-current.static { cursor:default; }
        .sp-chev { margin-left:auto; color:var(--ink-3); }
        .sp-menu { position:absolute; left:0; right:0; top:calc(100% + 6px); background:var(--card); border:1px solid var(--line); border-radius:14px; box-shadow:0 14px 34px rgba(22,24,29,.14); padding:6px; z-index:20; }
        .sp-menu button { display:flex; align-items:flex-start; gap:10px; width:100%; padding:9px 10px; border-radius:9px; text-align:left; }
        .sp-menu button:hover { background:var(--bg); }
        .sp-menu .st-dot { margin-top:5px; }
        .sp-menu b { display:block; font-size:13.5px; } .sp-menu small { font-size:12px; color:var(--ink-3); }
        .map { width:100%; height:170px; border:0; border-radius:14px; display:block; }
        .map.empty { display:flex; flex-direction:column; align-items:center; justify-content:center; gap:8px; text-align:center; background:var(--bg); color:var(--ink-3); font-size:12.5px; padding:16px; }
        .side-link { display:inline-flex; align-items:center; gap:5px; margin-top:8px; font-size:12.5px; font-weight:700; color:var(--primary-press); }
        .ring-row { display:flex; align-items:center; gap:14px; margin-bottom:10px; }
        .ring { --p:0; width:58px; height:58px; border-radius:50%; background:conic-gradient(var(--good) calc(var(--p) * 1%), var(--bg) 0); display:grid; place-items:center; flex-shrink:0; }
        .ring b { width:46px; height:46px; border-radius:50%; background:var(--card); display:grid; place-items:center; font-size:13px; font-weight:800; }
        .ring-row > span:last-child b { display:block; font-size:13.5px; } .ring-row small { font-size:12px; color:var(--ink-3); }
        .step { display:flex; align-items:center; gap:10px; width:100%; padding:7px 2px; font-size:13.5px; color:var(--ink-2); text-align:left; }
        .step:hover { color:var(--ink); }
        .step svg { color:var(--ink-3); }
        .step .tick { width:20px; height:20px; border-radius:50%; border:1.5px solid var(--line-strong); display:grid; place-items:center; flex-shrink:0; }
        .step.done .tick { background:var(--good); border-color:var(--good); color:#fff; }
        .step.done .tick svg { color:#fff; }
        .qa { display:flex; align-items:center; justify-content:center; gap:8px; width:100%; padding:11px; margin-top:8px; border-radius:12px; font-size:13.5px; font-weight:700; border:1px solid var(--line-strong); background:var(--card); color:var(--ink); }
        .qa:first-of-type { margin-top:0; }
        .qa:hover { border-color:var(--ink-3); }
        .qa.primary { background:var(--primary); border-color:var(--primary); color:#fff; box-shadow:0 8px 20px rgba(245,99,31,.22); }
        .qa.primary:hover { background:var(--primary-press); }
        .err { font-size:12px; color:var(--primary-press); font-weight:600; }

        @media (max-width:1200px) { .pf { grid-template-columns:minmax(0, 1fr); } .pf-side { position:static; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); } }
        @media (max-width:900px) { .ov { grid-template-columns:minmax(0, 1fr); } .hero-photo { inset:0; opacity:.25; } .hero-fade { display:none; } .hero-body { flex-wrap:wrap; } .kv.two { grid-template-columns:1fr; } }

        @media (max-width:760px){
            .fields { grid-template-columns:1fr; }
            .field, .field.w2, .field.w4 { grid-column:1 / -1; }
            .status-toggle { flex-direction:column; }
        }
    </style>
@endpush

<div>
    @if ($statusMessage !== '')
        <div class="dl-status">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
            <span>{{ $statusMessage }}</span>
        </div>
    @endif

    @if ($dealerId === null)
        {{-- ================= New dealer ================= --}}
        <div class="dl-head">
            <h1>New dealer</h1>
            <p>One franchise rooftop at one physical address. Every vehicle on this dealer is shown at this address.</p>
        </div>
        <form wire:submit="save">
            <fieldset class="dl-grid">
                <div class="card">
                    <div class="card-head"><div class="ttl">Dealership</div><div class="sub">What shoppers see, and how the dealer is registered.</div></div>
                    <div class="fields">@include('partials.dealer.info-fields')</div>
                </div>
                <div class="card">
                    <div class="card-head"><div class="ttl">Rooftop address</div><div class="sub">Where this dealer's cars physically are. Required before any vehicle can be published.</div></div>
                    <div class="fields">@include('partials.dealer.location-fields')</div>
                </div>
                <div class="card">
                    <div class="card-head"><div class="ttl">Start as</div></div>
                    <div class="fields">
                        <div class="field w6">
                            <div class="status-toggle">
                                <button type="button" class="status-opt {{ $status === 'draft' ? 'on' : '' }}" wire:click="$set('status', 'draft')"><div class="k">Draft</div><div class="d">Being set up — none of its cars are listed</div></button>
                                <button type="button" class="status-opt {{ $status === 'active' ? 'on' : '' }}" wire:click="$set('status', 'active')"><div class="k">Active</div><div class="d">Live — its published cars appear on TruCars</div></button>
                            </div>
                            @error('status') <span class="err">{{ $message }}</span> @enderror
                        </div>
                    </div>
                </div>
            </fieldset>
            <div class="dl-actions">
                <a href="{{ route('admin.dealers') }}" wire:navigate class="btn btn-ghost">Cancel</a>
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save">Create dealer</button>
            </div>
        </form>
    @else
        {{-- ================= Dealer profile ================= --}}
        @php
            $dealer = $this->dealerOrFail();
            $settings = $this->brandSettings();
            $stats = $this->stats();
            $steps = $this->setupSteps();
            $done = collect($steps)->where('done', true)->count();
            $percent = (int) round($done / count($steps) * 100);
            $publicUrl = route('shop', ['dealer' => [$dealer->id]]);
            $displayName = ucwords(strtolower($dealer->name));
            $canManage = $this->canManage();
            $icon = fn (string $path, int $size = 18) => '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'.$path.'</svg>';
            $paths = [
                'pin'   => '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
                'phone' => '<path d="M5 4h4l2 5-3 2a11 11 0 0 0 5 5l2-3 5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2Z"/>',
                'mail'  => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
                'globe' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
                'car'   => '<path d="M5 17h14M6 17l1.5-6h9L18 17M7 21v-2M17 21v-2"/><circle cx="8" cy="14" r="1"/><circle cx="16" cy="14" r="1"/>',
                'user'  => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
                'users' => '<circle cx="9" cy="8" r="4"/><path d="M2 21a7 7 0 0 1 14 0M17 11a3 3 0 1 0 0-6M22 21a6 6 0 0 0-4-5.6"/>',
                'inbox' => '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
                'build' => '<path d="M3 21h18M5 21V8l7-4 7 4v13M9 21v-6h6v6"/>',
                'shield'=> '<path d="M12 3 4 6v6c0 5 8 9 8 9s8-4 8-9V6Z"/><path d="m9 12 2 2 4-4"/>',
                'edit'  => '<path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
                'ext'   => '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
                'camera'=> '<path d="M4 7h3l2-3h6l2 3h3a1 1 0 0 1 1 1v11a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V8a1 1 0 0 1 1-1Z"/><circle cx="12" cy="13" r="4"/>',
                'tag'   => '<path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8Z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
                'plug'  => '<path d="M9 2v6M15 2v6M6 8h12v4a6 6 0 0 1-12 0Z"/><path d="M12 18v4"/>',
                'send'  => '<path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/>',
                'chev'  => '<path d="m9 6 6 6-6 6"/>',
            ];
        @endphp

        {{-- Hero --}}
        <section class="hero {{ $dealer->cover_photo_path ? 'has-photo' : '' }}">
            <div class="hero-photo" @if ($dealer->cover_photo_path) style="background-image:url('{{ $dealer->coverPhotoUrl() }}')" @endif></div>
            <div class="hero-fade"></div>
            <div class="hero-body">
                <div class="hero-brand">
                    <span class="mark">{{ $settings ? $settings['brand']->initials() : '—' }}</span>
                    <span class="mark-name">{{ $settings ? $settings['brand']->name : 'No brand' }}</span>
                </div>
                <div class="hero-main">
                    <div class="hero-title">
                        <h1>{{ $displayName }}</h1>
                        <span class="st st-{{ $dealer->status }}"><i></i>{{ \App\Models\Dealer::STATUS_LABELS[$dealer->status] ?? $dealer->status }}</span>
                    </div>
                    <div class="hero-line">{!! $icon($paths['pin'], 15) !!} {{ implode(', ', array_filter([$dealer->street_address, $dealer->city, trim(($dealer->province ?? '') . ' ' . ($dealer->postal_code ?? ''))])) ?: 'No address yet' }}</div>
                    <div class="hero-contacts">
                        @if ($dealer->phone) <span>{!! $icon($paths['phone'], 15) !!} {{ $dealer->phone }}</span> @endif
                        @if ($dealer->email) <span>{!! $icon($paths['mail'], 15) !!} {{ $dealer->email }}</span> @endif
                        @if ($dealer->website) <a href="{{ $dealer->website }}" target="_blank" rel="noopener">{!! $icon($paths['globe'], 15) !!} {{ preg_replace('#^https?://(www\.)?#', '', rtrim($dealer->website, '/')) }}</a> @endif
                    </div>
                </div>
                <div class="hero-actions">
                    <a class="hbtn" href="{{ $publicUrl }}" target="_blank" rel="noopener">View public page {!! $icon($paths['ext'], 14) !!}</a>
                </div>
            </div>
            @if ($canManage)
                <label class="photo-btn">
                    {!! $icon($paths['camera'], 15) !!}
                    <span wire:loading.remove wire:target="coverUpload">{{ $dealer->cover_photo_path ? 'Change photo' : 'Add storefront photo' }}</span>
                    <span wire:loading wire:target="coverUpload">Uploading…</span>
                    <input type="file" accept="image/*" wire:model="coverUpload" hidden>
                </label>
            @endif
        </section>
        @error('coverUpload') <div class="err" style="margin:-8px 0 12px">{{ $message }}</div> @enderror

        <div class="pf">
            <div class="pf-main">
                {{-- Stats --}}
                <div class="stats">
                    <a class="stat" href="{{ route('admin.dealers.inventory', $dealer) }}" wire:navigate>
                        <span class="ic">{!! $icon($paths['car'], 20) !!}</span>
                        <span><b>{{ number_format($stats['live']) }}</b><small>Live vehicles</small></span>
                        <span class="go">{!! $icon($paths['chev'], 16) !!}</span>
                    </a>
                    <button type="button" class="stat" wire:click="$set('tab', 'team')">
                        <span class="ic">{!! $icon($paths['user'], 20) !!}</span>
                        <span><b>{{ $stats['users'] }}</b><small>Dealer users{{ $stats['invited'] ? ' · ' . $stats['invited'] . ' invited' : '' }}</small></span>
                        <span class="go">{!! $icon($paths['chev'], 16) !!}</span>
                    </button>
                    <div class="stat">
                        <span class="ic">{!! $icon($paths['inbox'], 20) !!}</span>
                        <span><b>{{ $stats['reservations'] }}</b><small>Reservations (30 days)</small></span>
                        @if ($stats['delta']) <span class="delta {{ $stats['deltaUp'] ? 'up' : 'down' }}">{{ $stats['deltaUp'] ? '↑' : '↓' }} {{ ltrim($stats['delta'], '+-') }}</span> @endif
                    </div>
                    @can('customers.view')
                        <a class="stat" href="{{ route('admin.dealers.customers', $dealer) }}" wire:navigate>
                            <span class="ic">{!! $icon($paths['users'], 20) !!}</span>
                            <span><b>{{ $stats['customers'] }}</b><small>Customers</small></span>
                            <span class="go">{!! $icon($paths['chev'], 16) !!}</span>
                        </a>
                    @endcan
                </div>

                {{-- Tabs --}}
                <nav class="ptabs">
                    @foreach ($this::TABS as $key => $label)
                        <button type="button" class="{{ $tab === $key ? 'on' : '' }}" wire:click="$set('tab', '{{ $key }}')">{{ $label }}</button>
                    @endforeach
                </nav>

                @if ($tab === 'overview')
                    <div class="ov">
                        <div class="pcard">
                            <div class="pcard-head">
                                <span class="pic">{!! $icon($paths['build'], 20) !!}</span>
                                <div><div class="t">Dealer information</div><div class="s">Name, franchise brand, registration and contact details.</div></div>
                                @if ($canManage) <button type="button" class="ebtn" wire:click="$set('tab', 'info')">{!! $icon($paths['edit'], 14) !!} Edit</button> @endif
                            </div>
                            <div class="kv two">
                                <div><span>Public name</span><b>{{ $displayName }}</b></div>
                                <div><span>Public phone</span><b>{{ $dealer->phone ?: '—' }}</b></div>
                                <div><span>Franchise brand</span><b>{{ $settings['brand']->name ?? 'Not set' }}</b></div>
                                <div><span>Public email</span><b>{{ $dealer->email ?: '—' }}</b></div>
                                <div><span>Dealer registration #</span><b>{{ $dealer->registration_number ? trim(($dealer->regulator ?? '') . ' ' . $dealer->registration_number) : '—' }}</b></div>
                                <div><span>Website</span>@if ($dealer->website)<a href="{{ $dealer->website }}" target="_blank" rel="noopener">{{ preg_replace('#^https?://(www\.)?#', '', rtrim($dealer->website, '/')) }}</a>@else<b>—</b>@endif</div>
                            </div>
                        </div>

                        <div class="pcard">
                            <div class="pcard-head">
                                <span class="pic">{!! $icon($paths['pin'], 20) !!}</span>
                                <div><div class="t">Location</div><div class="s">Physical address, timezone and map location.</div></div>
                                @if ($canManage) <button type="button" class="ebtn" wire:click="$set('tab', 'location')">{!! $icon($paths['edit'], 14) !!} Edit</button> @endif
                            </div>
                            <div class="kv two">
                                <div><span>Address</span><b>{{ trim($dealer->street_address . ($dealer->unit ? ', Unit ' . $dealer->unit : '')) ?: '—' }}</b></div>
                                <div><span>Country</span><b>{{ $dealer->country === 'CA' ? 'Canada' : ($dealer->country ?: '—') }}</b></div>
                                <div><span>City</span><b>{{ $dealer->city ?: '—' }}</b></div>
                                <div><span>Timezone</span><b>{{ $dealer->timezone ?: '—' }}</b></div>
                                <div><span>Province</span><b>{{ \App\Support\CanadianAddress::PROVINCES[$dealer->province] ?? ($dealer->province ?: '—') }}</b></div>
                                <div><span>Coordinates</span><b>{{ $dealer->hasCoordinates() ? number_format($dealer->latitude, 6) . ', ' . number_format($dealer->longitude, 6) : '—' }}</b></div>
                                <div><span>Postal code</span><b>{{ $dealer->postal_code ?: '—' }}</b></div>
                                <div><span>Rooftops</span><b>{{ $this->rooftops()->count() }}</b></div>
                            </div>
                        </div>

                        <div class="pcard">
                            <div class="pcard-head">
                                <span class="pic">{!! $icon($paths['users'], 20) !!}</span>
                                <div><div class="t">Team &amp; access</div><div class="s">Dealership staff and invitations.</div></div>
                                @if ($this->canManageUsers()) <button type="button" class="ebtn" wire:click="$set('tab', 'team')">{!! $icon($paths['users'], 14) !!} Manage users</button> @endif
                            </div>
                            <div class="people">
                                @forelse ($this->staffMembers()->take(4) as $member)
                                    <div class="person">
                                        <span class="av">{{ $member->initials }}</span>
                                        <span class="grow"><b>{{ $member->name }}</b><small>{{ $member->email }}</small></span>
                                        <span class="dot-st {{ $member->isSuspended() ? 'off' : 'on' }}">{{ $member->isSuspended() ? 'Suspended' : 'Active' }}</span>
                                    </div>
                                @empty
                                @endforelse
                                @foreach ($this->openInvitations()->take(max(0, 4 - $this->staffMembers()->count())) as $invitation)
                                    <div class="person">
                                        <span class="av muted">{{ collect(explode(' ', $invitation->name))->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('') }}</span>
                                        <span class="grow"><b>{{ $invitation->name }}</b><small>{{ $invitation->email }}</small></span>
                                        <span class="dot-st wait">Invited</span>
                                    </div>
                                @endforeach
                                @if ($this->staffMembers()->isEmpty() && $this->openInvitations()->isEmpty())
                                    <div class="empty-line">No one has portal access yet.</div>
                                @endif
                                @if ($this->canManageUsers())
                                    <button type="button" class="add-line" wire:click="$set('tab', 'team')">+ Invite new user</button>
                                @endif
                            </div>
                        </div>

                        <div class="pcard">
                            <div class="pcard-head">
                                <span class="pic">{!! $icon($paths['shield'], 20) !!}</span>
                                <div><div class="t">CPO program</div><div class="s">Follows the brand’s inspection checklist and offers.</div></div>
                                <button type="button" class="ebtn" wire:click="$set('tab', 'cpo')">View</button>
                            </div>
                            <div class="kv">
                                <div class="row"><span>Franchise brand</span><b>{{ $settings['brand']->name ?? 'Not set' }}</b></div>
                                <div class="row"><span>Inspection checklist</span><b>{{ ($settings['checklist'] ?? null) ? $settings['checklist']->name . ' (' . $settings['checklist']->points_count . ' points)' : 'None yet' }}</b></div>
                                <div class="row"><span>Shows on</span><b>{{ ($settings['checklist'] ?? null) ? \App\Models\InspectionProgram::APPLIES_TO_LABELS[$settings['checklist']->applies_to] : '—' }}</b></div>
                                <div class="row"><span>Active offers</span><b>{{ $settings ? $settings['offers'] . ' ' . ($settings['offers'] === 1 ? 'offer' : 'offers') : '—' }}</b></div>
                            </div>
                        </div>
                    </div>

                @elseif ($tab === 'info')
                    <form wire:submit="save" class="pcard">
                        <div class="pcard-head">
                            <span class="pic">{!! $icon($paths['build'], 20) !!}</span>
                            <div><div class="t">Dealer information</div><div class="s">What shoppers see, and how the dealer is registered.</div></div>
                        </div>
                        <fieldset class="fields" @disabled(! $canManage) style="border:none; margin:0;">@include('partials.dealer.info-fields')</fieldset>
                        @if ($canManage) <div class="form-end"><button type="submit" class="btn btn-primary">Save changes</button></div> @endif
                    </form>

                @elseif ($tab === 'location')
                    <form wire:submit="save" class="pcard">
                        <div class="pcard-head">
                            <span class="pic">{!! $icon($paths['pin'], 20) !!}</span>
                            <div><div class="t">Location</div><div class="s">Where this dealer’s cars physically are. Required before any vehicle can be published.</div></div>
                        </div>
                        <fieldset class="fields" @disabled(! $canManage) style="border:none; margin:0;">@include('partials.dealer.location-fields')</fieldset>
                        @if ($canManage) <div class="form-end"><button type="submit" class="btn btn-primary">Save changes</button></div> @endif
                    </form>

                    <div class="pcard" style="margin-top:16px">
                        <div class="pcard-head">
                            <span class="pic">{!! $icon($paths['pin'], 20) !!}</span>
                            <div><div class="t">Rooftops</div><div class="s">Each physical store. Every car sits at one; the dealer stays the seller.</div></div>
                            @if ($canManage && ! $rooftopFormOpen) <button type="button" class="ebtn" wire:click="startRooftop">+ Add rooftop</button> @endif
                        </div>
                        <div class="people">
                            @foreach ($this->rooftops() as $rooftop)
                                <div class="person">
                                    <span class="grow">
                                        <b>{{ $rooftop->display_name }} @if ($rooftop->is_primary) · <span style="color:var(--ink-3)">Main store</span> @endif</b>
                                        <small>{{ implode(' · ', $rooftop->address_lines) ?: 'No address yet' }} · {{ $rooftop->vehicles_count }} {{ $rooftop->vehicles_count === 1 ? 'car' : 'cars' }} · feed {{ $rooftop->feed_rooftop_id ?? 'not linked' }}</small>
                                    </span>
                                    @if ($rooftop->is_primary)
                                        <small style="color:var(--ink-3)">Edited above</small>
                                    @else
                                        <span class="dot-st {{ $rooftop->is_active ? 'on' : 'off' }}">{{ $rooftop->is_active ? 'Open' : 'Closed' }}</span>
                                        @if ($canManage)
                                            <button type="button" class="ebtn" wire:click="startRooftop({{ $rooftop->id }})">Edit</button>
                                            <button type="button" class="ebtn" wire:click="toggleRooftop({{ $rooftop->id }})" wire:confirm="{{ $rooftop->is_active ? 'Close this store? Its cars come off the marketplace.' : 'Reopen this store?' }}">{{ $rooftop->is_active ? 'Close' : 'Reopen' }}</button>
                                        @endif
                                    @endif
                                </div>
                            @endforeach
                        </div>

                        @if ($rooftopFormOpen)
                            <form wire:submit="saveRooftop">
                                <div class="fields">
                                    <div class="field w4">
                                        <label for="rt-name">Store name</label>
                                        <input id="rt-name" type="text" wire:model="rooftopForm.name" maxlength="120">
                                        @error('rooftopForm.name') <span class="err">{{ $message }}</span> @enderror
                                    </div>
                                    <div class="field w2">
                                        <label for="rt-feed">Feed rooftop id <span class="opt">(optional)</span></label>
                                        <input id="rt-feed" type="text" wire:model="rooftopForm.feed_rooftop_id" maxlength="64">
                                        @error('rooftopForm.feed_rooftop_id') <span class="err">{{ $message }}</span> @enderror
                                    </div>
                                    <div class="field w4">
                                        <label for="rt-street">Street address</label>
                                        <input id="rt-street" type="text" wire:model="rooftopForm.street_address" maxlength="160">
                                        @error('rooftopForm.street_address') <span class="err">{{ $message }}</span> @enderror
                                    </div>
                                    <div class="field w2">
                                        <label for="rt-unit">Unit <span class="opt">(optional)</span></label>
                                        <input id="rt-unit" type="text" wire:model="rooftopForm.unit" maxlength="30">
                                    </div>
                                    <div class="field w2">
                                        <label for="rt-city">City</label>
                                        <input id="rt-city" type="text" wire:model="rooftopForm.city" maxlength="80">
                                        @error('rooftopForm.city') <span class="err">{{ $message }}</span> @enderror
                                    </div>
                                    <div class="field w2">
                                        <label for="rt-province">Province / territory</label>
                                        <select id="rt-province" wire:model="rooftopForm.province">
                                            <option value="">Choose…</option>
                                            @foreach (\App\Support\CanadianAddress::PROVINCES as $code => $provinceName)
                                                <option value="{{ $code }}">{{ $provinceName }}</option>
                                            @endforeach
                                        </select>
                                        @error('rooftopForm.province') <span class="err">{{ $message }}</span> @enderror
                                    </div>
                                    <div class="field w2">
                                        <label for="rt-postal">Postal code</label>
                                        <input id="rt-postal" type="text" wire:model="rooftopForm.postal_code" maxlength="7">
                                        @error('rooftopForm.postal_code') <span class="err">{{ $message }}</span> @enderror
                                    </div>
                                    <div class="field w2">
                                        <label for="rt-tz">Timezone</label>
                                        <select id="rt-tz" wire:model="rooftopForm.timezone">
                                            <option value="">From the province</option>
                                            @foreach ($this->timezoneOptions() as $zone)
                                                <option value="{{ $zone }}">{{ $zone }}</option>
                                            @endforeach
                                        </select>
                                        @error('rooftopForm.timezone') <span class="err">{{ $message }}</span> @enderror
                                    </div>
                                    <div class="field w2">
                                        <label for="rt-lat">Latitude <span class="opt">(optional)</span></label>
                                        <input id="rt-lat" type="text" inputmode="decimal" wire:model="rooftopForm.latitude">
                                        @error('rooftopForm.latitude') <span class="err">{{ $message }}</span> @enderror
                                    </div>
                                    <div class="field w2">
                                        <label for="rt-lng">Longitude <span class="opt">(optional)</span></label>
                                        <input id="rt-lng" type="text" inputmode="decimal" wire:model="rooftopForm.longitude">
                                        @error('rooftopForm.longitude') <span class="err">{{ $message }}</span> @enderror
                                    </div>
                                    <div class="field w2">
                                        <label for="rt-phone">Store phone <span class="opt">(optional)</span></label>
                                        <input id="rt-phone" type="tel" wire:model="rooftopForm.phone" maxlength="30">
                                        @error('rooftopForm.phone') <span class="err">{{ $message }}</span> @enderror
                                    </div>
                                    <div class="field w2">
                                        <label for="rt-email">Store email <span class="opt">(optional)</span></label>
                                        <input id="rt-email" type="email" wire:model="rooftopForm.email" maxlength="160">
                                        @error('rooftopForm.email') <span class="err">{{ $message }}</span> @enderror
                                    </div>
                                </div>
                                <div class="form-end" style="gap:8px">
                                    <button type="button" class="btn btn-ghost" wire:click="cancelRooftop">Cancel</button>
                                    <button type="submit" class="btn btn-primary">{{ $editingRooftopId ? 'Save rooftop' : 'Add rooftop' }}</button>
                                </div>
                            </form>
                        @endif
                    </div>

                @elseif ($tab === 'team')
                    @if ($this->canManageUsers())
                        @include('partials.dealer.users')
                    @else
                        <div class="pcard"><div class="empty-line" style="padding:20px">You can’t manage this dealer’s users.</div></div>
                    @endif

                @elseif ($tab === 'cpo')
                    <div class="pcard">
                        <div class="pcard-head">
                            <span class="pic">{!! $icon($paths['shield'], 20) !!}</span>
                            <div><div class="t">CPO program</div><div class="s">Set by the brand — every {{ $settings['brand']->name ?? '' }} dealer follows the same checklist and offers.</div></div>
                            @can('settings.manage') @if ($settings) <a class="ebtn" href="{{ route('admin.brands.show', $settings['brand']) }}" wire:navigate>{{ $settings['brand']->name }} settings {!! $icon($paths['chev'], 14) !!}</a> @endif @endcan
                        </div>
                        <div class="brand-panel">
                            <span class="mark big">{{ $settings ? $settings['brand']->initials() : '—' }}</span>
                            <div class="grow">
                                <div class="bp-name">{{ $settings['brand']->name ?? 'No brand set' }}</div>
                                <div class="bp-sub">{{ $settings ? 'Same rules as ' . $settings['dealers'] . ' other ' . $settings['brand']->name . ' ' . ($settings['dealers'] === 1 ? 'dealer' : 'dealers') : 'This dealer follows no brand settings until a brand is set.' }}</div>
                            </div>
                            @if ($canManage) <button type="button" class="brand-change" wire:click="startBrandChange">{{ $settings ? 'Change brand…' : 'Set brand…' }}</button> @endif
                        </div>
                        <div class="kv">
                            <div class="row"><span>Inspection checklist</span><b>{{ ($settings['checklist'] ?? null) ? $settings['checklist']->name : 'None yet' }}</b></div>
                            <div class="row"><span>Points</span><b>{{ ($settings['checklist'] ?? null) ? $settings['checklist']->points_count : '—' }}</b></div>
                            <div class="row"><span>Shows on</span><b>{{ ($settings['checklist'] ?? null) ? \App\Models\InspectionProgram::APPLIES_TO_LABELS[$settings['checklist']->applies_to] : '—' }}</b></div>
                            <div class="row"><span>Current offers</span><b>{{ $settings['offers'] ?? 0 }}</b></div>
                        </div>
                    </div>

                @elseif ($tab === 'offers')
                    <div class="pcard">
                        <div class="pcard-head">
                            <span class="pic">{!! $icon($paths['tag'], 20) !!}</span>
                            <div><div class="t">Offers &amp; incentives</div><div class="s">{{ $settings ? $settings['brand']->name . ' offers this dealer’s eligible cars get, within their dates.' : 'No brand set.' }}</div></div>
                            @can('settings.manage') @if ($settings) <a class="ebtn" href="{{ route('admin.brands.offers', $settings['brand']) }}" wire:navigate>Manage {!! $icon($paths['chev'], 14) !!}</a> @endif @endcan
                        </div>
                        @forelse ($this->brandOffers() as $offer)
                            <div class="offer-line">
                                <span class="o-big">{{ $offer->headline() }}</span>
                                <span class="grow"><b>{{ $offer->title }}</b><small>{{ $offer->models ? implode(', ', $offer->models) : 'All models' }} · {{ $offer->starts_on->format('M j') }} – {{ $offer->ends_on->format('M j, Y') }}</small></span>
                                <span class="o-st {{ $offer->state() }}">{{ $offer->state() }}</span>
                            </div>
                        @empty
                            <div class="empty-line" style="padding:6px 22px 22px">No current or upcoming offers.</div>
                        @endforelse
                    </div>

                @elseif ($tab === 'integrations')
                    @php $import = $this->lastImport(); @endphp
                    <div class="pcard">
                        <div class="pcard-head">
                            <span class="pic">{!! $icon($paths['plug'], 20) !!}</span>
                            <div><div class="t">Inventory feed</div><div class="s">The dealer’s CSV export, matched to this dealer by its rooftop id.</div></div>
                            <a class="ebtn" href="{{ route('admin.dealers.inventory', $dealer) }}" wire:navigate>Open inventory {!! $icon($paths['chev'], 14) !!}</a>
                        </div>
                        <div class="kv">
                            <div class="row"><span>Status</span><b class="{{ $dealer->feed_rooftop_id ? 'ok' : 'warn' }}">{{ $dealer->feed_rooftop_id ? 'Connected' : 'Not connected yet' }}</b></div>
                            @foreach ($this->rooftops() as $rooftop)
                                <div class="row"><span>{{ $rooftop->is_primary ? 'Rooftop id' : $rooftop->display_name }}</span><b class="mono">{{ $rooftop->feed_rooftop_id ?? ($rooftop->is_primary ? 'Set by the first import' : 'Not linked') }}</b></div>
                            @endforeach
                            <div class="row"><span>Last import</span><b>{{ $import ? $import->created_at->diffForHumans() . ' by ' . ($import->actor_email ?? 'console') : 'Never' }}</b></div>
                            <div class="row"><span>Vehicles</span><b>{{ $stats['live'] }} live of {{ $stats['vehicles'] }}</b></div>
                        </div>
                    </div>
                @endif
            </div>

            {{-- Right column --}}
            <aside class="pf-side">
                <div class="side-card">
                    <div class="side-t">Dealership status</div>
                    @if ($canManage)
                        <div class="status-pick" x-data="{ open: false }" @click.outside="open = false">
                            <button type="button" class="sp-current" @click="open = ! open"><i class="st-dot st-{{ $dealer->status }}"></i>{{ \App\Models\Dealer::STATUS_LABELS[$dealer->status] }} <span class="sp-chev">{!! $icon('<path d="m6 9 6 6 6-6"/>', 15) !!}</span></button>
                            <div class="sp-menu" x-show="open" x-cloak x-transition>
                                @foreach (['active' => 'Live — published cars appear on TruCars', 'draft' => 'Being set up — no cars listed', 'suspended' => 'Cars unlisted, staff locked out'] as $value => $detail)
                                    @continue($value === $dealer->status)
                                    <button type="button" wire:click="changeStatus('{{ $value }}')" wire:confirm="Change {{ $displayName }} to {{ \App\Models\Dealer::STATUS_LABELS[$value] }}? {{ $detail }}." @click="open = false">
                                        <i class="st-dot st-{{ $value }}"></i><span><b>{{ \App\Models\Dealer::STATUS_LABELS[$value] }}</b><small>{{ $detail }}</small></span>
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @else
                        <div class="sp-current static"><i class="st-dot st-{{ $dealer->status }}"></i>{{ \App\Models\Dealer::STATUS_LABELS[$dealer->status] }}</div>
                    @endif
                    @error('status') <div class="err" style="margin-top:8px">{{ $message }}</div> @enderror
                    <div class="side-note">{{ ['active' => 'Live — published cars appear on TruCars.', 'draft' => 'Draft — none of its cars are listed yet.', 'suspended' => 'Suspended — cars unlisted, staff can’t sign in.'][$dealer->status] ?? '' }}</div>
                </div>

                <div class="side-card">
                    <div class="side-t">Location map</div>
                    @if ($dealer->hasCoordinates())
                        @php $lat = $dealer->latitude; $lng = $dealer->longitude; @endphp
                        <iframe class="map" loading="lazy" title="Map of {{ $displayName }}" src="https://www.openstreetmap.org/export/embed.html?bbox={{ $lng - 0.02 }}%2C{{ $lat - 0.012 }}%2C{{ $lng + 0.02 }}%2C{{ $lat + 0.012 }}&amp;layer=mapnik&amp;marker={{ $lat }}%2C{{ $lng }}"></iframe>
                        <a class="side-link" href="https://www.openstreetmap.org/?mlat={{ $lat }}&mlon={{ $lng }}#map=15/{{ $lat }}/{{ $lng }}" target="_blank" rel="noopener">Open larger map {!! $icon($paths['ext'], 13) !!}</a>
                    @else
                        <div class="map empty">{!! $icon($paths['pin'], 22) !!}<span>Add coordinates on the Location tab to show the map and distance to shoppers.</span></div>
                    @endif
                </div>

                <div class="side-card">
                    <div class="side-t">Setup checklist</div>
                    <div class="ring-row">
                        <span class="ring" style="--p:{{ $percent }}"><b>{{ $percent }}%</b></span>
                        <span><b>{{ $done }} of {{ count($steps) }} completed</b><small>{{ $done === count($steps) ? 'All set.' : ($percent >= 60 ? 'Almost ready!' : 'Keep going.') }}</small></span>
                    </div>
                    @foreach ($steps as $step)
                        <button type="button" class="step {{ $step['done'] ? 'done' : '' }}" wire:click="$set('tab', '{{ $step['tab'] }}')">
                            <span class="tick">@if ($step['done']){!! $icon('<path d="M20 6 9 17l-5-5"/>', 12) !!}@endif</span>
                            <span class="grow">{{ $step['label'] }}</span>
                            {!! $icon($paths['chev'], 14) !!}
                        </button>
                    @endforeach
                </div>

                <div class="side-card">
                    <div class="side-t">Quick actions</div>
                    <a class="qa primary" href="{{ $publicUrl }}" target="_blank" rel="noopener">{!! $icon($paths['car'], 16) !!} View public page {!! $icon($paths['ext'], 13) !!}</a>
                    <a class="qa" href="{{ route('admin.dealers.inventory', $dealer) }}" wire:navigate>{!! $icon($paths['inbox'], 16) !!} Manage inventory</a>
                    @if ($this->canManageUsers())
                        <button type="button" class="qa" wire:click="$set('tab', 'team')">{!! $icon($paths['send'], 16) !!} Send login invite</button>
                    @endif
                </div>
            </aside>
        </div>

        @include('partials.dealer.brand-dialog')
    @endif
</div>
