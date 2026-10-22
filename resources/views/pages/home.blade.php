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
            'cities'      => (clone $activeDealers)->orderBy('city')->distinct()->pluck('city')->filter()->values(),
            'latest'      => $latest,
            'featured'    => $featured,
            'bodyTypes'   => $this->countsBy($listed, 'body_type', 8),
            'makes'       => $this->countsBy($listed, 'make', 12),
            'allMakes'    => (clone $listed)->whereNotNull('make')->where('make', '!=', '')->distinct()->orderBy('make')->pluck('make'),
            'budgets'     => collect([15000, 25000, 35000])
                ->mapWithKeys(fn (int $max) => [$max => (clone $listed)->where('price_in_cents', '<', $max * 100)->count()])
                ->filter(),
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

<div class="home">
    <style>
        .home { min-width:0; }
        .home > * { min-width:0; }
        .home section { margin-top:64px; padding-top:40px; border-top:1px solid var(--line); }
        .home h2 { font-size:24px; font-weight:700; letter-spacing:-.015em; line-height:1.2; margin:0; }
        .home-head { display:flex; align-items:baseline; justify-content:space-between; gap:16px; flex-wrap:wrap; }
        .home-head a { font-size:14.5px; font-weight:600; color:var(--primary-press); text-decoration:none; }
        .home-head a:hover { text-decoration:underline; }

        /* ---- intro ---- */
        .intro { display:grid; grid-template-columns:minmax(0, 1fr) minmax(0, 1fr); gap:48px; align-items:center; padding-top:16px; }
        .intro h1 { font-size:clamp(34px, 4vw, 50px); font-weight:700; letter-spacing:-.025em; line-height:1.08; margin:0; max-width:15ch; }
        .intro p { font-size:17px; color:var(--ink-2); margin:18px 0 0; max-width:520px; }
        .finder { margin-top:28px; display:grid; grid-template-columns:1fr 1fr; gap:10px; max-width:560px; }
        .finder label { display:block; font-size:12.5px; font-weight:600; color:var(--ink-2); margin-bottom:5px; }
        .finder select, .finder input { width:100%; font:inherit; font-size:15px; color:var(--ink); background:var(--card); border:1px solid var(--line-strong); border-radius:8px; padding:11px 12px; outline:none; }
        .finder select:focus, .finder input:focus { border-color:var(--ink); }
        .finder .wide { grid-column:1 / -1; }
        .finder button { grid-column:1 / -1; font:inherit; font-size:15.5px; font-weight:700; background:var(--primary); color:#fff; border:none; border-radius:8px; padding:13px; cursor:pointer; }
        .finder button:hover { background:var(--primary-press); }
        .intro-count { margin-top:14px; font-size:14px; color:var(--ink-3); }
        .intro-count a { color:var(--ink); font-weight:600; }

        .feature { display:block; text-decoration:none; color:inherit; }
        .feature-photo { aspect-ratio:4/3; border-radius:12px; background:var(--bg-2) center/cover; }
        .feature-cap { display:flex; justify-content:space-between; align-items:baseline; gap:12px; margin-top:12px; font-size:14px; }
        .feature-cap span { color:var(--ink-3); }
        .feature-cap b { font-size:16px; white-space:nowrap; }
        .feature:hover .feature-cap strong { text-decoration:underline; }

        /* ---- latest cars ---- */
        .cars { display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:28px 20px; margin-top:22px; }
        .car { display:block; text-decoration:none; color:inherit; }
        .car-photo { position:relative; aspect-ratio:4/3; border-radius:10px; background:var(--bg-2) center/cover; }
        .car-photo .no-photo { position:absolute; inset:0; display:grid; place-items:center; font-size:13px; color:var(--ink-3); }
        .car-photo .reserved { position:absolute; top:8px; left:8px; font-size:11.5px; font-weight:700; background:#FFF4D6; color:#7A4E00; padding:3px 8px; border-radius:4px; }
        .car-name { font-size:15px; font-weight:600; margin-top:10px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .car:hover .car-name { text-decoration:underline; }
        .car-meta { font-size:13px; color:var(--ink-3); margin-top:1px; }
        .car-price { font-size:17px; font-weight:700; margin-top:6px; }
        .car-price small { font-size:12.5px; font-weight:500; color:var(--ink-3); }

        /* ---- browse ---- */
        .browse { display:grid; grid-template-columns:2fr 1fr 1fr; gap:40px; margin-top:22px; }
        .browse h3 { font-size:14px; font-weight:700; margin:0 0 10px; }
        .browse ul { list-style:none; margin:0; padding:0; }
        .browse .makes ul { columns:2; column-gap:32px; }
        .browse li { break-inside:avoid; }
        .browse a { display:flex; justify-content:space-between; gap:12px; padding:7px 0; font-size:15px; color:var(--ink); text-decoration:none; border-bottom:1px solid var(--line); }
        .browse a:hover { color:var(--primary-press); }
        .browse a span { color:var(--ink-3); font-variant-numeric:tabular-nums; }

        /* ---- how it works ---- */
        .how { display:grid; grid-template-columns:minmax(0, 1fr) minmax(0, 1fr); gap:56px; margin-top:22px; }
        .how ol { margin:0 0 6px; padding:0 0 0 22px; }
        .how li { padding-left:6px; margin-bottom:18px; font-size:15.5px; color:var(--ink-2); }
        .how li::marker { font-weight:700; color:var(--ink); }
        .how li b { display:block; color:var(--ink); font-size:16px; margin-bottom:2px; }
        .how-more { font-size:14.5px; font-weight:600; color:var(--primary-press); }
        .price-sheet { align-self:start; border:1px solid var(--line-strong); border-radius:10px; padding:20px 22px; font-size:15px; }
        .price-sheet h3 { font-size:16px; margin:0 0 4px; }
        .price-sheet > p { margin:0 0 14px; font-size:14px; color:var(--ink-3); }
        .price-sheet table { width:100%; border-collapse:collapse; }
        .price-sheet td { padding:8px 0; border-top:1px solid var(--line); }
        .price-sheet td:last-child { text-align:right; color:var(--ink-2); }
        .price-sheet tr.in td:last-child { color:var(--good); font-weight:600; }

        /* ---- questions ---- */
        .faq { margin-top:14px; max-width:760px; }
        .faq details { border-bottom:1px solid var(--line); }
        .faq summary { cursor:pointer; list-style:none; display:flex; justify-content:space-between; gap:16px; padding:16px 0; font-size:16px; font-weight:600; }
        .faq summary::-webkit-details-marker { display:none; }
        .faq summary::after { content:"+"; font-size:20px; line-height:1; color:var(--ink-3); }
        .faq details[open] summary::after { content:"−"; }
        .faq p { margin:0 0 18px; font-size:15px; color:var(--ink-2); }

        @media (max-width:1100px) {
            .cars { grid-template-columns:repeat(3, minmax(0, 1fr)); }
            .browse { grid-template-columns:1fr 1fr; }
            .browse .makes { grid-column:1 / -1; }
        }
        @media (max-width:860px) {
            .intro, .how { grid-template-columns:minmax(0, 1fr); gap:32px; }
            .cars { grid-template-columns:repeat(2, minmax(0, 1fr)); gap:22px 14px; }
        }
        @media (max-width:560px) {
            .home section { margin-top:44px; padding-top:28px; }
            .finder { grid-template-columns:1fr; }
            .browse { grid-template-columns:minmax(0, 1fr); gap:28px; }
            .browse .makes ul { columns:1; }
        }
    </style>

    {{-- INTRO: say what this is, then let them search --}}
    <div class="intro">
        <div>
            <h1>Used cars from licensed dealers, priced all-in.</h1>
            <p>The price on every car already includes freight, prep and dealer fees. HST and licensing are the only things added. Found the one? Hold it for $150, and get it back if you change your mind.</p>

            <form class="finder" wire:submit="search" role="search">
                <div>
                    <label for="find-make">Make</label>
                    <select id="find-make" wire:model="make">
                        <option value="">Any make</option>
                        @foreach ($allMakes as $option)
                            <option value="{{ $option }}">{{ $option }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="find-max">Max price</label>
                    <select id="find-max" wire:model="max">
                        <option value="">No max</option>
                        @foreach ($this::PRICE_STEPS as $step)
                            <option value="{{ $step }}">${{ number_format($step) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="wide">
                    <label for="find-q">Model or keyword</label>
                    <input id="find-q" type="search" wire:model="q" placeholder="e.g. Outlander, AWD, hybrid">
                </div>
                <button type="submit">Show cars</button>
            </form>

            <div class="intro-count">
                <a href="{{ route('shop') }}" wire:navigate>{{ number_format($carCount) }} {{ $carCount === 1 ? 'car' : 'cars' }}</a>
                from {{ $dealerCount }} {{ $dealerCount === 1 ? 'dealer' : 'dealers' }}@if ($cities->isNotEmpty()) in {{ $cities->take(3)->join(', ', ' and ') }}{{ $cities->count() > 3 ? ' and more' : '' }}@endif.
            </div>
        </div>

        @if ($featured)
            <a class="feature" href="/cars/{{ $featured->id }}" wire:navigate>
                <div class="feature-photo" style="background-image:url('{{ $featured->primary_photo_url }}')" role="img" aria-label="{{ $featured->model_year }} {{ $featured->make }} {{ $featured->model }}"></div>
                <div class="feature-cap">
                    <div><strong>{{ $featured->model_year }} {{ $featured->make }} {{ $featured->model }}</strong><br><span>{{ $featured->display_kilometres }} · {{ ucwords(strtolower($featured->dealer->name)) }}, {{ $featured->display_location }}</span></div>
                    <b>{{ $featured->display_price }}</b>
                </div>
            </a>
        @endif
    </div>

    {{-- LATEST CARS --}}
    @if ($latest->isNotEmpty())
        <section>
            <div class="home-head">
                <h2>Just listed</h2>
                <a href="{{ route('shop') }}" wire:navigate>See all {{ number_format($carCount) }} cars</a>
            </div>
            <div class="cars">
                @foreach ($latest as $car)
                    <a class="car" href="/cars/{{ $car->id }}" wire:navigate wire:key="latest-{{ $car->id }}">
                        <div class="car-photo" @if ($car->primary_photo_url) style="background-image:url('{{ $car->primary_photo_url }}')" @endif>
                            @unless ($car->primary_photo_url)<span class="no-photo">No photo yet</span>@endunless
                            @if ($car->is_reserved)<span class="reserved">Reserved</span>@endif
                        </div>
                        <div class="car-name">{{ $car->model_year }} {{ $car->make }} {{ $car->model }}</div>
                        <div class="car-meta">{{ $car->display_kilometres }} · {{ $car->display_location }}</div>
                        <div class="car-price">{{ $car->display_price }} <small>all-in</small></div>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    {{-- BROWSE --}}
    @if ($makes->isNotEmpty())
        <section>
            <h2>Browse</h2>
            <div class="browse">
                <div class="makes">
                    <h3>By make</h3>
                    <ul>
                        @foreach ($makes as $name => $total)
                            <li><a href="{{ route('shop', ['make' => [$name]]) }}" wire:navigate>{{ $name }} <span>{{ $total }}</span></a></li>
                        @endforeach
                    </ul>
                </div>
                @if ($bodyTypes->isNotEmpty())
                    <div>
                        <h3>By body type</h3>
                        <ul>
                            @foreach ($bodyTypes as $body => $total)
                                <li><a href="{{ route('shop', ['body' => [$body]]) }}" wire:navigate>{{ $body }} <span>{{ $total }}</span></a></li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                @if ($budgets->isNotEmpty())
                    <div>
                        <h3>By price</h3>
                        <ul>
                            @foreach ($budgets as $max => $total)
                                <li><a href="{{ route('shop', ['max' => $max]) }}" wire:navigate>Under ${{ number_format($max) }} <span>{{ $total }}</span></a></li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        </section>
    @endif

    {{-- HOW IT WORKS --}}
    <section>
        <h2>How buying works</h2>
        <div class="how">
            <div>
                <ol>
                    <li><b>Pick a car</b>Every listing names the dealership selling it and where the car is.</li>
                    <li><b>Hold it for $150</b>The car is marked reserved so no one else can buy it. The deposit is refundable until you take delivery.</li>
                    <li><b>Confirm your details</b>Verify your ID, and the dealership gets in touch to go over the paperwork.</li>
                    <li><b>Pick it up or have it delivered</b>You choose the day. Your $150 comes off the price.</li>
                </ol>
                <a class="how-more" href="{{ route('how-it-works') }}" wire:navigate>Read the full walkthrough</a>
            </div>

            <div class="price-sheet">
                <h3>What the price includes</h3>
                <p>The number on the listing, broken down.</p>
                <table>
                    <tr class="in"><td>Freight and pre-delivery prep</td><td>Included</td></tr>
                    <tr class="in"><td>Dealer admin fee</td><td>Included</td></tr>
                    <tr class="in"><td>Regulator fee</td><td>Included</td></tr>
                    <tr><td>HST</td><td>Added at sale</td></tr>
                    <tr><td>Licensing</td><td>Added at sale</td></tr>
                </table>
            </div>
        </div>
    </section>

    {{-- QUESTIONS --}}
    <section>
        <h2>Questions</h2>
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
</div>
