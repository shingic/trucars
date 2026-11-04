{{--
    The trumoto logo. $variant: 'dark' (navy text, for light backgrounds — default)
    or 'light' (white text, for ink backgrounds). $height in px, default 28.
--}}
@php
    $variant = $variant ?? 'dark';
    $height = $height ?? 28;
    $file = $variant === 'light' ? 'trumoto-logo-light.png' : 'trumoto-logo.png';
    // The file's own timestamp busts browser caches whenever the logo is replaced.
    $version = @filemtime(public_path('images/'.$file)) ?: 1;
@endphp
<img src="{{ asset('images/'.$file) }}?v={{ $version }}" alt="trumoto" class="site-logo" height="{{ $height }}" width="{{ round($height * 1186 / 173) }}" style="height:{{ $height }}px; width:auto; display:block;">
