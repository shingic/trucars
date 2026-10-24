<?php

use App\Models\Dealer;
use App\Models\Vehicle;
use Illuminate\Support\Collection;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
| The public homepage — where someone who finds TruCars lands before they
| shop. Everything on it is live: the counts, the cars and the photos are
| read from what's actually listed (published cars at active dealers).
*/
new #[Title('TruCars — Real dealers. Real prices.')] class extends Component {
    public string $q = '';

    /** The search box opens the shop with the search already applied. */
    public function search(): void
    {
        $this->redirectRoute('shop', array_filter(['q' => trim($this->q)]), navigate: true);
    }

    public function with(): array
    {
        $listed = Vehicle::query()->listed();

        $activeDealers = Dealer::where('status', Dealer::STATUS_ACTIVE)->whereHas('vehicles', fn ($vehicles) => $vehicles->where('is_published', true));
        $featured = $this->showcase($listed);
        $closer = $this->showcase($listed, except: $featured);

        return [
            'carCount'    => (clone $listed)->count(),
            'dealerCount' => (clone $activeDealers)->count(),
            'cityCount'   => (clone $activeDealers)->distinct()->count('city'),
            'mix'         => $this->mix($listed, 8),
            'featured'    => $featured,
            'closer'      => $closer,
            'bodyTypes'   => $this->countsBy($listed, 'body_type', 4),
            'makes'       => $this->countsBy($listed, 'make', 10),
            'under'       => (clone $listed)->where('price_in_cents', '<', 2500000)->count(),
        ];
    }

    /**
     * A random car with a photo for the big pictures, different on every
     * visit. A random dealer is picked first, so a small lot gets the same
     * turns as a big one.
     */
    private function showcase($listed, ?Vehicle $except = null): ?Vehicle
    {
        $withPhotos = (clone $listed)->whereNotNull('photos')
            ->when($except, fn ($query) => $query->whereKeyNot($except->id));

        $dealerId = (clone $withPhotos)->distinct()->pluck('vehicles.dealer_id')->shuffle()->first();

        if ($dealerId === null) {
            return null;
        }

        // A few candidates, in case a feed sent some cars with an empty photo list.
        return (clone $withPhotos)->where('vehicles.dealer_id', $dealerId)->with(['dealer', 'rooftop'])
            ->inRandomOrder()->limit(5)->get()
            ->first(fn (Vehicle $vehicle) => $vehicle->primary_photo_url !== null);
    }

    /**
     * Random cars with photos for the "On the lot now" row, dealt out like
     * cards: every dealer gets one before anyone gets a second, so a dealer
     * whose whole feed just uploaded can't fill the row on their own.
     */
    private function mix($listed, int $count): Collection
    {
        $withPhotos = (clone $listed)->whereNotNull('photos');

        $hands = (clone $withPhotos)->distinct()->pluck('vehicles.dealer_id')->shuffle()->take($count)
            ->map(fn ($dealerId) => (clone $withPhotos)->where('vehicles.dealer_id', $dealerId)->with(['dealer', 'rooftop'])->withReservedFlag()
                ->inRandomOrder()->limit($count + 2)->get()
                ->filter(fn (Vehicle $vehicle) => $vehicle->primary_photo_url !== null)->values());

        $dealt = collect();
        for ($round = 0; $dealt->count() < $count && $hands->contains(fn ($hand) => $hand->has($round)); $round++) {
            foreach ($hands as $hand) {
                if ($hand->has($round)) {
                    $dealt->push($hand[$round]);
                }
            }
        }

        // Whole rows only, so the grid never ends on a half-empty line.
        return $dealt->take($dealt->count() >= 4 ? intdiv(min($dealt->count(), $count), 4) * 4 : $dealt->count());
    }

    private function countsBy($listed, string $column, int $limit): Collection
    {
        return (clone $listed)
            ->whereNotNull($column)->where($column, '!=', '')
            ->selectRaw("{$column} as value, count(*) as total")
            ->groupBy($column)
            ->orderByDesc('total')
            ->limit($limit)
            ->pluck('total', 'value');
    }
}; ?>

