<?php

use App\Models\AuditLog;
use App\Models\Deal;
use App\Models\Dealer;
use App\Models\Scopes\DealerScope;
use App\Models\User;
use App\Support\Permission;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/*
| Command Centre — the customers of one dealer: every buyer account with a
| reservation at this dealer, newest activity first. Needs customers.view
| (customer support reaches it from the Dealers list without seeing dealer
| profiles). Opening the list is audited, like a customer search.
*/
new #[Layout('layouts.dealer')] #[Title('Customers')] class extends Component {
    use WithPagination;

    #[Locked]
    public int $dealerId;

    public string $search = '';

    public function mount(Dealer $dealer): void
    {
        Gate::authorize(Permission::CUSTOMERS_VIEW);
        $this->dealerId = $dealer->id;

        AuditLog::record('dealer.customers_viewed', $dealer);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        Gate::authorize(Permission::CUSTOMERS_VIEW);

        $dealsHere = fn ($deals) => $deals->withoutGlobalScope(DealerScope::class)->where('dealer_id', $this->dealerId);
        $term = '%' . str_replace(['%', '_'], ['\%', '\_'], trim($this->search)) . '%';

        $customers = User::query()
            ->where('role', User::ROLE_CUSTOMER)
            ->whereHas('deals', $dealsHere)
            ->when(trim($this->search) !== '', fn ($query) => $query->where(fn ($match) => $match->where('name', 'like', $term)->orWhere('email', 'like', $term)))
            ->withCount(['deals as reservations_here' => $dealsHere])
            ->withMax(['deals as last_reserved_at' => $dealsHere], 'created_at')
            ->orderByDesc('last_reserved_at')
            ->paginate(25);

        // The latest reservation here for each customer on this page, for its stage and car.
        $latest = Deal::withoutGlobalScope(DealerScope::class)
            ->where('dealer_id', $this->dealerId)
            ->whereIn('user_id', $customers->pluck('id'))
            ->with('vehicle')
            ->latest()
            ->get()
            ->unique('user_id')
            ->keyBy('user_id');

        return [
            'dealer'    => Dealer::findOrFail($this->dealerId),
            'customers' => $customers,
            'latest'    => $latest,
        ];
    }
}; ?>

@push('crumb')
    <a href="{{ route('admin.dealers') }}" wire:navigate>Dealers</a>
    <span>›</span>
    @can('dealers.view')
        <a href="{{ route('admin.dealers.edit', $dealerId) }}" wire:navigate>{{ $dealer->name }}</a>
    @else
        <span>{{ $dealer->name }}</span>
    @endcan
    <span>›</span>
    <span class="cur">Customers</span>
@endpush

@push('styles')
    <style>
        .dc-head { display:flex; align-items:flex-end; gap:16px; margin-bottom:16px; flex-wrap:wrap; }
        .dc-head h1 { font-size:24px; font-weight:800; letter-spacing:-.02em; margin:0 0 6px; }
        .dc-head p { margin:0; font-size:14px; color:var(--ink-2); max-width:620px; }
        .dc-head .spacer { flex:1; }
        .dc-search { position:relative; }
        .dc-search svg { position:absolute; left:13px; top:50%; transform:translateY(-50%); width:16px; height:16px; color:var(--ink-3); }
        .dc-search input { border:1px solid var(--line-strong); border-radius:var(--radius-pill); padding:9px 16px 9px 37px; font-size:14px; font-family:inherit; min-width:260px; background:var(--card); }
        .dc-search input:focus { outline:none; border-color:var(--primary); }
        .card { background:var(--card); border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow-sm); overflow:hidden; }
        .dc-row { display:grid; grid-template-columns:40px minmax(0, 1.4fr) minmax(0, 1.4fr) 140px 20px; gap:14px; align-items:center; padding:12px 20px; border-bottom:1px solid var(--line); }
        .dc-row:last-child { border-bottom:none; }
        a.dc-row:hover { background:var(--bg); }
        .dc-row.head { font-size:11px; font-weight:700; letter-spacing:.05em; text-transform:uppercase; color:var(--ink-3); padding-top:10px; padding-bottom:10px; }
        .av { width:36px; height:36px; border-radius:50%; background:var(--primary-soft); color:var(--primary-press); display:grid; place-items:center; font-size:12.5px; font-weight:800; }
        .nm { font-size:14px; font-weight:700; }
        .meta { font-size:12px; color:var(--ink-3); margin-top:2px; }
        .unv { color:var(--amber); font-weight:600; }
        .car { font-size:13.5px; font-weight:600; }
        .stage { display:inline-block; font-size:10.5px; font-weight:800; letter-spacing:.04em; text-transform:uppercase; padding:3px 9px; border-radius:var(--radius-pill); background:var(--bg); color:var(--ink-2); border:1px solid var(--line); }
        .go { width:16px; height:16px; color:var(--ink-3); }
        .empty { padding:22px 20px; font-size:13.5px; color:var(--ink-3); }
        .foot { margin-top:14px; display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; font-size:13px; color:var(--ink-3); }
        .foot a { font-weight:700; color:var(--primary-press); }
        @media (max-width:860px) {
            .dc-row { grid-template-columns:36px minmax(0, 1fr) 16px; }
            .dc-row .hide-sm, .dc-row.head { display:none; }
            .dc-search input { min-width:0; width:100%; }
            .dc-search { width:100%; }
        }
    </style>
@endpush

<div>
    @include('partials.dealer-tabs', ['tabDealer' => $dealer, 'activeTab' => 'customers'])

    <div class="dc-head">
        <div>
            <h1>Customers</h1>
            <p>Everyone who has reserved a car with {{ $dealer->name }}. Opening this list and each customer is logged.</p>
        </div>
        <div class="spacer"></div>
        <label class="dc-search">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Name or email" aria-label="Search this dealer's customers">
        </label>
    </div>

    <div class="card">
        <div class="dc-row head"><span></span><span>Customer</span><span class="hide-sm">Latest reservation</span><span class="hide-sm">Stage</span><span></span></div>
        @forelse ($customers as $customer)
            @php $deal = $latest[$customer->id] ?? null; @endphp
            <a class="dc-row" href="{{ route('admin.customers.show', $customer) }}" wire:navigate wire:key="c-{{ $customer->id }}">
                <span class="av">{{ collect(explode(' ', $customer->name))->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('') }}</span>
                <span>
                    <div class="nm">{{ $customer->name }}</div>
                    <div class="meta">{{ $customer->email }}@unless ($customer->email_verified_at) · <span class="unv">email not verified</span>@endunless</div>
                </span>
                <span class="hide-sm">
                    @if ($deal?->vehicle)
                        <div class="car">{{ $deal->vehicle->model_year }} {{ $deal->vehicle->make }} {{ $deal->vehicle->model }}</div>
                    @endif
                    <div class="meta">{{ $deal?->created_at?->format('M j, Y') }} · {{ $customer->reservations_here }} {{ $customer->reservations_here === 1 ? 'reservation' : 'reservations' }} here</div>
                </span>
                <span class="hide-sm">@if ($deal)<span class="stage">{{ $deal->stage_label }}</span>@endif</span>
                <svg class="go" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="m9 6 6 6-6 6"/></svg>
            </a>
        @empty
            <div class="empty">{{ trim($search) !== '' ? 'No customers match “' . $search . '”.' : $dealer->name . ' has no reservations yet.' }}</div>
        @endforelse
    </div>

    <div class="foot">
        <span>{{ $customers->links() }}</span>
        <span>Helping someone with no reservation yet? <a href="{{ route('admin.customers') }}" wire:navigate>Find any customer account →</a></span>
    </div>
</div>
