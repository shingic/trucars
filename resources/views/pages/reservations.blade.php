<?php

use App\Models\Deal;
use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\Auth;

new #[Layout('layouts.dealer')] class extends Component {
    public string $stageFilter = 'all';
    public function filterByStage(string $stage): void
    {
        $this->stageFilter = $stage;
    }

    public function advanceDeal(int $dealId): void
    {
        // DealerScope fences this lookup to the signed-in dealer.
        $deal = Deal::findOrFail($dealId);

        $nextStage = Deal::NEXT_STAGE[$deal->stage] ?? null;

        if ($nextStage) {
            $deal->update(['stage' => $nextStage]);
            $deal->recordActivity(
                'status',
                'Stage moved to ' . Deal::STAGE_LABELS[$nextStage] . '.',
                Auth::user()->name ?? 'Dealer',
            );
        }
    }

    /**
     * The reservations shown under the current stage filter. Plain methods
     * (not #[Computed]) on purpose: computed properties memoise for the whole
     * request, which bites when an action mutates state and then reads the
     * value back. A fresh query per call is the predictable choice here.
     */
    public function deals()
    {
        return Deal::query()
            ->with('vehicle')
            ->when(
                $this->stageFilter !== 'all',
                fn ($query) => $query->where('stage', $this->stageFilter),
            )
            ->latest()
            ->get();
    }

    public function stageCounts(): array
    {
        $countedByStage = Deal::query()
            ->selectRaw('stage, count(*) as total')
            ->groupBy('stage')
            ->pluck('total', 'stage')
            ->toArray();

        $emptyTally = array_fill_keys(array_keys(Deal::STAGE_LABELS), 0);

        return array_merge($emptyTally, $countedByStage);
    }

    /**
     * Hand the view its data computed exactly once per render. This is what
     * replaces the old #[Computed] properties: the counts and lists are derived
     * fresh on every render (so a stage advance shows immediately) but only
     * once, and the template stays free of inline PHP.
     */
    public function with(): array
    {
        return [
            'stageCounts'  => $this->stageCounts(),
            'reservations' => $this->deals(),
        ];
    }
}; ?>

