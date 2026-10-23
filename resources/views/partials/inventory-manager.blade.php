{{--
    One dealer's inventory table, import form and bulk actions. Rendered by both
    the Command Centre and the dealer portal inventory pages, whose component
    uses App\Livewire\Concerns\ManagesDealerInventory. Expects the view data
    from inventoryViewData(): $inventoryDealer, $canManage, $counts, $vehicles.
--}}
<style>
        .iv-note { font-size:14px; color:var(--ink-2); margin:-8px 0 18px; max-width:760px; }
        .msg { border-radius:3px; padding:11px 15px; font-size:14px; font-weight:600; margin-bottom:16px; background:#fff; border:1px solid var(--line-strong); }
        .msg.ok { color:#0B7355; border-left:3px solid var(--good); }
        .msg.bad { background:#FFEEF3; color:#D81A48; border-color:rgba(216,26,72,.25); }
        .output { background:var(--bg-2); border:1px solid var(--line-strong); border-radius:3px; padding:12px 14px; font-family:var(--mono); font-size:13px; white-space:pre-wrap; margin-bottom:16px; color:var(--ink-2); }
        .card { border-top:2px solid var(--ink); }
        .card.import { background:#fff; border:1px solid var(--line-strong); border-radius:4px; }
        .import { padding:14px 18px; display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:22px; }
        .import .lbl { font-family:var(--font-display); font-size:18px; font-weight:700; text-transform:uppercase; }
        .import input[type=file] { font:inherit; font-size:14px; min-width:0; max-width:100%; }
        .import .opt { font-size:14px; color:var(--ink-2); display:flex; gap:8px; align-items:center; flex-basis:100%; }
        .import .hint { font-size:13px; color:var(--ink-3); flex-basis:100%; }
        .btn { font-size:13.5px; font-weight:700; border-radius:3px; padding:8px 15px; white-space:nowrap; transition:background .14s ease, color .14s ease; }
        .btn-primary { background:var(--primary); color:#fff; }
        .btn-primary:hover { background:var(--primary-press); }
        .btn-ghost { background:#fff; border:1.5px solid var(--ink); color:var(--ink); padding:6.5px 13.5px; }
        .btn-ghost:hover { background:var(--ink); color:#fff; }
        .btn:disabled { opacity:.5; pointer-events:none; }
        .btn:focus-visible, .chip:focus-visible { outline:2px solid var(--primary); outline-offset:2px; }
        .filters { display:flex; gap:4px 18px; flex-wrap:wrap; align-items:flex-end; margin-bottom:14px; border-bottom:1px solid var(--line-strong); }
        .chip { padding:8px 0 9px; margin-bottom:-1px; border-bottom:3px solid transparent; font-size:14px; font-weight:700; color:var(--ink-3); }
        .chip:hover { color:var(--ink); }
        .chip.on { color:var(--ink); border-bottom-color:var(--primary); }
        .filters input { margin:0 0 8px auto; border:1.5px solid var(--line-strong); border-radius:3px; padding:7px 12px; font-size:14px; min-width:240px; max-width:100%; font-family:inherit; background:#fff; color:var(--ink); }
        .filters input:focus { outline:none; border-color:var(--ink); box-shadow:0 0 0 2px var(--primary-soft); }
        .bulk { display:flex; gap:8px; align-items:center; padding:9px 0; border-bottom:2px solid var(--ink); font-size:14px; font-weight:600; }
        .v-row { display:grid; grid-template-columns:24px 64px minmax(0, 1.6fr) 120px 90px 130px 110px; gap:12px; align-items:center; padding:10px 0; border-bottom:1px solid var(--line-strong); font-size:14px; }
        .v-row input[type=checkbox], .import input[type=checkbox] { width:16px; height:16px; accent-color:var(--ink); }
        .v-photo { width:64px; height:44px; border-radius:2px; background:var(--bg-2) center/cover; }
        .v-title { font-weight:700; }
        .v-meta { font-size:13px; color:var(--ink-3); font-family:var(--mono); }
        .v-price { font-family:var(--font-display); font-size:17px; font-weight:700; color:var(--ink); }
        .tag { display:inline-block; font-family:var(--font-display); font-size:13px; font-weight:700; letter-spacing:.02em; text-transform:uppercase; padding:1px 7px; border-radius:2px; margin-right:4px; }
        .tag.live { background:#E5F8EC; color:#0B7355; }
        .tag.off { background:var(--bg-2); color:var(--ink-3); border:1px solid var(--line-strong); }
        .tag.held { background:#FFF8DD; color:#7A4E00; }
        .tag.cert { background:var(--ink); color:#fff; }
        .empty { margin-top:12px; padding:14px 16px; font-size:14px; color:var(--ink-2); border:1.5px dashed var(--line-strong); border-radius:3px; }
        .pages { margin-top:14px; }
        @media (max-width:980px){ .v-row { grid-template-columns:24px 64px 1fr auto; } .v-row .hide-sm { display:none; } }
        @media (max-width:560px){ .filters input { min-width:0; width:100%; margin-left:0; } .v-row { grid-template-columns:24px 52px minmax(0, 1fr); } .v-row > :nth-last-child(-n+2) { grid-column:3; } .v-row > :last-child { text-align:left !important; } .v-photo { width:52px; height:38px; } }
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
                <span class="hide-sm"><span class="v-price">{{ $vehicle->display_price }}</span><br><span class="v-meta">{{ $vehicle->display_kilometres }} · {{ strtolower($vehicle->condition) }}</span></span>
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
