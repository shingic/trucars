{{--
    The TRUMO logo. $variant 'dark' (the default, for light backgrounds) is
    the navy TRU/MO badge; 'light' (for navy backgrounds, where the badge
    would vanish) is the white wordmark. $height in px: badge 40, wordmark 28.
--}}
@php
    $variant = $variant ?? 'dark';
    $isBadge = $variant !== 'light';
    $height = $height ?? ($isBadge ? 40 : 28);
    $file = $isBadge ? 'trumo-badge-192.png' : 'trumo-logo-white.svg';
    $width = $isBadge ? $height : round($height * 3240 / 799);
    $version = @filemtime(public_path('images/'.$file)) ?: 1;
@endphp
<img src="{{ asset('images/'.$file) }}?v={{ $version }}" alt="TRUMO" class="site-logo" height="{{ $height }}" width="{{ $width }}" style="height:{{ $height }}px; width:{{ $isBadge ? $height.'px' : 'auto' }}; display:block;">
