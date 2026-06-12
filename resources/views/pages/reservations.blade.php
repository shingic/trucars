<?php

use App\Models\Deal;
use App\Models\Lead;
use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\Auth;

new #[Layout('layouts.dealer')] class extends Component {
    public string $activeTab = 'reservations';
    public string $stageFilter = 'all';
    public string $statusFilter = 'all';

    public array $leadStatusLabels = [
        'new'       => 'New inquiry',
        'contacted' => 'Contacted',
        'confirmed' => 'Confirmed',
        'closed'    => 'Closed',
    ];

    public array $leadFilterOrder = ['new', 'contacted', 'confirmed', 'closed'];

    public array $leadNextStage = [
        'new'       => 'contacted',
        'contacted' => 'confirmed',
        'confirmed' => 'closed',
    ];

    public function switchTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->stageFilter = 'all';
        $this->statusFilter = 'all';
    }

    public function filterByStage(string $stage): void
    {
        $this->stageFilter = $stage;
    }

    public function filterByStatus(string $status): void
    {
        $this->statusFilter = $status;
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

    public function advanceLead(int $leadId): void
    {
        $lead = Lead::findOrFail($leadId);

        $nextStatus = $this->leadNextStage[$lead->status] ?? null;

        if ($nextStatus) {
            $lead->update(['status' => $nextStatus]);
        }
    }

    #[Computed]
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

    #[Computed]
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

    #[Computed]
    public function leads()
    {
        return Lead::query()
            ->with('vehicle')
            ->when(
                $this->statusFilter !== 'all',
                fn ($query) => $query->where('status', $this->statusFilter),
            )
            ->latest()
            ->get();
    }

    #[Computed]
    public function statusCounts(): array
    {
        $countedByStatus = Lead::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();

        $emptyTally = ['new' => 0, 'contacted' => 0, 'confirmed' => 0, 'closed' => 0];

        return array_merge($emptyTally, $countedByStatus);
    }
}; ?>

