<?php

use App\Livewire\Concerns\ManagesDealerProtection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
| Dealer portal — Protection & add-ons. What this dealership's buyers can flag
| at checkout: the TruCars defaults (shown or hidden, at the TruCars price or
| the dealership's own) and the dealership's own items. Shared with the
| Command Centre's dealer tab (ManagesDealerProtection).
*/
new #[Layout('layouts.dealer')] #[Title('Protection & add-ons')] class extends Component {
    use ManagesDealerProtection;

    #[Locked]
    public int $dealerId;

    public function mount(): void
    {
        $dealer = auth()->user()?->portalDealer();
        abort_unless($dealer !== null, 403, 'This area is for dealer accounts.');
        $this->dealerId = $dealer->id;
    }

    protected function protectionDealerId(): int
    {
        // Re-read every request: switching dealership in the sidebar moves the page with it.
        $dealer = auth()->user()?->portalDealer();
        abort_unless($dealer !== null && $dealer->id === $this->dealerId, 403);

        return $this->dealerId;
    }

    protected function authorizeProtectionManage(): void
    {
        $this->protectionDealerId();
    }

    public function with(): array
    {
        return ['sections' => $this->protectionSections(), 'canManage' => true];
    }
}; ?>

@push('crumb')
    <span>Settings</span>
    <span>›</span>
    <span class="cur">Protection &amp; add-ons</span>
@endpush

<div>
    <div class="pm-intro">
        <h1>Protection &amp; add-ons</h1>
        <p>What your buyers can ask about at checkout. Nothing is sold or charged online: buyers tick what interests them, and your F&amp;I office quotes the real price after they reserve. The prices here are the estimates buyers see.</p>
    </div>

    @include('partials.protection-manager')
</div>
