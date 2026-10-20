<?php

use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\InspectionProgram;
use App\Models\Offer;
use App\Support\Permission;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
| Settings › Brands (settings.manage). Every dealer belongs to one brand, and
| each brand's settings — CPO checklist and offers — apply to all its dealers.
*/
new #[Layout('layouts.dealer')] #[Title('Brands')] class extends Component {
    public string $search = '';
    public string $newBrand = '';

    public function mount(): void
    {
        Gate::authorize(Permission::SETTINGS_MANAGE);
    }

    public function brands(): Collection
    {
        $today = now()->toDateString();

        $brands = Brand::query()
            ->withCount([
                'dealers',
                'offers as live_offers' => fn ($offers) => $offers->where('status', Offer::STATUS_PUBLISHED)->whereDate('starts_on', '<=', $today)->whereDate('ends_on', '>=', $today),
            ])
            ->when(trim($this->search) !== '', fn ($query) => $query->where('name', 'like', '%' . trim($this->search) . '%'))
            ->orderByDesc('dealers_count')
            ->orderBy('name')
            ->get();

        // Checklist point counts, keyed by lower-cased make.
        $points = InspectionProgram::whereNull('dealer_id')->where('is_active', true)->withCount('points')->get()
            ->groupBy(fn ($program) => mb_strtolower((string) $program->make))
            ->map(fn ($programs) => $programs->max('points_count'));

        return $brands->each(fn (Brand $brand) => $brand->checklist_points = $points[mb_strtolower($brand->name)] ?? 0);
    }

    public function addBrand(): void
    {
        Gate::authorize(Permission::SETTINGS_MANAGE);
        $this->newBrand = trim($this->newBrand);
        $this->validate(['newBrand' => ['required', 'string', 'max:40', Rule::unique('brands', 'name')]], ['newBrand.unique' => 'That brand is already listed.'], ['newBrand' => 'brand']);

        $brand = Brand::create(['name' => $this->newBrand]);
        AuditLog::record('brand.created', $brand, null, ['name' => $brand->name]);
        $this->redirectRoute('admin.brands.show', $brand, navigate: true);
    }
}; ?>

@push('crumb')
    <a href="{{ route('admin.settings') }}" wire:navigate>Platform settings</a>
    <span>›</span>
    <span class="cur">Brands</span>
@endpush

@push('styles')
    <style>
        .br-head { display:flex; align-items:flex-end; gap:16px; margin-bottom:18px; flex-wrap:wrap; }
        .br-head h1 { font-size:24px; font-weight:800; letter-spacing:-.02em; margin:0 0 6px; }
        .br-head p { margin:0; font-size:14px; color:var(--ink-2); max-width:620px; }
        .br-head .spacer { flex:1; }
        .br-tools { display:flex; gap:8px; flex-wrap:wrap; }
        .br-tools input { border:1px solid var(--line-strong); border-radius:var(--radius-pill); padding:9px 15px; font-size:14px; font-family:inherit; background:var(--card); min-width:180px; }
        .br-tools input:focus { outline:none; border-color:var(--primary); }
        .btn { display:inline-flex; align-items:center; gap:7px; font-size:13.5px; font-weight:700; border-radius:var(--radius-pill); padding:9px 16px; background:var(--ink); color:#fff; white-space:nowrap; }
        .btn:hover { background:var(--primary); }
        .err { font-size:12px; color:var(--primary-press); font-weight:600; }
        .grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(250px, 1fr)); gap:14px; }
        .brand { display:flex; flex-direction:column; gap:14px; padding:18px; border-radius:var(--radius); background:var(--card); border:1px solid var(--line); box-shadow:var(--shadow-sm); text-decoration:none; transition:border-color .15s ease, transform .15s ease; }
        .brand:hover { border-color:var(--primary-line); transform:translateY(-1px); }
        .brand.quiet { box-shadow:none; background:transparent; }
        .brand-top { display:flex; align-items:center; gap:12px; }
        .logo { width:44px; height:44px; border-radius:13px; background:#14161B; color:#fff; display:grid; place-items:center; font-weight:800; font-size:15px; flex-shrink:0; }
        .brand.quiet .logo { background:var(--bg); color:var(--ink-3); border:1px solid var(--line); }
        .brand .nm { font-size:16px; font-weight:800; letter-spacing:-.01em; }
        .brand .dl { font-size:12.5px; color:var(--ink-3); }
        .chips { display:flex; gap:6px; flex-wrap:wrap; }
        .chip { display:inline-flex; align-items:center; gap:5px; font-size:11.5px; font-weight:700; padding:4px 9px; border-radius:var(--radius-pill); background:var(--bg); color:var(--ink-3); border:1px solid var(--line); }
        .chip.on { background:var(--good-soft); color:var(--good-ink); border-color:transparent; }
        .chip.offer { background:#14161B; color:#FFB98A; border-color:transparent; }
        .chip svg { width:12px; height:12px; }
        .sec { font-size:11px; font-weight:800; letter-spacing:.06em; text-transform:uppercase; color:var(--ink-3); margin:26px 0 12px; }
    </style>
@endpush

@php $brands = $this->brands(); $active = $brands->where('dealers_count', '>', 0); $others = $brands->where('dealers_count', 0); @endphp

<div>
    @include('partials.settings-tabs', ['activeTab' => 'brands'])

    <div class="br-head">
        <div>
            <h1>Brands</h1>
            <p>Each brand’s CPO checklist and offers apply to every dealer of that brand. Assign a dealer its brand and everything else follows.</p>
        </div>
        <div class="spacer"></div>
        <form class="br-tools" wire:submit="addBrand">
            <input type="search" wire:model.live.debounce.250ms="search" placeholder="Find a brand" aria-label="Find a brand">
            <input type="text" wire:model="newBrand" placeholder="New brand" maxlength="40" aria-label="New brand name">
            <button type="submit" class="btn">Add brand</button>
        </form>
    </div>
    @error('newBrand') <div class="err" style="margin:-8px 0 12px">{{ $message }}</div> @enderror

    @foreach (['With dealers' => $active, 'No dealers yet' => $others] as $label => $group)
        @continue($group->isEmpty())
        <div class="sec">{{ $label }} · {{ $group->count() }}</div>
        <div class="grid">
            @foreach ($group as $brand)
                <a class="brand {{ $brand->dealers_count ? '' : 'quiet' }}" href="{{ route('admin.brands.show', $brand) }}" wire:navigate wire:key="b-{{ $brand->id }}">
                    <div class="brand-top">
                        <span class="logo">{{ $brand->initials() }}</span>
                        <span>
                            <div class="nm">{{ $brand->name }}</div>
                            <div class="dl">{{ $brand->dealers_count }} {{ $brand->dealers_count === 1 ? 'dealer' : 'dealers' }}</div>
                        </span>
                    </div>
                    <div class="chips">
                        <span class="chip {{ $brand->checklist_points ? 'on' : '' }}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                            {{ $brand->checklist_points ? $brand->checklist_points . '-pt checklist' : 'No checklist' }}
                        </span>
                        @if ($brand->live_offers)
                            <span class="chip offer">{{ $brand->live_offers }} live {{ $brand->live_offers === 1 ? 'offer' : 'offers' }}</span>
                        @endif
                    </div>
                </a>
            @endforeach
        </div>
    @endforeach
</div>
