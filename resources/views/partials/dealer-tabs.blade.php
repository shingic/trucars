{{--
    The tabs across one dealer's Command Centre pages. Expects $tabDealer (a
    Dealer or its id) and $activeTab: profile | inventory | protection | customers.
--}}
<style>
    .tabs { display:flex; gap:4px; margin-bottom:20px; border-bottom:1px solid var(--line-strong); overflow-x:auto; }
    .tabs a { padding:10px 14px; font-family:var(--font-display); font-size:15px; font-weight:700; text-transform:uppercase; color:var(--ink-3); border-bottom:3px solid transparent; margin-bottom:-1px; white-space:nowrap; }
    .tabs a:hover { color:var(--ink); }
    .tabs a.on { color:var(--ink); border-bottom-color:var(--primary); }
</style>
<nav class="tabs">
    @can('dealers.view')
        <a href="{{ route('admin.dealers.edit', $tabDealer) }}" wire:navigate class="{{ $activeTab === 'profile' ? 'on' : '' }}">Profile &amp; users</a>
        <a href="{{ route('admin.dealers.inventory', $tabDealer) }}" wire:navigate class="{{ $activeTab === 'inventory' ? 'on' : '' }}">Inventory</a>
        <a href="{{ route('admin.dealers.protection', $tabDealer) }}" wire:navigate class="{{ $activeTab === 'protection' ? 'on' : '' }}">Protection &amp; add-ons</a>
    @endcan
    @can('customers.view')
        <a href="{{ route('admin.dealers.customers', $tabDealer) }}" wire:navigate class="{{ $activeTab === 'customers' ? 'on' : '' }}">Customers</a>
    @endcan
</nav>
