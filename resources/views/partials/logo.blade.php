{{--
    The TRUMO logo with the "Certified Pre-owned" tagline (SVG, sharp at any
    size). $variant 'dark' (default) is navy, for light backgrounds; 'light'
    is white, for navy backgrounds. $height in px, default 46: much smaller
    and the tagline stops being readable.
--}}
@php
    $variant = $variant ?? 'dark';
    $height = $height ?? 46;
    $file = $variant === 'light' ? 'trumo-tagline-white.svg' : 'trumo-tagline.svg';
    $version = @filemtime(public_path('images/'.$file)) ?: 1;
@endphp
<img src="{{ asset('images/'.$file) }}?v={{ $version }}" alt="TRUMO Certified Pre-owned" class="site-logo" height="{{ $height }}" width="{{ round($height * 3240 / 1266) }}" style="height:{{ $height }}px; width:auto; display:block;">
