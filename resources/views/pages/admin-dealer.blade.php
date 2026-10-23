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

    // Opening hours and extra pickup points for one store, main store included.
    #[Locked]
    public ?int $hoursRooftopId = null;
    public bool $hoursKnown = false;
    public array $hoursForm = [];
    public array $pickupForm = [];

    // Which stores a staff member can work with (Team tab).
    #[Locked]
    public ?int $accessUserId = null;
    public bool $accessAllStores = true;
    public array $accessStoreIds = [];

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

    public function startHours(int $rooftopId): void
    {
        Gate::authorize(Permission::DEALERS_MANAGE);
        $rooftop = $this->dealerOrFail()->rooftops()->findOrFail($rooftopId);

        $this->resetValidation();
        $this->hoursRooftopId = $rooftop->id;
        $this->hoursKnown = $rooftop->hasHours();
        $this->hoursForm = collect(Rooftop::WEEKDAYS)->map(function (string $label, string $day) use ($rooftop) {
            $hours = $rooftop->hours[$day] ?? null;

            return [
                'open'   => $hours === null && $rooftop->hasHours() ? false : true,
                'from'   => $hours[0] ?? '09:00',
                'until'  => $hours[1] ?? '18:00',
            ];
        })->all();
        $points = $rooftop->pickupPoints();
        $this->pickupForm = collect(range(0, 2))->map(fn (int $i) => ['name' => $points[$i]['name'] ?? '', 'address' => $points[$i]['address'] ?? ''])->all();
    }

    public function cancelHours(): void
    {
        $this->hoursRooftopId = null;
    }

    public function saveHours(): void
    {
        Gate::authorize(Permission::DEALERS_MANAGE);
        $rooftop = $this->dealerOrFail()->rooftops()->findOrFail($this->hoursRooftopId);

        $rules = [];
        foreach (array_keys(Rooftop::WEEKDAYS) as $day) {
            if ($this->hoursKnown && ! empty($this->hoursForm[$day]['open'])) {
                $rules["hoursForm.{$day}.from"] = ['required', 'date_format:H:i'];
                $rules["hoursForm.{$day}.until"] = ['required', 'date_format:H:i', "after:hoursForm.{$day}.from"];
            }
        }
        foreach (range(0, 2) as $i) {
            $rules["pickupForm.{$i}.name"] = ['nullable', 'string', 'max:80', "required_with:pickupForm.{$i}.address"];
            $rules["pickupForm.{$i}.address"] = ['nullable', 'string', 'max:160', "required_with:pickupForm.{$i}.name"];
        }

        $this->validate($rules, [
            'hoursForm.*.until.after'          => 'Closing time must be after opening time.',
            'pickupForm.*.name.required_with'    => 'Give this pickup point a name.',
            'pickupForm.*.address.required_with' => 'Add the pickup point’s address.',
        ], collect(Rooftop::WEEKDAYS)->flatMap(fn (string $label, string $day) => [
            "hoursForm.{$day}.from" => "{$label} opening time", "hoursForm.{$day}.until" => "{$label} closing time",
        ])->all());

        $hours = $this->hoursKnown
            ? collect(Rooftop::WEEKDAYS)->map(fn (string $label, string $day) => empty($this->hoursForm[$day]['open'])
                ? null
                : [$this->hoursForm[$day]['from'], $this->hoursForm[$day]['until']])->all()
            : null;
        $points = collect($this->pickupForm)
            ->map(fn (array $point) => ['name' => trim((string) $point['name']), 'address' => trim((string) $point['address'])])
            ->filter(fn (array $point) => $point['name'] !== '')
            ->values()->all();

        $before = $rooftop->only(['hours', 'pickup_points']);
        $rooftop->update(['hours' => $hours, 'pickup_points' => $points ?: null]);
        AuditLog::record('rooftop.hours_updated', $rooftop, $before, $rooftop->only(['hours', 'pickup_points']));

        $this->hoursRooftopId = null;
        $this->statusMessage = "Saved hours and pickup points for {$rooftop->display_name}.";
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
        return $this->dealerId === null ? collect() : $this->dealerOrFail()->staff()->with('storeGrants.dealer:id,name')->orderBy('name')->get();
    }

    /**
     * Stores a staff member of this dealer can be given: its own, plus those
     * of the other dealers in its group. Nothing outside the group, ever.
     */
    public function grantableStores(): Collection
    {
        $dealer = $this->dealerOrFail();

        return Rooftop::with('dealer:id,name')
            ->whereIn('dealer_id', $dealer->dealer_group_id
                ? Dealer::where('dealer_group_id', $dealer->dealer_group_id)->select('id')
                : [$dealer->id])
            ->get()
            ->sortBy(fn (Rooftop $store) => [$store->dealer_id !== $dealer->id, $store->dealer->name, ! $store->is_primary, $store->display_name])
            ->values();
    }

    public function startStoreAccess(int $userId): void
    {
        Gate::authorize(Permission::DEALER_USERS_MANAGE);
        $member = $this->dealerOrFail()->staff()->whereKey($userId)->firstOrFail();

        $this->accessUserId = $member->id;
        $this->accessStoreIds = $member->storeGrants()->pluck('rooftops.id')->map(fn ($id) => (string) $id)->all();
        $this->accessAllStores = $this->accessStoreIds === [];
        $this->resetErrorBag('accessStoreIds');
    }

    public function cancelStoreAccess(): void
    {
        $this->accessUserId = null;
    }

    /** Either every store of this dealer (no grants), or exactly the chosen ones. */
    public function saveStoreAccess(): void
    {
        Gate::authorize(Permission::DEALER_USERS_MANAGE);
        $member = $this->dealerOrFail()->staff()->whereKey($this->accessUserId)->firstOrFail();

        $chosen = $this->accessAllStores ? [] : array_values(array_intersect(
            $this->grantableStores()->pluck('id')->all(),
            array_map('intval', $this->accessStoreIds),
        ));

        if (! $this->accessAllStores && $chosen === []) {
            $this->addError('accessStoreIds', 'Choose at least one store, or give access to all of this dealer’s stores.');

            return;
        }

        $before = $member->storeGrants()->pluck('rooftops.id')->all();
        $member->storeGrants()->sync($chosen);
        AuditLog::record('dealer_user.store_access_changed', $member, ['stores' => $before ?: 'all'], ['stores' => $chosen ?: 'all']);

        $this->accessUserId = null;
        $this->statusMessage = $chosen === []
            ? "{$member->email} can work with every store of this dealer."
            : "{$member->email} can now work with " . count($chosen) . ' ' . (count($chosen) === 1 ? 'store' : 'stores') . '.';
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
        .tabs { display:flex; gap:4px; margin-bottom:18px; border-bottom:1px solid var(--line-strong); overflow-x:auto; }
        .tabs a { padding:10px 14px 9px; font-family:var(--font-display); font-size:16px; font-weight:600; text-transform:uppercase; color:var(--ink-2); border-bottom:3px solid transparent; margin-bottom:-1px; white-space:nowrap; }
        .tabs a:hover { color:var(--ink); }
        .tabs a.on { color:var(--ink); border-bottom-color:var(--primary); }
        .dl-head { margin-bottom:22px; }
        .dl-head h1 { font-family:var(--font-display); font-size:36px; font-weight:700; line-height:1; text-transform:uppercase; letter-spacing:0; margin:0 0 10px; color:var(--ink); }
        .dl-head p { margin:0; font-size:15px; color:var(--ink-2); max-width:640px; }

        .btn { display:inline-flex; align-items:center; justify-content:center; gap:7px; font-family:var(--font-display); font-size:15px; font-weight:600; text-transform:uppercase; letter-spacing:.02em; line-height:1.2; border-radius:3px; padding:10px 18px; border:1.5px solid transparent; transition:background .15s ease, color .15s ease, border-color .15s ease; white-space:nowrap; cursor:pointer; }
        .btn-primary { background:var(--primary); border-color:var(--primary); color:#fff; box-shadow:none; }
        .btn-primary:hover { background:var(--primary-press); border-color:var(--primary-press); }
        .btn-primary:disabled { opacity:.5; cursor:not-allowed; }
        .btn-ghost { background:#fff; color:var(--ink); border-color:var(--ink); }
        .btn-ghost:hover { background:var(--ink); color:#fff; }
        .btn-warn { color:#D81A48; border-color:#D81A48; }
        .btn-warn:hover { background:#D81A48; border-color:#D81A48; color:#fff; }
        .btn:focus-visible, .ebtn:focus-visible, .qa:focus-visible, .brand-change:focus-visible, .stat:focus-visible { outline:2px solid var(--primary); outline-offset:2px; }

        .dl-status { display:flex; align-items:center; gap:9px; background:var(--good-soft); color:#0B7355; border-left:3px solid var(--good); border-radius:2px; padding:11px 15px; font-size:14px; font-weight:600; margin-bottom:20px; }

        .dl-grid { display:grid; gap:28px; max-width:880px; border:none; padding:0; margin:0; min-width:0; }
        .card { background:transparent; border:none; border-top:2px solid var(--ink); border-radius:0; box-shadow:none; }
        .card-head { padding:14px 0 12px; border-bottom:1px solid var(--line-strong); }
        .card-head .ttl { font-family:var(--font-display); font-size:22px; font-weight:700; line-height:1.05; text-transform:uppercase; color:var(--ink); }
        .card-head .sub { font-size:14px; color:var(--ink-2); margin-top:5px; max-width:680px; }
        .brand-fixed { display:flex; align-items:center; gap:10px; flex-wrap:wrap; padding:10px 12px; border:1px solid var(--line-strong); border-radius:3px; background:#fff; }
        .brand-chip { font-family:var(--font-display); font-size:15px; font-weight:700; text-transform:uppercase; padding:3px 9px; border-radius:2px; background:var(--ink); color:#fff; }
        .brand-chip.missing { background:#FFF8DD; color:#7A4E00; }
        .brand-follows-text { font-size:13.5px; color:var(--ink-2); flex:1; min-width:200px; }
        .brand-follows-text a { color:var(--primary-press); font-weight:700; text-decoration:underline; text-underline-offset:3px; }
        .brand-change { font-size:13.5px; font-weight:700; color:var(--ink); padding:6px 12px; border-radius:3px; border:1.5px solid var(--ink); background:#fff; cursor:pointer; }
        .brand-change:hover { background:var(--ink); color:#fff; }
        .bc-scrim { position:fixed; inset:0; background:rgba(7,20,55,.55); z-index:80; display:grid; place-items:center; padding:20px; }
        .bc { width:min(560px, 100%); max-height:calc(100vh - 40px); overflow:auto; background:#fff; border-top:3px solid var(--ink); border-radius:4px; box-shadow:var(--shadow-md); }
        .bc-head { padding:22px 24px 6px; }
        .bc-head .warn { display:inline-flex; align-items:center; gap:7px; font-size:13px; font-weight:700; color:#7A4E00; background:#FFF8DD; border-radius:2px; padding:4px 8px; }
        .bc-head .warn svg { color:#C59A00; }
        .bc-head h2 { font-family:var(--font-display); font-size:26px; font-weight:700; line-height:1.05; text-transform:uppercase; letter-spacing:0; margin:12px 0 8px; color:var(--ink); }
        .bc-head p { margin:0; font-size:14px; color:var(--ink-2); }
        .bc-body { padding:16px 24px 6px; display:grid; gap:16px; }
        .bc-body label { font-size:14px; font-weight:600; color:var(--ink); display:block; margin-bottom:6px; }
        .bc-body select, .bc-body input, .bc-body textarea { width:100%; border:1.5px solid var(--line-strong); border-radius:3px; padding:10px 12px; font-size:15px; font-family:inherit; color:var(--ink); background:#fff; }
        .bc-body select:focus, .bc-body input:focus, .bc-body textarea:focus { outline:none; border-color:var(--ink); box-shadow:inset 0 0 0 1px var(--ink); }
        .impact { border-top:2px solid var(--ink); }
        .impact-row { display:grid; grid-template-columns:110px minmax(0, 1fr) 18px minmax(0, 1fr); gap:10px; align-items:center; padding:10px 0; border-bottom:1px solid var(--line-strong); font-size:14px; }
        .impact-row .k { font-family:var(--font-display); font-size:14px; font-weight:600; color:var(--ink-3); text-transform:uppercase; }
        .impact-row .from { color:var(--ink-3); text-decoration:line-through; }
        .impact-row .to { font-weight:700; color:var(--ink); }
        .impact-note { font-size:13.5px; color:#7A4E00; font-weight:600; background:#FFF8DD; border-left:3px solid #C59A00; border-radius:2px; padding:10px 12px; }
        .bc-foot { display:flex; justify-content:flex-end; gap:8px; flex-wrap:wrap; padding:18px 24px 22px; }
        .bc-foot .btn-danger, .bc-foot .btn-cancel { font-family:var(--font-display); font-size:15px; font-weight:600; text-transform:uppercase; letter-spacing:.02em; border-radius:3px; padding:10px 18px; cursor:pointer; }
        .bc-foot .btn-danger { background:#fff; color:#D81A48; border:1.5px solid #D81A48; }
        .bc-foot .btn-danger:not(:disabled):hover { background:#D81A48; color:#fff; }
        .bc-foot .btn-danger:disabled { opacity:.4; cursor:not-allowed; }
        .bc-foot .btn-cancel { border:1.5px solid var(--ink); color:var(--ink); background:#fff; }
        .bc-foot .btn-cancel:hover { background:var(--ink); color:#fff; }
        .fields { padding:18px 0 20px; min-width:0; display:grid; grid-template-columns:repeat(6, minmax(0, 1fr)); gap:16px; }
        .field { display:flex; flex-direction:column; gap:6px; grid-column:span 3; min-width:0; }
        .field.w2 { grid-column:span 2; }
        .field.w4 { grid-column:span 4; }
        .field.w6 { grid-column:1 / -1; }
        .field label { font-size:14px; font-weight:600; color:var(--ink); }
        .field label .opt { font-weight:500; color:var(--ink-3); }
        .field input, .field select { border:1.5px solid var(--line-strong); border-radius:3px; padding:10px 12px; font-size:15px; color:var(--ink); background:#fff; font-family:inherit; width:100%; }
        .field input:focus, .field select:focus { outline:none; border-color:var(--ink); box-shadow:inset 0 0 0 1px var(--ink); }
        .field input[readonly] { background:var(--bg-2); color:var(--ink-3); }
        .field .err { font-size:13px; color:#D81A48; font-weight:600; }
        .field .hint { font-size:13px; color:var(--ink-3); }
        input[type=checkbox], input[type=radio] { accent-color:var(--ink); width:16px; height:16px; }

        .status-toggle { display:flex; gap:0; border:1.5px solid var(--ink); border-radius:3px; overflow:hidden; }
        .status-opt { flex:1; border:none; border-radius:0; padding:12px 14px; text-align:left; background:#fff; cursor:pointer; transition:background .14s ease, color .14s ease; }
        .status-opt + .status-opt { border-left:1.5px solid var(--ink); }
        .status-opt .k { font-family:var(--font-display); font-size:17px; font-weight:700; text-transform:uppercase; color:var(--ink); }
        .status-opt .d { font-size:13px; color:var(--ink-2); margin-top:2px; }
        .status-opt:hover { background:var(--bg-2); }
        .status-opt.on { background:var(--ink); }
        .status-opt.on .k { color:#fff; }
        .status-opt.on .d { color:rgba(255,255,255,.78); }

        .btn-sm { font-family:var(--font); font-size:13.5px; font-weight:700; text-transform:none; letter-spacing:0; padding:6px 12px; }
        .dl-users { max-width:880px; margin-top:28px; }
        .u-row { display:flex; align-items:center; gap:12px; padding:13px 0; border-bottom:1px solid var(--line-strong); }
        .u-row > div:first-child { flex:1; min-width:0; }
        .u-name { font-size:15px; font-weight:700; color:var(--ink); }
        .u-meta { font-size:13px; color:var(--ink-3); margin-top:2px; overflow:hidden; text-overflow:ellipsis; }
        .u-pill { font-family:var(--font-display); font-size:13px; font-weight:600; text-transform:uppercase; padding:2px 7px; border-radius:2px; background:var(--good-soft); color:#0B7355; }
        .u-pill.u-off { background:#FFEEF3; color:#D81A48; }
        .u-pill.u-wait { background:#FFF8DD; color:#7A4E00; }
        .u-acts { display:flex; gap:6px; flex-wrap:wrap; }
        .rt-hours { display:grid; gap:8px; padding:14px 16px 16px; margin:0 0 12px; border:1px solid var(--line-strong); border-top:none; background:#fff; }
        .rt-day { display:flex; align-items:center; gap:10px; flex-wrap:wrap; font-size:14px; }
        .rt-day .u-check { width:130px; }
        .rt-day input[type=time], .rt-day input[type=text] { border:1.5px solid var(--line-strong); border-radius:3px; padding:7px 10px; font:inherit; font-size:14px; background:#fff; color:var(--ink); flex:1; min-width:0; }
        .rt-day input[type=time]:focus, .rt-day input[type=text]:focus { outline:none; border-color:var(--ink); }
        .rt-day input[type=time] { flex:0 0 auto; }
        .rt-hours .err { font-size:13px; color:#D81A48; font-weight:600; }
        .rt-hours .hint { font-size:13px; color:var(--ink-3); }
        .rt-hours .sb-label { font-family:var(--font-display); font-size:16px; font-weight:700; text-transform:uppercase; color:var(--ink); margin-top:10px; padding-top:10px; border-top:1px solid var(--line-strong); }
        .rt-hours .opt { font-family:var(--font); font-size:13px; font-weight:500; text-transform:none; color:var(--ink-3); }
        .u-access { display:grid; gap:8px; padding:14px 16px 16px; border:1px solid var(--line-strong); border-top:none; background:#fff; }
        .u-stores { display:grid; gap:6px; padding:4px 0 4px 24px; }
        .u-check { display:flex; align-items:center; gap:8px; font-size:14px; font-weight:600; color:var(--ink); }
        .u-access .err { font-size:13px; color:#D81A48; font-weight:600; }
        .u-empty { padding:14px 0; font-size:14px; color:var(--ink-3); border-bottom:1px solid var(--line-strong); }
        .u-invite { display:grid; grid-template-columns:1fr 1fr auto; gap:14px; align-items:end; padding:18px 0 4px; }
        @media (max-width:760px){ .u-invite { grid-template-columns:1fr; } .u-row { flex-wrap:wrap; } }
        .dl-actions { display:flex; justify-content:flex-end; gap:10px; max-width:880px; margin-top:22px; padding-top:18px; border-top:1px solid var(--line-strong); }


        /* ---- profile ---- */
        .hero { position:relative; border-top:2px solid var(--ink); border-bottom:1px solid var(--line-strong); margin-bottom:22px; }
        .hero-photo { display:none; }
        .hero.has-photo .hero-photo { display:block; position:absolute; top:0; right:0; bottom:0; width:36%; background-position:center; background-size:cover; background-repeat:no-repeat; background-color:var(--bg-2); }
        .hero-body { position:relative; display:flex; align-items:center; gap:24px; padding:24px 0; }
        .hero.has-photo .hero-body { padding-right:calc(36% + 24px); min-height:220px; }
        .hero-brand { display:flex; flex-direction:column; align-items:center; gap:6px; flex-shrink:0; width:96px; }
        .mark { width:72px; height:72px; border-radius:3px; background:var(--ink); color:#fff; display:grid; place-items:center; font-family:var(--font-display); font-size:26px; font-weight:700; letter-spacing:0; }
        .mark.big { width:56px; height:56px; font-size:20px; }
        .mark-name { font-family:var(--font-display); font-size:13px; font-weight:600; text-transform:uppercase; color:var(--ink-2); text-align:center; }
        .hero-main { flex:1; min-width:0; }
        .hero-title { display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
        .hero-title h1 { font-family:var(--font-display); font-size:40px; font-weight:700; line-height:1; text-transform:uppercase; letter-spacing:0; margin:0; color:var(--ink); }
        .st { display:inline-flex; align-items:center; font-family:var(--font-display); font-size:14px; font-weight:600; text-transform:uppercase; padding:3px 8px; border-radius:2px; background:var(--bg-2); color:var(--ink-2); border:1px solid var(--line-strong); }
        .st.st-draft { background:#FFF8DD; color:#7A4E00; border-color:transparent; }
        .st-active, .st.st-active { background:var(--good-soft); color:#0B7355; border-color:transparent; }
        .st.st-suspended { background:#FFEEF3; color:#D81A48; border-color:transparent; }
        .st-dot { width:9px; height:9px; border-radius:1px; background:var(--ink-3); display:inline-block; flex-shrink:0; }
        .st-dot.st-active { background:var(--good); }
        .st-dot.st-suspended { background:#D81A48; }
        .st-dot.st-draft { background:#C59A00; }
        .hero-line { display:flex; align-items:center; gap:7px; margin-top:12px; font-size:15px; color:var(--ink-2); }
        .hero-line svg, .hero-contacts svg { color:var(--ink-3); flex-shrink:0; }
        .hero-contacts { display:flex; flex-wrap:wrap; gap:8px 22px; margin-top:12px; font-size:14px; color:var(--ink-2); }
        .hero-contacts > * { display:inline-flex; align-items:center; gap:7px; min-width:0; overflow-wrap:anywhere; }
        .hero-contacts a { text-decoration:underline; text-underline-offset:3px; }
        .hero-contacts a:hover { color:var(--primary-press); }
        .hero-actions { align-self:flex-start; }
        .hbtn { display:inline-flex; align-items:center; gap:7px; font-size:14px; font-weight:700; padding:8px 14px; border-radius:3px; background:#fff; color:var(--ink); border:1.5px solid var(--ink); white-space:nowrap; }
        .hbtn:hover { background:var(--ink); color:#fff; }
        .photo-btn { position:absolute; right:12px; bottom:12px; display:inline-flex; align-items:center; gap:7px; font-size:13.5px; font-weight:700; color:#fff; padding:8px 13px; border-radius:3px; background:var(--ink); cursor:pointer; }
        .photo-btn:hover { background:#000; }
        .hero:not(.has-photo) .photo-btn { position:static; margin:0 0 18px; background:#fff; color:var(--ink); border:1.5px solid var(--ink); }
        .hero:not(.has-photo) .photo-btn:hover { background:var(--ink); color:#fff; }

        .pf { display:grid; grid-template-columns:minmax(0, 1fr) 290px; gap:28px; align-items:start; }
        .pf-main { min-width:0; }
        .stats { display:flex; flex-wrap:wrap; overflow:hidden; border-top:2px solid var(--ink); border-bottom:1px solid var(--line-strong); margin-bottom:22px; }
        .stat { position:relative; flex:1 1 170px; display:block; padding:14px 18px 16px; margin:-1px 0 0 -1px; border-top:1px solid var(--line-strong); border-left:1px solid var(--line-strong); background:transparent; text-align:left; color:var(--ink); transition:background .15s ease; }
        a.stat:hover, button.stat:hover { background:#fff; }
        button.stat { cursor:pointer; font:inherit; }
        .stat .sv { display:flex; flex-direction:column; gap:6px; padding-right:22px; }
        .stat small { font-size:14px; color:var(--ink-2); }
        .stat b { display:block; font-family:var(--font-display); font-size:34px; font-weight:700; line-height:1; letter-spacing:0; color:var(--ink); font-variant-numeric:tabular-nums; }
        .stat .go { position:absolute; right:14px; top:14px; color:var(--ink-3); }
        a.stat:hover .go, button.stat:hover .go { color:var(--ink); }
        .delta { position:absolute; right:14px; top:13px; font-family:var(--font-display); font-size:13px; font-weight:600; padding:1px 6px; border-radius:2px; }
        .delta.up { background:var(--good-soft); color:#0B7355; }
        .delta.down { background:#FFEEF3; color:#D81A48; }

        .ptabs { display:flex; gap:2px; border-bottom:1px solid var(--line-strong); margin-bottom:22px; overflow-x:auto; }
        .ptabs button { padding:10px 14px 9px; font-family:var(--font-display); font-size:16px; font-weight:600; text-transform:uppercase; color:var(--ink-2); border-bottom:3px solid transparent; margin-bottom:-1px; white-space:nowrap; cursor:pointer; }
        .ptabs button:hover { color:var(--ink); }
        .ptabs button.on { color:var(--ink); border-bottom-color:var(--primary); font-weight:700; }
        .ptabs button:focus-visible { outline:2px solid var(--primary); outline-offset:-2px; }

        .ov { display:grid; grid-template-columns:minmax(0, 1fr) minmax(0, 1fr); gap:30px 28px; }
        .pcard { background:transparent; border:none; border-top:2px solid var(--ink); border-radius:0; box-shadow:none; display:block; min-width:0; }
        .pcard-head { display:flex; align-items:center; gap:14px; padding:14px 0 12px; border-bottom:1px solid var(--line-strong); }
        .pcard-head .t { font-family:var(--font-display); font-size:22px; font-weight:700; line-height:1.05; text-transform:uppercase; color:var(--ink); }
        .pcard-head .s { font-size:14px; color:var(--ink-2); margin-top:4px; }
        .pcard-head > div { flex:1; min-width:0; }
        .ebtn { display:inline-flex; align-items:center; gap:6px; font-size:13.5px; font-weight:700; padding:7px 12px; border-radius:3px; border:1.5px solid var(--ink); background:#fff; color:var(--ink); white-space:nowrap; cursor:pointer; }
        .ebtn:hover { background:var(--ink); color:#fff; }
        .ebtn.ebtn-danger { color:#D81A48; border-color:#D81A48; }
        .ebtn.ebtn-danger:hover { background:#D81A48; color:#fff; }
        .kv { padding:14px 0 18px; }
        .kv.two { display:grid; grid-template-columns:1fr 1fr; gap:14px 24px; }
        .kv span { display:block; font-size:13px; color:var(--ink-3); font-weight:600; }
        .kv b, .kv a { display:block; font-size:15px; font-weight:600; color:var(--ink); margin-top:3px; word-break:break-word; }
        .kv a { color:var(--primary-press); text-decoration:underline; text-underline-offset:3px; }
        .kv .row { display:grid; grid-template-columns:150px minmax(0, 1fr); gap:12px; padding:10px 0; border-bottom:1px solid var(--line-strong); align-items:baseline; }
        .kv .row:last-child { border-bottom:none; }
        .kv .row span { font-size:14px; color:var(--ink-2); font-weight:500; }
        .kv .row b { margin-top:0; }
        .kv b.ok { color:#0B7355; } .kv b.warn { color:#7A4E00; }
        .mono { font-family:var(--mono); }
        .people { padding:0 0 14px; }
        .person { display:flex; align-items:center; gap:12px; flex-wrap:wrap; padding:11px 0; border-bottom:1px solid var(--line-strong); }
        .person .av { width:34px; height:34px; border-radius:3px; background:var(--ink); color:#fff; display:grid; place-items:center; font-family:var(--font-display); font-size:14px; font-weight:700; flex-shrink:0; }
        .person .av.muted { background:#fff; color:var(--ink-3); border:1.5px dashed var(--line-strong); }
        .person b { display:block; font-size:14.5px; color:var(--ink); } .person small { display:block; font-size:13px; color:var(--ink-3); }
        .grow { flex:1; min-width:0; }
        .dot-st { display:inline-flex; align-items:center; font-family:var(--font-display); font-size:13px; font-weight:600; text-transform:uppercase; padding:2px 7px; border-radius:2px; background:var(--bg-2); color:var(--ink-2); }
        .dot-st.on { background:var(--good-soft); color:#0B7355; }
        .dot-st.off { background:#FFEEF3; color:#D81A48; }
        .dot-st.wait { background:#FFF8DD; color:#7A4E00; }
        .add-line { margin-top:12px; font-size:14px; font-weight:700; color:var(--primary-press); text-decoration:underline; text-underline-offset:3px; cursor:pointer; }
        .add-line:hover { color:var(--ink); }
        .empty-line { font-size:14px; color:var(--ink-3); padding:12px 0; }
        .form-end { display:flex; justify-content:flex-end; padding:16px 0 4px; border-top:1px solid var(--line-strong); }
        .brand-panel { display:flex; align-items:center; gap:14px; flex-wrap:wrap; padding:16px 0; border-bottom:1px solid var(--line-strong); }
        .bp-name { font-family:var(--font-display); font-size:24px; font-weight:700; line-height:1; text-transform:uppercase; color:var(--ink); }
        .bp-sub { font-size:14px; color:var(--ink-2); margin-top:4px; }
        .offer-line { display:flex; align-items:center; gap:16px; flex-wrap:wrap; padding:14px 0; border-bottom:1px solid var(--line-strong); }
        .o-big { font-family:var(--font-display); font-size:24px; font-weight:700; line-height:1; color:var(--ink); min-width:110px; }
        .offer-line b { display:block; font-size:15px; color:var(--ink); } .offer-line small { font-size:13px; color:var(--ink-3); }
        .o-st { font-family:var(--font-display); font-size:13px; font-weight:600; text-transform:uppercase; padding:2px 7px; border-radius:2px; background:var(--bg-2); color:var(--ink-2); border:1px solid var(--line-strong); }
        .o-st.live { background:var(--good-soft); color:#0B7355; border-color:transparent; }
        .dl-users { max-width:none; margin-top:0; }

        .pf-side { display:grid; gap:26px; position:sticky; top:84px; }
        .side-card { background:transparent; border:none; border-top:2px solid var(--ink); border-radius:0; box-shadow:none; padding:12px 0 4px; min-width:0; }
        .side-t { font-family:var(--font-display); font-size:20px; font-weight:700; text-transform:uppercase; line-height:1.1; color:var(--ink); margin-bottom:12px; }
        .side-note { font-size:13px; color:var(--ink-3); margin-top:8px; }
        .status-pick { position:relative; }
        .sp-current { display:flex; align-items:center; gap:9px; width:100%; padding:10px 12px; border-radius:3px; border:1.5px solid var(--line-strong); font-size:15px; font-weight:700; color:var(--ink); background:#fff; text-align:left; cursor:pointer; }
        button.sp-current:hover, button.sp-current:focus-visible { border-color:var(--ink); outline:none; }
        .sp-current.static { cursor:default; }
        .sp-chev { margin-left:auto; color:var(--ink-3); }
        .sp-menu { position:absolute; left:0; right:0; top:calc(100% + 4px); background:#fff; border:1px solid var(--line-strong); border-radius:4px; box-shadow:var(--shadow-md); padding:4px; z-index:20; }
        .sp-menu button { display:flex; align-items:flex-start; gap:10px; width:100%; padding:9px 10px; border-radius:2px; text-align:left; cursor:pointer; }
        .sp-menu button + button { border-top:1px solid var(--line); }
        .sp-menu button:hover { background:var(--bg-2); }
        .sp-menu .st-dot { margin-top:5px; }
        .sp-menu b { display:block; font-size:14px; color:var(--ink); } .sp-menu small { font-size:13px; color:var(--ink-3); }
        .map { width:100%; height:170px; border:1px solid var(--line-strong); border-radius:3px; display:block; }
        .map.empty { display:flex; flex-direction:column; align-items:center; justify-content:center; gap:8px; text-align:center; background:transparent; border:1.5px dashed var(--line-strong); color:var(--ink-3); font-size:13px; padding:16px; }
        .side-link { display:inline-flex; align-items:center; gap:5px; margin-top:8px; font-size:13.5px; font-weight:700; color:var(--primary-press); text-decoration:underline; text-underline-offset:3px; }
        .ring-row { position:relative; display:flex; align-items:baseline; gap:14px; margin-bottom:12px; padding-bottom:16px; }
        .ring-row::before { content:""; position:absolute; left:0; right:0; bottom:0; height:4px; background:var(--line-strong); }
        .ring { --p:0; flex-shrink:0; }
        .ring::after { content:""; position:absolute; left:0; bottom:0; height:4px; width:calc(var(--p) * 1%); background:var(--good); }
        .ring b { display:block; font-family:var(--font-display); font-size:34px; font-weight:700; line-height:1; color:var(--ink); }
        .ring-row > span:last-child b { display:block; font-size:14px; color:var(--ink); } .ring-row small { font-size:13px; color:var(--ink-3); }
        .step { display:flex; align-items:center; gap:10px; width:100%; padding:8px 0; font-size:14px; color:var(--ink-2); text-align:left; border-bottom:1px solid var(--line); cursor:pointer; }
        .step:last-child { border-bottom:none; }
        .step:hover { color:var(--ink); }
        .step svg { color:var(--ink-3); }
        .step .tick { width:18px; height:18px; border-radius:2px; border:1.5px solid var(--line-strong); background:#fff; display:grid; place-items:center; flex-shrink:0; }
        .step.done .tick { background:var(--good); border-color:var(--good); color:#fff; }
        .step.done .tick svg { color:#fff; }
        .qa { display:flex; align-items:center; justify-content:center; gap:8px; width:100%; padding:10px 11px; margin-top:8px; border-radius:3px; font-size:14px; font-weight:700; border:1.5px solid var(--ink); background:#fff; color:var(--ink); cursor:pointer; }
        .qa:first-of-type { margin-top:0; }
        .qa:hover { background:var(--ink); color:#fff; }
        .qa.primary { background:var(--primary); border-color:var(--primary); color:#fff; font-family:var(--font-display); font-size:15px; font-weight:600; text-transform:uppercase; letter-spacing:.02em; }
        .qa.primary:hover { background:var(--primary-press); border-color:var(--primary-press); }
        .err { font-size:13px; color:#D81A48; font-weight:600; }

        @media (max-width:1200px) { .pf { grid-template-columns:minmax(0, 1fr); } .pf-side { position:static; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); } }
        @media (max-width:900px) {
            .ov { grid-template-columns:minmax(0, 1fr); }
            .hero.has-photo .hero-photo { position:relative; width:auto; height:180px; }
            .hero.has-photo .hero-body { padding-right:0; min-height:0; }
            .hero-body { flex-wrap:wrap; }
            .hero-title h1 { font-size:32px; }
            .kv.two { grid-template-columns:1fr; }
        }

        @media (max-width:760px){
            .fields { grid-template-columns:1fr; }
            .field, .field.w2, .field.w4 { grid-column:1 / -1; }
            .status-toggle { flex-direction:column; }
            .status-opt + .status-opt { border-left:none; border-top:1.5px solid var(--ink); }
            .dl-head h1 { font-size:30px; }
            .kv .row { grid-template-columns:1fr; gap:2px; }
            .impact-row { grid-template-columns:1fr auto 1fr; }
            .impact-row .k { grid-column:1 / -1; }
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
            <div class="hero-body">
                <div class="hero-brand">
                    <span class="mark">{{ $settings ? $settings['brand']->initials() : '—' }}</span>
                    <span class="mark-name">{{ $settings ? $settings['brand']->name : 'No brand' }}</span>
                </div>
                <div class="hero-main">
                    <div class="hero-title">
                        <h1>{{ $displayName }}</h1>
                        <span class="st st-{{ $dealer->status }}">{{ \App\Models\Dealer::STATUS_LABELS[$dealer->status] ?? $dealer->status }}</span>
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
                        <span class="sv"><small>Live vehicles</small><b>{{ number_format($stats['live']) }}</b></span>
                        <span class="go">{!! $icon($paths['chev'], 16) !!}</span>
                    </a>
                    <button type="button" class="stat" wire:click="$set('tab', 'team')">
                        <span class="sv"><small>Dealer users{{ $stats['invited'] ? ' · ' . $stats['invited'] . ' invited' : '' }}</small><b>{{ $stats['users'] }}</b></span>
                        <span class="go">{!! $icon($paths['chev'], 16) !!}</span>
                    </button>
                    <div class="stat">
                        <span class="sv"><small>Reservations (30 days)</small><b>{{ $stats['reservations'] }}</b></span>
                        @if ($stats['delta']) <span class="delta {{ $stats['deltaUp'] ? 'up' : 'down' }}">{{ $stats['deltaUp'] ? '↑' : '↓' }} {{ ltrim($stats['delta'], '+-') }}</span> @endif
                    </div>
                    @can('customers.view')
                        <a class="stat" href="{{ route('admin.dealers.customers', $dealer) }}" wire:navigate>
                            <span class="sv"><small>Customers</small><b>{{ $stats['customers'] }}</b></span>
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
                            <div><div class="t">Dealer information</div><div class="s">What shoppers see, and how the dealer is registered.</div></div>
                        </div>
                        <fieldset class="fields" @disabled(! $canManage) style="border:none; margin:0;">@include('partials.dealer.info-fields')</fieldset>
                        @if ($canManage) <div class="form-end"><button type="submit" class="btn btn-primary">Save changes</button></div> @endif
                    </form>

                @elseif ($tab === 'location')
                    <form wire:submit="save" class="pcard">
                        <div class="pcard-head">
                            <div><div class="t">Location</div><div class="s">Where this dealer’s cars physically are. Required before any vehicle can be published.</div></div>
                        </div>
                        <fieldset class="fields" @disabled(! $canManage) style="border:none; margin:0;">@include('partials.dealer.location-fields')</fieldset>
                        @if ($canManage) <div class="form-end"><button type="submit" class="btn btn-primary">Save changes</button></div> @endif
                    </form>

                    <div class="pcard" style="margin-top:30px">
                        <div class="pcard-head">
                            <div><div class="t">Rooftops</div><div class="s">Each physical store. Every car sits at one; the dealer stays the seller.</div></div>
                            @if ($canManage && ! $rooftopFormOpen) <button type="button" class="ebtn" wire:click="startRooftop">+ Add rooftop</button> @endif
                        </div>
                        <div class="people">
                            @foreach ($this->rooftops() as $rooftop)
                                <div class="person">
                                    <span class="grow">
                                        <b>{{ $rooftop->display_name }} @if ($rooftop->is_primary) · <span style="color:var(--ink-3)">Main store</span> @endif</b>
                                        <small>{{ implode(' · ', $rooftop->address_lines) ?: 'No address yet' }} · {{ $rooftop->vehicles_count }} {{ $rooftop->vehicles_count === 1 ? 'car' : 'cars' }} · feed {{ $rooftop->feed_rooftop_id ?? 'not linked' }}</small>
                                        <small>{{ $rooftop->hasHours() ? 'Hours set' : 'No hours set' }}{{ count($rooftop->pickupPoints()) ? ' · ' . count($rooftop->pickupPoints()) . ' extra pickup ' . (count($rooftop->pickupPoints()) === 1 ? 'point' : 'points') : '' }}</small>
                                    </span>
                                    @if ($canManage) <button type="button" class="ebtn" wire:click="startHours({{ $rooftop->id }})">Hours &amp; pickup</button> @endif
                                    @if ($rooftop->is_primary)
                                        <small style="color:var(--ink-3)">Address edited above</small>
                                    @else
                                        <span class="dot-st {{ $rooftop->is_active ? 'on' : 'off' }}">{{ $rooftop->is_active ? 'Open' : 'Closed' }}</span>
                                        @if ($canManage)
                                            <button type="button" class="ebtn" wire:click="startRooftop({{ $rooftop->id }})">Edit</button>
                                            <button type="button" class="ebtn {{ $rooftop->is_active ? 'ebtn-danger' : '' }}" wire:click="toggleRooftop({{ $rooftop->id }})" wire:confirm="{{ $rooftop->is_active ? 'Close this store? Its cars come off the marketplace.' : 'Reopen this store?' }}">{{ $rooftop->is_active ? 'Close' : 'Reopen' }}</button>
                                        @endif
                                    @endif
                                </div>
                                @if ($hoursRooftopId === $rooftop->id)
                                    <form wire:submit="saveHours" class="rt-hours" wire:key="hours-{{ $rooftop->id }}">
                                        <label class="u-check"><input type="checkbox" wire:model.live="hoursKnown"> Opening hours are known</label>
                                        @if ($hoursKnown)
                                            @foreach (\App\Models\Rooftop::WEEKDAYS as $day => $dayLabel)
                                                <div class="rt-day">
                                                    <label class="u-check"><input type="checkbox" wire:model.live="hoursForm.{{ $day }}.open"> {{ $dayLabel }}</label>
                                                    @if (! empty($hoursForm[$day]['open']))
                                                        <input type="time" wire:model="hoursForm.{{ $day }}.from" aria-label="{{ $dayLabel }} opens">
                                                        <span>to</span>
                                                        <input type="time" wire:model="hoursForm.{{ $day }}.until" aria-label="{{ $dayLabel }} closes">
                                                    @else
                                                        <span style="color:var(--ink-3)">Closed</span>
                                                    @endif
                                                </div>
                                                @error("hoursForm.{$day}.from") <span class="err">{{ $message }}</span> @enderror
                                                @error("hoursForm.{$day}.until") <span class="err">{{ $message }}</span> @enderror
                                            @endforeach
                                            <span class="hint">Buyers can only pick handover windows inside these hours.</span>
                                        @else
                                            <span class="hint">Without hours, buyers can pick any window from 10am to 6pm.</span>
                                        @endif

                                        <div class="sb-label">Other pickup points <span class="opt">(optional)</span></div>
                                        @foreach (range(0, 2) as $i)
                                            <div class="rt-day">
                                                <input type="text" wire:model="pickupForm.{{ $i }}.name" placeholder="Name" maxlength="80" aria-label="Pickup point {{ $i + 1 }} name">
                                                <input type="text" wire:model="pickupForm.{{ $i }}.address" placeholder="Address" maxlength="160" aria-label="Pickup point {{ $i + 1 }} address" style="flex:2">
                                            </div>
                                            @error("pickupForm.{$i}.name") <span class="err">{{ $message }}</span> @enderror
                                            @error("pickupForm.{$i}.address") <span class="err">{{ $message }}</span> @enderror
                                        @endforeach
                                        <span class="hint">Offered at checkout after the store itself.</span>

                                        <div class="u-acts" style="justify-content:flex-end">
                                            <button type="button" class="btn btn-ghost btn-sm" wire:click="cancelHours">Cancel</button>
                                            <button type="submit" class="btn btn-primary btn-sm">Save</button>
                                        </div>
                                    </form>
                                @endif
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
                        <div class="pcard"><div class="empty-line">You can’t manage this dealer’s users.</div></div>
                    @endif

                @elseif ($tab === 'cpo')
                    <div class="pcard">
                        <div class="pcard-head">
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
                            <div class="empty-line">No current or upcoming offers.</div>
                        @endforelse
                    </div>

                @elseif ($tab === 'integrations')
                    @php $import = $this->lastImport(); @endphp
                    <div class="pcard">
                        <div class="pcard-head">
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
                    <a class="qa" href="{{ route('admin.dealers.protection', $dealer) }}" wire:navigate>{!! $icon($paths['shield'], 16) !!} Protection &amp; add-ons</a>
                    @if ($this->canManageUsers())
                        <button type="button" class="qa" wire:click="$set('tab', 'team')">{!! $icon($paths['send'], 16) !!} Send login invite</button>
                    @endif
                </div>
            </aside>
        </div>

        @include('partials.dealer.brand-dialog')
    @endif
</div>
