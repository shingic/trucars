<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Reserve your car · TruCars' }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Geist:wght@300;400;500;600;700;800&family=Geist+Mono:wght@400;500;600&display=swap" rel="stylesheet">

    @livewireStyles

    <style>
        /* ===== Citrus storefront palette ===== */
        :root {
            --bg:#FFFFFF;
            --bg-2:#F6F7F7;
            --card:#FFFFFF;
            --ink:#16181D;
            --ink-2:#585E66;
            --ink-3:#969CA3;
            --line:rgba(22,24,29,.10);
            --line-strong:rgba(22,24,29,.17);
            --primary:#F5631F;
            --primary-press:#E2520F;
            --primary-soft:rgba(245,99,31,.10);
            --primary-line:rgba(245,99,31,.32);
            --coral:#FF8A3D;
            --coral-soft:rgba(255,138,61,.14);
            --good:#12B886;
            --good-soft:rgba(18,184,134,.12);
            --bad:#D65454;
            --bad-soft:rgba(214,84,84,.10);
            --shadow-sm:0 1px 3px rgba(22,24,29,.06);
            --shadow-md:0 14px 40px rgba(22,24,29,.09), 0 3px 10px rgba(22,24,29,.05);
            --shadow-primary:0 12px 28px rgba(245,99,31,.30);
            --hero-grad:linear-gradient(155deg,#FF8A3D,#F5631F 65%,#EC4E0C);
            --radius:24px; --radius-sm:16px; --radius-pill:999px;
            --font-ui:"Geist",-apple-system,BlinkMacSystemFont,sans-serif;
            --font-mono:"Geist Mono",ui-monospace,monospace;
        }

        * { box-sizing:border-box; }
        body { margin:0; font-family:var(--font-ui); color:var(--ink); background:var(--bg); -webkit-font-smoothing:antialiased; line-height:1.45; padding-bottom:96px; }
        button { font-family:inherit; cursor:pointer; border:none; background:none; color:inherit; }
        input, select, textarea { font-family:inherit; }
        a { color:inherit; }
        ::selection { background:var(--primary); color:#fff; }
        .mono { font-family:var(--font-mono); }
        .muted { color:var(--ink-2); }

        /* ===== top: brand + vehicle chip + named stepper ===== */
        .top { position:sticky; top:0; z-index:40; background:rgba(255,255,255,.9); backdrop-filter:saturate(160%) blur(14px); border-bottom:1px solid var(--line); }
        .top-inner { max-width:1080px; margin:0 auto; display:flex; align-items:center; gap:16px; height:64px; padding:0 26px; }
        .brand { display:flex; align-items:center; gap:10px; font-weight:800; font-size:18px; letter-spacing:-.02em; text-decoration:none; }
        .brand .glyph { width:28px; height:28px; border-radius:9px; background:var(--primary); color:#fff; display:grid; place-items:center; font-weight:800; font-size:15px; box-shadow:var(--shadow-primary); }
        .veh-chip { margin-left:auto; display:flex; align-items:center; gap:11px; background:var(--card); border:1px solid var(--line); border-radius:var(--radius-pill); padding:6px 16px 6px 8px; box-shadow:var(--shadow-sm); }
        .veh-chip .thumb { width:38px; height:26px; border-radius:7px; background:linear-gradient(135deg,#FFE2CE,#FFD0B3); background-size:cover; background-position:center; display:grid; place-items:center; overflow:hidden; }
        .veh-chip .vt { font-size:13px; font-weight:600; }
        .veh-chip .vp { font-size:13px; color:var(--ink-3); }

        .stepper { display:flex; max-width:1040px; margin:0 auto; padding:12px 26px 14px; }
        .st-step { flex:1 1 0; min-width:0; display:flex; flex-direction:column; align-items:center; gap:8px; position:relative; background:none; padding:0; }
        .st-step::before { content:""; position:absolute; top:13px; left:-50%; width:100%; height:2px; background:var(--line-strong); z-index:0; }
        .st-step:first-child::before { display:none; }
        .st-step.done::before, .st-step.current::before { background:var(--primary); }
        .st-node { width:28px; height:28px; border-radius:50%; display:grid; place-items:center; font-size:12px; font-weight:700; background:var(--card); border:2px solid var(--line-strong); color:var(--ink-3); z-index:1; transition:all .2s ease; }
        .st-step.done .st-node { background:var(--primary); border-color:var(--primary); color:#fff; }
        .st-step.done { cursor:pointer; }
        .st-step.done:hover .st-label { color:var(--primary); }
        .st-step.current .st-node { border-color:var(--primary); color:var(--primary); box-shadow:0 0 0 4px var(--primary-soft); }
        .st-label { font-size:11.5px; font-weight:600; color:var(--ink-3); white-space:nowrap; max-width:100%; overflow:hidden; text-overflow:ellipsis; }
        .st-step.done .st-label { color:var(--ink-2); }
        .st-step.current .st-label { color:var(--primary); font-weight:700; }

        /* ===== two-column: content + sticky summary rail ===== */
        .layout { max-width:1140px; margin:0 auto; padding:40px 26px; display:grid; grid-template-columns:1fr 360px; gap:34px; align-items:start; }
        .layout-solo { grid-template-columns:1fr; max-width:760px; }
        .content { min-width:0; }
        .step-meta { font-size:13px; font-weight:600; color:var(--primary); letter-spacing:.02em; margin-bottom:10px; }
        .h1 { font-size:36px; font-weight:800; letter-spacing:-.03em; line-height:1.06; margin:0 0 12px; }
        .lede { font-size:16.5px; color:var(--ink-2); margin:0 0 30px; }

        /* summary rail */
        .rail { position:sticky; top:140px; }
        .rail-card { background:var(--card); border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow-md); overflow:hidden; }
        .rail-photo { height:158px; background:var(--hero-grad); display:grid; place-items:center; position:relative; }
        .rail-photo::before { content:""; position:absolute; inset:0; background:radial-gradient(360px 220px at 72% 18%, rgba(255,255,255,.22), transparent 60%); }
        .rail-photo .art { position:relative; z-index:1; filter:drop-shadow(0 16px 18px rgba(0,0,0,.35)); }
        .rail-body { padding:18px 20px 20px; }
        .rail-veh { font-weight:700; font-size:15.5px; letter-spacing:-.01em; }
        .rail-km { font-size:12.5px; color:var(--ink-3); margin-top:2px; }
        .rail-group { margin-top:14px; padding-top:14px; border-top:1px solid var(--line); }
        .rail-line { display:flex; justify-content:space-between; align-items:baseline; font-size:13.5px; padding:4px 0; }
        .rail-line.head { font-weight:700; font-size:14px; }
        .rail-line.sub { color:var(--ink-3); font-size:12.5px; padding-left:2px; }
        .rail-line.credit { color:var(--good); font-weight:600; }
        .rail-total { display:flex; justify-content:space-between; align-items:baseline; margin-top:14px; padding-top:14px; border-top:1.5px solid var(--line-strong); }
        .rail-total .lbl { font-weight:700; font-size:14.5px; }
        .rail-total .amt { font-family:var(--font-mono); font-weight:700; font-size:23px; letter-spacing:-.02em; }
        .rail-total .amt .per { font-size:12.5px; color:var(--ink-3); font-weight:500; }
        .rail-due { display:flex; align-items:center; gap:8px; margin-top:14px; padding:11px 13px; background:var(--primary-soft); border-radius:12px; font-size:12.5px; color:var(--ink-2); }
        .rail-due b { color:var(--ink); }
        .rail-due svg { color:var(--primary); flex-shrink:0; }
        .rail-cash { font-size:12px; color:var(--ink-3); margin-top:10px; text-align:center; line-height:1.4; }

        /* ===== buttons ===== */
        .btn { display:inline-flex; align-items:center; justify-content:center; gap:9px; font-size:15px; font-weight:700; padding:15px 30px; border-radius:var(--radius-pill); text-decoration:none; transition:transform .1s ease, box-shadow .18s ease, background .18s ease, border-color .18s ease, color .18s ease; }
        .btn:active { transform:translateY(1px); }
        .btn-primary { background:var(--primary); color:#fff; box-shadow:var(--shadow-primary); }
        .btn-primary:hover { background:var(--primary-press); }
        .btn-soft { background:var(--primary-soft); color:var(--primary); }
        .btn-soft:hover { background:rgba(245,99,31,.16); }
        .btn-ghost { background:var(--card); color:var(--ink); border:1.5px solid var(--line-strong); }
        .btn-ghost:hover { border-color:var(--primary); color:var(--primary); }
        .btn-block { width:100%; }
        .btn-lg { padding:17px 34px; font-size:16px; }
        .btn[disabled] { opacity:.45; pointer-events:none; }
        .text-link { color:var(--primary); font-weight:600; text-decoration:underline; text-underline-offset:3px; cursor:pointer; background:none; }
        .text-link.subtle { color:var(--ink-2); }

        /* ===== choice cards ===== */
        .choice { display:flex; align-items:center; gap:16px; width:100%; text-align:left; padding:22px; border:1.5px solid var(--line-strong); border-radius:var(--radius); margin-bottom:14px; cursor:pointer; transition:border-color .15s ease, background .15s ease, transform .12s ease; background:var(--card); box-shadow:var(--shadow-sm); }
        .choice:hover { border-color:var(--primary); transform:translateY(-2px); }
        .choice.is-on { border-color:var(--primary); border-width:2px; background:var(--primary-soft); }
        .radio { width:24px; height:24px; border-radius:50%; border:2px solid var(--line-strong); display:grid; place-items:center; flex-shrink:0; }
        .choice.is-on .radio { border-color:var(--primary); }
        .choice.is-on .radio::after { content:""; width:12px; height:12px; border-radius:50%; background:var(--primary); }
        .choice-title { font-weight:600; font-size:16px; }
        .choice-sub { font-size:13.5px; color:var(--ink-3); margin-top:2px; }

        /* ===== fields ===== */
        .card { background:var(--card); border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow-sm); }
        .field { margin-bottom:20px; }
        .field-label { display:block; font-size:13.5px; font-weight:600; color:var(--ink-2); margin-bottom:8px; }
        .field-input, .field-select { width:100%; padding:14px 16px; border:1.5px solid var(--line-strong); border-radius:var(--radius-sm); font-size:15px; outline:none; background:var(--card); transition:border-color .15s ease, box-shadow .15s ease; }
        .field-input:focus, .field-select:focus { border-color:var(--primary); box-shadow:0 0 0 4px var(--primary-soft); }
        textarea.field-input { resize:vertical; }
        .field-row { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
        .seg { display:inline-flex; padding:4px; background:var(--bg-2); border:1px solid var(--line); border-radius:var(--radius-pill); gap:4px; }
        .seg.seg-wrap { display:flex; flex-wrap:wrap; }
        .seg-btn { padding:9px 22px; border-radius:var(--radius-pill); font-size:14px; font-weight:600; color:var(--ink-2); transition:all .18s ease; }
        .seg-btn.is-active { background:var(--card); color:var(--primary); box-shadow:var(--shadow-sm); }
        .section-label { font-size:18px; font-weight:700; margin:30px 0 6px; }
        .section-note { font-size:13.5px; color:var(--ink-2); margin:0 0 18px; }

        /* ===== sticky bottom pay-bar ===== */
        .paybar { position:fixed; left:0; right:0; bottom:0; z-index:45; background:rgba(255,255,255,.9); backdrop-filter:saturate(150%) blur(16px); border-top:1px solid var(--line); box-shadow:0 -10px 30px rgba(27,27,46,.06); }
        .paybar-inner { max-width:1080px; margin:0 auto; display:flex; align-items:center; gap:18px; padding:14px 26px; }
        .paybar-back { display:flex; align-items:center; gap:8px; font-size:14px; font-weight:600; color:var(--ink-2); padding:10px 6px; }
        .paybar-back:hover { color:var(--primary); }
        .paybar-price { display:none; flex-direction:column; }
        .paybar-price .lbl { font-size:11px; letter-spacing:.08em; text-transform:uppercase; color:var(--ink-3); }
        .paybar-price .amt { font-size:20px; font-weight:800; letter-spacing:-.02em; }
        .paybar-price .amt .per { font-size:12.5px; color:var(--ink-3); font-weight:500; }
        .paybar-cta { margin-left:auto; }

        @media (max-width:980px){
            .layout { grid-template-columns:1fr; }
            .rail { position:static; margin-top:8px; }
            .paybar-price { display:flex; }
            .h1 { font-size:30px; }
        }
        @media (max-width:560px){
            .field-row { grid-template-columns:1fr; }
            .paybar-cta .btn { padding:14px 22px; }
        }
    </style>

    @stack('styles')
</head>
<body>
{{ $slot }}

@livewireScripts
</body>
</html>
