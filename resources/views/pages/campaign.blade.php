<?php

use App\Models\Campaign;
use App\Models\Dealer;
use App\Models\Scopes\DealerScope;
use App\Models\Vehicle;
use App\Support\VehicleCertification;
use App\Support\CampaignAttribution;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
| A dealer's campaign storefront — where a /shop/{token} link lands.
|
| Fenced on the server to the campaign dealer's eligible manufacturer-CPO stock
| (Vehicle::eligibleForDealerCpo). No other dealer's cars, recommendations or
| ads are rendered here, and nothing on the page links to the open floor.
*/
new class extends Component {
    #[Locked]
    public int $campaignId;

    /** Set when the campaign's own car (or a car the shopper followed) is gone. */
    #[Locked]
    public ?string $unavailableVehicleLabel = null;

    public string $search = '';

    public string $sort = 'newest';

    public function mount(string $token): void
    {
        $campaign = Campaign::findActiveByToken($token);

        abort_if($campaign === null, 404);

        CampaignAttribution::capture($campaign, request());

        $this->campaignId = $campaign->id;
        $this->unavailableVehicleLabel = session('campaign_unavailable_vehicle');

        if ($campaign->targetsVehicle()) {
            $target = $campaign->vehicle;

            if ($target !== null && $target->isEligibleForDealerCpo($campaign->dealer, $campaign->rooftop_id)) {
                $this->redirectRoute('campaign.vehicle', ['token' => $token, 'vehicle' => $target->id]);

                return;
            }

            // Sold, held, unpublished or removed — say so plainly, then offer the
            // same dealer's other eligible cars. Never another dealer's.
            $this->unavailableVehicleLabel ??= $target !== null
                ? "{$target->model_year} {$target->make} {$target->model}"
                : 'The vehicle in this ad';
        }

        View::share('referredCampaign', $campaign);
    }

    #[Computed]
    public function campaign(): Campaign
    {
        return Campaign::withoutGlobalScope(DealerScope::class)->with('dealer')->findOrFail($this->campaignId);
    }

    #[Computed]
    public function dealer(): Dealer
    {
        return $this->campaign->dealer;
    }

    /**
     * @return Collection<int, Vehicle>
     */
    #[Computed]
    public function vehicles(): Collection
    {
        return Vehicle::query()
            ->with(VehicleCertification::EAGER)
            ->eligibleForDealerCpo($this->dealer, $this->campaign->rooftop_id)
            ->when(trim($this->search) !== '', function (Builder $query) {
                $keyword = '%' . trim($this->search) . '%';
                $query->where(function (Builder $keywordQuery) use ($keyword) {
                    $keywordQuery->where('model', 'like', $keyword)
                        ->orWhere('trim', 'like', $keyword)
                        ->orWhere('body_type', 'like', $keyword);
                });
            })
            ->when($this->sort === 'price-low', fn (Builder $query) => $query->orderBy('price_in_cents'))
            ->when($this->sort === 'price-high', fn (Builder $query) => $query->orderByDesc('price_in_cents'))
            ->when($this->sort === 'km-low', fn (Builder $query) => $query->orderBy('kilometres'))
            ->when($this->sort === 'newest', fn (Builder $query) => $query->latest())
            ->get();
    }

    #[Computed]
    public function totalCount(): int
    {
        return Vehicle::query()->eligibleForDealerCpo($this->dealer, $this->campaign->rooftop_id)->count();
    }

    /**
     * Same contract as the marketplace heart, limited to this storefront's cars.
     */
    public function toggleFavourite(int $vehicleId): void
    {
        $user = auth()->user();

        if ($user !== null && $user->dealer_id !== null) {
            return;
        }

        if ($user === null) {
            session()->put('url.intended', $this->campaign->shopUrl());
            $this->redirect(route('buyer.login'));

            return;
        }

        $vehicleIsHere = Vehicle::query()->eligibleForDealerCpo($this->dealer, $this->campaign->rooftop_id)->whereKey($vehicleId)->exists();

        if (! $vehicleIsHere) {
            return;
        }

        $user->favouriteVehicles()->toggle($vehicleId);

        $this->dispatch('favourites-updated', count: $user->favouriteVehicles()->count());
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $user = auth()->user();
        $isBuyerOrGuest = $user === null || $user->dealer_id === null;

        return [
            'savedVehicleIds' => $user !== null && $isBuyerOrGuest
                ? $user->favouriteVehicles()->pluck('vehicles.id')->all()
                : [],
            'favouritesAreVisible' => $isBuyerOrGuest,
        ];
    }
};
?>

