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
            --radius:12px; --radius-sm:8px; --radius-pill:999px;
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
        /* form controls: one flat, square look everywhere instead of each browser's own.
           :where() keeps these at zero specificity, so any page's own field styles still win. */
        :where(input:not([type=checkbox]):not([type=radio]):not([type=range]):not([type=file]):not([type=hidden]):not([type=submit]):not([type=button]), select, textarea) {
            font:inherit; color:var(--ink); background-color:#fff;
            border:1.5px solid var(--line-strong); border-radius:8px;
            -webkit-appearance:none; appearance:none;
        }
        :where(input:not([type=checkbox]):not([type=radio]):not([type=range]), select, textarea):focus { outline:none; border-color:var(--ink); }
        :where(input, textarea)::placeholder { color:var(--ink-3); opacity:1; }
        :where(input[type=checkbox], input[type=radio]) { accent-color:var(--ink); }
        /* every dropdown gets the same single chevron, even where a page sets its own background */
        select:not([multiple]):not(.bare) {
            -webkit-appearance:none; appearance:none;
            background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%23071437' stroke-width='2.6' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E") !important;
            background-repeat:no-repeat !important; background-position:right 12px center !important; background-size:14px 14px !important;
            padding-right:36px !important;
        }
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
