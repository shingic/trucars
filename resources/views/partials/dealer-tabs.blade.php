{{--
    The tabs across one dealer's Command Centre pages. Expects $tabDealer (a
    Dealer or its id) and $activeTab: profile | inventory | customers.
--}}
<style>
    .tabs { display:flex; gap:6px; margin-bottom:18px; border-bottom:1px solid var(--line); overflow-x:auto; }
    .tabs a { padding:9px 14px; font-size:14px; font-weight:600; color:var(--ink-2); border-bottom:2px solid transparent; margin-bottom:-1px; white-space:nowrap; }
    .tabs a.on { color:var(--ink); border-bottom-color:var(--primary); }
</style>
<nav class="tabs">
    @can('dealers.view')
        <a href="{{ route('admin.dealers.edit', $tabDealer) }}" wire:navigate class="{{ $activeTab === 'profile' ? 'on' : '' }}">Profile &amp; users</a>
        <a href="{{ route('admin.dealers.inventory', $tabDealer) }}" wire:navigate class="{{ $activeTab === 'inventory' ? 'on' : '' }}">Inventory</a>
    @endcan
    @can('customers.view')
        <a href="{{ route('admin.dealers.customers', $tabDealer) }}" wire:navigate class="{{ $activeTab === 'customers' ? 'on' : '' }}">Customers</a>
    @endcan
</nav>
