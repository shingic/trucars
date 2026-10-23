<?php

use App\Livewire\Concerns\EditsOffer;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Offer;
use App\Support\Permission;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
| Settings › Brands › one offer (settings.manage). Save as a draft, publish
| (it then switches on and off by its dates for every dealer of the brand),
| copy it for next month, or archive it. Every change is audited.
*/
new #[Layout('layouts.dealer')] #[Title('Offer')] class extends Component {
    use EditsOffer;

    #[Locked]
    public int $brandId;

    #[Locked]
    public ?int $offerId = null;

    public string $statusMessage = '';

    public function mount(Brand $brand, ?Offer $offer = null): void
    {
        Gate::authorize(Permission::SETTINGS_MANAGE);
        $this->brandId = $brand->id;
        $this->make = $brand->name;

        if ($offer?->exists) {
            abort_unless($offer->brand_id === $brand->id, 404);
            $this->offerId = $offer->id;
            $this->fillOfferForm($offer);
        } else {
            $this->defaultOfferDates();
        }

        $this->statusMessage = (string) session('offer_status', '');
    }

    public function brand(): Brand
    {
        return Brand::findOrFail($this->brandId);
    }

    public function offer(): ?Offer
    {
        return $this->offerId ? Offer::where('brand_id', $this->brandId)->findOrFail($this->offerId) : null;
    }

    public function save(bool $publish = false): void
    {
        Gate::authorize(Permission::SETTINGS_MANAGE);

        $attributes = $this->validatedOfferAttributes();
        $offer = $this->offer() ?? new Offer(['brand_id' => $this->brandId, 'created_by_user_id' => auth()->id()]);

        $before = $offer->exists ? $offer->only(array_keys($attributes)) + ['status' => $offer->status] : null;
        $offer->fill($attributes);

        if ($publish) {
            $offer->status = Offer::STATUS_PUBLISHED;
        }

        $offer->save();
        AuditLog::record($before === null ? 'offer.created' : 'offer.updated', $offer, $before, $offer->only(array_keys($attributes)) + ['status' => $offer->status]);

        $brand = $this->brand()->name;
        $message = match (true) {
            $offer->status === Offer::STATUS_DRAFT => 'Draft saved. Publish it when it’s ready.',
            $offer->state() === 'scheduled'        => "Published — it switches on for every {$brand} dealer on " . $offer->starts_on->format('F j') . '.',
            $offer->state() === 'ended'            => 'Saved. Its end date has passed, so it isn’t showing.',
            default                                => "Published — showing now on eligible cars at every {$brand} dealer.",
        };

        if ($this->offerId === null) {
            session()->flash('offer_status', $message);
            $this->redirectRoute('admin.brands.offers.edit', [$this->brandId, $offer], navigate: true);

            return;
        }

        $this->statusMessage = $message;
    }

    public function publish(): void
    {
        $this->save(publish: true);
    }

    public function archive(): void
    {
        Gate::authorize(Permission::SETTINGS_MANAGE);
        $offer = $this->offer() ?? abort(404);
        $before = $offer->status;
        $offer->forceFill(['status' => Offer::STATUS_ARCHIVED])->save();
        AuditLog::record('offer.archived', $offer, ['status' => $before], ['status' => Offer::STATUS_ARCHIVED]);
        $this->statusMessage = 'Archived — it no longer shows anywhere. It stays on record.';
    }

    /** Copy into a draft for the following period — the usual monthly refresh. */
    public function duplicateForNextMonth(): void
    {
        Gate::authorize(Permission::SETTINGS_MANAGE);
        $source = $this->offer() ?? abort(404);

        $start = $source->ends_on->copy()->addDay();
        $copy = $source->replicate(['status']);
        $copy->fill(['starts_on' => $start, 'ends_on' => $start->copy()->addDays((int) $source->starts_on->diffInDays($source->ends_on)), 'created_by_user_id' => auth()->id()]);
        $copy->status = Offer::STATUS_DRAFT;
        $copy->save();

        AuditLog::record('offer.created', $copy, null, ['copied_from' => $source->id]);
        session()->flash('offer_status', 'Copied as a draft for ' . $copy->starts_on->format('F j') . ' – ' . $copy->ends_on->format('F j') . '. Check the rate and publish.');
        $this->redirectRoute('admin.brands.offers.edit', [$this->brandId, $copy], navigate: true);
    }
}; ?>

@push('crumb')
    <a href="{{ route('admin.brands') }}" wire:navigate>Brands</a>
    <span>›</span>
    <a href="{{ route('admin.brands.show', $brandId) }}" wire:navigate>{{ $make }}</a>
    <span>›</span>
    <a href="{{ route('admin.brands.offers', $brandId) }}" wire:navigate>Offers</a>
    <span>›</span>
    <span class="cur">{{ $this->offer()?->headline() ?? 'New offer' }}</span>
@endpush

