<?php

use App\Models\Campaign;
use App\Models\Dealer;
use App\Models\Vehicle;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
| Dealer campaigns — trackable TruCars links a dealer places in their own ads
| and posts. No ad platform is touched from here: nothing is published, bought
| or synced. The dealer copies the link; TruCars resolves it and tracks results.
|
| What a dealer can't set is decided for them: the dealership and franchise
| brand come from the signed-in account, eligible stock from
| Vehicle::eligibleForDealerCpo, and the no-competitor rules are fixed.
*/
new #[Layout('layouts.dealer')] #[Title('Campaigns')] class extends Component {
    public bool $showEditor = false;

    #[Locked]
    public ?int $editingCampaignId = null;

    public string $campaignName = '';
    public string $campaignSource = 'facebook_instagram';
    public string $destinationType = Campaign::DESTINATION_CPO_INVENTORY;
    public $targetVehicleId = '';
    /** '' = every store of the dealer; otherwise one rooftop id. */
    public string $campaignStoreId = '';

    public string $statusMessage = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->portalDealer() !== null, 403, 'This area is for dealer accounts.');
    }

    /** The signed-in dealership. Guaranteed non-null past the mount guard. */
    protected function dealer(): Dealer
    {
        return auth()->user()->portalDealer();
    }

    #[Computed]
    public function franchiseMake(): ?string
    {
        return $this->dealer()->franchise_make;
    }

    #[Computed]
    public function campaigns(): Collection
    {
        return $this->dealer()->campaigns()
            ->with(['vehicle', 'rooftop.dealer'])
            ->withCount(['deals', 'leads'])
            ->latest()
            ->get();
    }

    /**
     * Stores a campaign can be limited to: this dealer's open stores the
     * signed-in staff member works with.
     */
    #[Computed]
    public function storeOptions(): Collection
    {
        return $this->dealer()->rooftops()
            ->where('is_active', true)
            ->whereKey(auth()->user()->accessibleRooftopIds())
            ->get();
    }

    /** Whether "every store" is on offer — only to staff who see them all. */
    #[Computed]
    public function canTargetAllStores(): bool
    {
        return auth()->user()->seesAllStoresOf($this->dealer());
    }

    private function chosenStoreId(): ?int
    {
        return $this->campaignStoreId === '' ? null : (int) $this->campaignStoreId;
    }

    /** This dealer's cars a campaign may point at — and the only ones it can. */
    #[Computed]
    public function eligibleVehicles(): Collection
    {
        return Vehicle::query()
            ->eligibleForDealerCpo($this->dealer(), $this->chosenStoreId())
            ->orderBy('model')
            ->orderByDesc('model_year')
            ->get();
    }

    #[Computed]
    public function editingCampaign(): ?Campaign
    {
        return $this->editingCampaignId === null
            ? null
            : $this->dealer()->campaigns()->whereKey($this->editingCampaignId)->first();
    }

    /** What the shopper will land on, as currently configured in the editor. */
    #[Computed]
    public function previewVehicles(): Collection
    {
        if ($this->destinationType === Campaign::DESTINATION_VEHICLE) {
            return $this->eligibleVehicles->where('id', (int) $this->targetVehicleId)->values();
        }

        return $this->eligibleVehicles->take(4);
    }

    public function startCreate(): void
    {
        $this->resetEditor();
        $this->campaignStoreId = $this->canTargetAllStores ? '' : (string) ($this->storeOptions->first()?->id ?? '');
        $this->showEditor = true;
    }

    /** A campaign this staff member may change: store-limited staff can't touch whole-dealer ones. */
    private function ownCampaign(int $campaignId): Campaign
    {
        $campaign = $this->dealer()->campaigns()->whereKey($campaignId)->firstOrFail();
        abort_if($campaign->rooftop_id === null && ! $this->canTargetAllStores, 403);

        return $campaign;
    }

    public function updatedCampaignStoreId(): void
    {
        unset($this->eligibleVehicles, $this->previewVehicles);
    }

    public function startEdit(int $campaignId): void
    {
        $campaign = $this->ownCampaign($campaignId);

        $this->resetEditor();
        $this->editingCampaignId = $campaign->id;
        $this->campaignName = $campaign->name;
        $this->campaignSource = $campaign->source;
        $this->destinationType = $campaign->destination_type;
        $this->targetVehicleId = (string) ($campaign->vehicle_id ?? '');
        $this->campaignStoreId = (string) ($campaign->rooftop_id ?? '');
        $this->showEditor = true;
    }

    /**
     * Save the editor. $intent is 'draft' (link stays dark) or 'activate' (link
     * goes live, after the activation checks pass).
     */
    public function saveCampaign(string $intent = 'draft'): void
    {
        $validated = $this->validate([
            'campaignName'    => ['required', 'string', 'max:80'],
            'campaignSource'  => ['required', Rule::in(array_keys(Campaign::SOURCES))],
            'destinationType' => ['required', Rule::in(array_keys(Campaign::DESTINATION_LABELS))],
            'targetVehicleId' => [Rule::requiredIf($this->destinationType === Campaign::DESTINATION_VEHICLE), 'nullable', 'integer'],
        ], [
            'targetVehicleId.required' => 'Choose the vehicle this campaign features.',
        ], [
            'campaignName'    => 'campaign name',
            'campaignSource'  => 'source',
            'destinationType' => 'destination',
        ]);

        $storeId = $this->chosenStoreId();

        if ($storeId === null ? ! $this->canTargetAllStores : ! $this->storeOptions->contains('id', $storeId)) {
            $this->addError('campaignStoreId', 'Choose one of your stores.');

            return;
        }

        $vehicleId = null;

        if ($validated['destinationType'] === Campaign::DESTINATION_VEHICLE) {
            // Ownership and eligibility in one: an id from another dealer, or an
            // unpublished, held or non-CPO car, simply isn't found.
            $vehicle = Vehicle::query()
                ->eligibleForDealerCpo($this->dealer(), $storeId)
                ->whereKey((int) $validated['targetVehicleId'])
                ->first();

            if ($vehicle === null) {
                $this->addError('targetVehicleId', 'Choose one of your published, manufacturer-certified vehicles.');

                return;
            }

            $vehicleId = $vehicle->id;
        }

        $campaign = $this->editingCampaignId !== null ? $this->ownCampaign($this->editingCampaignId) : new Campaign(['dealer_id' => $this->dealer()->id]);

        $campaign->fill([
            'name'             => $validated['campaignName'],
            'source'           => $validated['campaignSource'],
            'destination_type' => $validated['destinationType'],
            'vehicle_id'       => $vehicleId,
            'rooftop_id'       => $storeId,
        ]);

        if ($intent === 'activate') {
            $blocker = $this->activationBlocker($campaign);

            if ($blocker !== null) {
                $this->addError('activation', $blocker);

                return;
            }

            $campaign->status = Campaign::STATUS_ACTIVE;
            $campaign->activated_at ??= now();
        } else {
            $campaign->status = Campaign::STATUS_DRAFT;
        }

        $campaign->save();

        $this->editingCampaignId = $campaign->id;
        $this->statusMessage = $campaign->isActive()
            ? "“{$campaign->name}” is live — copy the link below into your ad or post."
            : "Saved “{$campaign->name}” as a draft. The link won't open until you activate it.";

        unset($this->campaigns, $this->editingCampaign);
    }

    public function activateCampaign(int $campaignId): void
    {
        $campaign = $this->ownCampaign($campaignId);
        $blocker = $this->activationBlocker($campaign);

        if ($blocker !== null) {
            $this->statusMessage = '';
            $this->addError('activation', $blocker);

            return;
        }

        $campaign->update([
            'status'       => Campaign::STATUS_ACTIVE,
            'activated_at' => $campaign->activated_at ?? now(),
        ]);

        $this->resetErrorBag('activation');
        $this->statusMessage = "“{$campaign->name}” is live.";
        unset($this->campaigns, $this->editingCampaign);
    }

    public function pauseCampaign(int $campaignId): void
    {
        $campaign = $this->ownCampaign($campaignId);

        $campaign->update(['status' => Campaign::STATUS_PAUSED]);

        $this->resetErrorBag('activation');
        $this->statusMessage = "Paused “{$campaign->name}”. Its link now shows a not-found page until you reactivate it.";
        unset($this->campaigns, $this->editingCampaign);
    }

    /**
     * Why this campaign can't go live, or null if it can. Re-checked on every
     * activation, so a car sold since the draft was saved is caught here.
     */
    protected function activationBlocker(Campaign $campaign): ?string
    {
        if (blank($this->franchiseMake)) {
            return "TruCars hasn't confirmed your franchise brand yet, so none of your stock can be shown as manufacturer certified. Drafts are fine — activation unlocks once it's confirmed.";
        }

        if ($campaign->targetsVehicle()) {
            $vehicleIsEligible = $campaign->vehicle_id !== null && Vehicle::query()
                ->eligibleForDealerCpo($this->dealer(), $campaign->rooftop_id)
                ->whereKey($campaign->vehicle_id)
                ->exists();

            return $vehicleIsEligible
                ? null
                : 'The featured vehicle is no longer published and available as manufacturer CPO. Pick another car or point the campaign at your CPO inventory.';
        }

        $hasCars = Vehicle::query()->eligibleForDealerCpo($this->dealer(), $campaign->rooftop_id)->exists();

        return ! $hasCars
            ? "You don't have any published, available manufacturer-certified vehicles yet, so the link would land on an empty page."
            : null;
    }

    public function closeEditor(): void
    {
        $this->showEditor = false;
        $this->resetEditor();
    }

    protected function resetEditor(): void
    {
        $this->reset(['editingCampaignId', 'campaignName', 'campaignSource', 'destinationType', 'targetVehicleId', 'campaignStoreId']);
        $this->resetValidation();
        unset($this->editingCampaign);
    }
}; ?>

