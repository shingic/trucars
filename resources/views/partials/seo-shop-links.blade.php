{{--
    Under the shop's results (and every search landing page): why buy here,
    then the search links: models, makes, body styles, price bands, fuel types
    and towns with enough cars in stock (App\Support\Seo\SeoCatalog). It gives
    search engines real copy and a path to every landing page.

    $subject   what the page is about, singular ("Honda Civic"), or null for all cars
    $current   this page's own URL, left out of its links
--}}
@php
    $subject ??= null;
    $current ??= null;
    $brand = config('site.name');
    $groups = collect(\App\Support\Seo\SeoCatalog::linkGroups())
        ->map(fn ($links) => array_values(array_filter($links, fn ($link) => $link['url'] !== $current)))
        ->filter();
    $titles = [
        'models' => 'Popular Models',
        'makes'  => 'Popular Makes',
        'bodies' => 'Shop by Body Style',
        'prices' => 'Shop by Price',
        'fuels'  => 'Shop by Fuel Type',
        'cities' => 'Popular Locations',
    ];
@endphp
<section class="seo-shop" aria-labelledby="seo-why">
    <div class="seo-why">
        <h2 id="seo-why">Why buy your next {{ $subject ?? 'car' }} from {{ $brand }}?</h2>
        <div class="seo-why-cols">
            <div>
                <h3>Overview</h3>
                <p>{{ $brand }} brings certified pre-owned cars from Canadian dealerships into one place. Every car is inspected and certified by the dealership that sells it, and priced <b>all-in</b>: freight, prep, dealer fees and the regulator fee are already in the number you see. Only HST and licensing are added.</p>
                <ul>
                    <li><b>One price, no add-ons</b> – What's on the listing is what the car costs before tax and licensing.</li>
                    <li><b>Certified, never as-is</b> – Each car passes the dealership's certification inspection before it's listed.</li>
                    <li><b>Vehicle history up front</b> – A CARFAX Canada report on the car's page wherever the dealership provides one.</li>
                    <li><b>Hold it for $150</b> – Reserve online. Your deposit is fully refundable until pickup and comes off the price.</li>
                    <li><b>Close to home</b> – Every car shows how far it is from you, and you pick it up at the dealership on a day you choose.</li>
                    <li><b>Trade-in and financing in one checkout</b> – Get a trade-in estimate, and the dealership's finance office confirms your rate.</li>
                </ul>
            </div>
            <div>
                <h3>Why buy certified pre-owned</h3>
                <p>A certified used car gets you most of what a new one does, for a lot less.</p>
                <ul>
                    <li><b>Less depreciation</b> – The steepest drop in value happens in the first years, and the first owner took it.</li>
                    <li><b>More car for the money</b> – The same budget reaches a higher trim, a premium brand or an EV.</li>
                    <li><b>Checked, not guessed</b> – Certification means a full inspection, not just a wash and a photo.</li>
                    <li><b>Often cheaper to insure</b> – Premiums are usually lower on a used car than on the same model new.</li>
                </ul>
                <h3>Things to weigh</h3>
                <ul>
                    <li><b>How far it is</b> – You collect the car in person, so check the distance before you reserve.</li>
                    <li><b>Financing rates</b> – Used-car loans can carry higher rates than new-car promotions. The lower price usually still wins on total cost.</li>
                    <li><b>Older tech</b> – Earlier model years may miss the newest driver-assistance and infotainment features.</li>
                    <li><b>Popular cars go fast</b> – If one fits, a refundable $150 holds it while you decide.</li>
                </ul>
            </div>
        </div>
    </div>

    @foreach ($titles as $key => $title)
        @continue(! isset($groups[$key]))
        <div class="seo-group" x-data="{ more: false }">
            <h2>{{ $title }}</h2>
            <ul>
                @foreach ($groups[$key] as $index => $link)
                    <li @if ($index >= 8) x-show="more" x-cloak @endif><a href="{{ $link['url'] }}" wire:navigate>{{ $link['text'] }}</a></li>
                @endforeach
            </ul>
            @if (count($groups[$key]) > 8)
                <button type="button" class="seo-more" @click="more = ! more" x-text="more ? 'Show less' : 'Show more'">Show more</button>
            @endif
        </div>
    @endforeach
</section>

@once
    <style>
        .seo-shop { margin-top:56px; }
        .seo-why, .seo-group { border-top:1px solid var(--line-strong); padding:30px 0; }
        .seo-shop h2 { font-family:var(--font-display); font-size:22px; font-weight:700; line-height:1.2; color:var(--ink); margin:0 0 18px; }
        .seo-why-cols { display:grid; grid-template-columns:1fr 1fr; gap:24px 56px; }
        .seo-why h3 { font-family:var(--font-display); font-size:17px; font-weight:700; color:var(--ink); margin:0 0 10px; }
        .seo-why h3 + p { margin-top:0; }
        .seo-why ul + h3 { margin-top:26px; }
        .seo-why p, .seo-why li { font-size:15px; line-height:1.6; color:var(--ink-2); }
        .seo-why p { margin:0 0 14px; }
        .seo-why p b, .seo-why li b { color:var(--ink); font-weight:700; }
        .seo-why ul { list-style:none; margin:0; padding:0; display:grid; gap:8px; }
        .seo-group ul { list-style:none; margin:0; padding:0; display:grid; grid-template-columns:1fr 1fr; gap:14px 56px; }
        .seo-group a { font-size:15.5px; color:var(--ink); text-decoration:underline; text-decoration-color:var(--line-strong); text-underline-offset:4px; }
        .seo-group a:hover { color:var(--primary-press); text-decoration-color:currentColor; }
        .seo-more { margin-top:18px; font-size:15px; font-weight:700; color:var(--ink); background:none; border:none; padding:0; cursor:pointer; }
        .seo-more:hover { color:var(--primary-press); }
        .seo-group a:focus-visible, .seo-more:focus-visible { outline:2px solid var(--primary); outline-offset:3px; }
        @media (max-width:760px) {
            .seo-shop { margin-top:40px; }
            .seo-why-cols, .seo-group ul { grid-template-columns:1fr; }
            .seo-shop h2 { font-size:20px; }
        }
    </style>
@endonce
