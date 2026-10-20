<?php

use App\Models\Dealer;
use App\Models\Vehicle;
use Illuminate\Support\Collection;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
| The public homepage — where someone who finds TruCars lands before they
| shop. Everything on it is live: the counts, the cars and the price card are
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

        $latest = (clone $listed)->with('dealer')->latest('vehicles.created_at')->limit(10)->get();
        $featured = $latest->first(fn (Vehicle $vehicle) => $vehicle->primary_photo_url !== null) ?? $latest->first();

        return [
            'carCount'    => (clone $listed)->count(),
            'dealerCount' => Dealer::where('status', Dealer::STATUS_ACTIVE)->whereHas('vehicles', fn ($vehicles) => $vehicles->where('is_published', true))->count(),
            'cityCount'   => Dealer::where('status', Dealer::STATUS_ACTIVE)->whereHas('vehicles', fn ($vehicles) => $vehicles->where('is_published', true))->distinct()->count('city'),
            'latest'      => $latest,
            'featured'    => $featured,
            'bodyTypes'   => $this->countsBy($listed, 'body_type', 6),
            'makes'       => $this->countsBy($listed, 'make', 10),
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
    $carIcon = '<path d="M5 17h14M6 17l1.5-6h9L18 17M7 21v-2M17 21v-2"/><circle cx="8" cy="14" r="1"/><circle cx="16" cy="14" r="1"/>';
@endphp

<div class="home">
    <style>
        .home { --sec:88px; min-width:0; }
        .home > * { min-width:0; }
        .home section { margin-top:var(--sec); }
        .home .eyebrow { display:inline-flex; align-items:center; gap:8px; font-size:12.5px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; color:var(--primary-press); }
        .home .eyebrow::before { content:""; width:18px; height:2px; background:var(--primary); border-radius:2px; }
        .home h2 { font-size:clamp(28px, 3.4vw, 42px); font-weight:800; letter-spacing:-.03em; line-height:1.08; margin:12px 0 0; }
        .home .lead { font-size:17px; color:var(--ink-2); max-width:620px; margin:14px 0 0; }
        .sec-head { display:flex; align-items:flex-end; gap:20px; flex-wrap:wrap; }
        .sec-head .spacer { flex:1; }
        .link-arrow { display:inline-flex; align-items:center; gap:6px; font-size:14.5px; font-weight:700; color:var(--ink); text-decoration:none; }
        .link-arrow:hover { color:var(--primary-press); }
        .link-arrow svg { transition:transform .15s ease; }
        .link-arrow:hover svg { transform:translateX(3px); }
        .btn-hero { display:inline-flex; align-items:center; justify-content:center; gap:9px; font-size:15.5px; font-weight:700; border-radius:var(--radius-pill); padding:15px 26px; text-decoration:none; transition:all .16s ease; white-space:nowrap; }
        .btn-hero.primary { background:var(--primary); color:#fff; box-shadow:var(--shadow-primary); }
        .btn-hero.primary:hover { background:var(--primary-press); transform:translateY(-1px); }
        .btn-hero.ghost { background:var(--card); color:var(--ink); border:1.5px solid var(--line-strong); }
        .btn-hero.ghost:hover { border-color:var(--ink); }

        /* ---- hero ---- */
        .hero { position:relative; display:grid; grid-template-columns:minmax(0, 1.15fr) minmax(0, .85fr); gap:56px; align-items:center; padding:56px 56px 60px; border-radius:36px; background:
            radial-gradient(900px 420px at 92% -10%, rgba(255,138,61,.22), transparent 60%),
            radial-gradient(600px 400px at -10% 110%, rgba(245,99,31,.10), transparent 60%),
            var(--bg-2); overflow:hidden; }
        .hero h1 { font-size:clamp(40px, 5.6vw, 76px); font-weight:800; letter-spacing:-.045em; line-height:.98; margin:18px 0 0; }
        .hero h1 em { font-style:normal; background:var(--hero-grad); -webkit-background-clip:text; background-clip:text; color:transparent; }
        .hero .lead { font-size:18.5px; }
        .hero-search { margin-top:30px; display:flex; align-items:center; gap:8px; background:var(--card); border:1.5px solid var(--line-strong); border-radius:var(--radius-pill); padding:7px 7px 7px 22px; max-width:560px; box-shadow:var(--shadow-md); transition:border-color .15s ease; }
        .hero-search:focus-within { border-color:var(--primary); }
        .hero-search svg { color:var(--ink-3); flex-shrink:0; }
        .hero-search input { flex:1; min-width:0; border:none; outline:none; font-size:16px; background:none; padding:10px 4px; }
        .hero-search button { background:var(--ink); color:#fff; font-size:15px; font-weight:700; padding:12px 22px; border-radius:var(--radius-pill); }
        .hero-search button:hover { background:var(--primary); }
        .hero-chips { display:flex; gap:8px; flex-wrap:wrap; margin-top:16px; }
        .hero-chips a { font-size:13.5px; font-weight:600; color:var(--ink-2); text-decoration:none; padding:7px 14px; border-radius:var(--radius-pill); background:rgba(255,255,255,.7); border:1px solid var(--line); transition:all .14s ease; }
        .hero-chips a:hover { border-color:var(--primary-line); color:var(--primary-press); background:#fff; }
        .hero-stats { display:flex; gap:36px; margin-top:36px; flex-wrap:wrap; }
        .hero-stats div { font-size:13px; color:var(--ink-2); }
        .hero-stats b { display:block; font-size:28px; font-weight:800; letter-spacing:-.03em; color:var(--ink); font-variant-numeric:tabular-nums; }

        /* the itemized price card */
        .tag-wrap { position:relative; justify-self:center; width:100%; max-width:410px; }
        .tag { position:relative; background:var(--card); border-radius:26px; box-shadow:0 40px 80px -20px rgba(22,24,29,.25), 0 6px 18px rgba(22,24,29,.06); transform:rotate(2.2deg); overflow:hidden; transition:transform .4s cubic-bezier(.2,.8,.2,1); }
        .tag-wrap:hover .tag { transform:rotate(0deg) translateY(-4px); }
        .tag-photo { aspect-ratio:16/10; background:var(--hero-grad) center/cover; position:relative; }
        .tag-photo .pin { position:absolute; left:14px; bottom:14px; display:inline-flex; align-items:center; gap:6px; font-size:12px; font-weight:700; color:#fff; background:rgba(22,24,29,.62); backdrop-filter:blur(6px); padding:6px 11px; border-radius:var(--radius-pill); }
        .tag-body { padding:18px 22px 20px; }
        .tag-title { font-size:17px; font-weight:800; letter-spacing:-.01em; }
        .tag-dealer { font-size:12.5px; color:var(--ink-3); margin-top:2px; }
        .tag-lines { margin:14px 0 0; padding:12px 0 0; border-top:1.5px dashed var(--line-strong); display:grid; gap:7px; }
        .tag-line { display:flex; justify-content:space-between; font-size:13.5px; color:var(--ink-2); }
        .tag-line b { font-weight:700; color:var(--good); }
        .tag-line.zero b { color:var(--ink); }
        .tag-total { display:flex; justify-content:space-between; align-items:baseline; margin-top:12px; padding-top:12px; border-top:1.5px dashed var(--line-strong); }
        .tag-total span { font-size:13px; font-weight:700; }
        .tag-total b { font-size:30px; font-weight:800; letter-spacing:-.03em; }
        .tag-note { font-size:11.5px; color:var(--ink-3); text-align:right; margin-top:2px; }
        .tag-hole { position:absolute; top:16px; right:18px; width:16px; height:16px; border-radius:50%; background:var(--bg-2); box-shadow:inset 0 2px 4px rgba(0,0,0,.18); }
        .stamp { position:absolute; left:-18px; top:-16px; transform:rotate(-7deg); background:var(--ink); color:#fff; border-radius:16px; padding:11px 15px; box-shadow:0 16px 30px rgba(22,24,29,.28); font-size:12px; line-height:1.3; }
        .stamp b { display:block; font-size:16px; color:#FFB98A; }

        /* ---- fresh arrivals ---- */
        .rail { display:grid; grid-auto-flow:column; grid-auto-columns:minmax(250px, 1fr); max-width:100%; gap:18px; overflow-x:auto; scroll-snap-type:x mandatory; padding:4px 2px 18px; margin-top:28px; scrollbar-width:thin; }
        .car { scroll-snap-align:start; display:block; text-decoration:none; border-radius:22px; background:var(--card); border:1px solid var(--line); overflow:hidden; transition:transform .18s ease, box-shadow .18s ease; }
        .car:hover { transform:translateY(-3px); box-shadow:var(--shadow-md); }
        .car-photo { aspect-ratio:4/3; background:var(--bg-2) center/cover; display:grid; place-items:center; color:var(--ink-3); }
        .car-body { padding:14px 16px 16px; }
        .car-name { font-size:15.5px; font-weight:700; letter-spacing:-.01em; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .car-meta { font-size:12.5px; color:var(--ink-3); margin-top:3px; }
        .car-price { display:flex; justify-content:space-between; align-items:baseline; margin-top:12px; }
        .car-price b { font-size:20px; font-weight:800; letter-spacing:-.02em; }
        .car-price span { font-size:11px; font-weight:700; color:var(--good); background:var(--good-soft); padding:3px 8px; border-radius:var(--radius-pill); }

        /* ---- promises ---- */
        .promises { border-radius:36px; background:#14161B; color:#fff; padding:64px 56px; position:relative; overflow:hidden; }
        .promises::after { content:""; position:absolute; width:520px; height:520px; right:-160px; top:-220px; background:radial-gradient(circle, rgba(245,99,31,.35), transparent 65%); }
        .promises .eyebrow { color:#FFB98A; }
        .promises .eyebrow::before { background:#FFB98A; }
        .promises h2 { color:#fff; max-width:760px; }
        .promise-grid { position:relative; z-index:1; display:grid; grid-template-columns:repeat(3, 1fr); gap:20px; margin-top:44px; }
        .promise { border-radius:24px; background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.1); padding:26px; }
        .promise .num { font-family:var(--font-mono); font-size:13px; color:#FFB98A; }
        .promise h3 { font-size:21px; font-weight:800; letter-spacing:-.02em; margin:16px 0 8px; }
        .promise p { margin:0; color:rgba(255,255,255,.7); font-size:14.5px; }
        .promise .ic { width:46px; height:46px; border-radius:14px; background:var(--hero-grad); display:grid; place-items:center; margin-top:18px; }

        /* ---- how it works strip ---- */
        .steps { display:grid; grid-template-columns:repeat(4, 1fr); gap:18px; margin-top:36px; counter-reset:step; }
        .step { position:relative; padding:24px; border-radius:24px; border:1px solid var(--line); background:var(--card); }
        .step::before { counter-increment:step; content:counter(step); display:grid; place-items:center; width:38px; height:38px; border-radius:12px; background:var(--primary-soft); color:var(--primary-press); font-weight:800; font-size:16px; }
        .step:not(:last-child)::after { content:""; position:absolute; top:43px; right:-19px; width:20px; height:2px; background:var(--line-strong); }
        .step h3 { font-size:17px; font-weight:800; letter-spacing:-.01em; margin:16px 0 6px; }
        .step p { margin:0; font-size:14px; color:var(--ink-2); }

        /* ---- browse ---- */
        .browse { display:grid; grid-template-columns:minmax(0, 1.4fr) minmax(0, 1fr); gap:24px; margin-top:32px; }
        .styles { display:grid; grid-template-columns:repeat(3, 1fr); gap:14px; }
        .style { display:flex; flex-direction:column; justify-content:space-between; gap:22px; padding:20px; border-radius:22px; background:var(--bg-2); text-decoration:none; transition:all .16s ease; min-height:150px; }
        .style:hover { background:var(--primary-soft); }
        .style:hover svg { color:var(--primary); transform:translateX(4px); }
        .style svg { width:54px; height:54px; color:var(--ink); transition:all .2s ease; }
        .style b { display:block; font-size:16px; font-weight:800; color:var(--ink); }
        .style b { color:var(--ink); }
        .style span span { font-size:12.5px; color:var(--ink-3); }
        .makes { border-radius:22px; border:1px solid var(--line); padding:22px; }
        .makes h3 { margin:0 0 14px; font-size:16px; font-weight:800; }
        .make-list { display:flex; flex-wrap:wrap; gap:8px; }
        .make-list a { display:inline-flex; align-items:center; gap:8px; padding:9px 14px; border-radius:var(--radius-pill); border:1px solid var(--line-strong); text-decoration:none; font-size:14px; font-weight:600; transition:all .14s ease; }
        .make-list a:hover { border-color:var(--primary); color:var(--primary-press); }
        .make-list a span { font-family:var(--font-mono); font-size:11.5px; color:var(--ink-3); }
        .budget { margin-top:16px; display:flex; align-items:center; gap:14px; padding:16px 18px; border-radius:18px; background:var(--good-soft); text-decoration:none; }
        .budget b { font-size:22px; font-weight:800; color:#0B7A59; }
        .budget span { font-size:13.5px; color:#0B7A59; font-weight:600; }

        /* ---- faq ---- */
        .faq-wrap { display:grid; grid-template-columns:minmax(0, .8fr) minmax(0, 1.2fr); gap:48px; }
        .faq { border-top:1px solid var(--line); }
        .faq-item { border-bottom:1px solid var(--line); }
        .faq-item button { display:flex; justify-content:space-between; align-items:center; gap:16px; width:100%; padding:20px 0; text-align:left; font-size:17px; font-weight:700; color:var(--ink); }
        .faq-item .pm { width:30px; height:30px; flex-shrink:0; border-radius:50%; background:var(--bg-2); display:grid; place-items:center; transition:transform .2s ease, background .2s ease; }
        .faq-item.open .pm { transform:rotate(45deg); background:var(--primary-soft); color:var(--primary-press); }
        .faq-item p { margin:0 0 20px; font-size:15px; color:var(--ink-2); max-width:640px; }

        /* ---- final cta ---- */
        .cta { border-radius:36px; padding:60px; background:var(--hero-grad); color:#fff; display:flex; align-items:center; gap:30px; flex-wrap:wrap; position:relative; overflow:hidden; }
        .cta::after { content:""; position:absolute; inset:auto -80px -180px auto; width:420px; height:420px; border-radius:50%; border:60px solid rgba(255,255,255,.12); }
        .cta h2 { color:#fff; margin:0; max-width:620px; }
        .cta p { margin:10px 0 0; color:rgba(255,255,255,.88); font-size:16.5px; }
        .cta .spacer { flex:1; }
        .cta .btn-hero { background:#fff; color:var(--ink); position:relative; z-index:1; }
        .cta .btn-hero:hover { background:var(--ink); color:#fff; }

        @media (max-width:1100px) {
            .hero { grid-template-columns:minmax(0, 1fr); padding:40px 32px 48px; gap:40px; }
            .steps { grid-template-columns:repeat(2, minmax(0, 1fr)); }
            .step:nth-child(2)::after { display:none; }
            .browse, .faq-wrap { grid-template-columns:minmax(0, 1fr); }
        }
        @media (max-width:760px) {
            .home { --sec:60px; }
            .hero { padding:30px 20px 36px; border-radius:26px; }
            .hero-stats { gap:22px; }
            .hero-stats b { font-size:23px; }
            .stamp { left:-6px; top:-14px; }
            .promises { padding:40px 22px; border-radius:26px; }
            .promise-grid { grid-template-columns:minmax(0, 1fr); }
            .steps { grid-template-columns:minmax(0, 1fr); }
            .step::after { display:none; }
            .styles { grid-template-columns:repeat(2, minmax(0, 1fr)); }
            .cta { padding:36px 24px; border-radius:26px; }
            .hero-search { padding-left:16px; }
            .hero-search button { padding:11px 16px; }
            .hero h1 { font-size:38px; }
            .tag-wrap { max-width:340px; }
        }
    </style>

    {{-- HERO --}}
    <div class="hero">
        <div>
            <span class="eyebrow">Licensed dealers · all-in prices</span>
            <h1>The real price.<br>From a <em>real dealer.</em></h1>
            <p class="lead">Every car on TruCars is sold by a licensed dealership — and the price you see already includes freight, prep and fees. Find one you love, hold it for $150, refundable.</p>

            <form class="hero-search" wire:submit="search" role="search">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
                <input type="search" wire:model="q" placeholder="Search a make or model — try “Outlander”" aria-label="Search cars">
                <button type="submit">Search</button>
            </form>

            <div class="hero-chips">
                @foreach ($bodyTypes->keys()->take(4) as $body)
                    <a href="{{ route('shop', ['body' => [$body]]) }}" wire:navigate>{{ $body }}s</a>
                @endforeach
                @if ($under) <a href="{{ route('shop', ['max' => 25000]) }}" wire:navigate>Under $25k</a> @endif
                <a href="{{ route('shop') }}" wire:navigate>Everything →</a>
            </div>

            <div class="hero-stats">
                <div><b>{{ number_format($carCount) }}</b>cars listed now</div>
                <div><b>{{ $dealerCount }}</b>licensed {{ $dealerCount === 1 ? 'dealer' : 'dealers' }}</div>
                <div><b>$0</b>surprise fees</div>
            </div>
        </div>

        {{-- An itemized price tag, built from a real listing --}}
        <div class="tag-wrap" aria-hidden="true">
            <div class="tag">
                <div class="tag-photo" @if ($featured?->primary_photo_url) style="background-image:url('{{ $featured->primary_photo_url }}')" @endif>
                    @if ($featured?->dealer)
                        <span class="pin"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg> {{ $featured->display_location }}</span>
                    @endif
                </div>
                <span class="tag-hole"></span>
                <div class="tag-body">
                    <div class="tag-title">{{ $featured ? "{$featured->model_year} {$featured->make} {$featured->model}" : 'Your next car' }}</div>
                    <div class="tag-dealer">{{ $featured ? 'Sold by ' . ucwords(strtolower($featured->dealer->name)) : 'Sold by a licensed dealership' }}</div>
                    <div class="tag-lines">
                        <div class="tag-line">Freight &amp; PDI <b>✓ included</b></div>
                        <div class="tag-line">Dealer admin fee <b>✓ included</b></div>
                        <div class="tag-line">Regulator fee <b>✓ included</b></div>
                        <div class="tag-line zero">Surprise add-ons <b>$0</b></div>
                    </div>
                    <div class="tag-total"><span>All-in price</span><b>{{ $featured?->display_price ?? '$—' }}</b></div>
                    <div class="tag-note">+ HST &amp; licensing</div>
                </div>
            </div>
            <div class="stamp">Hold it today<b>$150 · refundable</b></div>
        </div>
    </div>

    {{-- FRESH ARRIVALS --}}
    @if ($latest->isNotEmpty())
        <section>
            <div class="sec-head">
                <div>
                    <span class="eyebrow">Just listed</span>
                    <h2>Fresh on the lot</h2>
                </div>
                <div class="spacer"></div>
                <a class="link-arrow" href="{{ route('shop') }}" wire:navigate>Shop all {{ number_format($carCount) }} cars <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
            </div>
            <div class="rail">
                @foreach ($latest as $car)
                    <a class="car" href="/cars/{{ $car->id }}" wire:navigate wire:key="fresh-{{ $car->id }}">
                        <div class="car-photo" @if ($car->primary_photo_url) style="background-image:url('{{ $car->primary_photo_url }}')" @endif>
                            @unless ($car->primary_photo_url)<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">{!! $carIcon !!}</svg>@endunless
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

    {{-- PROMISES --}}
    <section class="promises">
        <span class="eyebrow">Why TruCars</span>
        <h2>Buying a used car shouldn’t feel like a negotiation you didn’t sign up for.</h2>
        <div class="promise-grid">
            <div class="promise">
                <span class="num">01</span>
                <div class="ic"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18M5 21V8l7-4 7 4v13M9 21v-6h6v6"/></svg></div>
                <h3>A real dealership on every listing</h3>
                <p>No mystery sellers. Every car shows the licensed dealer selling it, where it is, and their registration number.</p>
            </div>
            <div class="promise">
                <span class="num">02</span>
                <div class="ic"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8Z"/><circle cx="7.5" cy="7.5" r="1.5"/></svg></div>
                <h3>One price. All in.</h3>
                <p>Freight, prep, admin and regulator fees are already in the number. The only things added are HST and licensing.</p>
            </div>
            <div class="promise">
                <span class="num">03</span>
                <div class="ic"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg></div>
                <h3>$150 holds it — refundable</h3>
                <p>Reserve online and the car comes off the market for you. Change your mind before you take it home and you get it back.</p>
            </div>
        </div>
    </section>

    {{-- HOW IT WORKS --}}
    <section>
        <div class="sec-head">
            <div>
                <span class="eyebrow">How it works</span>
                <h2>From “that one” to keys in hand</h2>
            </div>
            <div class="spacer"></div>
            <a class="link-arrow" href="{{ route('how-it-works') }}" wire:navigate>The full walkthrough <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
        </div>
        <div class="steps">
            <div class="step"><h3>Find it</h3><p>Browse cars from licensed dealers near you, with the all-in price on every one.</p></div>
            <div class="step"><h3>Hold it</h3><p>Reserve with a $150 refundable deposit. The car is taken off the market for you.</p></div>
            <div class="step"><h3>Confirm it</h3><p>Verify your ID and the dealership reaches out to confirm the details with you.</p></div>
            <div class="step"><h3>Drive it</h3><p>Pick it up at the dealership or have it delivered. Your deposit comes off the price.</p></div>
        </div>
    </section>

    {{-- BROWSE --}}
    @if ($bodyTypes->isNotEmpty())
        <section>
            <span class="eyebrow">Browse</span>
            <h2>Start with what fits your life</h2>
            <div class="browse">
                <div class="styles">
                    @foreach ($bodyTypes as $body => $total)
                        <a class="style" href="{{ route('shop', ['body' => [$body]]) }}" wire:navigate>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round">{!! $bodyIcons[$body] ?? $carIcon !!}</svg>
                            <span><b>{{ $body }}</b><span>{{ $total }} {{ $total === 1 ? 'car' : 'cars' }}</span></span>
                        </a>
                    @endforeach
                </div>
                <div>
                    <div class="makes">
                        <h3>Popular makes</h3>
                        <div class="make-list">
                            @foreach ($makes as $make => $total)
                                <a href="{{ route('shop', ['make' => [$make]]) }}" wire:navigate>{{ $make }} <span>{{ $total }}</span></a>
                            @endforeach
                        </div>
                    </div>
                    @if ($under)
                        <a class="budget" href="{{ route('shop', ['max' => 25000]) }}" wire:navigate>
                            <b>{{ $under }}</b>
                            <span>{{ $under === 1 ? 'car' : 'cars' }} under $25,000, all-in →</span>
                        </a>
                    @endif
                </div>
            </div>
        </section>
    @endif

    {{-- FAQ --}}
    <section class="faq-wrap">
        <div>
            <span class="eyebrow">Questions</span>
            <h2>The short answers</h2>
            <p class="lead">Anything else? The <a href="{{ route('how-it-works') }}" wire:navigate style="color:var(--primary-press); font-weight:700;">full walkthrough</a> covers every step.</p>
        </div>
        <div class="faq" x-data="{ open: 0 }">
            @foreach ([
                ['Who am I actually buying from?', 'The licensed dealership named on the listing. TruCars is where you find the car and reserve it; the dealership sells it to you, and their registration number is on every listing.'],
                ['Is the price really all-in?', 'Yes. Freight, pre-delivery prep, dealer admin and regulator fees are already in the price you see. HST and licensing are the only things added.'],
                ['What does the $150 deposit do?', 'It takes the car off the market while you finish up, and it comes off the price when you buy. It’s refundable any time before you take delivery.'],
                ['Can I pick the car up myself?', 'Yes — pick it up at the dealership, or choose delivery when you reserve. Either way you pick the day and time window.'],
                ['What does “certified” mean on a listing?', 'It means the car is covered by the dealer’s or manufacturer’s inspection checklist — and the listing shows every point that’s checked, not just a number.'],
            ] as $index => [$question, $answer])
                <div class="faq-item" :class="{ open: open === {{ $index }} }">
                    <button type="button" @click="open = open === {{ $index }} ? null : {{ $index }}" :aria-expanded="open === {{ $index }}">
                        {{ $question }}
                        <span class="pm"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg></span>
                    </button>
                    <p x-show="open === {{ $index }}" x-collapse x-cloak>{{ $answer }}</p>
                </div>
            @endforeach
        </div>
    </section>

    {{-- CTA --}}
    <section class="cta">
        <div>
            <h2>Your next car is already on a lot near you.</h2>
            <p>{{ number_format($carCount) }} cars from {{ $dealerCount }} licensed {{ $dealerCount === 1 ? 'dealer' : 'dealers' }}{{ $cityCount > 1 ? ' in ' . $cityCount . ' cities' : '' }}, every price all-in.</p>
        </div>
        <div class="spacer"></div>
        <a class="btn-hero" href="{{ route('shop') }}" wire:navigate>Start shopping <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
    </section>
</div>
