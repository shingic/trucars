{{--
    One offer as a customer sees it. Expects $offer (App\Models\Offer). Used on
    the vehicle page and as the live preview in the offer forms.
--}}
<div class="offer-card offer-{{ $offer->type }}">
    <style>
        .offer-card { border-radius:3px; padding:14px 16px 15px; background:var(--ink); color:#fff; border-left:5px solid var(--primary); }
        .offer-card .oc-kicker { display:flex; align-items:center; gap:7px; font-family:var(--font-display); font-size:15px; font-weight:600; text-transform:uppercase; color:var(--primary-bright, #6CB0FF); }
        .offer-card .oc-kicker svg { width:14px; height:14px; flex-shrink:0; }
        .offer-card .oc-head { font-family:var(--font-display); font-size:34px; font-weight:700; line-height:1; margin-top:6px; }
        .offer-card .oc-detail { font-size:14px; color:rgba(255,255,255,.85); margin-top:4px; }
        .offer-card .oc-fine { font-size:12.5px; color:rgba(255,255,255,.6); margin-top:10px; line-height:1.45; }
    </style>
    <div class="oc-kicker">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8Z"/><circle cx="7.5" cy="7.5" r="1.5"/></svg>
        {{ $offer->title }}
    </div>
    <div class="oc-head">{{ $offer->headline() }}</div>
    @if ($offer->detail()) <div class="oc-detail">{{ $offer->detail() }}</div> @endif
    <div class="oc-fine">{{ $offer->conditions }} Offer ends {{ $offer->ends_on->format('F j, Y') }}.</div>
</div>
