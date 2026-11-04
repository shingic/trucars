{{--
    The TRUMO lane logo (SVG, sharp at any size). $variant 'dark' (default)
    is navy with the blue lane, for light backgrounds; 'light' is white with
    the blue lane, for navy backgrounds. $height in px, default 38.
--}}
@php
    $variant = $variant ?? 'dark';
    $height = $height ?? 38;
    $file = $variant === 'light' ? 'trumo-lane-white.svg' : 'trumo-lane.svg';
    $version = @filemtime(public_path('images/'.$file)) ?: 1;
@endphp
<img src="{{ asset('images/'.$file) }}?v={{ $version }}" alt="TRUMO" class="site-logo" height="{{ $height }}" width="{{ round($height * 3240 / 1108) }}" style="height:{{ $height }}px; width:auto; display:block;">
