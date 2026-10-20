<?php

use App\Models\Offer;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
| Dealer portal — Offers (read-only). The current and upcoming offers of the
| dealer's brand, set up by TruCars. They apply to every dealer of the brand
| the same way, so there's nothing to switch on here.
*/
new #[Layout('layouts.dealer')] #[Title('Offers')] class extends Component {
    public function mount(): void
    {
        abort_unless(auth()->user()?->isDealerStaff() && ! auth()->user()->isSuspended(), 403);
    }

    public function offers(): Collection
    {
        $dealer = auth()->user()->dealer;

        if (! $dealer->brand_id) {
            return collect();
        }

        return Offer::query()
            ->where('brand_id', $dealer->brand_id)
            ->where('status', Offer::STATUS_PUBLISHED)
            ->whereDate('ends_on', '>=', now()->toDateString())
            ->with('brand')
            ->orderBy('starts_on')
            ->get()
            ->filter(fn (Offer $offer) => ! $offer->provinces || in_array($dealer->province, $offer->provinces, true))
            ->values();
    }
}; ?>

@push('crumb')
    <span>Marketing</span>
    <span>›</span>
    <span class="cur">Offers</span>
@endpush

@push('styles')
    <style>
        .do-head h1 { font-size:24px; font-weight:800; letter-spacing:-.02em; margin:0 0 6px; }
        .do-head p { margin:0 0 20px; font-size:14px; color:var(--ink-2); max-width:680px; }
        .offers { display:grid; grid-template-columns:repeat(auto-fill, minmax(320px, 1fr)); gap:14px; }
        .oc { background:var(--card); border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow-sm); overflow:hidden; display:flex; flex-direction:column; }
        .oc-top { padding:18px 18px 12px; }
        .oc-k { display:flex; align-items:center; gap:8px; font-size:11px; font-weight:800; letter-spacing:.06em; text-transform:uppercase; color:var(--ink-3); }
        .oc-big { font-size:28px; font-weight:800; letter-spacing:-.03em; margin-top:6px; }
        .oc-t { font-size:14px; font-weight:700; margin-top:2px; }
        .oc-m { font-size:12.5px; color:var(--ink-3); margin-top:6px; line-height:1.5; }
        .oc-fine { font-size:12px; color:var(--ink-2); background:var(--bg); padding:10px 18px; border-top:1px solid var(--line); margin-top:auto; }
        .oc-foot { display:flex; align-items:center; padding:12px 18px; border-top:1px solid var(--line); font-size:12.5px; font-weight:600; color:var(--ink-2); }
        .pill { font-size:10.5px; font-weight:800; letter-spacing:.05em; padding:3px 9px; border-radius:var(--radius-pill); background:var(--bg); border:1px solid var(--line); color:var(--ink-2); }
        .pill.live { background:var(--good-soft); color:var(--good-ink); border-color:transparent; }
        .pill.scheduled { background:#E3EDFB; color:#2E5FA3; border-color:transparent; }
        .empty { background:var(--card); border:1px dashed var(--line-strong); border-radius:var(--radius); padding:26px; text-align:center; font-size:13.5px; color:var(--ink-3); }
    </style>
@endpush

@php $dealer = auth()->user()->dealer; $offers = $this->offers(); @endphp

<div>
    <div class="do-head">
        <h1>{{ $dealer->franchise_make ? $dealer->franchise_make . ' offers' : 'Offers' }}</h1>
        <p>Set up by TruCars for every {{ $dealer->franchise_make ?: 'brand' }} dealer. They show on your eligible cars automatically, only within their dates.</p>
    </div>

    @if (! $dealer->brand_id)
        <div class="empty">Your dealership’s brand hasn’t been set yet. Contact TruCars.</div>
    @elseif ($offers->isEmpty())
        <div class="empty">No {{ $dealer->franchise_make }} offers are running or scheduled right now.</div>
    @else
        <div class="offers">
            @foreach ($offers as $offer)
                <div class="oc" wire:key="o-{{ $offer->id }}">
                    <div class="oc-top">
                        <div class="oc-k">{{ $offer->title }} <span class="pill {{ $offer->state() }}">{{ $offer->state() }}</span></div>
                        <div class="oc-big">{{ $offer->headline() }}</div>
                        @if ($offer->detail()) <div class="oc-t">{{ $offer->detail() }}</div> @endif
                        <div class="oc-m">{{ $offer->models ? implode(', ', $offer->models) : 'All ' . $dealer->franchise_make . ' models' }}{{ $offer->year_min || $offer->year_max ? ' · ' . ($offer->year_min ?? '…') . '–' . ($offer->year_max ?? '…') : '' }}{{ $offer->certified_only ? ' · certified only' : '' }}</div>
                    </div>
                    <div class="oc-fine">{{ $offer->conditions }}</div>
                    <div class="oc-foot">{{ $offer->starts_on->format('M j') }} – {{ $offer->ends_on->format('M j, Y') }}</div>
                </div>
            @endforeach
        </div>
    @endif
</div>
