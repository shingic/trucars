{{--
    Header and tabs for one brand's settings. Expects $brand (App\Models\Brand)
    and $activeTab: overview | checklist | offers.
--}}
<style>
    .bh { display:flex; align-items:center; gap:16px; margin-bottom:20px; }
    .bh .logo { width:56px; height:56px; border-radius:3px; background:var(--ink); color:#fff; display:grid; place-items:center; font-family:var(--font-display); font-size:22px; font-weight:700; letter-spacing:0; flex-shrink:0; }
    .bh h1 { font-family:var(--font-display); font-size:34px; font-weight:700; text-transform:uppercase; line-height:1; letter-spacing:0; margin:0; }
    .bh p { margin:6px 0 0; font-size:14px; color:var(--ink-2); }
    .btabs { display:flex; gap:4px; margin-bottom:22px; box-shadow:inset 0 -2px 0 var(--ink); overflow-x:auto; }
    .btabs a { display:inline-flex; align-items:center; gap:7px; padding:11px 14px 10px; font-family:var(--font-display); font-size:16px; font-weight:600; text-transform:uppercase; color:var(--ink-2); text-decoration:none; border-bottom:3px solid transparent; white-space:nowrap; }
    .btabs a:hover { color:var(--ink); }
    .btabs a.on { color:var(--ink); border-bottom-color:var(--primary); }
    .btabs svg { width:15px; height:15px; color:var(--ink-3); }
    .btabs a.on svg { color:var(--ink); }
    @media (max-width:560px) { .bh h1 { font-size:28px; } .bh .logo { width:46px; height:46px; font-size:19px; } }
</style>
@php $dealerCount = $brand->dealers()->count(); @endphp
<div class="bh">
    <span class="logo">{{ $brand->initials() }}</span>
    <div>
        <h1>{{ $brand->name }}</h1>
        <p>Applies to {{ $dealerCount }} {{ $dealerCount === 1 ? 'dealer' : 'dealers' }} — every {{ $brand->name }} franchise follows these settings.</p>
    </div>
</div>
<nav class="btabs">
    <a href="{{ route('admin.brands.show', $brand) }}" wire:navigate class="{{ $activeTab === 'overview' ? 'on' : '' }}">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
        Overview
    </a>
    <a href="{{ route('admin.brands.checklist', $brand) }}" wire:navigate class="{{ $activeTab === 'checklist' ? 'on' : '' }}">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M9 4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2a1 1 0 0 1-1 1h-4a1 1 0 0 1-1-1Z"/><path d="m9 14 2 2 4-4"/></svg>
        CPO checklist
    </a>
    <a href="{{ route('admin.brands.offers', $brand) }}" wire:navigate class="{{ $activeTab === 'offers' ? 'on' : '' }}">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8Z"/><circle cx="7.5" cy="7.5" r="1.5"/></svg>
        Offers &amp; incentives
    </a>
</nav>
