<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Sign in' }} · TruCars</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Geist:wght@300;400;500;600;700;800&family=Geist+Mono:wght@400;500;600&display=swap" rel="stylesheet">

    <style>
        :root {
            --bg:#F6F7F7; --card:#FFFFFF;
            --ink:#16181D; --ink-2:#585E66; --ink-3:#969CA3;
            --line:rgba(22,24,29,.10); --line-strong:rgba(22,24,29,.17);
            --primary:#F5631F; --primary-press:#E2520F; --primary-soft:rgba(245,99,31,.10); --primary-line:rgba(245,99,31,.32);
            --coral:#FF8A3D; --coral-soft:rgba(255,138,61,.16);
            --good:#12B886; --good-soft:rgba(18,184,134,.12); --good-ink:#0E5A43;
            --amber:#C8841A; --amber-soft:rgba(216,150,40,.16);
            --shadow-sm:0 1px 3px rgba(22,24,29,.06);
            --shadow-md:0 14px 40px rgba(22,24,29,.09), 0 3px 10px rgba(22,24,29,.05);
            --shadow-lg:0 30px 70px rgba(22,24,29,.18);
            --radius:18px; --radius-sm:13px; --radius-pill:999px;
            --font:"Geist",-apple-system,sans-serif; --mono:"Geist Mono",monospace;
        }

        * { box-sizing:border-box; }
        html, body { height:100%; }
        body { margin:0; font-family:var(--font); color:var(--ink); background:var(--bg); -webkit-font-smoothing:antialiased; line-height:1.5; }
        button { font-family:inherit; cursor:pointer; border:none; background:none; }
        input { font-family:inherit; }
        a { color:inherit; }
    </style>

    @livewireStyles
    @stack('styles')
</head>
<body>
{{ $slot }}

@livewireScripts
@stack('scripts')
</body>
</html>