@push('styles')
    <style>
        .oe-head { display:flex; align-items:center; gap:12px; margin-bottom:8px; flex-wrap:wrap; }
        .oe-head h1 { font-family:var(--font-display); font-size:34px; font-weight:700; text-transform:uppercase; line-height:1; letter-spacing:0; margin:0; }
        .oe-sub { font-size:15px; color:var(--ink-2); margin:0 0 20px; }
        .state { font-family:var(--font-display); font-size:14px; font-weight:600; text-transform:uppercase; padding:3px 9px; border-radius:2px; background:#fff; border:1px solid var(--line-strong); color:var(--ink-2); }
        .state.live { background:#E6F8EC; color:#0B7355; border-color:transparent; }
        .state.scheduled { background:var(--primary-soft); color:var(--primary-press); border-color:transparent; }
        .msg { background:#E6F8EC; color:#0B7355; border-left:4px solid var(--good); border-radius:2px; padding:11px 15px; font-size:14px; font-weight:600; margin-bottom:16px; }
        .oe { display:grid; grid-template-columns:minmax(0, 1fr) 360px; gap:24px; align-items:start; }
        .card { background:#fff; border:1px solid var(--line-strong); border-top:2px solid var(--ink); border-radius:3px; }
        .side { position:sticky; top:84px; }
        .preview { padding:16px; }
        .pv-label { font-family:var(--font-display); font-size:18px; font-weight:700; text-transform:uppercase; line-height:1; color:var(--ink); margin-bottom:12px; }
        .pv-car { border:1px solid var(--line-strong); border-radius:3px; padding:14px; margin-bottom:12px; }
        .pv-car .t { font-family:var(--font-display); font-size:18px; font-weight:700; text-transform:uppercase; line-height:1.1; }
        .pv-car .s { font-size:13px; color:var(--ink-3); margin-top:2px; }
        .pv-car .p { font-family:var(--font-display); font-size:28px; font-weight:700; line-height:1; color:var(--ink); margin-top:8px; }
        .reach { font-size:13px; color:var(--ink-2); margin-top:12px; line-height:1.5; }
        .actions { display:flex; gap:8px; padding:16px 20px; flex-wrap:wrap; border-top:2px solid var(--ink); }
        .btn { display:inline-flex; align-items:center; justify-content:center; gap:7px; font-size:14px; font-weight:700; border-radius:3px; padding:9px 17px; white-space:nowrap; border:1.5px solid transparent; }
        .btn:focus-visible { outline:2px solid var(--primary); outline-offset:2px; }
        .btn-primary { background:var(--primary); border-color:var(--primary); color:#fff; font-family:var(--font-display); font-size:15px; text-transform:uppercase; }
        .btn-primary:hover { background:var(--primary-press); border-color:var(--primary-press); }
        .btn-ghost { border-color:var(--ink); color:var(--ink); background:#fff; }
        .btn-ghost:hover { background:var(--ink); color:#fff; }
        .btn-quiet { color:#D81A48; text-decoration:underline; text-underline-offset:3px; padding-left:4px; padding-right:4px; }
        .btn-quiet:hover { color:#A8143A; }
        .grow { flex:1; }
        @media (max-width:1100px) { .oe { grid-template-columns:minmax(0, 1fr); } .side { position:static; } }
    </style>
@endpush

@php $offer = $this->offer(); $dealerCount = $this->brand()->dealers()->count(); @endphp

<div>
    <div class="oe-head">
        <h1>{{ $offer ? $make . ' offer' : 'New ' . $make . ' offer' }}</h1>
        @if ($offer) <span class="state {{ $offer->state() }}">{{ $offer->state() }}</span> @endif
    </div>
    <p class="oe-sub">Applies to eligible {{ $make }} cars at all {{ $dealerCount }} {{ $make }} {{ $dealerCount === 1 ? 'dealer' : 'dealers' }}, within its dates.</p>

    @if ($statusMessage !== '') <div class="msg">{{ $statusMessage }}</div> @endif

    <div class="oe">
        <div class="card">
            @include('partials.offer-form')
            <div class="actions">
                @if ($offer && $offer->status !== 'archived')
                    <button type="button" class="btn btn-quiet" wire:click="archive" wire:confirm="Archive this offer? It stops showing everywhere.">Archive</button>
                @endif
                @if ($offer)
                    <button type="button" class="btn btn-ghost" wire:click="duplicateForNextMonth">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="8" y="8" width="13" height="13" rx="2"/><path d="M16 8V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h3"/></svg>
                        Copy for next month
                    </button>
                @endif
                <span class="grow"></span>
                @if (! $offer || $offer->status === 'draft')
                    <button type="button" class="btn btn-ghost" wire:click="save">Save draft</button>
                @endif
                <button type="button" class="btn btn-primary" wire:click="publish">{{ $offer && $offer->status === 'published' ? 'Save changes' : 'Publish' }}</button>
            </div>
        </div>

        <div class="side">
            <div class="card preview">
                <div class="pv-label">Customer preview</div>
                <div class="pv-car">
                    <div class="t">{{ now()->year - 2 }} {{ $make }} {{ trim(explode(',', $models)[0] ?? '') ?: 'Model' }}</div>
                    <div class="s">{{ $certifiedOnly ? 'Certified pre-owned' : 'Pre-owned' }}</div>
                    <div class="p">$39,995</div>
                </div>
                @include('partials.offer-card', ['offer' => $this->previewOffer()])
                <div class="reach">Shows at every {{ $make }} dealer — there’s no per-dealer opt-in.</div>
            </div>
        </div>
    </div>
</div>
