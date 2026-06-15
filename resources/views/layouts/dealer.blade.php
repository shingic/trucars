<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Reservations' }} · Trueleads</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Geist:wght@300;400;500;600;700;800&family=Geist+Mono:wght@400;500;600&display=swap" rel="stylesheet">

    <style>
        :root {
            --bg:#F6F7F7; --card:#FFFFFF;
            --ink:#16181D; --ink-2:#585E66; --ink-3:#969CA3;
            --line:rgba(22,24,29,.09); --line-strong:rgba(22,24,29,.15);
            --primary:#F5631F; --primary-press:#E2520F; --primary-soft:rgba(245,99,31,.10); --primary-line:rgba(245,99,31,.30);
            --coral:#FF8A3D; --coral-soft:rgba(255,138,61,.16);
            --good:#12B886; --good-soft:rgba(18,184,134,.12); --good-ink:#0E5A43;
            --amber:#C8841A; --amber-soft:rgba(216,150,40,.16);
            --active:#F3F1EF;
            --btn:#16181D; --btn-press:#101114;
            --hero-grad:linear-gradient(155deg,#FF8A3D,#F5631F);
            --shadow-sm:0 1px 3px rgba(22,24,29,.06);
            --shadow-md:0 14px 40px rgba(22,24,29,.09), 0 3px 10px rgba(22,24,29,.05);
            --shadow-lg:0 30px 70px rgba(22,24,29,.18);
            --radius:16px; --radius-sm:12px; --radius-pill:999px;
            --sidebar-w:268px;
            --font:"Geist",-apple-system,sans-serif; --mono:"Geist Mono",monospace;
        }

        * { box-sizing:border-box; }
        body { margin:0; font-family:var(--font); color:var(--ink); background:var(--bg); -webkit-font-smoothing:antialiased; line-height:1.5; }
        button { font-family:inherit; cursor:pointer; border:none; background:none; }
        input { font-family:inherit; }
        a { color:inherit; text-decoration:none; }

        .app { display:flex; min-height:100vh; }

        .sidebar { width:var(--sidebar-w); flex-shrink:0; background:var(--card); border-right:1px solid var(--line); display:flex; flex-direction:column; height:100vh; position:sticky; top:0; }

        .sb-brand { display:flex; align-items:center; gap:11px; padding:18px 20px 16px; }
        .sb-brand .glyph { width:34px; height:34px; border-radius:10px; background:var(--hero-grad); color:#fff; display:grid; place-items:center; font-weight:800; font-size:16px; box-shadow:0 6px 16px rgba(245,99,31,.30); flex-shrink:0; }
        .sb-brand .nm { font-weight:800; font-size:16px; letter-spacing:-.02em; }

        .sb-scroll { flex:1; overflow-y:auto; padding:6px 12px 22px; }
        .sb-group { margin-top:13px; }
        .sb-group:first-child { margin-top:0; }
        .sb-group-label { font-size:11px; font-weight:700; letter-spacing:.05em; text-transform:uppercase; color:var(--ink-3); padding:8px 12px 5px; }
        .nav-item { width:100%; text-align:left; display:flex; align-items:center; gap:11px; font-size:14px; font-weight:600; color:var(--ink-2); padding:9px 12px; border-radius:10px; transition:background .14s ease, color .14s ease; }
        .nav-item svg { width:18px; height:18px; color:var(--ink-3); flex-shrink:0; transition:color .14s ease; }
        .nav-item:hover { background:var(--bg); color:var(--ink); }
        .nav-item.on { background:var(--active); color:var(--ink); }
        .nav-item.on svg { color:var(--primary); }
        .nav-badge { margin-left:auto; font-size:10px; font-weight:800; padding:2px 7px; border-radius:var(--radius-pill); background:var(--primary-soft); color:var(--primary-press); }

        .sb-foot { border-top:1px solid var(--line); padding:13px 14px; display:flex; align-items:center; gap:10px; }
        .sb-foot .av { width:34px; height:34px; border-radius:50%; background:var(--hero-grad); color:#fff; display:grid; place-items:center; font-weight:700; font-size:12px; flex-shrink:0; }
        .sb-foot .nm { font-size:13px; font-weight:700; line-height:1.2; }
        .sb-foot .rl { font-size:11px; color:var(--ink-3); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:150px; }

        .content { flex:1; min-width:0; display:flex; flex-direction:column; }
        .appbar { position:sticky; top:0; z-index:40; background:rgba(246,247,247,.86); backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px); border-bottom:1px solid var(--line); display:flex; align-items:center; gap:16px; height:60px; padding:0 30px; }
        .crumb { font-size:14px; font-weight:600; color:var(--ink-3); display:flex; align-items:center; gap:8px; }
        .crumb .cur { color:var(--ink); font-weight:700; }
        .appbar-right { margin-left:auto; display:flex; align-items:center; gap:12px; }
        .appbar-signout { font-size:13px; font-weight:600; color:var(--ink-2); padding:8px 14px; border-radius:var(--radius-pill); border:1px solid var(--line-strong); transition:all .15s ease; }
        .appbar-signout:hover { border-color:var(--primary); color:var(--primary); }

        .content-body { padding:28px 30px 72px; }

        @media (max-width:880px){
            :root { --sidebar-w:72px; }
            .sb-brand { justify-content:center; padding:18px 0 16px; }
            .sb-brand .nm { display:none; }
            .sb-group-label, .nav-item .nav-label, .sb-foot .meta { display:none; }
            .nav-item { justify-content:center; padding:11px; gap:0; }
            .sb-foot { justify-content:center; }
            .content-body { padding:22px 18px 60px; }
        }
    </style>

    @livewireStyles
    @stack('styles')
</head>
<body>
<div class="app">
    <aside class="sidebar">
        <div class="sb-brand">
            <span class="glyph">T</span>
            <span class="nm">Trueleads</span>
        </div>

        <nav class="sb-scroll">
            <div class="sb-group">
                <div class="sb-group-label">Desk</div>
                <a href="{{ route('dealer.reservations') }}"
                   class="nav-item {{ request()->routeIs('dealer.reservations') ? 'on' : '' }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></svg>
                    <span class="nav-label">Reservations</span>
                </a>
            </div>

            <div class="sb-group">
                <div class="sb-group-label">Settings</div>
                <a href="{{ route('dealer.fees') }}"
                   class="nav-item {{ request()->routeIs('dealer.fees') ? 'on' : '' }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/></svg>
                    <span class="nav-label">Fee schedule</span>
                </a>
            </div>
        </nav>

        <div class="sb-foot">
            <span class="av">{{ collect(explode(' ', auth()->user()->name))->take(2)->map(fn ($namePart) => mb_strtoupper(mb_substr($namePart, 0, 1)))->implode('') }}</span>
            <div class="meta">
                <div class="nm">{{ auth()->user()->name }}</div>
                <div class="rl">{{ auth()->user()->dealer?->name }}</div>
            </div>
        </div>
    </aside>

    <div class="content">
        <header class="appbar">
            <div class="crumb">@stack('crumb')</div>
            <div class="appbar-right">
                <form method="POST" action="{{ route('dealer.logout') }}">
                    @csrf
                    <button type="submit" class="appbar-signout">Sign out</button>
                </form>
            </div>
        </header>

        <main class="content-body">
            {{ $slot }}
        </main>
    </div>
</div>

@livewireScripts
@stack('scripts')
</body>
</html>
