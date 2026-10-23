<?php

use App\Livewire\Concerns\ManagesDealerProtection;
use App\Models\Dealer;
use App\Support\Permission;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
| Command Centre — one dealer's protection & add-ons, the same list the
| dealership manages in its portal. Viewing needs dealers.view; changing it
| needs dealers.manage.
*/
new #[Layout('layouts.dealer')] #[Title('Protection & add-ons')] class extends Component {
    use ManagesDealerProtection;

    #[Locked]
    public int $dealerId;

    public function mount(Dealer $dealer): void
    {
        Gate::authorize(Permission::DEALERS_VIEW);
        $this->dealerId = $dealer->id;
    }

    protected function protectionDealerId(): int
    {
        return $this->dealerId;
    }

    protected function authorizeProtectionManage(): void
    {
        Gate::authorize(Permission::DEALERS_MANAGE);
    }

    public function with(): array
    {
        Gate::authorize(Permission::DEALERS_VIEW);

        return [
            'dealer'    => $this->protectionDealer(),
            'sections'  => $this->protectionSections(),
            'canManage' => Gate::allows(Permission::DEALERS_MANAGE),
        ];
    }
}; ?>

@push('crumb')
    <a href="{{ route('admin.dealers') }}" wire:navigate>Dealers</a>
    <span>›</span>
    <a href="{{ route('admin.dealers.edit', $dealerId) }}" wire:navigate>{{ $dealer->name }}</a>
    <span>›</span>
    <span class="cur">Protection &amp; add-ons</span>
@endpush

<div>
    @include('partials.dealer-tabs', ['tabDealer' => $dealer, 'activeTab' => 'protection'])

    <div class="pm-intro">
        <h1>{{ $dealer->name }} — protection &amp; add-ons</h1>
        <p>What this dealership's buyers can add to their estimate at checkout: the TruCars defaults it shows, at its own price where it set one, and the items it added. Changes here are the same as the dealership making them in its portal.</p>
    </div>

    @include('partials.protection-manager')
</div>
