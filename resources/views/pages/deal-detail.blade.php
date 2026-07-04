<?php

use App\Models\Deal;
use App\Models\DealDocument;
use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

new #[Layout('layouts.dealer')] #[Title('Reservation')] class extends Component {
    /**
     * The reservation being worked. Route-model-bound, so it arrives already
     * fenced to the signed-in dealership: DealerScope is on Deal, so a deal
     * belonging to another rooftop 404s before this component ever loads. The
     * EnsureUserIsStaff middleware on the route has already refused buyers.
     */
    public Deal $deal;

    /**
     * Which document (if any) the manager is currently writing a re-upload
     * reason for. Null means the inline reason box is closed everywhere.
     */
    public ?int $documentBeingRejected = null;

    public string $rejectionReason = '';

    public function mount(Deal $deal): void
    {
        $this->deal = $deal;
    }

    /**
     * Move the reservation one step along the pipeline and leave a status note
     * on the trail — the same advance logic the reservations inbox uses, mirrored
     * here so the manager can work the deal without bouncing back to the list.
     */
    public function advanceStage(): void
    {
        $nextStage = Deal::NEXT_STAGE[$this->deal->stage] ?? null;

        if ($nextStage === null) {
            return;
        }

        $this->deal->update(['stage' => $nextStage]);

        $this->deal->recordActivity(
            'status',
            'Stage moved to ' . Deal::STAGE_LABELS[$nextStage] . '.',
            Auth::user()->name ?? 'Dealer',
        );
    }

    /**
     * Accept a buyer-uploaded document. Only an actually-uploaded file can be
     * approved (the pre-cleared licence and not-yet-uploaded rows have no file),
     * so we guard on file_path before flipping it to Approved.
     */
    public function approveDocument(int $documentId): void
    {
        $document = $this->deal->documents()->whereKey($documentId)->first();

        if ($document === null || $document->file_path === null) {
            return;
        }

        $document->update([
            'status'  => 'Approved',
            'is_done' => true,
        ]);

        $this->deal->recordActivity(
            'document',
            'Approved ' . $document->name . '.',
            Auth::user()->name ?? 'Dealer',
            'outbound',
        );

        $this->documentBeingRejected = null;
    }

    /** Open the inline re-upload reason box against one document. */
    public function startRejection(int $documentId): void
    {
        $this->documentBeingRejected = $documentId;
        $this->rejectionReason = '';
        $this->resetValidation();
    }

    /** Close the re-upload reason box without sending anything. */
    public function cancelRejection(): void
    {
        $this->documentBeingRejected = null;
        $this->rejectionReason = '';
        $this->resetValidation();
    }

    /**
     * Send a document back to the buyer for re-upload. Flipping is_done to false
     * drops it back into the outstanding pile in My Garage (the buyer's Upload
     * button reappears), and the optional reason rides along on the activity
     * trail so there's a record of why. Buyer-facing email is deliberately left
     * out of this slice — this is activity-only for now.
     */
    public function requestReupload(int $documentId): void
    {
        $this->validate(
            ['rejectionReason' => ['nullable', 'string', 'max:200']],
            attributes: ['rejectionReason' => 'reason'],
        );

        $document = $this->deal->documents()->whereKey($documentId)->first();

        if ($document === null) {
            return;
        }

        $document->update([
            'status'  => 'Needs re-upload',
            'is_done' => false,
        ]);

        $trimmedReason = trim($this->rejectionReason);
        $reasonSuffix = $trimmedReason !== '' ? ' — ' . $trimmedReason : '';

        $this->deal->recordActivity(
            'document',
            'Requested re-upload of ' . $document->name . $reasonSuffix,
            Auth::user()->name ?? 'Dealer',
            'outbound',
        );

        $this->documentBeingRejected = null;
        $this->rejectionReason = '';
    }

    /**
     * Stream a buyer-uploaded document back to the manager. The lookup runs
     * through the deal's own documents relation, which is fenced to this dealer
     * through the deal, so there's no way to pull a file off someone else's deal
     * by guessing an id. The file lives on the private deal_documents disk.
     */
    public function downloadDocument(int $documentId)
    {
        $document = $this->deal->documents()->whereKey($documentId)->first();

        if ($document === null || $document->file_path === null) {
            return null;
        }

        $extension = pathinfo($document->file_path, PATHINFO_EXTENSION);
        $downloadName = Str::slug($this->deal->reference . '-' . $document->name)
            . ($extension !== '' ? '.' . $extension : '');

        return Storage::disk('deal_documents')->download($document->file_path, $downloadName);
    }

    /**
     * The display state for one document row, collapsing status + is_done +
     * file_path into a single word the view can switch on:
     *   cleared  — verified at checkout, no file to review (the licence)
     *   waiting  — buyer hasn't uploaded yet
     *   uploaded — file in, awaiting the manager's review
     *   approved — manager accepted it
     *   rejected — sent back, waiting on a fresh upload
     */
    public function documentState(DealDocument $document): string
    {
        if ($document->is_done && $document->file_path === null) {
            return 'cleared';
        }

        if ($document->file_path === null) {
            return 'waiting';
        }

        if ($document->status === 'Approved') {
            return 'approved';
        }

        if (! $document->is_done) {
            return 'rejected';
        }

        return 'uploaded';
    }

    public function purchaseTypeLabel(): string
    {
        return match ($this->deal->purchase_type) {
            'finance' => 'Financing',
            'lease'   => 'Lease',
            'cash'    => 'Cash purchase',
            default   => ucfirst((string) $this->deal->purchase_type),
        };
    }

    /** True when the buyer flagged any non-binding coverage interest at checkout. */
    public function hasCoverageInterest(): bool
    {
        return ! empty($this->deal->warranty_plan) || ! empty($this->deal->extras_interest);
    }

    /** Documents received / total, for the panel header count. */
    public function documentsReceived(): int
    {
        return $this->deal->documents()->where('is_done', true)->count();
    }

    /**
     * A flattened, render-ready row per document so the view never calls a
     * method inside its loop or reaches for an inline PHP block — each row
     * already carries its collapsed display state. Queried fresh through the
     * relation method so it always reflects the latest approve / re-upload.
     */
    public function documentRows(): \Illuminate\Support\Collection
    {
        return $this->deal->documents()->get()->map(function (DealDocument $document) {
            return [
                'id'            => $document->id,
                'name'          => $document->name,
                'status'        => $document->status,
                'uploadedLabel' => $document->uploaded_at?->diffForHumans(),
                'state'         => $this->documentState($document),
            ];
        });
    }

    /**
     * Everything the template renders that must stay fresh across re-renders,
     * resolved once per render. Querying through the relation methods (not the
     * cached relations) means an approve, re-upload or stage change shows
     * immediately, and the activity feed is always newest-first no matter how
     * the component was hydrated on this request.
     */
    public function with(): array
    {
        return [
            'feeBuckets'   => $this->deal->fees_by_kind,
            'documentRows' => $this->documentRows(),
            'activityFeed' => $this->deal->activities()->latest()->get(),
        ];
    }
}; ?>

