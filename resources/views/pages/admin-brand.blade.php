<?php

use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\InspectionProgram;
use App\Models\Offer;
use App\Support\Permission;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
| Settings › Brands › one brand (settings.manage): what its dealers get — the
| CPO checklist, the offers — and which dealers those are.
*/
new #[Layout('layouts.dealer')] #[Title('Brand')] class extends Component {
    #[Locked]
    public int $brandId;

    public function mount(Brand $brand): void
    {
        Gate::authorize(Permission::SETTINGS_MANAGE);
        $this->brandId = $brand->id;
    }

    /** An unused brand (no dealers, offers or checklist) can be removed. */
    public function deleteBrand(): void
    {
        Gate::authorize(Permission::SETTINGS_MANAGE);
        $brand = Brand::findOrFail($this->brandId);
        abort_if($brand->dealers()->exists() || $brand->offers()->exists() || $brand->checklists()->exists(), 422);

        AuditLog::record('brand.deleted', $brand, ['name' => $brand->name]);
        $brand->delete();
        $this->redirectRoute('admin.brands', navigate: true);
    }

    public function with(): array
    {
        $brand = Brand::findOrFail($this->brandId);
        $today = now()->startOfDay();
        $offers = $brand->offers()->whereIn('status', [Offer::STATUS_PUBLISHED, Offer::STATUS_DRAFT])->whereDate('ends_on', '>=', $today)->orderBy('starts_on')->get();

        return [
            'brand'      => $brand,
            'checklists' => $brand->checklists()->withCount('points')->orderByDesc('is_active')->get(),
            'offers'     => $offers,
            'dealers'    => $brand->dealers()->withCount(['vehicles as live_count' => fn ($vehicles) => $vehicles->where('is_published', true)])->orderBy('name')->get(),
            'deletable'  => ! $brand->dealers()->exists() && ! $brand->offers()->exists() && ! $brand->checklists()->exists(),
        ];
    }
}; ?>

@push('crumb')
    <a href="{{ route('admin.settings') }}" wire:navigate>Platform settings</a>
    <span>›</span>
    <a href="{{ route('admin.brands') }}" wire:navigate>Brands</a>
    <span>›</span>
    <span class="cur">{{ $brand->name }}</span>
@endpush

