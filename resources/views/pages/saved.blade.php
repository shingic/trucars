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

        return $user->favouriteVehicles()->with('dealer')->get();
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
        <a href="/" wire:navigate class="saved-back">
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
            <span class="saved-empty-icon">
                <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M19 14c1.5-1.5 3-3.3 3-5.5A4.5 4.5 0 0 0 12 5 4.5 4.5 0 0 0 2 8.5c0 2.2 1.5 4 3 5.5l7 7Z"/></svg>
            </span>
            <div class="saved-empty-title">No saved cars yet</div>
            <p class="saved-empty-text">Tap the heart on any car and it will be kept here for you — across visits, on any device you sign in from.</p>
            <a href="/" wire:navigate class="saved-empty-cta">
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

        .saved-head { margin-bottom:22px; }
        .saved-back { display:inline-flex; align-items:center; gap:7px; font-size:13.5px; font-weight:600; color:var(--ink-2); text-decoration:none; margin-bottom:18px; }
        .saved-back:hover { color:var(--primary); }
        .saved-title { font-size:30px; font-weight:800; letter-spacing:-.025em; margin:0; }
        .saved-sub { color:var(--ink-2); font-size:15px; margin:6px 0 0; }

        /* Same floor grid so a saved car reads exactly as it does on the floor. */
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

        .saved-empty { max-width:440px; margin:40px auto 20px; text-align:center; padding:20px; }
        .saved-empty-icon { display:inline-grid; place-items:center; width:66px; height:66px; border-radius:50%; background:var(--primary-soft); color:var(--primary); margin-bottom:18px; }
        .saved-empty-title { font-size:20px; font-weight:800; letter-spacing:-.02em; }
        .saved-empty-text { font-size:14.5px; color:var(--ink-2); line-height:1.55; margin:8px 0 22px; }
        .saved-empty-cta { display:inline-flex; align-items:center; gap:8px; font-size:15px; font-weight:700; color:#fff; text-decoration:none; background:var(--primary); border-radius:var(--radius-pill); padding:13px 24px; box-shadow:var(--shadow-primary); transition:background .16s ease, transform .16s ease; }
        .saved-empty-cta:hover { background:var(--primary-press); transform:translateY(-1px); }

        @media (max-width:860px) {
            .saved-title { font-size:25px; }
            .saved-sub { font-size:14px; }
            .grid { grid-template-columns:1fr; gap:14px; }
            .vcard { border-radius:18px; }
            .vcard-photo { width:100%; height:210px; }
            .vcard-fav { width:38px; height:38px; }
            .vcard-body { padding:16px 17px 17px; }
            .vcard-title { font-size:16.5px; }
            .vcard-price { font-size:20px; }
        }
    </style>
</div>
