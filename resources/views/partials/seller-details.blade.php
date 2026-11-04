{{--
    The selling dealership, shown only after the buyer's deposit is paid
    (checkout confirmation and My Garage). Expects $seller from
    Deal::sellerDetails(). Before reserving, shoppers only see the distance.
--}}
@once
    <style>
        .seller { text-align:left; border-top:2px solid var(--ink); padding-top:14px; }
        .seller-k { font-size:13px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:var(--ink-3); }
        .seller-name { font-family:var(--font-display); font-size:24px; font-weight:700; color:var(--ink); margin-top:4px; line-height:1.1; }
        .seller-store { font-size:15px; font-weight:600; color:var(--ink-2); margin-top:3px; }
        .seller-lines { margin:10px 0 0; padding:0; list-style:none; font-size:15px; color:var(--ink-2); line-height:1.55; }
        .seller-lines a { color:var(--primary-press); font-weight:600; text-decoration:underline; text-underline-offset:3px; }
        .seller-pickup { font-size:14.5px; color:var(--ink-2); margin-top:10px; padding-top:10px; border-top:1px solid var(--line); }
        .seller-pickup b { color:var(--ink); }
        .seller-reg { font-size:13.5px; color:var(--ink-3); margin-top:8px; }
    </style>
@endonce
<div class="seller">
    <div class="seller-k">Your dealership</div>
    <div class="seller-name">{{ ucwords(strtolower($seller['name'])) }}</div>
    @if ($seller['store']) <div class="seller-store">{{ $seller['store'] }}</div> @endif
    <ul class="seller-lines">
        @foreach ($seller['address_lines'] as $line) <li>{{ $line }}</li> @endforeach
        @if ($seller['phone']) <li><a href="tel:{{ preg_replace('/[^0-9+]/', '', $seller['phone']) }}">{{ $seller['phone'] }}</a></li> @endif
        @if ($seller['email']) <li><a href="mailto:{{ $seller['email'] }}">{{ $seller['email'] }}</a></li> @endif
    </ul>
    @if (! empty($seller['pickup'])) <div class="seller-pickup"><b>Pickup:</b> {{ $seller['pickup'] }}</div> @endif
    @if ($seller['registration']) <div class="seller-reg">{{ $seller['registration'] }}</div> @endif
</div>
