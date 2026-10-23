<?php

use App\Models\AuditLog;
use App\Models\Deal;
use App\Models\Dealer;
use App\Models\Scopes\DealerScope;
use App\Support\Permission;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/*
| Command Centre — reservations across every dealer, read-only
| (reservations.view). Rows carry customer names, so opening the list and each
| search is audited. Dealers still only ever see their own (DealerScope); the
| scope is lifted here deliberately and only for this permission.
*/
new #[Layout('layouts.dealer')] #[Title('Reservations')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $stage = '';
    public string $dealerId = '';

    public function mount(): void
    {
        Gate::authorize(Permission::RESERVATIONS_VIEW);
        AuditLog::record('reservation.listed');
    }

    public function applyFilters(): void
    {
        Gate::authorize(Permission::RESERVATIONS_VIEW);

        $this->search = trim($this->search);
        $this->validate([
            'search'   => ['nullable', 'string', 'max:120'],
            'stage'    => ['nullable', 'in:' . implode(',', array_keys(Deal::STAGE_LABELS))],
            'dealerId' => ['nullable', 'integer'],
        ]);

        $this->resetPage();
        AuditLog::record('reservation.searched', null, null, array_filter(['search' => $this->search, 'stage' => $this->stage, 'dealer_id' => $this->dealerId]));
    }

    public function with(): array
    {
        Gate::authorize(Permission::RESERVATIONS_VIEW);

        $term = '%' . str_replace(['%', '_'], ['\%', '\_'], $this->search) . '%';

        return [
            'dealers' => Dealer::orderBy('name')->get(['id', 'name']),
            'deals'   => Deal::withoutGlobalScope(DealerScope::class)
                ->with(['vehicle', 'dealer'])
                ->when($this->search !== '', fn ($query) => $query->where(fn ($match) => $match
                    ->where('reference', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('first_name', 'like', $term)
                    ->orWhere('last_name', 'like', $term)))
                ->when($this->stage !== '', fn ($query) => $query->where('stage', $this->stage))
                ->when($this->dealerId !== '', fn ($query) => $query->where('dealer_id', (int) $this->dealerId))
                ->latest()
                ->paginate(25),
        ];
    }
}; ?>

@push('crumb')
    <span>Command Centre</span>
    <span>›</span>
    <span class="cur">Reservations</span>
@endpush

@push('styles')
    <style>
        .rv-head h1 { font-size:24px; font-weight:800; letter-spacing:0; margin:0 0 6px; }
        .rv-head p { margin:0 0 18px; font-size:14px; color:var(--ink-2); }
        .rv-filters { display:flex; flex-wrap:wrap; gap:10px; margin-bottom:16px; }
        .rv-filters input, .rv-filters select { border:1px solid var(--line-strong); border-radius:var(--radius-sm); padding:9px 12px; font-size:13.5px; font-family:inherit; background:var(--card); }
        .rv-filters input { flex:1; min-width:220px; }
        .btn { font-size:13.5px; font-weight:700; border-radius:var(--radius-pill); padding:9px 16px; background:var(--primary); color:#fff; }
        .card { background:var(--card); border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow-sm); overflow:hidden; }
        .rv-row { display:grid; grid-template-columns:110px minmax(0, 1.3fr) minmax(0, 1.3fr) 150px 90px; gap:14px; align-items:center; padding:12px 18px; border-bottom:1px solid var(--line); font-size:13.5px; }
        .rv-row:last-child { border-bottom:none; }
        .rv-row .meta { font-size:12px; color:var(--ink-3); }
        .rv-row a { font-weight:700; color:var(--primary); text-align:right; }
        .ref { font-family:var(--mono); font-weight:600; }
        .empty { padding:18px; color:var(--ink-3); font-size:13.5px; }
        .pages { margin-top:14px; }
        @media (max-width:900px){ .rv-row { grid-template-columns:1fr 1fr; } }
    </style>
@endpush

<div>
    <div class="rv-head">
        <h1>Reservations</h1>
        <p>Every dealer's reservations, read-only. Viewing is logged.</p>
    </div>

    <form class="rv-filters" wire:submit="applyFilters">
        <input type="search" wire:model="search" placeholder="Reference, customer name or email" maxlength="120" aria-label="Search">
        <select wire:model="stage" aria-label="Stage">
            <option value="">All stages</option>
            @foreach (\App\Models\Deal::STAGE_LABELS as $key => $label)
                <option value="{{ $key }}">{{ $label }}</option>
            @endforeach
        </select>
        <select wire:model="dealerId" aria-label="Dealer">
            <option value="">All dealers</option>
            @foreach ($dealers as $dealer)
                <option value="{{ $dealer->id }}">{{ $dealer->name }}</option>
            @endforeach
        </select>
        <button type="submit" class="btn">Filter</button>
    </form>

    <div class="card">
        @forelse ($deals as $deal)
            <div class="rv-row" wire:key="rv-{{ $deal->id }}">
                <span class="ref">{{ $deal->reference }}</span>
                <span>{{ $deal->customer_full_name }}<br><span class="meta">{{ $deal->email }}</span></span>
                <span>{{ $deal->vehicle?->model_year }} {{ $deal->vehicle?->make }} {{ $deal->vehicle?->model }}<br><span class="meta">{{ $deal->seller_name }}</span></span>
                <span>{{ $deal->stage_label }}<br><span class="meta">{{ $deal->created_at->format('M j, Y') }}</span></span>
                <a href="{{ route('admin.reservations.show', $deal) }}" wire:navigate>Open →</a>
            </div>
        @empty
            <div class="empty">No reservations match.</div>
        @endforelse
    </div>

    <div class="pages">{{ $deals->links() }}</div>
</div>
