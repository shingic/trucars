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
        return (clone $withPhotos)->where('vehicles.dealer_id', $dealerId)->with('dealer')
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
            ->map(fn ($dealerId) => (clone $withPhotos)->where('vehicles.dealer_id', $dealerId)->with('dealer')->withReservedFlag()
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

    // Two-tone icons: .d parts take the badge's colour, lines are ink.
    $icons = [
        'tag'    => '<path class="d" d="M3.5 12.2V4.5a1 1 0 0 1 1-1h7.7l8.3 8.3a1.5 1.5 0 0 1 0 2.1l-6.6 6.6a1.5 1.5 0 0 1-2.1 0Z"/><circle cx="8" cy="8" r="1.6"/><path d="m10.5 13.5 2 2 4-4"/>',
        'store'  => '<path class="d" d="M4 20.5V10l8-5 8 5v10.5Z"/><path d="M10 20.5v-5h4v5M12 5V1.8h4l-1 1.3 1 1.3h-4M2.5 20.5h19"/>',
        'key'    => '<circle class="d" cx="8" cy="15.5" r="4.8"/><circle cx="8" cy="15.5" r="1.4"/><path d="M11.4 12.1 20 3.5M17.2 6.3l2.4 2.4M14.6 8.9l1.9 1.9"/>',
        'search' => '<circle class="d" cx="10.5" cy="10.5" r="6.8"/><path d="m15.5 15.5 5.5 5.5M7 12.5h7M7.6 12.5l1.2-2.6h3.4l1.2 2.6"/>',
        'lock'   => '<rect class="d" x="5" y="10.5" width="14" height="10.5" rx="2.2"/><path d="M8 10.5V7.5a4 4 0 0 1 8 0v3M12 14.5V17"/>',
        'id'     => '<rect class="d" x="3" y="5.5" width="18" height="13" rx="2.2"/><circle cx="8.5" cy="10.8" r="2"/><path d="M5.8 15.8c.6-1.4 1.6-2.1 2.7-2.1s2.1.7 2.7 2.1M14 10h4M14 13h3"/>',
        'wheel'  => '<circle class="d" cx="12" cy="12" r="8.8"/><circle cx="12" cy="12" r="2.4"/><path d="M3.6 10.6h6M14.4 10.6h6M12 14.4v6.4"/>',
        'piggy'  => '<path class="d" d="M5 11.5C5 8 8.4 6 12 6c2 0 3.6.5 4.8 1.4L19.5 6l-.4 3.2c1 .9 1.4 1.8 1.6 2.8H22v3h-1.6c-.5 1.2-1.4 2.2-2.6 2.8V20h-3v-1.5h-4V20H8v-2.3C6 16.6 5 14.3 5 11.5Z"/><circle cx="16.5" cy="10.5" r=".9"/><path d="M10 6.3V4.5h3v1.6M5 11.5H3"/>',
        'grid'   => '<rect class="d" x="3.5" y="3.5" width="7" height="7" rx="1.8"/><rect x="13.5" y="3.5" width="7" height="7" rx="1.8"/><rect x="3.5" y="13.5" width="7" height="7" rx="1.8"/><rect class="d" x="13.5" y="13.5" width="7" height="7" rx="1.8"/>',
        'ribbon' => '<circle class="d" cx="12" cy="9.5" r="6.2"/><path d="m9.4 9.6 1.8 1.8 3.4-3.5M8.6 14.6 7 21l5-2.6 5 2.6-1.6-6.4"/>',
        'chat'   => '<path class="d" d="M4 5.5h16a1 1 0 0 1 1 1V16a1 1 0 0 1-1 1h-9l-4.5 3.5V17H4a1 1 0 0 1-1-1V6.5a1 1 0 0 1 1-1Z"/><path d="M10 9.3a2 2 0 1 1 2.8 1.8c-.5.3-.8.7-.8 1.3M12 14.3v.2"/>',
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
        .home { --peach:#FFE6D6; --sky:#DDEEFF; --mint:#DDF4E6; --butter:#FFF1C2; --lilac:#ECE5FF; --rose:#FFE0EA;
            --peach-ink:#F5631F; --sky-ink:#2F7BEA; --mint-ink:#1E9E5A; --butter-ink:#E3A008; --lilac-ink:#7B5CE0; --rose-ink:#E0457B;
            min-width:0; }
        .home > * { min-width:0; }
        .home section { margin-top:96px; }
        .home h2 { font-size:clamp(28px, 3vw, 38px); font-weight:700; letter-spacing:-.03em; line-height:1.1; margin:0; }
        .sec-head { display:flex; align-items:flex-end; justify-content:space-between; gap:20px; flex-wrap:wrap; margin-bottom:28px; }
        .sec-head p { margin:8px 0 0; color:var(--ink-2); font-size:16px; }
        .sec-head > a { font-size:15px; font-weight:600; color:var(--ink); text-decoration:none; border-bottom:1.5px solid var(--ink); padding-bottom:1px; }

        /* icons: ink lines, the .d parts filled with the badge colour */
        .ico { width:30px; height:30px; fill:none; stroke:var(--ink); stroke-width:1.6; stroke-linecap:round; stroke-linejoin:round; }
        .ico .d { fill:var(--duo, var(--peach-ink)); }
        .badge { flex-shrink:0; width:52px; height:52px; border-radius:16px; display:grid; place-items:center; background:var(--bg-tone, var(--peach)); }
        .badge.peach  { --bg-tone:var(--peach);  --duo:var(--peach-ink); }
        .badge.sky    { --bg-tone:var(--sky);    --duo:var(--sky-ink); }
        .badge.mint   { --bg-tone:var(--mint);   --duo:var(--mint-ink); }
        .badge.butter { --bg-tone:var(--butter); --duo:var(--butter-ink); }
        .badge.lilac  { --bg-tone:var(--lilac);  --duo:var(--lilac-ink); }
        .badge .ico .d { opacity:.85; }

        /* the little cars */
        .ride { overflow:visible; }
        .ride .paint { fill:var(--duo, var(--peach-ink)); stroke:var(--ink); stroke-width:1.6; stroke-linejoin:round; }
        .ride .glass { fill:#fff; stroke:var(--ink); stroke-width:1.3; stroke-linejoin:round; }
        .ride .tyre { fill:var(--ink); }
        .ride .hub { fill:#D9DCE0; }

        /* hero: the car is the picture */
        .stage { position:relative; min-height:600px; border-radius:28px; overflow:hidden; background:#1b1d22 center/cover; display:flex; align-items:flex-end; }
        .stage::before { content:""; position:absolute; inset:0; background:linear-gradient(90deg, rgba(12,13,16,.82) 0%, rgba(12,13,16,.55) 42%, rgba(12,13,16,0) 75%), linear-gradient(0deg, rgba(12,13,16,.55), rgba(12,13,16,0) 45%); }
        .stage-inner { position:relative; padding:56px; width:100%; display:flex; align-items:flex-end; justify-content:space-between; gap:32px; flex-wrap:wrap; }
        .stage h1 { color:#fff; font-size:clamp(42px, 5.4vw, 76px); font-weight:700; letter-spacing:-.045em; line-height:1; margin:0; max-width:11ch; }
        .stage p { color:rgba(255,255,255,.82); font-size:18px; margin:18px 0 0; max-width:460px; }
        .hero-search { margin-top:30px; display:flex; align-items:center; gap:8px; background:#fff; border-radius:999px; padding:7px 7px 7px 22px; max-width:560px; box-shadow:0 20px 50px rgba(0,0,0,.3); }
        .hero-search svg { color:var(--ink-3); flex-shrink:0; }
        .hero-search input { flex:1; min-width:0; border:none; outline:none; font-size:16px; background:none; padding:10px 4px; color:var(--ink); }
        .hero-search button { background:var(--primary); color:#fff; font-size:15px; font-weight:700; padding:12px 24px; border-radius:999px; }
        .hero-search button:hover { background:var(--primary-press); }
        .chips { display:flex; gap:8px; flex-wrap:wrap; margin-top:16px; }
        .chips a { display:inline-flex; align-items:center; gap:7px; font-size:14px; font-weight:600; color:#fff; text-decoration:none; padding:7px 14px 7px 10px; border-radius:999px; background:rgba(255,255,255,.14); border:1px solid rgba(255,255,255,.25); backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px); transition:background .15s ease, color .15s ease; }
        .chips a:hover { background:#fff; color:var(--ink); }
        .chips .ride { width:26px; height:13px; --duo:#FFB98A; }
        .chips .ride .paint, .chips .ride .glass { stroke-width:2.2; }
        .pictured { color:#fff; text-decoration:none; font-size:14px; background:rgba(255,255,255,.14); backdrop-filter:blur(12px); -webkit-backdrop-filter:blur(12px); border:1px solid rgba(255,255,255,.22); border-radius:14px; padding:12px 16px; }
        .pictured span { display:block; font-size:12px; color:rgba(255,255,255,.7); }
        .pictured b { font-size:18px; }

        .facts { display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); margin-top:20px; border:1px solid var(--line); border-radius:22px; }
        .fact { padding:22px 24px; display:flex; gap:16px; align-items:center; }
        .fact + .fact { border-left:1px solid var(--line); }
        .fact b { display:block; font-size:16.5px; }
        .fact span { font-size:14px; color:var(--ink-2); }

        /* cars */
        .cars { display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:28px 22px; }
        .car { display:block; text-decoration:none; color:var(--ink); }
        .car-photo { position:relative; aspect-ratio:4/3; border-radius:16px; background:var(--bg-2) center/cover; transition:transform .25s ease; }
        .car:hover .car-photo { transform:scale(1.02); }
        .car-photo .reserved { position:absolute; top:10px; left:10px; background:#fff; color:#7A4E00; font-size:12px; font-weight:700; padding:4px 10px; border-radius:999px; }
        .car-name { font-size:16px; font-weight:600; margin-top:12px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .car-meta { font-size:13.5px; color:var(--ink-3); margin-top:2px; }
        .car-price { font-size:19px; font-weight:700; margin-top:8px; letter-spacing:-.01em; }
        .car-price small { font-size:13px; font-weight:500; color:var(--ink-3); }

        /* shop by: a little car per type that drives off when you point at it */
        .garage { display:grid; grid-template-columns:repeat(6, minmax(0, 1fr)); gap:16px; }
        .bay { position:relative; display:flex; flex-direction:column; justify-content:space-between; gap:18px; min-height:190px; padding:20px; border-radius:24px; background:var(--tone); text-decoration:none; color:var(--ink); overflow:hidden; transition:transform .2s ease; }
        .bay:hover { transform:translateY(-4px); }
        .bay b { display:block; font-size:19px; font-weight:700; letter-spacing:-.01em; }
        .bay span { font-size:13.5px; color:var(--ink-2); }
        .bay .ride { width:100%; max-width:132px; height:auto; transition:transform .5s cubic-bezier(.3,.7,.3,1); }
        .bay:hover .ride { animation:vroom .7s cubic-bezier(.3,.7,.3,1) forwards; }
        .bay .ico { width:58px; height:58px; stroke-width:1.3; }
        .bay:hover .ico { transform:rotate(-8deg) scale(1.06); }
        .bay .ico { transition:transform .25s ease; }
        .bay.peach  { --tone:var(--peach);  --duo:var(--peach-ink); }
        .bay.sky    { --tone:var(--sky);    --duo:var(--sky-ink); }
        .bay.mint   { --tone:var(--mint);   --duo:var(--mint-ink); }
        .bay.butter { --tone:var(--butter); --duo:var(--butter-ink); }
        .bay.lilac  { --tone:var(--lilac);  --duo:var(--lilac-ink); }
        .bay.rose   { --tone:var(--rose);   --duo:var(--rose-ink); }
        /* a little rev-and-go when you point at a type */
        @keyframes vroom { 0% { transform:none; } 25% { transform:translateX(-4px) rotate(-2deg); } 60% { transform:translateX(14px) rotate(1deg); } 100% { transform:translateX(10px); } }
        @media (prefers-reduced-motion:reduce) { .bay:hover .ride { animation:none; } }
        .makes { display:flex; flex-wrap:wrap; gap:8px; margin-top:18px; }
        .makes a { font-size:14px; font-weight:600; color:var(--ink); text-decoration:none; padding:8px 14px; border-radius:999px; border:1px solid var(--line-strong); }
        .makes a span { color:var(--ink-3); margin-left:3px; }
        .makes a:hover { border-color:var(--ink); }

        .car-photo .cert { position:absolute; left:10px; bottom:10px; display:inline-flex; align-items:center; gap:4px; max-width:calc(100% - 20px); background:#fff; color:var(--ink); font-size:12px; font-weight:700; padding:3px 10px 3px 4px; border-radius:999px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; --duo:var(--sky-ink); }
        .car-photo .cert .ico { width:20px; height:20px; flex-shrink:0; }
        /* no haggling: the lines everyone has heard at a sales desk, crossed out */
        .calm { display:grid; grid-template-columns:minmax(0, 1fr) minmax(0, 1fr); gap:56px; align-items:center; }
        .calm p { font-size:17px; color:var(--ink-2); margin:14px 0 0; max-width:460px; }
        .chat { display:flex; flex-direction:column; gap:10px; }
        .chat small { font-size:13px; font-weight:600; color:var(--ink-3); margin:0 0 2px 4px; }
        .said { align-self:flex-start; max-width:88%; background:var(--bg-2); color:var(--ink-2); font-size:16px; padding:12px 18px; border-radius:20px 20px 20px 6px; text-decoration:line-through; text-decoration-color:var(--primary); text-decoration-thickness:2.5px; }
        .said:nth-of-type(2) { margin-left:28px; }
        .said:nth-of-type(4) { margin-left:14px; }
        .reply { align-self:flex-end; display:flex; align-items:flex-end; gap:10px; max-width:92%; margin-top:10px; }
        .reply p { margin:0 !important; background:var(--primary); color:#fff !important; font-size:16px !important; font-weight:500; padding:14px 18px; border-radius:20px 20px 6px 20px; }
        .reply span { flex-shrink:0; width:34px; height:34px; border-radius:50%; background:var(--ink); color:#fff; display:grid; place-items:center; font-weight:800; font-size:14px; }

        /* how it works */
        .how { display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:32px; }
        .how div { position:relative; }
        .how .badge { width:64px; height:64px; border-radius:20px; margin-bottom:18px; }
        .how .badge .ico { width:38px; height:38px; stroke-width:1.4; }
        .how small { font-size:13px; font-weight:600; color:var(--ink-3); }
        .how b { display:block; font-size:19px; letter-spacing:-.01em; margin:4px 0 6px; }
        .how p { margin:0; font-size:15px; color:var(--ink-2); }
        .how div:not(:last-child)::after { content:""; position:absolute; top:32px; left:80px; right:-20px; border-top:2px dashed var(--line-strong); }

        /* questions + close */
        .faq-wrap { display:grid; grid-template-columns:minmax(0, .8fr) minmax(0, 1.2fr); gap:56px; }
        .faq-wrap .badge { margin-bottom:18px; }
        .faq details { border-bottom:1px solid var(--line); }
        .faq details:first-child { border-top:1px solid var(--line); }
        .faq summary { cursor:pointer; list-style:none; display:flex; justify-content:space-between; gap:16px; padding:20px 0; font-size:17px; font-weight:600; }
        .faq summary::-webkit-details-marker { display:none; }
        .faq summary::after { content:"+"; font-size:22px; line-height:1; color:var(--ink-3); transition:transform .2s ease; }
        .faq details[open] summary::after { transform:rotate(45deg); color:var(--primary); }
        .faq p { margin:0 0 20px; font-size:15.5px; color:var(--ink-2); }
        .close { position:relative; border-radius:28px; overflow:hidden; background:#1b1d22 center/cover; min-height:360px; display:flex; align-items:center; }
        .close::before { content:""; position:absolute; inset:0; background:rgba(12,13,16,.62); }
        .close div { position:relative; padding:56px; color:#fff; }
        .close h2 { color:#fff; font-size:clamp(32px, 4vw, 52px); }
        .close p { color:rgba(255,255,255,.8); font-size:17px; margin:12px 0 26px; }
        .btn-light { display:inline-block; background:#fff; color:var(--ink); font-weight:700; font-size:16px; padding:15px 26px; border-radius:12px; text-decoration:none; }
        .btn-light:hover { background:var(--primary); color:#fff; }

        @media (max-width:1100px) {
            .cars { grid-template-columns:repeat(2, minmax(0, 1fr)); }
            .garage { grid-template-columns:repeat(3, minmax(0, 1fr)); }
            .how { grid-template-columns:repeat(2, minmax(0, 1fr)); }
            .how div::after { display:none; }
            .faq-wrap { grid-template-columns:minmax(0, 1fr); gap:24px; }
            .calm { grid-template-columns:minmax(0, 1fr); gap:32px; }
        }
        @media (max-width:640px) {
            .home section { margin-top:64px; }
            .stage { min-height:620px; border-radius:22px; }
            .stage::before { background:linear-gradient(0deg, rgba(12,13,16,.9) 0%, rgba(12,13,16,.6) 55%, rgba(12,13,16,.15) 100%); }
            .stage-inner { padding:26px 20px; }
            .hero-search { padding-left:16px; }
            .hero-search button { padding:11px 16px; }
            .facts { grid-template-columns:minmax(0, 1fr); }
            .fact + .fact { border-left:none; border-top:1px solid var(--line); }
            .fact { padding:16px 18px; }
            .cars { gap:22px 12px; }
            .car-price { font-size:17px; }
            .garage { grid-template-columns:repeat(2, minmax(0, 1fr)); gap:12px; }
            .bay { min-height:150px; padding:16px; border-radius:20px; }
            .bay b { font-size:17px; }
            .how { grid-template-columns:minmax(0, 1fr); gap:28px; }
            .close div { padding:32px 22px; }
            .said { font-size:15px; }
            .said:nth-of-type(2), .said:nth-of-type(4) { margin-left:0; }
        }
    </style>

    {{-- HERO --}}
    <div class="stage" @if ($featured) style="background-image:url('{{ $featured->primary_photo_url }}')" @endif>
        <div class="stage-inner">
            <div>
                <h1>Your next car, at the real price.</h1>
                <p>Certified pre-owned and used cars from licensed dealerships, with freight, prep and fees already in the price. Just add HST and licensing.</p>

                <form class="hero-search" wire:submit="search" role="search">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
                    <input type="search" wire:model="q" placeholder="Search a make or model, like “Outlander”" aria-label="Search cars">
                    <button type="submit">Search</button>
                </form>

                <div class="chips">
                    @foreach ($bodyTypes->keys() as $body)
                        <a href="{{ route('shop', ['body' => [$body]]) }}" wire:navigate>{!! $car($body) !!} {{ $body }}s</a>
                    @endforeach
                    @if ($under) <a href="{{ route('shop', ['max' => 25000]) }}" wire:navigate>Under $25k</a> @endif
                    <a href="{{ route('shop') }}" wire:navigate>Everything →</a>
                </div>
            </div>
            @if ($featured)
                <a class="pictured" href="/cars/{{ $featured->id }}" wire:navigate>
                    <span>Pictured · {{ $featured->display_location }}</span>
                    {{ $featured->model_year }} {{ $featured->make }} {{ $featured->model }} · <b>{{ $featured->display_price }}</b>
                </a>
            @endif
        </div>
    </div>

    <div class="facts">
        <div class="fact"><span class="badge peach">{!! $icon('tag') !!}</span><div><b>All-in prices</b><span>Freight, prep and dealer fees included.</span></div></div>
        <div class="fact"><span class="badge sky">{!! $icon('store') !!}</span><div><b>Licensed dealers only</b><span>Every listing names the dealership.</span></div></div>
        <div class="fact"><span class="badge mint">{!! $icon('key') !!}</span><div><b>Hold it for $150</b><span>Refundable until you take delivery.</span></div></div>
    </div>

    {{-- ON THE LOT NOW --}}
    @if ($mix->isNotEmpty())
        <section>
            <div class="sec-head">
                <div><h2>On the lot now</h2><p>A mix from our dealers. It changes every visit.</p></div>
                <a href="{{ route('shop') }}" wire:navigate>See all {{ number_format($carCount) }} cars</a>
            </div>
            <div class="cars">
                @foreach ($mix as $listing)
                    <a class="car" href="/cars/{{ $listing->id }}" wire:navigate wire:key="mix-{{ $listing->id }}">
                        <div class="car-photo" style="background-image:url('{{ $listing->primary_photo_url }}')">
                            @if ($listing->is_reserved)<span class="reserved">Reserved</span>@endif
                            @if ($certified = $listing->certification())<span class="cert">{!! $icon('ribbon') !!} {{ $certified->badge }}</span>@endif
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
        <div class="chat">
            <small>Heard at the sales desk</small>
            <div class="said">Let me go talk to my manager.</div>
            <div class="said">This price is only good today.</div>
            <div class="said">Freight and admin are extra, of course.</div>
            <div class="said">What would it take to get you into this car today?</div>
            <div class="reply"><p>The price on the listing is the price, fees included. Take your time, and hold it for $150 if you want to think it over.</p><span>T</span></div>
        </div>
    </section>

    {{-- SHOP BY --}}
    @if ($bodyTypes->isNotEmpty())
        <section>
            <div class="sec-head"><div><h2>What are you after?</h2><p>Pick a type and we'll show you what's on the lot.</p></div></div>
            @php $tones = ['peach', 'sky', 'mint', 'butter']; @endphp
            <div class="garage">
                @foreach ($bodyTypes as $body => $total)
                    <a class="bay {{ $tones[$loop->index % 4] }}" href="{{ route('shop', ['body' => [$body]]) }}" wire:navigate>
                        {!! $car($body) !!}
                        <div><b>{{ $body }}s</b><span>{{ $total }} {{ $total === 1 ? 'car' : 'cars' }}</span></div>
                    </a>
                @endforeach
                @if ($under)
                    <a class="bay lilac" href="{{ route('shop', ['max' => 25000]) }}" wire:navigate>
                        {!! $icon('piggy') !!}
                        <div><b>Under $25k</b><span>{{ $under }} {{ $under === 1 ? 'car' : 'cars' }}</span></div>
                    </a>
                @endif
                <a class="bay rose" href="{{ route('shop') }}" wire:navigate>
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
            <a href="{{ route('how-it-works') }}" wire:navigate>The full walkthrough</a>
        </div>
        <div class="how">
            <div><span class="badge peach">{!! $icon('search') !!}</span><small>Step 1</small><b>Find it</b><p>Browse cars from licensed dealers, each with its all-in price.</p></div>
            <div><span class="badge sky">{!! $icon('lock') !!}</span><small>Step 2</small><b>Hold it for $150</b><p>The car is marked reserved for you. Refundable until delivery.</p></div>
            <div><span class="badge mint">{!! $icon('id') !!}</span><small>Step 3</small><b>Confirm it</b><p>Verify your ID and the dealership reaches out about the details.</p></div>
            <div><span class="badge butter">{!! $icon('wheel') !!}</span><small>Step 4</small><b>Drive it</b><p>Pick it up or have it delivered. Your $150 comes off the price.</p></div>
        </div>
    </section>

    {{-- QUESTIONS --}}
    <section class="faq-wrap">
        <div><span class="badge lilac">{!! $icon('chat') !!}</span><h2>Questions</h2></div>
        <div class="faq">
            @foreach ($faqs as [$question, $answer])
                <details><summary>{{ $question }}</summary><p>{{ $answer }}</p></details>
            @endforeach
        </div>
    </section>

    {{-- CLOSE --}}
    <section class="close" @if ($closer) style="background-image:url('{{ $closer->primary_photo_url }}')" @endif>
        <div>
            <h2>{{ number_format($carCount) }} cars, all priced all-in.</h2>
            <p>From {{ $dealerCount }} licensed {{ $dealerCount === 1 ? 'dealer' : 'dealers' }}{{ $cityCount > 1 ? ' in ' . $cityCount . ' cities' : '' }}.</p>
            <a class="btn-light" href="{{ route('shop') }}" wire:navigate>Start shopping</a>
        </div>
    </section>
</div>
