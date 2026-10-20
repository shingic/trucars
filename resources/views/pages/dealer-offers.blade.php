<?php

use App\Livewire\Concerns\EditsOffer;
use App\Models\AuditLog;
use App\Models\Offer;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
| Dealer portal — Offers. Two things only:
|   - Manufacturer offers for this dealer's franchise brand, set up by TruCars:
|     switch participation on or off. The terms can't be changed here.
|   - The dealer's own promotions: create or edit, then they go to TruCars for
|     approval before they show. Editing an approved one sends it back for review.
| Everything is fenced to the signed-in staff member's dealer; EnsureUserIsStaff
| runs on the page and every action.
*/
new #[Layout('layouts.dealer')] #[Title('Offers')] class extends Component {
    use EditsOffer;

    /** Null = not editing; 0 = new promotion; otherwise the promotion's id. */
    #[Locked]
    public ?int $editingId = null;

    public string $statusMessage = '';

    private function dealer()
    {
        abort_unless(auth()->user()?->isDealerStaff() && ! auth()->user()->isSuspended(), 403);

        return auth()->user()->dealer;
    }

    private function canChange(): void
    {
        abort_if($this->dealer()->isSuspended(), 403);
    }

    /** Manufacturer offers for this dealer's brand that haven't ended. */
    public function manufacturerOffers(): Collection
    {
        $make = mb_strtolower(trim((string) $this->dealer()->franchise_make));

        if ($make === '') {
            return collect();
        }

        return Offer::query()
            ->where('source', Offer::SOURCE_MANUFACTURER)
            ->where('status', Offer::STATUS_PUBLISHED)
            ->whereRaw('lower(make) = ?', [$make])
            ->whereDate('ends_on', '>=', now()->toDateString())
            ->with(['participants' => fn ($dealers) => $dealers->whereKey($this->dealer()->id)])
            ->orderBy('starts_on')
            ->get()
            ->filter(fn (Offer $offer) => ! $offer->provinces || in_array($this->dealer()->province, $offer->provinces, true))
            ->values();
    }

    public function myPromotions(): Collection
    {
        return Offer::where('source', Offer::SOURCE_DEALER)->where('dealer_id', $this->dealer()->id)
            ->where('status', '!=', Offer::STATUS_ARCHIVED)->orderByDesc('starts_on')->get();
    }

    public function toggleParticipation(int $offerId): void
    {
        $this->canChange();
        $offer = $this->manufacturerOffers()->firstWhere('id', $offerId) ?? abort(404);
        abort_unless($offer->requires_opt_in, 422);

        $dealer = $this->dealer();

        if ($offer->participants->contains('id', $dealer->id)) {
            $offer->participants()->detach($dealer->id);
            AuditLog::record('offer.opted_out', $offer, null, ['dealer_id' => $dealer->id]);
            $this->statusMessage = "You’ve left “{$offer->title}”. It no longer shows on your cars.";
        } else {
            $offer->participants()->attach($dealer->id, ['opted_in_by_user_id' => auth()->id()]);
            AuditLog::record('offer.opted_in', $offer, null, ['dealer_id' => $dealer->id]);
            $this->statusMessage = "You’re in. “{$offer->title}” shows on your eligible cars " . ($offer->state() === 'scheduled' ? 'from ' . $offer->starts_on->format('F j') : 'now') . '.';
        }
    }

    public function newPromotion(): void
    {
        $this->canChange();
        $this->resetValidation();
        $this->reset(['type', 'title', 'models', 'yearMin', 'yearMax', 'provinces', 'apr', 'termMonths', 'lender', 'cashAmount', 'benefit', 'conditions', 'startsOn', 'endsOn', 'combinable']);
        $this->type = Offer::TYPE_CASH;
        $this->title = 'CPO cash discount';
        $this->certifiedOnly = false;
        $this->make = (string) $this->dealer()->franchise_make;
        $this->defaultOfferDates();
        $this->editingId = 0;
    }

    public function editPromotion(int $offerId): void
    {
        $this->canChange();
        $offer = $this->myPromotions()->firstWhere('id', $offerId) ?? abort(404);
        $this->resetValidation();
        $this->fillOfferForm($offer);
        $this->editingId = $offer->id;
    }

    public function cancelEdit(): void
    {
        $this->editingId = null;
    }

    /** Save and send to TruCars for approval. */
    public function submitPromotion(): void
    {
        $this->canChange();
        $attributes = $this->validatedOfferAttributes();
        $dealer = $this->dealer();

        $offer = $this->editingId
            ? ($this->myPromotions()->firstWhere('id', $this->editingId) ?? abort(404))
            : new Offer(['source' => Offer::SOURCE_DEALER, 'dealer_id' => $dealer->id, 'created_by_user_id' => auth()->id()]);

        $before = $offer->exists ? $offer->only(array_keys($attributes)) + ['status' => $offer->status] : null;
        $offer->fill($attributes + ['requires_opt_in' => false]);
        $offer->forceFill(['status' => Offer::STATUS_PENDING, 'review_note' => null])->save();

        AuditLog::record($before === null ? 'offer.submitted' : 'offer.resubmitted', $offer, $before, $offer->only(array_keys($attributes)));

        $this->editingId = null;
        $this->statusMessage = 'Sent to TruCars for approval. It shows on your cars once approved, within its dates.';
    }

    public function withdrawPromotion(int $offerId): void
    {
        $this->canChange();
        $offer = $this->myPromotions()->firstWhere('id', $offerId) ?? abort(404);
        $before = $offer->status;
        $offer->forceFill(['status' => Offer::STATUS_ARCHIVED])->save();
        AuditLog::record('offer.withdrawn', $offer, ['status' => $before], ['status' => Offer::STATUS_ARCHIVED]);
        $this->statusMessage = 'Promotion withdrawn.';
    }
}; ?>

