{{--
    The Trumo logo with "Certified pre-owned" under it (SVG, sharp at any
    size). $variant: 'dark' (default) for light backgrounds, 'light' for
    navy ones. $height in px (default 40); below ~34 the tagline gets hard
    to read. The viewBox is 3155 × 1275.
--}}
@php
    $logoFile = ($variant ?? 'dark') === 'light' ? 'trumo-cpo-white.svg' : 'trumo-cpo.svg';
    $logoHeight = $height ?? 40;
    $logoVersion = @filemtime(public_path('images/' . $logoFile)) ?: 1;
@endphp
<img src="{{ asset('images/' . $logoFile) }}?v={{ $logoVersion }}" alt="{{ config('site.name') }}, certified pre-owned" class="site-logo" width="{{ (int) round($logoHeight * 3155 / 1275) }}" height="{{ $logoHeight }}" style="height:{{ $logoHeight }}px; width:auto; display:block;">
