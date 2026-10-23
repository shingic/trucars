<?php

use App\Models\Campaign;
use App\Models\Vehicle;
use App\Support\CampaignAttribution;
use App\Support\VehicleCertification;
use Illuminate\Support\Facades\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    public Vehicle $vehicle;

    /**
     * Set when the car is reached through a dealer campaign link
     * (/shop/{token}/cars/{vehicle}) rather than the open floor. Navigation
     * then leads back to that dealer's storefront, never the marketplace.
     */
    #[Locked]
    public ?string $campaignToken = null;

    public function mount(Vehicle $vehicle, ?string $token = null): void
    {
        $this->vehicle = $vehicle;

        if ($token === null) {
            // Open floor: only cars that are actually live (published, active
            // dealer), same rule as checkout. Not browsable by guessing an id.
            abort_unless($vehicle->isListed(), 404);

            return;
        }

        $campaign = Campaign::findActiveByToken($token);

        // Unknown or inactive link, or an id swapped for another dealer's car:
        // nothing to show. The campaign never reaches beyond its own dealer.
        abort_if($campaign === null, 404);
        abort_unless($vehicle->dealer_id === $campaign->dealer_id, 404);

        if (! $vehicle->isEligibleForDealerCpo($campaign->dealer, $campaign->rooftop_id)) {
            session()->flash('campaign_unavailable_vehicle', "{$vehicle->model_year} {$vehicle->make} {$vehicle->model}");
            $this->redirectRoute('campaign.shop', ['token' => $token]);

            return;
        }

        CampaignAttribution::capture($campaign, request());

        $this->campaignToken = $token;
        View::share('referredCampaign', $campaign);
    }

    /** Where "back" goes — the campaign storefront when referred, else the floor. */
    public function backUrl(): string
    {
        return $this->campaignToken !== null
            ? route('campaign.shop', ['token' => $this->campaignToken])
            : route('shop');
    }

    /**
     * The dealer's inspection checklist this car is shown with, or null — then
     * the page makes no certification claim. See VehicleCertification.
     *
     * Resolved once per request and cached on the component so the template can
     * read it freely. This is a plain method (not a #[Computed]) on purpose —
     * computed memoisation has bitten us before with one-step-behind rendering.
     */
    protected ?VehicleCertification $resolvedCertification = null;
    protected bool $certificationResolved = false;

    /** Offers this car qualifies for today, resolved once per request. */
    protected ?\Illuminate\Support\Collection $resolvedOffers = null;

    public function offers(): \Illuminate\Support\Collection
    {
        return $this->resolvedOffers ??= $this->vehicle->offers();
    }

    /**
     * The payment line: the offer's promotional rate and term when a live
     * financing offer applies to this car, otherwise the standard estimate.
     */
    public function paymentEstimate(): array
    {
        $offer = $this->offers()->firstWhere('type', \App\Models\Offer::TYPE_APR);

        return $offer
            ? ['biweekly' => $this->vehicle->biweeklyAt((float) $offer->apr, (int) $offer->term_months), 'apr' => (float) $offer->apr, 'term' => (int) $offer->term_months, 'offer' => $offer]
            : ['biweekly' => $this->vehicle->estimated_biweekly, 'apr' => 7.5, 'term' => 72, 'offer' => null];
    }

    public function certification(): ?VehicleCertification
    {
        if (! $this->certificationResolved) {
            $this->vehicle->loadMissing(VehicleCertification::EAGER);
            $this->resolvedCertification = $this->vehicle->certification();
            $this->certificationResolved = true;
        }

        return $this->resolvedCertification;
    }

    /**
     * The ordered photo set the gallery renders, normalized to a stable shape.
     *
     * Photos arrive today as plain URL strings. This maps each one into
     * ['url' => ..., 'category' => ...] and makes the lead photo open the
     * sequence so the gallery opens on the front of the car.
     *
     * When the photo records graduate to structured rows (carrying their own
     * category and position), the structured branch below reads those through
     * untouched and the gallery's category pills light up on their own.
     */
    public function galleryPhotos(): array
    {
        $leadUrl = $this->vehicle->primary_photo_url;
        $rawPhotos = $this->vehicle->photos ?? [];

        $photos = collect($rawPhotos)
            ->map(fn ($photo) => $this->normalizePhoto($photo))
            ->filter(fn ($photo) => filled($photo['url']))
            ->values();

        if (filled($leadUrl)) {
            $leadAlreadyPresent = $photos->contains(fn ($photo) => $photo['url'] === $leadUrl);

            if ($leadAlreadyPresent) {
                $photos = $photos
                    ->sortBy(fn ($photo) => $photo['url'] === $leadUrl ? 0 : 1)
                    ->values();
            } else {
                $photos->prepend(['url' => $leadUrl, 'category' => 'exterior']);
            }
        }

        return $photos->values()->all();
    }

    /**
     * Condition markers pinned to positions on the gallery photos. None are
     * wired yet, so the hero renders clean. The shape, for
     * when the inspection layer supplies them:
     *
     *   ['photoIndex' => 0, 'x' => 62, 'y' => 37, 'title' => 'Wheel scuff']
     *
     * x and y are percentages of the photo's width and height. photoIndex is
     * the position in galleryPhotos().
     */
    public function galleryHotspots(): array
    {
        return [];
    }

    protected function normalizePhoto(mixed $photo): array
    {
        if (is_array($photo)) {
            return [
                'url' => $photo['url'] ?? '',
                'category' => strtolower($photo['category'] ?? 'exterior'),
            ];
        }

        return [
            'url' => (string) $photo,
            'category' => 'exterior',
        ];
    }

    /**
     * Resolved-once cache for the saved state, mirroring how certification() is
     * cached above — the save control is read in a couple of places (the rail
     * button and the mobile sticky bar) and we don't want a query each time.
     * It is reset naturally on the next request, so the re-render after a toggle
     * reads fresh truth rather than a stale memoised value.
     */
    protected ?bool $resolvedIsSaved = null;

    /**
     * Save or unsave this car. A guest is sent to sign in, with this car set as
     * the intended return so they land back here after authenticating. Dealer
     * staff never keep favourites, so their tap is a no-op. For a signed-in
     * buyer the favourites pivot is toggled atomically.
     */
    public function toggleFavourite(): void
    {
        $user = auth()->user();

        if ($user !== null && $user->dealer_id !== null) {
            return;
        }

        if ($user === null) {
            session()->put('url.intended', $this->campaignToken !== null
                ? route('campaign.vehicle', ['token' => $this->campaignToken, 'vehicle' => $this->vehicle->id])
                : url('/cars/' . $this->vehicle->id));
            $this->redirect(route('buyer.login'));

            return;
        }

        $user->favouriteVehicles()->toggle($this->vehicle->id);

        $this->dispatch('favourites-updated', count: $user->favouriteVehicles()->count());
    }

    /**
     * Whether the current buyer has already saved this car. False for guests
     * and for dealer staff — neither holds favourites on this side.
     */
    public function isSaved(): bool
    {
        if ($this->resolvedIsSaved === null) {
            $user = auth()->user();

            $this->resolvedIsSaved = $user !== null
                && $user->dealer_id === null
                && $user->hasFavourited($this->vehicle);
        }

        return $this->resolvedIsSaved;
    }

    /**
     * Whether the save control should render at all. Buyers and guests see it
     * (a guest tap routes to sign in); dealer staff never do.
     */
    /**
     * The bell on a reserved car: email me if the reservation falls through.
     * Shoppers need an account (same as saving a car); a second tap turns it off.
     */
    public function toggleAvailabilityAlert(): void
    {
        $user = auth()->user();

        if ($user !== null && $user->dealer_id !== null) {
            return;
        }

        if ($user === null) {
            session()->put('url.intended', $this->campaignToken !== null
                ? route('campaign.vehicle', ['token' => $this->campaignToken, 'vehicle' => $this->vehicle->id])
                : url('/cars/' . $this->vehicle->id));
            $this->redirect(route('buyer.login'));

            return;
        }

        $alert = \App\Models\VehicleAlert::firstWhere(['user_id' => $user->id, 'vehicle_id' => $this->vehicle->id]);

        if ($alert && $alert->notified_at === null) {
            $alert->delete();
        } elseif ($alert) {
            $alert->forceFill(['notified_at' => null])->save(); // watching again after an earlier email
        } else {
            \App\Models\VehicleAlert::create(['user_id' => $user->id, 'vehicle_id' => $this->vehicle->id]);
        }
    }

    public function isWatching(): bool
    {
        $user = auth()->user();

        return $user !== null && \App\Models\VehicleAlert::where(['user_id' => $user->id, 'vehicle_id' => $this->vehicle->id])->whereNull('notified_at')->exists();
    }

    public function favouriteIsVisible(): bool
    {
        $user = auth()->user();

        return $user === null || $user->dealer_id === null;
    }
};
?>

