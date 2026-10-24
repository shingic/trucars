<?php

use App\Models\Brand;
use App\Models\Offer;
use App\Support\Permission;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
| Settings › Brands › Offers & incentives (settings.manage). A brand's offers
| apply to its eligible cars at every one of its dealers, on and off by date.
*/
new #[Layout('layouts.dealer')] #[Title('Offers & incentives')] class extends Component {
    #[Locked]
    public int $brandId;

    #[Url(except: 'current')]
    public string $tab = 'current';

    public function mount(Brand $brand): void
    {
        Gate::authorize(Permission::SETTINGS_MANAGE);
        $this->brandId = $brand->id;
    }

    public function with(): array
    {
        $brand = Brand::findOrFail($this->brandId);

        $groups = $brand->offers()->orderBy('starts_on')->get()
            ->groupBy(fn (Offer $offer) => match ($offer->state()) {
                'live', 'scheduled' => 'current',
                'draft'             => 'drafts',
                default             => 'past',
            });

        return ['brand' => $brand, 'groups' => $groups];
    }
}; ?>

@push('crumb')
    <a href="{{ route('admin.settings') }}" wire:navigate>Platform settings</a>
    <span>›</span>
    <a href="{{ route('admin.brands') }}" wire:navigate>Brands</a>
    <span>›</span>
    <a href="{{ route('admin.brands.show', $brandId) }}" wire:navigate>{{ $brand->name }}</a>
    <span>›</span>
    <span class="cur">Offers</span>
@endpush

@push('styles')
    <style>
        .ob-top { display:flex; align-items:center; gap:10px; margin-bottom:16px; flex-wrap:wrap; }
        .ob-top .spacer { flex:1; }
        .seg { display:inline-flex; flex-wrap:wrap; border:1.5px solid var(--ink); border-radius:8px; overflow:hidden; background:#fff; }
        .seg button { display:inline-flex; align-items:center; gap:6px; padding:8px 14px; font-size:14px; font-weight:700; color:var(--ink); border-right:1.5px solid var(--ink); }
        .seg button:last-child { border-right:none; }
        .seg button:hover { background:var(--bg); }
        .seg button.on { background:var(--ink); color:#fff; }
        .seg button:focus-visible { outline:2px solid var(--primary); outline-offset:-3px; }
        .seg .n { font-family:var(--font-display); font-size:14px; font-weight:600; color:var(--ink-3); }
        .seg button.on .n { color:var(--primary-bright); }
        .btn { display:inline-flex; align-items:center; gap:7px; font-family:var(--font-display); font-size:15px; font-weight:700; text-transform:uppercase; border-radius:8px; padding:10px 18px; background:var(--primary); color:#fff; }
        .btn:hover { background:var(--primary-press); }
        .btn svg { width:15px; height:15px; }
        .card { border-top:2px solid var(--ink); }
        .of-row { display:grid; grid-template-columns:120px minmax(0, 1.6fr) 190px 100px 20px; gap:16px; align-items:center; padding:13px 6px; border-bottom:1px solid var(--line-strong); text-decoration:none; }
        .of-row:hover { background:#fff; }
        .of-row:hover .nm { text-decoration:underline; text-underline-offset:3px; }
        .big { font-family:var(--font-display); font-size:22px; font-weight:700; line-height:1; color:var(--ink); }
        .nm { font-size:15px; font-weight:700; }
        .meta { font-size:13px; color:var(--ink-3); margin-top:2px; }
        .dates { font-size:14px; font-weight:600; color:var(--ink-2); }
        .pill { justify-self:start; font-family:var(--font-display); font-size:13px; font-weight:600; text-transform:uppercase; padding:2px 8px; border-radius:5px; background:#fff; border:1px solid var(--line-strong); color:var(--ink-2); }
        .pill.live { background:#E6F8EC; color:#0B7355; border-color:transparent; }
        .pill.scheduled { background:var(--primary-soft); color:var(--primary-press); border-color:transparent; }
        .go { width:16px; height:16px; color:var(--ink-3); }
        .empty { margin-top:14px; padding:22px 20px; border:1.5px dashed var(--line-strong); border-radius:8px; color:var(--ink-2); font-size:14px; }
        @media (max-width:860px) { .of-row { grid-template-columns:96px minmax(0, 1fr) 16px; } .of-row .hide-sm { display:none; } }
    </style>
@endpush

<div>
    @include('partials.brand-header', ['brand' => $brand, 'activeTab' => 'offers'])

    <div class="ob-top">
        <div class="seg">
            @foreach (['current' => 'Live & scheduled', 'drafts' => 'Drafts', 'past' => 'Past'] as $key => $label)
                <button type="button" class="{{ $tab === $key ? 'on' : '' }}" wire:click="$set('tab', '{{ $key }}')">{{ $label }} <span class="n">{{ ($groups[$key] ?? collect())->count() }}</span></button>
            @endforeach
        </div>
        <div class="spacer"></div>
        <a href="{{ route('admin.brands.offers.create', $brand) }}" wire:navigate class="btn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
            New {{ $brand->name }} offer
        </a>
    </div>

    <div class="card">
        @forelse ($groups[$tab] ?? [] as $offer)
            <a class="of-row" href="{{ route('admin.brands.offers.edit', [$brand, $offer]) }}" wire:navigate wire:key="o-{{ $offer->id }}">
                <span class="big">{{ $offer->headline() }}</span>
                <span>
                    <div class="nm">{{ $offer->title }}</div>
                    <div class="meta">{{ $offer->models ? implode(', ', $offer->models) : 'All ' . $brand->name . ' models' }}{{ $offer->year_min || $offer->year_max ? ' · ' . ($offer->year_min ?? '…') . '–' . ($offer->year_max ?? '…') : '' }}{{ $offer->provinces ? ' · ' . implode(', ', $offer->provinces) : '' }}{{ $offer->certified_only ? ' · certified only' : '' }}</div>
                </span>
                <span class="dates hide-sm">{{ $offer->starts_on->format('M j') }} – {{ $offer->ends_on->format('M j, Y') }}</span>
                <span class="pill {{ $offer->state() }} hide-sm">{{ $offer->state() }}</span>
                <svg class="go" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="m9 6 6 6-6 6"/></svg>
            </a>
        @empty
            <div class="empty">
                @switch($tab)
                    @case('current') No {{ $brand->name }} offers running or scheduled. Add next month’s ahead of time — they switch on by themselves. @break
                    @case('drafts') No drafts. @break
                    @default Nothing yet.
                @endswitch
            </div>
        @endforelse
    </div>
</div>
