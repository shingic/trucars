{{--
    Header and tabs for one brand's settings. Expects $brand (App\Models\Brand)
    and $activeTab: overview | checklist | offers.
--}}
<style>
    .bh { display:flex; align-items:center; gap:16px; margin-bottom:18px; }
    .bh .logo { width:56px; height:56px; border-radius:16px; background:#14161B; color:#fff; display:grid; place-items:center; font-size:19px; font-weight:800; letter-spacing:-.02em; flex-shrink:0; }
    .bh h1 { font-size:26px; font-weight:800; letter-spacing:-.02em; margin:0; }
    .bh p { margin:3px 0 0; font-size:13.5px; color:var(--ink-2); }
    .btabs { display:flex; gap:6px; margin-bottom:20px; border-bottom:1px solid var(--line); overflow-x:auto; }
    .btabs a { display:inline-flex; align-items:center; gap:7px; padding:9px 14px; font-size:14px; font-weight:600; color:var(--ink-2); border-bottom:2px solid transparent; margin-bottom:-1px; white-space:nowrap; }
    .btabs a.on { color:var(--ink); border-bottom-color:var(--primary); }
    .btabs svg { width:15px; height:15px; }
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
