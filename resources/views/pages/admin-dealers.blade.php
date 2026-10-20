<?php

use App\Models\Dealer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
| TruCars admin — every dealer record, with the ones that still need an address
| (and so can't publish cars) flagged. Needs dealers.view (route `can:` middleware, mount, and every action).
*/
new #[Layout('layouts.dealer')] #[Title('Dealers')] class extends Component {
    public bool $onlyNeedingAddress = false;

    public function mount(): void
    {
        // Route middleware already requires dealers.view; checked again here so
        // the page never depends on how it was reached.
        \Illuminate\Support\Facades\Gate::authorize('dealers.browse');
    }

    #[Computed]
    public function dealers(): Collection
    {
        return Dealer::query()
            ->withCount(['vehicles', 'vehicles as published_vehicles_count' => fn (Builder $query) => $query->where('is_published', true)])
            ->when($this->onlyNeedingAddress, fn (Builder $query) => $query->missingAddress())
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function needingAddressCount(): int
    {
        return Dealer::query()->missingAddress()->count();
    }
}; ?>

@push('crumb')
    <span>Command Centre</span>
    <span>›</span>
    <span class="cur">Dealers</span>
@endpush

@push('styles')
    <style>
        .ad-head { display:flex; align-items:flex-start; gap:20px; margin-bottom:22px; }
        .ad-head h1 { font-size:24px; font-weight:800; letter-spacing:-.02em; margin:0 0 6px; }
        .ad-head p { margin:0; font-size:14px; color:var(--ink-2); max-width:640px; }
        .ad-head .spacer { flex:1; }

        .btn { display:inline-block; font-size:14px; font-weight:700; border-radius:var(--radius-pill); padding:10px 18px; transition:all .15s ease; white-space:nowrap; }
        .btn-primary { background:var(--primary); color:#fff; box-shadow:0 8px 20px rgba(245,99,31,.24); }
        .btn-primary:hover { background:var(--primary-press); }

        .ad-alert { display:flex; align-items:center; gap:10px; background:var(--amber-soft); color:#7A4E0A; border:1px solid rgba(216,150,40,.3); border-radius:var(--radius-sm); padding:11px 15px; font-size:13.5px; font-weight:600; margin-bottom:20px; }
        .ad-alert label { margin-left:auto; display:flex; align-items:center; gap:7px; cursor:pointer; white-space:nowrap; }

        .card { background:var(--card); border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow-sm); }
        .ad-row { display:grid; grid-template-columns:minmax(0, 1.5fr) minmax(0, 1.4fr) 150px 110px 44px; gap:16px; align-items:center; padding:14px 20px; border-bottom:1px solid var(--line); }
        .ad-row:last-child { border-bottom:none; }
        .ad-row.head { font-size:11px; font-weight:700; letter-spacing:.05em; text-transform:uppercase; color:var(--ink-3); padding-top:11px; padding-bottom:11px; }
        .ad-row .nm { font-size:14px; font-weight:700; }
        .ad-row .meta { font-size:12px; color:var(--ink-3); margin-top:2px; }
        .ad-row .miss { font-size:12px; color:var(--amber); font-weight:600; margin-top:3px; }
        .ad-row .num { font-family:var(--mono); font-size:13.5px; }
        .ad-row { transition:background .12s ease; }
        .ad-row:not(.head):hover { background:var(--bg); }
        .ad-row:last-child { border-radius:0 0 var(--radius) var(--radius); }
        .ad-row .nm a:hover { color:var(--primary-press); }

        .kebab { position:relative; justify-self:end; }
        .kebab > button { width:34px; height:34px; display:grid; place-items:center; border:1px solid transparent; border-radius:10px; background:none; color:var(--ink-2); cursor:pointer; transition:all .12s ease; }
        .kebab > button:hover, .kebab.open > button { background:var(--card); border-color:var(--line); color:var(--ink); box-shadow:var(--shadow-sm); }
        .kebab svg { width:18px; height:18px; flex-shrink:0; }
        .kebab-menu { position:absolute; right:0; top:calc(100% + 6px); z-index:30; min-width:220px; background:var(--card); border:1px solid var(--line); border-radius:12px; box-shadow:0 14px 34px rgba(22,24,29,.14); padding:6px; }
        .kebab-menu a { display:flex; align-items:center; gap:11px; padding:9px 10px; border-radius:8px; font-size:13.5px; font-weight:600; color:var(--ink); }
        .kebab-menu a svg { width:16px; height:16px; color:var(--ink-3); }
        .kebab-menu a:hover { background:var(--primary-soft); color:var(--primary-press); }
        .kebab-menu a:hover svg { color:var(--primary); }
        .kebab-menu .count { margin-left:auto; font-family:var(--mono); font-size:11.5px; font-weight:700; color:var(--ink-3); background:var(--bg); border-radius:var(--radius-pill); padding:1px 8px; }
        .kebab-menu hr { border:none; border-top:1px solid var(--line); margin:5px 4px; }
        .pill { display:inline-block; font-size:10.5px; font-weight:800; letter-spacing:.04em; text-transform:uppercase; padding:3px 9px; border-radius:var(--radius-pill); }
        .pill-active { background:var(--good-soft); color:var(--good-ink); }
        .pill-draft { background:var(--bg); color:var(--ink-2); border:1px solid var(--line); }
        .pill-suspended { background:var(--primary-soft); color:var(--primary-press); }
        .ad-empty { padding:20px; font-size:13.5px; color:var(--ink-3); }

        @media (max-width:860px){
            .ad-head { flex-direction:column; }
            .ad-row { grid-template-columns:1fr 1fr; }
            .ad-row.head { display:none; }
            .ad-row > :first-child, .ad-row > :nth-child(2) { grid-column:1 / -1; }
            .ad-row { position:relative; }
            .ad-row .nm { padding-right:40px; }
            .kebab { position:absolute; top:10px; right:14px; }
        }
    </style>
@endpush

<div>
    <div class="ad-head">
        <div>
            <h1>Dealers</h1>
            <p>One record per selling rooftop at one physical address. Every vehicle on a dealer is at that address, so a dealer can't publish cars until its address is complete.</p>
        </div>
        <div class="spacer"></div>
        @can('dealers.manage')
            <a href="{{ route('admin.dealers.create') }}" wire:navigate class="btn btn-primary">+ New dealer</a>
        @endcan
    </div>

    @if ($this->needingAddressCount > 0)
        <div class="ad-alert">
            <span>{{ $this->needingAddressCount }} {{ $this->needingAddressCount === 1 ? 'dealer needs' : 'dealers need' }} an address backfill before {{ $this->needingAddressCount === 1 ? 'it' : 'they' }} can publish vehicles.</span>
            <label><input type="checkbox" wire:model.live="onlyNeedingAddress"> Show only these</label>
        </div>
    @endif

    <div class="card">
        <div class="ad-row head">
            <span>Dealer</span><span>Address</span><span>Timezone</span><span>Status</span><span></span>
        </div>

        @forelse ($this->dealers as $dealer)
            <div class="ad-row" wire:key="dealer-{{ $dealer->id }}">
                <div>
                    <div class="nm"><a href="{{ auth()->user()->can('dealers.view') ? route('admin.dealers.edit', $dealer) : route('admin.dealers.customers', $dealer) }}" wire:navigate>{{ $dealer->name }}</a></div>
                    <div class="meta">
                        #{{ $dealer->id }} · {{ $dealer->published_vehicles_count }} published / {{ $dealer->vehicles_count }} vehicles
                        @if ($dealer->franchise_make) · {{ $dealer->franchise_make }} @endif
                    </div>
                </div>
                <div>
                    <div class="meta" style="color:var(--ink-2)">{{ implode(' · ', $dealer->address_lines) ?: ($dealer->display_location ?? '—') }}</div>
                    @if (! $dealer->hasCompleteAddress())
                        <div class="miss">Missing: {{ implode(', ', array_map(fn ($field) => str_replace('_', ' ', $field), $dealer->missingAddressFields())) }}</div>
                    @endif
                </div>
                <div class="meta" style="color:{{ $dealer->timezone ? 'var(--ink-2)' : 'var(--amber)' }}">{{ $dealer->timezone ?? 'Not set' }}</div>
                <div><span class="pill pill-{{ $dealer->status }}">{{ \App\Models\Dealer::STATUS_LABELS[$dealer->status] ?? $dealer->status }}</span></div>
                <div class="kebab" x-data="{ open: false }" :class="{ open }" @click.outside="open = false" @keydown.escape.window="open = false">
                    <button type="button" @click="open = ! open" :aria-expanded="open" aria-haspopup="menu" aria-label="Actions for {{ $dealer->name }}">
                        <svg viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="12" cy="19" r="1.8"/></svg>
                    </button>
                    <div class="kebab-menu" role="menu" x-show="open" x-transition.origin.top.right x-cloak>
                        @can('dealers.view')
                        <a role="menuitem" href="{{ route('admin.dealers.edit', $dealer) }}" wire:navigate>
                            @can('dealers.manage')
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                                Edit profile
                            @else
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                                View profile
                            @endcan
                        </a>
                        <a role="menuitem" href="{{ route('admin.dealers.inventory', $dealer) }}" wire:navigate>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 17h14M6 17l1.5-6h9L18 17M7 21v-2M17 21v-2"/><circle cx="8" cy="14" r="1"/><circle cx="16" cy="14" r="1"/></svg>
                            Inventory
                            <span class="count">{{ $dealer->vehicles_count }}</span>
                        </a>
                        @endcan
                        @can('customers.view')
                            <a role="menuitem" href="{{ route('admin.dealers.customers', $dealer) }}" wire:navigate>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>
                                Customers
                            </a>
                        @endcan
                        @can('dealers.view')
                        <a role="menuitem" href="{{ route('admin.dealers.inspection', $dealer) }}" wire:navigate>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M9 4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2a1 1 0 0 1-1 1h-4a1 1 0 0 1-1-1Z"/><path d="m9 14 2 2 4-4"/></svg>
                            Inspection checklist
                        </a>
                        <a role="menuitem" href="{{ route('admin.dealers.edit', $dealer) }}#users">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                            Dealer users
                        </a>
                        @endcan
                        @if ($dealer->website)
                            <hr>
                            <a role="menuitem" href="{{ str_starts_with($dealer->website, 'http') ? $dealer->website : 'https://'.$dealer->website }}" target="_blank" rel="noopener">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/></svg>
                                Dealer website
                            </a>
                        @endif
                    </div>
                </div>
            </div>
        @empty
            <div class="ad-empty">{{ $onlyNeedingAddress ? 'Every dealer has a complete address.' : 'No dealers yet.' }}</div>
        @endforelse
    </div>
</div>
