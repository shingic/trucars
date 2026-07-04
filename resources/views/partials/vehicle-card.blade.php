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

    The heart calls $wire->toggleFavourite($vehicle->id) — both host components
    (marketplace and saved) expose that action with the same signature, so the
    card behaves identically wherever it is rendered.
--}}
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
        @if ($favouritesAreVisible)
            <button type="button"
                    class="vcard-fav"
                    x-data="{ saved: @js(in_array($vehicle->id, $savedVehicleIds, true)) }"
                    :class="{ on: saved }"
                    x-on:click.stop="saved = !saved; $wire.toggleFavourite({{ $vehicle->id }})"
                    :title="saved ? 'Saved' : 'Save'"
                    aria-label="Save this car">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 14c1.5-1.5 3-3.3 3-5.5A4.5 4.5 0 0 0 12 5 4.5 4.5 0 0 0 2 8.5c0 2.2 1.5 4 3 5.5l7 7Z"/></svg>
            </button>
        @endif
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