@push('styles')
    <style>
        .inbox { max-width:1180px; }

        .page-head { margin-bottom:20px; }
        .page-head h1 { font-family:var(--font-display); font-size:34px; font-weight:700; text-transform:uppercase; line-height:1; letter-spacing:0; margin:0; }
        .page-sub { color:var(--ink-2); font-size:15px; margin:10px 0 0; }

        .inbox-tabs { display:flex; gap:4px; border-bottom:1px solid var(--line-strong); margin-bottom:18px; overflow-x:auto; }
        .tab-btn { font-family:var(--font-display); font-size:17px; font-weight:700; text-transform:uppercase; color:var(--ink-3); padding:10px 16px 11px; border-bottom:3px solid transparent; margin-bottom:-1px; transition:color .15s ease; white-space:nowrap; }
        .tab-btn:hover { color:var(--ink); }
        .tab-btn.on { color:var(--ink); border-bottom-color:var(--primary); }
        .tab-n { font-family:var(--font); font-size:13px; font-weight:700; color:var(--ink-2); background:rgba(7,20,55,.07); padding:1px 7px; border-radius:5px; margin-left:6px; }
        .tab-btn.on .tab-n { background:var(--ink); color:#fff; }

        .inbox-toolbar { display:flex; align-items:center; gap:14px; margin-bottom:14px; flex-wrap:wrap; }
        .inbox-count { font-size:13px; color:var(--ink-3); font-weight:600; }
        .stage-filter { margin-left:auto; display:flex; gap:6px; flex-wrap:wrap; }
        .sf-pill { font-size:13px; font-weight:700; color:var(--ink); padding:6px 11px; border:1.5px solid var(--line-strong); border-radius:8px; transition:border-color .15s ease, background .15s ease, color .15s ease; display:inline-flex; align-items:center; gap:7px; background:var(--card); }
        .sf-pill:hover { border-color:var(--ink); }
        .sf-pill.on { background:var(--ink); color:#fff; border-color:var(--ink); }
        .sf-n { font-size:13px; opacity:.7; font-variant-numeric:tabular-nums; }

        .deal-list { display:flex; flex-direction:column; border-top:2px solid var(--ink); background:var(--card); }
        .deal-row { border-bottom:1px solid var(--line-strong); padding:14px 16px; display:grid; grid-template-columns:1.3fr 1.7fr 1.5fr 1fr auto; gap:18px; align-items:center; transition:background .12s ease; }
        .deal-row:hover { background:var(--bg-2, #F9F9F9); }

        .dr-cust { display:flex; flex-direction:column; gap:2px; }
        .dr-name { font-weight:700; font-size:15px; }
        .dr-meta { font-size:13px; color:var(--ink-3); }
        .dr-ref { font-family:var(--mono); font-size:13px; color:var(--ink-2); font-weight:600; }

        .dr-veh { display:flex; align-items:center; gap:11px; }
        .dr-thumb { width:46px; height:36px; border-radius:5px; background:var(--hero-grad); display:grid; place-items:center; flex-shrink:0; }
        .dr-veh-title { font-size:14px; font-weight:600; line-height:1.3; }
        .dr-veh-sub { font-size:13px; color:var(--ink-3); }

        .dr-contact { display:flex; flex-direction:column; gap:3px; min-width:0; }
        .dr-contact-line { font-size:13.5px; color:var(--ink); text-decoration:none; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .dr-contact-line:hover { color:var(--primary); text-decoration:underline; text-underline-offset:3px; }
        .dr-contact-line.muted { color:var(--ink-3); }

        /* Flat rectangular status chips; colour carries the meaning. */
        .stage { display:inline-flex; align-items:center; font-size:13px; font-weight:700; padding:3px 8px; border-radius:5px; white-space:nowrap; line-height:1.4; }

        /* Deal stages */
        .stage.reserved { background:#E9F3FF; color:#056EE9; }
        .stage.contacted { background:#FFF8DD; color:#7A4E00; }
        .stage.appointment_set { background:#E3EDFB; color:#2E5FA3; }
        .stage.financing { background:#EFE7FB; color:#5B3A9E; }
        .stage.documents { background:#E0F2F4; color:#1F6E78; }
        .stage.ready_for_delivery { background:#DDF5EB; color:#0B7355; }
        .stage.delivered { background:var(--ink); color:#fff; }
        .stage.cancelled { background:#FFEEF3; color:#D81A48; }

        /* Lead statuses */
        .stage.new { background:#E9F3FF; color:#056EE9; }
        .stage.confirmed { background:#DDF5EB; color:#0B7355; }
        .stage.closed { background:rgba(7,20,55,.07); color:var(--ink-2); }

        .dr-action { display:flex; justify-content:flex-end; gap:8px; }
        .advance-btn { font-size:13px; font-weight:700; color:#fff; background:var(--primary); padding:8px 13px; border:1.5px solid var(--primary); border-radius:8px; white-space:nowrap; transition:background .15s ease, border-color .15s ease; }
        .advance-btn:hover { background:var(--primary-press); border-color:var(--primary-press); }
        .advance-btn[disabled] { opacity:.5; cursor:wait; }
        .dr-done { font-size:13px; color:var(--ink-3); font-weight:600; }
        .open-btn { font-size:13px; font-weight:700; color:var(--ink); padding:8px 13px; border:1.5px solid var(--ink); border-radius:8px; white-space:nowrap; text-decoration:none; display:inline-flex; align-items:center; background:#fff; transition:background .15s ease, color .15s ease; }
        .open-btn:hover { background:var(--ink); color:#fff; }

        .deal-empty { color:var(--ink-2); padding:28px 20px; margin:14px 0 0; text-align:center; background:var(--card); border:1.5px dashed var(--line-strong); border-radius:var(--radius); }

        @media (max-width:760px){
            .deal-row { grid-template-columns:1fr; gap:12px; }
            .stage-filter { margin-left:0; }
            .dr-action { justify-content:flex-start; }
        }
    </style>
@endpush

@push('crumb')
    Dealer <span>·</span> <span class="cur">Reservations</span>
@endpush

<div class="inbox">
    <div class="page-head">
        <h1>Reservations</h1>
        <p class="page-sub">Committed buyers with a $150 deposit down. A shopper becomes your customer the moment they pay.</p>
    </div>

        <div class="inbox-toolbar">
            <span class="inbox-count">{{ $reservations->count() }} shown</span>

            <div class="stage-filter">
                <button type="button"
                        class="sf-pill {{ $stageFilter === 'all' ? 'on' : '' }}"
                        wire:click="filterByStage('all')">
                    All <span class="sf-n">{{ array_sum($stageCounts) }}</span>
                </button>

                @foreach (App\Models\Deal::STAGE_LABELS as $stageKey => $stageLabel)
                    <button type="button"
                            class="sf-pill {{ $stageFilter === $stageKey ? 'on' : '' }}"
                            wire:click="filterByStage('{{ $stageKey }}')">
                        {{ $stageLabel }} <span class="sf-n">{{ $stageCounts[$stageKey] }}</span>
                    </button>
                @endforeach
            </div>
        </div>

        <div class="deal-list">
            @forelse ($reservations as $deal)
                <div class="deal-row" wire:key="deal-{{ $deal->id }}">
                    <div class="dr-cust">
                        <span class="dr-name">{{ $deal->customer_full_name }}</span>
                        <span class="dr-ref">{{ $deal->reference }}</span>
                        <span class="dr-meta">{{ ucfirst($deal->purchase_type) }} · {{ $deal->created_at->diffForHumans() }}</span>
                    </div>

                    <div class="dr-veh">
                        <span class="dr-thumb">
                            <svg width="32" height="13" viewBox="0 0 320 130" xmlns="http://www.w3.org/2000/svg"><path d="M20 92 C20 78 34 74 50 72 L74 50 C82 40 96 34 116 34 L196 34 C220 34 236 42 250 58 L286 70 C300 74 304 80 304 92 L304 96 C304 100 300 102 296 102 L24 102 C20 102 20 98 20 96 Z" fill="#1f2227"/><circle cx="92" cy="100" r="20" fill="#111"/><circle cx="240" cy="100" r="20" fill="#111"/></svg>
                        </span>
                        <div>
                            <div class="dr-veh-title">{{ $deal->vehicle->model_year }} {{ $deal->vehicle->make }} {{ $deal->vehicle->model }}</div>
                            <div class="dr-veh-sub">{{ $deal->vehicle->trim }} · ${{ number_format($deal->vehicle->price_in_cents / 100) }}</div>
                        </div>
                    </div>

                    <div class="dr-contact">
                        <a href="mailto:{{ $deal->email }}" class="dr-contact-line">{{ $deal->email }}</a>
                        <a href="tel:{{ $deal->phone }}" class="dr-contact-line muted">{{ $deal->phone }}</a>
                    </div>

                    <div>
                        <span class="stage {{ $deal->stage }}">
                            {{ $deal->stage_label }}
                        </span>
                    </div>

                    <div class="dr-action">
                        <a href="{{ route('dealer.deal', $deal) }}" class="open-btn">Open</a>

                        @if (isset(App\Models\Deal::NEXT_STAGE[$deal->stage]))
                            <button type="button"
                                    class="advance-btn"
                                    wire:click="advanceDeal({{ $deal->id }})"
                                    wire:loading.attr="disabled"
                                    wire:target="advanceDeal({{ $deal->id }})">
                                Mark {{ App\Models\Deal::STAGE_LABELS[App\Models\Deal::NEXT_STAGE[$deal->stage]] }}
                            </button>
                        @else
                            <span class="dr-done">{{ $deal->stage_label }}</span>
                        @endif
                    </div>
                </div>
            @empty
                <p class="deal-empty">No reservations in this view yet. They'll land here the moment a buyer puts $150 down.</p>
            @endforelse
        </div>
</div>