@push('styles')
    <style>
        .deal-detail { max-width:1140px; }

        .detail-back { display:inline-flex; align-items:center; gap:7px; font-size:13px; font-weight:600; color:var(--ink-2); margin-bottom:16px; transition:color .15s ease; }
        .detail-back:hover { color:var(--ink); }
        .detail-back svg { width:16px; height:16px; }

        .detail-head { display:flex; align-items:flex-start; justify-content:space-between; gap:18px; margin-bottom:22px; flex-wrap:wrap; }
        .detail-title h1 { font-size:25px; font-weight:800; letter-spacing:-.025em; margin:0; }
        .detail-title .sub { font-size:13.5px; color:var(--ink-2); margin-top:5px; }
        .detail-head-right { display:flex; align-items:center; gap:10px; flex-wrap:wrap; justify-content:flex-end; }

        .deposit-chip { display:inline-flex; align-items:center; gap:6px; font-size:12px; font-weight:700; padding:6px 11px; border-radius:var(--radius-pill); background:var(--good-soft); color:var(--good-ink); white-space:nowrap; }
        .deposit-chip svg { width:13px; height:13px; }

        .adv-btn { font-size:12.5px; font-weight:700; color:#fff; background:var(--btn); padding:9px 16px; border-radius:var(--radius-pill); white-space:nowrap; transition:background .15s ease; }
        .adv-btn:hover { background:var(--primary); }
        .adv-btn[disabled] { opacity:.55; cursor:wait; }
        .adv-done { font-size:12.5px; font-weight:700; color:var(--ink-3); }

        /* Stage pill (shared vocabulary with the inbox) */
        .stage { display:inline-flex; align-items:center; gap:6px; font-size:12px; font-weight:700; padding:5px 11px; border-radius:var(--radius-pill); white-space:nowrap; }
        .stage .dot { width:7px; height:7px; border-radius:50%; }
        .stage.reserved { background:#FDEAD9; color:#B5611A; } .stage.reserved .dot { background:#F5631F; }
        .stage.contacted { background:#FCF3D7; color:#9A7B1B; } .stage.contacted .dot { background:#E3B53A; }
        .stage.appointment_set { background:#E3EDFB; color:#2E5FA3; } .stage.appointment_set .dot { background:#4A82D6; }
        .stage.financing { background:#EFE7FB; color:#6B47B5; } .stage.financing .dot { background:#8B5FE0; }
        .stage.documents { background:#E0F2F4; color:#1F7A85; } .stage.documents .dot { background:#2BA3B2; }
        .stage.ready_for_delivery { background:#DDF5EB; color:#0E8A60; } .stage.ready_for_delivery .dot { background:#12B886; }
        .stage.delivered { background:rgba(22,24,29,.07); color:var(--ink-2); } .stage.delivered .dot { background:var(--ink-3); }
        .stage.cancelled { background:#FBE4E4; color:#B23A3A; } .stage.cancelled .dot { background:#D65454; }

        .detail-grid { display:grid; grid-template-columns:1fr 332px; gap:20px; align-items:start; }
        .col-main { display:flex; flex-direction:column; gap:16px; min-width:0; }
        .col-side { display:flex; flex-direction:column; gap:16px; position:sticky; top:78px; }

        .panel { background:var(--card); border:1px solid var(--line); border-radius:var(--radius); overflow:hidden; }
        .panel-head { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:14px 18px; border-bottom:1px solid var(--line); }
        .panel-head h3 { font-size:14.5px; font-weight:800; letter-spacing:-.01em; margin:0; }
        .panel-head .ph-right { font-size:12px; font-weight:600; color:var(--ink-3); }
        .panel-body { padding:15px 18px; }

        .kv { display:flex; flex-direction:column; }
        .kv .row { display:flex; align-items:baseline; justify-content:space-between; gap:16px; padding:9px 0; border-bottom:1px solid var(--line); font-size:13.5px; }
        .kv .row:first-child { padding-top:0; }
        .kv .row:last-child { border-bottom:none; padding-bottom:0; }
        .kv .k { color:var(--ink-3); font-weight:600; flex-shrink:0; }
        .kv .v { color:var(--ink); font-weight:600; text-align:right; }
        .kv .v.mono { font-family:var(--mono); font-size:12.5px; letter-spacing:-.01em; }

        .id-badge { display:inline-flex; align-items:center; gap:6px; font-size:11.5px; font-weight:700; padding:5px 10px; border-radius:var(--radius-pill); }
        .id-badge svg { width:12px; height:12px; }
        .id-badge.ok { background:var(--good-soft); color:var(--good-ink); }
        .id-badge.no { background:var(--amber-soft); color:var(--amber); }

        .pill-status { font-size:11.5px; font-weight:700; padding:5px 10px; border-radius:var(--radius-pill); white-space:nowrap; }
        .pill-status.preliminary { background:var(--coral-soft); color:#C85A14; }

        .estimate-line { display:flex; align-items:baseline; justify-content:space-between; gap:12px; margin-top:14px; padding-top:14px; border-top:1px solid var(--line); }
        .estimate-line .lbl { font-size:11px; font-weight:700; letter-spacing:.04em; text-transform:uppercase; color:var(--ink-3); }
        .estimate-line .amt { font-size:20px; font-weight:800; letter-spacing:-.02em; }

        .chips { display:flex; flex-wrap:wrap; gap:8px; }
        .chip { font-size:12px; font-weight:600; padding:6px 11px; border-radius:var(--radius-pill); background:var(--active); color:var(--ink-2); }
        .chip.warranty { background:var(--primary-soft); color:var(--primary-press); }

        .note { font-size:12px; color:var(--ink-3); line-height:1.55; margin:14px 0 0; }
        .note.tight { margin-top:10px; }

        .fee-row { display:flex; align-items:baseline; justify-content:space-between; gap:12px; padding:9px 0; border-bottom:1px solid var(--line); font-size:13.5px; }
        .fee-row:last-child { border-bottom:none; }
        .fee-row .fee-label { color:var(--ink); font-weight:600; }
        .fee-row .fee-amt { color:var(--ink-2); font-weight:600; font-variant-numeric:tabular-nums; }
        .fee-group-label { font-size:11px; font-weight:700; letter-spacing:.05em; text-transform:uppercase; color:var(--ink-3); margin:16px 0 4px; }
        .fee-group-label:first-child { margin-top:0; }
        .fee-total { display:flex; align-items:baseline; justify-content:space-between; gap:12px; margin-top:6px; padding-top:11px; border-top:1.5px solid var(--line-strong); font-size:13.5px; font-weight:800; }

        /* Documents */
        .doc-row { padding:14px 0; border-bottom:1px solid var(--line); }
        .doc-row:first-child { padding-top:0; }
        .doc-row:last-child { border-bottom:none; padding-bottom:0; }
        .doc-top { display:flex; align-items:center; gap:13px; }
        .doc-ic { width:34px; height:34px; border-radius:10px; display:grid; place-items:center; flex-shrink:0; }
        .doc-ic svg { width:17px; height:17px; }
        .doc-ic.done { background:var(--good-soft); color:var(--good-ink); }
        .doc-ic.review { background:var(--coral-soft); color:#C85A14; }
        .doc-ic.wait { background:rgba(22,24,29,.05); color:var(--ink-3); }
        .doc-ic.flag { background:var(--amber-soft); color:var(--amber); }
        .doc-main { flex:1; min-width:0; }
        .doc-name { font-size:13.5px; font-weight:700; }
        .doc-status { font-size:12px; color:var(--ink-3); margin-top:2px; }
        .doc-actions { display:flex; align-items:center; gap:8px; flex-wrap:wrap; flex-shrink:0; }

        .mini-btn { font-size:12px; font-weight:700; padding:7px 12px; border-radius:var(--radius-pill); white-space:nowrap; transition:all .15s ease; border:1.5px solid transparent; }
        .mini-btn[disabled] { opacity:.55; cursor:wait; }
        .mini-btn.approve { background:var(--good); color:#fff; }
        .mini-btn.approve:hover { filter:brightness(.95); }
        .mini-btn.ghost { color:var(--ink-2); border-color:var(--line-strong); }
        .mini-btn.ghost:hover { border-color:var(--ink); color:var(--ink); }
        .mini-btn.flag { color:#C85A14; border-color:rgba(200,90,20,.35); }
        .mini-btn.flag:hover { background:var(--coral-soft); }

        .doc-state { font-size:11.5px; font-weight:700; padding:5px 10px; border-radius:var(--radius-pill); white-space:nowrap; }
        .doc-state.approved { background:var(--good-soft); color:var(--good-ink); }
        .doc-state.cleared { background:var(--good-soft); color:var(--good-ink); }
        .doc-state.waiting { background:rgba(22,24,29,.06); color:var(--ink-3); }
        .doc-state.rejected { background:var(--amber-soft); color:var(--amber); }

        .reject-box { margin-top:12px; margin-left:47px; background:var(--bg); border:1px solid var(--line); border-radius:var(--radius-sm); padding:13px; }
        .reject-box label { font-size:12px; font-weight:600; color:var(--ink-2); display:block; margin-bottom:7px; }
        .reject-box textarea { width:100%; border:1.5px solid var(--line-strong); border-radius:10px; padding:10px 12px; font-size:13px; font-family:inherit; color:var(--ink); outline:none; resize:vertical; min-height:62px; background:#fff; }
        .reject-box textarea:focus { border-color:var(--primary); box-shadow:0 0 0 4px var(--primary-soft); }
        .reject-box-actions { display:flex; gap:9px; justify-content:flex-end; margin-top:11px; }
        .reject-err { font-size:12px; color:var(--primary-press); font-weight:600; margin-top:7px; }

        /* Activity log */
        .act-list { display:flex; flex-direction:column; }
        .act-item { display:flex; gap:11px; padding:12px 0; border-bottom:1px solid var(--line); }
        .act-item:first-child { padding-top:0; }
        .act-item:last-child { border-bottom:none; padding-bottom:0; }
        .act-dot { width:9px; height:9px; border-radius:50%; margin-top:5px; flex-shrink:0; background:var(--ink-3); }
        .act-dot.status { background:#8B5FE0; }
        .act-dot.document { background:#2BA3B2; }
        .act-dot.sms { background:var(--good); }
        .act-dot.email { background:#4A82D6; }
        .act-dot.note { background:var(--coral); }
        .act-body { font-size:13px; line-height:1.5; color:var(--ink); }
        .act-meta { font-size:11px; color:var(--ink-3); margin-top:3px; }

        .empty-line { font-size:13px; color:var(--ink-3); padding:6px 0; }

        @media (max-width:920px){
            .detail-grid { grid-template-columns:1fr; }
            .col-side { position:static; }
        }
    </style>
@endpush

@push('crumb')
    Dealer <span>·</span> <a href="{{ route('dealer.reservations') }}">Reservations</a> <span>·</span> <span class="cur">{{ $deal->customer_full_name }}</span>
@endpush

<div class="deal-detail">
    <a href="{{ route('dealer.reservations') }}" class="detail-back">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M11 6l-6 6 6 6"/></svg>
        Back to reservations
    </a>

    <div class="detail-head">
        <div class="detail-title">
            <h1>{{ $deal->customer_full_name }}</h1>
            <div class="sub">
                Reservation {{ $deal->reference }} ·
                {{ $deal->vehicle->model_year }} {{ $deal->vehicle->make }} {{ $deal->vehicle->model }}{{ $deal->vehicle->trim ? ' ' . $deal->vehicle->trim : '' }} ·
                reserved {{ $deal->created_at->diffForHumans() }}
            </div>
        </div>

        <div class="detail-head-right">
            <span class="deposit-chip">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 4.5 4.5L19 7"/></svg>
                $150 deposit held
            </span>

            <span class="stage {{ $deal->stage }}">
                <span class="dot"></span> {{ $deal->stage_label }}
            </span>

            @if (isset(App\Models\Deal::NEXT_STAGE[$deal->stage]))
                <button type="button"
                        class="adv-btn"
                        wire:click="advanceStage"
                        wire:loading.attr="disabled"
                        wire:target="advanceStage">
                    Mark {{ App\Models\Deal::STAGE_LABELS[App\Models\Deal::NEXT_STAGE[$deal->stage]] }}
                </button>
            @else
                <span class="adv-done">{{ $deal->stage_label }}</span>
            @endif
        </div>
    </div>

    <div class="detail-grid">
        <div class="col-main">

            {{-- Customer & identity --}}
            <div class="panel">
                <div class="panel-head">
                    <h3>Customer &amp; identity</h3>
                    @if ($deal->identity_verified_at)
                        <span class="id-badge ok">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 4.5 4.5L19 7"/></svg>
                            Identity verified
                        </span>
                    @else
                        <span class="id-badge no">Not verified</span>
                    @endif
                </div>
                <div class="panel-body">
                    <div class="kv">
                        <div class="row"><span class="k">Name</span><span class="v">{{ $deal->customer_full_name }}</span></div>
                        <div class="row"><span class="k">Phone</span><span class="v"><a href="tel:{{ $deal->phone }}">{{ $deal->phone }}</a></span></div>
                        <div class="row"><span class="k">Email</span><span class="v"><a href="mailto:{{ $deal->email }}">{{ $deal->email }}</a></span></div>
                        @if ($deal->street_address || $deal->city)
                            <div class="row">
                                <span class="k">Address</span>
                                <span class="v">{{ collect([$deal->street_address, $deal->city, $deal->province, $deal->postal_code])->filter()->implode(', ') }}</span>
                            </div>
                        @endif
                        @if ($deal->identity_verified_at)
                            <div class="row"><span class="k">Verified</span><span class="v">{{ $deal->identity_verified_at->format('M j, Y · g:i a') }}</span></div>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Vehicle --}}
            <div class="panel">
                <div class="panel-head">
                    <h3>Vehicle</h3>
                    <span class="ph-right">${{ number_format($deal->vehicle->price_in_cents / 100) }}</span>
                </div>
                <div class="panel-body">
                    <div class="kv">
                        <div class="row">
                            <span class="k">Vehicle</span>
                            <span class="v">{{ $deal->vehicle->model_year }} {{ $deal->vehicle->make }} {{ $deal->vehicle->model }}{{ $deal->vehicle->trim ? ' ' . $deal->vehicle->trim : '' }}</span>
                        </div>
                        <div class="row"><span class="k">Advertised price</span><span class="v">${{ number_format($deal->vehicle->price_in_cents / 100) }}</span></div>
                        @if ($deal->vehicle->vin)
                            <div class="row"><span class="k">VIN</span><span class="v mono">{{ $deal->vehicle->vin }}</span></div>
                        @endif
                        @if ($deal->vehicle->stock_number)
                            <div class="row"><span class="k">Stock #</span><span class="v">{{ $deal->vehicle->stock_number }}</span></div>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Trade-in (read-only this slice — buyer's self-reported, non-binding) --}}
            @if ($deal->tradeIn)
                <div class="panel">
                    <div class="panel-head">
                        <h3>Trade-in</h3>
                        <span class="pill-status preliminary">Preliminary · confirmed after inspection</span>
                    </div>
                    <div class="panel-body">
                        <div class="kv">
                            <div class="row">
                                <span class="k">Trade vehicle</span>
                                <span class="v">{{ $deal->tradeIn->model_year }} {{ $deal->tradeIn->make }} {{ $deal->tradeIn->model }}{{ $deal->tradeIn->trim ? ' ' . $deal->tradeIn->trim : '' }}</span>
                            </div>
                            <div class="row"><span class="k">Odometer</span><span class="v">{{ number_format($deal->tradeIn->kilometres) }} km</span></div>
                            @if ($deal->tradeIn->exterior_colour)
                                <div class="row"><span class="k">Colour</span><span class="v">{{ ucfirst($deal->tradeIn->exterior_colour) }}</span></div>
                            @endif
                            <div class="row"><span class="k">Accident history</span><span class="v">{{ ucwords(str_replace('_', ' ', $deal->tradeIn->accident_history ?? 'not stated')) }}</span></div>
                            <div class="row"><span class="k">Owners</span><span class="v">{{ $deal->tradeIn->owner_count ?? '—' }}</span></div>
                            <div class="row"><span class="k">Title</span><span class="v">{{ ucwords(str_replace('_', ' ', $deal->tradeIn->title_status ?? 'not stated')) }}</span></div>
                            <div class="row"><span class="k">Keys</span><span class="v">{{ $deal->tradeIn->key_count ?? '—' }}</span></div>
                            @if (($deal->tradeIn->lien_owing_in_cents ?? 0) > 0)
                                <div class="row"><span class="k">Lien owing</span><span class="v">${{ number_format($deal->tradeIn->lien_owing_in_cents / 100) }}</span></div>
                            @endif
                        </div>

                        @if ($deal->tradeIn->estimated_value_low_in_cents && $deal->tradeIn->estimated_value_high_in_cents)
                            <div class="estimate-line">
                                <span class="lbl">Preliminary estimate (non-binding)</span>
                                <span class="amt">${{ number_format($deal->tradeIn->estimated_value_low_in_cents / 100) }} – ${{ number_format($deal->tradeIn->estimated_value_high_in_cents / 100) }}</span>
                            </div>
                        @endif

                        @if ($deal->tradeIn->customer_notes)
                            <p class="note">Buyer note: {{ $deal->tradeIn->customer_notes }}</p>
                        @endif

                        <p class="note tight">Self-reported by the buyer. The final offer is set by the dealership after inspection — confirming the figure lands with the financing tools in the next release.</p>
                    </div>
                </div>
            @endif

            {{-- Plan (read-only — F&I confirms the real numbers) --}}
            <div class="panel">
                <div class="panel-head">
                    <h3>Plan</h3>
                    <span class="ph-right">{{ $this->purchaseTypeLabel() }}</span>
                </div>
                <div class="panel-body">
                    <div class="kv">
                        <div class="row"><span class="k">Purchase type</span><span class="v">{{ $this->purchaseTypeLabel() }}</span></div>
                        @if ($deal->purchase_type === 'finance')
                            @if ($deal->term_months)
                                <div class="row"><span class="k">Requested term</span><span class="v">{{ $deal->term_months }} months</span></div>
                            @endif
                            @if ($deal->down_payment_in_cents !== null)
                                <div class="row"><span class="k">Down payment</span><span class="v">${{ number_format($deal->down_payment_in_cents / 100) }}</span></div>
                            @endif
                        @endif
                    </div>
                    <p class="note">Plan details are what the buyer selected at checkout. The dealership's finance office confirms the rate, term and payment — those numbers are entered here in the next release.</p>
                </div>
            </div>

            {{-- Coverage interest (non-binding) --}}
            @if ($this->hasCoverageInterest())
                <div class="panel">
                    <div class="panel-head">
                        <h3>Coverage interest</h3>
                        <span class="ph-right">Non-binding · for F&amp;I review</span>
                    </div>
                    <div class="panel-body">
                        <div class="chips">
                            @if ($deal->warranty_plan)
                                <span class="chip warranty">Warranty: {{ ucwords(str_replace(['_', '-'], ' ', $deal->warranty_plan)) }}</span>
                            @endif
                            @foreach ($deal->extras_interest ?? [] as $coverageKey)
                                <span class="chip">{{ ucwords(str_replace(['_', '-'], ' ', $coverageKey)) }}</span>
                            @endforeach
                        </div>
                        <p class="note">The buyer flagged interest in these at checkout. Nothing has been priced, added or tied to the sale — the F&amp;I office confirms availability and pricing directly with the buyer.</p>
                    </div>
                </div>
            @endif

            {{-- Frozen fee schedule (OMVIC all-in split) --}}
            <div class="panel">
                <div class="panel-head">
                    <h3>Fees at reserve</h3>
                    <span class="ph-right">Frozen at checkout</span>
                </div>
                <div class="panel-body">
                    @if (empty($feeBuckets['included']) && empty($feeBuckets['passThrough']))
                        <p class="empty-line">No fee schedule was frozen onto this reservation.</p>
                    @else
                        @if (! empty($feeBuckets['included']))
                            <div class="fee-group-label">Inside the all-in price</div>
                            @foreach ($feeBuckets['included'] as $includedFee)
                                <div class="fee-row">
                                    <span class="fee-label">{{ $includedFee['label'] ?? 'Fee' }}</span>
                                    <span class="fee-amt">${{ number_format(($includedFee['amount_in_cents'] ?? 0) / 100) }}</span>
                                </div>
                            @endforeach
                        @endif

                        @if (! empty($feeBuckets['passThrough']))
                            <div class="fee-group-label">Added at delivery (at cost)</div>
                            @foreach ($feeBuckets['passThrough'] as $passThroughFee)
                                <div class="fee-row">
                                    <span class="fee-label">{{ $passThroughFee['label'] ?? 'Charge' }}</span>
                                    <span class="fee-amt">${{ number_format(($passThroughFee['amount_in_cents'] ?? 0) / 100) }}</span>
                                </div>
                            @endforeach
                            <div class="fee-total">
                                <span>Pass-through total</span>
                                <span>${{ number_format($feeBuckets['passThroughTotalInCents'] / 100) }}</span>
                            </div>
                        @endif

                        <p class="note">Included fees sit inside the advertised all-in price — never stacked on top. Only at-cost government charges are added at delivery, alongside HST.</p>
                    @endif
                </div>
            </div>

            {{-- Documents --}}
            <div class="panel">
                <div class="panel-head">
                    <h3>Documents</h3>
                    <span class="ph-right">{{ $this->documentsReceived() }} of {{ $documentRows->count() }} done</span>
                </div>
                <div class="panel-body">
                    @forelse ($documentRows as $row)
                        <div class="doc-row" wire:key="doc-{{ $row['id'] }}">
                            <div class="doc-top">
                                <span class="doc-ic {{ $row['state'] === 'approved' || $row['state'] === 'cleared' ? 'done' : ($row['state'] === 'uploaded' ? 'review' : ($row['state'] === 'rejected' ? 'flag' : 'wait')) }}">
                                    @if ($row['state'] === 'approved' || $row['state'] === 'cleared')
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 4.5 4.5L19 7"/></svg>
                                    @elseif ($row['state'] === 'uploaded')
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                                    @elseif ($row['state'] === 'rejected')
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9 2 18a2 2 0 0 0 1.7 3h16.6a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/></svg>
                                    @else
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                                    @endif
                                </span>

                                <div class="doc-main">
                                    <div class="doc-name">{{ $row['name'] }}</div>
                                    <div class="doc-status">
                                        {{ $row['status'] }}@if ($row['uploadedLabel']) · uploaded {{ $row['uploadedLabel'] }}@endif
                                    </div>
                                </div>

                                <div class="doc-actions">
                                    @if ($row['state'] === 'uploaded' || $row['state'] === 'approved')
                                        <button type="button" class="mini-btn ghost"
                                                wire:click="downloadDocument({{ $row['id'] }})"
                                                wire:loading.attr="disabled"
                                                wire:target="downloadDocument({{ $row['id'] }})">
                                            Download
                                        </button>
                                    @endif

                                    @if ($row['state'] === 'uploaded')
                                        <button type="button" class="mini-btn approve"
                                                wire:click="approveDocument({{ $row['id'] }})"
                                                wire:loading.attr="disabled"
                                                wire:target="approveDocument({{ $row['id'] }})">
                                            Approve
                                        </button>
                                        <button type="button" class="mini-btn flag"
                                                wire:click="startRejection({{ $row['id'] }})">
                                            Request re-upload
                                        </button>
                                    @elseif ($row['state'] === 'approved')
                                        <span class="doc-state approved">Approved</span>
                                    @elseif ($row['state'] === 'cleared')
                                        <span class="doc-state cleared">Verified</span>
                                    @elseif ($row['state'] === 'rejected')
                                        <span class="doc-state rejected">Awaiting re-upload</span>
                                    @else
                                        <span class="doc-state waiting">Waiting on buyer</span>
                                    @endif
                                </div>
                            </div>

                            @if ($documentBeingRejected === $row['id'])
                                <div class="reject-box">
                                    <label for="reason-{{ $row['id'] }}">Reason for re-upload (optional — the buyer sees this on the deal trail)</label>
                                    <textarea id="reason-{{ $row['id'] }}"
                                              wire:model="rejectionReason"
                                              placeholder="e.g. The licence photo is cut off — please re-upload showing all four corners."></textarea>
                                    @error('rejectionReason') <div class="reject-err">{{ $message }}</div> @enderror
                                    <div class="reject-box-actions">
                                        <button type="button" class="mini-btn ghost" wire:click="cancelRejection">Cancel</button>
                                        <button type="button" class="mini-btn flag"
                                                wire:click="requestReupload({{ $row['id'] }})"
                                                wire:loading.attr="disabled"
                                                wire:target="requestReupload({{ $row['id'] }})">
                                            Send back to buyer
                                        </button>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @empty
                        <p class="empty-line">No documents on this reservation.</p>
                    @endforelse
                </div>
            </div>

        </div>

        <div class="col-side">

            {{-- Handover --}}
            <div class="panel">
                <div class="panel-head">
                    <h3>Handover</h3>
                    <span class="ph-right">{{ $deal->handover_mode === 'delivery' ? 'Delivery' : 'Pickup' }}</span>
                </div>
                <div class="panel-body">
                    <div class="kv">
                        <div class="row"><span class="k">Mode</span><span class="v">{{ $deal->handover_mode === 'delivery' ? 'Delivery' : 'Pickup' }}</span></div>
                        @if ($deal->pickup_location)
                            <div class="row"><span class="k">Location</span><span class="v">{{ $deal->pickup_location }}</span></div>
                        @endif
                        @if ($deal->pickup_at)
                            <div class="row"><span class="k">Requested</span><span class="v">{{ $deal->pickup_at->format('D M j · g:i a') }}</span></div>
                        @endif
                    </div>
                    <p class="note">The dealership confirms the exact time with the buyer.</p>
                </div>
            </div>

            {{-- Activity log --}}
            <div class="panel">
                <div class="panel-head">
                    <h3>Activity</h3>
                    <span class="ph-right">{{ $activityFeed->count() }}</span>
                </div>
                <div class="panel-body">
                    <div class="act-list">
                        @forelse ($activityFeed as $activity)
                            <div class="act-item" wire:key="act-{{ $activity->id }}">
                                <span class="act-dot {{ $activity->kind }}"></span>
                                <div>
                                    <div class="act-body">{{ $activity->body }}</div>
                                    <div class="act-meta">
                                        {{ $activity->author_name ?? 'TruCars' }} · {{ $activity->created_at->diffForHumans() }}
                                    </div>
                                </div>
                            </div>
                        @empty
                            <p class="empty-line">No activity yet.</p>
                        @endforelse
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>
