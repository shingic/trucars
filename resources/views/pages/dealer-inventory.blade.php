<?php

use App\Livewire\Concerns\ManagesDealerInventory;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
| Dealer portal — the signed-in dealership's own inventory.
|
| The dealer is always the signed-in staff member's own (never a route or
| form value), so another dealer's cars can't be reached even with a doctored
| id. Dealer staff can review their cars, publish or unpublish them, and
| upload their own feed; EnsureUserIsStaff runs on the page and on every
| action. Shared behaviour lives in ManagesDealerInventory.
*/
new #[Layout('layouts.dealer')] #[Title('Inventory')] class extends Component {
    use ManagesDealerInventory;

    public function mount(): void
    {
        $this->authorizeInventoryView();
    }

    protected function inventoryDealerId(): int
    {
        return (int) auth()->user()->portalDealerId();
    }

    /** Staff limited to chosen stores see only those stores' cars. */
    protected function inventoryRooftopIds(): ?array
    {
        return auth()->user()->isLimitedToStores() ? auth()->user()->accessibleRooftopIds() : null;
    }

    protected function authorizeInventoryView(): void
    {
        abort_unless(auth()->user()?->isDealerStaff() && ! auth()->user()->isSuspended(), 403, 'This area is for dealer staff.');
    }

    protected function authorizeInventoryManage(): void
    {
        $this->authorizeInventoryView();
        abort_if(auth()->user()->portalDealer()?->isSuspended(), 403);
    }

    public function canManageInventory(): bool
    {
        return auth()->user()?->isDealerStaff() ?? false;
    }

    public function with(): array
    {
        return $this->inventoryViewData();
    }
}; ?>

@push('crumb')
    <span>Desk</span>
    <span>›</span>
    <span class="cur">Inventory</span>
@endpush

@push('styles')
    <style>
        .iv-head h1 { font-size:34px; font-weight:700; text-transform:uppercase; line-height:1; letter-spacing:0; margin:0 0 18px; }
    </style>
@endpush

<div>
    <div class="iv-head"><h1>Inventory</h1></div>

    <p class="iv-note">
        Your cars as they come from your inventory feed. Publish the ones you want on TruCars; upload a fresh export any time to update prices, kilometres and photos.
        @unless ($inventoryDealer->isActive()) <b>Your dealership isn't live on TruCars yet, so published cars won't show until TruCars activates it.</b> @endunless
        @unless ($inventoryDealer->hasCompleteAddress()) <b>Your dealership address is incomplete, so cars can't be published yet — contact TruCars to complete it.</b> @endunless
    </p>

    @include('partials.inventory-manager')
</div>
