{{--
    The Motokari logo. $variant: 'dark' (navy text, for light backgrounds — default)
    or 'light' (white text, for ink backgrounds). $height in px, default 28.
--}}
@php
    $variant = $variant ?? 'dark';
    $height = $height ?? 28;
    $file = $variant === 'light' ? 'motokari-logo-light.png' : 'motokari-logo.png';
@endphp
<img src="{{ asset('images/'.$file) }}" alt="Motokari" class="site-logo" height="{{ $height }}" width="{{ round($height * 6) }}" style="height:{{ $height }}px; width:auto; display:block;">
