<?php

use App\Livewire\Concerns\ManagesDealerInventory;
use App\Models\Dealer;
use App\Support\Permission;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
| Command Centre — any dealer's inventory.
|
| Viewing needs dealers.view; importing the feed and publishing or
| unpublishing need inventory.manage. The list, actions and audit trail are
| shared with the dealer portal's own page (ManagesDealerInventory).
*/
new #[Layout('layouts.dealer')] #[Title('Inventory')] class extends Component {
    use ManagesDealerInventory;

    #[Locked]
    public int $dealerId;

    public function mount(Dealer $dealer): void
    {
        Gate::authorize(Permission::DEALERS_VIEW);
        $this->dealerId = $dealer->id;
    }

    protected function inventoryDealerId(): int
    {
        return $this->dealerId;
    }

    protected function authorizeInventoryView(): void
    {
        Gate::authorize(Permission::DEALERS_VIEW);
    }

    protected function authorizeInventoryManage(): void
    {
        Gate::authorize(Permission::INVENTORY_MANAGE);
    }

    public function canManageInventory(): bool
    {
        return Gate::allows(Permission::INVENTORY_MANAGE);
    }

    public function with(): array
    {
        return $this->inventoryViewData();
    }
}; ?>

@push('crumb')
    <a href="{{ route('admin.dealers') }}" wire:navigate>Dealers</a>
    <span>›</span>
    <a href="{{ route('admin.dealers.edit', $dealerId) }}" wire:navigate>{{ $inventoryDealer->name }}</a>
    <span>›</span>
    <span class="cur">Inventory</span>
@endpush

@push('styles')
    <style>
        .tabs { display:flex; gap:6px; margin-bottom:18px; border-bottom:1px solid var(--line); }
        .tabs a { padding:9px 14px; font-size:14px; font-weight:600; color:var(--ink-2); border-bottom:2px solid transparent; margin-bottom:-1px; }
        .tabs a.on { color:var(--ink); border-bottom-color:var(--primary); }
        .iv-head h1 { font-size:24px; font-weight:800; letter-spacing:-.02em; margin:0 0 16px; }
    </style>
@endpush

<div>
    @include('partials.dealer-tabs', ['tabDealer' => $inventoryDealer, 'activeTab' => 'inventory'])

    <div class="iv-head"><h1>{{ $inventoryDealer->name }} — inventory</h1></div>

    <p class="iv-note">
        Vehicle details come from the dealer's inventory feed; re-import it to update prices, kilometres or photos.
        Here you decide which cars are live.
        @unless ($inventoryDealer->isActive()) <b>This dealer is {{ $inventoryDealer->status }}, so none of its cars show on the marketplace right now.</b> @endunless
        @unless ($inventoryDealer->hasCompleteAddress()) <b>Its address is incomplete, so cars can't be published until it's filled in.</b> @endunless
    </p>

    @include('partials.inventory-manager')
</div>