@php $reserved = $vehicle->isReserved(); @endphp

<div class="vdp">
    <a href="{{ $this->backUrl() }}" wire:navigate class="vdp-back">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M11 6l-6 6 6 6"/></svg>
        {{ $campaignToken !== null ? 'More certified cars from ' . ucwords(strtolower($vehicle->dealer->name)) : 'Back to results' }}
    </a>

    <div class="vdp-grid">
        <div class="vdp-main">
            <div
                class="gallery"
                role="group"
                aria-label="Vehicle photos"
                x-data="vehicleGallery(@js($this->galleryPhotos()), @js($this->galleryHotspots()))"
                x-on:keydown.escape.window="isFullscreen ? closeFullscreen() : closeHotspot()"
                x-on:keydown.arrow-left.window="isFullscreen && prev()"
                x-on:keydown.arrow-right.window="isFullscreen && next()"
            >
                {{-- Category pills — hidden until photos carry more than one category --}}
                <div class="gallery-pills" x-show="showCategoryPills" x-cloak>
                    <button type="button" class="gpill" :class="{ on: activeCategory === 'all' }" x-on:click="showCategory('all')">
                        All <span class="gpill-count" x-text="photos.length"></span>
                    </button>
                    <template x-for="category in categories" :key="category.name">
                        <button type="button" class="gpill" :class="{ on: activeCategory === category.name }" x-on:click="showCategory(category.name)">
                            <span x-text="prettyCategory(category.name)"></span>
                            <span class="gpill-count" x-text="category.count"></span>
                        </button>
                    </template>
                </div>

                {{-- Hero — swipe or drag sideways for the next or previous photo --}}
                <div
                    class="gallery-hero"
                    x-on:pointerdown="dragStart($event)"
                    x-on:pointerup="dragEnd($event)"
                    x-on:pointercancel="dragCancel()"
                    x-on:pointerleave="dragCancel()"
                    x-on:dblclick="openFullscreen()"
                >
                    <template x-if="currentPhoto.url">
                        <img
                            class="gallery-img"
                            :src="currentPhoto.url"
                            :alt="`{{ $vehicle->model_year }} {{ $vehicle->make }} {{ $vehicle->model }} — photo ${currentPosition} of ${visibleCount}`"
                            draggable="false"
                        >
                    </template>

                    <div class="gallery-badges">
                        @if ($reserved)
                            <span class="gallery-badge reserved">
                                <span class="dot"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.6"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg></span>
                                Reserved
                            </span>
                        @endif
                        @if ($this->certification())
                            <span class="gallery-badge">
                                <span class="dot"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3"><path d="M20 6 9 17l-5-5"/></svg></span>
                                {{ $this->certification()->badge }}
                            </span>
                        @endif
                    </div>

                    <button type="button" class="ghero-fs" x-on:click="openFullscreen()" aria-label="Open fullscreen gallery">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/></svg>
                    </button>

                    <button type="button" class="ghero-arrow left" x-show="visibleCount > 1" x-on:click.stop="prev()" aria-label="Previous photo">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="m15 18-6-6 6-6"/></svg>
                    </button>
                    <button type="button" class="ghero-arrow right" x-show="visibleCount > 1" x-on:click.stop="next()" aria-label="Next photo">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="m9 18 6-6-6-6"/></svg>
                    </button>

                    <span class="ghero-counter" x-show="visibleCount > 1">
                        <span x-text="currentPosition"></span> / <span x-text="visibleCount"></span>
                    </span>

                    {{-- Condition hotspots for the current photo — render nothing when none --}}
                    <template x-for="spot in hotspotsForCurrent" :key="spot.id">
                        <button
                            type="button"
                            class="ghero-hotspot"
                            :style="`left:${spot.x}%; top:${spot.y}%`"
                            x-on:click.stop="toggleHotspot(spot.id)"
                            :aria-label="`Condition note: ${spot.title}`"
                        >
                            <span class="hs-dot"></span>
                            <span class="hs-pop" x-show="openHotspot === spot.id" x-transition x-cloak x-text="spot.title"></span>
                        </button>
                    </template>
                </div>

                {{-- Thumbnails — lazy loaded, scoped to the active category --}}
                <div class="gallery-thumbs-wrap" x-show="visibleCount > 1">
                    <button type="button" class="gthumb-nav" x-on:click="$refs.thumbs.scrollBy({ left: -240, behavior: 'smooth' })" aria-label="Scroll thumbnails left">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>
                    </button>
                    <div class="gallery-thumbs" x-ref="thumbs">
                        <template x-for="photo in visiblePhotos" :key="photo.index">
                            <button
                                type="button"
                                class="gthumb"
                                :class="{ on: photo.index === currentIndex }"
                                :data-active="photo.index === currentIndex"
                                x-on:click="goTo(photo.index)"
                                :aria-label="`Show photo ${photo.index + 1}`"
                            >
                                <img :src="photo.url" loading="lazy" alt="">
                            </button>
                        </template>
                    </div>
                    <button type="button" class="gthumb-nav" x-on:click="$refs.thumbs.scrollBy({ left: 240, behavior: 'smooth' })" aria-label="Scroll thumbnails right">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
                    </button>
                </div>

                {{-- Inspection condition notes — appears only when hotspots exist; jumps to the photo --}}
                <div class="gallery-conditions" x-show="hotspots.length > 0" x-cloak>
                    <div class="gc-head">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v4M12 17h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/></svg>
                        Inspection condition notes
                    </div>
                    <template x-for="(spot, id) in hotspots" :key="id">
                        <button type="button" class="gc-row" x-on:click="jumpToHotspot(id)">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v4M12 17h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/></svg>
                            <span x-text="spot.title"></span>
                            <svg class="gc-go" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                        </button>
                    </template>
                </div>

                {{-- Fullscreen viewer — teleported to body to escape the grid's overflow --}}
                <template x-teleport="body">
                    <div
                        class="gallery-fs"
                        x-show="isFullscreen"
                        x-cloak
                        x-ref="fullscreen"
                        role="dialog"
                        aria-modal="true"
                        aria-label="Vehicle photo gallery, fullscreen"
                        x-on:keydown="trapFocus($event)"
                        x-transition.opacity.duration.150ms
                    >
                        <div class="gfs-bar">
                            <span class="gfs-counter">
                                <span x-text="currentPosition"></span> / <span x-text="visibleCount"></span>
                            </span>
                            <div class="gfs-pills" x-show="showCategoryPills">
                                <button type="button" class="gfs-pill" :class="{ on: activeCategory === 'all' }" x-on:click="showCategory('all')">All</button>
                                <template x-for="category in categories" :key="category.name">
                                    <button type="button" class="gfs-pill" :class="{ on: activeCategory === category.name }" x-on:click="showCategory(category.name)" x-text="prettyCategory(category.name)"></button>
                                </template>
                            </div>
                            <button type="button" class="gfs-close" x-ref="fullscreenClose" x-on:click="closeFullscreen()" aria-label="Close fullscreen">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18M6 6l12 12"/></svg>
                            </button>
                        </div>

                        <div class="gfs-stage" x-on:click.self="closeFullscreen()">
                            <button type="button" class="gfs-arrow left" x-show="visibleCount > 1" x-on:click="prev()" aria-label="Previous photo">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="m15 18-6-6 6-6"/></svg>
                            </button>

                            <template x-if="currentPhoto.url">
                                <img
                                    class="gfs-img"
                                    :src="currentPhoto.url"
                                    :alt="`Vehicle photo ${currentPosition} of ${visibleCount}`"
                                    draggable="false"
                                    :style="`transform:${stageTransform}; cursor:${zoomScale > 1 ? 'grab' : 'zoom-in'}`"
                                    x-on:pointerdown="stagePointerDown($event)"
                                    x-on:pointermove="stagePointerMove($event)"
                                    x-on:pointerup="stagePointerUp($event)"
                                    x-on:pointercancel="stagePointerUp($event)"
                                    x-on:wheel.prevent="zoomWithWheel($event)"
                                    x-on:dblclick="toggleZoom()"
                                >
                            </template>

                            <button type="button" class="gfs-arrow right" x-show="visibleCount > 1" x-on:click="next()" aria-label="Next photo">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="m9 18 6-6-6-6"/></svg>
                            </button>
                        </div>

                        <div class="gfs-hint">Scroll or double-click to zoom · drag to pan · swipe to browse</div>

                        <div class="gfs-thumbs" x-ref="fullscreenThumbs">
                            <template x-for="photo in visiblePhotos" :key="photo.index">
                                <button
                                    type="button"
                                    class="gfs-thumb"
                                    :class="{ on: photo.index === currentIndex }"
                                    :data-active="photo.index === currentIndex"
                                    x-on:click="goTo(photo.index)"
                                    :aria-label="`Show photo ${photo.index + 1}`"
                                >
                                    <img :src="photo.url" loading="lazy" alt="">
                                </button>
                            </template>
                        </div>
                    </div>
                </template>
            </div>

            <div class="vdp-titlerow">
                <h1 class="vdp-h1">{{ $vehicle->model_year }} {{ $vehicle->make }} {{ $vehicle->model }}</h1>
                <p class="vdp-trim">{{ collect([$vehicle->trim, $vehicle->stock_number ? 'Stock #' . $vehicle->stock_number : null])->filter()->implode(' · ') }}</p>
                <dl class="vdp-quickmeta">
                    <div class="qm"><dt>Mileage</dt><dd>{{ number_format($vehicle->kilometres) }} km</dd></div>
                    @if ($vehicle->drivetrain)<div class="qm"><dt>Drivetrain</dt><dd>{{ $vehicle->drivetrain }}</dd></div>@endif
                    @if ($vehicle->fuel_type)<div class="qm"><dt>Fuel</dt><dd>{{ $vehicle->fuel_type }}</dd></div>@endif
                    @if ($vehicle->transmission)<div class="qm"><dt>Transmission</dt><dd>{{ $vehicle->transmission }}</dd></div>@endif
                    @if ($vehicle->colour)<div class="qm"><dt>Colour</dt><dd>{{ $vehicle->colour }}</dd></div>@endif
                </dl>
            </div>

            @if ($cert = $this->certification())
                <div class="vdp-section">
                    <div class="cert-block" x-data="{ certDetails: false }" :class="{ 'is-expanded': certDetails }">
                        <div class="cert-head">
                            <div class="cert-headtext">
                                <h3>{{ $cert->name }}</h3>
                                <div class="cert-by">{{ $cert->pointCount }}-point inspection · {{ ucwords(strtolower($vehicle->dealer->name)) }}</div>
                                @if ($cert->summary) <p>{{ $cert->summary }}</p> @endif
                            </div>
                        </div>

                        {{-- Every point on the dealer's checklist, by section. --}}
                        <div class="cert-benefits">
                            @foreach ($cert->sections as $section => $points)
                                <div class="cben">
                                    <span class="ic"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6 9 17l-5-5"/></svg></span>
                                    <div>
                                        <div class="t">{{ $section }} <span class="cben-count">{{ count($points) }}</span></div>
                                        <ul class="s cben-points">
                                            @foreach ($points as $point)<li>{{ $point }}</li>@endforeach
                                        </ul>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <button type="button" class="cert-more" x-on:click="certDetails = ! certDetails">
                            <span x-text="certDetails ? 'Hide points' : 'See all {{ $cert->pointCount }} points'">See all {{ $cert->pointCount }} points</span>
                            <svg class="cert-more-chev" :class="{ 'is-up': certDetails }" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="m6 9 6 6 6-6"/></svg>
                        </button>
                    </div>
                </div>
            @endif

            <div class="vdp-section">
                <h2>Vehicle details</h2>
                <div class="specs">
                    @if ($vehicle->body_type)<div class="spec"><span class="k">Body style</span><span class="v">{{ $vehicle->body_type }}</span></div>@endif
                    @if ($vehicle->drivetrain)<div class="spec"><span class="k">Drivetrain</span><span class="v">{{ $vehicle->drivetrain }}</span></div>@endif
                    @if ($vehicle->transmission)<div class="spec"><span class="k">Transmission</span><span class="v">{{ $vehicle->transmission }}</span></div>@endif
                    @if ($vehicle->fuel_type)<div class="spec"><span class="k">Fuel</span><span class="v">{{ $vehicle->fuel_type }}</span></div>@endif
                    @if ($vehicle->colour)<div class="spec"><span class="k">Exterior</span><span class="v">{{ $vehicle->colour }}</span></div>@endif
                    <div class="spec"><span class="k">Mileage</span><span class="v">{{ number_format($vehicle->kilometres) }} km</span></div>
                    <div class="spec"><span class="k">Condition</span><span class="v">{{ ucfirst(strtolower($vehicle->condition)) }}</span></div>
                    @if ($vehicle->stock_number)<div class="spec"><span class="k">Stock #</span><span class="v">{{ $vehicle->stock_number }}</span></div>@endif
                    <div class="spec"><span class="k">VIN</span><span class="v">{{ $vehicle->vin }}</span></div>
                </div>
            </div>

            <div class="vdp-section">
                <h2>Sold by</h2>
                <div class="dealer-card">
                    <div class="dealer-top">
                        <div>
                            <div class="dealer-name">{{ ucwords(strtolower($vehicle->dealer->name)) }}</div>
                            {{-- The dealer sells it; the address is the rooftop the car sits at. --}}
                            @php $store = $vehicle->locatedAt(); @endphp
                            <div class="dealer-sub">
                                @if ($store instanceof \App\Models\Rooftop && $store->name) {{ $store->name }} · @endif
                                {{ implode(' · ', $store->address_lines) ?: $store->display_location }}
                                @if ($store instanceof \App\Models\Rooftop && $store->todayLabel()) <span class="dot-sep"></span> {{ $store->todayLabel() }} @endif
                                @if ($vehicle->dealer->registration_number)
                                    <span class="dot-sep"></span> {{ $vehicle->dealer->regulator ?? 'Dealer reg.' }}&nbsp;#{{ $vehicle->dealer->registration_number }}
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <aside class="buy-rail">
            <div class="buy-card">
                <div class="buy-top">
                    <div class="buy-pricelabel">TruCars price</div>
                    <div class="buy-price">{{ $vehicle->display_price }}</div>
                    <div class="price-disclosure">
                        <div class="pd-row">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 13h18M5 13 7 6h10l2 7M7 17h.01M17 17h.01"/></svg>
                            $149 delivery to your door
                        </div>
                        <div class="pd-note">All-in price — freight, PDI, admin &amp; OMVIC fee included. <b>Excludes HST &amp; licensing.</b></div>
                    </div>
                    @php $payment = $this->paymentEstimate(); @endphp
                    <div class="buy-pay">
                        <div class="big">${{ number_format($payment['biweekly']) }}<span class="per"> /biweekly</span></div>
                        <div class="sub">est. {{ $payment['term'] }} mo · {{ rtrim(rtrim(number_format($payment['apr'], 2), '0'), '.') }}% APR{{ $payment['offer'] ? ' (offer, on approved credit)' : '' }} · $0 down · before HST &amp; licensing</div>
                    </div>
                    @if ($cert = $this->certification())
                        <div class="buy-cert-mini">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6 9 17l-5-5"/></svg>
                            <div>
                                <div class="t">{{ $cert->badge }}</div>
                                <div class="s">{{ $cert->pointCount }}-point inspection</div>
                            </div>
                        </div>
                    @endif
                    @if ($this->offers()->isNotEmpty())
                        <div class="buy-offers">
                            @foreach ($this->offers() as $offer)
                                @include('partials.offer-card', ['offer' => $offer])
                            @endforeach
                            @if ($this->offers()->count() > 1 && $this->offers()->contains(fn ($offer) => ! $offer->combinable))
                                <div class="offer-stack-note">Offers can’t be combined unless stated — the dealership applies the one you choose.</div>
                            @endif
                        </div>
                    @endif
                </div>

                @if ($reserved && ! $vehicle->isReservedBy(auth()->user()))
                    <div class="reserved-note" role="status">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex:none;margin-top:1px"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg>
                        <span>{{ session('vehicle_reserved') ? 'Sorry — someone reserved this car while you were checking out. You haven’t been charged.' : 'Someone has reserved this car.' }} Tap the bell and we’ll email you if their deal doesn’t go through.</span>
                    </div>
                @endif
                <div class="buy-actions">
                    @if ($reserved)
                        @if ($vehicle->isReservedBy(auth()->user()))
                            <a href="{{ route('garage') }}" wire:navigate class="btn btn-primary">You reserved this car — open My Garage</a>
                        @else
                            <span class="btn btn-reserved" aria-disabled="true">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
                                Reserved
                            </span>
                            @if ($this->favouriteIsVisible())
                                @include('partials.vehicle-bell')
                            @endif
                        @endif
                    @else
                    <a href="{{ route('checkout', $vehicle) }}" wire:navigate class="btn btn-primary">
                        Reserve my car now
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                    </a>
                    @endif
                    @if ($this->favouriteIsVisible())
                        <button type="button"
                                class="btn btn-save"
                                x-data="{ saved: @js($this->isSaved()) }"
                                :class="{ 'is-saved': saved }"
                                x-on:click="saved = !saved; $wire.toggleFavourite()"
                                :aria-pressed="saved">
                            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 14c1.5-1.5 3-3.3 3-5.5A4.5 4.5 0 0 0 12 5 4.5 4.5 0 0 0 2 8.5c0 2.2 1.5 4 3 5.5l7 7Z"/></svg>
                            <span x-text="saved ? 'Saved' : 'Save for later'"></span>
                        </button>
                    @endif
                </div>

                <div class="buy-assure">
                    <div class="ba"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6 9 17l-5-5"/></svg> Fully refundable $150 deposit until delivery</div>
                    <div class="ba"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6 9 17l-5-5"/></svg> Home delivery or dealership pickup</div>
                    <div class="ba"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6 9 17l-5-5"/></svg> Trade-in &amp; financing built into checkout</div>
                </div>
            </div>
        </aside>
    </div>

    <!-- mobile-only sticky action bar: price + biweekly on top, full-width Reserve pinned to the bottom -->
    <div class="vdp-stickybar">
        <div class="sb-line">
            <span class="sb-amount">{{ $vehicle->display_price }}</span>
            <span class="sb-bw">or <b>${{ number_format($vehicle->estimated_biweekly) }}</b>/biweekly</span>
            <span class="sb-allin">All-in · +HST &amp; lic.</span>
        </div>
        <div class="sb-actions">
            @if ($this->favouriteIsVisible())
                <button type="button"
                        class="sb-save"
                        x-data="{ saved: @js($this->isSaved()) }"
                        :class="{ 'is-saved': saved }"
                        x-on:click="saved = !saved; $wire.toggleFavourite()"
                        :aria-pressed="saved"
                        aria-label="Save this car">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 14c1.5-1.5 3-3.3 3-5.5A4.5 4.5 0 0 0 12 5 4.5 4.5 0 0 0 2 8.5c0 2.2 1.5 4 3 5.5l7 7Z"/></svg>
                </button>
            @endif
            @if ($reserved && ! $vehicle->isReservedBy(auth()->user()) && $this->favouriteIsVisible())
                @include('partials.vehicle-bell', ['compact' => true])
            @elseif ($reserved)
                <span class="btn btn-reserved sb-cta" aria-disabled="true">Reserved</span>
            @else
            <a href="{{ route('checkout', $vehicle) }}" wire:navigate class="btn btn-primary sb-cta">
                Reserve my car now
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
            </a>
            @endif
        </div>
    </div>

    <style>
        [x-cloak] { display:none !important; }
        .btn-reserved { background:var(--bg-2); color:var(--ink-3); border:1.5px solid var(--line-strong); cursor:not-allowed; display:inline-flex; align-items:center; justify-content:center; gap:8px; }
        .btn-bell { display:inline-flex; align-items:center; justify-content:center; gap:8px; border:1.5px solid var(--ink); background:#fff; color:var(--ink); font-weight:700; }
        .btn-bell:hover { background:var(--ink); color:#fff; }
        .btn-bell.on { border-color:#0B7355; background:#fff; color:#0B7355; }
        .btn-bell.on svg { animation:bell-ring .6s ease 1; transform-origin:50% 10%; }
        @keyframes bell-ring { 20% { transform:rotate(14deg); } 45% { transform:rotate(-12deg); } 70% { transform:rotate(7deg); } 100% { transform:rotate(0); } }
        .reserved-note { display:flex; gap:10px; align-items:flex-start; background:#FFF8DD; border-left:4px solid #C59A00; color:#5C3D00; padding:12px 14px; font-size:14px; line-height:1.45; margin:0 20px 14px; }

        .vdp-back { display:inline-flex; align-items:center; gap:7px; font-size:14px; font-weight:600; color:var(--ink-2); margin-bottom:16px; text-decoration:none; }
        .vdp-back:hover { color:var(--ink); }
        .vdp-grid { display:grid; grid-template-columns:minmax(0, 1fr) 380px; gap:44px; align-items:start; }
        .vdp-main { min-width:0; }

        /* gallery: the photos, square-cornered, with flat solid controls */
        .gallery { position:relative; }
        .gallery-pills { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:12px; }
        .gpill { display:inline-flex; align-items:center; gap:7px; padding:6px 12px; border-radius:3px; border:1.5px solid var(--line-strong); background:#fff; font-size:14px; font-weight:600; color:var(--ink-2); cursor:pointer; }
        .gpill:hover { border-color:var(--ink); color:var(--ink); }
        .gpill.on { background:var(--ink); border-color:var(--ink); color:#fff; }
        .gpill-count { font-size:12.5px; color:var(--ink-3); }
        .gpill.on .gpill-count { color:rgba(255,255,255,.7); }

        .gallery-hero { height:480px; border-radius:3px; background:var(--bg-2); position:relative; overflow:hidden; touch-action:pan-y; user-select:none; }
        /* dealer photos carry a vendor badge along the bottom edge; the frame trims it off (fullscreen shows the whole photo) */
        .gallery-img { position:absolute; top:0; left:0; width:100%; height:110%; object-fit:cover; object-position:center top; display:block; -webkit-user-drag:none; }
        .gallery-badges { position:absolute; top:0; left:0; z-index:2; display:flex; flex-direction:column; align-items:flex-start; pointer-events:none; }
        .gallery-badge { display:inline-flex; align-items:center; gap:7px; background:#fff; padding:6px 12px 6px 10px; font-family:var(--font-display); font-size:16px; font-weight:600; text-transform:uppercase; color:var(--ink); }
        .gallery-badge .dot { width:16px; height:16px; border-radius:2px; background:var(--good); display:grid; place-items:center; }
        .gallery-badge.reserved { background:var(--ink); color:#fff; }
        .gallery-badge.reserved .dot { background:transparent; }

        .ghero-fs { position:absolute; z-index:3; top:10px; right:10px; width:40px; height:40px; border:none; border-radius:3px; background:var(--ink); color:#fff; display:grid; place-items:center; cursor:pointer; }
        .ghero-fs:hover { background:var(--primary); }
        .ghero-arrow { position:absolute; z-index:3; top:50%; transform:translateY(-50%); width:44px; height:44px; border:none; border-radius:3px; background:#fff; color:var(--ink); display:grid; place-items:center; cursor:pointer; opacity:0; transition:opacity .16s ease; }
        .gallery-hero:hover .ghero-arrow { opacity:1; }
        .ghero-arrow:hover { background:var(--ink); color:#fff; }
        .ghero-arrow.left { left:10px; }
        .ghero-arrow.right { right:10px; }
        .ghero-counter { position:absolute; z-index:3; bottom:10px; right:10px; padding:6px 10px; border-radius:3px; background:var(--ink); color:#fff; font-family:var(--font-display); font-size:15px; font-weight:600; }

        .ghero-hotspot { position:absolute; z-index:4; transform:translate(-50%,-50%); width:30px; height:30px; border:none; background:transparent; cursor:pointer; padding:0; }
        .hs-dot { display:block; width:18px; height:18px; margin:6px; border-radius:50%; background:var(--primary); border:3px solid #fff; animation:hsPulse 2s ease-in-out infinite; }
        @keyframes hsPulse { 0%, 100% { transform:scale(1); } 50% { transform:scale(1.2); } }
        .hs-pop { position:absolute; bottom:130%; left:50%; transform:translateX(-50%); white-space:nowrap; background:var(--ink); color:#fff; font-size:13px; font-weight:600; padding:7px 11px; border-radius:3px; z-index:5; }
        .hs-pop::after { content:''; position:absolute; top:100%; left:50%; transform:translateX(-50%); border:6px solid transparent; border-top-color:var(--ink); }

        .gallery-thumbs-wrap { display:flex; align-items:center; gap:6px; margin-top:8px; }
        .gthumb-nav { flex:0 0 auto; width:30px; height:64px; border-radius:3px; border:1.5px solid var(--line-strong); background:#fff; color:var(--ink); display:grid; place-items:center; cursor:pointer; }
        .gthumb-nav:hover { border-color:var(--ink); }
        .gallery-thumbs { display:flex; gap:6px; overflow-x:auto; scroll-behavior:smooth; scrollbar-width:thin; flex:1; padding-bottom:2px; }
        .gallery-thumbs::-webkit-scrollbar { height:6px; }
        .gallery-thumbs::-webkit-scrollbar-thumb { background:var(--line-strong); border-radius:3px; }
        .gthumb { flex:0 0 96px; height:64px; border-radius:2px; background:var(--bg-2); border:none; cursor:pointer; overflow:hidden; padding:0; opacity:.6; }
        .gthumb:hover { opacity:1; }
        .gthumb img { width:100%; height:100%; object-fit:cover; display:block; }
        .gthumb.on { opacity:1; box-shadow:inset 0 -4px 0 var(--ink); }
        .gthumb.on img { height:calc(100% - 4px); }

        .gallery-conditions { margin-top:14px; border-top:2px solid var(--ink); }
        .gc-head { display:flex; align-items:center; gap:8px; font-family:var(--font-display); font-size:16px; font-weight:700; text-transform:uppercase; color:var(--ink); padding:10px 0 4px; }
        .gc-head svg { color:#C59A00; }
        .gc-row { display:flex; align-items:center; gap:9px; width:100%; padding:10px 0; border:none; border-bottom:1px solid var(--line-strong); background:none; font-size:14px; font-weight:600; color:var(--ink); cursor:pointer; text-align:left; }
        .gc-row:hover { color:var(--primary-press); }
        .gc-row > svg:first-child { color:#C59A00; flex-shrink:0; }
        .gc-row .gc-go { margin-left:auto; color:var(--ink-3); flex-shrink:0; }

        .gallery-fs { position:fixed; inset:0; z-index:200; display:flex; flex-direction:column; background:#050B1E; }
        .gfs-bar { display:flex; align-items:center; gap:14px; padding:calc(14px + env(safe-area-inset-top)) 20px 14px; color:#fff; }
        .gfs-counter { font-family:var(--font-display); font-size:17px; font-weight:600; flex-shrink:0; }
        .gfs-pills { display:flex; gap:6px; flex-wrap:wrap; }
        .gfs-pill { padding:5px 11px; border-radius:3px; border:1.5px solid rgba(255,255,255,.25); background:transparent; color:rgba(255,255,255,.82); font-size:13.5px; font-weight:600; cursor:pointer; }
        .gfs-pill:hover { border-color:#fff; color:#fff; }
        .gfs-pill.on { background:#fff; border-color:#fff; color:var(--ink); }
        .gfs-close { margin-left:auto; width:42px; height:42px; flex-shrink:0; border-radius:3px; border:1.5px solid rgba(255,255,255,.3); background:transparent; color:#fff; display:grid; place-items:center; cursor:pointer; }
        .gfs-close:hover { background:#fff; color:var(--ink); }
        .gfs-stage { flex:1; position:relative; min-height:0; display:grid; place-items:center; overflow:hidden; padding:0 16px; }
        .gfs-img { max-width:94%; max-height:100%; object-fit:contain; touch-action:none; will-change:transform; transition:transform .08s ease-out; -webkit-user-drag:none; user-select:none; }
        .gfs-arrow { position:absolute; z-index:2; top:50%; transform:translateY(-50%); width:48px; height:48px; border-radius:3px; border:1.5px solid rgba(255,255,255,.3); background:transparent; color:#fff; display:grid; place-items:center; cursor:pointer; }
        .gfs-arrow:hover { background:#fff; color:var(--ink); }
        .gfs-arrow.left { left:20px; }
        .gfs-arrow.right { right:20px; }
        .gfs-hint { text-align:center; color:rgba(255,255,255,.5); font-size:12.5px; padding:10px 16px 4px; }
        .gfs-thumbs { display:flex; gap:6px; overflow-x:auto; padding:12px 20px calc(14px + env(safe-area-inset-bottom)); scrollbar-width:thin; }
        .gfs-thumbs::-webkit-scrollbar { height:6px; }
        .gfs-thumbs::-webkit-scrollbar-thumb { background:rgba(255,255,255,.25); border-radius:3px; }
        .gfs-thumb { flex:0 0 88px; height:56px; border-radius:2px; overflow:hidden; border:2px solid transparent; background:#222; cursor:pointer; padding:0; opacity:.6; }
        .gfs-thumb img { width:100%; height:100%; object-fit:cover; display:block; }
        .gfs-thumb.on { border-color:#fff; opacity:1; }

        /* title + the spec strip, ruled like the top of a window sticker */
        .vdp-titlerow { margin-top:24px; }
        .vdp-h1 { font-family:var(--font-display); font-size:clamp(32px, 3.6vw, 46px); font-weight:700; line-height:1; text-transform:uppercase; margin:0; }
        .vdp-trim { color:var(--ink-2); font-size:17px; margin:6px 0 0; }
        .vdp-quickmeta { display:grid; grid-template-columns:repeat(auto-fit, minmax(120px, 1fr)); margin:20px 0 0; border-top:2px solid var(--ink); border-bottom:2px solid var(--ink); }
        .qm { padding:10px 14px 11px; }
        .qm:first-child { padding-left:0; }
        .qm + .qm { border-left:1px solid var(--line-strong); }
        .qm dt { font-size:13px; color:var(--ink-3); }
        .qm dd { margin:2px 0 0; font-family:var(--font-display); font-size:20px; font-weight:700; line-height:1.15; }

        .vdp-section { padding:34px 0 0; }
        .vdp-section h2 { font-family:var(--font-display); font-size:26px; font-weight:700; text-transform:uppercase; margin:0 0 10px; }

        /* the dealer's inspection checklist, as a ruled sheet with green ticks */
        .cert-head { padding:0 0 14px; }
        .cert-headtext { min-width:0; }
        .cert-head h3 { margin:0; font-family:var(--font-display); font-size:26px; font-weight:700; text-transform:uppercase; }
        .cert-head .cert-by { font-size:15px; font-weight:600; color:#0B7355; margin-top:2px; }
        .cert-head p { font-size:15px; color:var(--ink-2); margin:8px 0 0; line-height:1.55; max-width:640px; }
        .cben-count { font-family:var(--font-display); font-size:16px; font-weight:600; color:var(--ink-3); margin-left:6px; }
        .cben-points { margin:4px 0 0; padding-left:0; list-style:none; line-height:1.55; }
        .cben-points li { padding:2px 0; }
        .cert-block:not(.is-expanded) .cben-points li:nth-child(n+4) { display:none; }
        .cert-benefits { display:grid; grid-template-columns:1fr 1fr; column-gap:32px; border-top:2px solid var(--ink); }
        .cben { padding:14px 0; display:flex; gap:10px; align-items:flex-start; border-bottom:1px solid var(--line-strong); }
        .cben .ic { color:var(--good); flex-shrink:0; margin-top:3px; }
        .cben .t { font-size:15.5px; font-weight:700; }
        .cben .s { font-size:14px; color:var(--ink-2); margin-top:2px; line-height:1.45; }
        .cert-more { display:inline-flex; align-items:center; gap:7px; padding:14px 0 0; background:none; font-size:15px; font-weight:700; color:var(--ink); text-decoration:underline; text-underline-offset:3px; }
        .cert-more-chev { transition:transform .2s ease; }
        .cert-more-chev.is-up { transform:rotate(180deg); }

        .specs { display:grid; grid-template-columns:1fr 1fr; column-gap:32px; border-top:2px solid var(--ink); }
        .spec { padding:11px 0; display:flex; justify-content:space-between; gap:12px; font-size:15px; border-bottom:1px solid var(--line-strong); }
        .spec .k { color:var(--ink-2); }
        .spec .v { font-weight:600; text-align:right; overflow-wrap:anywhere; }

        .dealer-card { border-top:2px solid var(--ink); padding-top:14px; }
        .dealer-top { display:block; }
        .dealer-name { font-family:var(--font-display); font-size:24px; font-weight:700; }
        .dealer-sub { font-size:15px; color:var(--ink-2); margin-top:4px; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
        .dealer-sub .dot-sep { width:4px; height:4px; background:var(--ink-3); }

        /* the buy box is the window sticker: a heavy frame, the price, then ruled lines */
        .buy-rail { position:sticky; top:90px; }
        .buy-card { background:#fff; border:3px solid var(--ink); }
        .buy-top { padding:18px 20px 16px; }
        .buy-pricelabel { font-family:var(--font-display); font-size:16px; font-weight:600; text-transform:uppercase; color:var(--ink-2); }
        .buy-price { font-family:var(--font-display); font-size:54px; font-weight:700; line-height:1; margin-top:2px; }
        .price-disclosure { margin-top:14px; border-top:1px dashed var(--line-strong); }
        .pd-row { display:flex; align-items:center; gap:8px; font-size:14.5px; color:var(--ink); font-weight:500; padding:10px 0; border-bottom:1px dashed var(--line-strong); }
        .pd-row svg { color:var(--ink-3); flex-shrink:0; }
        .pd-note { padding:10px 0; font-size:13.5px; color:var(--ink-2); line-height:1.5; border-bottom:1px dashed var(--line-strong); }
        .pd-note b { color:var(--ink); font-weight:700; }
        .buy-pay { padding:10px 0; border-bottom:1px dashed var(--line-strong); }
        .buy-pay .big { font-family:var(--font-display); font-size:26px; font-weight:700; }
        .buy-pay .big .per { font-family:var(--font-ui); font-size:14px; color:var(--ink-3); font-weight:500; }
        .buy-pay .sub { font-size:13px; color:var(--ink-2); margin-top:2px; }
        .buy-offers { margin-top:14px; display:grid; gap:10px; }
        .offer-stack-note { font-size:13px; color:var(--ink-3); }
        .buy-cert-mini { display:flex; align-items:center; gap:10px; padding:10px 0; border-bottom:1px dashed var(--line-strong); }
        .buy-cert-mini svg { color:var(--good); flex-shrink:0; }
        .buy-cert-mini .t { font-size:14.5px; font-weight:700; color:var(--ink); }
        .buy-cert-mini .s { font-size:13px; color:var(--ink-3); }
        .buy-actions { padding:0 20px 20px; }
        .buy-actions .btn-bell { margin-top:8px; }
        .btn { border-radius:3px; font-weight:700; font-size:15px; padding:14px 20px; display:inline-flex; align-items:center; justify-content:center; gap:9px; width:100%; cursor:pointer; text-decoration:none; }
        .btn-primary { background:var(--primary); color:#fff; border:none; font-family:var(--font-display); font-size:20px; text-transform:uppercase; }
        .btn-primary:hover { background:var(--primary-press); }
        .btn-save { margin-top:8px; background:#fff; color:var(--ink); border:1.5px solid var(--ink); }
        .btn-save:hover { background:var(--ink); color:#fff; }
        .btn-save.is-saved { color:var(--primary-press); border-color:var(--primary-press); background:#fff; }
        .btn-save.is-saved svg { fill:var(--primary); }
        .buy-assure { padding:14px 20px 16px; background:var(--bg-2); border-top:1px solid var(--line-strong); display:flex; flex-direction:column; gap:9px; }
        .ba { display:flex; align-items:flex-start; gap:9px; font-size:14px; color:var(--ink-2); }
        .ba svg { color:var(--good); flex-shrink:0; margin-top:2px; }

        /* sticky bottom action bar — hidden on desktop, shown when the rail goes static */
        .vdp-stickybar {
            display:none;
            position:fixed; left:0; right:0; bottom:0; z-index:50;
            flex-direction:column; gap:8px;
            background:#fff;
            border-top:3px solid var(--ink);
            padding:10px 16px calc(12px + env(safe-area-inset-bottom));
        }
        .vdp-stickybar .sb-line { display:flex; align-items:baseline; flex-wrap:wrap; gap:4px 9px; }
        .vdp-stickybar .sb-amount { font-family:var(--font-display); font-size:26px; font-weight:700; line-height:1; }
        .vdp-stickybar .sb-bw { font-size:13.5px; color:var(--ink-2); font-weight:500; }
        .vdp-stickybar .sb-bw b { color:var(--ink); font-weight:700; }
        .vdp-stickybar .sb-allin { margin-left:auto; font-size:12px; color:var(--ink-3); font-weight:600; white-space:nowrap; }
        .vdp-stickybar .sb-actions { display:flex; align-items:stretch; gap:8px; }
        .vdp-stickybar .sb-save { flex:none; width:52px; border:1.5px solid var(--ink); border-radius:3px; background:#fff; color:var(--ink); display:grid; place-items:center; }
        .vdp-stickybar .sb-save.is-saved { color:var(--primary); border-color:var(--primary); }
        .vdp-stickybar .sb-save.is-saved svg { fill:var(--primary); }
        .vdp-stickybar .sb-cta { flex:1; padding:13px 20px; }

        @media (max-width:1000px) {
            .vdp-grid { grid-template-columns:minmax(0, 1fr); gap:30px; }
            .buy-rail { position:static; }
            .gallery-hero { height:400px; }
            .vdp { padding-bottom:calc(124px + env(safe-area-inset-bottom)); }
            .vdp-stickybar { display:flex; }
        }

        @media (max-width:860px) {
            .vdp-back { margin-bottom:12px; }
            .gallery-hero { height:280px; }
            .gallery-badge { padding:5px 10px 5px 8px; font-size:14.5px; }
            .ghero-arrow { display:none; }
            .gthumb-nav { display:none; }
            .gthumb { flex-basis:84px; height:56px; }
            .gfs-arrow { display:none; }
            .gfs-bar { gap:10px; padding-left:16px; padding-right:16px; }
            .vdp-titlerow { margin-top:18px; }
            .vdp-trim { font-size:15.5px; }
            .vdp-quickmeta { grid-template-columns:1fr 1fr; }
            .qm, .qm:first-child { padding:9px 12px 10px 0; }
            .qm + .qm { border-left:none; }
            .qm:nth-child(n+3) { border-top:1px solid var(--line-strong); }
            .vdp-section { padding-top:28px; }
            .vdp-section h2 { font-size:23px; }
            .cert-head h3 { font-size:22px; }
            .cert-benefits { grid-template-columns:1fr; }
            .cert-head p { display:none; }
            .cben .s { display:none; }
            .cert-block.is-expanded .cben .s { display:block; }
            .specs { grid-template-columns:1fr; }
            .buy-price { font-size:46px; }
        }

        @media (prefers-reduced-motion: reduce) {
            .hs-dot { animation:none; }
            .gfs-img { transition:none; }
            .gallery-thumbs, .gfs-thumbs { scroll-behavior:auto; }
        }
    </style>

    @script
    <script>
        if (! window.__vehicleGalleryRegistered) {
            window.__vehicleGalleryRegistered = true;

            Alpine.data('vehicleGallery', (initialPhotos, initialHotspots) => ({
                photos: initialPhotos || [],
                hotspots: initialHotspots || [],
                activeCategory: 'all',
                currentIndex: 0,

                isFullscreen: false,
                zoomScale: 1,
                panX: 0,
                panY: 0,

                activePointers: {},
                heroDragX: null,
                swipeStartX: 0,
                swipeStartY: 0,
                pinchStartDistance: 0,
                pinchStartScale: 1,

                openHotspot: null,

                init() {
                    this.currentIndex = 0;
                    this.preloadNeighbours();
                },

                destroy() {
                    document.body.style.overflow = '';
                },

                // ----- derived state -----
                get categories() {
                    const counts = {};
                    this.photos.forEach((photo) => {
                        counts[photo.category] = (counts[photo.category] || 0) + 1;
                    });
                    return Object.keys(counts).map((name) => ({ name, count: counts[name] }));
                },

                get showCategoryPills() {
                    return this.categories.length > 1;
                },

                get visiblePhotos() {
                    const tagged = this.photos.map((photo, index) => ({ ...photo, index }));
                    if (this.activeCategory === 'all') return tagged;
                    return tagged.filter((photo) => photo.category === this.activeCategory);
                },

                get visibleCount() {
                    return this.visiblePhotos.length;
                },

                get currentPhoto() {
                    return this.photos[this.currentIndex] || { url: '', category: '' };
                },

                get currentPosition() {
                    const order = this.visiblePhotos.findIndex((photo) => photo.index === this.currentIndex);
                    return order === -1 ? 1 : order + 1;
                },

                get hotspotsForCurrent() {
                    return this.hotspots
                        .map((spot, id) => ({ ...spot, id }))
                        .filter((spot) => spot.photoIndex === this.currentIndex);
                },

                get stageTransform() {
                    return `translate(${this.panX}px, ${this.panY}px) scale(${this.zoomScale})`;
                },

                prettyCategory(name) {
                    return name.charAt(0).toUpperCase() + name.slice(1);
                },

                // ----- navigation -----
                showCategory(name) {
                    this.activeCategory = name;
                    const firstVisible = this.visiblePhotos[0];
                    if (firstVisible) this.goTo(firstVisible.index);
                },

                setCurrent(index) {
                    this.currentIndex = index;
                    this.closeHotspot();
                    this.preloadNeighbours();
                },

                goTo(index) {
                    this.resetZoom();
                    this.setCurrent(index);
                    this.scrollActiveThumbIntoView();
                },

                stepWithin(direction) {
                    if (this.visibleCount === 0) return;
                    const order = this.visiblePhotos.findIndex((photo) => photo.index === this.currentIndex);
                    const safeOrder = order === -1 ? 0 : order;
                    const nextOrder = (safeOrder + direction + this.visibleCount) % this.visibleCount;
                    this.setCurrent(this.visiblePhotos[nextOrder].index);
                },

                next() {
                    this.resetZoom();
                    this.stepWithin(1);
                    this.scrollActiveThumbIntoView();
                },

                prev() {
                    this.resetZoom();
                    this.stepWithin(-1);
                    this.scrollActiveThumbIntoView();
                },

                preloadNeighbours() {
                    const order = this.visiblePhotos.findIndex((photo) => photo.index === this.currentIndex);
                    if (order === -1) return;
                    [order - 1, order + 1].forEach((position) => {
                        const wrapped = (position + this.visibleCount) % this.visibleCount;
                        const neighbour = this.visiblePhotos[wrapped];
                        if (neighbour) {
                            const preloader = new Image();
                            preloader.src = neighbour.url;
                        }
                    });
                },

                scrollActiveThumbIntoView() {
                    this.$nextTick(() => {
                        const rail = this.isFullscreen ? this.$refs.fullscreenThumbs : this.$refs.thumbs;
                        if (! rail) return;
                        const active = rail.querySelector('[data-active="true"]');
                        if (active) active.scrollIntoView({ inline: 'center', block: 'nearest', behavior: 'smooth' });
                    });
                },

                // ----- hero swipe: one swipe, one photo -----
                dragStart(event) {
                    this.heroDragX = this.visibleCount > 1 ? event.clientX : null;
                },

                dragEnd(event) {
                    if (this.heroDragX === null) return;
                    const travelled = event.clientX - this.heroDragX;
                    this.heroDragX = null;
                    if (Math.abs(travelled) < 50) return;
                    this.stepWithin(travelled < 0 ? 1 : -1);
                    this.scrollActiveThumbIntoView();
                },

                dragCancel() {
                    this.heroDragX = null;
                },

                // ----- hotspots -----
                toggleHotspot(id) {
                    this.openHotspot = this.openHotspot === id ? null : id;
                },

                closeHotspot() {
                    this.openHotspot = null;
                },

                jumpToHotspot(id) {
                    const spot = this.hotspots[id];
                    if (! spot) return;
                    this.activeCategory = 'all';
                    this.goTo(spot.photoIndex);
                    this.openHotspot = id;
                },

                // ----- fullscreen -----
                openFullscreen() {
                    this.isFullscreen = true;
                    document.body.style.overflow = 'hidden';
                    this.$nextTick(() => {
                        this.scrollActiveThumbIntoView();
                        if (this.$refs.fullscreenClose) this.$refs.fullscreenClose.focus();
                    });
                },

                closeFullscreen() {
                    this.isFullscreen = false;
                    this.resetZoom();
                    document.body.style.overflow = '';
                },

                trapFocus(event) {
                    if (event.key !== 'Tab' || ! this.$refs.fullscreen) return;
                    const focusable = this.$refs.fullscreen.querySelectorAll(
                        'button:not([disabled]), [href], [tabindex]:not([tabindex="-1"])'
                    );
                    if (! focusable.length) return;
                    const first = focusable[0];
                    const last = focusable[focusable.length - 1];
                    if (event.shiftKey && document.activeElement === first) {
                        event.preventDefault();
                        last.focus();
                    } else if (! event.shiftKey && document.activeElement === last) {
                        event.preventDefault();
                        first.focus();
                    }
                },

                // ----- zoom + pan (fullscreen) -----
                applyZoom(scale) {
                    this.zoomScale = Math.min(Math.max(scale, 1), 4);
                    if (this.zoomScale === 1) {
                        this.panX = 0;
                        this.panY = 0;
                    }
                },

                resetZoom() {
                    this.zoomScale = 1;
                    this.panX = 0;
                    this.panY = 0;
                },

                zoomWithWheel(event) {
                    const direction = event.deltaY < 0 ? 1 : -1;
                    this.applyZoom(this.zoomScale + direction * 0.3);
                },

                toggleZoom() {
                    this.zoomScale > 1 ? this.resetZoom() : this.applyZoom(2.4);
                },

                pointerDistance() {
                    const points = Object.values(this.activePointers);
                    if (points.length < 2) return 1;
                    const dx = points[0].x - points[1].x;
                    const dy = points[0].y - points[1].y;
                    return Math.hypot(dx, dy) || 1;
                },

                stagePointerDown(event) {
                    this.activePointers[event.pointerId] = { x: event.clientX, y: event.clientY };
                    const count = Object.keys(this.activePointers).length;
                    if (count === 1) {
                        this.swipeStartX = event.clientX;
                        this.swipeStartY = event.clientY;
                    }
                    if (count === 2) {
                        this.pinchStartDistance = this.pointerDistance();
                        this.pinchStartScale = this.zoomScale;
                    }
                },

                stagePointerMove(event) {
                    const tracked = this.activePointers[event.pointerId];
                    if (! tracked) return;
                    const deltaX = event.clientX - tracked.x;
                    const deltaY = event.clientY - tracked.y;
                    this.activePointers[event.pointerId] = { x: event.clientX, y: event.clientY };
                    const count = Object.keys(this.activePointers).length;
                    if (count === 2) {
                        const ratio = this.pointerDistance() / this.pinchStartDistance;
                        this.applyZoom(this.pinchStartScale * ratio);
                    } else if (count === 1 && this.zoomScale > 1) {
                        this.panX += deltaX;
                        this.panY += deltaY;
                    }
                },

                stagePointerUp(event) {
                    const released = this.activePointers[event.pointerId];
                    const wasOnlyPointer = Object.keys(this.activePointers).length === 1;
                    delete this.activePointers[event.pointerId];
                    if (wasOnlyPointer && this.zoomScale === 1 && released) {
                        const dx = released.x - this.swipeStartX;
                        const dy = released.y - this.swipeStartY;
                        if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy)) {
                            dx < 0 ? this.next() : this.prev();
                        }
                    }
                },
            }));
        }
    </script>
    @endscript
</div>
