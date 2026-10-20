{{--
    One dealer's inventory table, import form and bulk actions. Rendered by both
    the Command Centre and the dealer portal inventory pages, whose component
    uses App\Livewire\Concerns\ManagesDealerInventory. Expects the view data
    from inventoryViewData(): $inventoryDealer, $canManage, $counts, $vehicles.
--}}
<style>
        .iv-note { font-size:13px; color:var(--ink-2); margin:-8px 0 16px; max-width:760px; }
        .msg { border-radius:var(--radius-sm); padding:11px 15px; font-size:13.5px; font-weight:600; margin-bottom:16px; }
        .msg.ok { background:var(--good-soft); color:var(--good-ink); border:1px solid rgba(18,184,134,.25); }
        .msg.bad { background:var(--primary-soft); color:var(--primary-press); border:1px solid var(--primary-line); }
        .output { background:var(--card); border:1px solid var(--line); border-radius:var(--radius-sm); padding:12px 14px; font-family:var(--mono); font-size:12px; white-space:pre-wrap; margin-bottom:16px; color:var(--ink-2); }
        .card { background:var(--card); border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow-sm); overflow:hidden; }
        .import { padding:14px 18px; display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:16px; }
        .import .lbl { font-size:13.5px; font-weight:700; }
        .import .opt { font-size:12.5px; color:var(--ink-2); display:flex; gap:6px; align-items:center; flex-basis:100%; }
        .import .hint { font-size:12px; color:var(--ink-3); flex-basis:100%; }
        .btn { font-size:13px; font-weight:700; border-radius:var(--radius-pill); padding:8px 15px; white-space:nowrap; }
        .btn-primary { background:var(--primary); color:#fff; }
        .btn-ghost { border:1px solid var(--line-strong); color:var(--ink-2); }
        .btn:disabled { opacity:.5; }
        .filters { display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-bottom:12px; }
        .chip { border:1px solid var(--line-strong); border-radius:var(--radius-pill); padding:6px 12px; font-size:12.5px; font-weight:600; color:var(--ink-2); }
        .chip.on { border-color:var(--primary); background:var(--primary-soft); color:var(--primary-press); }
        .filters input { margin-left:auto; border:1px solid var(--line-strong); border-radius:var(--radius-pill); padding:7px 14px; font-size:13.5px; min-width:240px; font-family:inherit; }
        .bulk { display:flex; gap:8px; align-items:center; padding:10px 18px; border-bottom:1px solid var(--line); background:var(--bg); font-size:13px; }
        .v-row { display:grid; grid-template-columns:24px 64px minmax(0, 1.6fr) 110px 90px 130px 110px; gap:12px; align-items:center; padding:10px 18px; border-bottom:1px solid var(--line); font-size:13px; }
        .v-row:last-child { border-bottom:none; }
        .v-photo { width:64px; height:44px; border-radius:7px; background:var(--hero-grad) center/cover; }
        .v-title { font-weight:700; }
        .v-meta { font-size:11.5px; color:var(--ink-3); font-family:var(--mono); }
        .tag { display:inline-block; font-size:10px; font-weight:800; letter-spacing:.04em; text-transform:uppercase; padding:2px 7px; border-radius:var(--radius-pill); margin-right:4px; }
        .tag.live { background:var(--good-soft); color:var(--good-ink); }
        .tag.off { background:var(--bg); color:var(--ink-3); border:1px solid var(--line); }
        .tag.held { background:var(--amber-soft); color:var(--amber); }
        .tag.cert { background:var(--active); color:var(--ink-2); }
        .empty { padding:18px; font-size:13.5px; color:var(--ink-3); }
        .pages { margin-top:14px; }
        @media (max-width:980px){ .v-row { grid-template-columns:24px 64px 1fr auto; } .v-row .hide-sm { display:none; } }
</style>

    @if ($statusMessage !== '') <div class="msg ok">{{ $statusMessage }}</div> @endif
    @if ($errorMessage !== '') <div class="msg bad">{{ $errorMessage }}</div> @endif
    @if ($importOutput !== '') <div class="output">{{ $importOutput }}</div> @endif

    @if ($canManage)
        <form class="card import" wire:submit="importFeed">
            <span class="lbl">Import inventory feed</span>
            <input type="file" wire:model="feed" accept=".csv,text/csv">
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="feed,importFeed">
                <span wire:loading.remove wire:target="importFeed">Import</span>
                <span wire:loading wire:target="importFeed">Importing…</span>
            </button>
            @error('feed') <span class="msg bad" style="margin:0">{{ $message }}</span> @enderror
            <label class="opt"><input type="checkbox" wire:model="unpublishMissing"> Unpublish cars that are no longer in the feed (sold or removed)</label>
            <span class="hint">The dealer's CSV export. Existing cars are updated and keep their published state; new cars arrive unpublished. Cars missing from the file are unpublished, never deleted. A file from another rooftop is refused.</span>
        </form>
    @endif

    <div class="filters">
        @foreach (['all' => 'All', 'published' => 'Published', 'unpublished' => 'Unpublished', 'reserved' => 'Reserved / sold'] as $key => $label)
            <button type="button" class="chip {{ $show === $key ? 'on' : '' }}" wire:click="$set('show', '{{ $key }}')">{{ $label }} · {{ $counts[$key] }}</button>
        @endforeach
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="VIN, stock #, make or model" aria-label="Search inventory">
    </div>

    <div class="card">
        @if ($canManage && $vehicles->isNotEmpty())
            <div class="bulk">
                <span>{{ count($selected) }} selected</span>
                <button type="button" class="btn btn-primary" wire:click="publishSelected" @disabled($selected === [])>Publish</button>
                <button type="button" class="btn btn-ghost" wire:click="unpublishSelected" @disabled($selected === [])>Unpublish</button>
            </div>
        @endif

        @forelse ($vehicles as $vehicle)
            <div class="v-row" wire:key="v-{{ $vehicle->id }}">
                <span>@if ($canManage) <input type="checkbox" value="{{ $vehicle->id }}" wire:model.live="selected" aria-label="Select vehicle"> @endif</span>
                <span class="v-photo" @if ($vehicle->primary_photo_url) style="background-image:url('{{ $vehicle->primary_photo_url }}')" @endif></span>
                <span>
                    <div class="v-title">{{ $vehicle->model_year }} {{ $vehicle->make }} {{ $vehicle->model }} {{ $vehicle->trim }}</div>
                    <div class="v-meta">{{ $vehicle->vin }}{{ $vehicle->stock_number ? ' · #' . $vehicle->stock_number : '' }}</div>
                </span>
                <span class="hide-sm">{{ $vehicle->display_price }}<br><span class="v-meta">{{ $vehicle->display_kilometres }} · {{ strtolower($vehicle->condition) }}</span></span>
                <span class="hide-sm">
                    @if ($vehicle->is_manufacturer_certified) <span class="tag cert">Mfr CPO</span>
                    @elseif ($vehicle->is_certified) <span class="tag cert">Certified</span> @endif
                </span>
                <span>
                    <span class="tag {{ $vehicle->is_published ? 'live' : 'off' }}">{{ $vehicle->is_published ? 'Published' : 'Unpublished' }}</span>
                    @if ($vehicle->is_reserved) <span class="tag held">Reserved</span> @endif
                </span>
                <span style="text-align:right">
                    @if ($canManage)
                        @if ($vehicle->is_published)
                            <button type="button" class="btn btn-ghost" wire:click="unpublish({{ $vehicle->id }})">Unpublish</button>
                        @else
                            <button type="button" class="btn btn-primary" wire:click="publish({{ $vehicle->id }})">Publish</button>
                        @endif
                    @endif
                </span>
            </div>
        @empty
            <div class="empty">{{ $counts['all'] === 0 ? 'No vehicles yet. Import the dealer’s inventory feed to add them.' : 'No vehicles match.' }}</div>
        @endforelse
    </div>

    <div class="pages">{{ $vehicles->links() }}</div>
