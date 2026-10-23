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
        .rv-head h1 { font-family:var(--font-display); font-size:36px; font-weight:700; line-height:1; text-transform:uppercase; margin:0 0 10px; }
        .rv-head p { margin:0 0 20px; font-size:15px; color:var(--ink-2); }
        .rv-filters { display:flex; flex-wrap:wrap; gap:10px; margin-bottom:20px; }
        .rv-filters input, .rv-filters select { background:#fff; border:1.5px solid var(--line-strong); border-radius:3px; padding:9px 12px; font-size:14px; font-family:inherit; color:var(--ink); max-width:100%; }
        .rv-filters input:focus, .rv-filters select:focus { outline:none; border-color:var(--ink); box-shadow:0 0 0 3px var(--primary-soft); }
        .rv-filters input { flex:1; min-width:220px; }
        .btn { font-family:var(--font-display); font-size:16px; font-weight:700; text-transform:uppercase; border-radius:3px; padding:9px 18px; background:var(--primary); color:#fff; transition:background .15s ease; }
        .btn:hover { background:var(--primary-press); }
        .card { border-top:2px solid var(--ink); }
        .rv-row { display:grid; grid-template-columns:120px minmax(0, 1.3fr) minmax(0, 1.3fr) 150px 90px; gap:14px; align-items:center; padding:12px 6px; border-bottom:1px solid var(--line-strong); font-size:14px; }
        .rv-row:hover { background:#fff; }
        .rv-row > span { min-width:0; overflow-wrap:anywhere; }
        .rv-row .meta { font-size:13px; color:var(--ink-3); }
        .rv-row a { font-weight:700; color:var(--primary-press); text-align:right; text-decoration:underline; text-underline-offset:3px; white-space:nowrap; }
        .rv-row a:hover { color:var(--ink); }
        .ref { font-family:var(--mono); font-weight:600; font-size:14px; }
        .empty { margin-top:14px; padding:18px 20px; border:1.5px dashed var(--line-strong); border-radius:3px; color:var(--ink-3); font-size:15px; }
        .pages { margin-top:16px; }
        @media (max-width:900px){ .rv-row { grid-template-columns:1fr 1fr; } }
        @media (max-width:640px){ .rv-head h1 { font-size:30px; } .rv-filters input { min-width:0; flex-basis:100%; } }
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
