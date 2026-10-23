{{--
    Shared marketplace vehicle card.

    Rendered on the search floor (marketplace) and the Saved cars page, so the
    two surfaces can never drift — the favourite heart, the pricing line and the
    dealer footer all live here once. The host component supplies:

      $vehicle              the Vehicle model to render
      $savedVehicleIds      array<int> of vehicle ids this buyer has saved, used
                            to seed each heart's filled state
      $favouritesAreVisible whether to render the heart at all (buyers and guests
                            see it; dealer staff never do)

    Optional overrides, used by a dealer campaign storefront:

      $vehicleUrl           where the card links (defaults to the open-floor /cars/{id})
      $badgeLabel           the badge text (defaults to the dealer's inspection program badge
                            when one applies — see VehicleCertification)
      $distanceKm           straight-line km from the shopper, when their location is known

    The car's location is always its dealer's address — vehicles carry none of their own.

    The card's styles live here too (emitted once per page), so every host
    shows the same card.

    The heart calls $wire->toggleFavourite($vehicle->id) — both host components
    (marketplace and saved) expose that action with the same signature, so the
    card behaves identically wherever it is rendered.
--}}
@php
    $vehicleUrl ??= '/cars/' . $vehicle->id;
    $badgeLabel ??= $vehicle->certification()?->badge;
    $distanceKm ??= null;
    $reserved = (bool) ($vehicle->getAttributes()['is_reserved'] ?? false);