@push('styles')
    <style>
        .bo { display:grid; grid-template-columns:minmax(0, 1fr) minmax(0, 1fr); gap:28px 32px; }
        .card { border-top:2px solid var(--ink); }
        .card-head { display:flex; align-items:flex-start; gap:10px; padding:14px 0 12px; }
        .card-head .t { font-family:var(--font-display); font-size:22px; font-weight:700; text-transform:uppercase; line-height:1.05; color:var(--ink); }
        .card-head .s { font-size:13px; color:var(--ink-3); margin-top:3px; }
        .card-head .spacer { flex:1; }
        .card-head a { font-size:14px; font-weight:700; color:var(--primary-press); text-decoration:underline; text-underline-offset:3px; white-space:nowrap; }
        .big { display:flex; align-items:baseline; gap:10px; padding:2px 0 16px; }
        .big b { font-family:var(--font-display); font-size:44px; font-weight:700; line-height:1; color:var(--ink); }
        .big span { font-size:14px; color:var(--ink-2); }
        .row { display:flex; align-items:center; gap:12px; padding:12px 4px; border-top:1px solid var(--line-strong); font-size:14px; text-decoration:none; }
        .card > .row:last-child, .card > .empty:last-child { border-bottom:1px solid var(--line-strong); }
        a.row:hover { background:#fff; }
        a.row:hover .nm { text-decoration:underline; text-underline-offset:3px; }
        .row .grow { flex:1; min-width:0; }
        .row .nm { font-weight:700; }
        .meta { font-size:13px; color:var(--ink-3); margin-top:1px; }
        .pill { font-family:var(--font-display); font-size:13px; font-weight:600; text-transform:uppercase; padding:2px 8px; border-radius:2px; background:#fff; border:1px solid var(--line-strong); color:var(--ink-2); white-space:nowrap; }
        .pill.live { background:#E6F8EC; color:#0B7355; border-color:transparent; }
        .pill.scheduled { background:var(--primary-soft); color:var(--primary-press); border-color:transparent; }
        .offer-big { font-family:var(--font-display); font-size:22px; font-weight:700; line-height:1; color:var(--ink); min-width:96px; }
        .empty { margin:0 0 14px; padding:14px 16px; border:1.5px dashed var(--line-strong); border-radius:3px; font-size:14px; color:var(--ink-2); }
        .cta { display:inline-flex; align-items:center; gap:7px; font-family:var(--font-display); font-size:15px; font-weight:700; text-transform:uppercase; border-radius:3px; padding:9px 18px; background:var(--primary); color:#fff; }
        .cta:hover { background:var(--primary-press); }
        .wide { grid-column:1 / -1; }
        .danger { margin-top:28px; padding-top:14px; border-top:1px solid var(--line-strong); font-size:14px; color:var(--ink-3); }
        .danger button { color:#D81A48; font-weight:700; text-decoration:underline; text-underline-offset:3px; }
        @media (max-width:980px) { .bo { grid-template-columns:minmax(0, 1fr); } }
    </style>
@endpush

<div>
    @include('partials.brand-header', ['brand' => $brand, 'activeTab' => 'overview'])

    <div class="bo">
        @php $main = $checklists->firstWhere('is_active', true); @endphp
        <div class="card">
            <div class="card-head">
                <div><div class="t">CPO checklist</div><div class="s">Shown on every {{ $brand->name }} dealer’s certified cars</div></div>
                <div class="spacer"></div>
                <a href="{{ route('admin.brands.checklist', $brand) }}" wire:navigate>{{ $checklists->isEmpty() ? 'Set up →' : 'Edit →' }}</a>
            </div>
            @if ($main)
                <div class="big"><b>{{ $main->points_count }}</b><span>points · {{ $main->name }}</span></div>
                <div class="row">
                    <span class="grow">
                        <div class="nm">Shows on</div>
                        <div class="meta">{{ InspectionProgram::APPLIES_TO_LABELS[$main->applies_to] }}</div>
                    </span>
                    <span class="pill live">Live</span>
                </div>
            @else
                <div class="empty">No checklist yet — {{ $brand->name }} cars show no inspection until one is set up.</div>
                <a class="cta" href="{{ route('admin.brands.checklist', $brand) }}" wire:navigate>Set up the checklist</a>
            @endif
        </div>

        <div class="card">
            <div class="card-head">
                <div><div class="t">Offers &amp; incentives</div><div class="s">Current and upcoming</div></div>
                <div class="spacer"></div>
                <a href="{{ route('admin.brands.offers', $brand) }}" wire:navigate>All offers →</a>
            </div>
            @forelse ($offers->take(4) as $offer)
                <a class="row" href="{{ route('admin.brands.offers.edit', [$brand, $offer]) }}" wire:navigate>
                    <span class="offer-big">{{ $offer->headline() }}</span>
                    <span class="grow"><div class="nm">{{ $offer->title }}</div><div class="meta">{{ $offer->starts_on->format('M j') }} – {{ $offer->ends_on->format('M j, Y') }}</div></span>
                    <span class="pill {{ $offer->state() }}">{{ $offer->state() }}</span>
                </a>
            @empty
                <div class="empty">No current or upcoming offers.</div>
                <a class="cta" href="{{ route('admin.brands.offers.create', $brand) }}" wire:navigate>Add an offer</a>
            @endforelse
        </div>

        <div class="card wide">
            <div class="card-head">
                <div><div class="t">{{ $brand->name }} dealers · {{ $dealers->count() }}</div><div class="s">They all follow the settings above. Set a dealer’s brand on its profile.</div></div>
            </div>
            @forelse ($dealers as $dealer)
                <a class="row" href="{{ route('admin.dealers.edit', $dealer) }}" wire:navigate>
                    <span class="grow"><div class="nm">{{ $dealer->name }}</div><div class="meta">{{ $dealer->display_location }}</div></span>
                    <span class="meta">{{ $dealer->live_count }} live {{ $dealer->live_count === 1 ? 'car' : 'cars' }}</span>
                    <span class="pill {{ $dealer->status === 'active' ? 'live' : '' }}">{{ $dealer->status }}</span>
                </a>
            @empty
                <div class="empty">No {{ $brand->name }} dealers yet.</div>
            @endforelse
        </div>
    </div>

    @if ($deletable)
        <div class="danger">This brand isn’t used yet. <button type="button" wire:click="deleteBrand" wire:confirm="Remove {{ $brand->name }} from the brand list?">Remove it</button></div>
    @endif
</div>
