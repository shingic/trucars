<?php

use App\Models\AuditLog;
use App\Models\Deal;
use App\Models\Dealer;
use App\Models\DealerInvitation;
use App\Models\Scopes\DealerScope;
use App\Models\StaffInvitation;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Permission;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
| Command Centre home: a live view of the marketplace for the owner and
| internal staff. Every panel is shown only to people whose permissions cover
| it; each area it links to enforces its own permission regardless.
*/
new #[Layout('layouts.dealer')] #[Title('Command Centre')] class extends Component {
    private const DAYS = 30;

    public function mount(): void
    {
        abort_unless(auth()->user()?->canUseCommandCentre(), 403);
    }

    private function deals()
    {
        return Deal::withoutGlobalScope(DealerScope::class);
    }

    /** Headline numbers, each with the matching figure for the previous period where it makes sense. */
    public function stats(): array
    {
        $user = auth()->user();
        $now = now();
        $from = $now->copy()->subDays(self::DAYS);
        $priorFrom = $from->copy()->subDays(self::DAYS);
        $stats = [];

        if ($user->can(Permission::RESERVATIONS_VIEW)) {
            $current = $this->deals()->where('created_at', '>=', $from)->count();
            $prior = $this->deals()->whereBetween('created_at', [$priorFrom, $from])->count();
            $open = $this->deals()->whereNotIn('stage', ['delivered', 'cancelled']);

            $stats[] = ['label' => 'Reservations', 'value' => number_format($current), 'sub' => 'last ' . self::DAYS . ' days', 'delta' => $this->delta($current, $prior), 'icon' => 'inbox', 'url' => route('admin.reservations')];
            $stats[] = ['label' => 'Deposits held', 'value' => '$' . number_format((clone $open)->where('deposit_status', 'held')->sum('deposit_in_cents') / 100), 'sub' => (clone $open)->count() . ' open deals', 'delta' => null, 'icon' => 'wallet', 'url' => route('admin.reservations')];
            $stats[] = ['label' => 'Delivered', 'value' => number_format($this->deals()->where('stage', 'delivered')->where('updated_at', '>=', $from)->count()), 'sub' => 'last ' . self::DAYS . ' days', 'delta' => null, 'icon' => 'key', 'url' => route('admin.reservations')];
        }

        if ($user->can(Permission::DEALERS_VIEW)) {
            $active = Dealer::where('status', Dealer::STATUS_ACTIVE)->count();
            $stats[] = ['label' => 'Live listings', 'value' => number_format(Vehicle::listed()->count()), 'sub' => $active . ' active ' . ($active === 1 ? 'dealer' : 'dealers') . ' of ' . Dealer::count(), 'delta' => null, 'icon' => 'car', 'url' => route('admin.dealers')];
        }

        if ($user->can(Permission::CUSTOMERS_VIEW)) {
            $current = User::where('role', User::ROLE_CUSTOMER)->where('created_at', '>=', $from)->count();
            $prior = User::where('role', User::ROLE_CUSTOMER)->whereBetween('created_at', [$priorFrom, $from])->count();
            $stats[] = ['label' => 'New customers', 'value' => number_format($current), 'sub' => 'last ' . self::DAYS . ' days', 'delta' => $this->delta($current, $prior), 'icon' => 'user', 'url' => null];
        }

        return $stats;
    }

    private function delta(int $current, int $prior): ?array
    {
        if ($prior === 0) {
            return $current > 0 ? ['up' => true, 'text' => 'new'] : null;
        }

        $change = (int) round(($current - $prior) / $prior * 100);

        return ['up' => $change >= 0, 'text' => ($change >= 0 ? '+' : '') . $change . '%'];
    }

    /** Reservations per day for the chart, oldest first. */
    public function trend(): Collection
    {
        $start = now()->subDays(self::DAYS - 1)->startOfDay();
        $byDay = $this->deals()->where('created_at', '>=', $start)->pluck('created_at')
            ->countBy(fn ($createdAt) => Carbon::parse($createdAt)->toDateString());

        return collect(range(0, self::DAYS - 1))->map(function (int $offset) use ($start, $byDay) {
            $day = $start->copy()->addDays($offset);

            return ['date' => $day, 'count' => $byDay[$day->toDateString()] ?? 0];
        });
    }

    /** Open deals by stage, in pipeline order. */
    public function pipeline(): Collection
    {
        $counts = $this->deals()->whereNotIn('stage', ['delivered', 'cancelled'])
            ->selectRaw('stage, count(*) as total')->groupBy('stage')->pluck('total', 'stage');

        return collect(Deal::STAGE_LABELS)
            ->except(['delivered', 'cancelled'])
            ->map(fn ($label, $stage) => ['label' => $label, 'count' => (int) ($counts[$stage] ?? 0)]);
    }

    /** Things someone should act on, most urgent first. */
    public function attention(): array
    {
        $user = auth()->user();
        $items = [];

        if ($user->can(Permission::RESERVATIONS_VIEW)) {
            $waiting = $this->deals()->where('stage', 'reserved')->where('created_at', '<', now()->subDay())->count();
            if ($waiting) {
                $items[] = ['tone' => 'bad', 'title' => $waiting . ' ' . ($waiting === 1 ? 'reservation' : 'reservations') . ' not contacted', 'detail' => 'Waiting more than 24 hours for the dealer to reach out.', 'url' => route('admin.reservations')];
            }
        }

        if ($user->can(Permission::DEALERS_VIEW)) {
            $missing = Dealer::missingAddress()->count();
            if ($missing) {
                $items[] = ['tone' => 'warn', 'title' => $missing . ' ' . ($missing === 1 ? 'dealer needs' : 'dealers need') . ' an address', 'detail' => 'Their cars can’t be published until it’s complete.', 'url' => route('admin.dealers')];
            }

            $drafts = Dealer::where('status', Dealer::STATUS_DRAFT)->count();
            if ($drafts) {
                $items[] = ['tone' => 'warn', 'title' => $drafts . ' draft ' . ($drafts === 1 ? 'dealer' : 'dealers'), 'detail' => 'Not live on the marketplace yet.', 'url' => route('admin.dealers')];
            }

            $review = Vehicle::where('is_published', false)->whereHas('dealer', fn ($dealer) => $dealer->where('status', Dealer::STATUS_ACTIVE))->count();
            if ($review) {
                $items[] = ['tone' => 'info', 'title' => $review . ' unpublished ' . ($review === 1 ? 'car' : 'cars') . ' at live dealers', 'detail' => 'New from the feed or taken down — review and publish.', 'url' => route('admin.dealers')];
            }

            $dealerInvites = DealerInvitation::open()->count();
            if ($dealerInvites) {
                $items[] = ['tone' => 'info', 'title' => $dealerInvites . ' dealer ' . ($dealerInvites === 1 ? 'invitation' : 'invitations') . ' pending', 'detail' => 'Sent but not accepted yet.', 'url' => route('admin.dealers')];
            }
        }

        if ($user->can(Permission::STAFF_MANAGE)) {
            $staffInvites = StaffInvitation::open()->count();
            if ($staffInvites) {
                $items[] = ['tone' => 'info', 'title' => $staffInvites . ' staff ' . ($staffInvites === 1 ? 'invitation' : 'invitations') . ' pending', 'detail' => 'Sent but not accepted yet.', 'url' => route('admin.staff')];
            }
        }

        return $items;
    }

    public function recentReservations(): Collection
    {
        return $this->deals()->with(['dealer', 'vehicle'])->latest()->limit(6)->get();
    }

    /** Dealers ranked by reservations in the period, with their live listings. */
    public function topDealers(): Collection
    {
        $from = now()->subDays(self::DAYS);

        return Dealer::query()
            ->withCount([
                'vehicles as live_count' => fn ($vehicles) => $vehicles->where('is_published', true),
                'deals as recent_deals' => fn ($deals) => $deals->withoutGlobalScope(DealerScope::class)->where('created_at', '>=', $from),
            ])
            ->where('status', Dealer::STATUS_ACTIVE)
            ->orderByDesc('recent_deals')
            ->orderByDesc('live_count')
            ->limit(5)
            ->get();
    }

    public function activity(): Collection
    {
        return AuditLog::query()->with('actor')->latest('id')->limit(7)->get();
    }

    public function greeting(): string
    {
        $hour = (int) now(config('app.timezone'))->format('G');

        return match (true) {
            $hour < 12 => 'Good morning',
            $hour < 18 => 'Good afternoon',
            default    => 'Good evening',
        };
    }
}; ?>

