<?php

use App\Models\AuditLog;
use App\Support\Permission;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/*
| Command Centre — the audit log, newest first. Read-only (entries are
| append-only at the model). Needs audit.view.
*/
new #[Layout('layouts.dealer')] #[Title('Audit log')] class extends Component {
    use WithPagination;

    public string $actionFilter = '';

    public function mount(): void
    {
        Gate::authorize(Permission::AUDIT_VIEW);
    }

    public function updatedActionFilter(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        Gate::authorize(Permission::AUDIT_VIEW);

        return [
            'entries' => AuditLog::query()
                ->when($this->actionFilter !== '', fn ($query) => $query->where('action', 'like', $this->actionFilter . '%'))
                ->latest('id')
                ->paginate(50),
        ];
    }
}; ?>

@push('crumb')
    <span>Command Centre</span>
    <span>›</span>
    <span class="cur">Audit log</span>
@endpush

@push('styles')
    <style>
        .al-head { display:flex; align-items:flex-end; gap:16px; margin-bottom:18px; }
        .al-head h1 { font-size:24px; font-weight:800; letter-spacing:-.02em; margin:0; flex:1; }
        .al-head select { border:1px solid var(--line-strong); border-radius:var(--radius-sm); padding:8px 12px; font-family:inherit; font-size:13.5px; background:var(--card); }
        .card { background:var(--card); border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow-sm); overflow:hidden; }
        .al-row { display:grid; grid-template-columns:150px 200px minmax(0, 1fr); gap:14px; padding:12px 18px; border-bottom:1px solid var(--line); font-size:13px; }
        .al-row:last-child { border-bottom:none; }
        .al-when { color:var(--ink-3); font-family:var(--mono); font-size:12px; }
        .al-who { color:var(--ink-2); overflow:hidden; text-overflow:ellipsis; }
        .al-what b { font-family:var(--mono); font-size:12.5px; }
        .al-diff { font-family:var(--mono); font-size:11.5px; color:var(--ink-3); margin-top:4px; word-break:break-word; }
        .al-empty { padding:20px; color:var(--ink-3); font-size:13.5px; }
        .al-pages { margin-top:16px; }
        @media (max-width:760px){ .al-row { grid-template-columns:1fr; gap:4px; } }
    </style>
@endpush

<div>
    <div class="al-head">
        <h1>Audit log</h1>
        <select wire:model.live="actionFilter" aria-label="Filter">
            <option value="">All actions</option>
            <option value="staff.">TruCars staff</option>
            <option value="owner.">Owner access</option>
            <option value="dealer.">Dealers</option>
            <option value="dealer_user.">Dealer users</option>
            <option value="customer.">Customer data</option>
            <option value="command_centre.">Sign-ins</option>
        </select>
    </div>

    <div class="card">
        @forelse ($entries as $entry)
            <div class="al-row" wire:key="audit-{{ $entry->id }}">
                <div class="al-when">{{ $entry->created_at->format('Y-m-d H:i:s') }} UTC</div>
                <div class="al-who" title="{{ $entry->ip_address }}">{{ $entry->actor_email ?? 'console' }}<br><span class="al-when">{{ $entry->actor_role }}</span></div>
                <div class="al-what">
                    <b>{{ $entry->action }}</b>
                    @if ($entry->target_type) · {{ $entry->target_type }} #{{ $entry->target_id }} {{ $entry->target_label }} @endif
                    @if ($entry->before || $entry->after)
                        <div class="al-diff">
                            @if ($entry->before) before {{ json_encode($entry->before, JSON_UNESCAPED_SLASHES) }} @endif
                            @if ($entry->after) → after {{ json_encode($entry->after, JSON_UNESCAPED_SLASHES) }} @endif
                        </div>
                    @endif
                </div>
            </div>
        @empty
            <div class="al-empty">Nothing recorded yet.</div>
        @endforelse
    </div>

    <div class="al-pages">{{ $entries->links() }}</div>
</div>