<div>
    <header class="cs-head">
        <div class="cs-eyebrow">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 3 4 6v6c0 5 8 9 8 9s8-4 8-9V6ZM9 12l2 2 4-4"/></svg>
            {{ $this->dealer->franchise_make }} Certified Pre-Owned
        </div>
        <h1 class="cs-title">{{ ucwords(strtolower($this->dealer->name)) }}</h1>
        <p class="cs-sub">
            {{ $this->totalCount }} manufacturer-certified {{ $this->totalCount === 1 ? 'vehicle' : 'vehicles' }}
            @if ($this->dealer->city) · {{ $this->dealer->city }} @endif
            · Sold by {{ ucwords(strtolower($this->dealer->name)) }}, reserved online through TruCars
        </p>
    </header>

    @if ($unavailableVehicleLabel)
        <div class="cs-unavailable" role="status">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg>
            <div>
                <strong>{{ $unavailableVehicleLabel }} is no longer available.</strong>
                <span>It may have just been sold or reserved. Here's what else {{ ucwords(strtolower($this->dealer->name)) }} has that's certified and ready.</span>
            </div>
        </div>
    @endif

    @if ($this->totalCount > 0)
        <div class="cs-tools">
            <label class="cs-search">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search {{ $this->dealer->franchise_make }} models">
            </label>
            <select wire:model.live="sort" class="cs-sort" aria-label="Sort">
                <option value="newest">Newest listings</option>
                <option value="price-low">Price: low to high</option>
                <option value="price-high">Price: high to low</option>
                <option value="km-low">Lowest kilometres</option>
            </select>
        </div>
    @endif

    @if ($this->vehicles->isEmpty())
        <div class="cs-empty">
            @if ($this->totalCount === 0)
                <div class="cs-empty-title">No certified vehicles right now</div>
                <p>{{ ucwords(strtolower($this->dealer->name)) }} doesn't have manufacturer-certified stock listed at the moment. Check back soon.</p>
            @else
                <div class="cs-empty-title">No matches</div>
                <p>Nothing matches “{{ $search }}”. Try another model.</p>
            @endif
        </div>
    @else
        <div class="grid">
            @foreach ($this->vehicles as $vehicle)
                @include('partials.vehicle-card', [
                    'vehicle' => $vehicle,
                    'vehicleUrl' => route('campaign.vehicle', ['token' => $this->campaign->token, 'vehicle' => $vehicle->id]),
                    'badgeLabel' => $vehicle->certification()?->badge ?? 'Manufacturer CPO',
                    'savedVehicleIds' => $savedVehicleIds,
                    'favouritesAreVisible' => $favouritesAreVisible,
                ])
            @endforeach
        </div>
    @endif

    <style>
        [x-cloak] { display:none !important; }

        .cs-head { margin-bottom:22px; }
        .cs-eyebrow { display:inline-flex; align-items:center; gap:7px; font-size:12.5px; font-weight:700; color:var(--primary); background:var(--primary-soft); border-radius:var(--radius-pill); padding:5px 12px; margin-bottom:12px; }
        .cs-title { font-size:30px; font-weight:800; letter-spacing:0; margin:0; }
        .cs-sub { color:var(--ink-2); font-size:15px; margin:6px 0 0; }

        .cs-unavailable { display:flex; gap:12px; align-items:flex-start; border:1px solid var(--primary-line); background:var(--primary-soft); border-radius:var(--radius-sm); padding:14px 16px; margin-bottom:22px; font-size:14px; }
        .cs-unavailable svg { color:var(--primary); flex-shrink:0; margin-top:1px; }
        .cs-unavailable strong { display:block; }
        .cs-unavailable span { color:var(--ink-2); }

        .cs-tools { display:flex; gap:12px; margin-bottom:22px; }
        .cs-search { flex:1; max-width:420px; display:flex; align-items:center; gap:9px; border:1px solid var(--line-strong); border-radius:var(--radius-pill); padding:0 16px; color:var(--ink-3); background:var(--card); }
        .cs-search:focus-within { border-color:var(--primary); }
        .cs-search input { flex:1; border:none; outline:none; font-size:14.5px; padding:11px 0; color:var(--ink); background:none; min-width:0; }
        .cs-sort { border:1px solid var(--line-strong); border-radius:var(--radius-pill); padding:0 14px; font-size:14px; font-family:inherit; color:var(--ink); background:var(--card); }

        .cs-empty { max-width:440px; margin:40px auto 20px; text-align:center; padding:20px; color:var(--ink-2); font-size:14.5px; }
        .cs-empty-title { font-size:20px; font-weight:800; letter-spacing:0; color:var(--ink); margin-bottom:8px; }

        /* Same floor grid and card styles as the marketplace and Saved cars. */
        .grid { display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:34px 22px; }
        @media (max-width:1350px) { .grid { grid-template-columns:repeat(3, minmax(0, 1fr)); } }
        @media (max-width:1000px) { .grid { grid-template-columns:repeat(2, minmax(0, 1fr)); } }


        @media (max-width:860px) {
            .cs-title { font-size:25px; }
            .cs-sub { font-size:14px; }
            .cs-tools { flex-direction:column; }
            .cs-search { max-width:none; }
            .cs-sort { padding:11px 14px; }
            .grid { grid-template-columns:minmax(0, 1fr); gap:30px; }
        }
    </style>
</div>
