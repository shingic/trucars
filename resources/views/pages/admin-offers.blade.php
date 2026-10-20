<?php

use App\Models\Offer;
use App\Support\Permission;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
| Command Centre — Offers & Incentives (offers.manage). Manufacturer offers are
| created here once and reach every participating dealer's eligible cars;
| dealer-funded promotions arrive here for approval.
*/
new #[Layout('layouts.dealer')] #[Title('Offers & Incentives')] class extends Component {
    #[Url(except: 'live')]
    public string $tab = 'live';

    public function mount(): void
    {
        Gate::authorize(Permission::OFFERS_MANAGE);
    }

    public function offers(): Collection
    {
        return Offer::query()->with('dealer')->withCount('participants')->orderBy('starts_on')->orderBy('make')->get()
            ->groupBy(fn (Offer $offer) => match ($offer->state()) {
                'live'      => 'live',
                'scheduled' => 'scheduled',
                'pending'   => 'review',
                'draft'     => 'drafts',
                default     => 'ended',
            });
    }
}; ?>

@push('crumb')
    <span>Command Centre</span>
    <span>›</span>
    <span class="cur">Offers &amp; Incentives</span>
@endpush

@push('styles')
    <style>
        .of-head { display:flex; align-items:flex-end; gap:16px; margin-bottom:18px; flex-wrap:wrap; }
        .of-head h1 { font-size:24px; font-weight:800; letter-spacing:-.02em; margin:0 0 6px; }
        .of-head p { margin:0; font-size:14px; color:var(--ink-2); max-width:640px; }
        .of-head .spacer { flex:1; }
        .btn { display:inline-flex; align-items:center; gap:7px; font-size:14px; font-weight:700; border-radius:var(--radius-pill); padding:10px 18px; background:var(--primary); color:#fff; box-shadow:0 8px 20px rgba(245,99,31,.22); }
        .btn:hover { background:var(--primary-press); }
        .btn svg { width:15px; height:15px; }
        .tabs { display:flex; gap:6px; margin-bottom:16px; border-bottom:1px solid var(--line); overflow-x:auto; }
        .tabs button { display:inline-flex; align-items:center; gap:7px; padding:9px 14px; font-size:14px; font-weight:600; color:var(--ink-2); border-bottom:2px solid transparent; margin-bottom:-1px; white-space:nowrap; }
        .tabs button.on { color:var(--ink); border-bottom-color:var(--primary); }
        .tabs .n { font-size:11px; font-weight:800; padding:1px 7px; border-radius:var(--radius-pill); background:var(--bg); border:1px solid var(--line); color:var(--ink-2); }
        .tabs .n.hot { background:var(--primary); border-color:var(--primary); color:#fff; }
        .card { background:var(--card); border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow-sm); overflow:hidden; }
        .of-row { display:grid; grid-template-columns:110px minmax(0, 1.6fr) minmax(0, 1.2fr) 170px 20px; gap:16px; align-items:center; padding:14px 20px; border-bottom:1px solid var(--line); text-decoration:none; }
        .of-row:last-child { border-bottom:none; }
        .of-row:hover { background:var(--bg); }
        .big { font-size:19px; font-weight:800; letter-spacing:-.02em; }
        .big.apr { color:var(--primary-press); }
        .nm { font-size:14px; font-weight:700; }
        .meta { font-size:12px; color:var(--ink-3); margin-top:2px; }
        .src { display:inline-flex; align-items:center; gap:6px; font-size:12px; font-weight:700; color:var(--ink-2); }
        .src svg { width:14px; height:14px; color:var(--ink-3); }
        .dates { font-size:12.5px; font-weight:600; }
        .go { width:16px; height:16px; color:var(--ink-3); }
        .empty { padding:30px 20px; text-align:center; color:var(--ink-3); font-size:13.5px; }
        @media (max-width:860px) { .of-row { grid-template-columns:90px minmax(0, 1fr) 16px; } .of-row .hide-sm { display:none; } }
    </style>
@endpush

@php
    $groups = $this->offers();
    $tabs = ['live' => 'Live now', 'scheduled' => 'Scheduled', 'review' => 'Needs review', 'drafts' => 'Drafts', 'ended' => 'Ended'];
    $rows = $groups[$tab] ?? collect();
@endphp

<div>
    <div class="of-head">
        <div>
            <h1>Offers &amp; Incentives</h1>
            <p>Manufacturer programs are set up once here and appear on every eligible car at participating dealers — on and off by their dates. Dealer promotions come here for approval.</p>
        </div>
        <div class="spacer"></div>
        <a href="{{ route('admin.offers.create') }}" wire:navigate class="btn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
            New manufacturer offer
        </a>
    </div>

    <nav class="tabs">
        @foreach ($tabs as $key => $label)
            <button type="button" class="{{ $tab === $key ? 'on' : '' }}" wire:click="$set('tab', '{{ $key }}')">
                {{ $label }} <span class="n {{ $key === 'review' && ($groups[$key] ?? collect())->isNotEmpty() ? 'hot' : '' }}">{{ ($groups[$key] ?? collect())->count() }}</span>
            </button>
        @endforeach
    </nav>

    <div class="card">
        @forelse ($rows as $offer)
            <a class="of-row" href="{{ route('admin.offers.edit', $offer) }}" wire:navigate wire:key="o-{{ $offer->id }}">
                <span class="big {{ $offer->type }}">{{ $offer->headline() }}</span>
                <span>
                    <div class="nm">{{ $offer->make }} · {{ $offer->title }}</div>
                    <div class="meta">{{ $offer->models ? implode(', ', $offer->models) : 'All models' }}{{ $offer->year_min || $offer->year_max ? ' · ' . ($offer->year_min ?? '…') . '–' . ($offer->year_max ?? '…') : '' }}{{ $offer->provinces ? ' · ' . implode(', ', $offer->provinces) : '' }}</div>
                </span>
                <span class="hide-sm">
                    @if ($offer->isManufacturer())
                        <span class="src"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3 4 6v6c0 5 8 9 8 9s8-4 8-9V6Z"/></svg> Manufacturer</span>
                        <div class="meta">{{ $offer->requires_opt_in ? $offer->participants_count . ' ' . ($offer->participants_count === 1 ? 'dealer' : 'dealers') . ' opted in' : 'All ' . $offer->make . ' dealers' }}</div>
                    @else
                        <span class="src"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18M5 21V8l7-4 7 4v13M9 21v-6h6v6"/></svg> Dealer promotion</span>
                        <div class="meta">{{ $offer->dealer?->name }}</div>
                    @endif
                </span>
                <span class="dates hide-sm">{{ $offer->starts_on->format('M j') }} – {{ $offer->ends_on->format('M j, Y') }}</span>
                <svg class="go" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="m9 6 6 6-6 6"/></svg>
            </a>
        @empty
            <div class="empty">
                @switch($tab)
                    @case('live') No offers are live today. @break
                    @case('scheduled') Nothing scheduled. Add next month’s programs ahead of time and they switch on by themselves. @break
                    @case('review') No dealer promotions waiting for review. @break
                    @case('drafts') No drafts. @break
                    @default Nothing here yet.
                @endswitch
            </div>
        @endforelse
    </div>
</div>