@push('crumb')
    <span>Marketing</span>
    <span>›</span>
    <span class="cur">Offers</span>
@endpush

@push('styles')
    <style>
        .do-head h1 { font-size:24px; font-weight:800; letter-spacing:-.02em; margin:0 0 6px; }
        .do-head p { margin:0 0 20px; font-size:14px; color:var(--ink-2); max-width:680px; }
        .msg { background:var(--good-soft); color:var(--good-ink); border:1px solid rgba(18,184,134,.25); border-radius:var(--radius-sm); padding:11px 15px; font-size:13.5px; font-weight:600; margin-bottom:16px; }
        .sec-t { display:flex; align-items:center; gap:10px; margin:26px 0 12px; }
        .sec-t h2 { font-size:17px; font-weight:800; margin:0; }
        .sec-t .spacer { flex:1; }
        .sec-t .sub { font-size:12.5px; color:var(--ink-3); }
        .offers { display:grid; grid-template-columns:repeat(auto-fill, minmax(320px, 1fr)); gap:14px; }
        .oc { background:var(--card); border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow-sm); overflow:hidden; display:flex; flex-direction:column; }
        .oc.in { border-color:var(--good-ink); box-shadow:0 0 0 3px var(--good-soft); }
        .oc-top { padding:18px 18px 12px; }
        .oc-k { font-size:11px; font-weight:800; letter-spacing:.06em; text-transform:uppercase; color:var(--ink-3); }
        .oc-big { font-size:28px; font-weight:800; letter-spacing:-.03em; margin-top:6px; }
        .oc-t { font-size:14px; font-weight:700; margin-top:2px; }
        .oc-m { font-size:12.5px; color:var(--ink-3); margin-top:6px; line-height:1.5; }
        .oc-fine { font-size:12px; color:var(--ink-2); background:var(--bg); padding:10px 18px; border-top:1px solid var(--line); }
        .oc-foot { margin-top:auto; display:flex; align-items:center; gap:10px; padding:12px 18px; border-top:1px solid var(--line); }
        .oc-foot .when { font-size:12.5px; font-weight:600; color:var(--ink-2); }
        .oc-foot .spacer { flex:1; }
        .switch { display:inline-flex; align-items:center; gap:9px; cursor:pointer; font-size:13px; font-weight:700; color:var(--ink-2); }
        .switch .track { width:40px; height:23px; border-radius:999px; background:var(--line-strong); position:relative; transition:background .15s ease; }
        .switch .knob { position:absolute; top:3px; left:3px; width:17px; height:17px; border-radius:50%; background:#fff; box-shadow:0 1px 3px rgba(0,0,0,.2); transition:transform .15s ease; }
        .switch.on .track { background:var(--good-ink); }
        .switch.on .knob { transform:translateX(17px); }
        .switch.on { color:var(--good-ink); }
        .auto { font-size:12.5px; font-weight:700; color:var(--good-ink); }
        .pill { font-size:10.5px; font-weight:800; letter-spacing:.05em; text-transform:uppercase; padding:3px 9px; border-radius:var(--radius-pill); background:var(--bg); border:1px solid var(--line); color:var(--ink-2); }
        .pill.live { background:var(--good-soft); color:var(--good-ink); border-color:transparent; }
        .pill.scheduled { background:#E3EDFB; color:#2E5FA3; border-color:transparent; }
        .pill.pending { background:var(--amber-soft); color:var(--amber); border-color:transparent; }
        .pill.rejected { background:var(--primary-soft); color:var(--primary-press); border-color:transparent; }
        .btn { display:inline-flex; align-items:center; gap:7px; font-size:13.5px; font-weight:700; border-radius:var(--radius-pill); padding:9px 16px; white-space:nowrap; }
        .btn svg { width:15px; height:15px; }
        .btn-primary { background:var(--primary); color:#fff; }
        .btn-primary:hover { background:var(--primary-press); }
        .btn-ghost { border:1px solid var(--line-strong); color:var(--ink); background:var(--card); }
        .btn-quiet { color:var(--ink-3); font-size:12.5px; font-weight:700; }
        .btn-quiet:hover { color:var(--primary-press); }
        .empty { background:var(--card); border:1px dashed var(--line-strong); border-radius:var(--radius); padding:26px; text-align:center; font-size:13.5px; color:var(--ink-3); }
        .editor { display:grid; grid-template-columns:minmax(0, 1fr) 340px; gap:18px; align-items:start; }
        .card { background:var(--card); border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow-sm); overflow:hidden; }
        .card .actions { display:flex; gap:8px; justify-content:flex-end; padding:14px 20px; border-top:1px solid var(--line); }
        .note { font-size:12.5px; color:var(--primary-press); font-weight:600; padding:10px 18px; background:var(--primary-soft); }
        @media (max-width:1000px) { .editor { grid-template-columns:minmax(0, 1fr); } }
    </style>
@endpush

@php $dealer = auth()->user()->dealer; $manufacturer = $this->manufacturerOffers(); $mine = $this->myPromotions(); @endphp

<div>
    <div class="do-head">
        <h1>Offers</h1>
        <p>Switch on the manufacturer programs you take part in, and add your own promotions. Offers show on your eligible cars automatically, only within their dates.</p>
    </div>

    @if ($statusMessage !== '') <div class="msg">{{ $statusMessage }}</div> @endif

    <div class="sec-t">
        <h2>{{ $dealer->franchise_make ? $dealer->franchise_make . ' manufacturer offers' : 'Manufacturer offers' }}</h2>
        <span class="sub">Set up by TruCars · terms are the manufacturer’s</span>
    </div>
    @if (! $dealer->franchise_make)
        <div class="empty">TruCars hasn’t confirmed your franchise brand yet, so no manufacturer offers apply. Contact TruCars to confirm it.</div>
    @elseif ($manufacturer->isEmpty())
        <div class="empty">No {{ $dealer->franchise_make }} offers are running or scheduled right now.</div>
    @else
        <div class="offers">
            @foreach ($manufacturer as $offer)
                @php $in = ! $offer->requires_opt_in || $offer->participants->isNotEmpty(); @endphp
                <div class="oc {{ $in ? 'in' : '' }}" wire:key="m-{{ $offer->id }}">
                    <div class="oc-top">
                        <div class="oc-k">{{ $offer->title }} · <span class="pill {{ $offer->state() }}">{{ $offer->state() }}</span></div>
                        <div class="oc-big">{{ $offer->headline() }}</div>
                        @if ($offer->detail()) <div class="oc-t">{{ $offer->detail() }}</div> @endif
                        <div class="oc-m">
                            {{ $offer->models ? implode(', ', $offer->models) : 'All ' . $offer->make . ' models' }}{{ $offer->year_min || $offer->year_max ? ' · ' . ($offer->year_min ?? '…') . '–' . ($offer->year_max ?? '…') : '' }}{{ $offer->certified_only ? ' · certified only' : '' }}
                        </div>
                    </div>
                    <div class="oc-fine">{{ $offer->conditions }}</div>
                    <div class="oc-foot">
                        <span class="when">{{ $offer->starts_on->format('M j') }} – {{ $offer->ends_on->format('M j, Y') }}</span>
                        <span class="spacer"></span>
                        @if ($offer->requires_opt_in)
                            <button type="button" class="switch {{ $in ? 'on' : '' }}" wire:click="toggleParticipation({{ $offer->id }})" @disabled($dealer->isSuspended()) aria-pressed="{{ $in ? 'true' : 'false' }}">
                                {{ $in ? 'Participating' : 'Not participating' }}
                                <span class="track"><span class="knob"></span></span>
                            </button>
                        @else
                            <span class="auto">✓ Applies automatically</span>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <div class="sec-t">
        <h2>Your promotions</h2>
        <span class="sub">Dealer-funded · approved by TruCars before they show</span>
        <span class="spacer"></span>
        @if ($editingId === null && ! $dealer->isSuspended())
            <button type="button" class="btn btn-primary" wire:click="newPromotion">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                New promotion
            </button>
        @endif
    </div>

    @if ($editingId !== null)
        <div class="editor">
            <div class="card">
                @include('partials.offer-form')
                <div class="actions">
                    <button type="button" class="btn btn-ghost" wire:click="cancelEdit">Cancel</button>
                    <button type="button" class="btn btn-primary" wire:click="submitPromotion">Send for approval</button>
                </div>
            </div>
            <div class="card" style="padding:16px">
                <div class="oc-k" style="margin-bottom:10px">Customer preview</div>
                @include('partials.offer-card', ['offer' => $this->previewOffer()])
            </div>
        </div>
    @elseif ($mine->isEmpty())
        <div class="empty">No promotions yet. Add a cash discount, a rate offer or a warranty bonus on your own cars.</div>
    @else
        <div class="offers">
            @foreach ($mine as $offer)
                <div class="oc" wire:key="d-{{ $offer->id }}">
                    <div class="oc-top">
                        <div class="oc-k">{{ $offer->title }} · <span class="pill {{ $offer->state() }}">{{ $offer->state() === 'pending' ? 'Awaiting approval' : $offer->state() }}</span></div>
                        <div class="oc-big">{{ $offer->headline() }}</div>
                        <div class="oc-m">{{ $offer->make }} {{ $offer->models ? '· ' . implode(', ', $offer->models) : '' }}</div>
                    </div>
                    @if ($offer->status === 'rejected' && $offer->review_note) <div class="note">TruCars: {{ $offer->review_note }}</div> @endif
                    <div class="oc-foot">
                        <span class="when">{{ $offer->starts_on->format('M j') }} – {{ $offer->ends_on->format('M j, Y') }}</span>
                        <span class="spacer"></span>
                        @unless ($dealer->isSuspended())
                            <button type="button" class="btn-quiet" wire:click="withdrawPromotion({{ $offer->id }})" wire:confirm="Withdraw this promotion?">Withdraw</button>
                            <button type="button" class="btn btn-ghost" wire:click="editPromotion({{ $offer->id }})">Edit</button>
                        @endunless
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
