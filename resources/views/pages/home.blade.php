<?php

use App\Models\Dealer;
use App\Models\Vehicle;
use Illuminate\Support\Collection;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
| The public homepage — where someone who finds TruCars lands before they
| shop. Everything on it is live: the counts, the cars and the featured photo
| are read from what's actually listed (published cars at active dealers).
*/
new #[Title('TruCars — Real dealers. Real prices.')] class extends Component {
    public const PRICE_STEPS = [10000, 15000, 20000, 25000, 30000, 40000, 50000];

    public string $q = '';
    public string $make = '';
    public string $max = '';

    /** The search box opens the shop with the search already applied. */
    public function search(): void
    {
        $max = (int) $this->max;

        $this->redirectRoute('shop', array_filter([
            'q'    => trim($this->q),
            'make' => $this->make !== '' ? [$this->make] : null,
            'max'  => in_array($max, self::PRICE_STEPS, true) ? $max : null,
        ]), navigate: true);
    }

    public function with(): array
    {
        $listed = Vehicle::query()->listed();

        $latest = (clone $listed)->with('dealer')->withReservedFlag()->latest('vehicles.created_at')->limit(8)->get();
        $featured = $latest->first(fn (Vehicle $vehicle) => $vehicle->primary_photo_url !== null);
        $activeDealers = Dealer::where('status', Dealer::STATUS_ACTIVE)->whereHas('vehicles', fn ($vehicles) => $vehicles->where('is_published', true));

        return [
            'carCount'    => (clone $listed)->count(),
            'dealerCount' => (clone $activeDealers)->count(),
            'cityCount'   => (clone $activeDealers)->distinct()->count('city'),
            'latest'      => $latest,
            'featured'    => $featured,
            'bodyTypes'   => $this->countsBy($listed, 'body_type', 3),
            'makes'       => $this->countsBy($listed, 'make', 10),
            'allMakes'    => (clone $listed)->whereNotNull('make')->where('make', '!=', '')->distinct()->orderBy('make')->pluck('make'),
            'under'       => (clone $listed)->where('price_in_cents', '<', 2500000)->count(),
        ];
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
    $bodyIcons = [
        'SUV'         => '<path d="M3 15v-3l2-5h11l3 5h2v3"/><path d="M3 15h18"/><circle cx="7" cy="16.5" r="1.8"/><circle cx="17" cy="16.5" r="1.8"/><path d="M8 7v5M14 7v5"/>',
        'Sedan'       => '<path d="M2 15v-2l3-1 3-4h7l4 4 3 1v2"/><path d="M2 15h20"/><circle cx="7" cy="16.5" r="1.8"/><circle cx="17" cy="16.5" r="1.8"/>',
        'Truck'       => '<path d="M2 15V9h10v6M12 11h5l3 3v1"/><path d="M2 15h18"/><circle cx="6" cy="16.5" r="1.8"/><circle cx="16" cy="16.5" r="1.8"/>',
        'Van'         => '<path d="M2 15V8a1 1 0 0 1 1-1h12l5 5v3"/><path d="M2 15h18"/><circle cx="6" cy="16.5" r="1.8"/><circle cx="16" cy="16.5" r="1.8"/><path d="M15 7v5"/>',
        'Coupe'       => '<path d="M2 15v-2l5-1 4-4h4l5 4 2 1v2"/><path d="M2 15h20"/><circle cx="7" cy="16.5" r="1.8"/><circle cx="17" cy="16.5" r="1.8"/>',
        'Convertible' => '<path d="M2 15v-2l4-1h12l4 1v2"/><path d="M2 15h20M9 12l2-3"/><circle cx="7" cy="16.5" r="1.8"/><circle cx="17" cy="16.5" r="1.8"/>',
        'Hatchback'   => '<path d="M3 15v-3l3-4h8l5 4h2v3"/><path d="M3 15h18"/><circle cx="7" cy="16.5" r="1.8"/><circle cx="17" cy="16.5" r="1.8"/>',
        'Wagon'       => '<path d="M2 15v-3l3-4h14l2 4v3"/><path d="M2 15h20"/><circle cx="7" cy="16.5" r="1.8"/><circle cx="17" cy="16.5" r="1.8"/>',
    ];
    $carIcon = '<path d="M3 15v-3l2-5h11l3 5h2v3"/><path d="M3 15h18"/><circle cx="7" cy="16.5" r="1.8"/><circle cx="17" cy="16.5" r="1.8"/>';
    $tickerItems = ['All-in prices', 'Licensed dealers only', 'Hold any car for $150', 'Refundable until delivery', 'Pick up or delivery'];
@endphp

<div class="home">
    <link href="https://fonts.googleapis.com/css2?family=Archivo:wdth,wght@62..100,800..900&display=swap" rel="stylesheet">
    <style>
        .home { --display:"Archivo", var(--font-ui); --sun:#FFD43B; --cream:#FFF3E6; --edge:2.5px solid var(--ink); --pop:5px 5px 0 var(--ink); min-width:0; }
        .home > * { min-width:0; }
        .home section { margin-top:96px; }
        .big { font-family:var(--display); font-stretch:75%; font-weight:900; text-transform:uppercase; letter-spacing:-.01em; line-height:.92; margin:0; }
        .home h2.big { font-size:clamp(40px, 5.4vw, 72px); }
        .sec-head { display:flex; align-items:flex-end; justify-content:space-between; gap:20px; flex-wrap:wrap; margin-bottom:30px; }
        .btn-pop { display:inline-flex; align-items:center; gap:10px; font-size:16px; font-weight:800; color:var(--ink); background:#fff; border:var(--edge); border-radius:14px; padding:13px 22px; text-decoration:none; box-shadow:var(--pop); transition:transform .12s ease, box-shadow .12s ease; white-space:nowrap; }
        .btn-pop:hover { transform:translate(-2px, -2px); box-shadow:7px 7px 0 var(--ink); }
        .btn-pop:active { transform:translate(3px, 3px); box-shadow:2px 2px 0 var(--ink); }
        .btn-pop.dark { background:var(--ink); color:#fff; box-shadow:5px 5px 0 var(--primary); }
        .btn-pop.dark:hover { box-shadow:7px 7px 0 var(--primary); }

        /* ---- hero ---- */
        .hero { display:grid; grid-template-columns:minmax(0, 1.1fr) minmax(0, .9fr); gap:48px; align-items:center; background:var(--primary); border:var(--edge); border-radius:32px; padding:60px 56px; }
        .hero h1 { font-size:clamp(54px, 7.6vw, 112px); color:var(--ink); }
        .hero h1 span { color:#fff; display:block; }
        .hero-sub { font-size:19px; font-weight:600; color:var(--ink); margin:22px 0 0; max-width:500px; }
        .finder { margin-top:30px; display:flex; background:#fff; border:var(--edge); border-radius:18px; box-shadow:var(--pop); max-width:640px; overflow:hidden; }
        .finder input, .finder select { font:inherit; font-size:16px; font-weight:600; color:var(--ink); border:none; outline:none; background:none; padding:17px 16px; min-width:0; }
        .finder input { flex:1; }
        .finder select { border-left:2px solid var(--line-strong); cursor:pointer; max-width:150px; }
        .finder button { font-size:17px; font-weight:900; text-transform:uppercase; letter-spacing:.02em; background:var(--ink); color:#fff; padding:0 26px; }
        .finder button:hover { background:#000; color:var(--sun); }
        .hero-chips { display:flex; flex-wrap:wrap; gap:9px; margin-top:20px; }
        .hero-chips a { font-size:14px; font-weight:800; color:var(--ink); background:var(--sun); border:2px solid var(--ink); border-radius:999px; padding:7px 15px; text-decoration:none; }
        .hero-chips a:hover { background:#fff; }

        .hero-car { position:relative; display:block; text-decoration:none; color:var(--ink); }
        .hero-photo { aspect-ratio:4/3; border:var(--edge); border-radius:24px; background:var(--cream) center/cover; box-shadow:10px 10px 0 var(--ink); }
        .hero-car:hover .hero-photo { box-shadow:12px 12px 0 var(--ink); transform:translate(-2px, -2px); }
        .hero-photo { transition:transform .15s ease, box-shadow .15s ease; }
        .sticker { position:absolute; right:-18px; top:-26px; width:132px; height:132px; border-radius:50%; background:var(--sun); border:var(--edge); display:grid; place-content:center; text-align:center; transform:rotate(8deg); }
        .sticker small { font-size:12px; font-weight:900; text-transform:uppercase; letter-spacing:.05em; }
        .sticker b { font-family:var(--display); font-stretch:75%; font-weight:900; font-size:30px; line-height:1; }
        .hero-label { position:absolute; left:16px; bottom:16px; background:#fff; border:2px solid var(--ink); border-radius:12px; padding:8px 13px; font-size:14px; font-weight:800; max-width:calc(100% - 32px); }
        .hero-label span { display:block; font-size:12.5px; font-weight:600; color:var(--ink-2); }

        /* ---- ticker ---- */
        .ticker { margin-top:28px; background:var(--ink); color:var(--sun); border-radius:18px; overflow:hidden; white-space:nowrap; }
        .ticker-run { display:inline-flex; animation:ticker 32s linear infinite; }
        .ticker-run span { font-family:var(--display); font-stretch:75%; font-weight:900; font-size:24px; text-transform:uppercase; padding:16px 0; }
        .ticker-run span::after { content:"✶"; color:var(--primary); margin:0 26px; }
        @keyframes ticker { to { transform:translateX(-50%); } }
        @media (prefers-reduced-motion:reduce) { .ticker-run { animation:none; } }

        /* ---- shop by ---- */
        .tiles { display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:20px; }
        .tile { position:relative; display:flex; flex-direction:column; justify-content:space-between; min-height:210px; padding:22px; border:var(--edge); border-radius:24px; color:var(--ink); text-decoration:none; overflow:hidden; transition:transform .12s ease, box-shadow .12s ease; }
        .tile:hover { transform:translate(-3px, -3px); box-shadow:var(--pop); }
        .tile b { font-family:var(--display); font-stretch:75%; font-weight:900; font-size:44px; line-height:.9; text-transform:uppercase; }
        .tile em { font-style:normal; font-size:15px; font-weight:800; }
        .tile svg { position:absolute; right:-14px; bottom:-18px; width:150px; height:150px; opacity:.9; }
        .tile:nth-child(4n+1) { background:var(--sun); }
        .tile:nth-child(4n+2) { background:var(--cream); }
        .tile:nth-child(4n+3) { background:var(--ink); color:#fff; }
        .tile:nth-child(4n+3) svg { color:var(--primary); }
        .tile:nth-child(4n+4) { background:var(--primary); }
        .make-row { display:flex; flex-wrap:wrap; gap:10px; margin-top:22px; }
        .make-row a { font-size:15px; font-weight:800; color:var(--ink); text-decoration:none; border:2px solid var(--ink); border-radius:999px; padding:9px 16px; background:#fff; }
        .make-row a span { color:var(--ink-3); margin-left:4px; }
        .make-row a:hover { background:var(--ink); color:#fff; }

        /* ---- cars ---- */
        .cars { display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:22px; }
        .car { display:block; text-decoration:none; color:var(--ink); background:#fff; border:var(--edge); border-radius:22px; overflow:hidden; transition:transform .12s ease, box-shadow .12s ease; }
        .car:hover { transform:translate(-3px, -3px); box-shadow:var(--pop); }
        .car-photo { position:relative; aspect-ratio:4/3; background:var(--cream) center/cover; border-bottom:var(--edge); display:grid; place-items:center; color:var(--ink-3); font-size:13px; font-weight:700; }
        .car-photo .reserved { position:absolute; top:10px; left:10px; background:var(--sun); border:2px solid var(--ink); color:var(--ink); border-radius:999px; padding:3px 10px; font-size:11.5px; font-weight:900; text-transform:uppercase; }
        .car-body { padding:14px 16px 16px; }
        .car-name { font-size:16px; font-weight:800; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .car-meta { font-size:13px; font-weight:600; color:var(--ink-2); margin-top:2px; }
        .car-price { display:flex; align-items:center; justify-content:space-between; margin-top:12px; }
        .car-price b { font-family:var(--display); font-stretch:75%; font-weight:900; font-size:30px; line-height:1; }
        .car-price span { font-size:11px; font-weight:900; text-transform:uppercase; background:var(--ink); color:#fff; border-radius:6px; padding:4px 7px; }

        /* ---- the deal ---- */
        .deal { background:var(--cream); border:var(--edge); border-radius:32px; padding:56px; display:grid; grid-template-columns:minmax(0, 1fr) minmax(0, 380px); gap:56px; align-items:start; }
        .steps { list-style:none; margin:34px 0 0; padding:0; display:grid; grid-template-columns:1fr 1fr; gap:30px 36px; counter-reset:step; }
        .steps li { counter-increment:step; font-size:15.5px; font-weight:500; color:var(--ink-2); }
        .steps li::before { content:counter(step); display:block; font-family:var(--display); font-stretch:75%; font-weight:900; font-size:64px; line-height:.85; color:var(--primary); -webkit-text-stroke:2.5px var(--ink); margin-bottom:10px; }
        .steps b { display:block; font-size:19px; font-weight:900; color:var(--ink); margin-bottom:3px; }

        /* a dealer window sticker */
        .window { background:#fff; border:var(--edge); border-radius:6px; box-shadow:var(--pop); font-size:14.5px; }
        .window-top { background:var(--ink); color:#fff; padding:12px 18px; font-family:var(--display); font-stretch:75%; font-weight:900; font-size:22px; text-transform:uppercase; letter-spacing:.01em; }
        .window-body { padding:6px 18px 18px; }
        .window-row { display:flex; justify-content:space-between; gap:12px; padding:10px 0; border-bottom:1.5px dashed var(--line-strong); }
        .window-row b { font-weight:900; }
        .window-row.in b { color:#0B7A59; }
        .window-row.add b { color:var(--ink-2); font-weight:700; }
        .window-total { display:flex; justify-content:space-between; align-items:baseline; margin-top:14px; font-weight:900; text-transform:uppercase; font-size:13px; }
        .window-total b { font-family:var(--display); font-stretch:75%; font-size:38px; line-height:1; }
        .window-car { font-size:12.5px; font-weight:600; color:var(--ink-3); margin-top:4px; text-align:right; }

        /* ---- questions ---- */
        .faq-wrap { display:grid; grid-template-columns:minmax(0, .8fr) minmax(0, 1.2fr); gap:48px; align-items:start; }
        .faq { display:grid; gap:12px; }
        .faq details { background:#fff; border:var(--edge); border-radius:18px; }
        .faq details[open] { box-shadow:var(--pop); }
        .faq summary { cursor:pointer; list-style:none; display:flex; justify-content:space-between; align-items:center; gap:16px; padding:18px 20px; font-size:17px; font-weight:800; }
        .faq summary::-webkit-details-marker { display:none; }
        .faq summary::after { content:"+"; flex-shrink:0; width:30px; height:30px; border-radius:50%; background:var(--sun); border:2px solid var(--ink); display:grid; place-items:center; font-weight:900; line-height:1; }
        .faq details[open] summary::after { content:"−"; background:var(--primary); }
        .faq p { margin:0; padding:0 20px 20px; font-size:15.5px; color:var(--ink-2); }

        /* ---- last call ---- */
        .last { background:var(--sun); border:var(--edge); border-radius:32px; padding:56px; display:flex; align-items:center; justify-content:space-between; gap:30px; flex-wrap:wrap; }
        .last h2 { font-size:clamp(40px, 5.6vw, 76px); }
        .last p { margin:14px 0 0; font-size:17px; font-weight:700; }

        @media (max-width:1100px) {
            .hero { grid-template-columns:minmax(0, 1fr); padding:44px 36px 52px; }
            .hero-car { max-width:560px; }
            .tiles, .cars { grid-template-columns:repeat(2, minmax(0, 1fr)); }
            .deal, .faq-wrap { grid-template-columns:minmax(0, 1fr); }
        }
        @media (max-width:640px) {
            .home section { margin-top:64px; }
            .hero { padding:30px 20px 40px; border-radius:24px; gap:44px; }
            .hero-sub { font-size:17px; }
            .finder { flex-wrap:wrap; }
            .finder input { flex:1 1 100%; border-bottom:2px solid var(--line-strong); }
            .finder select { flex:1; max-width:none; border-left:none; }
            .finder select + select { border-left:2px solid var(--line-strong); }
            .finder button { flex:1 1 100%; padding:15px; }
            .sticker { width:104px; height:104px; right:-6px; top:-30px; }
            .sticker b { font-size:24px; }
            .ticker-run span { font-size:19px; padding:12px 0; }
            .tiles { gap:12px; }
            .tile { min-height:150px; padding:16px; border-radius:20px; }
            .tile b { font-size:32px; }
            .tile svg { width:100px; height:100px; }
            .cars { gap:12px; }
            .car { border-radius:16px; }
            .car-body { padding:10px 11px 12px; }
            .car-name { font-size:14px; }
            .car-meta { font-size:12px; }
            .car-price { margin-top:8px; }
            .car-price b { font-size:22px; }
            .car-price span { display:none; }
            .deal, .last { padding:32px 20px; border-radius:24px; }
            .steps { grid-template-columns:minmax(0, 1fr); }
        }
    </style>

    {{-- HERO --}}
    <div class="hero">
        <div>
            <h1 class="big">Real cars. Real dealers. <span>All-in prices.</span></h1>
            <p class="hero-sub">Freight, prep and dealer fees are already in every price. You add HST and licensing. That's it.</p>

            <form class="finder" wire:submit="search" role="search">
                <input type="search" wire:model="q" placeholder="Search {{ number_format($carCount) }} cars" aria-label="Model or keyword">
                <select wire:model="make" aria-label="Make">
                    <option value="">Any make</option>
                    @foreach ($allMakes as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </select>
                <select wire:model="max" aria-label="Max price">
                    <option value="">Any price</option>
                    @foreach ($this::PRICE_STEPS as $step)
                        <option value="{{ $step }}">Under ${{ number_format($step / 1000) }}k</option>
                    @endforeach
                </select>
                <button type="submit">Go</button>
            </form>

            <div class="hero-chips">
                @foreach ($bodyTypes->keys() as $body)
                    <a href="{{ route('shop', ['body' => [$body]]) }}" wire:navigate>{{ $body }}s</a>
                @endforeach
                @if ($under) <a href="{{ route('shop', ['max' => 25000]) }}" wire:navigate>Under $25k</a> @endif
            </div>
        </div>

        @if ($featured)
            <a class="hero-car" href="/cars/{{ $featured->id }}" wire:navigate>
                <div class="hero-photo" style="background-image:url('{{ $featured->primary_photo_url }}')" role="img" aria-label="{{ $featured->model_year }} {{ $featured->make }} {{ $featured->model }}"></div>
                <div class="sticker"><small>All-in</small><b>{{ $featured->display_price }}</b></div>
                <div class="hero-label">{{ $featured->model_year }} {{ $featured->make }} {{ $featured->model }}<span>{{ $featured->display_kilometres }} · {{ $featured->display_location }}</span></div>
            </a>
        @endif
    </div>

    <div class="ticker" aria-hidden="true">
        <div class="ticker-run">
            @foreach ([1, 2] as $copy)
                @foreach ([...$tickerItems, ...$tickerItems] as $item)<span>{{ $item }}</span>@endforeach
            @endforeach
        </div>
    </div>

    {{-- SHOP BY --}}
    @if ($bodyTypes->isNotEmpty())
        <section>
            <div class="sec-head">
                <h2 class="big">What are you<br>driving next?</h2>
            </div>
            <div class="tiles">
                @foreach ($bodyTypes as $body => $total)
                    <a class="tile" href="{{ route('shop', ['body' => [$body]]) }}" wire:navigate>
                        <b>{{ $body }}s</b>
                        <em>{{ $total }} {{ $total === 1 ? 'car' : 'cars' }} →</em>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round">{!! $bodyIcons[$body] ?? $carIcon !!}</svg>
                    </a>
                @endforeach
                @if ($under)
                    <a class="tile" href="{{ route('shop', ['max' => 25000]) }}" wire:navigate>
                        <b>Under<br>$25k</b>
                        <em>{{ $under }} {{ $under === 1 ? 'car' : 'cars' }} →</em>
                    </a>
                @endif
            </div>
            @if ($makes->isNotEmpty())
                <div class="make-row">
                    @foreach ($makes as $name => $total)
                        <a href="{{ route('shop', ['make' => [$name]]) }}" wire:navigate>{{ $name }}<span>{{ $total }}</span></a>
                    @endforeach
                </div>
            @endif
        </section>
    @endif

    {{-- JUST LANDED --}}
    @if ($latest->isNotEmpty())
        <section>
            <div class="sec-head">
                <h2 class="big">Just landed</h2>
                <a class="btn-pop" href="{{ route('shop') }}" wire:navigate>See all {{ number_format($carCount) }} cars →</a>
            </div>
            <div class="cars">
                @foreach ($latest as $car)
                    <a class="car" href="/cars/{{ $car->id }}" wire:navigate wire:key="latest-{{ $car->id }}">
                        <div class="car-photo" @if ($car->primary_photo_url) style="background-image:url('{{ $car->primary_photo_url }}')" @endif>
                            @unless ($car->primary_photo_url) No photo yet @endunless
                            @if ($car->is_reserved)<span class="reserved">Reserved</span>@endif
                        </div>
                        <div class="car-body">
                            <div class="car-name">{{ $car->model_year }} {{ $car->make }} {{ $car->model }}</div>
                            <div class="car-meta">{{ $car->display_kilometres }} · {{ $car->display_location }}</div>
                            <div class="car-price"><b>{{ $car->display_price }}</b><span>All-in</span></div>
                        </div>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    {{-- THE DEAL --}}
    <section class="deal">
        <div>
            <h2 class="big">Here's how<br>it works</h2>
            <ol class="steps">
                <li><b>Find it</b>Every car is listed by a licensed dealership, with where it is and what it costs.</li>
                <li><b>Hold it for $150</b>The car gets marked reserved so nobody else can grab it. Refundable until delivery.</li>
                <li><b>Confirm it</b>Verify your ID and the dealership calls you to sort out the details.</li>
                <li><b>Drive it</b>Pick it up or get it delivered. Your $150 comes off the price.</li>
            </ol>
            <div style="margin-top:34px"><a class="btn-pop" href="{{ route('how-it-works') }}" wire:navigate>The full walkthrough →</a></div>
        </div>

        <div class="window">
            <div class="window-top">What's in the price</div>
            <div class="window-body">
                <div class="window-row in">Freight &amp; prep <b>Included</b></div>
                <div class="window-row in">Dealer admin fee <b>Included</b></div>
                <div class="window-row in">Regulator fee <b>Included</b></div>
                <div class="window-row in">Surprise add-ons <b>$0</b></div>
                <div class="window-row add">HST &amp; licensing <b>Added at sale</b></div>
                @if ($featured)
                    <div class="window-total">All-in price <b>{{ $featured->display_price }}</b></div>
                    <div class="window-car">{{ $featured->model_year }} {{ $featured->make }} {{ $featured->model }}</div>
                @endif
            </div>
        </div>
    </section>

    {{-- QUESTIONS --}}
    <section class="faq-wrap">
        <h2 class="big">Good<br>questions</h2>
        <div class="faq">
            @foreach ([
                ['Who am I actually buying from?', 'The licensed dealership named on the listing. TruCars is where you find the car and reserve it; the dealership sells it to you, and their registration number is on every listing.'],
                ['Is the price really all-in?', 'Yes. Freight, pre-delivery prep, dealer admin and regulator fees are already in the price you see. HST and licensing are the only things added.'],
                ['What does the $150 deposit do?', 'It takes the car off the market while you finish up, and it comes off the price when you buy. It’s refundable any time before you take delivery.'],
                ['Can I pick the car up myself?', 'Yes. Pick it up at the dealership, or choose delivery when you reserve. Either way you pick the day and time window.'],
                ['What does “certified” mean on a listing?', 'It means the car is covered by the dealer’s or manufacturer’s inspection checklist, and the listing shows every point that’s checked, not just a number.'],
            ] as [$question, $answer])
                <details>
                    <summary>{{ $question }}</summary>
                    <p>{{ $answer }}</p>
                </details>
            @endforeach
        </div>
    </section>

    {{-- LAST CALL --}}
    <section class="last">
        <div>
            <h2 class="big">{{ number_format($carCount) }} cars.<br>All in.</h2>
            <p>From {{ $dealerCount }} licensed {{ $dealerCount === 1 ? 'dealer' : 'dealers' }}{{ $cityCount > 1 ? ' in ' . $cityCount . ' cities' : '' }}. Go have a look.</p>
        </div>
        <a class="btn-pop dark" href="{{ route('shop') }}" wire:navigate>Start shopping →</a>
    </section>
</div>
