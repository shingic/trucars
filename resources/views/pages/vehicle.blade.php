<?php

use App\Models\Vehicle;
use App\Support\CertificationProgram;
use Livewire\Component;

new class extends Component {
    public Vehicle $vehicle;

    /**
     * The resolved certification program for this vehicle, or null when the
     * vehicle isn't certified.
     *
     * Resolved once per request and cached on the component so the template can
     * read it freely without re-running resolveFor() on every reference. This
     * is a plain method (not a #[Computed]) on purpose — computed memoisation
     * has bitten us before with one-step-behind rendering.
     */
    protected ?CertificationProgram $resolvedCertification = null;
    protected bool $certificationResolved = false;

    public function certification(): ?CertificationProgram
    {
        if (! $this->certificationResolved) {
            $this->resolvedCertification = CertificationProgram::resolveFor($this->vehicle);
            $this->certificationResolved = true;
        }

        return $this->resolvedCertification;
    }

    /**
     * The certification benefits minus the powertrain warranty.
     *
     * The warranty is promoted to its own hero strip at the top of the card, so
     * we drop it here to avoid showing it twice. Removing one tile also makes
     * the remaining benefit count even, which keeps the two-column grid full and
     * kills the orphaned empty cell the old layout left behind.
     *
     * Maintenance-type perks (oil changes and the like) are nudged to the end so
     * trust signals such as the CARFAX history read ahead of them — a buyer
     * weighs accident history above free oil changes. The durable home for this
     * order is the benefits array in CertificationProgram itself; this is a
     * display-side guard so the card reads right regardless of source order.
     */
    public function supportingBenefits(): array
    {
        $certification = $this->certification();

        if (! $certification) {
            return [];
        }

        $maintenanceKeywords = ['oil', 'maintenance'];

        return collect($certification->benefits)
            ->reject(fn ($benefit) => str_contains(strtolower($benefit['title']), 'warranty'))
            ->sortBy(function ($benefit) use ($maintenanceKeywords) {
                $title = strtolower($benefit['title']);

                foreach ($maintenanceKeywords as $keyword) {
                    if (str_contains($title, $keyword)) {
                        return 1;
                    }
                }

                return 0;
            })
            ->values()
            ->all();
    }

    /**
     * The ordered photo set the gallery renders, normalized to a stable shape.
     *
     * Photos arrive today as plain URL strings. This maps each one into
     * ['url' => ..., 'category' => ...] and makes the lead photo open the
     * sequence so the drag-to-scrub spin starts on the front of the car.
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
     * Condition markers, sourced from the signed TruCert inspection for this
     * exact VIN. None are wired yet, so the hero renders clean. The shape, for
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
            session()->put('url.intended', url('/cars/' . $this->vehicle->id));
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
    public function favouriteIsVisible(): bool
    {
        $user = auth()->user();

        return $user === null || $user->dealer_id === null;
    }
};
?>

<div class="vdp">
    <a href="/" wire:navigate class="vdp-back">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M11 6l-6 6 6 6"/></svg>
        Back to results
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

                {{-- Hero — drag/swipe to scrub through the frames (pseudo-360) --}}
                <div
                    class="gallery-hero"
                    :class="{ grabbing: isScrubbing }"
                    x-on:pointerdown="scrubStart($event)"
                    x-on:pointermove="scrubMove($event)"
                    x-on:pointerup="scrubEnd()"
                    x-on:pointercancel="scrubEnd()"
                    x-on:pointerleave="scrubEnd()"
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

                    @if ($this->certification())
                        <span class="gallery-badge">
                            <span class="dot"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3"><path d="M20 6 9 17l-5-5"/></svg></span>
                            {{ $this->certification()->shortName }}
                        </span>
                    @endif

                    <button type="button" class="ghero-fs" x-on:click="openFullscreen()" aria-label="Open fullscreen gallery">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/></svg>
                    </button>

                    <button type="button" class="ghero-arrow left" x-show="visibleCount > 1" x-on:click.stop="prev()" aria-label="Previous photo">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="m15 18-6-6 6-6"/></svg>
                    </button>
                    <button type="button" class="ghero-arrow right" x-show="visibleCount > 1" x-on:click.stop="next()" aria-label="Next photo">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="m9 18 6-6-6-6"/></svg>
                    </button>

                    <button type="button" class="ghero-spin" x-show="visibleCount > 2" x-on:click.stop="toggleSpin()" :aria-pressed="isSpinning" aria-label="Auto-rotate photos">
                        <svg x-show="! isSpinning" width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
                        <svg x-show="isSpinning" x-cloak width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M6 5h4v14H6zM14 5h4v14h-4z"/></svg>
                        <span x-text="isSpinning ? 'Spinning' : '360° spin'"></span>
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
                <div class="vdp-quickmeta">
                    <span class="qm"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 8v4l3 2"/></svg> <b>{{ number_format($vehicle->kilometres) }}</b> km</span>
                    @if ($vehicle->drivetrain)<span class="qm"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 13h18M5 13 7 6h10l2 7"/></svg> {{ $vehicle->drivetrain }}</span>@endif
                    @if ($vehicle->fuel_type)<span class="qm">{{ $vehicle->fuel_type }}</span>@endif
                    @if ($vehicle->transmission)<span class="qm">{{ $vehicle->transmission }}</span>@endif
                    @if ($vehicle->colour)<span class="qm">{{ $vehicle->colour }}</span>@endif
                </div>
            </div>

            @if ($this->certification())
                <div class="vdp-section" style="border-top:none; padding-top:4px;">
                    <div class="cert-block" x-data="{ certDetails: false }" :class="{ 'is-expanded': certDetails }">
                        <div class="cert-head">
                            <div class="cert-shield"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><path d="M12 3 4 6v6c0 5 8 9 8 9s8-4 8-9V6Z"/><path d="m9 12 2 2 4-4"/></svg></div>
                            <div class="cert-headtext">
                                <h3>{{ $this->certification()->name }}</h3>
                                <div class="cert-by">Certified by {{ ucwords(strtolower($vehicle->dealer->name)) }}</div>
                                <p>{{ $this->certification()->tagline }} Inspected and certified by {{ ucwords(strtolower($vehicle->dealer->name)) }}'s factory-trained technicians.</p>
                            </div>
                        </div>

                        <div class="cert-warranty">
                            <span class="cw-ic"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3 4 6v6c0 5 8 9 8 9s8-4 8-9V6Z"/><path d="m9 12 2 2 4-4"/></svg></span>
                            <div class="cw-body">
                                <div class="cw-value">{{ $this->certification()->warrantyMonths }} months <span>/</span> {{ number_format($this->certification()->warrantyKilometres) }} km</div>
                                <div class="cw-label">Manufacturer-backed powertrain warranty, from the in-service date</div>
                                <div class="cw-stat">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6"><path d="M20 6 9 17l-5-5"/></svg>
                                    {{ $this->certification()->inspectionPoints }}-point inspection
                                </div>
                            </div>
                        </div>

                        <div class="cert-benefits">
                            @foreach ($this->supportingBenefits() as $benefit)
                                <div class="cben">
                                    <span class="ic"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="{{ $benefit['iconPath'] }}"/></svg></span>
                                    <div>
                                        <div class="t">{{ $benefit['title'] }}</div>
                                        <div class="s">{{ $benefit['detail'] }}</div>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <button type="button" class="cert-more" x-on:click="certDetails = ! certDetails">
                            <span x-text="certDetails ? 'Hide details' : 'What\'s covered'">What's covered</span>
                            <svg class="cert-more-chev" :class="{ 'is-up': certDetails }" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="m6 9 6 6 6-6"/></svg>
                        </button>

                        <div class="cert-foot">
                            <div class="cert-links">
                                <span class="cert-link"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M9 4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2a1 1 0 0 1-1 1h-4a1 1 0 0 1-1-1Z"/></svg> {{ $this->certification()->inspectionPoints }}-point inspection report</span>
                                <span class="cert-link"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12a9 9 0 1 0 9-9 9 9 0 0 0-6.4 2.6L3 8"/><path d="M3 3v5h5M12 7v5l3 2"/></svg> CARFAX history report</span>
                            </div>
                            <span class="cert-note">Signed inspection &amp; CARFAX are attached to this exact VIN.</span>
                        </div>
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
                        <span class="dealer-logo">{{ strtoupper(substr($vehicle->dealer->name, 0, 1)) }}</span>
                        <div>
                            <div class="dealer-name">{{ ucwords(strtolower($vehicle->dealer->name)) }}</div>
                            <div class="dealer-sub">
                                {{ $vehicle->dealer->city }}
                                @if ($vehicle->dealer->omvic_number)
                                    <span class="dot-sep"></span> OMVIC&nbsp;#{{ $vehicle->dealer->omvic_number }}
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
                    <div class="buy-pay">
                        <div class="big">${{ number_format($vehicle->estimated_biweekly) }}<span class="per"> /biweekly</span></div>
                        <div class="sub">est. 72 mo · 7.5% APR · $0 down · before HST &amp; licensing</div>
                    </div>
                    @if ($this->certification())
                        <div class="buy-cert-mini">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3 4 6v6c0 5 8 9 8 9s8-4 8-9V6Z"/></svg>
                            <div>
                                <div class="t">{{ $this->certification()->shortName }}</div>
                                <div class="s">{{ $this->certification()->inspectionPoints }}-pt inspection · {{ $this->certification()->warrantyMonths }}-mo warranty</div>
                            </div>
                        </div>
                    @endif
                </div>

                <div class="buy-actions">
                    <a href="{{ route('checkout', $vehicle) }}" wire:navigate class="btn btn-primary">
                        Reserve my car now
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                    </a>
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
            <a href="{{ route('checkout', $vehicle) }}" wire:navigate class="btn btn-primary sb-cta">
                Reserve my car now
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
            </a>
        </div>
    </div>

    <style>
        [x-cloak] { display:none !important; }

        .vdp-back { display:inline-flex; align-items:center; gap:7px; font-size:13.5px; font-weight:600; color:var(--ink-2); margin-bottom:18px; text-decoration:none; }
        .vdp-back:hover { color:var(--primary); }
        .vdp-grid { display:grid; grid-template-columns:1fr 400px; gap:48px; align-items:start; }
        .vdp-main { min-width:0; }

        .gallery { position:relative; }

        .gallery-pills { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:14px; }
        .gpill { display:inline-flex; align-items:center; gap:7px; padding:8px 14px; border-radius:var(--radius-pill); border:1px solid var(--line); background:var(--card); font-size:13px; font-weight:700; color:var(--ink-2); cursor:pointer; transition:all .15s ease; }
        .gpill:hover { border-color:var(--line-strong); color:var(--ink); }
        .gpill.on { background:var(--ink); border-color:var(--ink); color:#fff; }
        .gpill-count { font-size:11px; font-weight:700; padding:1px 7px; border-radius:var(--radius-pill); background:var(--bg-2); color:var(--ink-3); }
        .gpill.on .gpill-count { background:rgba(255,255,255,.18); color:#fff; }

        .gallery-hero { height:440px; border-radius:var(--radius); background:var(--hero-grad); position:relative; overflow:hidden; cursor:grab; touch-action:pan-y; user-select:none; }
        .gallery-hero.grabbing { cursor:grabbing; }
        .gallery-img { width:100%; height:100%; object-fit:cover; display:block; -webkit-user-drag:none; }
        .gallery-badge { position:absolute; top:18px; left:18px; z-index:2; display:inline-flex; align-items:center; gap:7px; background:rgba(255,255,255,.96); border-radius:var(--radius-pill); padding:8px 15px 8px 10px; font-size:13px; font-weight:700; color:#0E5A43; box-shadow:var(--shadow-md); }
        .gallery-badge .dot { width:18px; height:18px; border-radius:50%; background:var(--good); display:grid; place-items:center; }

        .ghero-fs { position:absolute; z-index:3; top:18px; right:18px; width:40px; height:40px; border:none; border-radius:11px; background:rgba(22,24,29,.55); backdrop-filter:blur(6px); color:#fff; display:grid; place-items:center; cursor:pointer; transition:background .15s ease; }
        .ghero-fs:hover { background:rgba(22,24,29,.82); }

        .ghero-arrow { position:absolute; z-index:3; top:50%; transform:translateY(-50%); width:44px; height:44px; border:none; border-radius:50%; background:rgba(255,255,255,.92); color:var(--ink); display:grid; place-items:center; cursor:pointer; box-shadow:var(--shadow-md); opacity:0; transition:opacity .16s ease, background .15s ease; }
        .gallery-hero:hover .ghero-arrow { opacity:1; }
        .ghero-arrow:hover { background:#fff; }
        .ghero-arrow.left { left:16px; }
        .ghero-arrow.right { right:16px; }

        .ghero-spin { position:absolute; z-index:3; bottom:18px; left:18px; display:inline-flex; align-items:center; gap:7px; padding:9px 14px; border:none; border-radius:var(--radius-pill); background:rgba(22,24,29,.6); backdrop-filter:blur(6px); color:#fff; font-size:12.5px; font-weight:700; cursor:pointer; transition:background .15s ease; }
        .ghero-spin:hover { background:rgba(22,24,29,.82); }
        .ghero-spin[aria-pressed="true"] { background:var(--primary); }

        .ghero-counter { position:absolute; z-index:3; bottom:18px; right:18px; padding:6px 12px; border-radius:var(--radius-pill); background:rgba(22,24,29,.6); backdrop-filter:blur(6px); color:#fff; font-size:12.5px; font-weight:700; letter-spacing:.02em; }

        .ghero-hotspot { position:absolute; z-index:4; transform:translate(-50%,-50%); width:30px; height:30px; border:none; background:transparent; cursor:pointer; padding:0; }
        .hs-dot { display:block; width:18px; height:18px; margin:6px; border-radius:50%; background:var(--primary); box-shadow:0 0 0 4px rgba(245,99,31,.35), var(--shadow-md); animation:hsPulse 2s ease-in-out infinite; }
        @keyframes hsPulse { 0%, 100% { box-shadow:0 0 0 4px rgba(245,99,31,.35), var(--shadow-md); } 50% { box-shadow:0 0 0 9px rgba(245,99,31,.10), var(--shadow-md); } }
        .hs-pop { position:absolute; bottom:130%; left:50%; transform:translateX(-50%); white-space:nowrap; background:var(--ink); color:#fff; font-size:12px; font-weight:600; padding:7px 11px; border-radius:9px; box-shadow:var(--shadow-md); z-index:5; }
        .hs-pop::after { content:''; position:absolute; top:100%; left:50%; transform:translateX(-50%); border:6px solid transparent; border-top-color:var(--ink); }

        .gallery-thumbs-wrap { display:flex; align-items:center; gap:8px; margin-top:12px; }
        .gthumb-nav { flex:0 0 auto; width:32px; height:66px; border-radius:10px; border:1px solid var(--line); background:var(--card); color:var(--ink-2); display:grid; place-items:center; cursor:pointer; }
        .gthumb-nav:hover { border-color:var(--primary); color:var(--primary); }
        .gallery-thumbs { display:flex; gap:10px; overflow-x:auto; scroll-behavior:smooth; scrollbar-width:thin; flex:1; padding-bottom:2px; }
        .gallery-thumbs::-webkit-scrollbar { height:6px; }
        .gallery-thumbs::-webkit-scrollbar-thumb { background:var(--line-strong); border-radius:3px; }
        .gthumb { flex:0 0 108px; height:66px; border-radius:12px; background:var(--bg-2); border:1.5px solid var(--line); cursor:pointer; overflow:hidden; padding:0; }
        .gthumb img { width:100%; height:100%; object-fit:cover; display:block; }
        .gthumb.on { border-color:var(--primary); }

        .gallery-conditions { margin-top:14px; border:1px solid var(--line); border-radius:14px; padding:14px 16px; }
        .gc-head { display:flex; align-items:center; gap:8px; font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--ink-3); margin-bottom:10px; }
        .gc-head svg { color:var(--primary); }
        .gc-row { display:flex; align-items:center; gap:9px; width:100%; padding:9px 11px; border:none; background:var(--bg-2); border-radius:10px; font-size:13px; font-weight:600; color:var(--ink); cursor:pointer; text-align:left; margin-bottom:6px; transition:background .15s ease; }
        .gc-row:last-child { margin-bottom:0; }
        .gc-row:hover { background:var(--good-soft); }
        .gc-row > svg:first-child { color:var(--primary); flex-shrink:0; }
        .gc-row .gc-go { margin-left:auto; color:var(--ink-3); flex-shrink:0; }

        .gallery-fs { position:fixed; inset:0; z-index:200; display:flex; flex-direction:column; background:rgba(16,18,23,.97); backdrop-filter:blur(4px); }
        .gfs-bar { display:flex; align-items:center; gap:14px; padding:calc(16px + env(safe-area-inset-top)) 20px 16px; color:#fff; }
        .gfs-counter { font-size:14px; font-weight:700; letter-spacing:.02em; flex-shrink:0; }
        .gfs-pills { display:flex; gap:8px; flex-wrap:wrap; }
        .gfs-pill { padding:6px 12px; border-radius:var(--radius-pill); border:1px solid rgba(255,255,255,.2); background:transparent; color:rgba(255,255,255,.82); font-size:12.5px; font-weight:700; cursor:pointer; transition:all .15s ease; }
        .gfs-pill:hover { border-color:rgba(255,255,255,.4); color:#fff; }
        .gfs-pill.on { background:#fff; border-color:#fff; color:var(--ink); }
        .gfs-close { margin-left:auto; width:42px; height:42px; flex-shrink:0; border-radius:12px; border:1px solid rgba(255,255,255,.2); background:rgba(255,255,255,.08); color:#fff; display:grid; place-items:center; cursor:pointer; transition:background .15s ease; }
        .gfs-close:hover { background:rgba(255,255,255,.18); }
        .gfs-stage { flex:1; position:relative; min-height:0; display:grid; place-items:center; overflow:hidden; padding:0 16px; }
        .gfs-img { max-width:94%; max-height:100%; object-fit:contain; touch-action:none; will-change:transform; transition:transform .08s ease-out; -webkit-user-drag:none; user-select:none; }
        .gfs-arrow { position:absolute; z-index:2; top:50%; transform:translateY(-50%); width:50px; height:50px; border-radius:50%; border:1px solid rgba(255,255,255,.2); background:rgba(255,255,255,.1); color:#fff; display:grid; place-items:center; cursor:pointer; transition:background .15s ease; }
        .gfs-arrow:hover { background:rgba(255,255,255,.22); }
        .gfs-arrow.left { left:20px; }
        .gfs-arrow.right { right:20px; }
        .gfs-hint { text-align:center; color:rgba(255,255,255,.5); font-size:11.5px; padding:10px 16px 4px; }
        .gfs-thumbs { display:flex; gap:8px; overflow-x:auto; padding:12px 20px calc(14px + env(safe-area-inset-bottom)); scrollbar-width:thin; }
        .gfs-thumbs::-webkit-scrollbar { height:6px; }
        .gfs-thumbs::-webkit-scrollbar-thumb { background:rgba(255,255,255,.25); border-radius:3px; }
        .gfs-thumb { flex:0 0 88px; height:56px; border-radius:9px; overflow:hidden; border:1.5px solid rgba(255,255,255,.18); background:#222; cursor:pointer; padding:0; }
        .gfs-thumb img { width:100%; height:100%; object-fit:cover; display:block; }
        .gfs-thumb.on { border-color:#fff; }

        .vdp-titlerow { margin-top:26px; padding-bottom:22px; border-bottom:1px solid var(--line); }
        .vdp-h1 { font-size:30px; font-weight:800; letter-spacing:-.025em; margin:0; }
        .vdp-trim { color:var(--ink-2); font-size:16px; margin:4px 0 0; }
        .vdp-quickmeta { display:flex; flex-wrap:wrap; gap:10px; margin-top:16px; }
        .qm { display:inline-flex; align-items:center; gap:7px; background:var(--bg-2); border-radius:10px; padding:9px 13px; font-size:13px; color:var(--ink); font-weight:500; }
        .qm svg { color:var(--ink-3); }
        .qm b { font-weight:700; }

        .vdp-section { padding:26px 0; border-bottom:1px solid var(--line); }
        .vdp-section h2 { font-size:20px; font-weight:800; letter-spacing:-.015em; margin:0 0 16px; }

        /* White card, hairline border, thin green top accent and the faintest
           green tint fading out — enough to read as a trust zone without the
           old wall-of-green. Green is now an accent (shield, warranty, checks),
           not the surface. */
        .cert-block { background:linear-gradient(180deg, rgba(18,184,134,.04), var(--card) 130px); border:1px solid var(--line-strong); border-top:3px solid var(--good); border-radius:var(--radius); overflow:hidden; }

        .cert-head { padding:22px 24px 18px; display:flex; align-items:flex-start; gap:16px; }
        .cert-shield { width:46px; height:46px; border-radius:13px; background:var(--good); display:grid; place-items:center; flex-shrink:0; box-shadow:0 8px 18px rgba(18,184,134,.28); }
        .cert-headtext { min-width:0; }
        .cert-head h3 { margin:0; font-size:18px; font-weight:800; letter-spacing:-.01em; }
        .cert-head .cert-by { font-size:13px; font-weight:700; color:var(--good); margin-top:3px; }
        .cert-head p { font-size:13.5px; color:var(--ink-2); margin:9px 0 0; line-height:1.55; }

        /* Warranty hero — the headline benefit, given real weight. Sits on a
           near-white off-white panel (a touch of contrast against the white
           card, not more green), with a hairline border and the faintest lift.
           The green icon is the single green anchor here. */
        .cert-warranty { margin:0 24px 20px; display:flex; align-items:center; gap:15px; padding:18px 20px; border-radius:14px; background:#F5FBF8; border:1px solid rgba(18,184,134,.16); box-shadow:0 1px 2px rgba(16,24,40,.04); }
        .cw-ic { width:40px; height:40px; border-radius:11px; background:var(--good); color:#fff; display:grid; place-items:center; flex-shrink:0; }
        .cw-body { flex:1; min-width:0; }
        .cw-value { font-size:23px; font-weight:800; letter-spacing:-.02em; color:var(--ink); line-height:1.1; }
        .cw-value span { color:var(--ink-3); font-weight:600; margin:0 2px; }
        .cw-label { font-size:12.5px; color:var(--ink-2); margin-top:4px; line-height:1.4; }
        .cw-stat { display:inline-flex; align-items:center; gap:6px; margin-top:9px; font-size:12.5px; font-weight:700; color:#0E5A43; }
        .cw-stat svg { color:var(--good); flex-shrink:0; }

        /* Supporting benefits — quieter than the hero. Neutral icon tiles keep
           the green load down; this is no longer a grid of green boxes. */
        .cert-benefits { display:grid; grid-template-columns:1fr 1fr; gap:1px; background:var(--line); border-top:1px solid var(--line); }
        .cben { background:var(--card); padding:15px 24px; display:flex; gap:12px; align-items:flex-start; }
        .cben .ic { width:30px; height:30px; border-radius:9px; background:var(--bg-2); color:var(--ink-2); display:grid; place-items:center; flex-shrink:0; }
        .cben .t { font-size:13.5px; font-weight:700; }
        .cben .s { font-size:12px; color:var(--ink-2); margin-top:2px; line-height:1.45; }

        .cert-foot { padding:16px 24px 18px; display:flex; flex-direction:column; align-items:center; gap:10px; background:var(--bg-2); border-top:1px solid var(--line); }
        .cert-links { display:flex; flex-wrap:wrap; justify-content:center; gap:10px; }
        .cert-link { display:inline-flex; align-items:center; gap:7px; font-size:13px; font-weight:700; color:var(--ink); background:var(--card); border:1px solid var(--line-strong); border-radius:10px; padding:9px 14px; }
        .cert-link svg { color:var(--good); }
        .cert-note { font-size:11.5px; color:var(--ink-3); text-align:center; }

        .cert-more { display:none; align-items:center; justify-content:center; gap:7px; width:100%; padding:13px; background:var(--card); border-top:1px solid var(--line); font-size:13.5px; font-weight:700; color:var(--good); }
        .cert-more-chev { transition:transform .2s ease; }
        .cert-more-chev.is-up { transform:rotate(180deg); }

        .specs { display:grid; grid-template-columns:1fr 1fr; gap:1px; background:var(--line); border:1px solid var(--line); border-radius:14px; overflow:hidden; }
        .spec { background:var(--card); padding:13px 17px; display:flex; justify-content:space-between; gap:12px; font-size:13.5px; }
        .spec .k { color:var(--ink-2); }
        .spec .v { font-weight:600; text-align:right; }

        .dealer-card { border:1px solid var(--line); border-radius:var(--radius); overflow:hidden; }
        .dealer-top { padding:20px 22px; display:flex; align-items:center; gap:15px; }
        .dealer-logo { width:52px; height:52px; border-radius:14px; background:var(--ink); color:#fff; display:grid; place-items:center; font-weight:800; font-size:18px; flex-shrink:0; }
        .dealer-name { font-size:17px; font-weight:800; letter-spacing:-.01em; }
        .dealer-sub { font-size:13px; color:var(--ink-2); margin-top:4px; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
        .dealer-sub .dot-sep { width:3px; height:3px; border-radius:50%; background:var(--ink-3); }

        .buy-rail { position:sticky; top:90px; }
        .buy-card { background:var(--card); border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow-md); overflow:hidden; }
        .buy-top { padding:22px 22px 18px; }
        .buy-pricelabel { font-size:12px; font-weight:600; letter-spacing:.06em; text-transform:uppercase; color:var(--ink-3); }
        .buy-price { font-size:34px; font-weight:800; letter-spacing:-.03em; margin-top:2px; }
        .price-disclosure { margin-top:14px; }
        .pd-row { display:flex; align-items:center; gap:8px; font-size:13px; color:var(--ink-2); font-weight:500; }
        .pd-row svg { color:var(--ink-3); flex-shrink:0; }
        .pd-note { margin-top:11px; padding-top:11px; border-top:1px solid var(--line); font-size:11.5px; color:var(--ink-3); line-height:1.5; }
        .pd-note b { color:var(--ink-2); font-weight:700; }
        .buy-pay { margin-top:16px; padding:14px 16px; background:var(--bg-2); border-radius:14px; }
        .buy-pay .big { font-size:20px; font-weight:800; letter-spacing:-.02em; }
        .buy-pay .big .per { font-size:13px; color:var(--ink-3); font-weight:500; }
        .buy-pay .sub { font-size:12px; color:var(--ink-2); margin-top:3px; }
        .buy-cert-mini { margin-top:14px; display:flex; align-items:center; gap:10px; background:var(--good-soft); border-radius:12px; padding:11px 13px; }
        .buy-cert-mini svg { color:var(--good); flex-shrink:0; }
        .buy-cert-mini .t { font-size:12.5px; font-weight:700; color:#0E5A43; }
        .buy-cert-mini .s { font-size:11px; color:#12805F; }
        .buy-actions { padding:0 22px 22px; }
        .btn { border-radius:var(--radius-pill); font-weight:700; font-size:15px; padding:15px 22px; display:inline-flex; align-items:center; justify-content:center; gap:9px; transition:all .16s ease; width:100%; cursor:pointer; text-decoration:none; }
        .btn-primary { background:var(--primary); color:#fff; box-shadow:var(--shadow-primary); border:none; }
        .btn-primary:hover { background:var(--primary-press); transform:translateY(-1px); }
        .btn-save { margin-top:10px; background:var(--card); color:var(--ink-2); border:1.5px solid var(--line-strong); }
        .btn-save:hover { border-color:var(--primary); color:var(--primary); }
        .btn-save.is-saved { background:var(--primary-soft); border-color:var(--primary-line); color:var(--primary); }
        .btn-save.is-saved svg { fill:var(--primary); }
        .buy-assure { padding:16px 22px; border-top:1px solid var(--line); display:flex; flex-direction:column; gap:11px; }
        .ba { display:flex; align-items:center; gap:10px; font-size:13px; color:var(--ink-2); }
        .ba svg { color:var(--good); flex-shrink:0; }

        /* sticky bottom action bar — hidden on desktop, shown when the rail goes static */
        .vdp-stickybar {
            display:none;
            position:fixed; left:0; right:0; bottom:0; z-index:50;
            flex-direction:column; gap:10px;
            background:rgba(255,255,255,.97); backdrop-filter:saturate(160%) blur(14px);
            border-top:1px solid var(--line);
            box-shadow:0 -10px 34px rgba(22,24,29,.12);
            padding:12px 16px calc(12px + env(safe-area-inset-bottom));
        }
        .vdp-stickybar .sb-line { display:flex; align-items:baseline; flex-wrap:wrap; gap:5px 9px; }
        .vdp-stickybar .sb-amount { font-size:20px; font-weight:800; letter-spacing:-.025em; }
        .vdp-stickybar .sb-bw { font-size:13px; color:var(--ink-2); font-weight:500; }
        .vdp-stickybar .sb-bw b { color:var(--ink); font-weight:700; }
        .vdp-stickybar .sb-allin { margin-left:auto; font-size:11px; color:var(--ink-3); font-weight:600; white-space:nowrap; }
        .vdp-stickybar .sb-actions { display:flex; align-items:stretch; gap:10px; }
        .vdp-stickybar .sb-save { flex:none; width:54px; border:1.5px solid var(--line-strong); border-radius:var(--radius-pill); background:var(--card); color:var(--ink-2); display:grid; place-items:center; transition:border-color .15s ease, color .15s ease, background .15s ease; }
        .vdp-stickybar .sb-save.is-saved { border-color:var(--primary-line); background:var(--primary-soft); color:var(--primary); }
        .vdp-stickybar .sb-save.is-saved svg { fill:var(--primary); }
        .vdp-stickybar .sb-cta { flex:1; padding:15px 22px; font-size:15.5px; }

        @media (max-width:1000px) {
            .vdp-grid { grid-template-columns:1fr; gap:30px; }
            .buy-rail { position:static; }
            .gallery-hero { height:380px; }
            .vdp { padding-bottom:calc(124px + env(safe-area-inset-bottom)); }
            .vdp-stickybar { display:flex; }
        }

        @media (max-width:860px) {
            .vdp-back { margin-bottom:14px; }
            .gallery-hero { height:290px; border-radius:var(--radius-sm); }
            .gallery-badge { top:12px; left:12px; padding:6px 12px 6px 8px; font-size:12px; }
            .ghero-fs { top:12px; right:12px; }
            .ghero-arrow { display:none; }
            .ghero-spin { bottom:12px; left:12px; }
            .ghero-counter { bottom:12px; right:12px; }
            .gthumb-nav { display:none; }
            .gthumb { flex-basis:90px; height:58px; }
            .gfs-arrow { display:none; }
            .gfs-bar { gap:10px; padding-left:16px; padding-right:16px; }
            .vdp-titlerow { margin-top:20px; padding-bottom:18px; }
            .vdp-h1 { font-size:24px; }
            .vdp-trim { font-size:14.5px; }
            .vdp-section { padding:22px 0; }
            .vdp-section h2 { font-size:18px; }
            .cert-head { padding:18px 18px 14px; gap:13px; }
            .cert-shield { width:44px; height:44px; }
            .cert-head h3 { font-size:16px; }
            .cert-warranty { margin:0 18px 16px; padding:15px 16px; }
            .cw-value { font-size:20px; }
            .cert-benefits { grid-template-columns:1fr; }
            .cben { padding:15px 18px; }
            .cert-head p { display:none; }
            .cben .s { display:none; }
            .cert-block.is-expanded .cben .s { display:block; }
            .cert-more { display:flex; }
            .cert-foot { padding:14px 18px; }
            .specs { grid-template-columns:1fr; }
            .spec { padding:12px 15px; }
            .dealer-top { padding:16px 16px; gap:13px; }
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
                isScrubbing: false,
                scrubAnchorX: 0,
                scrubLeftover: 0,
                swipeStartX: 0,
                swipeStartY: 0,
                pinchStartDistance: 0,
                pinchStartScale: 1,

                isSpinning: false,
                spinTimer: null,
                openHotspot: null,

                init() {
                    this.currentIndex = 0;
                    this.preloadNeighbours();
                },

                destroy() {
                    this.stopSpin();
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
                    this.stopSpin();
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

                // ----- hero scrub (pseudo-360) -----
                scrubStart(event) {
                    if (this.visibleCount < 2) return;
                    this.stopSpin();
                    this.isScrubbing = true;
                    this.scrubAnchorX = event.clientX;
                    this.scrubLeftover = 0;
                },

                scrubMove(event) {
                    if (! this.isScrubbing) return;
                    const pixelsPerFrame = 22;
                    let travelled = (event.clientX - this.scrubAnchorX) + this.scrubLeftover;
                    while (Math.abs(travelled) >= pixelsPerFrame) {
                        if (travelled > 0) {
                            this.stepWithin(1);
                            travelled -= pixelsPerFrame;
                        } else {
                            this.stepWithin(-1);
                            travelled += pixelsPerFrame;
                        }
                    }
                    this.scrubAnchorX = event.clientX;
                    this.scrubLeftover = travelled;
                },

                scrubEnd() {
                    if (! this.isScrubbing) return;
                    this.isScrubbing = false;
                    this.scrollActiveThumbIntoView();
                },

                // ----- auto-spin -----
                toggleSpin() {
                    this.isSpinning ? this.stopSpin() : this.startSpin();
                },

                startSpin() {
                    if (this.visibleCount < 2) return;
                    this.resetZoom();
                    this.isSpinning = true;
                    this.spinTimer = setInterval(() => this.stepWithin(1), 110);
                },

                stopSpin() {
                    this.isSpinning = false;
                    if (this.spinTimer) {
                        clearInterval(this.spinTimer);
                        this.spinTimer = null;
                    }
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
                    this.stopSpin();
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
