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
        \Illuminate\Support\Facades\Gate::authorize(\App\Support\Permission::DEALERS_VIEW);
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

        .card { background:var(--card); border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow-sm); overflow:hidden; }
        .ad-row { display:grid; grid-template-columns:minmax(0, 1.5fr) minmax(0, 1.4fr) 150px 110px 80px; gap:16px; align-items:center; padding:14px 20px; border-bottom:1px solid var(--line); }
        .ad-row:last-child { border-bottom:none; }
        .ad-row.head { font-size:11px; font-weight:700; letter-spacing:.05em; text-transform:uppercase; color:var(--ink-3); padding-top:11px; padding-bottom:11px; }
        .ad-row .nm { font-size:14px; font-weight:700; }
        .ad-row .meta { font-size:12px; color:var(--ink-3); margin-top:2px; }
        .ad-row .miss { font-size:12px; color:var(--amber); font-weight:600; margin-top:3px; }
        .ad-row .num { font-family:var(--mono); font-size:13.5px; }
        .ad-row .edit { font-size:13px; font-weight:700; color:var(--primary); text-align:right; }
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
                    <div class="nm">{{ $dealer->name }}</div>
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
                <a href="{{ route('admin.dealers.edit', $dealer) }}" wire:navigate class="edit">@can('dealers.manage') Edit → @else View → @endcan</a>
            </div>
        @empty
            <div class="ad-empty">{{ $onlyNeedingAddress ? 'Every dealer has a complete address.' : 'No dealers yet.' }}</div>
        @endforelse
    </div>
</div>
