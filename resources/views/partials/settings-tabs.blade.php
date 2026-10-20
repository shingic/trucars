{{-- Platform settings tabs. Expects $activeTab: general | brands. --}}
<style>
    .tabs { display:flex; gap:6px; margin-bottom:18px; border-bottom:1px solid var(--line); overflow-x:auto; }
    .tabs a { padding:9px 14px; font-size:14px; font-weight:600; color:var(--ink-2); border-bottom:2px solid transparent; margin-bottom:-1px; white-space:nowrap; }
    .tabs a.on { color:var(--ink); border-bottom-color:var(--primary); }
</style>
<nav class="tabs">
    <a href="{{ route('admin.settings') }}" wire:navigate class="{{ $activeTab === 'general' ? 'on' : '' }}">General</a>
    <a href="{{ route('admin.brands') }}" wire:navigate class="{{ $activeTab === 'brands' ? 'on' : '' }}">Brands</a>
</nav>
