<?php

use Livewire\Attributes\Title;
use Livewire\Component;

/*
| How TruCars works, start to finish, for shoppers. Static content — kept in
| step with the actual flow: listing → reserve ($150, refundable) → ID check
| and dealer contact → pickup or delivery.
*/
new #[Title('How it works — TruCars')] class extends Component {
}; ?>

<div class="hiw">
    <style>
        .hiw .eyebrow { display:inline-flex; align-items:center; gap:8px; font-size:12.5px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; color:var(--primary-press); }
        .hiw .eyebrow::before { content:""; width:18px; height:2px; background:var(--primary); border-radius:2px; }
        .hiw h2 { font-size:clamp(26px, 3vw, 38px); font-weight:800; letter-spacing:-.03em; line-height:1.1; margin:12px 0 0; }

        .hiw-hero { text-align:center; padding:56px 20px 30px; max-width:860px; margin:0 auto; }
        .hiw-hero h1 { font-size:clamp(38px, 5.4vw, 68px); font-weight:800; letter-spacing:-.045em; line-height:1; margin:18px 0 0; }
        .hiw-hero h1 em { font-style:normal; background:var(--hero-grad); -webkit-background-clip:text; background-clip:text; color:transparent; }
        .hiw-hero p { font-size:18.5px; color:var(--ink-2); margin:18px auto 0; max-width:640px; }
        .jump { display:flex; justify-content:center; gap:8px; flex-wrap:wrap; margin-top:28px; }
        .jump a { display:inline-flex; align-items:center; gap:8px; padding:9px 16px; border-radius:var(--radius-pill); border:1px solid var(--line-strong); text-decoration:none; font-size:14px; font-weight:600; transition:all .14s ease; }
        .jump a:hover { border-color:var(--primary); color:var(--primary-press); }
        .jump a span { font-family:var(--font-mono); font-size:12px; color:var(--primary-press); }

        /* steps */
        .timeline { position:relative; max-width:1120px; margin:56px auto 0; }
        .timeline::before { content:""; position:absolute; left:50%; top:30px; bottom:30px; width:2px; background:linear-gradient(var(--primary-line), var(--line)); transform:translateX(-50%); }
        .tstep { position:relative; display:grid; grid-template-columns:1fr 1fr; gap:80px; align-items:center; padding:36px 0; scroll-margin-top:90px; }
        .tstep .dot { position:absolute; left:50%; top:50%; transform:translate(-50%, -50%); width:54px; height:54px; border-radius:18px; background:var(--card); border:2px solid var(--primary); display:grid; place-items:center; font-weight:800; font-size:19px; color:var(--primary-press); box-shadow:0 0 0 8px var(--bg); z-index:1; }
        .tstep:nth-child(even) .copy { order:2; }
        .tstep .copy h3 { font-size:28px; font-weight:800; letter-spacing:-.025em; margin:10px 0 10px; }
        .tstep .copy p { margin:0; font-size:16px; color:var(--ink-2); }
        .tstep .copy ul { margin:16px 0 0; padding:0; list-style:none; display:grid; gap:9px; }
        .tstep .copy li { display:flex; gap:10px; font-size:14.5px; color:var(--ink); }
        .tstep .copy li svg { flex-shrink:0; color:var(--good); margin-top:2px; }
        .tstep .when { font-family:var(--font-mono); font-size:12px; color:var(--ink-3); }

        /* mini illustrations */
        .ill { border-radius:28px; background:var(--bg-2); padding:28px; min-height:250px; display:grid; place-items:center; position:relative; overflow:hidden; }
        .ill::after { content:""; position:absolute; inset:auto -60px -90px auto; width:220px; height:220px; border-radius:50%; background:radial-gradient(circle, rgba(245,99,31,.16), transparent 70%); }
        .mock { position:relative; z-index:1; width:100%; max-width:340px; background:var(--card); border-radius:20px; box-shadow:var(--shadow-md); padding:18px; }
        .mock .row { display:flex; justify-content:space-between; align-items:center; font-size:13px; padding:7px 0; color:var(--ink-2); }
        .mock .row b { color:var(--ink); }
        .mock .ok { color:var(--good); font-weight:700; }
        .mock .bar { height:9px; border-radius:9px; background:var(--bg-2); margin:6px 0; }
        .mock .bar.w1 { width:80%; } .mock .bar.w2 { width:55%; } .mock .bar.w3 { width:68%; }
        .mock .pill { display:inline-block; font-size:11px; font-weight:800; letter-spacing:.04em; text-transform:uppercase; padding:4px 10px; border-radius:var(--radius-pill); background:var(--good-soft); color:#0B7A59; }
        .mock .big { font-size:28px; font-weight:800; letter-spacing:-.02em; }
        .mock .btn { display:block; text-align:center; margin-top:12px; padding:11px; border-radius:var(--radius-pill); background:var(--primary); color:#fff; font-weight:700; font-size:14px; }
        .mock .search { display:flex; align-items:center; gap:8px; border:1.5px solid var(--line-strong); border-radius:var(--radius-pill); padding:9px 14px; font-size:13px; color:var(--ink-3); }
        .mock .cards { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-top:12px; }
        .mock .mini { border-radius:12px; background:var(--bg-2); padding:9px; }
        .mock .mini i { display:block; aspect-ratio:4/3; border-radius:8px; background:var(--hero-grad); opacity:.75; margin-bottom:7px; }
        .mock .mini b { font-size:12.5px; }
        .mock .chat { display:flex; gap:10px; align-items:flex-start; margin-top:4px; }
        .mock .chat .av { width:32px; height:32px; border-radius:50%; background:var(--ink); color:#fff; display:grid; place-items:center; font-size:12px; font-weight:800; flex-shrink:0; }
        .mock .chat .bubble { background:var(--bg-2); border-radius:4px 14px 14px 14px; padding:10px 12px; font-size:13px; }
        .mock .slots { display:grid; grid-template-columns:repeat(3, 1fr); gap:6px; margin-top:10px; }
        .mock .slots span { text-align:center; font-size:12px; font-weight:700; padding:8px 0; border-radius:10px; border:1.5px solid var(--line-strong); }
        .mock .slots span.on { border-color:var(--primary); background:var(--primary-soft); color:var(--primary-press); }

        /* what's in the price */
        .inprice { margin-top:80px; border-radius:36px; background:#14161B; color:#fff; padding:56px; display:grid; grid-template-columns:minmax(0, 1fr) minmax(0, 1fr); gap:48px; align-items:center; }
        .inprice h2 { color:#fff; }
        .inprice .eyebrow { color:#FFB98A; }
        .inprice .eyebrow::before { background:#FFB98A; }
        .inprice p { color:rgba(255,255,255,.72); font-size:16px; margin:14px 0 0; }
        .ledger { display:grid; gap:10px; }
        .ledger div { display:flex; justify-content:space-between; align-items:center; padding:14px 18px; border-radius:16px; background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.08); font-size:15px; }
        .ledger div b { color:#5EE0B5; font-size:13.5px; }
        .ledger div.extra { background:transparent; border-style:dashed; color:rgba(255,255,255,.6); }
        .ledger div.extra b { color:rgba(255,255,255,.6); }

        /* deposit + not-us */
        .twin { display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-top:20px; }
        .panel { border-radius:28px; border:1px solid var(--line); padding:34px; }
        .panel h3 { font-size:22px; font-weight:800; letter-spacing:-.02em; margin:14px 0 10px; }
        .panel p { margin:0; color:var(--ink-2); font-size:15px; }
        .panel .badge { width:48px; height:48px; border-radius:15px; display:grid; place-items:center; background:var(--primary-soft); color:var(--primary-press); }
        .facts { display:grid; gap:10px; margin-top:18px; }
        .facts div { display:flex; gap:12px; font-size:14.5px; }
        .facts b { min-width:110px; }

        .cta { margin-top:80px; text-align:center; padding:60px 24px; border-radius:36px; background:var(--bg-2); }
        .cta h2 { margin:0; }
        .cta p { color:var(--ink-2); font-size:17px; margin:12px 0 26px; }
        .btn-hero { display:inline-flex; align-items:center; gap:9px; font-size:15.5px; font-weight:700; border-radius:var(--radius-pill); padding:15px 28px; text-decoration:none; background:var(--primary); color:#fff; box-shadow:var(--shadow-primary); }
        .btn-hero:hover { background:var(--primary-press); }

        @media (max-width:900px) {
            .timeline::before { left:27px; }
            .tstep { grid-template-columns:1fr; gap:22px; padding:28px 0 28px 76px; }
            .tstep .dot { left:27px; top:56px; }
            .tstep:nth-child(even) .copy { order:0; }
            .inprice, .twin { grid-template-columns:1fr; }
            .inprice { padding:36px 22px; border-radius:26px; }
            .ill { padding:18px; min-height:0; }
        }
    </style>

    @php $check = '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>'; @endphp

    <div class="hiw-hero">
        <span class="eyebrow">How it works</span>
        <h1>Four steps. <em>No haggling.</em></h1>
        <p>TruCars connects you with cars at licensed dealerships, with the real, all-in price up front. Here’s exactly what happens from the moment you find a car to the day you drive it home.</p>
        <div class="jump">
            <a href="#find"><span>01</span> Find</a>
            <a href="#hold"><span>02</span> Hold</a>
            <a href="#confirm"><span>03</span> Confirm</a>
            <a href="#drive"><span>04</span> Drive</a>
        </div>
    </div>

    <div class="timeline">
        <div class="tstep" id="find">
            <span class="dot">1</span>
            <div class="copy">
                <span class="when">Takes as long as you like</span>
                <h3>Find the one</h3>
                <p>Every car is listed by a licensed dealership and shows the price you’ll actually pay. Filter by budget, body style, make and distance from you.</p>
                <ul>
                    <li>{!! $check !!} The selling dealer and their registration number on every listing</li>
                    <li>{!! $check !!} All-in price — freight, prep and fees already included</li>
                    <li>{!! $check !!} Certified cars list every inspection point</li>
                    <li>{!! $check !!} Save favourites to compare later</li>
                </ul>
            </div>
            <div class="ill">
                <div class="mock">
                    <div class="search"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg> SUV under $35,000 near me</div>
                    <div class="cards">
                        <div class="mini"><i></i><b>$31,990</b><div class="bar w1"></div></div>
                        <div class="mini"><i></i><b>$28,450</b><div class="bar w2"></div></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="tstep" id="hold">
            <span class="dot">2</span>
            <div class="copy">
                <span class="when">About 5 minutes</span>
                <h3>Hold it for $150</h3>
                <p>Reserve online and the car is taken off the market for you while you finish up. Tell us how you’d like to pay and whether you want it picked up or delivered.</p>
                <ul>
                    <li>{!! $check !!} $150 deposit, refundable any time before delivery</li>
                    <li>{!! $check !!} It comes straight off the price when you buy</li>
                    <li>{!! $check !!} No one else can reserve the car while it’s held for you</li>
                </ul>
            </div>
            <div class="ill">
                <div class="mock">
                    <div class="row"><b>2023 Mitsubishi Outlander</b><span class="pill">Available</span></div>
                    <div class="row">All-in price <b>$31,990</b></div>
                    <div class="row">Deposit today <b>$150</b></div>
                    <div class="row">Refundable <span class="ok">✓ Before delivery</span></div>
                    <span class="btn">Reserve this car</span>
                </div>
            </div>
        </div>

        <div class="tstep" id="confirm">
            <span class="dot">3</span>
            <div class="copy">
                <span class="when">Usually within a day</span>
                <h3>Confirm the details</h3>
                <p>Verify your ID and upload any documents from My Garage. The dealership then contacts you to confirm the final numbers and answer anything you want to know.</p>
                <ul>
                    <li>{!! $check !!} Quick online ID check — no trip to the store just for paperwork</li>
                    <li>{!! $check !!} Track every step of your deal in My Garage</li>
                    <li>{!! $check !!} Talk to the actual dealership selling the car</li>
                </ul>
            </div>
            <div class="ill">
                <div class="mock">
                    <div class="row"><b>My Garage</b><span class="pill">Reserved</span></div>
                    <div class="row">ID verified <span class="ok">✓</span></div>
                    <div class="row">Documents <span class="ok">✓ 2 of 2</span></div>
                    <div class="chat">
                        <span class="av">D</span>
                        <span class="bubble">Hi! Your Outlander is set aside. Does Thursday work for pickup?</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="tstep" id="drive">
            <span class="dot">4</span>
            <div class="copy">
                <span class="when">On the day you choose</span>
                <h3>Drive it home</h3>
                <p>Pick the car up at the dealership, or have it delivered. You choose the day and the time window when you reserve.</p>
                <ul>
                    <li>{!! $check !!} Pickup or delivery — your call</li>
                    <li>{!! $check !!} Your $150 deposit is already credited</li>
                    <li>{!! $check !!} Only HST and licensing on top of the price you saw</li>
                </ul>
            </div>
            <div class="ill">
                <div class="mock">
                    <div class="row"><b>Handover</b><span>Pickup</span></div>
                    <div class="slots"><span>Wed</span><span class="on">Thu</span><span>Fri</span></div>
                    <div class="slots"><span>10–12</span><span class="on">12–2</span><span>2–4</span></div>
                    <div class="row" style="margin-top:8px">You’re all set <span class="ok">✓</span></div>
                </div>
            </div>
        </div>
    </div>

    <div class="inprice">
        <div>
            <span class="eyebrow">What’s in the price</span>
            <h2>The number on the listing is the number you pay.</h2>
            <p>Everything it takes to get the car ready to sell is already in the price you see. The only things added at purchase are tax and your plates.</p>
        </div>
        <div class="ledger">
            <div>Vehicle <b>✓ Included</b></div>
            <div>Freight &amp; pre-delivery inspection <b>✓ Included</b></div>
            <div>Dealer admin fee <b>✓ Included</b></div>
            <div>Regulator fee <b>✓ Included</b></div>
            <div class="extra">HST <b>Added at purchase</b></div>
            <div class="extra">Licensing &amp; plates <b>Added at purchase</b></div>
        </div>
    </div>

    <div class="twin">
        <div class="panel">
            <span class="badge"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg></span>
            <h3>Your $150 deposit</h3>
            <div class="facts">
                <div><b>What it does</b><span>Takes the car off the market while you finish up.</span></div>
                <div><b>When you buy</b><span>It comes straight off the price.</span></div>
                <div><b>If you don’t</b><span>It’s refundable any time before you take delivery.</span></div>
            </div>
        </div>
        <div class="panel">
            <span class="badge"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18M5 21V8l7-4 7 4v13M9 21v-6h6v6"/></svg></span>
            <h3>Who you’re buying from</h3>
            <p>TruCars doesn’t own the cars. Each one is sold by the licensed dealership on the listing — you see their name, location and registration number before you reserve, and they’re the ones who hand you the keys.</p>
        </div>
    </div>

    <div class="cta">
        <h2>Ready when you are.</h2>
        <p>Every car, every dealer, every price all-in.</p>
        <a class="btn-hero" href="{{ route('shop') }}" wire:navigate>Browse cars <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
    </div>
</div>