@push('crumb')
    <span class="cur">Command Centre</span>
@endpush

@push('styles')
    <style>
        .cc-top { display:flex; align-items:flex-end; gap:16px; margin-bottom:24px; flex-wrap:wrap; }
        .cc-top h1 { font-family:var(--font-display); font-size:36px; font-weight:700; line-height:1; text-transform:uppercase; margin:0 0 8px; }
        .cc-top p { margin:0; font-size:15px; color:var(--ink-2); }
        .cc-top .spacer { flex:1; }
        .cc-actions { display:flex; gap:8px; flex-wrap:wrap; }
        .qa { display:inline-flex; align-items:center; gap:7px; font-size:14px; font-weight:700; padding:9px 15px; border-radius:8px; border:1.5px solid var(--ink); background:#fff; color:var(--ink); transition:background .15s ease, color .15s ease; }
        .qa:hover { background:var(--ink); color:#fff; }
        .qa.primary { background:var(--primary); border-color:var(--primary); color:#fff; font-family:var(--font-display); font-size:16px; text-transform:uppercase; }
        .qa.primary:hover { background:var(--primary-press); border-color:var(--primary-press); color:#fff; }
        .qa svg { width:15px; height:15px; }

        /* headline stats: a ruled strip, cells divided by 1px lines */
        .kpis { display:grid; grid-template-columns:repeat(auto-fit, minmax(190px, 1fr)); border-top:2px solid var(--ink); border-bottom:1px solid var(--line-strong); margin-bottom:34px; }
        .kpi { position:relative; padding:14px 18px 16px; display:block; border-left:1px solid var(--line-strong); margin-left:-1px; transition:background .15s ease; }
        a.kpi:hover { background:#fff; }
        a.kpi:hover .val { color:var(--primary-press); }
        .kpi .lbl { font-family:var(--font-display); font-size:15px; font-weight:600; text-transform:uppercase; color:var(--ink-2); }
        .kpi .val { font-family:var(--font-display); font-size:44px; font-weight:700; line-height:1; color:var(--ink); margin:8px 0 6px; font-variant-numeric:tabular-nums; }
        .kpi .sub { font-size:13px; color:var(--ink-3); display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
        .delta { font-size:13px; font-weight:700; padding:1px 6px; border-radius:5px; }
        .delta.up { background:var(--good-soft); color:#0B7355; }
        .delta.down { background:#FFEEF3; color:#D81A48; }

        .cc-grid { display:grid; grid-template-columns:minmax(0, 1.7fr) minmax(0, 1fr); gap:20px 32px; margin-bottom:34px; }
        .panel { border-top:2px solid var(--ink); min-width:0; }
        .panel-head { display:flex; align-items:flex-end; gap:10px; padding:12px 0 12px; }
        .panel-head .t { font-family:var(--font-display); font-size:22px; font-weight:700; line-height:1.05; text-transform:uppercase; }
        .panel-head .s { font-size:13px; color:var(--ink-3); margin-top:3px; }
        .panel-head .spacer { flex:1; }
        .panel-head a { font-size:14px; font-weight:700; color:var(--primary-press); text-decoration:underline; text-underline-offset:3px; white-space:nowrap; }
        .panel-head a:hover { color:var(--ink); }

        .chart { padding:6px 0 8px; }
        .bars { display:flex; align-items:flex-end; gap:3px; height:150px; border-bottom:2px solid var(--ink); }
        .bar { flex:1; min-width:3px; border-radius:0; background:var(--line-strong); position:relative; transition:background .12s ease; }
        .bar.has { background:var(--primary); }
        .bar:hover { background:var(--ink); }
        .bar .tip { display:none; position:absolute; bottom:calc(100% + 6px); left:50%; transform:translateX(-50%); background:var(--ink); color:#fff; font-size:13px; font-weight:700; padding:4px 8px; border-radius:8px; white-space:nowrap; z-index:2; box-shadow:var(--shadow-md); }
        .bar:hover .tip { display:block; }
        .axis { display:flex; justify-content:space-between; font-size:13px; color:var(--ink-3); padding:6px 0 4px; }

        .pipe { padding:14px 0 16px; display:grid; gap:10px; border-top:1px solid var(--line-strong); margin-top:8px; }
        .pipe-row { display:grid; grid-template-columns:130px minmax(0, 1fr) 40px; gap:12px; align-items:center; font-size:14px; }
        .pipe-row .n { color:var(--ink-2); font-weight:600; }
        .pipe-row .track { height:10px; background:var(--line); border-radius:0; overflow:hidden; }
        .pipe-row .fill { height:100%; background:var(--ink); border-radius:0; }
        .pipe-row:first-child .fill { background:var(--primary); }
        .pipe-row .c { text-align:right; font-family:var(--font-display); font-size:18px; font-weight:700; line-height:1; font-variant-numeric:tabular-nums; }

        .att { display:flex; gap:12px; align-items:flex-start; padding:12px 6px; border-top:1px solid var(--line-strong); }
        a.att:hover { background:#fff; }
        .att .dot { display:block; flex-shrink:0; padding-top:2px; }
        .att .dot svg { display:block; width:18px; height:18px; }
        .att.bad .dot { color:#D81A48; }
        .att.warn .dot { color:#C59A00; }
        .att.info .dot { color:var(--ink-3); }
        .att .t { font-size:15px; font-weight:700; }
        .att .d { font-size:13px; color:var(--ink-3); margin-top:1px; }
        .all-clear { display:flex; gap:10px; align-items:center; padding:18px 6px; border-top:1px solid var(--line-strong); font-size:15px; color:#0B7355; font-weight:600; }
        .all-clear svg { width:20px; height:20px; flex-shrink:0; }

        .res-row { display:grid; grid-template-columns:minmax(0, 1.3fr) minmax(0, 1fr) 120px; gap:12px; align-items:center; padding:11px 6px; border-top:1px solid var(--line-strong); font-size:14px; }
        a.res-row:hover { background:#fff; }
        .res-row .who { font-weight:700; }
        .meta { font-size:13px; color:var(--ink-3); margin-top:1px; }
        .stage { justify-self:end; font-family:var(--font-display); font-size:13px; font-weight:600; text-transform:uppercase; padding:2px 8px; border-radius:5px; background:var(--bg-2, #F9F9F9); border:1px solid var(--line-strong); color:var(--ink-2); white-space:nowrap; }
        .stage.reserved { background:var(--primary-soft); border-color:transparent; color:var(--primary-press); }
        .stage.delivered { background:var(--good-soft); border-color:transparent; color:#0B7355; }

        .rank { display:grid; grid-template-columns:26px minmax(0, 1fr) auto; gap:10px; align-items:center; padding:11px 6px; border-top:1px solid var(--line-strong); font-size:14px; }
        a.rank:hover { background:#fff; }
        .rank .pos { font-family:var(--font-display); font-size:20px; font-weight:700; line-height:1; color:var(--ink-3); text-align:center; }
        .rank:first-of-type .pos { color:var(--primary); }
        .rank .nm { font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .rank .num { text-align:right; font-family:var(--font-display); font-size:22px; font-weight:700; line-height:1; font-variant-numeric:tabular-nums; }

        .act { display:flex; gap:11px; padding:10px 6px; border-top:1px solid var(--line-strong); font-size:14px; }
        .act .av { width:30px; height:30px; border-radius:8px; background:var(--ink); color:#fff; display:grid; place-items:center; font-size:12px; font-weight:700; flex-shrink:0; }
        .act b { font-weight:700; }
        .empty { padding:16px 6px; border-top:1px solid var(--line-strong); font-size:14px; color:var(--ink-3); }

        @media (max-width:1100px) { .cc-grid { grid-template-columns:1fr; } }
        @media (max-width:640px) {
            .cc-top h1 { font-size:30px; }
            .kpis { grid-template-columns:repeat(2, minmax(0, 1fr)); }
            .kpi { padding:12px 12px 14px; }
            .kpi .val { font-size:34px; }
            .res-row { grid-template-columns:minmax(0, 1fr) auto; }
            .res-row .hide-sm { display:none; }
            .pipe-row { grid-template-columns:100px minmax(0, 1fr) 34px; }
        }
    </style>
@endpush

@php
    $user = auth()->user();
    $icons = [
        'inbox'  => '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
        'wallet' => '<rect x="2" y="6" width="20" height="14" rx="2"/><path d="M2 10h20M16 15h2"/>',
        'key'    => '<circle cx="7.5" cy="15.5" r="4.5"/><path d="m10.7 12.3 9.3-9.3M17 6l3 3M14 9l2 2"/>',
        'car'    => '<path d="M5 17h14M6 17l1.5-6h9L18 17M7 21v-2M17 21v-2"/><circle cx="8" cy="14" r="1"/><circle cx="16" cy="14" r="1"/>',
        'user'   => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
    ];
    $toneIcons = [
        'bad'  => '<circle cx="12" cy="12" r="9"/><path d="M12 7v6M12 16.5v.5"/>',
        'warn' => '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4M12 17v.01"/>',
        'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8v.01"/>',
    ];
    $stats = $this->stats();
    $attention = $this->attention();
@endphp

<div>
    <div class="cc-top">
        <div>
            <h1>{{ $this->greeting() }}, {{ \Illuminate\Support\Str::before($user->name, ' ') }}</h1>
            <p>{{ now(config('app.timezone'))->format('l, F j') }} · here’s how TruCars is doing.</p>
        </div>
        <div class="spacer"></div>
        <div class="cc-actions">
            @can('dealers.manage')
                <a href="{{ route('admin.dealers.create') }}" wire:navigate class="qa primary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                    Add dealer
                </a>
            @endcan
            @can('staff.manage')
                <a href="{{ route('admin.staff') }}" wire:navigate class="qa">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="4"/><path d="M2 21a7 7 0 0 1 14 0M19 8v6M16 11h6"/></svg>
                    Invite staff
                </a>
            @endcan
            @can('customers.view')
                <a href="{{ route('admin.customers') }}" wire:navigate class="qa">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
                    Find a customer
                </a>
            @endcan
        </div>
    </div>

    @if ($stats !== [])
        <div class="kpis">
            @foreach ($stats as $stat)
                <{{ $stat['url'] ? 'a' : 'div' }} class="kpi" @if ($stat['url']) href="{{ $stat['url'] }}" wire:navigate @endif>
                    <div class="lbl">{{ $stat['label'] }}</div>
                    <div class="val">{{ $stat['value'] }}</div>
                    <div class="sub">
                        @if ($stat['delta']) <span class="delta {{ $stat['delta']['up'] ? 'up' : 'down' }}">{{ $stat['delta']['text'] }}</span> @endif
                        {{ $stat['sub'] }}
                    </div>
                </{{ $stat['url'] ? 'a' : 'div' }}>
            @endforeach
        </div>
    @endif

    <div class="cc-grid">
        @can('reservations.view')
            @php $trend = $this->trend(); $peak = max(1, $trend->max('count')); $pipeline = $this->pipeline(); $pipeMax = max(1, $pipeline->max('count')); @endphp
            <div class="panel">
                <div class="panel-head">
                    <div>
                        <div class="t">Reservations</div>
                        <div class="s">Per day, last 30 days · {{ $trend->sum('count') }} total</div>
                    </div>
                    <div class="spacer"></div>
                    <a href="{{ route('admin.reservations') }}" wire:navigate>View all →</a>
                </div>
                <div class="chart">
                    <div class="bars">
                        @foreach ($trend as $day)
                            <div class="bar {{ $day['count'] ? 'has' : '' }}" style="height:{{ $day['count'] ? max(6, round($day['count'] / $peak * 100)) : 3 }}%">
                                <span class="tip">{{ $day['date']->format('M j') }} · {{ $day['count'] }}</span>
                            </div>
                        @endforeach
                    </div>
                    <div class="axis"><span>{{ $trend->first()['date']->format('M j') }}</span><span>{{ $trend->get(14)['date']->format('M j') }}</span><span>Today</span></div>
                </div>
                <div class="pipe">
                    @foreach ($pipeline as $stage)
                        <div class="pipe-row">
                            <span class="n">{{ $stage['label'] }}</span>
                            <span class="track"><span class="fill" style="display:block; width:{{ round($stage['count'] / $pipeMax * 100) }}%"></span></span>
                            <span class="c">{{ $stage['count'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endcan

        <div class="panel">
            <div class="panel-head">
                <div>
                    <div class="t">Needs attention</div>
                    <div class="s">{{ count($attention) ? count($attention) . ' to look at' : 'Nothing waiting' }}</div>
                </div>
            </div>
            @forelse ($attention as $item)
                <a class="att {{ $item['tone'] }}" href="{{ $item['url'] }}" wire:navigate>
                    <span class="dot"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">{!! $toneIcons[$item['tone']] !!}</svg></span>
                    <span><div class="t">{{ $item['title'] }}</div><div class="d">{{ $item['detail'] }}</div></span>
                </a>
            @empty
                <div class="all-clear">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/></svg>
                    All clear — nothing needs you right now.
                </div>
            @endforelse
        </div>
    </div>

    <div class="cc-grid">
        @can('reservations.view')
            <div class="panel">
                <div class="panel-head">
                    <div class="t">Latest reservations</div>
                    <div class="spacer"></div>
                    <a href="{{ route('admin.reservations') }}" wire:navigate>All reservations →</a>
                </div>
                @forelse ($this->recentReservations() as $deal)
                    <a class="res-row" href="{{ route('admin.reservations.show', $deal) }}" wire:navigate>
                        <span>
                            <div class="who">{{ $deal->first_name }} {{ $deal->last_name }}</div>
                            <div class="meta">{{ $deal->created_at->diffForHumans() }} · {{ $deal->dealer?->name }}</div>
                        </span>
                        <span class="hide-sm">
                            <div>{{ $deal->vehicle ? "{$deal->vehicle->model_year} {$deal->vehicle->make} {$deal->vehicle->model}" : '—' }}</div>
                            <div class="meta">{{ $deal->vehicle?->display_price }}</div>
                        </span>
                        <span class="stage {{ $deal->stage }}">{{ $deal->stage_label }}</span>
                    </a>
                @empty
                    <div class="empty">No reservations yet.</div>
                @endforelse
            </div>
        @endcan

        @can('dealers.view')
            <div class="panel">
                <div class="panel-head">
                    <div>
                        <div class="t">Top dealers</div>
                        <div class="s">By reservations, last 30 days</div>
                    </div>
                    <div class="spacer"></div>
                    <a href="{{ route('admin.dealers') }}" wire:navigate>All dealers →</a>
                </div>
                @forelse ($this->topDealers() as $dealer)
                    <a class="rank" href="{{ route('admin.dealers.edit', $dealer) }}" wire:navigate>
                        <span class="pos">{{ $loop->iteration }}</span>
                        <span>
                            <div class="nm">{{ $dealer->name }}</div>
                            <div class="meta">{{ $dealer->live_count }} live {{ $dealer->live_count === 1 ? 'car' : 'cars' }} · {{ $dealer->display_location }}</div>
                        </span>
                        <span class="num">{{ $dealer->recent_deals }}</span>
                    </a>
                @empty
                    <div class="empty">No live dealers yet.</div>
                @endforelse
            </div>
        @endcan
    </div>

    @can('audit.view')
        <div class="panel" style="max-width:none">
            <div class="panel-head">
                <div class="t">Recent activity</div>
                <div class="spacer"></div>
                <a href="{{ route('admin.audit') }}" wire:navigate>Audit log →</a>
            </div>
            @forelse ($this->activity() as $entry)
                <div class="act">
                    @php $actorName = $entry->actor?->name ?? $entry->actor_email ?? 'System'; @endphp
                    <span class="av">{{ collect(explode(' ', $actorName))->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('') }}</span>
                    <span>
                        <b>{{ $actorName }}</b>
                        {{ str_replace(['.', '_'], ' ', $entry->action) }}
                        @if ($entry->target_label) · <b>{{ $entry->target_label }}</b> @endif
                        <div class="meta">{{ $entry->created_at->diffForHumans() }}</div>
                    </span>
                </div>
            @empty
                <div class="empty">No activity yet.</div>
            @endforelse
        </div>
    @endcan
</div>
