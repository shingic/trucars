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
        $dealer = auth()->user()->portalDealer();

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
        .do-head h1 { font-size:34px; font-weight:700; text-transform:uppercase; line-height:1; letter-spacing:0; margin:0 0 10px; }
        .do-head p { margin:0 0 22px; font-size:14.5px; color:var(--ink-2); max-width:680px; }
        .offers { display:grid; grid-template-columns:repeat(auto-fill, minmax(min(320px, 100%), 1fr)); gap:24px 20px; }
        .oc { border-top:2px solid var(--ink); display:flex; flex-direction:column; }
        .oc-top { padding:12px 0 12px; }
        .oc-k { display:flex; align-items:center; flex-wrap:wrap; gap:8px; font-size:14px; font-weight:700; color:var(--ink-2); }
        .oc-big { font-family:var(--font-display); font-size:34px; font-weight:700; line-height:1; letter-spacing:0; color:var(--ink); margin-top:8px; }
        .oc-t { font-size:15px; font-weight:700; margin-top:4px; }
        .oc-m { font-size:13.5px; color:var(--ink-3); margin-top:6px; line-height:1.5; }
        .oc-fine { font-size:13px; color:var(--ink-2); padding:10px 0; border-top:1px solid var(--line-strong); margin-top:auto; }
        .oc-foot { display:flex; align-items:center; padding:10px 0; border-top:1px solid var(--line-strong); border-bottom:1px solid var(--line-strong); font-family:var(--font-display); font-size:15px; font-weight:700; text-transform:uppercase; color:var(--ink); }
        .pill { font-family:var(--font-display); font-size:13px; font-weight:700; text-transform:uppercase; letter-spacing:.02em; padding:1px 8px; border-radius:2px; background:var(--bg-2); border:1px solid var(--line-strong); color:var(--ink-2); }
        .pill.live { background:#E5F8EC; color:#0B7355; border-color:transparent; }
        .pill.scheduled { background:var(--primary-soft); color:var(--primary-press); border-color:transparent; }
        .empty { border:1.5px dashed var(--line-strong); border-radius:3px; padding:18px 20px; font-size:14.5px; color:var(--ink-2); }
    </style>
@endpush

@php $dealer = auth()->user()->portalDealer(); $offers = $this->offers(); @endphp

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
