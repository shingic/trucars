<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
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
        [x-cloak] { display:none !important; }
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
        .nav-acct { width:36px; height:36px; border-radius:50%; background:var(--bg-2); border:1px solid var(--line); display:grid; place-items:center; font-weight:700; font-size:13px; color:var(--ink-2); text-decoration:none; transition:border-color .15s ease, color .15s ease; }
        .nav-acct:hover { border-color:var(--primary); color:var(--primary); }

        /* desktop account dropdown */
        .acct { position:relative; display:inline-flex; }
        .acct-badge { position:absolute; top:-4px; right:-4px; min-width:18px; height:18px; padding:0 5px; border-radius:var(--radius-pill); background:var(--primary); color:#fff; font-size:10.5px; font-weight:700; display:grid; place-items:center; border:2px solid var(--bg); box-shadow:var(--shadow-sm); }
        .acct-menu { position:absolute; top:calc(100% + 10px); right:0; width:252px; background:var(--card); border:1px solid var(--line); border-radius:16px; box-shadow:var(--shadow-md); padding:8px; z-index:50; }
        .acct-menu-head { padding:10px 12px 12px; border-bottom:1px solid var(--line); margin-bottom:6px; }
        .acct-menu-name { display:block; font-size:14.5px; font-weight:700; letter-spacing:-.01em; }
        .acct-menu-email { display:block; font-size:12.5px; color:var(--ink-3); margin-top:2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .acct-menu form { margin:0; }
        .acct-menu-item { display:flex; align-items:center; gap:11px; width:100%; padding:10px 12px; border-radius:10px; font-size:14px; font-weight:600; color:var(--ink); text-decoration:none; text-align:left; background:none; transition:background .14s ease, color .14s ease; }
        .acct-menu-item:hover { background:var(--bg-2); }
        .acct-menu-item svg { color:var(--ink-3); flex-shrink:0; }
        .acct-menu-item:hover svg { color:var(--primary); }
        .acct-menu-label { flex:1; }
        .acct-menu-count { min-width:20px; height:20px; padding:0 6px; border-radius:var(--radius-pill); background:var(--primary-soft); color:var(--primary); font-size:11.5px; font-weight:700; display:grid; place-items:center; }
        .acct-menu-sep { height:1px; background:var(--line); margin:6px 4px; }
        .acct-menu-signout { color:var(--ink-2); }
        .acct-menu-signout:hover { background:var(--primary-soft); color:var(--primary); }
        .nav-signin { display:inline-flex; align-items:center; font-size:14.5px; font-weight:700; color:var(--ink); text-decoration:none; border:1.5px solid var(--line-strong); border-radius:var(--radius-pill); padding:8px 18px; transition:border-color .15s ease, color .15s ease; }
        .nav-signin:hover { border-color:var(--primary); color:var(--primary); }

        /* hamburger — hidden on desktop, shown under 860px */
        .nav-burger { display:none; margin-left:auto; width:42px; height:42px; border-radius:12px; align-items:center; justify-content:center; color:var(--ink); }
        .nav-burger:active { background:var(--bg-2); }

        /* slide-out drawer + scrim (mobile) */
        .nav-scrim { position:fixed; inset:0; background:rgba(22,24,29,.5); backdrop-filter:blur(2px); z-index:55; }
        .nav-drawer {
            position:fixed; top:0; right:0;
            height:100vh; height:100dvh;
            width:min(86vw, 360px);
            background:var(--card); z-index:60;
            box-shadow:-24px 0 60px rgba(22,24,29,.2);
            transform:translateX(100%);
            transition:transform .3s cubic-bezier(.32,.72,0,1);
            display:flex; flex-direction:column;
            padding-top:env(safe-area-inset-top);
        }
        .nav-drawer.is-open { transform:translateX(0); }
        .drawer-top { display:flex; align-items:center; justify-content:space-between; padding:16px 18px; border-bottom:1px solid var(--line); }
        .drawer-close { width:40px; height:40px; border-radius:50%; background:var(--bg-2); display:grid; place-items:center; color:var(--ink-2); }
        .drawer-close:active { color:var(--ink); }
        .drawer-auth { padding:16px 18px; border-bottom:1px solid var(--line); }
        .drawer-signin { display:flex; align-items:center; justify-content:center; width:100%; font-size:15px; font-weight:700; color:#fff; text-decoration:none; background:var(--ink); border-radius:var(--radius-pill); padding:13px 18px; }
        .drawer-account { display:flex; align-items:center; gap:12px; text-decoration:none; color:var(--ink); }
        .drawer-account .da-name { font-size:15.5px; font-weight:700; letter-spacing:-.01em; }
        .drawer-account .da-sub { font-size:12.5px; color:var(--ink-3); margin-top:1px; }
        .drawer-rows { flex:1; overflow-y:auto; -webkit-overflow-scrolling:touch; }
        .drawer-row { display:flex; align-items:center; gap:14px; padding:18px; border-bottom:1px solid var(--line); text-decoration:none; color:var(--ink); }
        .drawer-row:active { background:var(--bg-2); }
        .drawer-row .dr-main { flex:1; min-width:0; }
        .drawer-row .dr-title { font-size:17px; font-weight:700; letter-spacing:-.01em; }
        .drawer-row.active .dr-title { color:var(--primary); }
        .drawer-row .dr-sub { font-size:13px; color:var(--ink-3); margin-top:2px; }
        .drawer-row .dr-chev { color:var(--ink-3); flex-shrink:0; }
        .drawer-row .dr-count { min-width:22px; height:22px; padding:0 7px; border-radius:var(--radius-pill); background:var(--primary); color:#fff; font-size:12px; font-weight:700; display:grid; place-items:center; flex-shrink:0; }
        .drawer-foot { padding:16px 18px calc(16px + env(safe-area-inset-bottom)); border-top:1px solid var(--line); }
        .drawer-signout-form { margin:0 0 14px; }
        .drawer-signout { display:flex; align-items:center; justify-content:center; gap:9px; width:100%; font-size:15px; font-weight:700; color:var(--ink); background:var(--bg-2); border-radius:var(--radius-pill); padding:13px 18px; }
        .drawer-signout svg { color:var(--ink-2); flex-shrink:0; }
        .drawer-signout:active { background:var(--line); }
        .drawer-loc { display:inline-flex; align-items:center; gap:7px; font-size:13.5px; color:var(--ink-2); font-weight:500; }
        .drawer-loc svg { color:var(--primary); }

        .page { max-width:1600px; margin:0 auto; padding:32px 24px 64px; }

        .site-foot { border-top:1px solid var(--line); background:var(--bg-2); }
        .site-foot-inner { max-width:1600px; margin:0 auto; padding:28px 24px; display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap; }
        .site-foot .brand { font-size:15px; }
        .site-foot-links { display:flex; gap:20px; flex-wrap:wrap; }
        .site-foot-links a { font-size:13px; color:var(--ink-2); text-decoration:none; }
        .site-foot-links a:hover { color:var(--ink); }
        .site-foot-legal { font-size:12px; color:var(--ink-3); width:100%; }

        @media (max-width:860px) {
            .nav-inner { padding:10px 16px; gap:12px; padding-top:max(10px, env(safe-area-inset-top)); }
            .nav-links, .nav-right { display:none; }
            .nav-burger { display:flex; }
            .page { padding:18px 16px calc(40px + env(safe-area-inset-bottom)); }
            .site-foot-inner { padding:22px 16px calc(22px + env(safe-area-inset-bottom)); gap:12px; }
            .site-foot-legal { font-size:11.5px; }
        }
    </style>
</head>
<body>
<nav class="nav"
     x-data="{ menuOpen: false }"
     x-effect="document.body.style.overflow = menuOpen ? 'hidden' : ''"
     @keydown.escape.window="menuOpen = false">
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
            @auth
                @if (auth()->user()->dealer_id === null)
                    <div class="acct" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
                        <button type="button"
                                class="nav-acct"
                                @click="open = !open"
                                :aria-expanded="open"
                                aria-haspopup="true"
                                aria-label="Account menu">
                            {{ auth()->user()->initials }}
                            <span class="acct-badge"
                                  x-data="{ count: {{ auth()->user()->favouriteVehiclesCount }} }"
                                  x-show="count > 0"
                                  x-text="count"
                                  x-cloak
                                  @favourites-updated.window="count = $event.detail.count"></span>
                        </button>

                        <div class="acct-menu" x-show="open" x-transition x-cloak>
                            <div class="acct-menu-head">
                                <span class="acct-menu-name">{{ auth()->user()->firstName }}</span>
                                <span class="acct-menu-email">{{ auth()->user()->email }}</span>
                            </div>

                            <a href="{{ route('garage') }}" wire:navigate class="acct-menu-item" @click="open = false">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18M5 21V8l7-4 7 4v13M9 21v-6h6v6"/></svg>
                                <span class="acct-menu-label">My Garage</span>
                            </a>

                            <a href="{{ route('saved') }}" wire:navigate class="acct-menu-item" @click="open = false">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 14c1.5-1.5 3-3.3 3-5.5A4.5 4.5 0 0 0 12 5 4.5 4.5 0 0 0 2 8.5c0 2.2 1.5 4 3 5.5l7 7Z"/></svg>
                                <span class="acct-menu-label">Saved cars</span>
                                <span class="acct-menu-count"
                                      x-data="{ count: {{ auth()->user()->favouriteVehiclesCount }} }"
                                      x-show="count > 0"
                                      x-text="count"
                                      x-cloak
                                      @favourites-updated.window="count = $event.detail.count"></span>
                            </a>

                            <div class="acct-menu-sep"></div>

                            <form method="POST" action="{{ route('buyer.logout') }}">
                                @csrf
                                <button type="submit" class="acct-menu-item acct-menu-signout">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
                                    <span class="acct-menu-label">Sign out</span>
                                </button>
                            </form>
                        </div>
                    </div>
                @else
                    <a href="{{ route('dealer.reservations') }}" wire:navigate class="nav-acct" title="Dealer console">{{ auth()->user()->initials }}</a>
                @endif
            @else
                <a href="{{ route('buyer.login') }}" wire:navigate class="nav-signin">Sign in</a>
            @endauth
        </div>

        <button type="button" class="nav-burger" @click="menuOpen = true" aria-label="Open menu">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="3" y1="7" x2="21" y2="7"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="17" x2="21" y2="17"/></svg>
        </button>
    </div>

    <!-- mobile drawer -->
    <div class="nav-scrim" x-cloak x-show="menuOpen" x-transition.opacity @click="menuOpen = false"></div>

    <aside class="nav-drawer" :class="{ 'is-open': menuOpen }" x-cloak>
        <div class="drawer-top">
            <a href="/" class="brand" @click="menuOpen = false"><span class="glyph">T</span> TruCars</a>
            <button type="button" class="drawer-close" @click="menuOpen = false" aria-label="Close menu">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </div>

        <div class="drawer-auth">
            @auth
                @if (auth()->user()->dealer_id === null)
                    <a href="{{ route('garage') }}" wire:navigate class="drawer-account" @click="menuOpen = false">
                        <span class="nav-acct">{{ auth()->user()->initials }}</span>
                        <span>
                            <span class="da-name">My Garage</span>
                            <span class="da-sub">Saved cars, documents &amp; deals</span>
                        </span>
                    </a>
                @else
                    <a href="{{ route('dealer.reservations') }}" wire:navigate class="drawer-account" @click="menuOpen = false">
                        <span class="nav-acct">{{ auth()->user()->initials }}</span>
                        <span>
                            <span class="da-name">Dealer console</span>
                            <span class="da-sub">Reservations &amp; leads</span>
                        </span>
                    </a>
                @endif
            @else
                <a href="{{ route('buyer.login') }}" wire:navigate class="drawer-signin" @click="menuOpen = false">Sign in</a>
            @endauth
        </div>

        <nav class="drawer-rows">
            <a href="/" wire:navigate class="drawer-row active" @click="menuOpen = false">
                <span class="dr-main">
                    <span class="dr-title">Shop cars</span>
                    <span class="dr-sub">Hundreds of certified cars</span>
                </span>
                <svg class="dr-chev" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 6 6 6-6 6"/></svg>
            </a>
            @auth
                @if (auth()->user()->dealer_id === null)
                    <a href="{{ route('saved') }}" wire:navigate class="drawer-row" @click="menuOpen = false">
                        <span class="dr-main">
                            <span class="dr-title">Saved cars</span>
                            <span class="dr-sub">Cars you've hearted</span>
                        </span>
                        <span class="dr-count"
                              x-data="{ count: {{ auth()->user()->favouriteVehiclesCount }} }"
                              x-show="count > 0"
                              x-text="count"
                              x-cloak
                              @favourites-updated.window="count = $event.detail.count"></span>
                        <svg class="dr-chev" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 6 6 6-6 6"/></svg>
                    </a>
                @endif
            @endauth
            <a href="#" class="drawer-row" @click="menuOpen = false">
                <span class="dr-main">
                    <span class="dr-title">Sell or trade</span>
                    <span class="dr-sub">Get a preliminary trade estimate</span>
                </span>
                <svg class="dr-chev" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 6 6 6-6 6"/></svg>
            </a>
            <a href="#" class="drawer-row" @click="menuOpen = false">
                <span class="dr-main">
                    <span class="dr-title">Financing</span>
                    <span class="dr-sub">Built right into checkout</span>
                </span>
                <svg class="dr-chev" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 6 6 6-6 6"/></svg>
            </a>
            <a href="#" class="drawer-row" @click="menuOpen = false">
                <span class="dr-main">
                    <span class="dr-title">How it works</span>
                    <span class="dr-sub">Reserve, certify, deliver</span>
                </span>
                <svg class="dr-chev" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 6 6 6-6 6"/></svg>
            </a>
        </nav>

        <div class="drawer-foot">
            @auth
                @if (auth()->user()->dealer_id === null)
                    <form method="POST" action="{{ route('buyer.logout') }}" class="drawer-signout-form">
                        @csrf
                        <button type="submit" class="drawer-signout">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
                            Sign out
                        </button>
                    </form>
                @endif
            @endauth
            <span class="drawer-loc">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                Thornhill, ON
            </span>
        </div>
    </aside>
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
