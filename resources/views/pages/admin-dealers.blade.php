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
        .ad-head { display:flex; align-items:flex-end; gap:20px; margin-bottom:22px; }
        .ad-head h1 { font-family:var(--font-display); font-size:40px; font-weight:700; line-height:1; text-transform:uppercase; letter-spacing:0; margin:0 0 10px; color:var(--ink); }
        .ad-head p { margin:0; font-size:15px; color:var(--ink-2); max-width:640px; }
        .ad-head .spacer { flex:1; }

        .btn { display:inline-block; font-family:var(--font-display); font-size:15px; font-weight:600; text-transform:uppercase; letter-spacing:.02em; border-radius:8px; padding:10px 18px; border:1.5px solid transparent; transition:background .15s ease; white-space:nowrap; }
        .btn-primary { background:var(--primary); border-color:var(--primary); color:#fff; box-shadow:none; }
        .btn-primary:hover { background:var(--primary-press); border-color:var(--primary-press); }
        .btn:focus-visible { outline:2px solid var(--primary); outline-offset:2px; }

        .ad-alert { display:flex; align-items:center; flex-wrap:wrap; gap:10px; background:#FFF8DD; color:#5C3D00; border-left:3px solid #C59A00; border-radius:5px; padding:11px 15px; font-size:14px; font-weight:600; margin-bottom:22px; }
        .ad-alert label { margin-left:auto; display:flex; align-items:center; gap:7px; cursor:pointer; white-space:nowrap; }
        .ad-alert input[type=checkbox] { accent-color:var(--ink); width:16px; height:16px; }

        .card { background:transparent; border:none; border-radius:0; box-shadow:none; }
        .ad-row { display:grid; grid-template-columns:minmax(0, 1.5fr) minmax(0, 1.4fr) 150px 110px 44px; gap:16px; align-items:center; padding:13px 8px; border-bottom:1px solid var(--line-strong); }
        .ad-row.head { font-family:var(--font-display); font-size:14px; font-weight:600; text-transform:uppercase; color:var(--ink-3); padding-top:8px; padding-bottom:8px; border-bottom:2px solid var(--ink); }
        .ad-row .nm { font-size:15px; font-weight:700; color:var(--ink); }
        .ad-row .meta { font-size:13px; color:var(--ink-3); margin-top:2px; }
        .ad-row .miss { font-size:13px; color:#7A4E00; font-weight:600; margin-top:3px; }
        .ad-row .num { font-family:var(--mono); font-size:13.5px; }
        .ad-row { transition:background .12s ease; }
        .ad-row:not(.head):hover { background:#fff; }
        .ad-row .nm a:hover { color:var(--primary-press); text-decoration:underline; text-underline-offset:3px; }

        .kebab { position:relative; justify-self:end; }
        .kebab > button { width:34px; height:34px; display:grid; place-items:center; border:1.5px solid transparent; border-radius:8px; background:none; color:var(--ink-2); cursor:pointer; transition:background .12s ease, color .12s ease, border-color .12s ease; }
        .kebab > button:hover, .kebab.open > button { background:#fff; border-color:var(--ink); color:var(--ink); }
        .kebab > button:focus-visible { outline:2px solid var(--primary); outline-offset:2px; }
        .kebab svg { width:18px; height:18px; flex-shrink:0; }
        .kebab-menu { position:absolute; right:0; top:calc(100% + 4px); z-index:30; min-width:220px; background:#fff; border:1px solid var(--line-strong); border-radius:10px; box-shadow:var(--shadow-md); padding:4px; }
        .kebab-menu a { display:flex; align-items:center; gap:11px; padding:9px 10px; border-radius:5px; font-size:14px; font-weight:600; color:var(--ink); }
        .kebab-menu a svg { width:16px; height:16px; color:var(--ink-3); }
        .kebab-menu a:hover { background:var(--bg-2); color:var(--ink); }
        .kebab-menu a:hover svg { color:var(--ink); }
        .kebab-menu .count { margin-left:auto; font-family:var(--font-display); font-size:13px; font-weight:600; color:var(--ink-2); background:var(--bg-2); border:1px solid var(--line-strong); border-radius:5px; padding:0 6px; }
        .kebab-menu hr { border:none; border-top:1px solid var(--line-strong); margin:4px 0; }
        .pill { display:inline-block; font-family:var(--font-display); font-size:13px; font-weight:600; text-transform:uppercase; padding:2px 7px; border-radius:5px; }
        .pill-active { background:var(--good-soft); color:#0B7355; }
        .pill-draft { background:#FFF8DD; color:#7A4E00; }
        .pill-suspended { background:#FFEEF3; color:#D81A48; }
        .ad-empty { margin-top:14px; padding:18px 16px; font-size:14px; color:var(--ink-2); border:1.5px dashed var(--line-strong); border-radius:8px; }

        @media (max-width:860px){
            .ad-head { flex-direction:column; align-items:flex-start; }
            .ad-head h1 { font-size:32px; }
            .ad-row { grid-template-columns:1fr 1fr; padding-left:0; padding-right:0; }
            .ad-row.head { display:none; }
            .card { border-top:2px solid var(--ink); }
            .ad-row > :first-child, .ad-row > :nth-child(2) { grid-column:1 / -1; }
            .ad-row { position:relative; }
            .ad-row .nm { padding-right:40px; }
            .kebab { position:absolute; top:10px; right:0; }
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
                <div class="meta" style="color:{{ $dealer->timezone ? 'var(--ink-2)' : '#7A4E00' }}">{{ $dealer->timezone ?? 'Not set' }}</div>
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
