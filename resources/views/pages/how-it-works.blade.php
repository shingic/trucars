<?php

use App\Models\Vehicle;
use App\Support\Deposits;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
| How TruCars works, start to finish, for shoppers. Static content — kept in
| step with the actual flow: listing → reserve ($150, refundable) → ID check,
| documents and the dealer's call → financing approval → insurance → pickup
| or delivery. The deposit wording mirrors checkout; change both together.
|
| The worked example at the top is a real car on the marketplace right now,
| with its real price. With nothing listed, it is simply left out.
*/
new #[Title('How it works — TruCars')] class extends Component {
    public function featuredCar(): ?Vehicle
    {
        return Vehicle::query()->listed()->with(['dealer', 'rooftop'])->latest()->limit(20)->get()
            ->first(fn (Vehicle $vehicle) => $vehicle->primary_photo_url !== null);
    }
}; ?>

@php $car = $this->featuredCar(); @endphp

<div class="hw">
    <style>
        .hw { max-width:1080px; margin:0 auto; padding:0 0 64px; font-size:16px; line-height:1.6; }
        .hw h1, .hw h2, .hw h3 { font-family:var(--font-display); color:var(--ink); }
        .hw p { margin:0 0 12px; max-width:620px; }
        .hw .muted { color:var(--ink-2); }

        /* top: what happens and when you pay, worked through on a real car from the lot */
        .hw-top { display:grid; grid-template-columns:minmax(0, 1fr) minmax(0, 420px); gap:56px; align-items:start; padding:28px 0 8px; }
        .hw-top.no-car { grid-template-columns:minmax(0, 1fr); }
        .hw-top h1 { font-size:clamp(40px, 5vw, 64px); font-weight:700; line-height:.95; text-transform:uppercase; margin:0; max-width:16ch; }
        .hw-top-copy > p { font-size:18px; margin:18px 0 0; max-width:500px; color:var(--ink-2); }
        .hw-jump { list-style:none; margin:30px 0 0; padding:0; border-top:2px solid var(--ink); max-width:560px; }
        .hw-jump a { display:grid; grid-template-columns:34px minmax(0, 1fr) auto; gap:14px; align-items:baseline; padding:13px 0; border-bottom:1px solid var(--line-strong); color:var(--ink); text-decoration:none; }
        .hw-jump b { font-family:var(--font-display); font-size:24px; font-weight:700; color:var(--primary); }
        .hw-jump span { font-family:var(--font-display); font-size:22px; font-weight:600; }
        .hw-jump small { font-size:14px; color:var(--ink-3); text-align:right; }
        .hw-jump a:hover span { color:var(--primary-press); }

        .hw-example { border:1px solid var(--line-strong); border-radius:10px; overflow:hidden; background:#fff; }
        /* dealer photos carry a vendor badge along the bottom edge; the frame trims it off */
        .hw-ex-photo { position:relative; overflow:hidden; aspect-ratio:4/2.8; background:var(--bg-2); }
        .hw-ex-photo img { position:absolute; top:0; left:0; width:100%; height:107%; object-fit:cover; object-position:center 40%; }
        .hw-ex-head { padding:16px 20px 14px; border-bottom:2px solid var(--ink); }
        .hw-ex-head small { display:block; font-family:var(--font-display); font-size:14px; font-weight:600; text-transform:uppercase; color:var(--ink-3); }
        .hw-ex-head a { display:block; font-family:var(--font-display); font-size:22px; font-weight:700; color:var(--ink); text-decoration:none; margin-top:2px; }
        .hw-ex-head a:hover { color:var(--primary-press); }
        .hw-ex-head span { font-size:13.5px; color:var(--ink-3); }
        .hw-example dl { margin:0; }
        .hw-example dl > div { display:flex; justify-content:space-between; align-items:baseline; gap:16px; padding:13px 20px; border-bottom:1px dashed var(--line-strong); }
        .hw-example dl > div:last-child { border-bottom:none; background:var(--bg-2); }
        .hw-example dt { font-weight:700; } .hw-example dt small { display:block; font-weight:400; font-size:13.5px; color:var(--ink-3); }
        .hw-example dd { margin:0; font-family:var(--font-display); font-size:24px; font-weight:700; white-space:nowrap; }
        .hw-example .today dd { color:var(--primary); }

        /* steps: a big numeral, then what happens */
        .hw-steps { list-style:none; margin:24px 0 0; padding:0; }
        .hw-step { display:grid; grid-template-columns:120px 230px minmax(0, 1fr); gap:0 28px; padding:30px 0; border-bottom:1px solid var(--line-strong); scroll-margin-top:90px; }
        .hw-num { font-family:var(--font-display); font-size:104px; font-weight:700; line-height:.8; color:var(--primary); }
        .hw-step h3 { font-size:26px; font-weight:700; line-height:1.1; margin:0; }
        .hw-step .hw-meta { font-size:14px; color:var(--ink-3); margin-top:6px; }
        .hw-step ul { margin:4px 0 12px; padding-left:20px; max-width:620px; }
        .hw-step li { margin:4px 0; }

        .hw-section { padding-top:56px; }
        .hw-section h2 { font-size:34px; font-weight:700; line-height:1.1; margin:0 0 8px; }

        /* documents: a paper checklist with real boxes to tick */
        .hw-checklist { list-style:none; margin:18px 0 0; padding:0; max-width:820px; border-top:2px solid var(--ink); }
        .hw-checklist li { display:grid; grid-template-columns:28px minmax(0, 1fr) 200px; gap:16px; align-items:start; padding:14px 0; border-bottom:1px solid var(--line-strong); }
        .hw-box { width:20px; height:20px; border:2px solid var(--ink); border-radius:5px; margin-top:3px; }
        .hw-checklist b { display:block; } .hw-checklist small { color:var(--ink-2); font-size:14px; }
        .hw-when { font-family:var(--font-display); font-size:15px; font-weight:600; text-transform:uppercase; color:var(--primary-press); }

        /* price: laid out like a window sticker */
        .hw-sticker { max-width:560px; border:3px solid var(--ink); border-radius:14px; overflow:hidden; margin-top:18px; background:#fff; }
        .hw-sticker header { background:var(--ink); color:#fff; display:flex; justify-content:space-between; padding:10px 16px; font-family:var(--font-display); font-size:18px; font-weight:600; text-transform:uppercase; }
        .hw-sticker .grp { padding:12px 16px 4px; font-family:var(--font-display); font-weight:700; font-size:15px; text-transform:uppercase; }
        .hw-sticker .line { display:flex; justify-content:space-between; gap:16px; padding:9px 16px; border-top:1px dashed var(--line-strong); font-size:15px; }
        .hw-sticker .line span:last-child { color:var(--ink-2); text-align:right; }
        .hw-sticker footer { border-top:3px solid var(--ink); padding:10px 16px; font-size:13.5px; color:var(--ink-2); }
        .hw-price { display:grid; grid-template-columns:minmax(0, 1fr) minmax(0, 560px); gap:40px; align-items:start; }

        /* deposit and seller */
        .hw-two { display:grid; grid-template-columns:minmax(0, 1fr) minmax(0, 1fr); gap:48px; padding-top:56px; }
        .hw-deposit { display:grid; grid-template-columns:auto minmax(0, 1fr); gap:0 24px; }
        .hw-big { font-family:var(--font-display); font-size:92px; font-weight:700; line-height:.85; color:var(--primary); }
        .hw-two dl { margin:6px 0 0; }
        .hw-two dt { font-weight:700; margin-top:10px; }
        .hw-two dd { margin:2px 0 0; color:var(--ink-2); }

        .hw-end { margin-top:60px; background:var(--ink); color:#fff; border-radius:10px; padding:30px 32px; display:flex; align-items:center; justify-content:space-between; gap:20px; flex-wrap:wrap; }
        .hw-end h2 { color:#fff; font-size:32px; font-weight:700; text-transform:uppercase; margin:0; }
        .hw-btn { display:inline-block; padding:13px 24px; border-radius:10px; background:var(--primary); color:#fff; font-family:var(--font-display); font-size:18px; font-weight:700; text-transform:uppercase; text-decoration:none; }
        .hw-btn:hover { background:#fff; color:var(--ink); }

        @media (max-width:860px) {
            .hw-top { grid-template-columns:minmax(0, 1fr); gap:28px; padding-top:8px; }
            .hw-jump small { display:none; }
            .hw-step { grid-template-columns:72px minmax(0, 1fr); gap:8px 18px; }
            .hw-num { font-size:68px; grid-row:span 2; }
            .hw-step > div:last-child { grid-column:2; }
            .hw-checklist li { grid-template-columns:28px minmax(0, 1fr); }
            .hw-when { grid-column:2; }
            .hw-price, .hw-two { grid-template-columns:1fr; gap:28px; }
            .hw-big { font-size:68px; }
        }
    </style>

    <header class="hw-top {{ $car ? '' : 'no-car' }}">
        <div class="hw-top-copy">
            <h1>How buying a car on TruCars works</h1>
            <p>Every car here is sold by a licensed dealership at the price on the listing. You reserve it online, the dealership finishes the deal with you, and you drive away.</p>
            <ol class="hw-jump" aria-label="Steps">
                <li><a href="#find"><b>1</b><span>Find a car</span><small>No account needed</small></a></li>
                <li><a href="#reserve"><b>2</b><span>Reserve it for $150</span><small>About five minutes</small></a></li>
                <li><a href="#paperwork"><b>3</b><span>Finish the paperwork</span><small>Usually within a day</small></a></li>
                <li><a href="#pickup"><b>4</b><span>Pick it up</span><small>On the day you chose</small></a></li>
            </ol>
        </div>
        @if ($car)
            <aside class="hw-example" aria-label="Worked example">
                <div class="hw-ex-photo"><img src="{{ $car->primary_photo_url }}" alt=""></div>
                <div class="hw-ex-head">
                    <small>What you’d pay, and when</small>
                    <a href="{{ url('/cars/' . $car->id) }}" wire:navigate>{{ $car->model_year }} {{ $car->make }} {{ $car->model }}</a>
                    <span>{{ ucwords(strtolower($car->dealer->name)) }}{{ $car->display_location ? ', ' . $car->display_location : '' }}</span>
                </div>
                <dl>
                    <div><dt>Listed price<small>The price on the listing</small></dt><dd>{{ $car->display_price }}</dd></div>
                    <div class="today"><dt>Today, to reserve it<small>Refundable any time before delivery</small></dt><dd>${{ number_format(Deposits::AMOUNT_IN_CENTS / 100) }}</dd></div>
                    <div><dt>At pickup<small>Plus tax and licensing</small></dt><dd>${{ number_format(max(0, $car->price_in_cents - Deposits::AMOUNT_IN_CENTS) / 100) }}</dd></div>
                </dl>
            </aside>
        @endif
    </header>

    <ol class="hw-steps">
        <li class="hw-step" id="find">
            <div class="hw-num">1</div>
            <header>
                <h3>Find a car</h3>
                <div class="hw-meta">No account needed to browse</div>
            </header>
            <div>
                <p>Search by budget, body style, make and distance. Each listing shows:</p>
                <ul>
                    <li>the dealership selling it, where it is, and its dealer registration number</li>
                    <li>the price you’ll pay before tax and licensing</li>
                    <li>for certified cars, the full inspection checklist</li>
                </ul>
                <p class="muted">Sign in to save cars and compare them later.</p>
            </div>
        </li>

        <li class="hw-step" id="reserve">
            <div class="hw-num">2</div>
            <header>
                <h3>Reserve it for $150</h3>
                <div class="hw-meta">About five minutes online</div>
            </header>
            <div>
                <p>Checkout asks how you’d like to pay (cash, finance or lease), whether you have a trade-in, and when you’d like to pick the car up or have it delivered. You also do a quick ID check.</p>
                <p>Once you pay the $150 deposit, the car is held for you and nobody else can reserve it. The deposit comes off the price when you buy, and it’s refundable any time before delivery.</p>
            </div>
        </li>

        <li class="hw-step" id="paperwork">
            <div class="hw-num">3</div>
            <header>
                <h3>Finish the paperwork</h3>
                <div class="hw-meta">The dealership usually calls within a day</div>
            </header>
            <div>
                <p>The dealership contacts you to go over the numbers and answer questions. You upload documents in My Garage when they’re asked for (the checklist below), and you can see where your deal is at any time.</p>
                <p>If you’re financing, the dealership sends your application to a lender. When the bank approves it, the actual terms appear in My Garage: the lender, your rate, the payment and how often, the term, and the date of your first payment.</p>
            </div>
        </li>

        <li class="hw-step" id="pickup">
            <div class="hw-num">4</div>
            <header>
                <h3>Pick it up</h3>
                <div class="hw-meta">On the day you chose</div>
            </header>
            <div>
                <p>Collect the car at the dealership, or have it brought to your address, in the time window you picked at checkout. The dealership confirms the exact time with you beforehand.</p>
                <p>You pay the balance: the listed price, less your $150 deposit, plus tax and licensing.</p>
            </div>
        </li>
    </ol>

    <section class="hw-section" id="documents">
        <h2>What you’ll be asked for</h2>
        <p class="muted">Nothing is due all at once. Each document is requested when the dealership needs it, and we’ll email you if one is still missing. A PDF or a clear phone photo is fine.</p>
        <ul class="hw-checklist">
            <li><span class="hw-box"></span><div><b>Driver’s licence</b><small>Everyone</small></div><span class="hw-when">After you reserve</span></li>
            <li><span class="hw-box"></span><div><b>Your last two pay stubs</b><small>If you’re financing or leasing</small></div><span class="hw-when">After you reserve</span></li>
            <li><span class="hw-box"></span><div><b>A void cheque</b><small>If you’re financing or leasing, for the car payments</small></div><span class="hw-when">After you reserve</span></li>
            <li><span class="hw-box"></span><div><b>Proof of insurance</b><small>Everyone, before you take the car home</small></div><span class="hw-when">Once the deal is final</span></li>
        </ul>
        <p class="muted" style="margin-top:14px">Now and then a dealership needs something else, such as a bank statement. They’ll tell you what and why.</p>
    </section>

    <section class="hw-section hw-price" id="price">
        <div>
            <h2>What the listed price includes</h2>
            <p class="muted">Dealers on TruCars list an all-in price. Their own costs are already in it, and only government charges are added. Checkout shows every line for the car you’ve chosen before you pay anything.</p>
        </div>
        <div class="hw-sticker">
            <header><span>Price sheet</span><span>TruCars</span></header>
            <div class="grp">In the listed price</div>
            <div class="line"><span>The car</span><span></span></div>
            <div class="line"><span>Freight and pre-delivery inspection</span><span>where charged</span></div>
            <div class="line"><span>Dealer admin fees</span><span></span></div>
            <div class="grp">Added when you buy</div>
            <div class="line"><span>HST, or GST and PST</span><span>your province’s rate</span></div>
            <div class="line"><span>Licensing and registration</span><span>at cost</span></div>
            <footer>The exact amounts for your car are on the checkout page.</footer>
        </div>
    </section>

    <div class="hw-two">
        <section class="hw-deposit" id="deposit">
            <div class="hw-big">$150</div>
            <div>
                <h2 style="font-size:28px; margin:0">The deposit</h2>
                <dl>
                    <dt>What it does</dt>
                    <dd>Takes the car off the market while you finish up.</dd>
                    <dt>When you buy</dt>
                    <dd>It comes straight off the price.</dd>
                    <dt>If you change your mind</dt>
                    <dd>It’s refundable any time before you take delivery.</dd>
                </dl>
            </div>
        </section>
        <section id="seller">
            <h2 style="font-size:28px; margin:0 0 8px">Who you’re buying from</h2>
            <p>TruCars doesn’t own any of the cars. Each one is sold by the dealership named on the listing, which is licensed in its province. They handle your financing, paperwork and handover, and they’re the ones who give you the keys.</p>
            <p class="muted">Questions about a specific car are best asked to that dealership. My Garage shows which one is handling your purchase.</p>
        </section>
    </div>

    <div class="hw-end">
        <h2>See what’s on the lot</h2>
        <a class="hw-btn" href="{{ route('shop') }}" wire:navigate>Browse cars</a>
    </div>
</div>
