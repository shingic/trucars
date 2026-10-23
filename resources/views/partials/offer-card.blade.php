{{--
    One offer as a customer sees it. Expects $offer (App\Models\Offer). Used on
    the vehicle page and as the live preview in the offer forms.
--}}
<div class="offer-card offer-{{ $offer->type }}">
    <style>
        .offer-card { position:relative; border-radius:18px; padding:16px 18px; background:linear-gradient(135deg, #14161B, #2A2D35); color:#fff; overflow:hidden; }
        .offer-card::after { content:""; position:absolute; right:-40px; top:-60px; width:160px; height:160px; border-radius:50%; background:radial-gradient(circle, rgba(31,85,214,.45), transparent 70%); }
        .offer-card .oc-kicker { position:relative; z-index:1; display:flex; align-items:center; gap:7px; font-size:11px; font-weight:800; letter-spacing:.06em; text-transform:uppercase; color:#A9C2FF; }
        .offer-card .oc-kicker svg { width:13px; height:13px; }
        .offer-card .oc-head { position:relative; z-index:1; font-size:30px; font-weight:800; letter-spacing:0; line-height:1.05; margin-top:8px; }
        .offer-card .oc-detail { position:relative; z-index:1; font-size:13.5px; color:rgba(255,255,255,.82); margin-top:4px; }
        .offer-card .oc-fine { position:relative; z-index:1; font-size:11.5px; color:rgba(255,255,255,.55); margin-top:10px; line-height:1.45; }
    </style>
    <div class="oc-kicker">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8Z"/><circle cx="7.5" cy="7.5" r="1.5"/></svg>
        {{ $offer->title }}
    </div>
    <div class="oc-head">{{ $offer->headline() }}</div>
    @if ($offer->detail()) <div class="oc-detail">{{ $offer->detail() }}</div> @endif
    <div class="oc-fine">{{ $offer->conditions }} Offer ends {{ $offer->ends_on->format('F j, Y') }}.</div>
</div>
