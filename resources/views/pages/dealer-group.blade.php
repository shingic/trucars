<?php

use App\Models\Deal;
use App\Models\Rooftop;
use App\Models\Scopes\DealerScope;
use App\Models\Vehicle;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
| Dealer portal — All stores. A read-only roll-up of every store this staff
| member can see, across the dealers of their group. Only granted stores are
| counted: DealerScope is lifted for the cross-dealer totals and replaced by
| an explicit whereIn on the staff member's own store list.
*/
new #[Layout('layouts.dealer')] #[Title('All stores')] class extends Component {
    public function mount(): void
    {
        abort_unless(auth()->user()?->isDealerStaff() && ! auth()->user()->isSuspended(), 403);
    }

    /** @return Collection<int, array<string, mixed>> one row per store */
    public function stores(): Collection
    {
        $storeIds = auth()->user()->accessibleRooftopIds();
        $deals = fn () => Deal::withoutGlobalScope(DealerScope::class)->whereIn('rooftop_id', $storeIds);

        $live = Vehicle::query()->listed()->whereIn('rooftop_id', $storeIds)
            ->selectRaw('rooftop_id, count(*) as total')->groupBy('rooftop_id')->pluck('total', 'rooftop_id');
        $open = $deals()->whereNotIn('stage', ['cancelled', 'delivered'])
            ->selectRaw('rooftop_id, count(*) as total')->groupBy('rooftop_id')->pluck('total', 'rooftop_id');
        $thisWeek = $deals()->where('created_at', '>=', now()->subDays(7))
            ->selectRaw('rooftop_id, count(*) as total')->groupBy('rooftop_id')->pluck('total', 'rooftop_id');
        $delivered = $deals()->where('stage', 'delivered')
            ->selectRaw('rooftop_id, count(*) as total')->groupBy('rooftop_id')->pluck('total', 'rooftop_id');
        $held = $deals()->where('stage', '!=', 'cancelled')->where('deposit_status', 'held')
            ->selectRaw('rooftop_id, sum(deposit_in_cents) as total')->groupBy('rooftop_id')->pluck('total', 'rooftop_id');

        return Rooftop::with('dealer:id,name')->whereKey($storeIds)->get()
            ->sortBy(fn (Rooftop $store) => [$store->dealer->name, ! $store->is_primary, $store->display_name])
            ->map(fn (Rooftop $store) => [
                'store'     => $store,
                'live'      => (int) ($live[$store->id] ?? 0),
                'open'      => (int) ($open[$store->id] ?? 0),
                'thisWeek'  => (int) ($thisWeek[$store->id] ?? 0),
                'delivered' => (int) ($delivered[$store->id] ?? 0),
                'held'      => (int) ($held[$store->id] ?? 0),
            ])
            ->values();
    }
}; ?>

@push('crumb')
    <span>Desk</span>
    <span>›</span>
    <span class="cur">All stores</span>
@endpush

@push('styles')
    <style>
        .dg-head h1 { font-size:24px; font-weight:800; letter-spacing:-.02em; margin:0 0 6px; }
        .dg-head p { margin:0 0 20px; font-size:14px; color:var(--ink-2); max-width:680px; }
        .dg-table { width:100%; border-collapse:collapse; background:var(--card); border:1px solid var(--line); border-radius:var(--radius); overflow:hidden; box-shadow:var(--shadow-sm); }
        .dg-table th { text-align:left; font-size:11px; font-weight:800; letter-spacing:.06em; text-transform:uppercase; color:var(--ink-3); padding:12px 16px; border-bottom:1px solid var(--line); }
        .dg-table td { padding:13px 16px; border-bottom:1px solid var(--line); font-size:14px; }
        .dg-table tr:last-child td { border-bottom:none; }
        .dg-table .num { text-align:right; font-variant-numeric:tabular-nums; }
        .dg-table .nm { font-weight:700; } .dg-table .sub { font-size:12.5px; color:var(--ink-3); }
        .dg-table tfoot td { font-weight:800; background:var(--bg); }
        .dg-wrap { overflow-x:auto; }
    </style>
@endpush

@php $rows = $this->stores(); @endphp

<div>
    <div class="dg-head">
        <h1>All stores</h1>
        <p>Every store you have access to, side by side. Pick a dealership in the sidebar to work on its reservations and inventory.</p>
    </div>

    <div class="dg-wrap">
        <table class="dg-table">
            <thead>
                <tr>
                    <th>Store</th>
                    <th class="num">Live cars</th>
                    <th class="num">Open reservations</th>
                    <th class="num">New this week</th>
                    <th class="num">Delivered</th>
                    <th class="num">Deposits held</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr wire:key="store-{{ $row['store']->id }}">
                        <td>
                            <div class="nm">{{ ucwords(strtolower($row['store']->display_name)) }}</div>
                            <div class="sub">{{ ucwords(strtolower($row['store']->dealer->name)) }}{{ $row['store']->display_location ? ' · ' . $row['store']->display_location : '' }}{{ $row['store']->is_active ? '' : ' · Closed' }}</div>
                        </td>
                        <td class="num">{{ $row['live'] }}</td>
                        <td class="num">{{ $row['open'] }}</td>
                        <td class="num">{{ $row['thisWeek'] }}</td>
                        <td class="num">{{ $row['delivered'] }}</td>
                        <td class="num">${{ number_format($row['held'] / 100) }}</td>
                    </tr>
                @endforeach
            </tbody>
            @if ($rows->count() > 1)
                <tfoot>
                    <tr>
                        <td>All {{ $rows->count() }} stores</td>
                        <td class="num">{{ $rows->sum('live') }}</td>
                        <td class="num">{{ $rows->sum('open') }}</td>
                        <td class="num">{{ $rows->sum('thisWeek') }}</td>
                        <td class="num">{{ $rows->sum('delivered') }}</td>
                        <td class="num">${{ number_format($rows->sum('held') / 100) }}</td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
</div>