@endphp
@once
    <style>
        /* a car on the lot: a trimmed photo, plain facts, the price on a rule */
        .vcard { position:relative; display:flex; flex-direction:column; min-width:0; }
        .vcard-link { position:absolute; inset:0; z-index:1; }
        .vcard-photo { position:relative; overflow:hidden; aspect-ratio:4/3; border-radius:3px; background:var(--bg-2); }
        /* dealer photos carry a vendor badge along the bottom edge; the frame trims it off */
        .vcard-photo img { position:absolute; top:0; left:0; width:100%; height:110%; object-fit:cover; object-position:center top; display:block; }
        .vcard-tags { position:absolute; top:0; left:0; z-index:2; display:flex; flex-direction:column; align-items:flex-start; max-width:calc(100% - 52px); }
        .vcard-tags > span { font-family:var(--font-display); font-size:13.5px; font-weight:600; text-transform:uppercase; padding:3px 9px; max-width:100%; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .vcard-reserved { background:var(--ink); color:#fff; }
        .vcard-offer { background:var(--primary); color:#fff; }
        .vcard-badge { background:#fff; color:var(--ink); display:inline-flex; align-items:center; gap:5px; }
        .vcard-badge svg { color:var(--good); flex-shrink:0; }
        .vcard-fav { position:absolute; top:8px; right:8px; z-index:2; width:34px; height:34px; border-radius:3px; background:#fff; display:grid; place-items:center; color:var(--ink-2); }
        .vcard-fav:hover, .vcard-fav.on { color:var(--primary); }
        .vcard-fav.on svg { fill:var(--primary); }
        .vcard-body { display:flex; flex-direction:column; flex:1; padding-top:12px; pointer-events:none; }
        .vcard-title { font-size:16.5px; font-weight:700; line-height:1.25; }
        .vcard:hover .vcard-title { color:var(--primary-press); }
        .vcard-trim { font-size:13.5px; color:var(--ink-3); margin-top:1px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .vcard-specs { font-size:14px; color:var(--ink-2); margin-top:6px; }
        .vcard-priceline { display:flex; align-items:baseline; justify-content:space-between; gap:10px; margin-top:auto; padding-top:10px; }
        .vcard-specs + .vcard-priceline { margin-top:10px; border-top:1px solid var(--line-strong); }
        .vcard-price { font-family:var(--font-display); font-size:26px; font-weight:700; line-height:1; }
        .vcard-pay { font-size:13px; color:var(--ink-3); white-space:nowrap; }
        .vcard-pay b { color:var(--ink); font-weight:600; }
        .vcard-dealer { font-size:13px; color:var(--ink-3); margin-top:8px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .vcard-dealer .star { color:var(--ink-2); font-weight:600; }

        /* list view: one line per car, like a dealer's stock sheet */
        .is-list .vcard { flex-direction:row; gap:20px; padding:14px 0; border-bottom:1px solid var(--line-strong); }
        .is-list .vcard-photo { width:168px; flex-shrink:0; align-self:flex-start; }
        .is-list .vcard-body { display:grid; grid-template-columns:minmax(0, 1fr) auto; align-content:start; column-gap:24px; padding-top:2px; }
        .is-list .vcard-body > * { grid-column:1; }
        .is-list .vcard-priceline { grid-column:2; grid-row:1 / span 4; flex-direction:column; align-items:flex-end; justify-content:flex-start; gap:6px; margin:0; padding:0; border:none; }
        .is-list .vcard-price { font-size:30px; }

        @media (max-width:860px) {
            .is-list .vcard { flex-direction:column; gap:0; padding:0; border:none; }
            .is-list .vcard-photo { width:100%; }
            .is-list .vcard-body { display:flex; padding-top:12px; }
            .is-list .vcard-priceline { flex-direction:row; align-items:baseline; justify-content:space-between; margin-top:10px; padding-top:10px; border-top:1px solid var(--line-strong); }
            .is-list .vcard-price { font-size:26px; }
            .vcard-fav { width:40px; height:40px; }
        }
    </style>
@endonce
<article class="vcard" wire:key="vehicle-{{ $vehicle->id }}">
    <a href="{{ $vehicleUrl }}" wire:navigate class="vcard-link" aria-label="View {{ $vehicle->model_year }} {{ $vehicle->make }} {{ $vehicle->model }}"></a>
    <div class="vcard-photo">
        @if ($vehicle->primary_photo_url)
            <img src="{{ $vehicle->primary_photo_url }}" alt="{{ $vehicle->model_year }} {{ $vehicle->make }} {{ $vehicle->model }}" loading="lazy">
        @endif
        <div class="vcard-tags">
            @if ($reserved)
                <span class="vcard-reserved">Reserved</span>
            @endif
            @if ($featuredOffer = $vehicle->offers()->first())
                <span class="vcard-offer">{{ $featuredOffer->headline() }}</span>
            @endif
            @if ($badgeLabel)
                <span class="vcard-badge">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5"><path d="M20 6 9 17l-5-5"/></svg>
                    {{ $badgeLabel }}
                </span>
            @endif
        </div>
        @if ($favouritesAreVisible)
            <button type="button"
                    class="vcard-fav"
                    x-data="{ saved: @js(in_array($vehicle->id, $savedVehicleIds, true)) }"
                    :class="{ on: saved }"
                    x-on:click.stop="saved = !saved; $wire.toggleFavourite({{ $vehicle->id }})"
                    :title="saved ? 'Saved' : 'Save'"
                    aria-label="Save this car">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 14c1.5-1.5 3-3.3 3-5.5A4.5 4.5 0 0 0 12 5 4.5 4.5 0 0 0 2 8.5c0 2.2 1.5 4 3 5.5l7 7Z"/></svg>
            </button>
        @endif
    </div>
    <div class="vcard-body">
        <div class="vcard-title">{{ $vehicle->model_year }} {{ $vehicle->make }} {{ $vehicle->model }}</div>
        @if ($vehicle->trim)
            <div class="vcard-trim">{{ $vehicle->trim }}</div>
        @endif
        <div class="vcard-specs">{{ collect([
            $vehicle->display_kilometres,
            $vehicle->drivetrain,
            $vehicle->fuel_type === 'Electric' ? 'Electric' : $vehicle->transmission,
        ])->filter()->implode(' · ') }}</div>
        <div class="vcard-priceline">
            <span class="vcard-price">{{ $vehicle->display_price }}</span>
            <span class="vcard-pay">from <b>${{ number_format($vehicle->estimated_biweekly) }}</b> biweekly</span>
        </div>
        <div class="vcard-dealer">
            {{ ucwords(strtolower($vehicle->dealer->name)) }}@if ($vehicle->display_location) · {{ $vehicle->display_location }}@endif @if ($distanceKm !== null) · {{ $distanceKm < 10 ? number_format($distanceKm, 1) : number_format($distanceKm) }} km away @endif
            @if ($vehicle->dealer->rating) · <span class="star">★ {{ $vehicle->dealer->rating }}</span> @endif
        </div>
    </div>
</article>
