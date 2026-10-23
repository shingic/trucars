<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Sign in' }} · TruCars</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400&family=Barlow+Semi+Condensed:wght@500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --bg:#F9F9F9; --bg-2:#F9F9F9; --card:#FFFFFF;
            --ink:#071437; --ink-2:#4B5675; --ink-3:#78829D;
            --line:rgba(7,20,55,.10); --line-strong:rgba(7,20,55,.17);
            --primary:#1B84FF; --primary-press:#056EE9; --primary-bright:#6CB0FF; --primary-soft:rgba(27,132,255,.10); --primary-line:rgba(27,132,255,.32);
            --coral:#4DA0FF; --coral-soft:rgba(77,160,255,.16);
            --good:#17C653; --good-soft:rgba(23,198,83,.12); --good-ink:#0E5A43;
            --amber:#C8841A; --amber-soft:rgba(216,150,40,.16);
            --shadow-sm:0 1px 3px rgba(7,20,55,.06);
            --shadow-md:0 8px 24px rgba(7,20,55,.10);
            --shadow-lg:0 30px 70px rgba(7,20,55,.18);
            --radius:4px; --radius-sm:3px; --radius-pill:3px;
            --font:"Barlow",-apple-system,sans-serif; --font-display:"Barlow Semi Condensed","Barlow",sans-serif; --mono:ui-monospace,"SF Mono",Menlo,Consolas,monospace;
        }

        * { box-sizing:border-box; }
        html, body { height:100%; }
        body { margin:0; font-family:var(--font); color:var(--ink); background:var(--bg); -webkit-font-smoothing:antialiased; line-height:1.5; }
        h1, h2, h3, h4 { font-family:var(--font-display); }
        button { font-family:inherit; cursor:pointer; border:none; background:none; }
        input { font-family:inherit; }
        a { color:inherit; }
        /* the TruCars wordmark: condensed caps, "Cars" in blue */
        .wordmark { font-family:var(--font-display); font-size:24px; font-weight:800; line-height:1; text-transform:uppercase; letter-spacing:.01em; color:var(--ink); white-space:nowrap; }
        .wordmark b { font-weight:800; color:var(--primary); }
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
