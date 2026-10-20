<?php

use App\Support\Permission;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
| Command Centre home: where the owner and internal staff land. Shows only the
| areas the signed-in person's permissions open; each area enforces its own
| permission regardless of what's shown here.
*/
new #[Layout('layouts.dealer')] #[Title('Command Centre')] class extends Component {
    public function mount(): void
    {
        abort_unless(auth()->user()?->canUseCommandCentre(), 403);
    }

    /** @return array<int, array{title: string, detail: string, url: ?string}> */
    public function areas(): array
    {
        $user = auth()->user();

        return array_values(array_filter([
            $user->can(Permission::DEALERS_VIEW) ? ['title' => 'Dealers', 'detail' => 'Dealer profiles, addresses and status' . ($user->can(Permission::DEALER_USERS_MANAGE) ? ', and their portal users.' : '.'), 'url' => route('admin.dealers')] : null,
            $user->can(Permission::STAFF_MANAGE) ? ['title' => 'TruCars staff', 'detail' => 'Invite staff and set what each can do.', 'url' => route('admin.staff')] : null,
            $user->can(Permission::AUDIT_VIEW) ? ['title' => 'Audit log', 'detail' => 'Who changed what, and when.', 'url' => route('admin.audit')] : null,
            $user->can(Permission::CUSTOMERS_VIEW) ? ['title' => 'Customers', 'detail' => 'Find a customer and help with their account.', 'url' => route('admin.customers')] : null,
            $user->can(Permission::RESERVATIONS_VIEW) ? ['title' => 'Reservations', 'detail' => 'Every dealer’s reservations, read-only.', 'url' => route('admin.reservations')] : null,
            $user->can(Permission::SETTINGS_MANAGE) ? ['title' => 'Platform settings', 'detail' => 'Marketplace headline and site notice.', 'url' => route('admin.settings')] : null,
        ]));
    }
}; ?>

@push('crumb')
    <span class="cur">Command Centre</span>
@endpush

@push('styles')
    <style>
        .cc-head h1 { font-size:24px; font-weight:800; letter-spacing:-.02em; margin:0 0 6px; }
        .cc-head p { margin:0 0 22px; font-size:14px; color:var(--ink-2); }
        .cc-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(260px, 1fr)); gap:16px; max-width:920px; }
        .cc-card { display:block; background:var(--card); border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow-sm); padding:18px 20px; transition:border-color .15s ease; }
        a.cc-card:hover { border-color:var(--primary-line); }
        .cc-card .t { font-size:15px; font-weight:700; }
        .cc-card .d { font-size:13px; color:var(--ink-2); margin-top:4px; }
        .cc-card.soon { opacity:.7; }
        .cc-empty { font-size:14px; color:var(--ink-2); }
    </style>
@endpush

<div>
    <div class="cc-head">
        <h1>Command Centre</h1>
        <p>Signed in as {{ auth()->user()->name }} · {{ auth()->user()->isOwner() ? 'Owner — full access' : 'TruCars staff' }}</p>
    </div>

    @if ($this->areas() === [])
        <p class="cc-empty">Your account doesn't have any areas yet. Ask the owner to grant the access you need.</p>
    @else
        <div class="cc-grid">
            @foreach ($this->areas() as $area)
                @if ($area['url'])
                    <a href="{{ $area['url'] }}" wire:navigate class="cc-card"><div class="t">{{ $area['title'] }}</div><div class="d">{{ $area['detail'] }}</div></a>
                @else
                    <div class="cc-card soon"><div class="t">{{ $area['title'] }}</div><div class="d">{{ $area['detail'] }}</div></div>
                @endif
            @endforeach
        </div>
    @endif
</div>
