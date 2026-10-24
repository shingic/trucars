<?php

use App\Mail\DocumentReuploadRequested;
use App\Mail\FinancingApproved;
use App\Mail\MoreInfoRequested;
use App\Models\Deal;
use App\Models\DealDocument;
use App\Support\Deposits;
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

    /**
     * The lender's approval, typed in by the finance office from what the bank
     * sent back. Strings while editing; dollars, not cents. The form is open
     * until an approval is saved, then closed behind an Edit button.
     */
    public string $financingLender = '';

    public string $financingApr = '';

    public string $financingTermMonths = '';

    public string $financingPayment = '';

    public string $financingFrequency = 'biweekly';

    public string $financingAmount = '';

    public string $financingDownPayment = '';

    public string $financingFirstPaymentOn = '';

    public bool $editingFinancing = false;

    /** Which extra item the "Ask for more" box is open for (a preset key or 'other'); null when closed. */
    public ?string $asking = null;

    public string $askName = '';

    public string $askNote = '';

    /** The decline box: an internal reason for the record, an optional note for the buyer. */
    public bool $declining = false;

    public string $declineReason = '';

    public string $declineNote = '';

    /** Common extras a lender comes back for, with the instructions the buyer sees. */
    public const EXTRA_REQUESTS = [
        'bank_statement'    => ['Bank statement', 'Your most recent month. The PDF from online banking works.'],
        'proof_of_address'  => ['Proof of address', 'A bill or statement from the last 3 months showing your name and address.'],
        'employment_letter' => ['Employment letter', 'On company letterhead, with your job title, start date and pay.'],
        'pay_stub'          => ['Another pay stub', 'Your most recent one.'],
    ];

    public function mount(Deal $deal): void
    {
        $this->deal = $deal;
        $this->fillFinancingForm();
    }

    /** Finance and lease deals carry a lender approval; cash deals don't. */
    public function takesFinancing(): bool
    {
        return in_array($this->deal->purchase_type, ['finance', 'lease'], true);
    }

    public function editFinancing(): void
    {
        $this->fillFinancingForm();
        $this->resetValidation();
        $this->editingFinancing = true;
    }

    public function cancelFinancing(): void
    {
        $this->editingFinancing = false;
        $this->resetValidation();
    }

    /**
     * Save the approval and email the buyer the terms. Saving it a second time
     * sends an "updated terms" email instead, so the buyer always has the
     * numbers that are on file.
     */
    public function saveFinancing(): void
    {
        if (! $this->takesFinancing()) {
            return;
        }

        $this->validate([
            'financingLender'         => ['required', 'string', 'max:120'],
            'financingApr'            => ['required', 'numeric', 'min:0', 'max:40'],
            'financingTermMonths'     => ['required', 'integer', 'min:6', 'max:120'],
            'financingPayment'        => ['required', 'numeric', 'min:1', 'max:100000'],
            'financingFrequency'      => ['required', 'in:' . implode(',', array_keys(Deal::PAYMENT_FREQUENCIES))],
            'financingAmount'         => ['required', 'numeric', 'min:1', 'max:1000000'],
            'financingDownPayment'    => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'financingFirstPaymentOn' => ['nullable', 'date'],
        ], attributes: [
            'financingLender'         => 'lender',
            'financingApr'            => 'rate',
            'financingTermMonths'     => 'term',
            'financingPayment'        => 'payment',
            'financingFrequency'      => 'payment frequency',
            'financingAmount'         => 'amount financed',
            'financingDownPayment'    => 'down payment',
            'financingFirstPaymentOn' => 'first payment date',
        ]);

        $updated = $this->deal->hasFinancingApproval();

        $this->deal->update([
            'financing_lender'                => trim($this->financingLender),
            'financing_apr'                   => $this->financingApr,
            'financing_term_months'           => (int) $this->financingTermMonths,
            'financing_payment_in_cents'      => (int) round((float) $this->financingPayment * 100),
            'financing_frequency'             => $this->financingFrequency,
            'financing_amount_in_cents'       => (int) round((float) $this->financingAmount * 100),
            'financing_down_payment_in_cents' => $this->financingDownPayment !== '' ? (int) round((float) $this->financingDownPayment * 100) : null,
            'financing_first_payment_on'      => $this->financingFirstPaymentOn !== '' ? $this->financingFirstPaymentOn : null,
            'financing_approved_at'           => $this->deal->financing_approved_at ?? now(),
        ]);

        $this->deal->recordActivity(
            'status',
            ($updated ? 'Updated the financing approval: ' : 'Entered the financing approval: ')
                . $this->deal->financing_lender . ', $' . number_format($this->deal->financing_payment_in_cents / 100, 2)
                . ' ' . strtolower($this->deal->financing_frequency_label) . ' over ' . $this->deal->financing_term_months . ' months.',
            Auth::user()->name ?? 'Dealer',
        );

        $this->deal->emailBuyer(new FinancingApproved($this->deal, $updated), $updated ? 'Updated financing terms' : 'Financing approval');

        $this->editingFinancing = false;
    }

    /** Open the "Ask for more" box for a preset item, or 'other' to type one in. */
    public function startAsking(string $kind): void
    {
        $this->asking = $kind === 'other' || isset(self::EXTRA_REQUESTS[$kind]) ? $kind : null;
        $this->askName = self::EXTRA_REQUESTS[$kind][0] ?? '';
        $this->askNote = '';
        $this->resetValidation();
    }

    public function cancelAsking(): void
    {
        $this->asking = null;
        $this->resetValidation();
    }

    /**
     * Add an item to the buyer's checklist and email them for it. It behaves
     * like any other document: an Upload button in My Garage, a reminder if
     * it's still missing two days later, approve or send back once it's in.
     */
    public function requestMore(): void
    {
        if ($this->asking === null || in_array($this->deal->stage, ['cancelled', 'delivered'], true)) {
            return;
        }

        $this->validate([
            'askName' => ['required', 'string', 'max:80'],
            'askNote' => ['nullable', 'string', 'max:200'],
        ], attributes: ['askName' => 'what you need', 'askNote' => 'note']);

        $note = trim($this->askNote);

        $document = $this->deal->documents()->create([
            'slug'         => 'extra_' . Str::lower(Str::random(8)),
            'name'         => trim($this->askName),
            'status'       => $note !== '' ? $note : (self::EXTRA_REQUESTS[$this->asking][1] ?? 'Requested by the dealership'),
            'sort_order'   => (int) $this->deal->documents()->max('sort_order') + 1,
            'requested_at' => now(),
        ]);

        $this->deal->recordActivity('document', 'Asked the buyer for ' . $document->name . '.', Auth::user()->name ?? 'Dealer', 'outbound');
        $this->deal->emailBuyer(new MoreInfoRequested($this->deal, $document), 'Request for ' . $document->name);

        $this->asking = null;
    }

    /** Take back an extra request the buyer hasn't answered yet (asked by mistake, or no longer needed). */
    public function withdrawRequest(int $documentId): void
    {
        $document = $this->deal->documents()->whereKey($documentId)->whereNull('file_path')->first();

        if ($document === null || ! str_starts_with($document->slug, 'extra_')) {
            return;
        }

        $document->delete();
        $this->deal->recordActivity('document', 'Withdrew the request for ' . $document->name . '.', Auth::user()->name ?? 'Dealer');
    }

    public function startDecline(): void
    {
        $this->declining = true;
        $this->resetValidation();
    }

    public function cancelDecline(): void
    {
        $this->declining = false;
        $this->resetValidation();
    }

    /**
     * Decline the reservation and refund the $150 to the buyer's card. The
     * refund goes first; if Stripe refuses it nothing changes, so the buyer is
     * never left with a cancelled reservation and their money still held.
     */
    public function declineDeal(Deposits $deposits): void
    {
        $this->validate([
            'declineReason' => ['required', 'string', 'min:5', 'max:500'],
            'declineNote'   => ['nullable', 'string', 'max:500'],
        ], ['declineReason.required' => 'Say why. It stays on the deal’s record and isn’t sent to the buyer.'], ['declineReason' => 'reason', 'declineNote' => 'note']);

        if (in_array($this->deal->stage, ['cancelled', 'delivered'], true)) {
            return;
        }

        try {
            $deposits->cancelReservation($this->deal, trim($this->declineReason), byDealer: true, noteToBuyer: trim($this->declineNote) ?: null);
        } catch (\Throwable $refundFailure) {
            report($refundFailure);
            $this->addError('declineReason', 'The refund didn’t go through, so nothing was changed. Try again in a minute, or contact TruCars.');

            return;
        }

        $this->deal->refresh();
        $this->declining = false;
    }

    private function fillFinancingForm(): void
    {
        $deal = $this->deal;
        $dollars = fn (?int $cents) => $cents === null ? '' : rtrim(rtrim(number_format($cents / 100, 2, '.', ''), '0'), '.');

        $this->financingLender = (string) $deal->financing_lender;
        $this->financingApr = $deal->financing_apr !== null ? rtrim(rtrim((string) $deal->financing_apr, '0'), '.') : '';
        $this->financingTermMonths = (string) ($deal->financing_term_months ?? $deal->term_months ?? '');
        $this->financingPayment = $dollars($deal->financing_payment_in_cents);
        $this->financingFrequency = $deal->financing_frequency ?? 'biweekly';
        $this->financingAmount = $dollars($deal->financing_amount_in_cents);
        $this->financingDownPayment = $dollars($deal->financing_down_payment_in_cents ?? $deal->down_payment_in_cents);
        $this->financingFirstPaymentOn = $deal->financing_first_payment_on?->toDateString() ?? '';
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
     * button reappears), the buyer is emailed with the reason, and the reason
     * rides along on the activity trail so there's a record of why.
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
            'status'       => 'Needs re-upload',
            'is_done'      => false,
            'requested_at' => now(),
        ]);

        $trimmedReason = trim($this->rejectionReason);
        $reasonSuffix = $trimmedReason !== '' ? ' — ' . $trimmedReason : '';

        $this->deal->recordActivity(
            'document',
            'Requested re-upload of ' . $document->name . $reasonSuffix,
            Auth::user()->name ?? 'Dealer',
            'outbound',
        );

        $this->deal->emailBuyer(
            new DocumentReuploadRequested($this->deal, $document, $trimmedReason !== '' ? $trimmedReason : null),
            'Re-upload request for ' . $document->name,
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
     *   later    — not asked for yet (insurance, until the deal is finalized)
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
            return $document->isRequested() ? 'waiting' : 'later';
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

    /** What the buyer gets emailed when the next stage is marked, if anything. */
    public function nextStageEmail(): ?string
    {
        return match (Deal::NEXT_STAGE[$this->deal->stage] ?? null) {
            'documents'          => 'Emails the buyer for proof of insurance',
            'ready_for_delivery' => 'Emails the buyer that the car is ready',
            default              => null,
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
                'withdrawable'  => str_starts_with($document->slug, 'extra_') && $document->file_path === null,
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

        .detail-back { display:inline-flex; align-items:center; gap:7px; font-size:14px; font-weight:600; color:var(--ink-2); margin-bottom:16px; text-decoration:underline; text-underline-offset:3px; text-decoration-color:var(--line-strong); transition:color .15s ease; }
        .detail-back:hover { color:var(--ink); text-decoration-color:currentColor; }
        .detail-back svg { width:16px; height:16px; }

        .detail-head { display:flex; align-items:flex-start; justify-content:space-between; gap:18px; margin-bottom:24px; flex-wrap:wrap; }
        .detail-title h1 { font-family:var(--font-display); font-size:34px; font-weight:700; text-transform:uppercase; line-height:1; letter-spacing:0; margin:0; }
        .detail-title .sub { font-size:14.5px; color:var(--ink-2); margin-top:9px; }
        .detail-head-right { display:flex; align-items:center; gap:10px; flex-wrap:wrap; justify-content:flex-end; }

        .deposit-chip { display:inline-flex; align-items:center; gap:6px; font-size:13px; font-weight:700; padding:4px 9px; border-radius:5px; background:#DDF5EB; color:#0B7355; white-space:nowrap; line-height:1.4; }
        .deposit-chip svg { width:13px; height:13px; }

        .adv-btn { font-family:var(--font-display); font-size:16px; font-weight:700; text-transform:uppercase; color:#fff; background:var(--primary); padding:8px 16px; border-radius:8px; white-space:nowrap; transition:background .15s ease; }
        .adv-btn:hover { background:var(--primary-press); }
        .adv-btn[disabled] { opacity:.55; cursor:wait; }
        .adv-done { font-size:13px; font-weight:700; color:var(--ink-3); }
        .adv-note { font-size:13px; color:var(--ink-3); }

        /* Stage chip (shared vocabulary with the inbox): flat rectangles, colour carries meaning */
        .stage { display:inline-flex; align-items:center; font-size:13px; font-weight:700; padding:4px 9px; border-radius:5px; white-space:nowrap; line-height:1.4; }
        .stage.reserved { background:#E9F3FF; color:#056EE9; }
        .stage.contacted { background:#FFF8DD; color:#7A4E00; }
        .stage.appointment_set { background:#E3EDFB; color:#2E5FA3; }
        .stage.financing { background:#EFE7FB; color:#5B3A9E; }
        .stage.documents { background:#E0F2F4; color:#1F6E78; }
        .stage.ready_for_delivery { background:#DDF5EB; color:#0B7355; }
        .stage.delivered { background:var(--ink); color:#fff; }
        .stage.cancelled { background:#FFEEF3; color:#D81A48; }

        .detail-grid { display:grid; grid-template-columns:minmax(0,1fr) 332px; gap:28px; align-items:start; }
        .col-main { display:flex; flex-direction:column; gap:12px; min-width:0; }
        .col-side { display:flex; flex-direction:column; gap:12px; position:sticky; top:78px; min-width:0; }

        /* Ruled sections rather than boxed cards */
        .panel { border-top:2px solid var(--ink); }
        .panel-head { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:12px 0 10px; flex-wrap:wrap; }
        .panel-head h3 { font-family:var(--font-display); font-size:21px; font-weight:700; text-transform:uppercase; line-height:1.1; letter-spacing:0; margin:0; }
        .panel-head .ph-right { font-size:13px; font-weight:600; color:var(--ink-3); }
        .panel-body { padding:4px 0 20px; }

        .kv { display:flex; flex-direction:column; border-top:1px solid var(--line-strong); }
        .kv .row { display:flex; align-items:baseline; justify-content:space-between; gap:16px; padding:9px 0; border-bottom:1px solid var(--line-strong); font-size:14px; }
        .kv .k { color:var(--ink-3); font-weight:600; flex-shrink:0; }
        .kv .v { color:var(--ink); font-weight:600; text-align:right; min-width:0; overflow-wrap:anywhere; }
        .kv .v .est { font-weight:500; color:var(--ink-3); }
        .kv .v a { text-decoration:underline; text-underline-offset:3px; text-decoration-color:var(--line-strong); }
        .kv .v a:hover { color:var(--primary-press); text-decoration-color:currentColor; }
        .kv .v.mono { font-family:var(--mono); font-size:13px; letter-spacing:-.01em; }

        .id-badge { display:inline-flex; align-items:center; gap:6px; font-size:13px; font-weight:700; padding:3px 8px; border-radius:5px; line-height:1.4; }
        .id-badge svg { width:12px; height:12px; }
        .id-badge.ok { background:#DDF5EB; color:#0B7355; }
        .id-badge.no { background:#FFF8DD; color:#7A4E00; }

        .pill-status { font-size:13px; font-weight:700; padding:3px 8px; border-radius:5px; white-space:nowrap; line-height:1.4; }
        .pill-status.preliminary { background:#E9F3FF; color:#056EE9; }

        .estimate-line { display:flex; align-items:baseline; justify-content:space-between; gap:12px; margin-top:14px; padding-top:12px; border-top:2px solid var(--ink); flex-wrap:wrap; }
        .estimate-line .lbl { font-size:13px; font-weight:600; color:var(--ink-3); }
        .estimate-line .amt { font-family:var(--font-display); font-size:26px; font-weight:700; line-height:1; color:var(--ink); letter-spacing:0; }

        .chips { display:flex; flex-wrap:wrap; gap:6px; }
        .chip { font-size:13px; font-weight:600; padding:4px 9px; border-radius:5px; background:rgba(7,20,55,.06); color:var(--ink-2); }
        .chip.warranty { background:#E9F3FF; color:var(--primary-press); }

        .note { font-size:13px; color:var(--ink-3); line-height:1.55; margin:14px 0 0; }
        .note.tight { margin-top:10px; }

        .fee-row { display:flex; align-items:baseline; justify-content:space-between; gap:12px; padding:9px 0; border-bottom:1px solid var(--line-strong); font-size:14px; }
        .fee-row .fee-label { color:var(--ink); font-weight:600; }
        .fee-row .fee-amt { color:var(--ink-2); font-weight:600; font-variant-numeric:tabular-nums; }
        .fee-group-label { font-family:var(--font-display); font-size:14px; font-weight:700; text-transform:uppercase; color:var(--ink-3); margin:16px 0 0; padding-bottom:6px; border-bottom:2px solid var(--ink); }
        .fee-group-label:first-child { margin-top:0; }
        .fee-total { display:flex; align-items:baseline; justify-content:space-between; gap:12px; margin-top:0; padding-top:11px; border-top:2px solid var(--ink); font-family:var(--font-display); font-size:17px; font-weight:700; text-transform:uppercase; }

        /* Documents */
        .doc-row { padding:13px 0; border-bottom:1px solid var(--line-strong); }
        .doc-row:first-child { border-top:1px solid var(--line-strong); }
        .doc-top { display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
        /* bare status icon, no tile */
        .doc-ic { width:22px; height:22px; display:grid; place-items:center; flex-shrink:0; background:none; }
        .doc-ic svg { width:18px; height:18px; }
        .doc-ic.done { color:#0B7355; }
        .doc-ic.review { color:var(--primary-press); }
        .doc-ic.wait { color:var(--ink-3); }
        .doc-ic.flag { color:#C59A00; }
        .doc-main { flex:1; min-width:160px; }
        .doc-name { font-size:14.5px; font-weight:700; }
        .doc-status { font-size:13px; color:var(--ink-3); margin-top:2px; }
        .doc-actions { display:flex; align-items:center; gap:8px; flex-wrap:wrap; flex-shrink:0; }

        .fin-form { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-top:12px; }
        .fin-form label { display:flex; flex-direction:column; gap:5px; font-size:13px; font-weight:600; color:var(--ink-2); min-width:0; }
        .fin-form label.wide, .fin-actions { grid-column:1 / -1; }
        .fin-form input, .fin-form select { width:100%; border:1.5px solid var(--line-strong); border-radius:8px; padding:9px 11px; font-size:14.5px; font-family:inherit; color:var(--ink); background:#fff; outline:none; }
        .fin-form input:focus, .fin-form select:focus { border-color:var(--ink); box-shadow:0 0 0 3px var(--primary-soft); }
        .fin-err { font-size:13px; color:#D81A48; font-weight:600; }
        .fin-actions { display:flex; gap:9px; justify-content:flex-end; flex-wrap:wrap; }

        .ask { margin-top:14px; padding-top:14px; border-top:1px solid var(--line-strong); }
        .ask-label { font-size:14px; font-weight:700; color:var(--ink-2); margin-bottom:9px; }
        .ask-chips { display:flex; flex-wrap:wrap; gap:6px; }
        .ask-chip { font-size:13px; font-weight:600; color:var(--ink); background:#fff; border:1.5px solid var(--line-strong); border-radius:8px; padding:6px 11px; transition:border-color .15s ease, background .15s ease; }
        .ask-chip:hover { border-color:var(--ink); }
        .ask-chip.on { border-color:var(--ink); color:#fff; background:var(--ink); }
        .ask-box { display:grid; gap:12px; margin-top:12px; background:#fff; border:1px solid var(--line-strong); border-radius:8px; padding:13px; }
        .ask-box label, .decline-form label { display:flex; flex-direction:column; gap:5px; font-size:13px; font-weight:600; color:var(--ink-2); }
        .ask-box input, .decline-form textarea { width:100%; border:1.5px solid var(--line-strong); border-radius:8px; padding:9px 11px; font-size:14px; font-family:inherit; color:var(--ink); background:#fff; outline:none; resize:vertical; }
        .ask-box input:focus, .decline-form textarea:focus { border-color:var(--ink); box-shadow:0 0 0 3px var(--primary-soft); }

        .decline-form { display:grid; gap:12px; }
        .mini-btn.danger { color:#D81A48; border-color:#D81A48; background:#fff; }
        .mini-btn.danger:hover { background:#FFEEF3; }
        .mini-btn.danger[type="submit"] { background:#D81A48; color:#fff; border-color:#D81A48; }
        .mini-btn.danger[type="submit"]:hover { background:#B5143B; border-color:#B5143B; }
        .decline-done .panel-body { font-size:14px; line-height:1.55; color:var(--ink-2); background:#FFEEF3; padding:14px 16px; margin-top:0; }
        .decline-done { border-top-color:#D81A48; }
        .decline-done b { color:#D81A48; }
        @media (max-width:560px) { .fin-form { grid-template-columns:1fr; } }

        .mini-btn { font-size:13px; font-weight:700; padding:7px 12px; border-radius:8px; white-space:nowrap; transition:background .15s ease, color .15s ease, border-color .15s ease; border:1.5px solid transparent; }
        .mini-btn[disabled] { opacity:.55; cursor:wait; }
        .mini-btn.approve { background:var(--primary); border-color:var(--primary); color:#fff; }
        .mini-btn.approve:hover { background:var(--primary-press); border-color:var(--primary-press); }
        .mini-btn.ghost { color:var(--ink); background:#fff; border-color:var(--ink); }
        .mini-btn.ghost:hover { background:var(--ink); color:#fff; }
        .mini-btn.flag { color:#7A4E00; background:#fff; border-color:#C59A00; }
        .mini-btn.flag:hover { background:#FFF8DD; }

        .doc-state { font-size:13px; font-weight:700; padding:3px 8px; border-radius:5px; white-space:nowrap; line-height:1.4; }
        .doc-state.approved { background:#DDF5EB; color:#0B7355; }
        .doc-state.cleared { background:#DDF5EB; color:#0B7355; }
        .doc-state.waiting { background:rgba(7,20,55,.07); color:var(--ink-2); }
        .doc-state.rejected { background:#FFF8DD; color:#7A4E00; }

        .reject-box { margin-top:12px; margin-left:34px; background:#fff; border:1px solid var(--line-strong); border-radius:8px; padding:13px; }
        .reject-box label { font-size:13px; font-weight:600; color:var(--ink-2); display:block; margin-bottom:7px; }
        .reject-box textarea { width:100%; border:1.5px solid var(--line-strong); border-radius:8px; padding:10px 12px; font-size:14px; font-family:inherit; color:var(--ink); outline:none; resize:vertical; min-height:62px; background:#fff; }
        .reject-box textarea:focus { border-color:var(--ink); box-shadow:0 0 0 3px var(--primary-soft); }
        .reject-box-actions { display:flex; gap:9px; justify-content:flex-end; margin-top:11px; flex-wrap:wrap; }
        .reject-err { font-size:13px; color:#D81A48; font-weight:600; margin-top:7px; }

        /* Activity log */
        .act-list { display:flex; flex-direction:column; border-top:1px solid var(--line-strong); }
        .act-item { display:flex; gap:11px; padding:11px 0; border-bottom:1px solid var(--line-strong); }
        .act-dot { width:4px; align-self:stretch; border-radius:0; flex-shrink:0; background:var(--ink-3); }
        .act-dot.status { background:var(--ink); }
        .act-dot.document { background:#1F6E78; }
        .act-dot.sms { background:#0B7355; }
        .act-dot.email { background:var(--primary); }
        .act-dot.note { background:#C59A00; }
        .act-body { font-size:14px; line-height:1.5; color:var(--ink); }
        .act-meta { font-size:13px; color:var(--ink-3); margin-top:3px; }

        .empty-line { font-size:14px; color:var(--ink-2); padding:14px; margin:0; border:1.5px dashed var(--line-strong); border-radius:8px; }

        @media (max-width:600px){
            .reject-box { margin-left:0; }
            .detail-head-right { justify-content:flex-start; }
        }

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
                ${{ number_format($deal->deposit_in_cents / 100) }} deposit {{ $deal->deposit_status === 'refunded' ? 'refunded' : 'held' }}
            </span>

            <span class="stage {{ $deal->stage }}">
                {{ $deal->stage_label }}
            </span>

            @if (isset(App\Models\Deal::NEXT_STAGE[$deal->stage]))
                @if ($this->nextStageEmail())
                    <span class="adv-note">{{ $this->nextStageEmail() }}</span>
                @endif
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

            {{-- Payment: the bank's approval as it came back, entered by F&I and shown to the buyer --}}
            <div class="panel">
                <div class="panel-head">
                    <h3>Payment</h3>
                    @if ($this->takesFinancing() && $deal->hasFinancingApproval() && ! $editingFinancing)
                        <button type="button" class="mini-btn ghost" wire:click="editFinancing">Edit</button>
                    @else
                        <span class="ph-right">{{ $this->takesFinancing() ? 'Bank approval' : 'Cash purchase' }}</span>
                    @endif
                </div>
                <div class="panel-body">
                    @if ($this->takesFinancing())
                        @if ($deal->hasFinancingApproval() && ! $editingFinancing)
                            <div class="kv">
                                <div class="row"><span class="k">Lender</span><span class="v">{{ $deal->financing_lender }}</span></div>
                                <div class="row"><span class="k">Payment</span><span class="v">${{ number_format($deal->financing_payment_in_cents / 100, 2) }} {{ strtolower($deal->financing_frequency_label) }}</span></div>
                                <div class="row"><span class="k">Rate</span><span class="v">{{ rtrim(rtrim(number_format((float) $deal->financing_apr, 2), '0'), '.') }}% APR</span></div>
                                <div class="row"><span class="k">Term</span><span class="v">{{ $deal->financing_term_months }} months</span></div>
                                <div class="row"><span class="k">Amount financed</span><span class="v">${{ number_format($deal->financing_amount_in_cents / 100, 2) }}</span></div>
                                @if ($deal->financing_down_payment_in_cents !== null)
                                    <div class="row"><span class="k">Down payment</span><span class="v">${{ number_format($deal->financing_down_payment_in_cents / 100, 2) }}</span></div>
                                @endif
                                @if ($deal->financing_first_payment_on)
                                    <div class="row"><span class="k">First payment</span><span class="v">{{ $deal->financing_first_payment_on->format('M j, Y') }}</span></div>
                                @endif
                            </div>
                            <p class="note">The buyer sees these terms in My Garage. Entered {{ $deal->financing_approved_at->diffForHumans() }}.</p>
                        @else
                            <p class="note" style="margin-top:0;">Enter the approval as it came back from the bank. Saving emails the buyer their terms and shows them in My Garage.</p>
                            <form class="fin-form" wire:submit="saveFinancing">
                                <label class="wide">Lender
                                    <input type="text" wire:model="financingLender" placeholder="e.g. Scotiabank" maxlength="120">
                                    @error('financingLender') <span class="fin-err">{{ $message }}</span> @enderror
                                </label>
                                <label>Payment ($)
                                    <input type="number" step="0.01" min="0" inputmode="decimal" wire:model="financingPayment">
                                    @error('financingPayment') <span class="fin-err">{{ $message }}</span> @enderror
                                </label>
                                <label>How often
                                    <select wire:model="financingFrequency">
                                        @foreach (App\Models\Deal::PAYMENT_FREQUENCIES as $value => $label)
                                            <option value="{{ $value }}" @selected($financingFrequency === $value)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    @error('financingFrequency') <span class="fin-err">{{ $message }}</span> @enderror
                                </label>
                                <label>Rate (APR %)
                                    <input type="number" step="0.01" min="0" inputmode="decimal" wire:model="financingApr">
                                    @error('financingApr') <span class="fin-err">{{ $message }}</span> @enderror
                                </label>
                                <label>Term (months)
                                    <input type="number" step="1" min="6" inputmode="numeric" wire:model="financingTermMonths">
                                    @error('financingTermMonths') <span class="fin-err">{{ $message }}</span> @enderror
                                </label>
                                <label>Amount financed ($)
                                    <input type="number" step="0.01" min="0" inputmode="decimal" wire:model="financingAmount">
                                    @error('financingAmount') <span class="fin-err">{{ $message }}</span> @enderror
                                </label>
                                <label>Down payment ($, optional)
                                    <input type="number" step="0.01" min="0" inputmode="decimal" wire:model="financingDownPayment">
                                    @error('financingDownPayment') <span class="fin-err">{{ $message }}</span> @enderror
                                </label>
                                <label>First payment (optional)
                                    <input type="date" wire:model="financingFirstPaymentOn">
                                    @error('financingFirstPaymentOn') <span class="fin-err">{{ $message }}</span> @enderror
                                </label>
                                <div class="fin-actions">
                                    @if ($editingFinancing)
                                        <button type="button" class="mini-btn ghost" wire:click="cancelFinancing">Cancel</button>
                                    @endif
                                    <button type="submit" class="mini-btn approve" wire:loading.attr="disabled" wire:target="saveFinancing">
                                        {{ $deal->hasFinancingApproval() ? 'Save and email the buyer' : 'Save approval and email the buyer' }}
                                    </button>
                                </div>
                            </form>
                        @endif
                    @else
                        <p class="note" style="margin:0;">Cash purchase, so there's no bank approval to enter. The buyer settles the balance with you directly.</p>
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
                                    @elseif ($row['state'] === 'later')
                                        <span class="doc-state waiting">Asked for at Documents</span>
                                    @else
                                        <span class="doc-state waiting">Waiting on buyer</span>
                                        @if ($row['withdrawable'])
                                            <button type="button" class="mini-btn ghost" wire:click="withdrawRequest({{ $row['id'] }})"
                                                    wire:confirm="Stop asking the buyer for {{ $row['name'] }}?">Withdraw</button>
                                        @endif
                                    @endif
                                </div>
                            </div>

                            @if ($documentBeingRejected === $row['id'])
                                <div class="reject-box">
                                    <label for="reason-{{ $row['id'] }}">Reason for re-upload (optional — the buyer gets this by email)</label>
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

                    @unless (in_array($deal->stage, ['cancelled', 'delivered'], true))
                        <div class="ask">
                            <div class="ask-label">Need something else? Ask the buyer:</div>
                            <div class="ask-chips">
                                @foreach ($this::EXTRA_REQUESTS as $kind => [$label])
                                    <button type="button" class="ask-chip {{ $asking === $kind ? 'on' : '' }}" wire:click="startAsking('{{ $kind }}')">{{ $label }}</button>
                                @endforeach
                                <button type="button" class="ask-chip {{ $asking === 'other' ? 'on' : '' }}" wire:click="startAsking('other')">Something else…</button>
                            </div>

                            @if ($asking !== null)
                                <form class="ask-box" wire:submit="requestMore">
                                    <label>What you need
                                        <input type="text" wire:model="askName" maxlength="80" placeholder="e.g. Void cheque from a different account">
                                        @error('askName') <span class="fin-err">{{ $message }}</span> @enderror
                                    </label>
                                    <label>Note for the buyer (optional)
                                        <input type="text" wire:model="askNote" maxlength="200" placeholder="{{ $this::EXTRA_REQUESTS[$asking][1] ?? 'Any detail that helps them get it right' }}">
                                        @error('askNote') <span class="fin-err">{{ $message }}</span> @enderror
                                    </label>
                                    <div class="fin-actions">
                                        <button type="button" class="mini-btn ghost" wire:click="cancelAsking">Cancel</button>
                                        <button type="submit" class="mini-btn approve" wire:loading.attr="disabled" wire:target="requestMore">Email the buyer</button>
                                    </div>
                                </form>
                            @endif
                        </div>
                    @endunless
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
                        {{-- Only worth a line when the dealer has more than one store. --}}
                        @if ($deal->rooftop_label && $deal->dealer?->rooftops()->count() > 1)
                            <div class="row"><span class="k">Store</span><span class="v">{{ $deal->rooftop_label }}</span></div>
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

            {{-- Plan: what the buyer picked at checkout (the real terms live in the Payment panel) --}}
            <div class="panel">
                <div class="panel-head">
                    <h3>Plan</h3>
                    <span class="ph-right">{{ $this->purchaseTypeLabel() }}</span>
                </div>
                <div class="panel-body">
                    <div class="kv">
                        <div class="row"><span class="k">Purchase type</span><span class="v">{{ $this->purchaseTypeLabel() }}</span></div>
                        @if ($this->takesFinancing())
                            @if ($deal->term_months)
                                <div class="row"><span class="k">Requested term</span><span class="v">{{ $deal->term_months }} months</span></div>
                            @endif
                            @if ($deal->down_payment_in_cents !== null)
                                <div class="row"><span class="k">Requested down payment</span><span class="v">${{ number_format($deal->down_payment_in_cents / 100) }}</span></div>
                            @endif
                        @endif
                    </div>

                </div>
            </div>

            {{-- Protection & add-ons the buyer added to their estimate --}}
            @if ($this->hasCoverageInterest())
                <div class="panel">
                    <div class="panel-head">
                        <h3>Protection &amp; add-ons</h3>
                        <span class="ph-right">In the buyer's estimate · confirm with them</span>
                    </div>
                    <div class="panel-body">
                        @if (! empty($deal->protection_snapshot))
                            {{-- What the buyer flagged, with the estimate they saw at checkout. --}}
                            <div class="kv">
                            @foreach ($deal->protection_snapshot as $flagged)
                                <div class="row">
                                    <span class="k">{{ \App\Models\ProtectionProduct::KINDS[$flagged['kind']] ?? 'Coverage' }}</span>
                                    <span class="v">{{ $flagged['name'] }}@if ($shown = \App\Models\ProtectionProduct::priceLabel($flagged['amount_in_cents'] ?? null, $flagged['price_basis'] ?? 'biweekly')) <small class="est">· {{ $shown }}</small>@endif</span>
                                </div>
                            @endforeach
                            </div>
                        @else
                            <div class="chips">
                                @if ($deal->warranty_plan)
                                    <span class="chip warranty">Warranty: {{ ucwords(str_replace(['_', '-'], ' ', $deal->warranty_plan)) }}</span>
                                @endif
                                @foreach ($deal->extras_interest ?? [] as $coverageKey)
                                    <span class="chip">{{ ucwords(str_replace(['_', '-'], ' ', $coverageKey)) }}</span>
                                @endforeach
                            </div>
                        @endif
                        <p class="note">The buyer added these at checkout, and their payment estimate included them at the prices shown. Nothing was charged for them; confirm availability and final pricing with the buyer.</p>
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
                            <div class="row"><span class="k">Requested</span><span class="v">{{ $deal->local_pickup_at->format('D M j · g:i a T') }}</span></div>
                        @endif
                    </div>
                    <p class="note">The dealership confirms the exact time with the buyer.</p>
                </div>
            </div>

            {{-- Decline: call the reservation off and give the buyer their $150 back --}}
            @if ($deal->stage === 'cancelled')
                <div class="panel decline-done">
                    <div class="panel-body">
                        <b>This reservation is off.</b>
                        {{ $deal->deposit_status === 'refunded' ? 'The $' . number_format($deal->deposit_in_cents / 100) . ' deposit was refunded to the buyer’s card.' : 'There was no card deposit to refund.' }}
                    </div>
                </div>
            @elseif ($deal->stage !== 'delivered')
                <div class="panel">
                    <div class="panel-head"><h3>Decline</h3></div>
                    <div class="panel-body">
                        @if (! $declining)
                            <p class="note" style="margin:0 0 12px;">Bank said no, or the deal can't go ahead? Decline it and the buyer gets their ${{ number_format($deal->deposit_in_cents / 100) }} back.</p>
                            <button type="button" class="mini-btn danger" wire:click="startDecline">Decline and refund ${{ number_format($deal->deposit_in_cents / 100) }}</button>
                        @else
                            <form class="decline-form" wire:submit="declineDeal">
                                <label>Reason (for your records, not sent to the buyer)
                                    <textarea wire:model="declineReason" rows="2" maxlength="500" placeholder="e.g. Not approved by any lender"></textarea>
                                    @error('declineReason') <span class="fin-err">{{ $message }}</span> @enderror
                                </label>
                                <label>Note to the buyer (optional)
                                    <textarea wire:model="declineNote" rows="3" maxlength="500" placeholder="e.g. We couldn't get an approval this time. You're welcome to try again with a co-signer."></textarea>
                                    @error('declineNote') <span class="fin-err">{{ $message }}</span> @enderror
                                </label>
                                <p class="note" style="margin:0;">The buyer is emailed, the car goes back on sale, and this can't be undone.</p>
                                <div class="fin-actions">
                                    <button type="button" class="mini-btn ghost" wire:click="cancelDecline">Keep it</button>
                                    <button type="submit" class="mini-btn danger" wire:loading.attr="disabled" wire:target="declineDeal"
                                            wire:confirm="Decline {{ $deal->reference }} and refund the deposit?">Decline and refund</button>
                                </div>
                            </form>
                        @endif
                    </div>
                </div>
            @endif

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
