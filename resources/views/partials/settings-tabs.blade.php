{{-- Platform settings tabs. Expects $activeTab: general | brands | protection. --}}
<style>
    .tabs { display:flex; gap:4px; margin-bottom:20px; border-bottom:1px solid var(--line-strong); overflow-x:auto; }
    .tabs a { padding:10px 14px; font-family:var(--font-display); font-size:15px; font-weight:700; text-transform:uppercase; color:var(--ink-3); border-bottom:3px solid transparent; margin-bottom:-1px; white-space:nowrap; }
    .tabs a:hover { color:var(--ink); }
    .tabs a.on { color:var(--ink); border-bottom-color:var(--primary); }
</style>
<nav class="tabs">
    <a href="{{ route('admin.settings') }}" wire:navigate class="{{ $activeTab === 'general' ? 'on' : '' }}">General</a>
    <a href="{{ route('admin.brands') }}" wire:navigate class="{{ $activeTab === 'brands' ? 'on' : '' }}">Brands</a>
    <a href="{{ route('admin.settings.protection') }}" wire:navigate class="{{ $activeTab === 'protection' ? 'on' : '' }}">Protection &amp; add-ons</a>
</nav>
