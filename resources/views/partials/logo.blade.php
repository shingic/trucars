{{--
    The TRUMO logo (SVG, sharp at any size). $variant: 'dark' (navy text, for light backgrounds — default)
    or 'light' (white text, for ink backgrounds). $height in px, default 28.
--}}
@php
    $variant = $variant ?? 'dark';
    $height = $height ?? 28;
    $file = $variant === 'light' ? 'trumo-logo-white.svg' : 'trumo-logo.svg';
    // The file's own timestamp busts browser caches whenever the logo is replaced.
    $version = @filemtime(public_path('images/'.$file)) ?: 1;
@endphp
<img src="{{ asset('images/'.$file) }}?v={{ $version }}" alt="TRUMO" class="site-logo" height="{{ $height }}" width="{{ round($height * 3240 / 799) }}" style="height:{{ $height }}px; width:auto; display:block;">
