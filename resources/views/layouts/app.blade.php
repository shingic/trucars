<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>TruCars — Certified used cars</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Geist:wght@300;400;500;600;700;800&family=Geist+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    @livewireStyles
    <style>
        :root {
            --bg:#FFFFFF; --bg-2:#F6F7F7; --card:#FFFFFF;
            --ink:#16181D; --ink-2:#585E66; --ink-3:#969CA3;
            --line:rgba(22,24,29,.10); --line-strong:rgba(22,24,29,.17);
            --primary:#F5631F; --primary-press:#E2520F; --primary-soft:rgba(245,99,31,.10); --primary-line:rgba(245,99,31,.32);
            --coral:#FF8A3D; --good:#12B886; --good-soft:rgba(18,184,134,.12);
            --shadow-sm:0 1px 3px rgba(22,24,29,.06);
            --shadow-md:0 14px 40px rgba(22,24,29,.09), 0 3px 10px rgba(22,24,29,.05);
            --shadow-primary:0 12px 28px rgba(245,99,31,.30);
            --hero-grad:linear-gradient(155deg,#FF8A3D,#F5631F 65%,#EC4E0C);
            --radius:24px; --radius-sm:16px; --radius-pill:999px;
            --font-ui:"Geist",-apple-system,BlinkMacSystemFont,sans-serif;
            --font-mono:"Geist Mono",ui-monospace,monospace;
        }
        * { box-sizing:border-box; }
        body { margin:0; font-family:var(--font-ui); color:var(--ink); background:var(--bg); -webkit-font-smoothing:antialiased; line-height:1.45; }
        button { font-family:inherit; cursor:pointer; border:none; background:none; }
        input { font-family:inherit; }
        a { color:inherit; }

        .nav { position:sticky; top:0; z-index:40; background:rgba(255,255,255,.9); backdrop-filter:saturate(160%) blur(14px); border-bottom:1px solid var(--line); }
        .nav-inner { max-width:1600px; margin:0 auto; padding:14px 24px; display:flex; align-items:center; gap:28px; }
        .brand { display:flex; align-items:center; gap:9px; font-weight:800; font-size:17px; letter-spacing:-.02em; text-decoration:none; }
        .brand .glyph { width:28px; height:28px; border-radius:9px; background:var(--primary); color:#fff; display:grid; place-items:center; font-weight:800; font-size:15px; box-shadow:var(--shadow-primary); }
        .nav-links { display:flex; align-items:center; gap:22px; }
        .nav-links a { font-size:14.5px; font-weight:600; color:var(--ink-2); text-decoration:none; }
        .nav-links a.active, .nav-links a:hover { color:var(--ink); }
        .nav-right { margin-left:auto; display:flex; align-items:center; gap:16px; }
        .nav-loc { display:flex; align-items:center; gap:7px; font-size:13.5px; color:var(--ink-2); font-weight:500; }
        .nav-loc svg { color:var(--primary); }
        .nav-acct { width:36px; height:36px; border-radius:50%; background:var(--bg-2); border:1px solid var(--line); display:grid; place-items:center; font-weight:700; font-size:13px; color:var(--ink-2); }

        .page { max-width:1600px; margin:0 auto; padding:32px 24px 64px; }

        .site-foot { border-top:1px solid var(--line); background:var(--bg-2); }
        .site-foot-inner { max-width:1600px; margin:0 auto; padding:28px 24px; display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap; }
        .site-foot .brand { font-size:15px; }
        .site-foot-links { display:flex; gap:20px; flex-wrap:wrap; }
        .site-foot-links a { font-size:13px; color:var(--ink-2); text-decoration:none; }
        .site-foot-links a:hover { color:var(--ink); }
        .site-foot-legal { font-size:12px; color:var(--ink-3); width:100%; }
    </style>
</head>
<body>
<nav class="nav">
    <div class="nav-inner">
        <a href="/" class="brand"><span class="glyph">T</span> TruCars</a>
        <div class="nav-links">
            <a href="/" class="active">Shop</a>
            <a href="#">Sell / Trade</a>
            <a href="#">Financing</a>
            <a href="#">How it works</a>
        </div>
        <div class="nav-right">
                <span class="nav-loc">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                    Thornhill, ON
                </span>
            <span class="nav-acct">SC</span>
        </div>
    </div>
</nav>

<main class="page">
    {{ $slot }}
</main>

<footer class="site-foot">
    <div class="site-foot-inner">
        <span class="brand"><span class="glyph">T</span> TruCars</span>
        <nav class="site-foot-links">
            <a href="#">Shop</a>
            <a href="#">Sell / Trade</a>
            <a href="#">Financing</a>
            <a href="#">How it works</a>
            <a href="#">Contact</a>
        </nav>
        <span class="site-foot-legal">© {{ date('Y') }} TruCars — every vehicle certified, every price all-in per OMVIC. Ontario.</span>
    </div>
</footer>

@livewireScripts
</body>
</html>
