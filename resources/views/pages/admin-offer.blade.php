<?php

use App\Livewire\Concerns\EditsOffer;
use App\Models\AuditLog;
use App\Models\Dealer;
use App\Models\Offer;
use App\Support\Permission;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
| Command Centre — one offer (offers.manage).
|
| Manufacturer offers are created and edited here: save as a draft, publish
| (it then switches on and off by its dates), duplicate for next month, or
| archive. Dealer promotions are reviewed here: approve or send back with a
| note. Every change is audited.
*/
new #[Layout('layouts.dealer')] #[Title('Offer')] class extends Component {
    use EditsOffer;

    #[Locked]
    public ?int $offerId = null;

    public string $reviewNote = '';
    public string $statusMessage = '';

    public function mount(?Offer $offer = null): void
    {
        Gate::authorize(Permission::OFFERS_MANAGE);

        if ($offer?->exists) {
            $this->offerId = $offer->id;
            $this->fillOfferForm($offer);
        } else {
            $this->defaultOfferDates();
        }

        $this->statusMessage = (string) session('offer_status', '');
    }

    public function offer(): ?Offer
    {
        return $this->offerId ? Offer::with(['dealer', 'participants'])->findOrFail($this->offerId) : null;
    }

    /** Manufacturer offers are edited here; dealer promotions are only reviewed. */
    public function editable(): bool
    {
        return $this->offer()?->isManufacturer() ?? true;
    }

    public function save(bool $publish = false): void
    {
        Gate::authorize(Permission::OFFERS_MANAGE);
        abort_unless($this->editable(), 403);

        $attributes = $this->validatedOfferAttributes() + ['requires_opt_in' => $this->requiresOptIn];
        $offer = $this->offer();

        if ($offer === null) {
            $offer = new Offer(['source' => Offer::SOURCE_MANUFACTURER, 'created_by_user_id' => auth()->id()]);
        }

        $before = $offer->exists ? $offer->only(array_keys($attributes)) + ['status' => $offer->status] : null;
        $offer->fill($attributes);

        if ($publish) {
            $offer->status = Offer::STATUS_PUBLISHED;
        }

        $offer->save();
        AuditLog::record($before === null ? 'offer.created' : 'offer.updated', $offer, $before, $offer->only(array_keys($attributes)) + ['status' => $offer->status]);

        $message = match (true) {
            ! $publish && $offer->status === Offer::STATUS_DRAFT => 'Draft saved. Publish it when it’s ready.',
            $offer->state() === 'scheduled' => 'Published — it switches on automatically on ' . $offer->starts_on->format('F j') . '.',
            $offer->state() === 'ended'     => 'Saved. Its end date has passed, so it isn’t showing.',
            default                         => 'Published — showing now on eligible cars.',
        };

        if ($this->offerId === null) {
            session()->flash('offer_status', $message);
            $this->redirectRoute('admin.offers.edit', $offer, navigate: true);

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
        Gate::authorize(Permission::OFFERS_MANAGE);
        $offer = $this->offer() ?? abort(404);
        $before = $offer->status;
        $offer->forceFill(['status' => Offer::STATUS_ARCHIVED])->save();
        AuditLog::record('offer.archived', $offer, ['status' => $before], ['status' => Offer::STATUS_ARCHIVED]);
        $this->statusMessage = 'Archived — it no longer shows anywhere. It stays on record.';
    }

    /** Copy into a draft for the following month — the usual monthly refresh. */
    public function duplicateForNextMonth(): void
    {
        Gate::authorize(Permission::OFFERS_MANAGE);
        $source = $this->offer() ?? abort(404);
        abort_unless($source->isManufacturer(), 403);

        $start = $source->ends_on->copy()->addDay();
        $copy = $source->replicate(['status', 'reviewed_at', 'reviewed_by_user_id', 'review_note']);
        $copy->fill(['starts_on' => $start, 'ends_on' => $start->copy()->addDays((int) $source->starts_on->diffInDays($source->ends_on)), 'created_by_user_id' => auth()->id()]);
        $copy->status = Offer::STATUS_DRAFT;
        $copy->save();
        $copy->participants()->sync($source->participants->mapWithKeys(fn (Dealer $dealer) => [$dealer->id => ['opted_in_by_user_id' => $dealer->pivot->opted_in_by_user_id]])->all());

        AuditLog::record('offer.created', $copy, null, ['copied_from' => $source->id]);
        session()->flash('offer_status', 'Copied as a draft for ' . $copy->starts_on->format('F j') . ' – ' . $copy->ends_on->format('F j') . '. Participating dealers carried over. Check the rate and publish.');
        $this->redirectRoute('admin.offers.edit', $copy, navigate: true);
    }

    public function approve(): void
    {
        $offer = $this->reviewable();
        $offer->forceFill(['status' => Offer::STATUS_PUBLISHED, 'reviewed_by_user_id' => auth()->id(), 'reviewed_at' => now(), 'review_note' => trim($this->reviewNote) ?: null])->save();
        AuditLog::record('offer.approved', $offer, ['status' => Offer::STATUS_PENDING], ['status' => Offer::STATUS_PUBLISHED]);
        $this->statusMessage = 'Approved — it shows on ' . $offer->dealer->name . '’s eligible cars within its dates.';
    }

    public function reject(): void
    {
        $offer = $this->reviewable();
        $this->validate(['reviewNote' => ['required', 'string', 'min:5', 'max:255']], ['reviewNote.required' => 'Tell the dealer what to change.']);
        $offer->forceFill(['status' => Offer::STATUS_REJECTED, 'reviewed_by_user_id' => auth()->id(), 'reviewed_at' => now(), 'review_note' => trim($this->reviewNote)])->save();
        AuditLog::record('offer.rejected', $offer, ['status' => Offer::STATUS_PENDING], ['status' => Offer::STATUS_REJECTED, 'note' => $offer->review_note]);
        $this->statusMessage = 'Sent back to the dealer with your note.';
    }

    private function reviewable(): Offer
    {
        Gate::authorize(Permission::OFFERS_MANAGE);
        $offer = $this->offer() ?? abort(404);
        abort_unless(! $offer->isManufacturer() && $offer->status === Offer::STATUS_PENDING, 422);

        return $offer;
    }
}; ?>

