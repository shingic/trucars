<?php

use App\Models\Vehicle;
use Illuminate\Support\Collection;
use Livewire\Component;

new class extends Component {
    /**
     * Saved cars is a buyer-only surface. Following the same self-guarding
     * pattern the other consumer pages use, this page carries no route
     * middleware and instead settles auth here: a guest is sent to sign in, and
     * dealer staff — who never keep favourites — are sent back to the floor.
     *
     * Note this deliberately does not require a verified email the way /garage
     * does. A buyer can save a car the moment they have an account, so they can
     * see those saves straight away rather than hitting a verification wall on
     * the one page built to show them.
     */
    public function mount(): void
    {
        $user = auth()->user();

        if ($user === null) {
            $this->redirect(route('buyer.login'));

            return;
        }

        if ($user->dealer_id !== null) {
            $this->redirect('/');
        }
    }

    /**
     * Unsave a car straight from this page. The card carries the same heart as
     * the floor, so the same toggle applies: detaching the pivot drops the car,
     * and the re-render that follows rebuilds the list without it, so it simply
     * falls away. Dealer staff are guarded out here too, matching the floor.
     */
    public function toggleFavourite(int $vehicleId): void
    {
        $user = auth()->user();

        if ($user === null || $user->dealer_id !== null) {
            return;
        }

        $user->favouriteVehicles()->toggle($vehicleId);

        $this->dispatch('favourites-updated', count: $user->favouriteVehicles()->count());
    }

    /**
     * This buyer's saved cars, newest save first (the relationship already
     * orders that way), with the dealer eager-loaded for the card footer.
     *
     * @return Collection<int, Vehicle>
     */
    private function savedVehicles(): Collection
    {
        $user = auth()->user();

        if ($user === null || $user->dealer_id !== null) {
            return collect();
        }

        // A saved car that's no longer listed (sold, pulled, dealer back in draft)
        // has no page to open, so it drops off rather than linking to a 404.
        return $user->favouriteVehicles()->listed()->withReservedFlag()->with(\App\Support\VehicleCertification::EAGER)->get();
    }

    /**
     * Fresh view data each render — resolved as a plain method rather than a
     * #[Computed] so the list reflects the current saved set immediately after
     * a toggle. Every car on this page is saved by definition, so the seed ids
     * the shared card reads are just the ids of the list itself.
     *
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $savedVehicles = $this->savedVehicles();

        return [
            'savedVehicles' => $savedVehicles,
            'savedVehicleIds' => $savedVehicles->pluck('id')->all(),
            'favouritesAreVisible' => true,
        ];
    }
};
?>

<div>
    <header class="saved-head">
        <a href="{{ route('shop') }}" wire:navigate class="saved-back">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M11 6l-6 6 6 6"/></svg>
            Back to shopping
        </a>
        <h1 class="saved-title">Saved cars</h1>
        <p class="saved-sub">
            @if ($savedVehicles->isNotEmpty())
                {{ $savedVehicles->count() }} {{ $savedVehicles->count() === 1 ? 'car' : 'cars' }} saved — tap the heart on any card to remove it.
            @else
                The cars you save will live here, ready when you come back.
            @endif
        </p>
    </header>

    @if ($savedVehicles->isEmpty())
        <div class="saved-empty">
            <div class="saved-empty-title">No saved cars yet</div>
            <p class="saved-empty-text">Tap the heart on any car and it will be kept here for you — across visits, on any device you sign in from.</p>
            <a href="{{ route('shop') }}" wire:navigate class="saved-empty-cta">
                Browse certified cars
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
            </a>
        </div>
    @else
        <div class="grid">
            @foreach ($savedVehicles as $vehicle)
                @include('partials.vehicle-card', [
                    'vehicle' => $vehicle,
                    'savedVehicleIds' => $savedVehicleIds,
                    'favouritesAreVisible' => $favouritesAreVisible,
                ])
            @endforeach
        </div>
    @endif

    <style>
        [x-cloak] { display:none !important; }

        .saved-head { padding-bottom:16px; margin-bottom:28px; border-bottom:2px solid var(--ink); }
        .saved-back { display:inline-flex; align-items:center; gap:7px; font-size:14px; font-weight:600; color:var(--ink-2); text-decoration:underline; text-underline-offset:3px; margin-bottom:18px; }
        .saved-back:hover { color:var(--ink); }
        .saved-title { font-family:var(--font-display); font-size:44px; font-weight:700; text-transform:uppercase; line-height:1; letter-spacing:0; margin:0; }
        .saved-sub { color:var(--ink-2); font-size:15px; margin:10px 0 0; }

        /* Same floor grid so a saved car reads exactly as it does on the floor. */
        .grid { display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:34px 22px; }
        @media (max-width:1350px) { .grid { grid-template-columns:repeat(3, minmax(0, 1fr)); } }
        @media (max-width:1000px) { .grid { grid-template-columns:repeat(2, minmax(0, 1fr)); } }

        /* empty: a plain note in a dashed box */
        .saved-empty { max-width:560px; margin:8px 0 20px; padding:24px 26px; border:1.5px dashed var(--line-strong); border-radius:3px; }
        .saved-empty-title { font-family:var(--font-display); font-size:24px; font-weight:700; text-transform:uppercase; line-height:1; letter-spacing:0; }
        .saved-empty-text { font-size:15px; color:var(--ink-2); line-height:1.55; margin:10px 0 20px; }
        .saved-empty-cta { display:inline-flex; align-items:center; gap:8px; font-family:var(--font-display); font-size:18px; font-weight:600; text-transform:uppercase; color:#fff; text-decoration:none; background:var(--primary); border-radius:3px; padding:11px 22px; transition:background .16s ease; }
        .saved-empty-cta:hover { background:var(--primary-press); }

        @media (max-width:860px) {
            .saved-title { font-size:34px; }
            .saved-sub { font-size:14px; }
            .saved-empty { padding:20px 18px; }
            .grid { grid-template-columns:minmax(0, 1fr); gap:30px; }
        }
    </style>
</div>