@push('styles')
    <style>
        .inbox { max-width:1180px; }

        .page-head { margin-bottom:18px; }
        .page-head h1 { font-size:25px; font-weight:800; letter-spacing:-.025em; margin:0; }
        .page-sub { color:var(--ink-2); font-size:14px; margin:5px 0 0; }

        .inbox-tabs { display:flex; gap:4px; border-bottom:1.5px solid var(--line); margin-bottom:18px; }
        .tab-btn { font-size:14px; font-weight:700; color:var(--ink-3); padding:10px 16px 12px; border-bottom:2.5px solid transparent; margin-bottom:-1.5px; transition:color .15s ease; }
        .tab-btn:hover { color:var(--ink); }
        .tab-btn.on { color:var(--ink); border-bottom-color:var(--primary); }
        .tab-n { font-size:11.5px; font-weight:700; color:var(--ink-3); background:rgba(22,24,29,.06); padding:2px 8px; border-radius:999px; margin-left:6px; }
        .tab-btn.on .tab-n { background:var(--primary); color:#fff; }

        .inbox-toolbar { display:flex; align-items:center; gap:14px; margin-bottom:16px; flex-wrap:wrap; }
        .inbox-count { font-size:13px; color:var(--ink-3); font-weight:600; }
        .stage-filter { margin-left:auto; display:flex; gap:7px; flex-wrap:wrap; }
        .sf-pill { font-size:12.5px; font-weight:600; color:var(--ink-2); padding:7px 13px; border:1.5px solid var(--line-strong); border-radius:var(--radius-pill); transition:all .15s ease; display:inline-flex; align-items:center; gap:7px; background:var(--card); }
        .sf-pill:hover { border-color:var(--primary); }
        .sf-pill.on { background:var(--btn); color:#fff; border-color:var(--btn); }
        .sf-n { font-size:11px; opacity:.7; }

        .deal-list { display:flex; flex-direction:column; gap:10px; }
        .deal-row { background:var(--card); border:1px solid var(--line); border-radius:var(--radius); padding:16px 20px; display:grid; grid-template-columns:1.3fr 1.7fr 1.5fr 1fr auto; gap:18px; align-items:center; transition:box-shadow .15s ease, border-color .15s ease, transform .1s ease; }
        .deal-row:hover { box-shadow:var(--shadow-md); border-color:transparent; transform:translateY(-1px); }

        .dr-cust { display:flex; flex-direction:column; gap:2px; }
        .dr-name { font-weight:700; font-size:14.5px; }
        .dr-meta { font-size:12px; color:var(--ink-3); }
        .dr-ref { font-family:'Geist Mono', monospace; font-size:11.5px; color:var(--ink-2); font-weight:600; }

        .dr-veh { display:flex; align-items:center; gap:11px; }
        .dr-thumb { width:46px; height:36px; border-radius:8px; background:linear-gradient(155deg,#FF8A3D,#F5631F 70%); display:grid; place-items:center; flex-shrink:0; }
        .dr-thumb svg { filter:drop-shadow(0 3px 4px rgba(0,0,0,.25)); }
        .dr-veh-title { font-size:13.5px; font-weight:600; line-height:1.3; }
        .dr-veh-sub { font-size:11.5px; color:var(--ink-3); }

        .dr-contact { display:flex; flex-direction:column; gap:3px; min-width:0; }
        .dr-contact-line { font-size:12.5px; color:var(--ink); text-decoration:none; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .dr-contact-line:hover { color:var(--primary); }
        .dr-contact-line.muted { color:var(--ink-3); }

        .stage { display:inline-flex; align-items:center; gap:6px; font-size:12px; font-weight:700; padding:5px 11px; border-radius:var(--radius-pill); white-space:nowrap; }
        .stage .dot { width:7px; height:7px; border-radius:50%; }

        /* Deal stages */
        .stage.reserved { background:#FDEAD9; color:#B5611A; } .stage.reserved .dot { background:#F5631F; }
        .stage.contacted { background:#FCF3D7; color:#9A7B1B; } .stage.contacted .dot { background:#E3B53A; }
        .stage.appointment_set { background:#E3EDFB; color:#2E5FA3; } .stage.appointment_set .dot { background:#4A82D6; }
        .stage.financing { background:#EFE7FB; color:#6B47B5; } .stage.financing .dot { background:#8B5FE0; }
        .stage.documents { background:#E0F2F4; color:#1F7A85; } .stage.documents .dot { background:#2BA3B2; }
        .stage.ready_for_delivery { background:#DDF5EB; color:#0E8A60; } .stage.ready_for_delivery .dot { background:#12B886; }
        .stage.delivered { background:rgba(22,24,29,.07); color:var(--ink-2); } .stage.delivered .dot { background:var(--ink-3); }
        .stage.cancelled { background:#FBE4E4; color:#B23A3A; } .stage.cancelled .dot { background:#D65454; }

        /* Lead statuses */
        .stage.new { background:#FDEAD9; color:#B5611A; } .stage.new .dot { background:#F5631F; }
        .stage.confirmed { background:#DDF5EB; color:#0E8A60; } .stage.confirmed .dot { background:#12B886; }
        .stage.closed { background:rgba(22,24,29,.07); color:var(--ink-2); } .stage.closed .dot { background:var(--ink-3); }

        .dr-action { display:flex; justify-content:flex-end; gap:8px; }
        .advance-btn { font-size:12.5px; font-weight:700; color:var(--ink); padding:8px 15px; border:1.5px solid var(--line-strong); border-radius:var(--radius-pill); white-space:nowrap; transition:all .15s ease; }
        .advance-btn:hover { background:var(--primary); border-color:var(--primary); color:#fff; }
        .advance-btn[disabled] { opacity:.5; cursor:wait; }
        .dr-done { font-size:12px; color:var(--ink-3); font-weight:600; }
        .open-btn { font-size:12.5px; font-weight:700; color:var(--ink-2); padding:8px 15px; border:1.5px solid var(--line); border-radius:var(--radius-pill); white-space:nowrap; text-decoration:none; display:inline-flex; align-items:center; transition:all .15s ease; }
        .open-btn:hover { border-color:var(--ink); color:var(--ink); }

        .deal-empty { color:var(--ink-2); padding:34px 20px; text-align:center; background:var(--card); border:1px dashed var(--line-strong); border-radius:var(--radius); }

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
        <h1>Reservations &amp; inquiries</h1>
        <p class="page-sub">Reservations are committed buyers with a deposit down. Inquiries are everything else.</p>
    </div>

    <div class="inbox-tabs">
        <button type="button" class="tab-btn {{ $activeTab === 'reservations' ? 'on' : '' }}" wire:click="switchTab('reservations')">
            Reservations <span class="tab-n">{{ array_sum($this->stageCounts) }}</span>
        </button>
        <button type="button" class="tab-btn {{ $activeTab === 'inquiries' ? 'on' : '' }}" wire:click="switchTab('inquiries')">
            Inquiries <span class="tab-n">{{ array_sum($this->statusCounts) }}</span>
        </button>
    </div>

    @if ($activeTab === 'reservations')
        <div class="inbox-toolbar">
            <span class="inbox-count">{{ $this->deals->count() }} shown</span>

            <div class="stage-filter">
                <button type="button"
                        class="sf-pill {{ $stageFilter === 'all' ? 'on' : '' }}"
                        wire:click="filterByStage('all')">
                    All <span class="sf-n">{{ array_sum($this->stageCounts) }}</span>
                </button>

                @foreach (App\Models\Deal::STAGE_LABELS as $stageKey => $stageLabel)
                    <button type="button"
                            class="sf-pill {{ $stageFilter === $stageKey ? 'on' : '' }}"
                            wire:click="filterByStage('{{ $stageKey }}')">
                        {{ $stageLabel }} <span class="sf-n">{{ $this->stageCounts[$stageKey] }}</span>
                    </button>
                @endforeach
            </div>
        </div>

        <div class="deal-list">
            @forelse ($this->deals as $deal)
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
                            <span class="dot"></span> {{ $deal->stage_label }}
                        </span>
                    </div>

                    <div class="dr-action">
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
    @else
        <div class="inbox-toolbar">
            <span class="inbox-count">{{ $this->leads->count() }} shown</span>

            <div class="stage-filter">
                <button type="button"
                        class="sf-pill {{ $statusFilter === 'all' ? 'on' : '' }}"
                        wire:click="filterByStatus('all')">
                    All <span class="sf-n">{{ array_sum($this->statusCounts) }}</span>
                </button>

                @foreach ($leadFilterOrder as $statusKey)
                    <button type="button"
                            class="sf-pill {{ $statusFilter === $statusKey ? 'on' : '' }}"
                            wire:click="filterByStatus('{{ $statusKey }}')">
                        {{ $leadStatusLabels[$statusKey] }} <span class="sf-n">{{ $this->statusCounts[$statusKey] }}</span>
                    </button>
                @endforeach
            </div>
        </div>

        <div class="deal-list">
            @forelse ($this->leads as $lead)
                <div class="deal-row" wire:key="lead-{{ $lead->id }}">
                    <div class="dr-cust">
                        <span class="dr-name">{{ $lead->name }}</span>
                        <span class="dr-meta">#{{ $lead->id }} · {{ $lead->created_at->diffForHumans() }}</span>
                    </div>

                    <div class="dr-veh">
                        <span class="dr-thumb">
                            <svg width="32" height="13" viewBox="0 0 320 130" xmlns="http://www.w3.org/2000/svg"><path d="M20 92 C20 78 34 74 50 72 L74 50 C82 40 96 34 116 34 L196 34 C220 34 236 42 250 58 L286 70 C300 74 304 80 304 92 L304 96 C304 100 300 102 296 102 L24 102 C20 102 20 98 20 96 Z" fill="#1f2227"/><circle cx="92" cy="100" r="20" fill="#111"/><circle cx="240" cy="100" r="20" fill="#111"/></svg>
                        </span>
                        <div>
                            @if ($lead->vehicle)
                                <div class="dr-veh-title">{{ $lead->vehicle->model_year }} {{ $lead->vehicle->make }} {{ $lead->vehicle->model }}</div>
                                <div class="dr-veh-sub">{{ $lead->vehicle->trim }} · ${{ number_format($lead->vehicle->price_in_cents / 100) }}</div>
                            @else
                                <div class="dr-veh-title">General inquiry</div>
                                <div class="dr-veh-sub">No vehicle attached</div>
                            @endif
                        </div>
                    </div>

                    <div class="dr-contact">
                        <a href="mailto:{{ $lead->email }}" class="dr-contact-line">{{ $lead->email }}</a>
                        <a href="tel:{{ $lead->phone }}" class="dr-contact-line muted">{{ $lead->phone }}</a>
                    </div>

                    <div>
                        <span class="stage {{ $lead->status }}">
                            <span class="dot"></span> {{ $leadStatusLabels[$lead->status] ?? ucfirst($lead->status) }}
                        </span>
                    </div>

                    <div class="dr-action">
                        <a href="{{ route('dealer.lead', $lead) }}" class="open-btn">Open</a>

                        @if (isset($leadNextStage[$lead->status]))
                            <button type="button"
                                    class="advance-btn"
                                    wire:click="advanceLead({{ $lead->id }})"
                                    wire:loading.attr="disabled"
                                    wire:target="advanceLead({{ $lead->id }})">
                                Mark {{ $leadStatusLabels[$leadNextStage[$lead->status]] }}
                            </button>
                        @else
                            <span class="dr-done">Closed</span>
                        @endif
                    </div>
                </div>
            @empty
                <p class="deal-empty">No inquiries in this view yet.</p>
            @endforelse
        </div>
    @endif
</div>