@push('crumb')
    <a href="{{ route('admin.offers') }}" wire:navigate>Offers &amp; Incentives</a>
    <span>›</span>
    <span class="cur">{{ $this->offer() ? $this->offer()->make . ' · ' . $this->offer()->headline() : 'New offer' }}</span>
@endpush

@push('styles')
    <style>
        .oe-head { display:flex; align-items:center; gap:12px; margin-bottom:18px; flex-wrap:wrap; }
        .oe-head h1 { font-size:24px; font-weight:800; letter-spacing:-.02em; margin:0; }
        .oe-head .spacer { flex:1; }
        .state { font-size:10.5px; font-weight:800; letter-spacing:.05em; text-transform:uppercase; padding:4px 10px; border-radius:var(--radius-pill); background:var(--bg); border:1px solid var(--line); color:var(--ink-2); }
        .state.live { background:var(--good-soft); color:var(--good-ink); border-color:transparent; }
        .state.scheduled { background:#E3EDFB; color:#2E5FA3; border-color:transparent; }
        .state.pending { background:var(--amber-soft); color:var(--amber); border-color:transparent; }
        .state.rejected { background:var(--primary-soft); color:var(--primary-press); border-color:transparent; }
        .msg { background:var(--good-soft); color:var(--good-ink); border:1px solid rgba(18,184,134,.25); border-radius:var(--radius-sm); padding:11px 15px; font-size:13.5px; font-weight:600; margin-bottom:16px; }
        .oe { display:grid; grid-template-columns:minmax(0, 1fr) 360px; gap:20px; align-items:start; }
        .card { background:var(--card); border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow-sm); overflow:hidden; }
        .card-head { padding:15px 20px; border-bottom:1px solid var(--line); font-size:15px; font-weight:700; }
        .side { position:sticky; top:84px; display:grid; gap:16px; }
        .preview { padding:16px; }
        .preview .pv-label { font-size:11px; font-weight:800; letter-spacing:.06em; text-transform:uppercase; color:var(--ink-3); margin-bottom:10px; }
        .pv-car { border:1px solid var(--line); border-radius:16px; padding:14px; margin-bottom:12px; }
        .pv-car .t { font-size:15px; font-weight:800; }
        .pv-car .s { font-size:12px; color:var(--ink-3); }
        .pv-car .p { font-size:22px; font-weight:800; margin-top:6px; }
        .actions { display:flex; gap:8px; padding:16px 20px; flex-wrap:wrap; border-top:1px solid var(--line); }
        .btn { display:inline-flex; align-items:center; justify-content:center; gap:7px; font-size:13.5px; font-weight:700; border-radius:var(--radius-pill); padding:10px 17px; white-space:nowrap; }
        .btn-primary { background:var(--primary); color:#fff; }
        .btn-primary:hover { background:var(--primary-press); }
        .btn-ghost { border:1px solid var(--line-strong); color:var(--ink); background:var(--card); }
        .btn-ghost:hover { border-color:var(--ink-3); }
        .btn-quiet { color:var(--ink-3); }
        .btn-quiet:hover { color:var(--primary-press); }
        .btn-good { background:var(--good-ink); color:#fff; }
        .grow { flex:1; }
        .who { padding:6px 20px 14px; }
        .who div { display:flex; align-items:center; gap:10px; padding:8px 0; border-bottom:1px solid var(--line); font-size:13.5px; font-weight:600; }
        .who div:last-child { border-bottom:none; }
        .who .meta { font-size:12px; color:var(--ink-3); font-weight:500; margin-left:auto; }
        .who-empty { padding:4px 20px 16px; font-size:13px; color:var(--ink-3); }
        .review { padding:16px 20px; display:grid; gap:10px; }
        .review textarea { border:1px solid var(--line-strong); border-radius:var(--radius-sm); padding:9px 12px; font-size:14px; font-family:inherit; width:100%; }
        .review .row { display:flex; gap:8px; }
        .note { font-size:13px; color:var(--ink-2); padding:12px 20px; background:var(--bg); border-top:1px solid var(--line); }
        @media (max-width:1100px) { .oe { grid-template-columns:minmax(0, 1fr); } .side { position:static; } }
    </style>
@endpush

@php $offer = $this->offer(); $editable = $this->editable(); @endphp

<div>
    <div class="oe-head">
        <h1>{{ $offer ? ($offer->isManufacturer() ? 'Manufacturer offer' : 'Dealer promotion') : 'New manufacturer offer' }}</h1>
        @if ($offer) <span class="state {{ $offer->state() }}">{{ $offer->state() === 'pending' ? 'Needs review' : $offer->state() }}</span> @endif
        <div class="spacer"></div>
        @if ($offer && ! $offer->isManufacturer())
            <span style="font-size:13.5px; color:var(--ink-2)">From <b>{{ $offer->dealer?->name }}</b></span>
        @endif
    </div>

    @if ($statusMessage !== '') <div class="msg">{{ $statusMessage }}</div> @endif

    <div class="oe">
        <div class="card">
            @include('partials.offer-form', ['showOptIn' => $editable, 'readOnly' => ! $editable])

            @if ($editable)
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
            @endif
        </div>

        <div class="side">
            <div class="card preview">
                <div class="pv-label">Customer preview</div>
                <div class="pv-car">
                    <div class="t">{{ now()->year - 2 }} {{ $make ?: 'Make' }} {{ trim(explode(',', $models)[0] ?? '') ?: 'Model' }}</div>
                    <div class="s">{{ $certifiedOnly ? 'Certified pre-owned' : 'Pre-owned' }}</div>
                    <div class="p">$39,995</div>
                </div>
                @include('partials.offer-card', ['offer' => $this->previewOffer()])
            </div>

            @if ($offer && ! $offer->isManufacturer() && $offer->status === 'pending')
                <div class="card">
                    <div class="card-head">Review</div>
                    <div class="review">
                        <textarea wire:model="reviewNote" rows="2" maxlength="255" placeholder="Note for the dealer (required to send back)"></textarea>
                        @error('reviewNote') <span class="err" style="font-size:12px; color:var(--primary-press); font-weight:600">{{ $message }}</span> @enderror
                        <div class="row">
                            <button type="button" class="btn btn-ghost grow" wire:click="reject">Send back</button>
                            <button type="button" class="btn btn-good grow" wire:click="approve">Approve</button>
                        </div>
                    </div>
                </div>
            @elseif ($offer?->review_note)
                <div class="card"><div class="note"><b>Review note:</b> {{ $offer->review_note }}</div></div>
            @endif

            @if ($offer?->isManufacturer() && $requiresOptIn)
                <div class="card">
                    <div class="card-head">Participating dealers · {{ $offer->participants->count() }}</div>
                    @forelse ($offer->participants as $dealer)
                        <div class="who"><div>{{ $dealer->name }} <span class="meta">since {{ $dealer->pivot->created_at?->format('M j') }}</span></div></div>
                    @empty
                        <div class="who-empty">No dealers have opted in yet. {{ $offer->make }} dealers see this offer in their portal under Offers and can switch it on.</div>
                    @endforelse
                </div>
            @endif
        </div>
    </div>
</div>
