{{-- The TRUMO app icon for browser tabs and phone home screens. --}}
<link rel="icon" href="{{ asset('images/trumo-icon.svg') }}?v={{ @filemtime(public_path('images/trumo-icon.svg')) ?: 1 }}" type="image/svg+xml">
<link rel="icon" href="{{ asset('images/trumo-icon-32.png') }}?v={{ @filemtime(public_path('images/trumo-icon-32.png')) ?: 1 }}" type="image/png" sizes="32x32">
<link rel="apple-touch-icon" href="{{ asset('images/trumo-icon-180.png') }}?v={{ @filemtime(public_path('images/trumo-icon-180.png')) ?: 1 }}">
<meta name="theme-color" content="#0A1435">
