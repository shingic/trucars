<?php

use App\Models\Dealer;
use App\Models\Vehicle;
use App\Support\VehicleCertification;
use App\Support\ShopperLocation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component {
    private const COLOUR_FAMILIES = [
        'White'  => ['white', 'pearl', 'ivory'],
        'Black'  => ['black', 'obsidian', 'ebony'],
        'Silver' => ['silver', 'platinum'],
        'Gray'   => ['gray', 'grey', 'graphite', 'magnetic', 'gunmetal'],
        'Blue'   => ['blue', 'navy'],
        'Red'    => ['red', 'crimson', 'burgundy', 'maroon'],
        'Green'  => ['green'],
    ];

    private const COLOUR_SWATCHES = [
        'White' => '#FFFFFF', 'Black' => '#071437', 'Silver' => '#C9CDD2',
        'Gray' => '#6B7280', 'Blue' => '#2563EB', 'Red' => '#DC2626', 'Green' => '#16A34A',
    ];

    // Search, make, body style and max price are in the URL, so the homepage
    // (and anyone sharing a link) can open the shop already filtered.
    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** @var array<int, string> */
    #[Url(as: 'make', except: [])]
    public array $selectedMakes = [];
    /** @var array<int, string> */
    #[Url(as: 'body', except: [])]
    public array $selectedBodyTypes = [];
    /** @var array<int, string> */
    public array $selectedFuels = [];
    /** @var array<int, string> */
    public array $selectedDrivetrains = [];
    /** @var array<int, string> */
    public array $selectedTransmissions = [];
    /** @var array<int, string> */
    public array $selectedColours = [];
    /** @var array<int, int> */
    #[Url(as: 'dealer', except: [])]
    public array $selectedDealers = [];

    public ?int $priceMin = null;
    #[Url(as: 'max')]
    public ?int $priceMax = null;
    public ?int $yearMin = null;
    public ?int $yearMax = null;
    public int $kmMax = 200000;

    public string $sort = 'newest';

    /** The typed fallback when browser location is denied or not wanted. */
    public string $locationInput = '';

    public function clearFilters(): void
    {
        $this->reset([
            'search', 'selectedMakes', 'selectedBodyTypes', 'selectedFuels',
            'selectedDrivetrains', 'selectedTransmissions', 'selectedColours',
            'selectedDealers', 'priceMin', 'priceMax', 'yearMin', 'yearMax', 'kmMax', 'sort',
        ]);
    }

    public function setPriceRange(?int $min, ?int $max): void
    {
        if ($this->priceMin === $min && $this->priceMax === $max) {
            $this->priceMin = null;
            $this->priceMax = null;

            return;
        }

        $this->priceMin = $min;
        $this->priceMax = $max;
    }

    /**
     * @return Collection<int, Vehicle>
     */
    private function distinctWithCounts(string $column): Collection
    {
        return Vehicle::query()
            ->listed()
            ->whereNotNull($column)
            ->selectRaw("{$column} as value, count(*) as total")
            ->groupBy($column)
            ->orderBy($column)
            ->get();
    }

    #[Computed]
    public function availableMakes(): Collection
    {
        return $this->distinctWithCounts('make');
    }

    #[Computed]
    public function availableBodyTypes(): Collection
    {
        return $this->distinctWithCounts('body_type');
    }

    #[Computed]
    public function availableFuels(): Collection
    {
        return $this->distinctWithCounts('fuel_type');
    }

    #[Computed]
    public function availableDrivetrains(): Collection
    {
        return $this->distinctWithCounts('drivetrain');
    }

    #[Computed]
    public function availableTransmissions(): Collection
    {
        return $this->distinctWithCounts('transmission');
    }

    #[Computed]
    public function availableDealers(): Collection
    {
        return Dealer::query()
            ->where('status', Dealer::STATUS_ACTIVE)
            ->whereHas('vehicles', fn (Builder $query) => $query->where('is_published', true))
            ->withCount(['vehicles' => fn (Builder $query) => $query->where('is_published', true)])
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function availableColourFamilies(): array
    {
        $presentFamilies = [];

        $distinctColours = Vehicle::query()
            ->listed()
            ->whereNotNull('colour')
            ->distinct()
            ->pluck('colour');

        foreach ($distinctColours as $colour) {
            $lowerColour = strtolower($colour);

            foreach (self::COLOUR_FAMILIES as $family => $keywords) {
                foreach ($keywords as $keyword) {
                    if (str_contains($lowerColour, $keyword)) {
                        $presentFamilies[$family] = true;
                    }
                }
            }
        }

        return array_values(array_filter(
            array_keys(self::COLOUR_FAMILIES),
            fn (string $family) => isset($presentFamilies[$family]),
        ));
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function colourSwatches(): array
    {
        return self::COLOUR_SWATCHES;
    }

    #[Computed]
    public function totalCount(): int
    {
        return Vehicle::query()->listed()->count();
    }

    /**
     * How many filters the buyer currently has applied — drives the mobile
     * "Filters" button badge so they can see at a glance that the sheet is
     * narrowing results without having to open it.
     */
    #[Computed]
    public function activeFilterCount(): int
    {
        $activeCount = 0;

        $activeCount += count($this->selectedMakes);
        $activeCount += count($this->selectedBodyTypes);
        $activeCount += count($this->selectedFuels);
        $activeCount += count($this->selectedDrivetrains);
        $activeCount += count($this->selectedTransmissions);
        $activeCount += count($this->selectedColours);
        $activeCount += count($this->selectedDealers);

        if ($this->priceMin !== null || $this->priceMax !== null) {
            $activeCount++;
        }

        if ($this->yearMin !== null || $this->yearMax !== null) {
            $activeCount++;
        }

        if ($this->kmMax < 200000) {
            $activeCount++;
        }

        if ($this->search !== '') {
            $activeCount++;
        }

        return $activeCount;
    }

    /**
     * @return Collection<int, Vehicle>
     */
    #[Computed]
    public function publishedVehicles(): Collection
    {
        return Vehicle::query()
            ->with(VehicleCertification::EAGER)
            ->listed()
            ->withReservedFlag()
            ->when($this->search !== '', function (Builder $query) {
                $keyword = '%' . $this->search . '%';
                $query->where(function (Builder $keywordQuery) use ($keyword) {
                    $keywordQuery->where('make', 'like', $keyword)
                        ->orWhere('model', 'like', $keyword)
                        ->orWhere('trim', 'like', $keyword);
                });
            })
            ->when($this->selectedMakes !== [], fn (Builder $query) => $query->whereIn('make', $this->selectedMakes))
            ->when($this->selectedBodyTypes !== [], fn (Builder $query) => $query->whereIn('body_type', $this->selectedBodyTypes))
            ->when($this->selectedFuels !== [], fn (Builder $query) => $query->whereIn('fuel_type', $this->selectedFuels))
            ->when($this->selectedDrivetrains !== [], fn (Builder $query) => $query->whereIn('drivetrain', $this->selectedDrivetrains))
            ->when($this->selectedTransmissions !== [], fn (Builder $query) => $query->whereIn('transmission', $this->selectedTransmissions))
            ->when($this->selectedDealers !== [], fn (Builder $query) => $query->whereIn('dealer_id', $this->selectedDealers))
            ->when($this->selectedColours !== [], function (Builder $query) {
                $query->where(function (Builder $colourQuery) {
                    foreach ($this->selectedColours as $family) {
                        foreach (self::COLOUR_FAMILIES[$family] ?? [] as $keyword) {
                            $colourQuery->orWhere('colour', 'like', '%' . $keyword . '%');
                        }
                    }
                });
            })
            ->when($this->priceMin !== null, fn (Builder $query) => $query->where('price_in_cents', '>=', $this->priceMin * 100))
            ->when($this->priceMax !== null, fn (Builder $query) => $query->where('price_in_cents', '<=', $this->priceMax * 100))
            ->when($this->yearMin !== null, fn (Builder $query) => $query->where('model_year', '>=', $this->yearMin))
            ->when($this->yearMax !== null, fn (Builder $query) => $query->where('model_year', '<=', $this->yearMax))
            ->when($this->kmMax < 200000, fn (Builder $query) => $query->where('kilometres', '<=', $this->kmMax))
            ->when($this->sort === 'price-low', fn (Builder $query) => $query->orderBy('price_in_cents'))
            ->when($this->sort === 'price-high', fn (Builder $query) => $query->orderByDesc('price_in_cents'))
            ->when($this->sort === 'km-low', fn (Builder $query) => $query->orderBy('kilometres'))
            ->when($this->sort === 'year-new', fn (Builder $query) => $query->orderByDesc('model_year'))
            ->when(in_array($this->sort, ['newest', 'nearest'], true), fn (Builder $query) => $query->latest())
            ->get()
            ->when($this->sort === 'nearest' && ShopperLocation::current() !== null, fn (Collection $vehicles) => $this->sortNearestFirst($vehicles));
    }

    /**
     * Nearest first, never nearest only — everything stays in the list. With
     * browser coordinates it's by distance (cars whose dealer has no coordinates
     * yet go last); with a typed city or postal code it's same city, then same
     * province, then the rest. Newest breaks ties, as the query already ordered.
     */
    private function sortNearestFirst(Collection $vehicles): Collection
    {
        $location = ShopperLocation::current();

        return $vehicles
            ->sortBy(fn (Vehicle $vehicle) => [
                ShopperLocation::distanceKm($location, $vehicle->locatedAt()) ?? PHP_INT_MAX,
                ShopperLocation::proximityRank($location, $vehicle->locatedAt()),
            ])
            ->values();
    }

    /* ---------------------------------------------------------------------
       Shopper location (optional — browsing never waits on it)
       --------------------------------------------------------------------- */
    public function useBrowserLocation($latitude, $longitude): void
    {
        $isUsable = is_numeric($latitude) && is_numeric($longitude)
            && abs((float) $latitude) <= 90 && abs((float) $longitude) <= 180;

        if (! $isUsable) {
            $this->addError('locationInput', "We couldn't read that location — enter a city or postal code instead.");

            return;
        }

        ShopperLocation::setFromBrowser((float) $latitude, (float) $longitude);
        $this->locationChanged();
    }

    public function setManualLocation(): void
    {
        $this->validate(['locationInput' => ['required', 'string', 'max:60']], attributes: ['locationInput' => 'city or postal code']);

        if (! ShopperLocation::setManual($this->locationInput)) {
            $this->addError('locationInput', 'Enter a city (e.g. Chilliwack) or a postal code (e.g. V2P 2M9).');

            return;
        }

        $this->locationInput = '';
        $this->locationChanged();
    }

    public function clearLocation(): void
    {
        ShopperLocation::clear();

        if ($this->sort === 'nearest') {
            $this->sort = 'newest';
        }

        $this->dispatch('shopper-location-updated', label: null);
    }

    private function locationChanged(): void
    {
        $this->resetErrorBag('locationInput');
        $this->sort = 'nearest';
        $this->dispatch('shopper-location-updated', label: ShopperLocation::current()['label'] ?? null);
    }

    /**
     * Save or unsave a car from the buyer's favourites.
     *
     * A guest who taps the heart is sent to sign in rather than failing
     * silently — saving needs an account. Dealer staff never keep favourites,
     * so their tap is a no-op (the heart is hidden from them in the template
     * anyway; this is the matching server-side guard). For a signed-in buyer
     * the pivot is toggled: present rows detach, absent rows attach, in one
     * atomic call, so a double-tap lands back where it started.
     *
     * This is a plain action, not a computed — the saved set is recomputed
     * fresh on the re-render that follows, which keeps the heart from rendering
     * one click behind the way memoised reads have bitten us before.
     */
    public function toggleFavourite(int $vehicleId): void
    {
        $user = auth()->user();

        if ($user !== null && $user->dealer_id !== null) {
            return;
        }

        if ($user === null) {
            $this->redirect(route('buyer.login'));

            return;
        }

        $user->favouriteVehicles()->toggle($vehicleId);

        $this->dispatch('favourites-updated', count: $user->favouriteVehicles()->count());
    }

    /**
     * The vehicle ids this buyer has already saved, as a flat array the card
     * loop can check against in O(1). Empty for guests and for dealer staff —
     * neither has a favourites set on this side.
     *
     * @return array<int, int>
     */
    private function resolveSavedVehicleIds(): array
    {
        $user = auth()->user();

        if ($user === null || $user->dealer_id !== null) {
            return [];
        }

        return $user->favouriteVehicles()->pluck('vehicles.id')->all();
    }

    /**
     * Whether the heart should render at all. Buyers and guests see it (a guest
     * tap routes to sign in); dealer staff never do.
     */
    private function favouritesAreVisible(): bool
    {
        $user = auth()->user();

        return $user === null || $user->dealer_id === null;
    }

    /**
     * Fresh view data each render. The saved set is resolved here rather than
     * in a #[Computed] on purpose, matching the convention that drives the
     * heart from a freshly-read value every time the component renders.
     *
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $shopperLocation = ShopperLocation::current();

        return [
            'savedVehicleIds' => $this->resolveSavedVehicleIds(),
            'favouritesAreVisible' => $this->favouritesAreVisible(),
            'shopperLocation' => $shopperLocation,
            // km per vehicle id, only where both ends have coordinates.
            'distancesKm' => $this->publishedVehicles
                ->mapWithKeys(fn (Vehicle $vehicle) => [$vehicle->id => ShopperLocation::distanceKm($shopperLocation, $vehicle->locatedAt())])
                ->filter(fn (?float $km) => $km !== null)
                ->all(),
        ];
    }
};
?>

<div x-data="{ filtersOpen: false }"
     x-effect="document.body.style.overflow = filtersOpen ? 'hidden' : ''"
     @keydown.escape.window="filtersOpen = false">

    <header class="srp-head">
        {{-- Copy is set in the Command Centre (Platform settings). --}}
        @if (\App\Support\PlatformSettings::get('marketplace_notice') !== '')
            <div class="srp-notice" role="status">{{ \App\Support\PlatformSettings::get('marketplace_notice') }}</div>
        @endif
        <h1 class="srp-title">{{ \App\Support\PlatformSettings::get('marketplace_headline') }}</h1>
        <p class="srp-sub">{{ \App\Support\PlatformSettings::get('marketplace_subheadline') }}</p>

        <div class="loc-bar"
             x-data="{
                 locating: false,
                 geoError: '',
                 locate() {
                     this.geoError = '';
                     if (! ('geolocation' in navigator)) {
                         this.geoError = 'Your browser can’t share a location — enter a city or postal code instead.';
                         return;
                     }
                     this.locating = true;
                     navigator.geolocation.getCurrentPosition(
                         (position) => { this.locating = false; this.$wire.useBrowserLocation(position.coords.latitude, position.coords.longitude); },
                         (error) => {
                             this.locating = false;
                             this.geoError = error.code === 1
                                 ? 'Location access was declined — enter a city or postal code instead.'
                                 : 'We couldn’t get your location — enter a city or postal code instead.';
                         },
                         { timeout: 10000, maximumAge: 600000 },
                     );
                 },
             }">
            <svg class="loc-pin" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
            @if ($shopperLocation)
                <span class="loc-current">
                    {{ isset($shopperLocation['latitude']) ? 'Showing distance from' : 'Nearest to' }}
                    <b>{{ $shopperLocation['label'] }}</b>
                </span>
                <button type="button" class="loc-link" wire:click="clearLocation">Change</button>
            @else
                <button type="button" class="loc-use" @click="locate()" :disabled="locating">
                    <span x-show="! locating">Use my location</span>
                    <span x-show="locating" x-cloak>Locating…</span>
                </button>
                <span class="loc-or">or</span>
                <form class="loc-form" wire:submit="setManualLocation">
                    <input type="text" wire:model="locationInput" placeholder="City or postal code" maxlength="60" aria-label="City or postal code">
                    <button type="submit">Set</button>
                </form>
            @endif
            <span class="loc-note" x-show="geoError" x-text="geoError" x-cloak></span>
            @error('locationInput') <span class="loc-note">{{ $message }}</span> @enderror
        </div>
    </header>

    <!-- mobile-only: search + filters + sort toolbar (inventory leads, filters fold into a sheet) -->
    <div class="srp-mobilebar">
        <label class="mb-search">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
            <input type="text" placeholder="Make, model or keyword" wire:model.live.debounce.300ms="search">
        </label>
        <div class="mb-tools">
            <button type="button" class="mb-btn" @click="filtersOpen = true">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="18" x2="20" y2="18"/><circle cx="9" cy="6" r="2.2" fill="var(--card)"/><circle cx="15" cy="12" r="2.2" fill="var(--card)"/><circle cx="9" cy="18" r="2.2" fill="var(--card)"/></svg>
                <span>Filters</span>
                @if ($this->activeFilterCount > 0)
                    <span class="mb-badge">{{ $this->activeFilterCount }}</span>
                @endif
            </button>
            <label class="mb-btn mb-sort">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h12M3 12h8M3 18h5M17 6v12m0 0 3-3m-3 3-3-3"/></svg>
                <select class="bare" wire:model.live="sort">
                    <option value="newest">Newest</option>
                    <option value="price-low">Price: low to high</option>
                    <option value="price-high">Price: high to low</option>
                    <option value="km-low">Mileage: lowest</option>
                    <option value="year-new">Year: newest</option>
                    @if ($shopperLocation)
                        <option value="nearest">Nearest first</option>
                    @endif
                </select>
                <svg class="mb-sort-chev" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>
            </label>
        </div>
    </div>

    <div class="srp-body">
        <aside class="sidebar" :class="{ 'is-open': filtersOpen }">
            <div class="sidebar-head">
                <span>Filters</span>
                <div class="sidebar-head-right">
                    <button class="clear-all" wire:click="clearFilters">Clear all</button>
                    <button type="button" class="sidebar-close" @click="filtersOpen = false" aria-label="Close filters">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M18 6 6 18M6 6l12 12"/></svg>
                    </button>
                </div>
            </div>

            <div class="sidebar-scroll">
                <div class="filter-search">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
                    <input type="text" placeholder="Make, model or keyword" wire:model.live.debounce.300ms="search">
                </div>

                <div class="fgroup" wire:key="group-price" x-data="{ open: false }">
                    <button type="button" class="fgroup-head" x-on:click="open = !open">
                        Price
                        <svg class="chev" :class="{ 'chev-closed': !open }" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>
                    </button>
                    <div class="fgroup-body" x-show="open" x-cloak>
                        <div class="frange">
                            <div class="money-field"><span>$</span><input type="number" placeholder="Min" wire:model.live.debounce.500ms="priceMin"></div>
                            <span class="dash">–</span>
                            <div class="money-field"><span>$</span><input type="number" placeholder="Max" wire:model.live.debounce.500ms="priceMax"></div>
                        </div>
                        <div class="fpills" style="margin-top:11px;">
                            <button class="fpill {{ $priceMin === 0 && $priceMax === 20000 ? 'on' : '' }}" wire:click="setPriceRange(0, 20000)">Under $20k</button>
                            <button class="fpill {{ $priceMin === 20000 && $priceMax === 30000 ? 'on' : '' }}" wire:click="setPriceRange(20000, 30000)">$20–30k</button>
                            <button class="fpill {{ $priceMin === 30000 && $priceMax === 40000 ? 'on' : '' }}" wire:click="setPriceRange(30000, 40000)">$30–40k</button>
                            <button class="fpill {{ $priceMin === 40000 && $priceMax === null ? 'on' : '' }}" wire:click="setPriceRange(40000, null)">$40k+</button>
                        </div>
                    </div>
                </div>

                <div class="fgroup" wire:key="group-make" x-data="{ open: false }">
                    <button type="button" class="fgroup-head" x-on:click="open = !open">
                        Make
                        <svg class="chev" :class="{ 'chev-closed': !open }" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>
                    </button>
                    <div class="fgroup-body" x-show="open" x-cloak>
                        @foreach ($this->availableMakes as $makeRow)
                            <label class="fopt {{ in_array($makeRow->value, $selectedMakes) ? 'on' : '' }}" wire:key="make-{{ $makeRow->value }}">
                                <input type="checkbox" wire:model.live="selectedMakes" value="{{ $makeRow->value }}" hidden>
                                <span class="box"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6 9 17l-5-5"/></svg></span>
                                <span class="fopt-label">{{ $makeRow->value }}</span>
                                <span class="count">{{ $makeRow->total }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="fgroup" wire:key="group-body" x-data="{ open: false }">
                    <button type="button" class="fgroup-head" x-on:click="open = !open">
                        Body type
                        <svg class="chev" :class="{ 'chev-closed': !open }" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>
                    </button>
                    <div class="fgroup-body" x-show="open" x-cloak>
                        @foreach ($this->availableBodyTypes as $bodyRow)
                            <label class="fopt {{ in_array($bodyRow->value, $selectedBodyTypes) ? 'on' : '' }}" wire:key="body-{{ $bodyRow->value }}">
                                <input type="checkbox" wire:model.live="selectedBodyTypes" value="{{ $bodyRow->value }}" hidden>
                                <span class="box"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6 9 17l-5-5"/></svg></span>
                                <span class="fopt-label">{{ $bodyRow->value }}</span>
                                <span class="count">{{ $bodyRow->total }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="fgroup" wire:key="group-year" x-data="{ open: false }">
                    <button type="button" class="fgroup-head" x-on:click="open = !open">
                        Year
                        <svg class="chev" :class="{ 'chev-closed': !open }" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>
                    </button>
                    <div class="fgroup-body" x-show="open" x-cloak>
                        <div class="frange">
                            <input type="number" class="num-field" placeholder="From" wire:model.live.debounce.500ms="yearMin">
                            <span class="dash">–</span>
                            <input type="number" class="num-field" placeholder="To" wire:model.live.debounce.500ms="yearMax">
                        </div>
                    </div>
                </div>

                <div class="fgroup" wire:key="group-mileage" x-data="{ open: false }">
                    <button type="button" class="fgroup-head" x-on:click="open = !open">
                        Mileage
                        <svg class="chev" :class="{ 'chev-closed': !open }" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>
                    </button>
                    <div class="fgroup-body" x-show="open" x-cloak>
                        <div class="km-readout"><b>{{ $kmMax < 200000 ? number_format($kmMax) : 'Any' }}</b>{{ $kmMax < 200000 ? ' km or less' : '' }}</div>
                        <input type="range" class="slider" min="20000" max="200000" step="10000" wire:model.live="kmMax">
                        <div class="slider-ends"><span>20,000</span><span>200,000+</span></div>
                    </div>
                </div>

                <div class="fgroup" wire:key="group-fuel" x-data="{ open: false }">
                    <button type="button" class="fgroup-head" x-on:click="open = !open">
                        Fuel
                        <svg class="chev" :class="{ 'chev-closed': !open }" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>
                    </button>
                    <div class="fgroup-body" x-show="open" x-cloak>
                        @foreach ($this->availableFuels as $fuelRow)
                            <label class="fopt {{ in_array($fuelRow->value, $selectedFuels) ? 'on' : '' }}" wire:key="fuel-{{ $fuelRow->value }}">
                                <input type="checkbox" wire:model.live="selectedFuels" value="{{ $fuelRow->value }}" hidden>
                                <span class="box"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6 9 17l-5-5"/></svg></span>
                                <span class="fopt-label">{{ $fuelRow->value }}</span>
                                <span class="count">{{ $fuelRow->total }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="fgroup" wire:key="group-drivetrain" x-data="{ open: false }">
                    <button type="button" class="fgroup-head" x-on:click="open = !open">
                        Drivetrain
                        <svg class="chev" :class="{ 'chev-closed': !open }" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>
                    </button>
                    <div class="fgroup-body" x-show="open" x-cloak>
                        @foreach ($this->availableDrivetrains as $drivetrainRow)
                            <label class="fopt {{ in_array($drivetrainRow->value, $selectedDrivetrains) ? 'on' : '' }}" wire:key="drivetrain-{{ $drivetrainRow->value }}">
                                <input type="checkbox" wire:model.live="selectedDrivetrains" value="{{ $drivetrainRow->value }}" hidden>
                                <span class="box"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6 9 17l-5-5"/></svg></span>
                                <span class="fopt-label">{{ $drivetrainRow->value }}</span>
                                <span class="count">{{ $drivetrainRow->total }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="fgroup" wire:key="group-transmission" x-data="{ open: false }">
                    <button type="button" class="fgroup-head" x-on:click="open = !open">
                        Transmission
                        <svg class="chev" :class="{ 'chev-closed': !open }" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>
                    </button>
                    <div class="fgroup-body" x-show="open" x-cloak>
                        @foreach ($this->availableTransmissions as $transmissionRow)
                            <label class="fopt {{ in_array($transmissionRow->value, $selectedTransmissions) ? 'on' : '' }}" wire:key="transmission-{{ $transmissionRow->value }}">
                                <input type="checkbox" wire:model.live="selectedTransmissions" value="{{ $transmissionRow->value }}" hidden>
                                <span class="box"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6 9 17l-5-5"/></svg></span>
                                <span class="fopt-label">{{ $transmissionRow->value }}</span>
                                <span class="count">{{ $transmissionRow->total }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="fgroup" wire:key="group-colour" x-data="{ open: false }">
                    <button type="button" class="fgroup-head" x-on:click="open = !open">
                        Colour
                        <svg class="chev" :class="{ 'chev-closed': !open }" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>
                    </button>
                    <div class="fgroup-body" x-show="open" x-cloak>
                        <div class="swatch-grid">
                            @foreach ($this->availableColourFamilies as $family)
                                <label class="cswatch {{ in_array($family, $selectedColours) ? 'on' : '' }}" wire:key="colour-{{ $family }}">
                                    <input type="checkbox" wire:model.live="selectedColours" value="{{ $family }}" hidden>
                                    <span class="dot" style="background: {{ $this->colourSwatches[$family] }}"></span>
                                    {{ $family }}
                                </label>
                            @endforeach
                        </div>
                    </div>
                </div>

                <div class="fgroup" wire:key="group-dealer" x-data="{ open: false }">
                    <button type="button" class="fgroup-head" x-on:click="open = !open">
                        Dealer
                        <svg class="chev" :class="{ 'chev-closed': !open }" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>
                    </button>
                    <div class="fgroup-body" x-show="open" x-cloak>
                        @foreach ($this->availableDealers as $dealer)
                            <label class="fopt {{ in_array($dealer->id, $selectedDealers) ? 'on' : '' }}" wire:key="dealer-{{ $dealer->id }}">
                                <input type="checkbox" wire:model.live="selectedDealers" value="{{ $dealer->id }}" hidden>
                                <span class="box"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6 9 17l-5-5"/></svg></span>
                                <span class="fopt-label">{{ ucwords(strtolower($dealer->name)) }}</span>
                                <span class="count">{{ $dealer->vehicles_count }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
            </div>

            <!-- mobile-only sheet footer -->
            <div class="sidebar-foot">
                <button type="button" class="sheet-apply" @click="filtersOpen = false">
                    Show {{ $this->publishedVehicles->count() }} {{ $this->publishedVehicles->count() === 1 ? 'car' : 'cars' }}
                </button>
            </div>
        </aside>

        <div class="srp-main" x-data="{ view: 'grid' }">
            <div class="results-bar">
                <span class="results-count"><b>{{ $this->publishedVehicles->count() }}</b> of {{ $this->totalCount }} {{ $this->totalCount === 1 ? 'car' : 'cars' }}</span>
                <div class="results-tools">
                    <div class="view-toggle">
                        <button type="button" class="vt-btn" :class="{ on: view === 'grid' }" x-on:click="view = 'grid'" title="Grid">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
                        </button>
                        <button type="button" class="vt-btn" :class="{ on: view === 'list' }" x-on:click="view = 'list'" title="List">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                        </button>
                    </div>
                    <select class="sort-select" wire:model.live="sort">
                        <option value="newest">Newest</option>
                        <option value="price-low">Price: low to high</option>
                        <option value="price-high">Price: high to low</option>
                        <option value="km-low">Mileage: lowest</option>
                        <option value="year-new">Year: newest</option>
                        @if ($shopperLocation)
                            <option value="nearest">Nearest first</option>
                        @endif
                    </select>
                </div>
            </div>

            <div class="grid" :class="{ 'is-list': view === 'list' }">
                @forelse ($this->publishedVehicles as $vehicle)
                    @include('partials.vehicle-card', [
                        'vehicle' => $vehicle,
                        'distanceKm' => $distancesKm[$vehicle->id] ?? null,
                        'savedVehicleIds' => $savedVehicleIds,
                        'favouritesAreVisible' => $favouritesAreVisible,
                    ])
                @empty
                    <div class="floor-empty">No cars match those filters — try widening them.</div>
                @endforelse
            </div>
        </div>
    </div>

    <!-- scrim behind the mobile filter sheet -->
    <div class="srp-scrim" x-cloak x-show="filtersOpen" x-transition.opacity @click="filtersOpen = false"></div>

    <style>
        [x-cloak] { display:none !important; }

        /* the head: what's on the lot, in the same voice as the homepage */
        .srp-head { margin-bottom:26px; padding-bottom:16px; border-bottom:2px solid var(--ink); }
        .srp-title { font-family:var(--font-display); font-size:clamp(32px, 3.6vw, 48px); font-weight:700; line-height:1; text-transform:uppercase; margin:0; }
        .srp-sub { color:var(--ink-2); font-size:16px; margin:8px 0 0; max-width:640px; }
        .srp-notice { background:#E9F3FF; border-left:4px solid var(--primary); border-radius:10px; color:var(--ink); padding:10px 14px; font-size:14.5px; font-weight:600; margin-bottom:18px; }

        .loc-bar { display:flex; align-items:center; flex-wrap:wrap; gap:8px 10px; margin-top:16px; font-size:14px; color:var(--ink-2); }
        .loc-pin { color:var(--ink); flex-shrink:0; }
        .loc-current b { color:var(--ink); }
        .loc-link { font-size:14px; font-weight:700; color:var(--primary-press); padding:0; text-decoration:underline; text-underline-offset:3px; }
        .loc-use { font-size:14px; font-weight:600; color:var(--ink); border:1.5px solid var(--ink); border-radius:8px; padding:6px 12px; background:#fff; }
        .loc-use:hover { background:var(--ink); color:#fff; }
        .loc-or { color:var(--ink-3); }
        .loc-form { display:flex; align-items:center; border:1.5px solid var(--line-strong); border-radius:8px; background:#fff; overflow:hidden; }
        .loc-form:focus-within { border-color:var(--ink); }
        .loc-form input { border:none; outline:none; font-size:14px; padding:7px 4px 7px 12px; width:170px; background:none; color:var(--ink); }
        .loc-form button { font-size:14px; font-weight:700; color:var(--ink); padding:7px 12px; border-left:1.5px solid var(--line-strong); }
        .loc-form button:hover { background:var(--ink); color:#fff; }
        .loc-note { flex-basis:100%; font-size:13px; color:var(--primary-press); font-weight:600; }
        .srp-body { display:grid; grid-template-columns:260px minmax(0, 1fr); gap:40px; align-items:start; }

        /* filters: a plain ruled index, no boxes */
        .sidebar { position:sticky; top:84px; max-height:calc(100vh - 100px); overflow-y:auto; padding-right:6px; scrollbar-width:thin; }
        .sidebar::-webkit-scrollbar { width:6px; } .sidebar::-webkit-scrollbar-thumb { background:var(--line-strong); border-radius:8px; }
        .sidebar-head { display:flex; align-items:baseline; justify-content:space-between; margin-bottom:12px; }
        .sidebar-head span { font-family:var(--font-display); font-size:22px; font-weight:700; text-transform:uppercase; }
        .sidebar-head-right { display:flex; align-items:center; gap:12px; }
        .clear-all { font-size:13.5px; font-weight:600; color:var(--ink-2); text-decoration:underline; text-underline-offset:3px; }
        .clear-all:hover { color:var(--ink); }
        .sidebar-close { display:none; }
        .sidebar-foot { display:none; }
        .filter-search { display:flex; align-items:center; gap:9px; background:#fff; border:1.5px solid var(--ink); border-radius:8px; padding:10px 12px; margin-bottom:14px; }
        .filter-search svg { color:var(--ink-3); flex-shrink:0; }
        .filter-search input { border:none; outline:none; background:transparent; font-size:14.5px; width:100%; color:var(--ink); }

        .fgroup { border-top:1px solid var(--line-strong); }
        .fgroup:last-child { border-bottom:1px solid var(--line-strong); }
        .fgroup-head { display:flex; align-items:center; justify-content:space-between; width:100%; padding:13px 0; font-size:15px; font-weight:700; color:var(--ink); }
        .fgroup-head .chev { color:var(--ink-3); transition:transform .2s ease; }
        .fgroup-head .chev.chev-closed { transform:rotate(-90deg); }
        .fgroup-body { padding-bottom:14px; }
        .fopt { display:flex; align-items:center; gap:10px; padding:5px 0; cursor:pointer; font-size:14.5px; color:var(--ink-2); }
        .fopt .box { width:18px; height:18px; border-radius:5px; border:1.5px solid var(--ink-3); display:grid; place-items:center; flex-shrink:0; color:#fff; }
        .fopt .box svg { opacity:0; }
        .fopt:hover .box { border-color:var(--ink); }
        .fopt.on .box { background:var(--ink); border-color:var(--ink); }
        .fopt.on .box svg { opacity:1; }
        .fopt.on { color:var(--ink); font-weight:600; }
        .fopt .fopt-label { flex:1; }
        .fopt .count { font-size:13px; color:var(--ink-3); }

        .frange { display:flex; align-items:center; gap:8px; }
        .money-field { flex:1; display:flex; align-items:center; gap:4px; border:1.5px solid var(--line-strong); border-radius:8px; padding:0 10px; background:#fff; }
        .money-field span { color:var(--ink-3); font-size:14px; }
        .money-field input { border:none; outline:none; width:100%; padding:8px 0; font-size:14px; background:transparent; color:var(--ink); }
        .num-field { flex:1; min-width:0; border:1.5px solid var(--line-strong); border-radius:8px; padding:8px 10px; font-size:14px; outline:none; background:#fff; color:var(--ink); }
        .money-field:focus-within, .num-field:focus { border-color:var(--ink); }
        .frange .dash { color:var(--ink-3); flex:none; }

        .fpills { display:flex; flex-wrap:wrap; gap:6px; }
        .fpill { padding:6px 10px; border:1.5px solid var(--line-strong); border-radius:8px; font-size:13.5px; font-weight:600; color:var(--ink-2); background:#fff; }
        .fpill:hover { border-color:var(--ink); color:var(--ink); }
        .fpill.on { background:var(--ink); border-color:var(--ink); color:#fff; }

        .km-readout { font-size:14px; color:var(--ink-2); margin-bottom:10px; }
        .km-readout b { font-family:var(--font-display); font-size:19px; color:var(--ink); font-weight:700; }
        .slider { -webkit-appearance:none; appearance:none; width:100%; height:3px; background:var(--line-strong); outline:none; }
        .slider::-webkit-slider-thumb { -webkit-appearance:none; width:16px; height:22px; border-radius:5px; background:var(--ink); cursor:pointer; }
        .slider::-moz-range-thumb { width:16px; height:22px; border-radius:5px; background:var(--ink); cursor:pointer; border:none; }
        .slider-ends { display:flex; justify-content:space-between; font-size:12px; color:var(--ink-3); margin-top:8px; }

        .swatch-grid { display:grid; grid-template-columns:1fr 1fr; gap:6px; }
        .cswatch { display:flex; align-items:center; gap:8px; padding:6px 9px; border:1.5px solid var(--line-strong); border-radius:8px; font-size:13.5px; font-weight:600; color:var(--ink-2); cursor:pointer; background:#fff; }
        .cswatch:hover { border-color:var(--ink); }
        .cswatch.on { border-color:var(--ink); color:var(--ink); box-shadow:inset 0 0 0 1px var(--ink); }
        .cswatch .dot { width:14px; height:14px; border-radius:5px; border:1px solid var(--line-strong); flex-shrink:0; }

        /* results bar: the count, big, like a stock board */
        .results-bar { display:flex; align-items:flex-end; justify-content:space-between; margin-bottom:20px; gap:14px; }
        .results-count { font-size:15px; color:var(--ink-2); }
        .results-count b { font-family:var(--font-display); font-size:30px; font-weight:700; line-height:1; color:var(--ink); margin-right:2px; }
        .results-tools { display:flex; align-items:center; gap:10px; }
        .view-toggle { display:flex; border:1.5px solid var(--ink); border-radius:8px; overflow:hidden; }
        .vt-btn { width:36px; height:34px; display:grid; place-items:center; color:var(--ink); background:#fff; }
        .vt-btn + .vt-btn { border-left:1.5px solid var(--ink); }
        .vt-btn.on { background:var(--ink); color:#fff; }
        .sort-select { border:1.5px solid var(--ink); border-radius:8px; padding:8px 12px; height:37px; font-size:14px; font-weight:600; color:var(--ink); outline:none; background:#fff; }

        /* mobile toolbar + filter sheet — hidden on desktop */
        .srp-mobilebar { display:none; }
        .srp-scrim { display:none; }

        .grid { display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:34px 22px; }
        @media (max-width:1350px) { .grid { grid-template-columns:repeat(3, minmax(0, 1fr)); } }
        @media (max-width:1000px) { .grid { grid-template-columns:repeat(2, minmax(0, 1fr)); } }
        .grid.is-list { grid-template-columns:1fr; gap:0; border-top:2px solid var(--ink); }
        .floor-empty { grid-column:1 / -1; padding:60px 32px; text-align:center; color:var(--ink-2); border:1.5px dashed var(--line-strong); }

        @media (max-width: 860px) {
            .srp-head { margin-bottom:16px; padding-bottom:14px; }
            .srp-sub { font-size:15px; }

            /* inventory leads; the sidebar lives off-canvas as a sheet */
            .srp-body { grid-template-columns:minmax(0, 1fr); gap:0; }

            .srp-mobilebar { display:flex; flex-direction:column; gap:8px; margin-bottom:18px; }
            .mb-search { display:flex; align-items:center; gap:9px; background:#fff; border:1.5px solid var(--ink); border-radius:8px; padding:12px 14px; }
            .mb-search svg { color:var(--ink-3); flex-shrink:0; }
            .mb-search input { border:none; outline:none; background:transparent; font-size:16px; width:100%; color:var(--ink); }
            .mb-tools { display:grid; grid-template-columns:1fr 1fr; gap:8px; }
            .mb-btn { display:flex; align-items:center; justify-content:center; gap:8px; height:46px; border:1.5px solid var(--ink); border-radius:8px; background:#fff; font-size:15px; font-weight:700; color:var(--ink); }
            .mb-btn svg { color:var(--ink); flex-shrink:0; }
            .mb-badge { display:inline-grid; place-items:center; min-width:20px; height:20px; padding:0 6px; border-radius:5px; background:var(--ink); color:#fff; font-size:12px; font-weight:700; }
            .mb-sort { position:relative; }
            .mb-sort select { -webkit-appearance:none; appearance:none; border:none; outline:none; background:transparent; font-family:inherit; font-size:15px; font-weight:700; color:var(--ink); padding:0; cursor:pointer; }
            .mb-sort .mb-sort-chev { color:var(--ink-2); }

            /* off-canvas bottom sheet */
            .sidebar {
                position:fixed; left:0; right:0; bottom:0; top:auto;
                width:auto; max-width:none; max-height:90dvh;
                z-index:70; padding:0; padding-right:0;
                background:#fff;
                border-top:3px solid var(--ink);
                transform:translateY(100%);
                transition:transform .32s cubic-bezier(.32,.72,0,1);
                display:flex; flex-direction:column; overflow:hidden;
            }
            .sidebar.is-open { transform:translateY(0); }
            .sidebar-head { padding:16px 16px 12px; margin:0; border-bottom:1px solid var(--line-strong); align-items:center; }
            .sidebar-close { display:grid; place-items:center; width:36px; height:36px; border-radius:8px; border:1.5px solid var(--ink); color:var(--ink); }
            .sidebar-scroll { flex:1; overflow-y:auto; -webkit-overflow-scrolling:touch; padding:14px 16px 12px; }
            .fopt { padding:9px 0; font-size:16px; }
            .fopt .box { width:22px; height:22px; }
            .fgroup-head { padding:16px 0; font-size:16px; }
            .cswatch { padding:10px 12px; font-size:14.5px; }
            .fpill { padding:10px 13px; font-size:14.5px; }
            .sidebar-foot {
                display:block;
                padding:12px 16px calc(12px + env(safe-area-inset-bottom));
                border-top:1px solid var(--line-strong);
                background:#fff;
            }
            .sheet-apply { width:100%; height:52px; border-radius:8px; background:var(--primary); color:#fff; font-family:var(--font-display); font-size:19px; font-weight:700; text-transform:uppercase; }
            .sheet-apply:active { background:var(--primary-press); }

            .srp-scrim { display:block; position:fixed; inset:0; background:rgba(7,20,55,.5); z-index:65; }

            /* count stays, desktop tools fold away (search + sort live in the toolbar) */
            .results-bar { margin-bottom:16px; }
            .results-tools { display:none; }

            .grid, .grid.is-list { grid-template-columns:minmax(0, 1fr); gap:30px; border-top:none; }
        }
    </style>
</div>
