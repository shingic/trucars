{{-- The TRUMO badge as the app icon for browser tabs and phone home screens. --}}
<link rel="icon" href="{{ asset('images/trumo-badge-32.png') }}?v={{ @filemtime(public_path('images/trumo-badge-32.png')) ?: 1 }}" type="image/png" sizes="32x32">
<link rel="icon" href="{{ asset('images/trumo-badge-192.png') }}?v={{ @filemtime(public_path('images/trumo-badge-192.png')) ?: 1 }}" type="image/png" sizes="192x192">
<link rel="apple-touch-icon" href="{{ asset('images/trumo-badge-180.png') }}?v={{ @filemtime(public_path('images/trumo-badge-180.png')) ?: 1 }}">
<meta name="theme-color" content="#0A1435">
