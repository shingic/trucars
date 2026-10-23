<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Permission;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
| Command Centre — find any customer account to help (customers.view).
| Customers are browsed per dealer (Dealers › Customers); this search is for
| accounts that aren't tied to a dealer yet, e.g. no reservation.
|
| Search-first by design: nothing is listed until support searches for a
| specific person, results are capped, and every search is audited. Only
| customer accounts are searchable — never dealer staff or TruCars staff.
*/
new #[Layout('layouts.dealer')] #[Title('Customers')] class extends Component {
    private const MAX_RESULTS = 25;

    public string $query = '';

    #[Locked]
    public string $searchedFor = '';

    public function mount(): void
    {
        Gate::authorize(Permission::CUSTOMERS_VIEW);
    }

    public function search(): void
    {
        Gate::authorize(Permission::CUSTOMERS_VIEW);

        $this->query = trim($this->query);
        $this->validate(['query' => ['required', 'string', 'min:3', 'max:120']], attributes: ['query' => 'search']);

        $this->searchedFor = $this->query;

        AuditLog::record('customer.searched', null, null, ['query' => $this->searchedFor, 'results' => $this->results()->count()]);
    }

    public function results(): Collection
    {
        if ($this->searchedFor === '') {
            return collect();
        }

        $term = '%' . str_replace(['%', '_'], ['\%', '\_'], $this->searchedFor) . '%';

        return User::query()
            ->where('role', User::ROLE_CUSTOMER)
            ->where(fn ($query) => $query->where('email', 'like', $term)->orWhere('name', 'like', $term))
            ->withCount('deals')
            ->orderBy('name')
            ->limit(self::MAX_RESULTS)
            ->get();
    }
}; ?>

@push('crumb')
    <a href="{{ route('admin.dealers') }}" wire:navigate>Dealers</a>
    <span>›</span>
    <span class="cur">Find a customer</span>
@endpush

@push('styles')
    <style>
        .cu-head h1 { font-size:24px; font-weight:800; letter-spacing:0; margin:0 0 6px; }
        .cu-head p { margin:0 0 20px; font-size:14px; color:var(--ink-2); max-width:640px; }
        .cu-search { display:flex; gap:10px; max-width:640px; margin-bottom:6px; }
        .cu-search input { flex:1; border:1px solid var(--line-strong); border-radius:var(--radius-pill); padding:10px 16px; font-size:14px; font-family:inherit; }
        .cu-search input:focus { outline:none; border-color:var(--primary); }
        .btn { font-size:14px; font-weight:700; border-radius:var(--radius-pill); padding:10px 18px; white-space:nowrap; }
        .btn-primary { background:var(--primary); color:#fff; }
        .err { font-size:12px; color:var(--primary-press); font-weight:600; }
        .card { background:var(--card); border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow-sm); max-width:880px; margin-top:18px; overflow:hidden; }
        .cu-row { display:flex; align-items:center; gap:14px; padding:13px 20px; border-bottom:1px solid var(--line); }
        .cu-row:last-child { border-bottom:none; }
        .cu-row:hover { background:var(--bg); }
        .cu-row > div { flex:1; min-width:0; }
        .cu-name { font-size:14px; font-weight:700; }
        .cu-meta { font-size:12px; color:var(--ink-3); margin-top:2px; }
        .cu-go { font-size:13px; font-weight:700; color:var(--primary); }
        .cu-empty { padding:18px 20px; font-size:13.5px; color:var(--ink-3); }
        .cu-note { font-size:12px; color:var(--ink-3); }
    </style>
@endpush

<div>
    <div class="cu-head">
        <h1>Find a customer</h1>
        <p>Search by name or email to find the customer you're helping. Searches and the records you open are logged.</p>
    </div>

    <form class="cu-search" wire:submit="search">
        <input type="search" wire:model="query" placeholder="Name or email (at least 3 characters)" maxlength="120" aria-label="Search customers">
        <button type="submit" class="btn btn-primary">Search</button>
    </form>
    @error('query') <span class="err">{{ $message }}</span> @enderror

    @if ($searchedFor !== '')
        <div class="card">
            @forelse ($this->results() as $customer)
                <a class="cu-row" href="{{ route('admin.customers.show', $customer) }}" wire:navigate wire:key="customer-{{ $customer->id }}">
                    <div>
                        <div class="cu-name">{{ $customer->name }}</div>
                        <div class="cu-meta">{{ $customer->email }} · {{ $customer->deals_count }} {{ $customer->deals_count === 1 ? 'reservation' : 'reservations' }}{{ $customer->email_verified_at ? '' : ' · email not verified' }}</div>
                    </div>
                    <span class="cu-go">Open →</span>
                </a>
            @empty
                <div class="cu-empty">No customers match “{{ $searchedFor }}”.</div>
            @endforelse
        </div>
        <p class="cu-note">Showing up to 25 matches. Refine the search if you don't see them.</p>
    @endif
</div>