@php
    // Side-on cars drawn on a 64×32 grid: [body, windows], wheels added below.
    $bodies = [
        'SUV'         => ['M4 24V9q0-4 4-5h33l9 8 8 1.5q3 .7 3 3.5v7Z', 'M9 7h11v5H8ZM23 7h11v5H23ZM37 7h4l6 5H37Z', [15, 49]],
        'Sedan'       => ['M3 24v-5q0-3 3-3.5l10-1.5 7-7h17l9 7 9 1.5q3 .5 3 3.5v5Z', 'M24.5 9H31v5H19ZM33 9h6.5l6 5H33Z', [15, 49]],
        'Truck'       => ['M3 24v-9h27V8q0-2 2-2h11l7 8 8 1q3 .5 3 3v6Z', 'M33 8.5h9l5 5.5H33ZM6.5 15h21', [14, 49]],
        'Van'         => ['M3 24V7q0-3 3-3h36l13 9q6 1 6 4v7Z', 'M7 7h11v6H7ZM21 7h11v6H21ZM35 7h7l8 6H35Z', [14, 49]],
        'Hatchback'   => ['M4 24V10q0-4 4-4h30l10 8 10 1.5q3 .5 3 3.5v5Z', 'M8 9h14v5H8ZM25 9h12l7 5H25Z', [15, 49]],
        'Coupe'       => ['M3 24v-5q0-3 4-4l11-2 9-6h11l10 7 10 1.5q3 .5 3 3.5v5Z', 'M28 9h9.5l6.5 5H22Z', [15, 49]],
    ];
    $bodies['Convertible'] = $bodies['Coupe'];
    $bodies['Wagon'] = $bodies['Hatchback'];

    $car = function (string $body) use ($bodies): string {
        [$shape, $glass, $wheels] = $bodies[$body] ?? $bodies['SUV'];
        $svg = '<path class="paint" d="' . $shape . '"/><path class="glass" d="' . $glass . '"/>';
        foreach ($wheels as $x) {
            $svg .= '<circle class="tyre" cx="' . $x . '" cy="24" r="5.5"/><circle class="hub" cx="' . $x . '" cy="24" r="2.2"/>';
        }

        return '<svg class="ride" viewBox="0 0 64 32" aria-hidden="true">' . $svg . '</svg>';
    };

    // Line icons for the shop-by tiles, the hero chips and the promise strip.
    $icons = [
        'piggy'  => '<path d="M5 11.5C5 8 8.4 6 12 6c2 0 3.6.5 4.8 1.4L19.5 6l-.4 3.2c1 .9 1.4 1.8 1.6 2.8H22v3h-1.6c-.5 1.2-1.4 2.2-2.6 2.8V20h-3v-1.5h-4V20H8v-2.3C6 16.6 5 14.3 5 11.5Z"/><circle cx="16.5" cy="10.5" r=".9"/><path d="M10 6.3V4.5h3v1.6M5 11.5H3"/>',
        'grid'   => '<rect x="3.5" y="3.5" width="7" height="7"/><rect x="13.5" y="3.5" width="7" height="7"/><rect x="3.5" y="13.5" width="7" height="7"/><rect x="13.5" y="13.5" width="7" height="7"/>',
        'tag'    => '<path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8Z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
        'shield' => '<path d="M12 3 4 6v6c0 5 8 9 8 9s8-4 8-9V6Z"/><path d="m9 12 2 2 4-4"/>',
        'store'  => '<path d="M4 21V5h10v16M14 9h6v12M2 21h20M7 8h1M10 8h1M7 11h1M10 11h1M7 14h1M10 14h1M17 13h1M17 16h1"/>',
        'dollar' => '<circle cx="12" cy="12" r="9"/><path d="M15 9.2a2.6 2.6 0 0 0-2.5-1.7h-1a2.25 2.25 0 0 0 0 4.5h1a2.25 2.25 0 0 1 0 4.5h-1A2.6 2.6 0 0 1 9 14.8M12 6v1.5M12 16.5V18"/>',
    ];
    $icon = fn (string $name): string => '<svg class="ico" viewBox="0 0 24 24" aria-hidden="true">' . $icons[$name] . '</svg>';

    $faqs = [
        ['Can I negotiate the price?', 'There’s nothing to haggle over. The price on the listing is the dealership’s price, with freight, prep and fees already in it. HST and licensing are the only things added.'],
        ['Who am I actually buying from?', 'The licensed dealership named on the listing. TruCars is where you find the car and reserve it; the dealership sells it to you, and their registration number is on every listing.'],
        ['Is the price really all-in?', 'Yes. Freight, pre-delivery prep, dealer admin and regulator fees are already in the price you see. HST and licensing are the only things added.'],
        ['What does the $150 deposit do?', 'It takes the car off the market while you finish up, and it comes off the price when you buy. It’s refundable any time before you take delivery.'],
        ['Can I pick the car up myself?', 'Yes. Pick it up at the dealership, or choose delivery when you reserve. Either way you pick the day and time window.'],
        ['What does “certified” mean on a listing?', 'It means the car is covered by the dealer’s or manufacturer’s inspection checklist, and the listing shows every point that’s checked, not just a number.'],
    ];
