<?php

use App\Models\AuditLog;
use App\Models\Deal;
use App\Models\Scopes\DealerScope;
use App\Support\Permission;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
| Command Centre — one reservation, read-only (reservations.view). Shows the
| seller as frozen at reserve time and the handover in the dealer's own
| timezone. Opening it is audited. Nothing here changes the deal: the dealer
| owns the sale.
*/
new #[Layout('layouts.dealer')] #[Title('Reservation')] class extends Component {
    #[Locked]
    public int $dealId;

    public function mount(int $deal): void
    {
        Gate::authorize(Permission::RESERVATIONS_VIEW);

        $record = Deal::withoutGlobalScope(DealerScope::class)->findOrFail($deal);
        $this->dealId = $record->id;

        AuditLog::record('reservation.viewed', $record, null, ['reference' => $record->reference, 'customer_user_id' => $record->user_id]);
    }

    public function deal(): Deal
    {
        Gate::authorize(Permission::RESERVATIONS_VIEW);

        return Deal::withoutGlobalScope(DealerScope::class)
            ->with(['vehicle', 'dealer', 'user', 'activities' => fn ($query) => $query->latest()])
            ->findOrFail($this->dealId);
    }
}; ?>

@push('crumb')
    <a href="{{ route('admin.reservations') }}" wire:navigate>Reservations</a>
    <span>›</span>
    <span class="cur">{{ $this->deal()->reference }}</span>
@endpush

@push('styles')
    <style>
        .rd-head h1 { font-size:24px; font-weight:800; letter-spacing:-.02em; margin:0 0 4px; font-family:var(--mono); }
        .rd-head p { margin:0 0 20px; font-size:14px; color:var(--ink-2); }
        .rd-grid { display:grid; grid-template-columns:1fr 1fr; gap:20px; max-width:980px; }
        .card { background:var(--card); border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow-sm); overflow:hidden; }
        .card.wide { grid-column:1 / -1; }
        .card-head { padding:15px 20px 13px; border-bottom:1px solid var(--line); font-size:15px; font-weight:700; }
        .kv { display:grid; grid-template-columns:130px 1fr; gap:9px 14px; padding:15px 20px; font-size:13.5px; }
        .kv .k { color:var(--ink-3); font-size:12.5px; }
        .act { padding:11px 20px; border-bottom:1px solid var(--line); font-size:13px; }
        .act:last-child { border-bottom:none; }
        .act .meta { font-size:11.5px; color:var(--ink-3); margin-top:2px; }
        .kv a { color:var(--primary); font-weight:700; }
        @media (max-width:860px){ .rd-grid { grid-template-columns:1fr; } }
    </style>
@endpush

@php($deal = $this->deal())
@php($seller = $deal->dealer_snapshot ?? [])

<div>
    <div class="rd-head">
        <h1>{{ $deal->reference }}</h1>
        <p>{{ $deal->stage_label }} · reserved {{ $deal->created_at->format('M j, Y') }} · read-only</p>
    </div>

    <div class="rd-grid">
        <div class="card">
            <div class="card-head">Customer</div>
            <div class="kv">
                <span class="k">Name</span><span>{{ $deal->customer_full_name }}</span>
                <span class="k">Email</span><span>{{ $deal->email }}</span>
                <span class="k">Phone</span><span>{{ $deal->phone }}</span>
                @if ($deal->user && $deal->user->isCustomer())
                    @can('customers.view')
                        <span class="k">Account</span><span><a href="{{ route('admin.customers.show', $deal->user) }}" wire:navigate>Open customer →</a></span>
                    @endcan
                @endif
            </div>
        </div>

        <div class="card">
            <div class="card-head">Vehicle &amp; seller</div>
            <div class="kv">
                <span class="k">Vehicle</span><span>{{ $deal->vehicle?->model_year }} {{ $deal->vehicle?->make }} {{ $deal->vehicle?->model }} {{ $deal->vehicle?->trim }}</span>
                <span class="k">VIN</span><span>{{ $deal->vehicle?->vin }}</span>
                <span class="k">Seller</span><span>{{ $deal->seller_name }}</span>
                <span class="k">Seller address</span>
                <span>{{ collect([$seller['street_address'] ?? null, $seller['city'] ?? null, $seller['province'] ?? null, $seller['postal_code'] ?? null])->filter()->implode(', ') ?: '—' }}</span>
            </div>
        </div>

        <div class="card">
            <div class="card-head">Plan &amp; handover</div>
            <div class="kv">
                <span class="k">Purchase</span><span>{{ ucfirst($deal->purchase_type) }}@if ($deal->term_months) · {{ $deal->term_months }} months @endif</span>
                <span class="k">Deposit</span><span>${{ number_format($deal->deposit_in_cents / 100, 2) }} · {{ $deal->deposit_status }}</span>
                <span class="k">Handover</span><span>{{ $deal->handover_summary }}</span>
            </div>
        </div>

        <div class="card">
            <div class="card-head">Identity</div>
            <div class="kv">
                <span class="k">Verified</span><span>{{ $deal->identity_verified_at ? $deal->identity_verified_at->format('M j, Y') : 'No' }}</span>
            </div>
        </div>

        <div class="card wide">
            <div class="card-head">Activity</div>
            @forelse ($deal->activities as $activity)
                <div class="act">
                    {{ $activity->body }}
                    <div class="meta">{{ $activity->kind }} · {{ $activity->author_name ?? 'TruCars' }} · {{ $activity->created_at->format('M j, Y g:i a') }} UTC</div>
                </div>
            @empty
                <div class="act">No activity.</div>
            @endforelse
        </div>
    </div>
</div>
