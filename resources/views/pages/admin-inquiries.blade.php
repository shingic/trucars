<?php

use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\Scopes\DealerScope;
use App\Support\Permission;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/*
| Command Centre: enquiries about cars, across every dealer (customers.view).
| An enquiry hasn't paid a deposit, so the shopper belongs to the platform:
| dealers don't see these. Opening the list and changing a status are audited.
*/
new #[Layout('layouts.dealer')] #[Title('Inquiries')] class extends Component {
    use WithPagination;

    public const STATUS_LABELS = [
        'new'       => 'New',
        'contacted' => 'Contacted',
        'confirmed' => 'Confirmed',
        'closed'    => 'Closed',
    ];

    public const NEXT_STATUS = ['new' => 'contacted', 'contacted' => 'confirmed', 'confirmed' => 'closed'];

    public string $status = 'new';

    public function mount(): void
    {
        Gate::authorize(Permission::CUSTOMERS_VIEW);
        AuditLog::record('inquiry.listed');
    }

    public function showStatus(string $status): void
    {
        abort_unless($status === 'all' || array_key_exists($status, self::STATUS_LABELS), 422);
        $this->status = $status;
        $this->resetPage();
    }

    public function advance(int $leadId): void
    {
        Gate::authorize(Permission::CUSTOMERS_VIEW);
        $lead = Lead::withoutGlobalScope(DealerScope::class)->findOrFail($leadId);
        $next = self::NEXT_STATUS[$lead->status] ?? null;

        if ($next !== null) {
            $before = $lead->status;
            $lead->update(['status' => $next]);
            AuditLog::record('inquiry.status_changed', $lead, ['status' => $before], ['status' => $next]);
        }
    }

    public function with(): array
    {
        Gate::authorize(Permission::CUSTOMERS_VIEW);
        $leads = Lead::withoutGlobalScope(DealerScope::class);

        return [
            'leads'  => (clone $leads)->with(['vehicle' => fn ($q) => $q->withoutGlobalScopes(), 'dealer'])
                ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
                ->latest()->paginate(30),
            'counts' => (clone $leads)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
        ];
    }
}; ?>

@push('crumb')
    <span>Command Centre</span>
    <span>›</span>
    <span class="cur">Inquiries</span>
@endpush

@push('styles')
    <style>
        .iq-head h1 { font-family:var(--font-display); font-size:36px; font-weight:700; line-height:1; text-transform:uppercase; margin:0 0 10px; }
        .iq-head p { margin:0 0 18px; font-size:15px; color:var(--ink-2); max-width:760px; }
        .iq-tabs { display:flex; gap:4px; border-bottom:1px solid var(--line-strong); margin-bottom:16px; overflow-x:auto; }
        .iq-tab { font-family:var(--font-display); font-size:17px; font-weight:700; text-transform:uppercase; color:var(--ink-3); padding:10px 16px 11px; border-bottom:3px solid transparent; margin-bottom:-1px; white-space:nowrap; }
        .iq-tab:hover { color:var(--ink); }
        .iq-tab.on { color:var(--ink); border-bottom-color:var(--primary); }
        .iq-tab .n { font-family:var(--font); font-size:13px; color:var(--ink-2); background:rgba(7,20,55,.07); padding:1px 7px; border-radius:5px; margin-left:6px; }
        .card { border-top:2px solid var(--ink); }
        .iq-row { display:grid; grid-template-columns:minmax(0, 1.2fr) minmax(0, 1.3fr) minmax(0, 1.6fr) 150px; gap:14px; align-items:start; padding:13px 6px; border-bottom:1px solid var(--line-strong); font-size:14px; }
        .iq-row > div { min-width:0; overflow-wrap:anywhere; }
        .iq-row b { font-weight:700; }
        .meta { display:block; font-size:13px; color:var(--ink-3); margin-top:2px; }
        .iq-row a { color:var(--primary-press); }
        .msg { color:var(--ink-2); }
        .badge { display:inline-block; font-size:12.5px; font-weight:700; padding:3px 8px; border-radius:5px; background:var(--primary-soft); color:var(--primary-press); }
        .adv { margin-top:8px; font-size:13px; font-weight:700; border:1.5px solid var(--ink); border-radius:8px; padding:6px 10px; background:#fff; color:var(--ink); }
        .adv:hover { background:var(--ink); color:#fff; }
        .empty { margin-top:14px; padding:18px 20px; border:1.5px dashed var(--line-strong); border-radius:8px; color:var(--ink-3); font-size:15px; }
        .pages { margin-top:16px; }
        @media (max-width:900px) { .iq-row { grid-template-columns:1fr 1fr; } }
    </style>
@endpush

<div>
    <div class="iq-head">
        <h1>Inquiries</h1>
        <p>Questions shoppers have sent about cars, across every dealer. They haven't paid a deposit, so they're yours to follow up: dealers don't see these. Viewing is logged.</p>
    </div>

    <div class="iq-tabs">
        @foreach (self::STATUS_LABELS as $key => $label)
            <button type="button" class="iq-tab {{ $status === $key ? 'on' : '' }}" wire:click="showStatus('{{ $key }}')">{{ $label }}<span class="n">{{ $counts[$key] ?? 0 }}</span></button>
        @endforeach
        <button type="button" class="iq-tab {{ $status === 'all' ? 'on' : '' }}" wire:click="showStatus('all')">All<span class="n">{{ $counts->sum() }}</span></button>
    </div>

    <div class="card">
        @forelse ($leads as $lead)
            <div class="iq-row" wire:key="iq-{{ $lead->id }}">
                <div>
                    <b>{{ $lead->name }}</b>
                    <span class="meta"><a href="mailto:{{ $lead->email }}">{{ $lead->email }}</a>{{ $lead->phone ? ' · ' . $lead->phone : '' }}</span>
                    <span class="meta">{{ $lead->created_at->diffForHumans() }}</span>
                </div>
                <div>
                    @if ($lead->vehicle)
                        <a href="{{ url('/cars/' . $lead->vehicle->id) }}" target="_blank" rel="noopener">{{ $lead->vehicle->model_year }} {{ $lead->vehicle->make }} {{ $lead->vehicle->model }}</a>
                        <span class="meta">{{ $lead->vehicle->display_price }} · {{ $lead->dealer?->name }} (internal only)</span>
                    @else
                        General inquiry
                    @endif
                </div>
                <div class="msg">{{ $lead->message ?: 'No message.' }}</div>
                <div>
                    <span class="badge">{{ self::STATUS_LABELS[$lead->status] ?? ucfirst($lead->status) }}</span>
                    @if (isset(self::NEXT_STATUS[$lead->status]))
                        <div><button type="button" class="adv" wire:click="advance({{ $lead->id }})">Mark {{ strtolower(self::STATUS_LABELS[self::NEXT_STATUS[$lead->status]]) }}</button></div>
                    @endif
                </div>
            </div>
        @empty
            <div class="empty">No inquiries here.</div>
        @endforelse
    </div>

    <div class="pages">{{ $leads->links() }}</div>
</div>