@endphp

<div class="home">
    <style>
        .home { min-width:0; font-size:16px; }
        .home > * { min-width:0; }
        .home section { margin-top:88px; }
        /* the hero runs edge to edge under the top bar; everything after it sits in a narrower column */
        html { overflow-x:clip; }
        .hero-band { --gut:max(24px, calc((100vw - 1600px) / 2 + 24px)); margin:-32px calc(50% - 50vw) 0; }
        .home-body { max-width:1080px; margin:0 auto; }
        @media (max-width:860px) { .hero-band { --gut:16px; margin-top:-18px; } }
        .home h2 { font-family:var(--font-display); font-size:clamp(30px, 3.4vw, 44px); font-weight:700; line-height:1; text-transform:uppercase; margin:0; }
        .sec-head { display:flex; align-items:flex-end; justify-content:space-between; gap:20px; flex-wrap:wrap; padding-bottom:14px; margin-bottom:26px; border-bottom:2px solid var(--ink); }
        .sec-head p { margin:8px 0 0; color:var(--ink-2); }
        .sec-head > a { font-family:var(--font-display); font-size:17px; font-weight:600; text-transform:uppercase; color:var(--primary-press); text-decoration:none; }
        .sec-head > a:hover { color:var(--ink); }

        .ico { width:44px; height:44px; fill:none; stroke:currentColor; stroke-width:1.6; stroke-linecap:round; stroke-linejoin:round; }

        /* the little cars: flat, ink outline, blue paint */
        .ride { overflow:visible; }
        .ride .paint { fill:var(--paint, var(--primary)); stroke:var(--ink); stroke-width:1.6; stroke-linejoin:round; }
        .ride .glass { fill:#fff; stroke:var(--ink); stroke-width:1.3; stroke-linejoin:round; }
        .ride .tyre { fill:var(--ink); }
        .ride .hub { fill:#D9DCE0; }

        /* hero: the promise on blue, beside a real car from the lot */
        .stage { display:grid; grid-template-columns:minmax(0, 1fr) minmax(0, 1.08fr); background:var(--primary); overflow:hidden; }
        .stage.no-photo { grid-template-columns:1fr; }
        .stage-copy { padding:64px 48px 60px var(--gut); color:#fff; align-self:center; }
        .stage-kicker { font-size:15px; font-weight:500; letter-spacing:.16em; text-transform:uppercase; color:rgba(255,255,255,.9); margin:0 0 18px; }
        .stage h1 { font-family:var(--font-ui); font-size:clamp(44px, 5.1vw, 84px); font-weight:800; line-height:1; letter-spacing:-.015em; margin:0; color:#fff; }
        .stage h1 span { display:block; }
        .stage p.lede { font-size:19px; color:rgba(255,255,255,.92); margin:22px 0 0; max-width:540px; line-height:1.5; }
        .hero-search { margin-top:30px; display:flex; align-items:center; gap:10px; max-width:660px; background:#fff; border-radius:10px; padding:8px 8px 8px 20px; }
        .hero-search > svg { color:var(--ink-2); flex-shrink:0; }
        .hero-search input { flex:1; min-width:0; border:none; outline:none; font-size:17px; background:none; padding:14px 4px; color:var(--ink); }
        .hero-search input::placeholder { color:var(--ink-3); }
        .hero-search button { display:inline-flex; align-items:center; gap:10px; background:var(--primary); color:#fff; font-size:18px; font-weight:700; padding:15px 28px; border-radius:7px; white-space:nowrap; }
        .hero-search button:hover { background:var(--primary-press); }
        .chips { display:flex; gap:8px; flex-wrap:wrap; margin-top:18px; }
        .chips a { display:inline-flex; align-items:center; gap:8px; font-size:14.5px; font-weight:600; color:#fff; text-decoration:none; padding:9px 15px; border:1.5px solid rgba(255,255,255,.55); border-radius:999px; }
        .chips a:hover, .chips a:first-child { background:#fff; color:var(--primary-press); border-color:#fff; }
        .chips .ride { width:24px; height:12px; }
        .chips .ride .paint, .chips .ride .glass { fill:none; stroke:currentColor; stroke-width:2.4; }
        .chips .ride .tyre { fill:currentColor; } .chips .ride .hub { fill:none; }
        .chips .ico { width:17px; height:17px; stroke-width:2; }
        .stage figure { margin:0; position:relative; min-height:620px; background:var(--primary-press); }
        /* dealer photos carry a vendor badge along the bottom edge; the frame trims it off */
        .shot { position:relative; overflow:hidden; }
        .shot > img { position:absolute; top:0; left:0; width:100%; height:107%; object-fit:cover; object-position:center 40%; }

        /* the car in the photo, named and priced, on a card over it */
        .pictured { position:absolute; z-index:1; top:40px; right:var(--gut-r, 44px); width:min(480px, calc(100% - 64px)); display:flex; align-items:center; gap:16px; background:#fff; color:var(--ink); text-decoration:none; border-radius:12px; padding:20px 22px 20px 24px; box-shadow:0 10px 30px rgba(7,20,55,.18); }
        .pictured-main { flex:1; min-width:0; }
        .pictured small { display:block; font-size:12.5px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; color:var(--ink-2); }
        .pictured strong { display:block; font-size:26px; font-weight:700; line-height:1.15; margin-top:4px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .pictured .meta { font-size:15px; color:var(--ink-2); margin-top:4px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .pictured .meta i { font-style:normal; color:var(--line-strong); margin:0 8px; }
        .pictured b { display:block; font-size:36px; font-weight:800; color:var(--primary); margin-top:8px; line-height:1; }
        .pictured .go { width:40px; height:40px; border-radius:50%; background:#E9F3FF; color:var(--primary-press); display:grid; place-items:center; flex-shrink:0; }
        .pictured:hover .go { background:var(--primary); color:#fff; }

        /* four promises on a navy strip */
        .facts { display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); background:var(--ink); color:#fff; padding:30px var(--gut); gap:0; }
        .fact { display:flex; gap:18px; align-items:flex-start; padding:0 28px; }
        .fact:first-child { padding-left:0; }
        .fact + .fact { border-left:1px solid rgba(255,255,255,.2); }
        .fact-ic { width:58px; height:58px; border-radius:50%; border:1.5px solid rgba(255,255,255,.75); display:grid; place-items:center; flex-shrink:0; }
        .fact-ic svg { width:26px; height:26px; fill:none; stroke:#fff; stroke-width:1.8; stroke-linecap:round; stroke-linejoin:round; }
        .fact b { display:block; font-family:var(--font-display); font-size:22px; font-weight:700; text-transform:uppercase; color:#fff; }
        .fact div span { display:block; font-size:16px; color:rgba(255,255,255,.85); margin-top:4px; line-height:1.45; }

        /* cars */
        .cars { display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:34px 22px; }
        .car { display:block; text-decoration:none; color:var(--ink); }
        .car-photo { aspect-ratio:4/2.8; border-radius:3px; background:var(--bg-2); }
        .car:hover .car-name { color:var(--primary-press); }
        .car-photo .reserved { position:absolute; z-index:1; top:0; left:0; background:var(--ink); color:#fff; font-family:var(--font-display); font-size:14px; font-weight:600; text-transform:uppercase; padding:4px 10px; }
        .car-photo .cert { position:absolute; z-index:1; left:0; bottom:0; max-width:100%; background:var(--primary); color:#fff; font-family:var(--font-display); font-size:14px; font-weight:600; text-transform:uppercase; padding:4px 10px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .car-name { font-size:16.5px; font-weight:700; margin-top:12px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .car-meta { font-size:14px; color:var(--ink-3); margin-top:1px; }
        .car-price { font-family:var(--font-display); font-size:26px; font-weight:700; line-height:1; margin-top:10px; padding-top:10px; border-top:1px solid var(--line-strong); }
        .car-price small { font-family:var(--font-ui); font-size:13px; font-weight:500; color:var(--ink-3); }

        /* no haggling: the sales-desk lines, struck through */
        .calm { display:grid; grid-template-columns:minmax(0, .85fr) minmax(0, 1.15fr); gap:56px; align-items:start; }
        .calm > div > p { font-size:17px; color:var(--ink-2); margin:16px 0 0; max-width:440px; line-height:1.55; }
        .heard small { display:block; font-family:var(--font-display); font-size:15px; font-weight:600; text-transform:uppercase; color:var(--ink-3); margin-bottom:6px; }
        .said { font-family:var(--font-display); font-size:clamp(22px, 2.4vw, 30px); font-weight:500; line-height:1.2; color:var(--ink-3); padding:8px 0; border-bottom:1px solid var(--line-strong); text-decoration:line-through; text-decoration-color:var(--ink); text-decoration-thickness:2px; }
        .reply { margin-top:18px; background:var(--ink); color:#fff; padding:18px 20px; font-size:17px; line-height:1.5; }
        .reply b { display:block; font-family:var(--font-display); font-size:15px; font-weight:600; text-transform:uppercase; color:var(--primary-bright); margin-bottom:4px; }

        /* shop by: outlined tiles; the little car drives off when you point at it */
        .garage { display:grid; grid-template-columns:repeat(6, minmax(0, 1fr)); gap:0; border:2px solid var(--ink); }
        .bay { display:flex; flex-direction:column; justify-content:space-between; gap:18px; min-height:180px; padding:18px; text-decoration:none; color:var(--ink); overflow:hidden; background:#fff; }
        .bay + .bay { border-left:2px solid var(--ink); }
        .bay .ride { --paint:#DBDFE9; }
        .bay:hover { background:var(--ink); color:#fff; }
        .bay:hover .ride { --paint:var(--primary); animation:vroom .7s cubic-bezier(.3,.7,.3,1) forwards; }
        .bay b { display:block; font-family:var(--font-display); font-size:24px; font-weight:700; text-transform:uppercase; line-height:1; }
        .bay span { font-size:14px; color:var(--ink-2); }
        .bay:hover span { color:rgba(255,255,255,.85); }
        .bay .ride { width:100%; max-width:128px; height:auto; }
        @keyframes vroom { 0% { transform:none; } 25% { transform:translateX(-4px) rotate(-2deg); } 60% { transform:translateX(14px) rotate(1deg); } 100% { transform:translateX(10px); } }
        @media (prefers-reduced-motion:reduce) { .bay:hover .ride { animation:none; } }
        .makes { display:flex; flex-wrap:wrap; gap:6px; margin-top:16px; }
        .makes a { font-size:14.5px; font-weight:600; color:var(--ink); text-decoration:none; padding:6px 12px; border:1.5px solid var(--ink); }
        .makes a span { color:var(--ink-3); margin-left:4px; }
        .makes a:hover { background:var(--ink); color:#fff; }
        .makes a:hover span { color:rgba(255,255,255,.6); }

        /* how it works: big numerals, like the full walkthrough */
        .how { display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:32px; }
        .how .n { font-family:var(--font-display); font-size:84px; font-weight:700; line-height:.8; color:var(--ink); }
        .how b { display:block; font-family:var(--font-display); font-size:24px; font-weight:700; line-height:1.1; margin:14px 0 6px; }
        .how p { margin:0; font-size:15.5px; color:var(--ink-2); line-height:1.5; }

        /* questions + close */
        .faq-wrap { display:grid; grid-template-columns:minmax(0, .8fr) minmax(0, 1.2fr); gap:56px; }
        .faq { border-top:2px solid var(--ink); }
        .faq details { border-bottom:1px solid var(--line-strong); }
        .faq summary { cursor:pointer; list-style:none; display:flex; justify-content:space-between; gap:16px; padding:18px 0; font-size:17px; font-weight:700; }
        .faq summary::-webkit-details-marker { display:none; }
        .faq summary::after { content:"+"; font-family:var(--font-display); font-size:26px; line-height:.8; color:var(--ink-3); }
        .faq details[open] summary::after { content:"–"; }
        .faq p { margin:0 0 20px; font-size:15.5px; color:var(--ink-2); max-width:620px; line-height:1.55; }
        .close { display:grid; grid-template-columns:minmax(0, 1fr) minmax(0, 1fr); background:var(--ink); border-radius:4px; overflow:hidden; }
        .close.no-photo { grid-template-columns:1fr; }
        .close > div { padding:48px 44px; color:#fff; }
        .close h2 { color:#fff; font-size:clamp(34px, 4.4vw, 60px); line-height:.95; }
        .close p { color:rgba(255,255,255,.75); font-size:17px; margin:14px 0 26px; }
        .close figure { margin:0; min-height:320px; background:#12224D; }
        .btn-go { display:inline-block; background:var(--primary); color:#fff; font-family:var(--font-display); font-size:20px; font-weight:700; text-transform:uppercase; padding:13px 26px; border-radius:4px; text-decoration:none; }
        .btn-go:hover { background:#fff; color:var(--ink); }

        @media (max-width:1100px) {
            .cars { grid-template-columns:repeat(2, minmax(0, 1fr)); }
            .garage { grid-template-columns:repeat(3, minmax(0, 1fr)); }
            .bay + .bay { border-left:none; }
            .bay { border-right:2px solid var(--ink); border-bottom:2px solid var(--ink); margin:0 -2px -2px 0; }
            .how { grid-template-columns:repeat(2, minmax(0, 1fr)); }
            .faq-wrap, .calm { grid-template-columns:minmax(0, 1fr); gap:24px; }
            .facts { grid-template-columns:repeat(2, minmax(0, 1fr)); row-gap:24px; }
            .fact:nth-child(3) { border-left:none; padding-left:0; }
        }
        @media (max-width:760px) {
            .home section { margin-top:60px; }
            .stage, .close { grid-template-columns:minmax(0, 1fr); }
            .stage-copy { padding:30px 16px 30px; }
            .stage-kicker { font-size:13px; letter-spacing:.12em; }
            .stage p.lede { font-size:17px; }
            .hero-search { padding:6px 6px 6px 14px; gap:6px; }
            .hero-search input { font-size:16px; padding:12px 2px; }
            .hero-search button { padding:13px 16px; font-size:16px; }
            .stage figure { min-height:0; aspect-ratio:4/2.8; order:-1; }
            .pictured { top:auto; bottom:12px; right:12px; left:12px; width:auto; padding:12px 14px; gap:10px; }
            .pictured strong { font-size:19px; }
            .pictured .meta { font-size:13px; }
            .pictured b { font-size:24px; margin-top:4px; }
            .facts { grid-template-columns:minmax(0, 1fr); padding:8px 16px; }
            .fact, .fact:first-child { padding:16px 0; }
            .fact + .fact { border-left:none; border-top:1px solid rgba(255,255,255,.16); }
            .fact-ic { width:48px; height:48px; }
            .cars { gap:26px 12px; }
            .car-price { font-size:22px; }
            .garage { grid-template-columns:repeat(2, minmax(0, 1fr)); }
            .bay { min-height:140px; padding:14px; }
            .bay b { font-size:20px; }
            .how { grid-template-columns:minmax(0, 1fr); gap:26px; }
            .how .n { font-size:64px; }
            .close > div { padding:30px 22px; }
            .close figure { min-height:200px; }
        }
    </style>

    {{-- HERO --}}
    <div class="hero-band">
    <div class="stage {{ $featured ? '' : 'no-photo' }}">
        <div class="stage-copy">
            <p class="stage-kicker">Certified pre-owned. Real dealerships.</p>
            <h1><span>Skip the haggle.</span> <span>Skip the day</span> <span>at the dealership.</span></h1>
            <p class="lede">Browse certified vehicles from licensed Canadian dealers, reserve online, get your financing approved, and pick up when you're ready.</p>

            <form class="hero-search" wire:submit="search" role="search">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
                <input type="search" wire:model="q" placeholder="Search by make, model or keyword, e.g. Outlander" aria-label="Search cars">
                <button type="submit">Search <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M5 12h14M13 6l6 6-6 6"/></svg></button>
            </form>

            <div class="chips">
                @foreach ($bodyTypes->keys() as $body)
                    <a href="{{ route('shop', ['body' => [$body]]) }}" wire:navigate>{!! $car($body) !!} {{ $body }}s</a>
                @endforeach
                @if ($under) <a href="{{ route('shop', ['max' => 25000]) }}" wire:navigate>{!! $icon('tag') !!} Under $25k</a> @endif
                <a href="{{ route('shop') }}" wire:navigate>{!! $icon('grid') !!} Everything</a>
            </div>
        </div>
        @if ($featured)
            <figure class="shot">
                <img src="{{ $featured->primary_photo_url }}" alt="">
                <a class="pictured" href="/cars/{{ $featured->id }}" wire:navigate>
                    <div class="pictured-main">
                        @if ($featured->certification()) <small>Certified pre-owned</small> @else <small>Pictured</small> @endif
                        <strong>{{ $featured->model_year }} {{ $featured->make }} {{ $featured->model }}</strong>
                        <div class="meta">{!! collect([$featured->trim, $featured->display_kilometres, $featured->display_location])->filter()->map(fn ($part) => e($part))->join('<i>|</i>') !!}</div>
                        <b>{{ $featured->display_price }}</b>
                    </div>
                    <span class="go"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="m9 6 6 6-6 6"/></svg></span>
                </a>
            </figure>
        @endif
    </div>

    <div class="facts">
        <div class="fact"><span class="fact-ic">{!! $icon('tag') !!}</span><div><b>All-in prices</b><span>No hidden fees. Freight, prep and dealer fees included.</span></div></div>
        <div class="fact"><span class="fact-ic">{!! $icon('shield') !!}</span><div><b>Certified vehicles</b><span>Inspected and backed by real dealerships.</span></div></div>
        <div class="fact"><span class="fact-ic">{!! $icon('store') !!}</span><div><b>Licensed dealers only</b><span>Every listing names the licensed dealership selling it.</span></div></div>
        <div class="fact"><span class="fact-ic">{!! $icon('dollar') !!}</span><div><b>Hold it for $150</b><span>Refundable until you take delivery.</span></div></div>
    </div>

    </div>

    <div class="home-body">
    {{-- ON THE LOT NOW --}}
    @if ($mix->isNotEmpty())
        <section>
            <div class="sec-head">
                <div><h2>On the lot now</h2><p>A mix from our dealers. It changes every visit.</p></div>
                <a href="{{ route('shop') }}" wire:navigate>See all {{ number_format($carCount) }} {{ $carCount === 1 ? 'car' : 'cars' }} →</a>
            </div>
            <div class="cars">
                @foreach ($mix as $listing)
                    <a class="car" href="/cars/{{ $listing->id }}" wire:navigate wire:key="mix-{{ $listing->id }}">
                        <div class="car-photo shot">
                            <img src="{{ $listing->primary_photo_url }}" alt="" loading="lazy">
                            @if ($listing->is_reserved)<span class="reserved">Reserved</span>@endif
                            @if ($certified = $listing->certification())<span class="cert">{{ $certified->badge }}</span>@endif
                        </div>
                        <div class="car-name">{{ $listing->model_year }} {{ $listing->make }} {{ $listing->model }}</div>
                        <div class="car-meta">{{ $listing->display_kilometres }} · {{ $listing->display_location }}</div>
                        <div class="car-price">{{ $listing->display_price }} <small>all-in</small></div>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    {{-- NO HAGGLING --}}
    <section class="calm">
        <div>
            <h2>No haggling. No pressure.</h2>
            <p>Every price on TruCars is set by the dealership and shown up front, with the fees already in it. Decide on your own time, from your own couch.</p>
        </div>
        <div class="heard">
            <small>Heard at the sales desk</small>
            <div class="said">Let me go talk to my manager.</div>
            <div class="said">This price is only good today.</div>
            <div class="said">Freight and admin are extra, of course.</div>
            <div class="said">What would it take to get you into this car today?</div>
            <div class="reply"><b>On TruCars</b>The price on the listing is the price, fees included. Take your time, and hold it for $150 if you want to think it over.</div>
        </div>
    </section>

    {{-- SHOP BY --}}
    @if ($bodyTypes->isNotEmpty())
        <section>
            <div class="sec-head"><div><h2>What are you after?</h2><p>Pick a type and we'll show you what's on the lot.</p></div></div>
            <div class="garage">
                @foreach ($bodyTypes as $body => $total)
                    <a class="bay" href="{{ route('shop', ['body' => [$body]]) }}" wire:navigate>
                        {!! $car($body) !!}
                        <div><b>{{ $body }}s</b><span>{{ $total }} {{ $total === 1 ? 'car' : 'cars' }}</span></div>
                    </a>
                @endforeach
                @if ($under)
                    <a class="bay" href="{{ route('shop', ['max' => 25000]) }}" wire:navigate>
                        {!! $icon('piggy') !!}
                        <div><b>Under $25k</b><span>{{ $under }} {{ $under === 1 ? 'car' : 'cars' }}</span></div>
                    </a>
                @endif
                <a class="bay" href="{{ route('shop') }}" wire:navigate>
                    {!! $icon('grid') !!}
                    <div><b>See everything</b><span>{{ number_format($carCount) }} {{ $carCount === 1 ? 'car' : 'cars' }}</span></div>
                </a>
            </div>
            @if ($makes->isNotEmpty())
                <div class="makes">
                    @foreach ($makes as $name => $total)<a href="{{ route('shop', ['make' => [$name]]) }}" wire:navigate>{{ $name }}<span>{{ $total }}</span></a>@endforeach
                </div>
            @endif
        </section>
    @endif

    {{-- HOW IT WORKS --}}
    <section>
        <div class="sec-head">
            <div><h2>How it works</h2></div>
            <a href="{{ route('how-it-works') }}" wire:navigate>The full walkthrough →</a>
        </div>
        <div class="how">
            <div><div class="n">1</div><b>Find it</b><p>Browse cars from licensed dealers, each with its all-in price.</p></div>
            <div><div class="n">2</div><b>Hold it for $150</b><p>The car is marked reserved for you. Refundable until delivery.</p></div>
            <div><div class="n">3</div><b>Confirm it</b><p>Verify your ID and the dealership reaches out about the details.</p></div>
            <div><div class="n">4</div><b>Drive it</b><p>Pick it up or have it delivered. Your $150 comes off the price.</p></div>
        </div>
    </section>

    {{-- QUESTIONS --}}
    <section class="faq-wrap">
        <div><h2>Questions</h2></div>
        <div class="faq">
            @foreach ($faqs as [$question, $answer])
                <details><summary>{{ $question }}</summary><p>{{ $answer }}</p></details>
            @endforeach
        </div>
    </section>

    {{-- CLOSE --}}
    <section class="close {{ $closer ? '' : 'no-photo' }}">
        <div>
            <h2>{{ number_format($carCount) }} {{ $carCount === 1 ? 'car' : 'cars' }}, all priced all-in.</h2>
            <p>From {{ $dealerCount }} licensed {{ $dealerCount === 1 ? 'dealer' : 'dealers' }}{{ $cityCount > 1 ? ' in ' . $cityCount . ' cities' : '' }}.</p>
            <a class="btn-go" href="{{ route('shop') }}" wire:navigate>Start shopping</a>
        </div>
        @if ($closer) <figure class="shot"><img src="{{ $closer->primary_photo_url }}" alt="" loading="lazy"></figure> @endif
    </section>
</div>
</div>