@push('crumb')
    <span>Marketing</span>
    <span>›</span>
    <span class="cur">Campaigns</span>
@endpush

@push('styles')
    <style>
        .cmp-head { display:flex; align-items:flex-end; gap:20px; margin-bottom:22px; }
        .cmp-head h1 { font-size:34px; font-weight:700; text-transform:uppercase; line-height:1; letter-spacing:0; margin:0 0 10px; }
        .cmp-head p { margin:0; font-size:14.5px; color:var(--ink-2); max-width:640px; }
        .cmp-head .spacer { flex:1; }

        .btn { font-size:14px; font-weight:700; border-radius:8px; padding:10px 18px; transition:background .15s ease, color .15s ease; white-space:nowrap; }
        .btn-primary { background:var(--primary); color:#fff; font-family:var(--font-display); font-size:15px; text-transform:uppercase; letter-spacing:.02em; }
        .btn-primary:hover { background:var(--primary-press); }
        .btn-primary:disabled { opacity:.55; }
        .btn-ghost { background:#fff; color:var(--ink); border:1.5px solid var(--ink); padding:8.5px 16.5px; }
        .btn-ghost:hover { background:var(--ink); color:#fff; }
        .btn-sm { font-size:13px; padding:6px 11px; }
        .btn-primary.btn-sm { font-family:var(--font); font-size:13px; text-transform:none; letter-spacing:0; padding:7.5px 12.5px; }
        .btn-ghost.btn-sm { padding:6px 11px; }
        .btn:focus-visible, .chip:focus-visible, .dest-opt:focus-visible { outline:2px solid var(--primary); outline-offset:2px; }

        .cmp-status { display:flex; align-items:center; gap:9px; background:#fff; color:#0B7355; border:1px solid var(--line-strong); border-left:3px solid var(--good); border-radius:8px; padding:11px 15px; font-size:14px; font-weight:600; margin-bottom:20px; }
        .cmp-alert { display:flex; align-items:flex-start; gap:9px; background:#FFF8DD; color:#5C3D00; border:1px solid rgba(197,154,0,.35); border-left:3px solid #C59A00; border-radius:8px; padding:11px 15px; font-size:14px; font-weight:600; margin-bottom:20px; }
        .cmp-alert svg { flex-shrink:0; margin-top:2px; color:#C59A00; }

        .card { background:#fff; border:1px solid var(--line-strong); border-radius:10px; }
        .card-head { padding:14px 20px 12px; border-bottom:2px solid var(--ink); }
        .card-head .ttl { font-family:var(--font-display); font-size:22px; font-weight:700; text-transform:uppercase; line-height:1.1; }
        .card-head .sub { font-size:13.5px; color:var(--ink-2); margin-top:3px; }

        /* list: ruled section, not a box */
        .card.cmp-list { background:none; border:none; border-radius:0; border-top:2px solid var(--ink); }
        .cmp-list .card-head { padding:12px 0; border-bottom:1px solid var(--line-strong); }

        /* editor */
        .cmp-editor { display:grid; grid-template-columns:minmax(0, 1fr) 360px; gap:20px; margin-bottom:30px; align-items:start; }
        .cmp-form { padding:20px; display:flex; flex-direction:column; gap:20px; }
        .field { display:flex; flex-direction:column; gap:7px; }
        .field > label, .field .lbl { font-size:13.5px; font-weight:700; color:var(--ink); }
        .field input, .field select { border:1.5px solid var(--line-strong); border-radius:8px; padding:9px 12px; font-size:15px; color:var(--ink); background:#fff; font-family:inherit; width:100%; }
        .field input:focus, .field select:focus { outline:none; border-color:var(--ink); box-shadow:0 0 0 2px var(--primary-soft); }
        .field .err { font-size:13px; color:#D81A48; font-weight:600; }
        .field .hint { font-size:13px; color:var(--ink-3); }

        .chips { display:flex; flex-wrap:wrap; gap:6px; }
        .chip { background:#fff; border:1.5px solid var(--line-strong); border-radius:8px; padding:7px 12px; font-size:14px; font-weight:600; color:var(--ink-2); transition:all .14s ease; }
        .chip:hover { border-color:var(--ink); color:var(--ink); }
        .chip.on { border-color:var(--ink); background:var(--ink); color:#fff; }

        .dest { display:grid; grid-template-columns:1fr 1fr; border:1.5px solid var(--ink); border-radius:8px; }
        .dest-opt { background:#fff; padding:12px 13px; text-align:left; transition:background .14s ease; }
        .dest-opt + .dest-opt { border-left:1.5px solid var(--ink); }
        .dest-opt .k { font-size:14px; font-weight:700; color:var(--ink); }
        .dest-opt .d { font-size:13px; color:var(--ink-3); margin-top:2px; }
        .dest-opt.on { background:var(--ink); }
        .dest-opt.on .k { color:#fff; }
        .dest-opt.on .d { color:rgba(255,255,255,.7); }

        .locked { border-top:1px solid var(--line-strong); }
        .locked-row { display:flex; align-items:center; gap:10px; padding:9px 0; border-bottom:1px solid var(--line-strong); font-size:14px; }
        .locked-row svg { color:var(--ink-3); flex-shrink:0; }
        .locked-row .v { margin-left:auto; font-family:var(--font-display); font-size:13px; font-weight:700; text-transform:uppercase; letter-spacing:.02em; color:var(--ink-2); background:var(--bg-2); border:1px solid var(--line-strong); border-radius:5px; padding:1px 8px; white-space:nowrap; }

        .linkbox { display:flex; gap:8px; align-items:center; }
        .linkbox code { flex:1; min-width:0; font-family:var(--mono); font-size:13px; background:var(--bg-2); border:1.5px solid var(--line-strong); border-radius:8px; padding:9px 12px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .linkbox .muted { flex:1; font-size:14px; color:var(--ink-3); border:1.5px dashed var(--line-strong); border-radius:8px; padding:9px 12px; }

        .cmp-actions { display:flex; justify-content:flex-end; gap:10px; flex-wrap:wrap; padding-top:18px; border-top:1px solid var(--line-strong); }

        /* preview */
        .pv { padding:18px; }
        .pv-eyebrow { display:inline-flex; font-family:var(--font-display); font-size:13px; font-weight:700; text-transform:uppercase; letter-spacing:.02em; color:#fff; background:var(--ink); border-radius:5px; padding:2px 8px; }
        .pv-dealer { font-family:var(--font-display); font-size:22px; font-weight:700; text-transform:uppercase; line-height:1.05; letter-spacing:0; margin-top:10px; }
        .pv-count { font-size:13px; color:var(--ink-3); margin-top:3px; }
        .pv-list { margin-top:14px; display:flex; flex-direction:column; border-top:2px solid var(--ink); }
        .pv-car { display:flex; gap:11px; align-items:center; border-bottom:1px solid var(--line-strong); padding:9px 0; }
        .pv-car .ph { width:64px; height:46px; border-radius:5px; background:var(--bg-2) center/cover; flex-shrink:0; }
        .pv-car .t { font-size:14px; font-weight:700; line-height:1.3; }
        .pv-car .p { font-size:13px; color:var(--ink-2); }
        .pv-empty { font-size:14px; color:var(--ink-2); margin-top:14px; padding:14px; border:1.5px dashed var(--line-strong); border-radius:8px; }

        /* list rows */
        .cmp-row { display:grid; grid-template-columns:minmax(0, 1.6fr) minmax(0, 1fr) 80px 100px auto; gap:16px; align-items:center; padding:13px 0; border-bottom:1px solid var(--line-strong); }
        .cmp-row .nm { font-size:15px; font-weight:700; }
        .cmp-row .meta { font-size:13px; color:var(--ink-3); margin-top:2px; }
        .cmp-row .num { font-family:var(--font-display); font-size:22px; font-weight:700; line-height:1; color:var(--ink); }
        .cmp-row .num small { display:block; font-family:var(--font); font-size:13px; color:var(--ink-3); font-weight:500; margin-top:3px; }
        .cmp-row .acts { display:flex; gap:6px; justify-content:flex-end; flex-wrap:wrap; }
        .pill { display:inline-block; font-family:var(--font-display); font-size:13px; font-weight:700; letter-spacing:.02em; text-transform:uppercase; padding:2px 8px; border-radius:5px; }
        .pill-active { background:#E5F8EC; color:#0B7355; }
        .pill-draft { background:var(--bg-2); color:var(--ink-2); border:1px solid var(--line-strong); }
        .pill-paused { background:#FFF8DD; color:#7A4E00; }
        .cmp-empty { margin-top:12px; padding:14px 16px; font-size:14px; color:var(--ink-2); border:1.5px dashed var(--line-strong); border-radius:8px; }

        @media (max-width:1100px){
            .cmp-editor { grid-template-columns:1fr; }
        }
        @media (max-width:760px){
            .cmp-head { flex-direction:column; align-items:flex-start; }
            .cmp-head h1 { font-size:30px; }
            .dest { grid-template-columns:1fr; }
            .dest-opt + .dest-opt { border-left:none; border-top:1.5px solid var(--ink); }
            .cmp-row { grid-template-columns:1fr 1fr; }
            .cmp-row > :first-child, .cmp-row .acts { grid-column:1 / -1; }
            .cmp-row .acts { justify-content:flex-start; }
        }
    </style>
@endpush

<div>
    <div class="cmp-head">
        <div>
            <h1>Campaigns</h1>
            <p>
                Create a trackable TruCars link for your own Facebook, Instagram or Google ads, your website, or email.
                Shoppers who follow it see only your published, manufacturer-certified pre-owned vehicles — and every
                reservation they make is credited to the campaign.
            </p>
        </div>
        <div class="spacer"></div>
        @unless ($showEditor)
            <button type="button" class="btn btn-primary" wire:click="startCreate">+ Create campaign</button>
        @endunless
    </div>

    @if ($statusMessage !== '')
        <div class="cmp-status">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
            <span>{{ $statusMessage }}</span>
        </div>
    @endif

    @error('activation')
        <div class="cmp-alert" role="alert">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg>
            <span>{{ $message }}</span>
        </div>
    @enderror

    @if (blank($this->franchiseMake))
        <div class="cmp-alert">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg>
            <span>Your franchise brand hasn't been confirmed by TruCars yet, so no vehicles qualify as manufacturer CPO. You can prepare drafts now; contact TruCars to confirm your franchise and certified-inventory feed.</span>
        </div>
    @endif

    @if ($showEditor)
        <div class="cmp-editor">
            <div class="card">
                <div class="card-head">
                    <div class="ttl">{{ $editingCampaignId === null ? 'Create campaign' : 'Edit campaign' }}</div>
                    <div class="sub">{{ $this->editingCampaign?->status_label ?? 'New' }} · {{ $this->dealer()->name }}</div>
                </div>

                <div class="cmp-form">
                    <div class="field">
                        <label for="campaignName">Campaign name</label>
                        <input id="campaignName" type="text" wire:model="campaignName" placeholder="e.g. Spring CPO event" maxlength="80">
                        @error('campaignName') <span class="err">{{ $message }}</span> @enderror
                    </div>

                    @if ($this->storeOptions->count() > 1 || ! $this->canTargetAllStores)
                        <div class="field">
                            <label for="campaignStoreId">Which store</label>
                            <select id="campaignStoreId" wire:model.live="campaignStoreId">
                                @if ($this->canTargetAllStores) <option value="">All stores</option> @endif
                                @foreach ($this->storeOptions as $store)
                                    <option value="{{ $store->id }}">{{ ucwords(strtolower($store->display_name)) }}{{ $store->display_location ? ' · ' . $store->display_location : '' }}</option>
                                @endforeach
                            </select>
                            <span class="hint">Shoppers from this link only see cars at this store.</span>
                            @error('campaignStoreId') <span class="err">{{ $message }}</span> @enderror
                        </div>
                    @endif

                    <div class="field">
                        <span class="lbl">Where you'll post the link</span>
                        <div class="chips">
                            @foreach (\App\Models\Campaign::SOURCES as $sourceKey => $source)
                                <button type="button" class="chip {{ $campaignSource === $sourceKey ? 'on' : '' }}" wire:click="$set('campaignSource', '{{ $sourceKey }}')">{{ $source['label'] }}</button>
                            @endforeach
                        </div>
                        <span class="hint">Only sets the tracking tags on your link. TruCars doesn't connect to or post on these platforms.</span>
                        @error('campaignSource') <span class="err">{{ $message }}</span> @enderror
                    </div>

                    <div class="field">
                        <span class="lbl">Destination</span>
                        <div class="dest">
                            <button type="button" class="dest-opt {{ $destinationType === 'cpo_inventory' ? 'on' : '' }}" wire:click="$set('destinationType', 'cpo_inventory')">
                                <div class="k">Your CPO inventory</div>
                                <div class="d">All your eligible certified vehicles</div>
                            </button>
                            <button type="button" class="dest-opt {{ $destinationType === 'vehicle' ? 'on' : '' }}" wire:click="$set('destinationType', 'vehicle')">
                                <div class="k">A specific vehicle</div>
                                <div class="d">Falls back to your CPO inventory if it sells</div>
                            </button>
                        </div>
                        @error('destinationType') <span class="err">{{ $message }}</span> @enderror
                    </div>

                    @if ($destinationType === 'vehicle')
                        <div class="field">
                            <label for="targetVehicleId">Vehicle</label>
                            <select id="targetVehicleId" wire:model.live="targetVehicleId">
                                <option value="">Choose a certified vehicle…</option>
                                @foreach ($this->eligibleVehicles as $vehicle)
                                    <option value="{{ $vehicle->id }}">{{ $vehicle->model_year }} {{ $vehicle->make }} {{ $vehicle->model }} {{ $vehicle->trim }} · {{ $vehicle->display_price }}{{ $vehicle->stock_number ? ' · #' . $vehicle->stock_number : '' }}</option>
                                @endforeach
                            </select>
                            @if ($this->eligibleVehicles->isEmpty())
                                <span class="hint">No vehicles qualify yet — only published, available, manufacturer-certified {{ $this->franchiseMake ?? 'franchise-brand' }} units can be featured.</span>
                            @endif
                            @error('targetVehicleId') <span class="err">{{ $message }}</span> @enderror
                        </div>
                    @endif

                    <div class="field">
                        <span class="lbl">TruCars rules for referred shoppers</span>
                        <div class="locked">
                            <div class="locked-row">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
                                Franchise brand
                                <span class="v">{{ $this->franchiseMake ?? 'Not confirmed' }}</span>
                            </div>
                            <div class="locked-row">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
                                Other dealers' vehicles
                                <span class="v">Off</span>
                            </div>
                            <div class="locked-row">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
                                Cross-dealer recommendations
                                <span class="v">Off</span>
                            </div>
                            <div class="locked-row">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
                                Competing dealer ads
                                <span class="v">Off</span>
                            </div>
                        </div>
                        <span class="hint">Set by TruCars on every campaign. You remain the seller of record.</span>
                    </div>

                    <div class="field">
                        <span class="lbl">Trackable link</span>
                        @if ($this->editingCampaign)
                            <div class="linkbox" x-data="{ copied: false }">
                                <code title="{{ $this->editingCampaign->trackableUrl() }}">{{ $this->editingCampaign->trackableUrl() }}</code>
                                <button type="button" class="btn btn-ghost btn-sm"
                                        @click="navigator.clipboard.writeText(@js($this->editingCampaign->trackableUrl())); copied = true; setTimeout(() => copied = false, 1600)"
                                        x-text="copied ? 'Copied' : 'Copy'">Copy</button>
                            </div>
                            @unless ($this->editingCampaign->isActive())
                                <span class="hint">This link won't open for shoppers until the campaign is active.</span>
                            @endunless
                        @else
                            <div class="linkbox"><span class="muted">Your link is generated when you save.</span></div>
                        @endif
                    </div>

                    <div class="cmp-actions">
                        <button type="button" class="btn btn-ghost" wire:click="closeEditor">Close</button>
                        @if ($this->editingCampaign?->isActive())
                            <button type="button" class="btn btn-ghost" wire:click="pauseCampaign({{ $editingCampaignId }})">Pause</button>
                            <button type="button" class="btn btn-primary" wire:click="saveCampaign('activate')" wire:loading.attr="disabled" wire:target="saveCampaign">Save changes</button>
                        @else
                            <button type="button" class="btn btn-ghost" wire:click="saveCampaign('draft')" wire:loading.attr="disabled" wire:target="saveCampaign">Save draft</button>
                            <button type="button" class="btn btn-primary" wire:click="saveCampaign('activate')" wire:loading.attr="disabled" wire:target="saveCampaign">Activate campaign</button>
                        @endif
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-head">
                    <div class="ttl">Preview</div>
                    <div class="sub">What a shopper sees when they follow the link</div>
                </div>
                <div class="pv">
                    <span class="pv-eyebrow">{{ $this->franchiseMake ?? 'Manufacturer' }} Certified Pre-Owned</span>
                    <div class="pv-dealer">{{ ucwords(strtolower($this->dealer()->name)) }}</div>
                    <div class="pv-count">
                        @if ($destinationType === 'vehicle')
                            Opens on the featured vehicle
                        @else
                            {{ $this->eligibleVehicles->count() }} eligible {{ $this->eligibleVehicles->count() === 1 ? 'vehicle' : 'vehicles' }} · your stock only
                        @endif
                    </div>

                    @if ($this->previewVehicles->isEmpty())
                        <div class="pv-empty">
                            {{ $destinationType === 'vehicle' ? 'Choose a vehicle to preview it.' : 'No eligible vehicles to show yet.' }}
                        </div>
                    @else
                        <div class="pv-list">
                            @foreach ($this->previewVehicles as $vehicle)
                                <div class="pv-car" wire:key="pv-{{ $vehicle->id }}">
                                    <span class="ph" @if ($vehicle->primary_photo_url) style="background-image:url('{{ $vehicle->primary_photo_url }}')" @endif></span>
                                    <div>
                                        <div class="t">{{ $vehicle->model_year }} {{ $vehicle->make }} {{ $vehicle->model }}</div>
                                        <div class="p">{{ $vehicle->display_price }} · {{ $vehicle->display_kilometres }}</div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif

    <div class="card cmp-list">
        <div class="card-head">
            <div class="ttl">Your campaigns</div>
            <div class="sub">Visits count once per shopping session. Reservations are deals placed by shoppers who arrived through the link.</div>
        </div>

        @forelse ($this->campaigns as $campaign)
            <div class="cmp-row" wire:key="campaign-{{ $campaign->id }}">
                <div>
                    <div class="nm">{{ $campaign->name }}</div>
                    <div class="meta">
                        {{ $campaign->source_label }} ·
                        @if ($campaign->targetsVehicle())
                            {{ $campaign->vehicle ? "{$campaign->vehicle->model_year} {$campaign->vehicle->make} {$campaign->vehicle->model}" : 'Vehicle removed — shows CPO inventory' }}
                        @else
                            CPO inventory
                        @endif
                        @if ($campaign->rooftop) · {{ ucwords(strtolower($campaign->rooftop->display_name)) }} @endif
                    </div>
                </div>
                <div><span class="pill pill-{{ $campaign->status }}">{{ $campaign->status_label }}</span></div>
                <div class="num">{{ number_format($campaign->visits_count) }}<small>visits</small></div>
                <div class="num">{{ number_format($campaign->deals_count) }}<small>reservations</small></div>
                <div class="acts">
                    <span x-data="{ copied: false }">
                        <button type="button" class="btn btn-ghost btn-sm"
                                @click="navigator.clipboard.writeText(@js($campaign->trackableUrl())); copied = true; setTimeout(() => copied = false, 1600)"
                                x-text="copied ? 'Copied' : 'Copy link'">Copy link</button>
                    </span>
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="startEdit({{ $campaign->id }})">Edit</button>
                    @if ($campaign->isActive())
                        <button type="button" class="btn btn-ghost btn-sm" wire:click="pauseCampaign({{ $campaign->id }})">Pause</button>
                    @else
                        <button type="button" class="btn btn-primary btn-sm" wire:click="activateCampaign({{ $campaign->id }})">Activate</button>
                    @endif
                </div>
            </div>
        @empty
            <div class="cmp-empty">No campaigns yet. Create one to get a link for your next ad or post.</div>
        @endforelse
    </div>
</div>
