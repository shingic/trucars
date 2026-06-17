<?php

use App\Models\Dealer;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
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
        'White' => '#FFFFFF', 'Black' => '#16181D', 'Silver' => '#C9CDD2',
        'Gray' => '#6B7280', 'Blue' => '#2563EB', 'Red' => '#DC2626', 'Green' => '#16A34A',
    ];

    public string $search = '';

    /** @var array<int, string> */
    public array $selectedMakes = [];
    /** @var array<int, string> */
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
    public array $selectedDealers = [];

    public ?int $priceMin = null;
    public ?int $priceMax = null;
    public ?int $yearMin = null;
    public ?int $yearMax = null;
    public int $kmMax = 200000;

    public string $sort = 'newest';

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
            ->where('is_published', true)
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
            ->where('is_published', true)
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
        return Vehicle::query()->where('is_published', true)->count();
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
            ->with('dealer')
            ->where('is_published', true)
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
            ->when($this->sort === 'newest', fn (Builder $query) => $query->latest())
            ->get();
    }
};
?>

<div x-data="{ filtersOpen: false }"
     x-effect="document.body.style.overflow = filtersOpen ? 'hidden' : ''"
     @keydown.escape.window="filtersOpen = false">

    <header class="srp-head">
        <h1 class="srp-title">Certified used cars in the GTA</h1>
        <p class="srp-sub">Every car is inspected, certified, and priced all-in — no as-is cars, ever. HST &amp; licensing extra.</p>
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
                <select wire:model.live="sort">
                    <option value="newest">Newest</option>
                    <option value="price-low">Price: low to high</option>
                    <option value="price-high">Price: high to low</option>
                    <option value="km-low">Mileage: lowest</option>
                    <option value="year-new">Year: newest</option>
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

                <div class="cert-promise">
                    <span class="ic"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6 9 17l-5-5"/></svg></span>
                    <div>
                        <div class="t">Every car is certified</div>
                        <div class="s">Inspected, warrantied &amp; CARFAX-backed.</div>
                    </div>
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
                <span class="results-count"><b>{{ $this->publishedVehicles->count() }}</b> of {{ $this->totalCount }} cars</span>
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
                    </select>
                </div>
            </div>

            <div class="grid" :class="{ 'is-list': view === 'list' }">
                @forelse ($this->publishedVehicles as $vehicle)
                    <article class="vcard" wire:key="vehicle-{{ $vehicle->id }}">
                        <a href="/cars/{{ $vehicle->id }}" wire:navigate class="vcard-link" aria-label="View {{ $vehicle->model_year }} {{ $vehicle->make }} {{ $vehicle->model }}"></a>
                        <div class="vcard-photo">
                            <div class="vcard-tags">
                                @if ($vehicle->is_certified)
                                    <span class="vcard-badge">
                                        <span class="dot"><svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6 9 17l-5-5"/></svg></span>
                                        Certified
                                    </span>
                                @endif
                            </div>
                            <button type="button" class="vcard-fav" x-data="{ saved: false }" :class="{ on: saved }" x-on:click="saved = !saved" title="Save">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 14c1.5-1.5 3-3.3 3-5.5A4.5 4.5 0 0 0 12 5 4.5 4.5 0 0 0 2 8.5c0 2.2 1.5 4 3 5.5l7 7Z"/></svg>
                            </button>
                            @if ($vehicle->primary_photo_url)
                                <img src="{{ $vehicle->primary_photo_url }}" alt="{{ $vehicle->model_year }} {{ $vehicle->make }} {{ $vehicle->model }}">
                            @endif
                            @if ($vehicle->fuel_type === 'Electric')
                                <span class="vcard-fuel-tag">⚡ Electric</span>
                            @endif
                        </div>
                        <div class="vcard-body">
                            <div class="vcard-title">{{ $vehicle->model_year }} {{ $vehicle->make }} {{ $vehicle->model }}</div>
                            @if ($vehicle->trim)
                                <div class="vcard-trim">{{ $vehicle->trim }}</div>
                            @endif
                            <div class="vcard-priceline">
                                <span class="vcard-price">{{ $vehicle->display_price }}</span>
                                <span class="vcard-pay">from <b>${{ number_format($vehicle->estimated_biweekly) }}</b>/bw</span>
                            </div>
                            <div class="vcard-spec-row">
                                <span class="vspec">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 8v4l3 2"/></svg>
                                    {{ $vehicle->display_kilometres }}
                                </span>
                                @if ($vehicle->drivetrain)
                                    <span class="vspec">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 13h18M5 13 7 6h10l2 7"/></svg>
                                        {{ $vehicle->drivetrain }}
                                    </span>
                                @endif
                                @if ($vehicle->fuel_type === 'Electric')
                                    <span class="vspec">Electric</span>
                                @elseif ($vehicle->transmission)
                                    <span class="vspec">{{ $vehicle->transmission }}</span>
                                @endif
                            </div>
                            <div class="vcard-foot">
                                <div class="vcard-dealer">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18M5 21V8l7-4 7 4v13M9 21v-6h6v6"/></svg>
                                    <span class="vcard-dealer-name">{{ ucwords(strtolower($vehicle->dealer->name)) }}</span>
                                    @if ($vehicle->dealer->rating)
                                        <span class="star">★{{ $vehicle->dealer->rating }}</span>
                                    @endif
                                </div>
                                <span class="vcard-cta">View →</span>
                            </div>
                        </div>
                    </article>
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

        .srp-head { margin-bottom:22px; }
        .srp-title { font-size:30px; font-weight:800; letter-spacing:-.025em; margin:0; }
        .srp-sub { color:var(--ink-2); font-size:15px; margin:6px 0 0; }
        .srp-body { display:grid; grid-template-columns:280px 1fr; gap:38px; align-items:start; }

        .sidebar { position:sticky; top:84px; max-height:calc(100vh - 100px); overflow-y:auto; padding-right:6px; scrollbar-width:thin; }
        .sidebar::-webkit-scrollbar { width:6px; } .sidebar::-webkit-scrollbar-thumb { background:var(--line-strong); border-radius:3px; }
        .sidebar-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:14px; }
        .sidebar-head span { font-size:16px; font-weight:800; letter-spacing:-.01em; }
        .sidebar-head-right { display:flex; align-items:center; gap:12px; }
        .clear-all { font-size:12.5px; font-weight:700; color:var(--primary); }
        .clear-all:hover { text-decoration:underline; }
        .sidebar-close { display:none; }
        .sidebar-foot { display:none; }
        .filter-search { display:flex; align-items:center; gap:9px; background:var(--bg-2); border:1px solid var(--line); border-radius:12px; padding:11px 14px; margin-bottom:14px; }
        .filter-search svg { color:var(--ink-3); flex-shrink:0; }
        .filter-search input { border:none; outline:none; background:transparent; font-size:14px; width:100%; color:var(--ink); }
        .cert-promise { background:var(--good-soft); border:1px solid rgba(18,184,134,.3); border-radius:12px; padding:13px; margin-bottom:8px; display:flex; align-items:flex-start; gap:11px; }
        .cert-promise .ic { width:32px; height:32px; border-radius:9px; background:var(--good); color:#fff; display:grid; place-items:center; flex-shrink:0; }
        .cert-promise .t { font-size:13px; font-weight:700; color:#0E5A43; }
        .cert-promise .s { font-size:11px; color:#12805F; margin-top:2px; line-height:1.4; }

        .fgroup { border-top:1px solid var(--line); }
        .fgroup-head { display:flex; align-items:center; justify-content:space-between; width:100%; padding:15px 0 13px; font-size:13.5px; font-weight:700; color:var(--ink); }
        .fgroup-head .chev { color:var(--ink-3); transition:transform .2s ease; }
        .fgroup-head .chev.chev-closed { transform:rotate(-90deg); }
        .fgroup-body { padding-bottom:15px; }
        .fopt { display:flex; align-items:center; gap:10px; padding:6px 0; cursor:pointer; font-size:13.5px; color:var(--ink-2); }
        .fopt .box { width:18px; height:18px; border-radius:6px; border:1.5px solid var(--line-strong); display:grid; place-items:center; flex-shrink:0; transition:all .15s ease; color:#fff; }
        .fopt .box svg { opacity:0; }
        .fopt.on .box { background:var(--primary); border-color:var(--primary); }
        .fopt.on .box svg { opacity:1; }
        .fopt.on { color:var(--ink); font-weight:600; }
        .fopt .fopt-label { flex:1; }
        .fopt .count { font-size:12px; color:var(--ink-3); font-weight:500; }

        .frange { display:flex; align-items:center; gap:9px; }
        .money-field { flex:1; display:flex; align-items:center; gap:4px; border:1.5px solid var(--line-strong); border-radius:10px; padding:0 11px; }
        .money-field span { color:var(--ink-3); font-size:13.5px; }
        .money-field input { border:none; outline:none; width:100%; padding:9px 0; font-size:13.5px; background:transparent; color:var(--ink); }
        .num-field { flex:1; border:1.5px solid var(--line-strong); border-radius:10px; padding:9px 11px; font-size:13.5px; outline:none; background:var(--card); color:var(--ink); }
        .money-field:focus-within, .num-field:focus { border-color:var(--primary); }
        .frange .dash { color:var(--ink-3); flex:none; }

        .fpills { display:flex; flex-wrap:wrap; gap:8px; }
        .fpill { padding:8px 12px; border:1.5px solid var(--line-strong); border-radius:var(--radius-pill); font-size:12.5px; font-weight:600; color:var(--ink-2); transition:all .15s ease; }
        .fpill:hover { border-color:var(--primary); }
        .fpill.on { background:var(--primary-soft); border-color:var(--primary); color:var(--primary); }

        .km-readout { font-size:13px; color:var(--ink-2); margin-bottom:10px; }
        .km-readout b { color:var(--ink); font-weight:700; }
        .slider { -webkit-appearance:none; appearance:none; width:100%; height:5px; border-radius:3px; background:var(--line-strong); outline:none; }
        .slider::-webkit-slider-thumb { -webkit-appearance:none; width:20px; height:20px; border-radius:50%; background:var(--primary); cursor:pointer; box-shadow:0 2px 6px rgba(245,99,31,.4); border:2px solid #fff; }
        .slider::-moz-range-thumb { width:20px; height:20px; border-radius:50%; background:var(--primary); cursor:pointer; border:2px solid #fff; }
        .slider-ends { display:flex; justify-content:space-between; font-size:11px; color:var(--ink-3); margin-top:7px; }

        .swatch-grid { display:grid; grid-template-columns:1fr 1fr; gap:8px; }
        .cswatch { display:flex; align-items:center; gap:8px; padding:7px 10px; border:1.5px solid var(--line-strong); border-radius:10px; font-size:12.5px; font-weight:600; color:var(--ink-2); transition:all .15s ease; cursor:pointer; }
        .cswatch:hover { border-color:var(--primary); }
        .cswatch.on { border-color:var(--primary); background:var(--primary-soft); color:var(--primary); }
        .cswatch .dot { width:15px; height:15px; border-radius:50%; border:1px solid var(--line-strong); flex-shrink:0; }

        .results-bar { display:flex; align-items:center; justify-content:space-between; margin-bottom:18px; gap:14px; }
        .results-count { font-size:14px; color:var(--ink-2); }
        .results-count b { color:var(--ink); font-weight:700; }
        .results-tools { display:flex; align-items:center; gap:12px; }
        .view-toggle { display:flex; background:var(--bg-2); border:1px solid var(--line); border-radius:10px; padding:3px; }
        .vt-btn { width:32px; height:30px; border-radius:7px; display:grid; place-items:center; color:var(--ink-3); }
        .vt-btn.on { background:var(--card); color:var(--primary); box-shadow:var(--shadow-sm); }
        .sort-select { border:1.5px solid var(--line-strong); border-radius:10px; padding:9px 13px; font-size:13.5px; font-weight:600; color:var(--ink); outline:none; background:var(--card); }

        /* mobile toolbar + filter sheet — hidden on desktop */
        .srp-mobilebar { display:none; }
        .srp-scrim { display:none; }

        .grid { display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:24px; }
        @media (max-width:1350px) { .grid { grid-template-columns:repeat(3, minmax(0, 1fr)); } }
        @media (max-width:1000px) { .grid { grid-template-columns:repeat(2, minmax(0, 1fr)); } }

        .vcard { position:relative; background:var(--card); border:1px solid var(--line); border-radius:var(--radius-sm); overflow:hidden; transition:transform .16s ease, box-shadow .16s ease, border-color .16s ease; display:flex; flex-direction:column; }
        .vcard:hover { transform:translateY(-4px); box-shadow:var(--shadow-md); border-color:transparent; }
        .vcard-link { position:absolute; inset:0; z-index:1; }
        .vcard-photo { height:178px; background:var(--hero-grad); position:relative; }
        .vcard-photo img { width:100%; height:100%; object-fit:cover; display:block; }
        .vcard-tags { position:absolute; top:11px; left:11px; z-index:2; }
        .vcard-badge { display:inline-flex; align-items:center; gap:5px; background:rgba(255,255,255,.96); border-radius:var(--radius-pill); padding:4px 10px 4px 7px; font-size:11px; font-weight:700; color:#0E5A43; box-shadow:var(--shadow-sm); }
        .vcard-badge .dot { width:13px; height:13px; border-radius:50%; background:var(--good); display:grid; place-items:center; color:#fff; }
        .vcard-fav { position:absolute; top:11px; right:11px; z-index:2; width:32px; height:32px; border-radius:50%; background:rgba(255,255,255,.92); display:grid; place-items:center; color:var(--ink-2); box-shadow:var(--shadow-sm); }
        .vcard-fav:hover { color:var(--primary); }
        .vcard-fav.on { color:var(--primary); }
        .vcard-fav.on svg { fill:var(--primary); }
        .vcard-fuel-tag { position:absolute; bottom:11px; right:11px; z-index:2; background:rgba(22,24,29,.78); color:#fff; font-size:10.5px; font-weight:700; padding:3px 9px; border-radius:var(--radius-pill); }
        .vcard-body { padding:15px 16px 16px; display:flex; flex-direction:column; flex:1; position:relative; z-index:2; pointer-events:none; }
        .vcard-title { font-weight:700; font-size:15.5px; letter-spacing:-.01em; line-height:1.3; }
        .vcard-trim { font-size:12.5px; color:var(--ink-3); margin-top:2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .vcard-priceline { display:flex; align-items:baseline; gap:8px; margin-top:10px; }
        .vcard-price { font-weight:800; font-size:19px; letter-spacing:-.02em; }
        .vcard-was { font-size:12.5px; color:var(--ink-3); text-decoration:line-through; }
        .vcard-pay { font-size:12px; color:var(--ink-3); margin-left:auto; white-space:nowrap; }
        .vcard-pay b { color:var(--good); font-weight:700; }
        .vcard-spec-row { display:flex; flex-wrap:wrap; gap:13px; margin-top:12px; font-size:12px; color:var(--ink-2); }
        .vcard-spec-row .vspec { display:inline-flex; align-items:center; gap:5px; }
        .vcard-spec-row svg { color:var(--ink-3); }
        .vcard-foot { margin-top:14px; padding-top:13px; border-top:1px solid var(--line); display:flex; align-items:center; justify-content:space-between; gap:10px; }
        .vcard-dealer { display:flex; align-items:center; gap:6px; font-size:11.5px; color:var(--ink-3); min-width:0; flex:1; }
        .vcard-dealer svg { flex-shrink:0; }
        .vcard-dealer-name { white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .vcard-dealer .star { color:var(--coral); font-weight:700; white-space:nowrap; flex-shrink:0; }
        .vcard-cta { font-size:12.5px; font-weight:700; color:var(--primary); white-space:nowrap; flex-shrink:0; }
        .floor-empty { grid-column:1 / -1; padding:60px 32px; text-align:center; color:var(--ink-2); }

        .grid.is-list { grid-template-columns:1fr; gap:14px; }
        .grid.is-list .vcard { flex-direction:row; }
        .grid.is-list .vcard-photo { width:280px; height:auto; flex-shrink:0; }
        .grid.is-list .vcard-body { flex:1; }

        @media (max-width: 860px) {
            .srp-head { margin-bottom:16px; }
            .srp-title { font-size:25px; }
            .srp-sub { font-size:14px; }

            /* inventory leads; the sidebar lives off-canvas as a sheet */
            .srp-body { grid-template-columns:1fr; gap:0; }

            .srp-mobilebar { display:flex; flex-direction:column; gap:10px; margin-bottom:16px; }
            .mb-search { display:flex; align-items:center; gap:9px; background:var(--bg-2); border:1px solid var(--line); border-radius:13px; padding:13px 15px; }
            .mb-search svg { color:var(--ink-3); flex-shrink:0; }
            .mb-search input { border:none; outline:none; background:transparent; font-size:15px; width:100%; color:var(--ink); }
            .mb-tools { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
            .mb-btn { display:flex; align-items:center; justify-content:center; gap:8px; height:46px; border:1.5px solid var(--line-strong); border-radius:13px; background:var(--card); font-size:14.5px; font-weight:700; color:var(--ink); }
            .mb-btn:active { border-color:var(--primary); }
            .mb-btn svg { color:var(--ink-2); flex-shrink:0; }
            .mb-badge { display:inline-grid; place-items:center; min-width:20px; height:20px; padding:0 6px; border-radius:var(--radius-pill); background:var(--primary); color:#fff; font-size:11.5px; font-weight:700; }
            .mb-sort { position:relative; }
            .mb-sort select { -webkit-appearance:none; appearance:none; border:none; outline:none; background:transparent; font-family:inherit; font-size:14.5px; font-weight:700; color:var(--ink); padding:0; cursor:pointer; }
            .mb-sort .mb-sort-chev { color:var(--ink-2); }

            /* off-canvas bottom sheet */
            .sidebar {
                position:fixed; left:0; right:0; bottom:0; top:auto;
                width:auto; max-width:none; max-height:90dvh;
                z-index:70; padding:0; padding-right:0;
                background:var(--card);
                border-top-left-radius:22px; border-top-right-radius:22px;
                box-shadow:0 -20px 60px rgba(22,24,29,.28);
                transform:translateY(100%);
                transition:transform .32s cubic-bezier(.32,.72,0,1);
                display:flex; flex-direction:column; overflow:hidden;
            }
            .sidebar.is-open { transform:translateY(0); }
            .sidebar-head { padding:18px 18px 14px; margin:0; border-bottom:1px solid var(--line); }
            .sidebar-head span { font-size:18px; }
            .sidebar-close { display:grid; place-items:center; width:34px; height:34px; border-radius:50%; background:var(--bg-2); color:var(--ink-2); }
            .sidebar-scroll { flex:1; overflow-y:auto; -webkit-overflow-scrolling:touch; padding:4px 18px 12px; }
            .fopt { padding:10px 0; font-size:15px; }
            .fopt .box { width:22px; height:22px; }
            .fgroup-head { padding:17px 0 15px; font-size:15px; }
            .cswatch { padding:10px 12px; font-size:13.5px; }
            .fpill { padding:10px 14px; font-size:13.5px; }
            .sidebar-foot {
                display:block;
                padding:14px 18px calc(14px + env(safe-area-inset-bottom));
                border-top:1px solid var(--line);
                background:var(--card);
            }
            .sheet-apply {
                width:100%; height:52px;
                border-radius:var(--radius-pill);
                background:var(--primary); color:#fff;
                font-size:15.5px; font-weight:700;
                box-shadow:var(--shadow-primary);
            }
            .sheet-apply:active { background:var(--primary-press); }

            .srp-scrim { display:block; position:fixed; inset:0; background:rgba(22,24,29,.45); backdrop-filter:blur(2px); z-index:65; }

            /* count stays, desktop tools fold away (search + sort live in the toolbar) */
            .results-bar { margin-bottom:14px; }
            .results-tools { display:none; }

            /* full-width cards with a taller hero */
            .grid, .grid.is-list { grid-template-columns:1fr; gap:14px; }
            .grid.is-list .vcard { flex-direction:column; }
            .vcard { border-radius:18px; }
            .vcard-photo, .grid.is-list .vcard-photo { width:100%; height:210px; }
            .vcard-fav { width:38px; height:38px; }
            .vcard-body { padding:16px 17px 17px; }
            .vcard-title { font-size:16.5px; }
            .vcard-price { font-size:20px; }
        }
    </style>
</div>
