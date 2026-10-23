<?php

use Livewire\Attributes\Title;
use Livewire\Component;

/*
| How TruCars works, start to finish, for shoppers. Static content — kept in
| step with the actual flow: listing → reserve ($150, refundable) → ID check,
| documents and the dealer's call → financing approval → insurance → pickup
| or delivery. The deposit wording mirrors checkout; change both together.
*/
new #[Title('How it works — TruCars')] class extends Component {
}; ?>

<div class="hw">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Semi+Condensed:wght@500;600&display=swap" rel="stylesheet">
    <style>
        .hw { max-width:1040px; margin:0 auto; padding:40px 0 72px; color:var(--ink); font-size:16px; line-height:1.6; }
        .hw h1, .hw h2, .hw h3 { font-family:"Barlow Semi Condensed", var(--font-ui); font-weight:600; letter-spacing:0; color:var(--ink); }
        .hw h1 { font-size:clamp(34px, 4.4vw, 48px); line-height:1.08; margin:0; max-width:760px; }
        .hw h2 { font-size:28px; line-height:1.15; margin:0 0 6px; }
        .hw h3 { font-size:22px; line-height:1.2; margin:0; }
        .hw p { margin:0 0 12px; max-width:640px; }
        .hw a { color:var(--primary-press); }
        .hw .muted { color:var(--ink-2); }

        .hw-intro { padding-bottom:28px; border-bottom:2px solid var(--ink); }
        .hw-intro p { font-size:18px; color:var(--ink-2); margin-top:14px; }

        /* the four steps: number and name on the left, what happens on the right */
        .hw-steps { list-style:none; margin:0; padding:0; counter-reset:step; }
        .hw-step { display:grid; grid-template-columns:260px minmax(0, 1fr); gap:32px; padding:28px 0; border-bottom:1px solid var(--line-strong); }
        .hw-step > header { counter-increment:step; }
        .hw-step > header::before { content:"Step " counter(step); display:block; font-size:13px; font-weight:600; color:var(--primary-press); margin-bottom:4px; }
        .hw-step .hw-meta { font-size:14px; color:var(--ink-3); margin-top:6px; }
        .hw-step ul { margin:4px 0 12px; padding-left:20px; max-width:640px; }
        .hw-step li { margin:4px 0; }
        .hw-step li::marker { color:var(--ink-3); }

        .hw-section { padding:44px 0 8px; }
        .hw-section > p.muted { margin-bottom:20px; }

        /* plain tables, ruled like a printed price sheet */
        .hw-table { width:100%; max-width:820px; border-collapse:collapse; font-size:15px; }
        .hw-table th { text-align:left; font-size:13px; font-weight:600; color:var(--ink-2); padding:0 16px 8px 0; border-bottom:1px solid var(--ink); }
        .hw-table td { padding:12px 16px 12px 0; border-bottom:1px solid var(--line-strong); vertical-align:top; }
        .hw-table td:first-child { font-weight:600; width:34%; }
        .hw-table .group td { padding-top:22px; font-size:13px; font-weight:600; color:var(--ink-2); border-bottom:1px solid var(--ink); }

        .hw-two { display:grid; grid-template-columns:1fr 1fr; gap:48px; padding-top:44px; }
        .hw-two dl { margin:12px 0 0; }
        .hw-two dt { font-weight:600; margin-top:12px; }
        .hw-two dd { margin:2px 0 0; color:var(--ink-2); }

        .hw-end { margin-top:52px; padding-top:24px; border-top:2px solid var(--ink); display:flex; align-items:center; justify-content:space-between; gap:20px; flex-wrap:wrap; }
        .hw-end p { margin:0; font-size:17px; }
        .hw-btn { display:inline-block; padding:12px 22px; border-radius:4px; background:var(--primary); color:#fff !important; font-weight:600; text-decoration:none; }
        .hw-btn:hover { background:var(--primary-press); }

        @media (max-width:820px) {
            .hw { padding-top:24px; }
            .hw-step { grid-template-columns:1fr; gap:10px; }
            .hw-two { grid-template-columns:1fr; gap:36px; }
            .hw-table td:first-child { width:auto; }
        }
    </style>

    <div class="hw-intro">
        <h1>How buying a car on TruCars works</h1>
        <p>Every car here is sold by a licensed dealership, at the price on the listing. You reserve it online, the dealership finishes the deal with you, and you pick it up or have it delivered. Here is each step and what you’ll be asked for along the way.</p>
    </div>

    <ol class="hw-steps">
        <li class="hw-step" id="find">
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
            <header>
                <h3>Reserve it for $150</h3>
                <div class="hw-meta">About five minutes online</div>
            </header>
            <div>
                <p>Checkout asks how you’d like to pay (cash, finance or lease), whether you have a trade-in, and when you’d like to pick the car up or have it delivered. You also do a quick ID check.</p>
                <p>Once you pay the $150 deposit, the car is held for you and nobody else can reserve it. The deposit comes off the price when you buy, and it’s refundable any time before delivery.</p>
            </div>
        </li>

        <li class="hw-step" id="confirm">
            <header>
                <h3>Finish the paperwork</h3>
                <div class="hw-meta">The dealership usually calls within a day</div>
            </header>
            <div>
                <p>The dealership contacts you to go over the numbers and answer questions. You upload documents in My Garage when they’re asked for (the table below lists them), and you can see where your deal is at any time.</p>
                <p>If you’re financing, the dealership sends your application to a lender. When the bank approves it, the actual terms appear in My Garage: the lender, your rate, the payment and how often, the term, and the date of your first payment.</p>
            </div>
        </li>

        <li class="hw-step" id="handover">
            <header>
                <h3>Pick it up</h3>
                <div class="hw-meta">On the day you chose</div>
            </header>
            <div>
                <p>Collect the car at the dealership, or have it brought to your address, in the time window you picked at checkout. The dealership confirms the exact time with you beforehand.</p>
                <p>You pay the balance: the listed price, less your $150 deposit, plus HST and licensing.</p>
            </div>
        </li>
    </ol>

    <section class="hw-section" id="documents">
        <h2>What you’ll be asked for</h2>
        <p class="muted">Nothing is due all at once. Each document is requested when the dealership needs it, and we’ll email you if one is still missing.</p>
        <table class="hw-table">
            <thead>
                <tr><th>Document</th><th>When</th><th>Who needs it</th></tr>
            </thead>
            <tbody>
                <tr><td>Driver’s licence</td><td>Right after you reserve</td><td>Everyone</td></tr>
                <tr><td>Your last two pay stubs</td><td>Right after you reserve</td><td>Finance and lease buyers</td></tr>
                <tr><td>A void cheque</td><td>Right after you reserve</td><td>Finance and lease buyers, for the car payments</td></tr>
                <tr><td>Proof of insurance</td><td>Once your deal is finalized</td><td>Everyone, before you take the car home</td></tr>
            </tbody>
        </table>
        <p class="muted" style="margin-top:14px">A PDF or a clear phone photo is fine. The dealership may occasionally ask for something else, such as a bank statement, and will tell you why.</p>
    </section>

    <section class="hw-section" id="price">
        <h2>What the listed price includes</h2>
        <p class="muted">Dealers on TruCars list an all-in price. Their own costs are already in it; only government charges are added.</p>
        <table class="hw-table">
            <tbody>
                <tr class="group"><td colspan="2">In the listed price</td></tr>
                <tr><td>The car</td><td></td></tr>
                <tr><td>Freight and pre-delivery inspection</td><td>Where the dealer charges them</td></tr>
                <tr><td>Dealer admin fees</td><td></td></tr>
                <tr class="group"><td colspan="2">Added when you buy</td></tr>
                <tr><td>HST or GST/PST</td><td>At your province’s rate</td></tr>
                <tr><td>Licensing and registration</td><td>At cost</td></tr>
            </tbody>
        </table>
        <p class="muted" style="margin-top:14px">Checkout shows every line for the car you’ve chosen before you pay anything.</p>
    </section>

    <div class="hw-two">
        <section id="deposit">
            <h2>About the $150 deposit</h2>
            <dl>
                <dt>What it does</dt>
                <dd>Takes the car off the market while you finish up.</dd>
                <dt>When you buy</dt>
                <dd>It comes straight off the price.</dd>
                <dt>If you change your mind</dt>
                <dd>It’s refundable any time before you take delivery.</dd>
            </dl>
        </section>
        <section id="seller">
            <h2>Who you’re buying from</h2>
            <p>TruCars doesn’t own any of the cars. Each one is sold by the dealership named on the listing, which is licensed in its province. They handle your financing, paperwork and handover, and they’re the ones who give you the keys.</p>
            <p class="muted">Questions about a specific car are best asked to that dealership. My Garage shows which one is handling your purchase.</p>
        </section>
    </div>

    <div class="hw-end">
        <p>Start with the cars near you.</p>
        <a class="hw-btn" href="{{ route('shop') }}" wire:navigate>Browse cars</a>
    </div>
</div>
