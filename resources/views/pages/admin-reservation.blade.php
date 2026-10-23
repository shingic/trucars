<?php

use App\Models\AuditLog;
use App\Models\Deal;
use App\Models\Scopes\DealerScope;
use App\Support\Deposits;
use App\Support\Permission;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
| Command Centre — one reservation (reservations.view). Shows the seller as
| frozen at reserve time and the handover in the dealer's own timezone.
| Opening it is audited. The dealer owns the sale; the one thing TruCars does
| here is cancel a reservation and refund the $150 deposit it holds
| (reservations.refund) — the buyer's right any time before delivery.
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

    public string $cancelReason = '';

    public string $statusMessage = '';

    public function cancelAndRefund(Deposits $deposits): void
    {
        Gate::authorize(Permission::RESERVATIONS_REFUND);
        $this->validate(['cancelReason' => ['required', 'string', 'min:5', 'max:500']], ['cancelReason.required' => 'Say why — it goes on the deal’s record.']);

        $deal = $this->deal();

        try {
            $refunded = $deposits->cancelReservation($deal, $this->cancelReason, byDealer: false);
        } catch (\Throwable $e) {
            report($e);
            $this->addError('cancelReason', 'Stripe didn’t accept the refund, so nothing was changed. Try again, or refund it in the Stripe dashboard. (' . $e->getMessage() . ')');

            return;
        }

        $this->cancelReason = '';
        $this->statusMessage = $refunded ? 'Cancelled. The $150 deposit is on its way back to the customer’s card.' : 'Cancelled. This reservation has no card deposit on file to refund.';
    }

    public function deal(): Deal
    {
        Gate::authorize(Permission::RESERVATIONS_VIEW);

        return Deal::withoutGlobalScope(DealerScope::class)
            ->with(['vehicle', 'dealer', 'user', 'depositPayment', 'activities' => fn ($query) => $query->latest()])
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
        .meta-line { font-size:12px; color:var(--ink-3); margin-top:3px; }
        .mono { font-family:var(--mono); }
        .rd-msg { background:var(--good-soft); color:var(--good-ink); border-radius:var(--radius-sm); padding:11px 15px; font-size:13.5px; font-weight:600; margin-bottom:16px; max-width:980px; }
        .refund { padding:15px 20px; font-size:13.5px; color:var(--ink-2); }
        .refund p { margin:0 0 10px; }
        .refund textarea { width:100%; border:1px solid var(--line-strong); border-radius:var(--radius-sm); padding:9px 12px; font:inherit; font-size:13.5px; resize:vertical; background:var(--card); color:var(--ink); }
        .refund .err { color:var(--bad, #c0392b); font-size:12.5px; font-weight:600; margin-top:6px; }
        .btn-danger { margin-top:10px; border-radius:var(--radius-pill); padding:9px 16px; font-size:13.5px; font-weight:700; background:#C0392B; color:#fff; }
        .btn-danger:disabled { opacity:.6; }
        @media (max-width:860px){ .rd-grid { grid-template-columns:1fr; } }
    </style>
@endpush

@php($deal = $this->deal())
@php($seller = $deal->dealer_snapshot ?? [])

<div>
    <div class="rd-head">
        <h1>{{ $deal->reference }}</h1>
        <p>{{ $deal->stage_label }} · reserved {{ $deal->created_at->format('M j, Y') }}</p>
    </div>

    @if ($statusMessage !== '') <div class="rd-msg">{{ $statusMessage }}</div> @endif

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
                @if ($store = $deal->rooftop_snapshot)
                    <span class="k">Store</span>
                    <span>{{ collect([$store['name'] ?? null, $store['street_address'] ?? null, $store['city'] ?? null, $store['province'] ?? null, $store['postal_code'] ?? null])->filter()->implode(', ') }}</span>
                @endif
            </div>
        </div>

        <div class="card">
            <div class="card-head">Plan &amp; handover</div>
            <div class="kv">
                <span class="k">Purchase</span><span>{{ ucfirst($deal->purchase_type) }}@if ($deal->term_months) · {{ $deal->term_months }} months @endif</span>
                <span class="k">Deposit</span>
                <span>
                    ${{ number_format($deal->deposit_in_cents / 100, 2) }} · {{ $deal->deposit_status }}
                    @if ($deal->depositPayment)
                        <div class="meta-line">
                            @if ($deal->depositPayment->isDemo())
                                Demo mode — no card was charged
                            @else
                                Card via Stripe · <span class="mono">{{ $deal->depositPayment->stripe_payment_intent_id }}</span>
                            @endif
                        </div>
                    @endif
                </span>
                <span class="k">Handover</span><span>{{ $deal->handover_summary }}</span>
            </div>
        </div>

        <div class="card">
            <div class="card-head">Identity</div>
            <div class="kv">
                <span class="k">Verified</span><span>{{ $deal->identity_verified_at ? $deal->identity_verified_at->format('M j, Y') : 'No' }}</span>
            </div>
        </div>

        @can('reservations.refund')
            @unless (in_array($deal->stage, ['cancelled', 'delivered'], true))
                <div class="card wide">
                    <div class="card-head">Cancel &amp; refund</div>
                    <div class="refund">
                        <p>The customer can cancel any time before delivery. This cancels the reservation, releases the car, and {{ $deal->depositPayment ? 'refunds the $' . number_format($deal->deposit_in_cents / 100) . ' deposit to their card' : 'records it — there’s no card deposit on file to refund' }}.</p>
                        <textarea wire:model="cancelReason" rows="2" placeholder="Reason (goes on the deal’s record), e.g. Customer changed their mind"></textarea>
                        @error('cancelReason') <div class="err">{{ $message }}</div> @enderror
                        <button type="button" class="btn-danger" wire:click="cancelAndRefund" wire:loading.attr="disabled" wire:target="cancelAndRefund"
                                wire:confirm="Cancel {{ $deal->reference }}{{ $deal->depositPayment ? ' and refund the deposit' : '' }}? This can’t be undone.">
                            {{ $deal->depositPayment ? 'Cancel & refund $' . number_format($deal->deposit_in_cents / 100) : 'Cancel reservation' }}
                        </button>
                    </div>
                </div>
            @endunless
        @endcan

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
