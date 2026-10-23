<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Reservations' }} · TruCars</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400&family=Barlow+Semi+Condensed:wght@500;600;700;800&display=swap" rel="stylesheet">

    <style>
        [x-cloak] { display:none !important; }
        :root {
            --bg:#F9F9F9; --card:#FFFFFF;
            --ink:#071437; --ink-2:#4B5675; --ink-3:#78829D;
            --line:rgba(7,20,55,.09); --line-strong:rgba(7,20,55,.15);
            --primary:#1B84FF; --primary-press:#056EE9; --primary-bright:#6CB0FF; --primary-soft:rgba(27,132,255,.10); --primary-line:rgba(27,132,255,.30);
            --coral:#4DA0FF; --coral-soft:rgba(77,160,255,.16);
            --good:#17C653; --good-soft:rgba(23,198,83,.12); --good-ink:#0E5A43;
            --amber:#C8841A; --amber-soft:rgba(216,150,40,.16);
            --active:#F3F1EF;
            --btn:#071437; --btn-press:#101114;
            --hero-grad:#DDE3EE;
            --shadow-sm:0 1px 3px rgba(7,20,55,.06);
            --shadow-md:0 8px 24px rgba(7,20,55,.10);
            --shadow-lg:0 30px 70px rgba(7,20,55,.18);
            --radius:4px; --radius-sm:3px; --radius-pill:3px;
            --sidebar-w:268px;
            --font:"Barlow",-apple-system,sans-serif; --font-display:"Barlow Semi Condensed","Barlow",sans-serif; --mono:ui-monospace,"SF Mono",Menlo,Consolas,monospace;
        }

        * { box-sizing:border-box; }
        body { margin:0; font-family:var(--font); color:var(--ink); background:var(--bg); -webkit-font-smoothing:antialiased; line-height:1.5; }
        h1, h2, h3, h4 { font-family:var(--font-display); }
        button { font-family:inherit; cursor:pointer; border:none; background:none; }
        input { font-family:inherit; }
        a { color:inherit; text-decoration:none; }

        .app { display:flex; min-height:100vh; }

        .sidebar { width:var(--sidebar-w); flex-shrink:0; background:var(--ink); color:#fff; display:flex; flex-direction:column; height:100vh; position:sticky; top:0; }

        .sb-brand { display:flex; align-items:center; gap:11px; padding:18px 20px 16px; }
        /* the TruCars wordmark: condensed caps, "Cars" in blue */
        .wordmark, .wordmark-short { font-family:var(--font-display); font-size:24px; font-weight:800; line-height:1; text-transform:uppercase; letter-spacing:.01em; color:#fff; white-space:nowrap; }
        .wordmark b, .wordmark-short b { font-weight:800; color:var(--primary-bright); }
        .wordmark-short { display:none; }

        .sb-scroll { flex:1; overflow-y:auto; padding:6px 12px 22px; }
        .sb-group { margin-top:13px; }
        .sb-group:first-child { margin-top:0; }
        .sb-group-label { font-family:var(--font-display); font-size:14px; font-weight:600; text-transform:uppercase; color:rgba(255,255,255,.45); padding:10px 12px 5px; }
        .nav-item { width:100%; text-align:left; display:flex; align-items:center; gap:11px; font-size:14.5px; font-weight:600; color:rgba(255,255,255,.78); padding:9px 12px; border-radius:0; border-left:3px solid transparent; transition:background .14s ease, color .14s ease; }
        .nav-item svg { width:18px; height:18px; color:rgba(255,255,255,.45); flex-shrink:0; transition:color .14s ease; }
        .nav-item:hover { background:rgba(255,255,255,.06); color:#fff; }
        .nav-item.on { background:rgba(255,255,255,.08); color:#fff; border-left-color:var(--primary); }
        .nav-item.on svg { color:var(--primary-bright); }
        .sb-switch { padding:0 12px; }
        .sb-switch .sb-group-label { display:block; padding-left:0; }
        .sb-switch select { width:100%; margin-top:6px; border:1px solid rgba(255,255,255,.25); border-radius:3px; padding:8px 10px; font:inherit; font-size:13.5px; font-weight:600; color:#fff; background:rgba(255,255,255,.06); }
        .sb-switch select option { color:var(--ink); }
        .sidebar .sb-switch select:not(.bare) { background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%23FFFFFF' stroke-width='2.6' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E") !important; }
        .nav-badge { margin-left:auto; font-size:11px; font-weight:700; padding:2px 7px; border-radius:2px; background:var(--primary); color:#fff; }

        .sb-foot { border-top:1px solid rgba(255,255,255,.12); padding:13px 14px; display:flex; align-items:center; gap:10px; }
        .sb-foot .av { width:34px; height:34px; border-radius:3px; background:rgba(255,255,255,.12); color:#fff; display:grid; place-items:center; font-weight:700; font-size:12px; flex-shrink:0; }
        .sb-foot .nm { font-size:13px; font-weight:700; line-height:1.2; }
        .sb-foot .rl { font-size:12px; color:rgba(255,255,255,.5); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:150px; }

        .content { flex:1; min-width:0; display:flex; flex-direction:column; }
        .appbar { position:sticky; top:0; z-index:40; background:#fff; border-bottom:1px solid var(--line-strong); display:flex; align-items:center; gap:16px; height:60px; padding:0 30px; }
        .crumb { font-size:14px; font-weight:600; color:var(--ink-3); display:flex; align-items:center; gap:8px; }
        .crumb .cur { color:var(--ink); font-weight:700; }
        .appbar-right { margin-left:auto; display:flex; align-items:center; gap:12px; }
        .appbar-signout { font-size:14px; font-weight:600; color:var(--ink); padding:7px 14px; border-radius:3px; border:1.5px solid var(--ink); }
        .appbar-signout:hover { background:var(--ink); color:#fff; }

        .content-body { padding:28px 30px 72px; }

        @media (max-width:880px){
            :root { --sidebar-w:72px; }
            .sb-brand { justify-content:center; padding:18px 0 16px; }
            .wordmark { display:none; } .wordmark-short { display:block; font-size:22px; }
            .sb-group-label, .nav-item .nav-label, .sb-foot .meta, .sb-switch { display:none; }
            .nav-item { justify-content:center; padding:11px; gap:0; }
            .sb-foot { justify-content:center; }
            .content-body { padding:22px 18px 60px; }
        }
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

    @livewireStyles
    @stack('styles')
</head>
<body>
<div class="app">
    <aside class="sidebar">
        <div class="sb-brand">
            <span class="wordmark">Tru<b>Cars</b></span>
            <span class="wordmark-short" aria-hidden="true">T<b>C</b></span>
        </div>

        <nav class="sb-scroll">
            {{-- Navigation only — every page enforces its own role on the server. --}}
            @if (auth()->user()->isDealerStaff())
                @if (count(auth()->user()->accessibleDealerIds()) > 1)
                    <form method="POST" action="{{ route('dealer.switch') }}" class="sb-group sb-switch">
                        @csrf
                        <label class="sb-group-label" for="portal-dealer">Dealership</label>
                        <select id="portal-dealer" name="dealer_id" onchange="this.form.submit()">
                            @foreach (\App\Models\Dealer::whereIn('id', auth()->user()->accessibleDealerIds())->orderBy('name')->get(['id', 'name']) as $portalOption)
                                <option value="{{ $portalOption->id }}" @selected($portalOption->id === auth()->user()->portalDealerId())>{{ ucwords(strtolower($portalOption->name)) }}</option>
                            @endforeach
                        </select>
                    </form>
                @endif
                <div class="sb-group">
                    <div class="sb-group-label">Desk</div>
                    @if (count(auth()->user()->accessibleRooftopIds()) > 1)
                        <a href="{{ route('dealer.group') }}"
                           class="nav-item {{ request()->routeIs('dealer.group') ? 'on' : '' }}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18M5 21V8l7-5 7 5v13M9 21v-6h6v6"/></svg>
                            <span class="nav-label">All stores</span>
                        </a>
                    @endif
                    <a href="{{ route('dealer.reservations') }}"
                       class="nav-item {{ request()->routeIs('dealer.reservations') ? 'on' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></svg>
                        <span class="nav-label">Reservations</span>
                    </a>
                    <a href="{{ route('dealer.inventory') }}"
                       class="nav-item {{ request()->routeIs('dealer.inventory') ? 'on' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 17h14M6 17l1.5-6h9L18 17M7 21v-2M17 21v-2"/><circle cx="8" cy="14" r="1"/><circle cx="16" cy="14" r="1"/></svg>
                        <span class="nav-label">Inventory</span>
                    </a>
                </div>

                <div class="sb-group">
                    <div class="sb-group-label">Marketing</div>
                    <a href="{{ route('dealer.offers') }}"
                       class="nav-item {{ request()->routeIs('dealer.offers') ? 'on' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8Z"/><circle cx="7.5" cy="7.5" r="1.5"/></svg>
                        <span class="nav-label">Offers</span>
                    </a>
                    <a href="{{ route('dealer.campaigns') }}"
                       class="nav-item {{ request()->routeIs('dealer.campaigns') ? 'on' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                        <span class="nav-label">Campaigns</span>
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
            @endif

            @if (auth()->user()->canUseCommandCentre())
                <div class="sb-group">
                    <div class="sb-group-label">Command Centre</div>
                    <a href="{{ route('admin.home') }}" class="nav-item {{ request()->routeIs('admin.home') ? 'on' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
                        <span class="nav-label">Home</span>
                    </a>
                    @can('dealers.browse')
                        <a href="{{ route('admin.dealers') }}" class="nav-item {{ request()->routeIs('admin.dealers*') || request()->routeIs('admin.customers*') ? 'on' : '' }}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18M5 21V8l7-4 7 4v13M9 21v-6h6v6"/></svg>
                            <span class="nav-label">Dealers</span>
                        </a>
                    @endcan
                    @can('reservations.view')
                        <a href="{{ route('admin.reservations') }}" class="nav-item {{ request()->routeIs('admin.reservations*') ? 'on' : '' }}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></svg>
                            <span class="nav-label">Reservations</span>
                        </a>
                    @endcan
                    @can('staff.manage')
                        <a href="{{ route('admin.staff') }}" class="nav-item {{ request()->routeIs('admin.staff') ? 'on' : '' }}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="4"/><path d="M2 21a7 7 0 0 1 14 0M17 11a3 3 0 1 0 0-6M22 21a6 6 0 0 0-4-5.6"/></svg>
                            <span class="nav-label">TruCars staff</span>
                        </a>
                    @endcan
                    @can('settings.manage')
                        <a href="{{ route('admin.settings') }}" class="nav-item {{ request()->routeIs('admin.settings*') ? 'on' : '' }}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-2.82 1.17V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 3.6 15H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6h.09A1.65 1.65 0 0 0 10 3.09V3a2 2 0 1 1 4 0v.09A1.65 1.65 0 0 0 15 4.6a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9v.09A1.65 1.65 0 0 0 21 10h.09a2 2 0 1 1 0 4H21a1.65 1.65 0 0 0-1.6 1z"/></svg>
                            <span class="nav-label">Platform settings</span>
                        </a>
                    @endcan
                    @can('audit.view')
                        <a href="{{ route('admin.audit') }}" class="nav-item {{ request()->routeIs('admin.audit') ? 'on' : '' }}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6M8 13h8M8 17h5"/></svg>
                            <span class="nav-label">Audit log</span>
                        </a>
                    @endcan
                </div>
            @endif
        </nav>

        <div class="sb-foot">
            <span class="av">{{ collect(explode(' ', auth()->user()->name))->take(2)->map(fn ($namePart) => mb_strtoupper(mb_substr($namePart, 0, 1)))->implode('') }}</span>
            <div class="meta">
                <div class="nm">{{ auth()->user()->name }}</div>
                <div class="rl">{{ auth()->user()->portalDealer()?->name ?? (auth()->user()->isOwner() ? 'TruCars owner' : (auth()->user()->isInternalStaff() ? 'TruCars staff' : '')) }}</div>
            </div>
        </div>
    </aside>

    <div class="content">
        <header class="appbar">
            <div class="crumb">@stack('crumb')</div>
            <div class="appbar-right">
                <form method="POST" action="{{ auth()->user()->canUseCommandCentre() ? route('admin.logout') : route('dealer.logout') }}">
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
