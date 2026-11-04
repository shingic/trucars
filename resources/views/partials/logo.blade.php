{{--
    The Motokari logo. $variant: 'dark' (navy text, for light backgrounds — default)
    or 'light' (white text, for ink backgrounds). $height in px, default 28.
--}}
@php
    $variant = $variant ?? 'dark';
    $height = $height ?? 28;
    $file = $variant === 'light' ? 'motokari-logo-light.png' : 'motokari-logo.png';
    // The file's own timestamp busts browser caches whenever the logo is replaced.
    $version = @filemtime(public_path('images/'.$file)) ?: 1;
@endphp
<img src="{{ asset('images/'.$file) }}?v={{ $version }}" alt="Motokari" class="site-logo" height="{{ $height }}" width="{{ round($height * 900 / 183) }}" style="height:{{ $height }}px; width:auto; display:block;">
