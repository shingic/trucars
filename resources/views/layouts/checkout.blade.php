<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Reserve your car · TruCars' }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400&family=Barlow+Semi+Condensed:wght@500;600;700;800&display=swap" rel="stylesheet">

    @livewireStyles

    <style>
        /* ===== Citrus storefront palette ===== */
        :root {
            --bg:#FFFFFF;
            --bg-2:#F9F9F9;
            --card:#FFFFFF;
            --ink:#071437;
            --ink-2:#4B5675;
            --ink-3:#78829D;
            --line:rgba(7,20,55,.10);
            --line-strong:rgba(7,20,55,.17);
            --primary:#1B84FF;
            --primary-press:#056EE9; --primary-bright:#6CB0FF;
            --primary-soft:rgba(27,132,255,.10);
            --primary-line:rgba(27,132,255,.32);
            --coral:#4DA0FF;
            --coral-soft:rgba(77,160,255,.14);
            --good:#17C653;
            --good-soft:rgba(23,198,83,.12);
            --bad:#D65454;
            --bad-soft:rgba(214,84,84,.10);
            --shadow-sm:0 1px 3px rgba(7,20,55,.06);
            --shadow-md:0 8px 24px rgba(7,20,55,.10);
            --shadow-primary:none;
            --hero-grad:#DDE3EE;
            --radius:4px; --radius-sm:3px; --radius-pill:3px;
            --font-ui:"Barlow",-apple-system,BlinkMacSystemFont,sans-serif; --font-display:"Barlow Semi Condensed","Barlow",sans-serif;
            --font-mono:ui-monospace,"SF Mono",Menlo,Consolas,monospace;
        }

        * { box-sizing:border-box; }
        body { margin:0; font-family:var(--font-ui); color:var(--ink); background:var(--bg); -webkit-font-smoothing:antialiased; line-height:1.45; padding-bottom:96px; }
        h1, h2, h3, h4 { font-family:var(--font-display); }
        button { font-family:inherit; cursor:pointer; border:none; background:none; color:inherit; }
        input, select, textarea { font-family:inherit; }
        a { color:inherit; }
        ::selection { background:var(--primary); color:#fff; }
        .mono { font-family:var(--font-mono); }
        .muted { color:var(--ink-2); }

        /* ===== top: brand + vehicle chip + named stepper ===== */
        .top { position:sticky; top:0; z-index:40; background:#fff; border-bottom:1px solid var(--line-strong); }
        .top-inner { max-width:1220px; margin:0 auto; display:flex; align-items:center; gap:16px; height:64px; padding:0 26px; }
        .brand { display:flex; align-items:center; gap:10px; font-weight:800; font-size:18px; letter-spacing:0; text-decoration:none; }
        .brand .glyph { width:28px; height:28px; border-radius:3px; background:var(--primary); color:#fff; display:grid; place-items:center; font-weight:800; font-size:15px; }
        .veh-chip { margin-left:auto; display:flex; align-items:center; gap:11px; background:#fff; border:1px solid var(--line-strong); border-radius:3px; padding:5px 14px 5px 5px; }
        .veh-chip .thumb { width:44px; height:30px; border-radius:2px; background:var(--bg-2); background-size:cover; background-position:center 40%; display:grid; place-items:center; overflow:hidden; }
        .veh-chip .vt { font-size:14px; font-weight:600; }
        .veh-chip .vp { font-family:var(--font-display); font-size:16px; font-weight:700; color:var(--ink); }

        /* the stepper: a flat ruled strip, one cell per step, the bar on top says where you are */
        .stepper { display:flex; gap:6px; max-width:1180px; margin:0 auto; padding:10px 26px 12px; }
        .st-step { flex:1 1 0; min-width:0; display:flex; align-items:baseline; gap:7px; background:none; padding:8px 0 0; border-top:3px solid var(--line-strong); text-align:left; }
        .st-step.done { border-top-color:var(--ink); cursor:pointer; }
        .st-step.current { border-top-color:var(--primary); }
        .st-node { flex:none; display:inline-flex; align-items:center; font-family:var(--font-display); font-size:15px; font-weight:700; line-height:1; color:var(--ink-3); }
        .st-step.done .st-node { color:var(--ink); }
        .st-step.current .st-node { color:var(--primary-press); }
        .st-label { font-family:var(--font-display); font-size:14px; font-weight:600; text-transform:uppercase; line-height:1.1; color:var(--ink-3); white-space:nowrap; max-width:100%; overflow:hidden; text-overflow:ellipsis; }
        .st-step.done .st-label { color:var(--ink-2); }
        .st-step.done:hover .st-label { color:var(--ink); text-decoration:underline; text-underline-offset:3px; }
        .st-step.current .st-label { color:var(--ink); font-weight:700; }
        .st-step:focus-visible { outline:2px solid var(--primary); outline-offset:2px; }

        /* compact mobile progress — swaps in for the named rail below 900px */
        .stepper-mini { display:none; max-width:1180px; margin:0 auto; padding:10px 26px 12px; }
        .smini-track { height:3px; background:var(--line-strong); overflow:hidden; }
        .smini-fill { height:100%; background:var(--primary); transition:width .3s ease; }
        .smini-meta { display:flex; align-items:baseline; gap:7px; margin-top:8px; font-size:14px; }
        .smini-count { color:var(--ink-3); font-weight:600; }
        .smini-dot { color:var(--ink-3); }
        .smini-step { font-family:var(--font-display); font-size:16px; text-transform:uppercase; color:var(--ink); font-weight:700; }
        @media (max-width:900px){
            .stepper { display:none; }
            .stepper-mini { display:block; }
            .veh-chip .vt { display:none; }
        }

        /* ===== two-column: content + sticky summary rail ===== */
        .layout { max-width:1280px; margin:0 auto; padding:40px 26px; display:grid; grid-template-columns:1fr 360px; gap:40px; align-items:start; }
        .layout-solo { grid-template-columns:1fr; max-width:760px; }
        .content { min-width:0; }
        .step-meta { font-family:var(--font-display); font-size:16px; font-weight:600; color:var(--ink-2); margin-bottom:8px; }
        .h1 { font-family:var(--font-display); font-size:42px; font-weight:700; text-transform:uppercase; letter-spacing:0; line-height:1; margin:0 0 12px; }
        .lede { font-size:16.5px; color:var(--ink-2); margin:0 0 30px; line-height:1.55; }

        /* summary rail: the window sticker — heavy frame, the figure, then ruled lines */
        .rail { position:sticky; top:140px; }
        .rail-card { background:#fff; border:3px solid var(--ink); }
        .rail-photo { aspect-ratio:4/2.8; overflow:hidden; background:var(--hero-grad); display:grid; place-items:center; position:relative; border-bottom:3px solid var(--ink); }
        .rail-photo img { position:absolute; top:0; left:0; display:block; width:100%; height:107%; object-fit:cover; object-position:center 40%; }
        .rail-photo .art { position:relative; z-index:1; }
        .rail-body { padding:16px 20px 20px; }
        .rail-veh { font-family:var(--font-display); font-weight:700; font-size:22px; line-height:1.05; text-transform:uppercase; }
        .rail-km { font-size:14px; color:var(--ink-2); margin-top:4px; }
        .rail-group { margin-top:12px; padding-top:10px; border-top:1px dashed var(--line-strong); }
        .rail-line { display:flex; justify-content:space-between; align-items:baseline; gap:12px; font-size:14px; padding:4px 0; }
        .rail-line.head { font-weight:700; font-size:14.5px; }
        .rail-line.sub { color:var(--ink-2); font-size:13.5px; }
        .rail-line.credit { color:#0B7355; font-weight:600; }
        .rail-total { display:flex; justify-content:space-between; align-items:baseline; margin-top:12px; padding-top:12px; border-top:2px solid var(--ink); }
        .rail-total .lbl { font-family:var(--font-display); font-weight:700; font-size:17px; text-transform:uppercase; }
        .rail-total .amt { font-family:var(--font-display); font-weight:700; font-size:28px; line-height:1; letter-spacing:0; }
        .rail-total .amt .per { font-family:var(--font-ui); font-size:13.5px; color:var(--ink-3); font-weight:500; }
        .rail-due { display:flex; align-items:center; gap:8px; margin-top:14px; padding:11px 0; border-top:1px dashed var(--line-strong); border-bottom:1px dashed var(--line-strong); font-size:14px; color:var(--ink-2); }
        .rail-due b { color:var(--ink); }
        .rail-due svg { color:var(--ink); flex-shrink:0; }
        .rail-cash { font-size:13px; color:var(--ink-3); margin-top:10px; line-height:1.45; }

        /* ===== buttons ===== */
        .btn { display:inline-flex; align-items:center; justify-content:center; gap:9px; font-size:15px; font-weight:700; padding:14px 26px; border-radius:3px; text-decoration:none; transition:background .15s ease, border-color .15s ease, color .15s ease; }
        .btn-primary { background:var(--primary); color:#fff; font-family:var(--font-display); font-size:18px; text-transform:uppercase; }
        .btn-primary:hover { background:var(--primary-press); }
        .btn-soft { background:#fff; color:var(--primary-press); border:1.5px solid var(--primary-press); }
        .btn-soft:hover { background:var(--primary-press); color:#fff; }
        .btn-ghost { background:#fff; color:var(--ink); border:1.5px solid var(--ink); }
        .btn-ghost:hover { background:var(--ink); color:#fff; }
        .btn:focus-visible { outline:2px solid var(--ink); outline-offset:2px; }
        .btn-block { width:100%; }
        .btn-lg { padding:15px 30px; font-size:16px; }
        .btn-primary.btn-lg { font-size:20px; }
        .btn[disabled] { opacity:.45; pointer-events:none; }
        .text-link { color:var(--primary-press); font-weight:600; text-decoration:underline; text-underline-offset:3px; cursor:pointer; background:none; }
        .text-link:hover { color:var(--ink); }
        .text-link.subtle { color:var(--ink-2); }

        /* ===== choice cards ===== */
        .choice { display:flex; align-items:center; gap:16px; width:100%; text-align:left; padding:20px 22px; border:1.5px solid var(--line-strong); border-radius:4px; margin-bottom:12px; cursor:pointer; transition:border-color .15s ease, box-shadow .15s ease; background:#fff; }
        .choice:hover { border-color:var(--ink); }
        .choice.is-on { border-color:var(--primary); box-shadow:inset 0 0 0 1.5px var(--primary); }
        .choice:focus-visible { outline:2px solid var(--ink); outline-offset:2px; }
        .radio { width:22px; height:22px; border-radius:3px; border:2px solid var(--line-strong); display:grid; place-items:center; flex-shrink:0; background:#fff; }
        .choice.is-on .radio { border-color:var(--primary); background:var(--primary); }
        .choice.is-on .radio::after { content:""; width:8px; height:8px; border-radius:1px; background:#fff; }
        .choice-title { font-weight:700; font-size:16px; }
        .choice-sub { font-size:14px; color:var(--ink-2); margin-top:2px; }
        .tag-free { display:inline-block; vertical-align:2px; margin-left:6px; padding:2px 6px; border-radius:2px; background:rgba(23,198,83,.14); color:#0B7355; font-family:var(--font-display); font-size:13px; font-weight:700; line-height:1.2; }

        /* ===== fields ===== */
        .card { background:#fff; border:1px solid var(--line-strong); border-radius:4px; }
        .field { margin-bottom:20px; }
        .field-label { display:block; font-size:14px; font-weight:600; color:var(--ink-2); margin-bottom:7px; }
        .field-input, .field-select { width:100%; padding:13px 15px; border:1.5px solid var(--line-strong); border-radius:3px; font-size:15px; outline:none; background:#fff; color:var(--ink); transition:border-color .15s ease, box-shadow .15s ease; }
        .field-input:focus, .field-select:focus { border-color:var(--ink); box-shadow:0 0 0 1px var(--ink); }
        textarea.field-input { resize:vertical; }
        .field-row { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
        /* segmented control: square, ink-outlined, the active segment filled navy */
        .seg { display:inline-flex; padding:0; gap:0; background:none; border:none; }
        .seg.seg-wrap { display:flex; flex-wrap:wrap; row-gap:6px; }
        .seg-btn { padding:10px 20px; margin-left:-1.5px; border:1.5px solid var(--ink); border-radius:0; background:#fff; font-size:14.5px; font-weight:600; color:var(--ink); transition:background .15s ease, color .15s ease; }
        .seg-btn:first-child { margin-left:0; border-radius:3px 0 0 3px; }
        .seg-btn:last-child { border-radius:0 3px 3px 0; }
        .seg-btn:hover { background:var(--bg-2); }
        .seg-btn.is-active { background:var(--ink); color:#fff; }
        .seg-btn:focus-visible { outline:2px solid var(--primary); outline-offset:2px; position:relative; z-index:1; }
        .section-label { font-family:var(--font-display); font-size:23px; font-weight:700; text-transform:uppercase; line-height:1.05; margin:34px 0 6px; padding-top:12px; border-top:2px solid var(--ink); }
        .section-note { font-size:14px; color:var(--ink-2); margin:0 0 18px; line-height:1.55; }

        /* ===== sticky bottom pay-bar ===== */
        .paybar { position:fixed; left:0; right:0; bottom:0; z-index:45; background:#fff; border-top:3px solid var(--ink); }
        .paybar-inner { max-width:1220px; margin:0 auto; display:flex; align-items:center; gap:18px; padding:12px 26px; }
        .paybar-back { display:flex; align-items:center; gap:8px; font-size:15px; font-weight:600; color:var(--ink-2); padding:10px 6px; }
        .paybar-back:hover { color:var(--ink); text-decoration:underline; text-underline-offset:3px; }
        .paybar-price { display:none; flex-direction:column; }
        .paybar-price .lbl { font-family:var(--font-display); font-size:14px; font-weight:600; text-transform:uppercase; color:var(--ink-2); }
        .paybar-price .amt { font-family:var(--font-display); font-size:24px; font-weight:700; line-height:1; letter-spacing:0; }
        .paybar-price .amt .per { font-family:var(--font-ui); font-size:13px; color:var(--ink-3); font-weight:500; }
        .paybar-cta { margin-left:auto; }

        @media (max-width:980px){
            .layout { grid-template-columns:1fr; }
            .rail { position:static; margin-top:8px; }
            .paybar-price { display:flex; }
            .h1 { font-size:34px; }
        }
        @media (max-width:560px){
            .top-inner, .stepper-mini, .paybar-inner { padding-left:16px; padding-right:16px; }
            .layout { padding:28px 16px; }
            .field-row { grid-template-columns:1fr; }
            .paybar-cta .btn { padding:13px 18px; }
            .btn-primary.btn-lg { font-size:18px; }
            .h1 { font-size:30px; }
        }
        /* the TruCars wordmark: condensed caps, "Cars" in blue */
        .wordmark { font-family:var(--font-display); font-size:24px; font-weight:800; line-height:1; text-transform:uppercase; letter-spacing:.01em; color:var(--ink); white-space:nowrap; }
        .wordmark b { font-weight:800; color:var(--primary); }
        /* form controls: one flat, square look everywhere instead of each browser's own.
           :where() keeps these at zero specificity, so any page's own field styles still win. */
        :where(input:not([type=checkbox]):not([type=radio]):not([type=range]):not([type=file]):not([type=hidden]):not([type=submit]):not([type=button]), select, textarea) {
            font:inherit; color:var(--ink); background-color:#fff;
            border:1.5px solid var(--line-strong); border-radius:3px;
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

    @stack('styles')
</head>
<body>
{{ $slot }}

@livewireScripts
</body>
</html>
