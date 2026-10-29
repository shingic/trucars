<?php

use App\Mail\DocumentReuploadRequested;
use App\Mail\MoreInfoRequested;
use App\Models\Deal;
use App\Models\DealDocument;
use App\Models\LenderOffer;
use App\Support\Deposits;
use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
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

    /** The open tab, kept in the address so a refresh or a shared link lands on it. */
    #[Url]
    public string $tab = 'overview';

    public const TABS = ['overview' => 'Overview', 'financing' => 'Financing & pricing', 'documents' => 'Documents', 'activity' => 'Activity'];

    /** The lender offer ticked in the offers list, ready to publish. */
    public ?int $selectedOfferId = null;

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

    public string $financingConditions = '';

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
        $this->selectedOfferId = $deal->publishedLenderOffer()?->id
            ?? $deal->lenderOffers()->get()->first(fn (LenderOffer $offer) => $offer->isPublishable())?->id;
    }

    /**
     * Show the buyer the ticked offer in My Garage and email them the terms.
     * Only approvals with full terms can be published; the offer is looked up
     * through this deal, so another deal's offer can't be reached by id.
     */
    public function publishOffer(): void
    {
        $offer = $this->selectedOfferId ? $this->deal->lenderOffers()->whereKey($this->selectedOfferId)->first() : null;

        if ($offer === null || ! $offer->isPublishable() || in_array($this->deal->stage, ['cancelled', 'delivered'], true)) {
            return;
        }

        if ($offer->isPublished() && ! $offer->changedSincePublished($this->deal)) {
            return;
        }

        $this->deal->publishLenderOffer($offer, Auth::user()->name ?? 'Dealer');
        $this->deal->refresh();
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
            'financingConditions'     => ['nullable', 'string', 'max:500'],
        ], attributes: [
            'financingLender'         => 'lender',
            'financingApr'            => 'rate',
            'financingTermMonths'     => 'term',
            'financingPayment'        => 'payment',
            'financingFrequency'      => 'payment frequency',
            'financingAmount'         => 'amount financed',
            'financingDownPayment'    => 'down payment',
            'financingFirstPaymentOn' => 'first payment date',
            'financingConditions'     => 'conditions',
        ]);

        $terms = [
            'lender'                => trim($this->financingLender),
            'decision'              => LenderOffer::APPROVED,
            'apr'                   => $this->financingApr,
            'term_months'           => (int) $this->financingTermMonths,
            'payment_in_cents'      => (int) round((float) $this->financingPayment * 100),
            'frequency'             => $this->financingFrequency,
            'amount_in_cents'       => (int) round((float) $this->financingAmount * 100),
            'down_payment_in_cents' => $this->financingDownPayment !== '' ? (int) round((float) $this->financingDownPayment * 100) : null,
            'conditions'            => trim($this->financingConditions) !== '' ? trim($this->financingConditions) : null,
            'decided_at'            => now(),
        ];

        // Editing a hand-entered approval changes that offer; otherwise it's a new one.
        $published = $this->deal->publishedLenderOffer();
        $offer = $published?->source === LenderOffer::SOURCE_MANUAL
            ? tap($published)->update($terms)
            : $this->deal->lenderOffers()->create($terms + ['source' => LenderOffer::SOURCE_MANUAL]);

        $this->deal->update(['financing_first_payment_on' => $this->financingFirstPaymentOn !== '' ? $this->financingFirstPaymentOn : null]);
        $this->deal->publishLenderOffer($offer->fresh(), Auth::user()->name ?? 'Dealer');
        $this->deal->refresh();
        $this->selectedOfferId = $offer->id;

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
        $this->financingConditions = (string) $deal->financing_conditions;
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
    /**
     * The one thing to do next, for the callout on Overview: documents waiting
     * on a review first, then a lender offer to publish, then the next stage.
     * Null once the deal is closed.
     *
     * @return array{title: string, body: string, cta: string, tab: ?string}|null
     */
    public function nextAction($documentRows, $offers): ?array
    {
        if (in_array($this->deal->stage, ['cancelled', 'delivered'], true)) {
            return null;
        }

        $toReview = $documentRows->where('state', 'uploaded')->count();
        if ($toReview > 0) {
            return ['title' => 'Review ' . ($toReview === 1 ? 'an uploaded document' : $toReview . ' uploaded documents'), 'body' => 'The buyer sent something in. Approve it, or send it back with a reason.', 'cta' => 'Review documents', 'tab' => 'documents'];
        }

        if ($this->takesFinancing() && ! $this->deal->hasFinancingApproval() && $offers->contains(fn (LenderOffer $offer) => $offer->isPublishable())) {
            return ['title' => 'Choose a lender offer', 'body' => 'Lenders have come back with approvals. Pick the one to show the buyer in My Garage.', 'cta' => 'Review offers', 'tab' => 'financing'];
        }

        $first = $this->deal->first_name ?: 'the buyer';

        return match ($this->deal->stage) {
            'reserved'           => ['title' => 'Contact ' . $first, 'body' => 'Call or email to confirm the car is theirs and go over their pickup time.', 'cta' => 'Mark contacted', 'tab' => null],
            'contacted'          => ['title' => 'Set the appointment', 'body' => 'Agree on a day and time for ' . $first . ' to sign and take the car.', 'cta' => 'Mark appointment set', 'tab' => null],
            'appointment_set'    => ['title' => 'Start financing', 'body' => $this->takesFinancing() ? 'Submit the credit application in Dealertrack. Lender decisions arrive on the Financing tab.' : 'Cash purchase, so there is no lender to wait on.', 'cta' => 'Mark financing', 'tab' => null],
            'financing'          => ['title' => $this->takesFinancing() && ! $this->deal->hasFinancingApproval() ? 'Waiting on lenders' : 'Move to documents', 'body' => $this->takesFinancing() && ! $this->deal->hasFinancingApproval() ? 'Decisions from Dealertrack show up on the Financing tab as they come in.' : 'Marking Documents emails the buyer for proof of insurance.', 'cta' => $this->takesFinancing() && ! $this->deal->hasFinancingApproval() ? 'Open financing' : 'Mark documents', 'tab' => $this->takesFinancing() && ! $this->deal->hasFinancingApproval() ? 'financing' : null],
            'documents'          => ['title' => 'Get the car ready', 'body' => 'Once the paperwork is in, mark it ready. The buyer is emailed that their car is waiting.', 'cta' => 'Mark ready for delivery', 'tab' => null],
            'ready_for_delivery' => ['title' => 'Hand over the keys', 'body' => 'Mark it delivered once ' . $first . ' has signed and driven away.', 'cta' => 'Mark delivered', 'tab' => null],
            default              => null,
        };
    }

    /** Status of the financing line on Overview's checklist: [label, tone]. */
    public function financingStatus($offers): array
    {
        if (! $this->takesFinancing()) {
            return ['Cash purchase', 'good'];
        }

        if ($this->deal->hasFinancingApproval()) {
            return ['Published · ' . $this->deal->financing_lender, 'good'];
        }

        $ready = $offers->filter(fn (LenderOffer $offer) => $offer->isPublishable())->count();

        return match (true) {
            $ready > 0              => [$ready . ' ' . ($ready === 1 ? 'offer' : 'offers') . ' to review', 'warn'],
            $offers->isNotEmpty()   => ['Waiting on lender decisions', 'warn'],
            default                 => ['No lender decisions yet', 'muted'],
        };
    }

    public function with(): array
    {
        if (! isset(self::TABS[$this->tab])) {
            $this->tab = 'overview';
        }

        $documentRows = $this->documentRows();
        $offers = $this->deal->lenderOffers()->get();

        return [
            'feeBuckets'   => $this->deal->fees_by_kind,
            'documentRows' => $documentRows,
            'activityFeed' => $this->deal->activities()->latest()->get(),
            'offers'       => $offers,
            'selectedOffer' => $offers->firstWhere('id', $this->selectedOfferId),
            'nextAction'   => $this->nextAction($documentRows, $offers),
            'financingStatus' => $this->financingStatus($offers),
            'stages'       => array_slice(Deal::STAGE_LABELS, 0, 7, true),
        ];
    }
}; ?>

@push('styles')
    <style>
        .deal-detail { max-width:1180px; }

        .detail-back { display:inline-flex; align-items:center; gap:7px; font-size:14px; font-weight:600; color:var(--ink-2); margin-bottom:14px; text-decoration:underline; text-underline-offset:3px; text-decoration-color:var(--line-strong); transition:color .15s ease; }
        .detail-back:hover { color:var(--ink); text-decoration-color:currentColor; }
        .detail-back svg { width:16px; height:16px; }

        .detail-head { display:flex; align-items:flex-start; justify-content:space-between; gap:18px; margin-bottom:22px; flex-wrap:wrap; }
        .title-row { display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
        .detail-title h1 { font-family:var(--font-display); font-size:34px; font-weight:700; text-transform:uppercase; line-height:1; letter-spacing:0; margin:0; }
        .detail-title .sub { font-size:14.5px; color:var(--ink-2); margin-top:9px; }
        .detail-head-right { display:flex; align-items:center; gap:10px; flex-wrap:wrap; justify-content:flex-end; }

        .adv-btn { font-family:var(--font-display); font-size:16px; font-weight:700; text-transform:uppercase; color:#fff; background:var(--primary); padding:8px 16px; border-radius:8px; white-space:nowrap; transition:background .15s ease; }
        .adv-btn:hover { background:var(--primary-press); }
        .adv-btn[disabled] { opacity:.55; cursor:wait; }
        .adv-note { font-size:13px; color:var(--ink-3); }

        .more { position:relative; }
        .more-btn { width:38px; height:36px; border-radius:8px; border:1.5px solid var(--line-strong); background:#fff; display:grid; place-items:center; color:var(--ink); }
        .more-btn:hover { border-color:var(--ink); }
        .more-menu { position:absolute; right:0; top:calc(100% + 6px); z-index:20; min-width:220px; background:#fff; border:1px solid var(--line-strong); border-radius:10px; box-shadow:var(--shadow-md); padding:6px; }
        .more-menu button { display:block; width:100%; text-align:left; font-size:14px; font-weight:600; padding:9px 11px; border-radius:7px; color:#D81A48; }
        .more-menu button:hover { background:#FFEEF3; }

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

        /* The pipeline as a line of steps */
        .steps { display:grid; grid-template-columns:repeat(7, minmax(0, 1fr)); margin:0 0 6px; padding:0; list-style:none; }
        .steps li { position:relative; text-align:center; font-size:13px; font-weight:600; color:var(--ink-3); padding-top:26px; }
        .steps li::before { content:""; position:absolute; top:8px; left:50%; transform:translateX(-50%); width:16px; height:16px; border-radius:50%; background:#fff; border:2px solid var(--line-strong); box-sizing:border-box; z-index:1; }
        .steps li::after { content:""; position:absolute; top:15px; left:-50%; width:100%; height:2px; background:var(--line-strong); }
        .steps li:first-child::after { display:none; }
        .steps li.done, .steps li.now { color:var(--ink); }
        .steps li.done::before { background:var(--primary); border-color:var(--primary); background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m5 12 4.5 4.5L19 7'/%3E%3C/svg%3E"); background-size:10px; background-position:center; background-repeat:no-repeat; }
        .steps li.now::before { border-color:var(--primary); box-shadow:inset 0 0 0 3px #fff; background:var(--primary); }
        .steps li.done::after, .steps li.now::after { background:var(--primary); }
        .steps li.now { color:var(--primary-press); font-weight:700; }

        /* Tabs */
        .tabs { display:flex; gap:4px; border-bottom:1px solid var(--line-strong); margin:18px 0 22px; overflow-x:auto; scrollbar-width:none; }
        .tabs::-webkit-scrollbar { display:none; }
        .tabs button { display:inline-flex; align-items:center; gap:7px; font-size:15px; font-weight:600; color:var(--ink-2); padding:11px 14px; border-bottom:3px solid transparent; margin-bottom:-1px; white-space:nowrap; }
        .tabs button:hover { color:var(--ink); }
        .tabs button.on { color:var(--primary-press); border-bottom-color:var(--primary); }
        .tabs .count { font-size:12px; font-weight:700; min-width:20px; padding:1px 6px; border-radius:999px; background:#FFF8DD; color:#7A4E00; }

        /* Cards */
        .card { background:#fff; border:1px solid var(--line-strong); border-radius:12px; padding:18px 20px; min-width:0; }
        .card-head { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-bottom:12px; }
        .card-head h3 { font-family:var(--font-display); font-size:21px; font-weight:700; text-transform:uppercase; line-height:1.1; margin:0; }
        .card-head .ph-right { font-size:13px; font-weight:600; color:var(--ink-3); }
        .card-sub { font-size:14px; color:var(--ink-2); margin:-6px 0 12px; }
        .stack { display:flex; flex-direction:column; gap:14px; }

        .link-btn { display:inline-flex; align-items:center; gap:6px; font-size:13.5px; font-weight:700; color:var(--primary-press); padding:7px 12px; border:1.5px solid var(--primary-line); border-radius:8px; background:#fff; white-space:nowrap; text-decoration:none; }
        .link-btn:hover { border-color:var(--primary); background:var(--primary-soft); }
        .link-btn svg { width:14px; height:14px; }

        /* Overview */
        .next { display:flex; align-items:center; gap:16px; background:#F1F7FF; border:1px solid var(--primary-line); border-radius:12px; padding:16px 20px; margin-bottom:14px; }
        .next-ic { width:48px; height:48px; border-radius:50%; background:#fff; border:1px solid var(--primary-line); display:grid; place-items:center; color:var(--primary); flex-shrink:0; }
        .next-ic svg { width:22px; height:22px; }
        .next-main { flex:1; min-width:0; }
        .next-main small { display:block; font-size:13px; font-weight:700; color:var(--primary-press); }
        .next-main b { display:block; font-family:var(--font-display); font-size:22px; font-weight:700; line-height:1.15; margin:2px 0 3px; }
        .next-main p { margin:0; font-size:14px; color:var(--ink-2); }
        .next-cta { display:inline-flex; align-items:center; gap:8px; background:var(--primary); color:#fff; font-size:15px; font-weight:700; padding:11px 18px; border-radius:8px; white-space:nowrap; }
        .next-cta:hover { background:var(--primary-press); }

        .ov-grid { display:grid; grid-template-columns:minmax(0, 1.6fr) minmax(0, 1fr); gap:14px; align-items:start; }
        .veh { display:grid; grid-template-columns:200px minmax(0, 1fr); gap:18px; align-items:center; }
        .veh-photo { position:relative; overflow:hidden; aspect-ratio:4/2.8; border-radius:8px; background:var(--bg); }
        .veh-photo img { position:absolute; top:0; left:0; width:100%; height:107%; object-fit:cover; object-position:center 40%; }
        .veh-name { font-size:17px; font-weight:700; line-height:1.25; }
        .veh-specs { font-size:13.5px; color:var(--ink-3); margin-top:3px; }
        .veh-price { font-family:var(--font-display); font-size:28px; font-weight:700; line-height:1; margin:10px 0 12px; }

        .who { display:flex; align-items:center; gap:12px; }
        .who-av { width:44px; height:44px; border-radius:50%; background:var(--bg); border:1px solid var(--line-strong); display:grid; place-items:center; font-weight:700; color:var(--ink-2); flex-shrink:0; }
        .who b { display:block; font-size:16px; }
        .contact { display:flex; flex-direction:column; gap:6px; margin:12px 0 0; font-size:14px; }
        .contact a, .contact span { display:inline-flex; align-items:center; gap:9px; color:var(--ink); text-decoration:none; overflow-wrap:anywhere; }
        .contact a:hover { color:var(--primary-press); }
        .contact svg { width:15px; height:15px; color:var(--ink-3); flex-shrink:0; }

        .check { display:grid; grid-template-columns:22px minmax(0, 150px) minmax(0, 1fr) auto; align-items:center; gap:12px; padding:12px 0; border-top:1px solid var(--line-strong); font-size:14.5px; }
        .check:first-of-type { border-top:none; padding-top:2px; }
        .check > svg { width:20px; height:20px; color:var(--ink-3); }
        .check b { font-weight:700; }
        .tone { display:inline-flex; align-items:center; gap:6px; font-size:13px; font-weight:700; padding:3px 9px; border-radius:5px; white-space:nowrap; line-height:1.4; justify-self:start; max-width:100%; overflow:hidden; text-overflow:ellipsis; }
        .tone.good { background:#DDF5EB; color:#0B7355; }
        .tone.warn { background:#FFF8DD; color:#7A4E00; }
        .tone.muted { background:rgba(7,20,55,.06); color:var(--ink-2); }
        .tone.bad { background:#FFEEF3; color:#D81A48; }
        .tone.info { background:#E9F3FF; color:var(--primary-press); }
        .ring { display:inline-flex; align-items:center; gap:8px; color:var(--ink-2); font-size:14px; }
        .ring svg { width:20px; height:20px; transform:rotate(-90deg); }

        .side-line { display:flex; align-items:flex-start; gap:10px; font-size:14.5px; }
        .side-line svg { width:18px; height:18px; color:var(--ink-3); flex-shrink:0; margin-top:2px; }
        .big-money { font-family:var(--font-display); font-size:26px; font-weight:700; line-height:1; }

        .latest { display:flex; align-items:center; gap:14px; }
        .latest > div { flex:1; min-width:0; }

        /* Financing & pricing */
        .strip { display:flex; align-items:center; gap:16px; }
        .strip .veh-photo { width:120px; flex-shrink:0; }
        .strip > div:not(.veh-photo):not(.veh-price) { flex:1; min-width:0; }
        .strip .veh-price { margin:0; font-size:26px; }

        .src { display:inline-flex; align-items:center; gap:7px; font-size:13px; color:var(--ink-2); }
        .src i { width:8px; height:8px; border-radius:50%; background:var(--good); }
        .src.off i { background:var(--ink-3); }

        .offers { width:100%; border-collapse:collapse; font-size:14.5px; }
        .offers th { text-align:left; font-size:12.5px; font-weight:700; color:var(--ink-3); padding:8px 10px; border-bottom:1px solid var(--line-strong); white-space:nowrap; }
        .offers td { padding:13px 10px; border-bottom:1px solid var(--line-strong); vertical-align:middle; }
        .offers tr.pick { cursor:pointer; }
        .offers tr.pick:hover td { background:var(--bg); }
        .offers tr.on td { background:#F1F7FF; }
        .offers tr.off td { color:var(--ink-3); }
        .offers input[type=radio] { width:18px; height:18px; accent-color:var(--primary); margin:0; }
        .offers .lender { font-weight:700; }
        .offers .src-tag { display:block; font-size:12px; font-weight:500; color:var(--ink-3); }
        .offers .pay { font-weight:700; white-space:nowrap; }
        .offers .cond { font-size:13.5px; color:var(--ink-2); max-width:240px; }
        .offers-wrap { overflow-x:auto; margin:0 -4px; }

        .publish-bar { display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap; padding-top:14px; }
        .publish-bar .sel { font-size:14.5px; }
        .publish-btn { background:var(--primary); color:#fff; font-size:15px; font-weight:700; padding:11px 22px; border-radius:8px; white-space:nowrap; }
        .publish-btn:hover { background:var(--primary-press); }
        .publish-btn[disabled] { opacity:.45; cursor:default; }

        .preview { display:grid; grid-template-columns:minmax(0, .9fr) minmax(0, 1.1fr); gap:20px; border:1px solid var(--line-strong); border-radius:10px; padding:16px 18px; }
        .preview-lender { font-size:18px; font-weight:700; }
        .preview-pay { font-family:var(--font-display); font-size:30px; font-weight:700; line-height:1; }
        .preview-pay small { font-family:var(--font); font-size:15px; font-weight:600; color:var(--ink-2); }
        .preview-terms { font-size:14px; color:var(--ink-2); margin-top:6px; }
        .preview-cond { font-size:13.5px; color:var(--ink-2); margin-top:8px; }

        .sect { background:#fff; border:1px solid var(--line-strong); border-radius:12px; }
        .sect > summary { display:flex; align-items:center; gap:12px; padding:15px 20px; cursor:pointer; list-style:none; }
        .sect > summary::-webkit-details-marker { display:none; }
        .sect > summary svg.ic { width:20px; height:20px; color:var(--ink-2); flex-shrink:0; }
        .sect > summary b { font-family:var(--font-display); font-size:19px; font-weight:700; text-transform:uppercase; flex:1; }
        .sect > summary span { font-size:14px; color:var(--ink-2); text-align:right; }
        .sect > summary::after { content:""; width:9px; height:9px; border-right:2px solid var(--ink-2); border-bottom:2px solid var(--ink-2); transform:rotate(45deg); margin:0 4px 4px 6px; transition:transform .15s ease; flex-shrink:0; }
        .sect[open] > summary::after { transform:rotate(-135deg); margin-bottom:-4px; }
        .sect-body { padding:0 20px 18px; }

        .kv { display:flex; flex-direction:column; border-top:1px solid var(--line-strong); }
        .kv .row { display:flex; align-items:baseline; justify-content:space-between; gap:16px; padding:9px 0; border-bottom:1px solid var(--line-strong); font-size:14px; }
        .kv .k { color:var(--ink-3); font-weight:600; flex-shrink:0; }
        .kv .v { color:var(--ink); font-weight:600; text-align:right; min-width:0; overflow-wrap:anywhere; }
        .kv .v .est { font-weight:500; color:var(--ink-3); }
        .kv .v.mono { font-family:var(--mono); font-size:13px; letter-spacing:-.01em; }

        .estimate-line { display:flex; align-items:baseline; justify-content:space-between; gap:12px; margin-top:14px; padding-top:12px; border-top:2px solid var(--ink); flex-wrap:wrap; }
        .estimate-line .lbl { font-size:13px; font-weight:600; color:var(--ink-3); }
        .estimate-line .amt { font-family:var(--font-display); font-size:26px; font-weight:700; line-height:1; color:var(--ink); }

        .chips { display:flex; flex-wrap:wrap; gap:6px; }
        .chip { font-size:13px; font-weight:600; padding:4px 9px; border-radius:5px; background:rgba(7,20,55,.06); color:var(--ink-2); }
        .chip.warranty { background:#E9F3FF; color:var(--primary-press); }

        .note { font-size:13px; color:var(--ink-3); line-height:1.55; margin:14px 0 0; }
        .note.tight { margin-top:10px; }

        .fee-row { display:flex; align-items:baseline; justify-content:space-between; gap:12px; padding:9px 0; border-bottom:1px solid var(--line-strong); font-size:14px; }
        .fee-row .fee-label { color:var(--ink); font-weight:600; }
        .fee-row .fee-amt { color:var(--ink-2); font-weight:600; font-variant-numeric:tabular-nums; }
        .fee-group-label { font-size:13px; font-weight:700; color:var(--ink-2); background:var(--bg); margin:14px 0 0; padding:8px 10px; border-radius:6px; }
        .fee-group-label:first-child { margin-top:0; }
        .fee-total { display:flex; align-items:baseline; justify-content:space-between; gap:12px; padding-top:11px; font-size:15px; font-weight:700; }

        .fin-form { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-top:4px; }
        .fin-form label { display:flex; flex-direction:column; gap:5px; font-size:13px; font-weight:600; color:var(--ink-2); min-width:0; }
        .fin-form label.wide, .fin-actions { grid-column:1 / -1; }
        .fin-form input, .fin-form select { width:100%; border:1.5px solid var(--line-strong); border-radius:8px; padding:9px 11px; font-size:14.5px; font-family:inherit; color:var(--ink); background:#fff; outline:none; }
        .fin-form input:focus, .fin-form select:focus { border-color:var(--ink); box-shadow:0 0 0 3px var(--primary-soft); }
        .fin-err { font-size:13px; color:#D81A48; font-weight:600; }
        .fin-actions { display:flex; gap:9px; justify-content:flex-end; flex-wrap:wrap; }

        /* Documents */
        .docs { width:100%; border-collapse:collapse; font-size:14.5px; }
        .docs th { text-align:left; font-size:12.5px; font-weight:700; color:var(--ink-3); padding:9px 12px; background:var(--bg); }
        .docs th:first-child { border-radius:8px 0 0 8px; } .docs th:last-child { border-radius:0 8px 8px 0; }
        .docs td { padding:12px; border-bottom:1px solid var(--line-strong); vertical-align:middle; }
        .docs tr:last-child td { border-bottom:none; }
        .doc-name { display:flex; align-items:center; gap:11px; font-weight:700; }
        .doc-name svg { width:20px; height:20px; color:var(--ink-2); flex-shrink:0; }
        .doc-name small { display:block; font-size:13px; font-weight:500; color:var(--ink-3); margin-top:1px; }
        .doc-actions { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
        .reject-row td { padding-top:0; }

        .ask { display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-top:14px; padding-top:14px; border-top:1px solid var(--line-strong); }
        .ask-label { font-size:14px; font-weight:700; color:var(--ink-2); margin-right:4px; }
        .ask-chip { font-size:13px; font-weight:600; color:var(--ink-2); background:var(--bg); border:1px solid var(--line-strong); border-radius:999px; padding:6px 13px; }
        .ask-chip:hover { border-color:var(--ink); color:var(--ink); }
        .ask-chip.on { border-color:var(--ink); color:#fff; background:var(--ink); }
        .ask-box { display:grid; gap:12px; margin-top:12px; background:var(--bg); border:1px solid var(--line-strong); border-radius:10px; padding:14px; }
        .ask-box label, .decline-form label { display:flex; flex-direction:column; gap:5px; font-size:13px; font-weight:600; color:var(--ink-2); }
        .ask-box input, .decline-form textarea { width:100%; border:1.5px solid var(--line-strong); border-radius:8px; padding:9px 11px; font-size:14px; font-family:inherit; color:var(--ink); background:#fff; outline:none; resize:vertical; }
        .ask-box input:focus, .decline-form textarea:focus { border-color:var(--ink); box-shadow:0 0 0 3px var(--primary-soft); }

        .decline-form { display:grid; gap:12px; }
        .decline-card { border-color:#F2B8C6; margin-bottom:14px; }
        .decline-done { background:#FFEEF3; border:1px solid #F2B8C6; border-radius:12px; padding:14px 18px; font-size:14px; line-height:1.55; color:var(--ink-2); margin-bottom:14px; }
        .decline-done b { color:#D81A48; }

        .mini-btn { font-size:13px; font-weight:700; padding:7px 12px; border-radius:8px; white-space:nowrap; transition:background .15s ease, color .15s ease, border-color .15s ease; border:1.5px solid transparent; }
        .mini-btn[disabled] { opacity:.55; cursor:wait; }
        .mini-btn.approve { background:var(--primary); border-color:var(--primary); color:#fff; }
        .mini-btn.approve:hover { background:var(--primary-press); border-color:var(--primary-press); }
        .mini-btn.ghost { color:var(--ink); background:#fff; border-color:var(--line-strong); }
        .mini-btn.ghost:hover { border-color:var(--ink); }
        .mini-btn.flag { color:#7A4E00; background:#fff; border-color:#C59A00; }
        .mini-btn.flag:hover { background:#FFF8DD; }
        .mini-btn.danger { color:#D81A48; border-color:#D81A48; background:#fff; }
        .mini-btn.danger:hover { background:#FFEEF3; }
        .mini-btn.danger[type="submit"] { background:#D81A48; color:#fff; border-color:#D81A48; }
        .mini-btn.danger[type="submit"]:hover { background:#B5143B; border-color:#B5143B; }

        .reject-box { background:var(--bg); border:1px solid var(--line-strong); border-radius:10px; padding:13px; }
        .reject-box label { font-size:13px; font-weight:600; color:var(--ink-2); display:block; margin-bottom:7px; }
        .reject-box textarea { width:100%; border:1.5px solid var(--line-strong); border-radius:8px; padding:10px 12px; font-size:14px; font-family:inherit; color:var(--ink); outline:none; resize:vertical; min-height:62px; background:#fff; }
        .reject-box textarea:focus { border-color:var(--ink); box-shadow:0 0 0 3px var(--primary-soft); }
        .reject-box-actions { display:flex; gap:9px; justify-content:flex-end; margin-top:11px; flex-wrap:wrap; }
        .reject-err { font-size:13px; color:#D81A48; font-weight:600; margin-top:7px; }

        /* Activity */
        .act-list { display:flex; flex-direction:column; }
        .act-item { display:flex; gap:11px; padding:11px 0; border-bottom:1px solid var(--line-strong); }
        .act-item:last-child { border-bottom:none; }
        .act-dot { width:4px; align-self:stretch; border-radius:2px; flex-shrink:0; background:var(--ink-3); }
        .act-dot.status { background:var(--ink); }
        .act-dot.document { background:#1F6E78; }
        .act-dot.sms { background:#0B7355; }
        .act-dot.email { background:var(--primary); }
        .act-dot.note { background:#C59A00; }
        .act-dot.lender { background:#5B3A9E; }
        .act-body { font-size:14px; line-height:1.5; color:var(--ink); }
        .act-meta { font-size:13px; color:var(--ink-3); margin-top:3px; }

        .empty-line { font-size:14px; color:var(--ink-2); padding:14px; margin:0; border:1.5px dashed var(--line-strong); border-radius:8px; }

        @media (max-width:920px) {
            .ov-grid { grid-template-columns:minmax(0, 1fr); }
            .preview { grid-template-columns:minmax(0, 1fr); }
            .steps li { font-size:0; }
            .steps li.now { font-size:12px; }
        }
        @media (max-width:600px) {
            .detail-head-right { justify-content:flex-start; }
            .veh { grid-template-columns:minmax(0, 1fr); }
            .next { flex-wrap:wrap; }
            .check { grid-template-columns:22px minmax(0, 1fr) auto; }
            .check > :nth-child(3) { grid-column:2 / -1; grid-row:2; }
            .fin-form { grid-template-columns:1fr; }
            .docs thead { display:none; }
            .docs td { display:block; padding:6px 0; border:none; }
            .docs tr { display:block; padding:10px 0; border-bottom:1px solid var(--line-strong); }
        }
    </style>
@endpush

@push('crumb')
    Dealer <span>·</span> <a href="{{ route('dealer.reservations') }}">Reservations</a> <span>·</span> <span class="cur">{{ $deal->customer_full_name }}</span>
@endpush

@php
    $vehicle = $deal->vehicle;
    $closed = in_array($deal->stage, ['cancelled', 'delivered'], true);
    $stageKeys = array_keys($stages);
    $stageAt = array_search($deal->stage, $stageKeys, true);
    $received = $this->documentsReceived();
    $toReview = $documentRows->where('state', 'uploaded')->count();
    $offersToReview = $deal->hasFinancingApproval() ? 0 : $offers->filter(fn ($offer) => $offer->isPublishable())->count();
    $latestDecision = $offers->where('source', \App\Models\LenderOffer::SOURCE_DEALERTRACK)->max('updated_at');
    $money = fn (?int $cents, int $decimals = 0) => $cents === null ? '—' : '$' . number_format($cents / 100, $decimals);
    $specs = collect([$vehicle->trim, $vehicle->drivetrain, $vehicle->transmission, $vehicle->display_kilometres])->filter()->implode(' · ');
    $listingUrl = rtrim(config('app.url'), '/') . '/cars/' . $vehicle->id;
    $ic = [
        'card'   => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h4"/>',
        'doc'    => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8Z"/><path d="M14 3v5h5M9 13h6M9 17h4"/>',
        'id'     => '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="11" r="2"/><path d="M6.5 16c.6-1.4 1.5-2 2.5-2s1.9.6 2.5 2M14 10h4M14 13h3"/>',
        'shield' => '<path d="M12 3 4 6v6c0 5 8 9 8 9s8-4 8-9V6Z"/><path d="m9 12 2 2 4-4"/>',
        'car'    => '<path d="M5 17h14M3 17v-4l2-5h14l2 5v4M7 17v2M17 17v2"/><circle cx="7.5" cy="13.5" r="1"/><circle cx="16.5" cy="13.5" r="1"/>',
        'cal'    => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
        'coins'  => '<ellipse cx="12" cy="6" rx="7" ry="3"/><path d="M5 6v6c0 1.7 3.1 3 7 3s7-1.3 7-3V6M5 12v6c0 1.7 3.1 3 7 3s7-1.3 7-3v-6"/>',
        'calc'   => '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M8 7h8M8 12h1M12 12h1M16 12h0M8 16h1M12 16h1M15 16h1"/>',
        'swap'   => '<path d="M4 8h13l-3-3M20 16H7l3 3"/>',
        'phone'  => '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/>',
        'mail'   => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
        'pin'    => '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
        'arrow'  => '<path d="m9 6 6 6-6 6"/>',
        'out'    => '<path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
        'clock'  => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'check'  => '<path d="m5 12 4.5 4.5L19 7"/>',
        'eye'    => '<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
        'warn'   => '<path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9 2 18a2 2 0 0 0 1.7 3h16.6a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/>',
    ];
    $svg = fn (string $name, string $class = '') => '<svg' . ($class ? ' class="' . $class . '"' : '') . ' viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $ic[$name] . '</svg>';
    $docIcon = fn (string $name) => match (true) {
        str_contains(strtolower($name), 'licence') || str_contains(strtolower($name), 'license') => 'id',
        str_contains(strtolower($name), 'insurance') => 'shield',
        str_contains(strtolower($name), 'cheque') || str_contains(strtolower($name), 'bank') => 'card',
        default => 'doc',
    };
@endphp

<div class="deal-detail">
    <a href="{{ route('dealer.reservations') }}" class="detail-back">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M11 6l-6 6 6 6"/></svg>
        Back to reservations
    </a>

    <div class="detail-head">
        <div class="detail-title">
            <div class="title-row">
                <h1>{{ $deal->customer_full_name }}</h1>
                <span class="stage {{ $deal->stage }}">{{ $deal->stage_label }}</span>
            </div>
            <div class="sub">
                Reservation {{ $deal->reference }} ·
                {{ $vehicle->model_year }} {{ $vehicle->make }} {{ $vehicle->model }} ·
                reserved {{ $deal->created_at->diffForHumans() }}
            </div>
        </div>

        <div class="detail-head-right">
            <a class="link-btn" href="mailto:{{ $deal->email }}">{!! $svg('mail') !!} Email customer</a>
            @if (isset(App\Models\Deal::NEXT_STAGE[$deal->stage]))
                @if ($this->nextStageEmail())
                    <span class="adv-note">{{ $this->nextStageEmail() }}</span>
                @endif
                <button type="button" class="adv-btn" wire:click="advanceStage" wire:loading.attr="disabled" wire:target="advanceStage">
                    Mark {{ App\Models\Deal::STAGE_LABELS[App\Models\Deal::NEXT_STAGE[$deal->stage]] }}
                </button>
            @endif
            @unless ($closed)
                <div class="more" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
                    <button type="button" class="more-btn" @click="open = !open" aria-label="More actions">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><circle cx="5" cy="12" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="19" cy="12" r="2"/></svg>
                    </button>
                    <div class="more-menu" x-show="open" x-cloak x-transition>
                        <button type="button" wire:click="startDecline" @click="open = false">Decline and refund ${{ number_format($deal->deposit_in_cents / 100) }}</button>
                    </div>
                </div>
            @endunless
        </div>
    </div>

    @if ($deal->stage !== 'cancelled')
        <ol class="steps" aria-label="Reservation progress">
            @foreach ($stages as $key => $label)
                <li class="{{ $loop->index < $stageAt || $deal->stage === 'delivered' ? 'done' : ($key === $deal->stage ? 'now' : '') }}">{{ $label }}</li>
            @endforeach
        </ol>
    @endif

    <nav class="tabs" role="tablist">
        @foreach ($this::TABS as $key => $label)
            <button type="button" role="tab" class="{{ $tab === $key ? 'on' : '' }}" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" wire:click="$set('tab', '{{ $key }}')">
                {{ $label }}
                @if ($key === 'financing' && $offersToReview > 0) <span class="count">{{ $offersToReview }}</span> @endif
                @if ($key === 'documents' && $toReview > 0) <span class="count">{{ $toReview }}</span> @endif
            </button>
        @endforeach
    </nav>

    {{-- Decline: call the reservation off and give the buyer their deposit back (opened from the ⋯ menu) --}}
    @if ($declining && ! $closed)
        <div class="card decline-card">
            <div class="card-head"><h3>Decline this reservation</h3></div>
            <form class="decline-form" wire:submit="declineDeal">
                <label>Reason (for your records, not sent to the buyer)
                    <textarea wire:model="declineReason" rows="2" maxlength="500" placeholder="e.g. Not approved by any lender"></textarea>
                    @error('declineReason') <span class="fin-err">{{ $message }}</span> @enderror
                </label>
                <label>Note to the buyer (optional)
                    <textarea wire:model="declineNote" rows="3" maxlength="500" placeholder="e.g. We couldn't get an approval this time. You're welcome to try again with a co-signer."></textarea>
                    @error('declineNote') <span class="fin-err">{{ $message }}</span> @enderror
                </label>
                <p class="note" style="margin:0;">The buyer gets their ${{ number_format($deal->deposit_in_cents / 100) }} back and is emailed, the car goes back on sale, and this can't be undone.</p>
                <div class="fin-actions">
                    <button type="button" class="mini-btn ghost" wire:click="cancelDecline">Keep it</button>
                    <button type="submit" class="mini-btn danger" wire:loading.attr="disabled" wire:target="declineDeal"
                            wire:confirm="Decline {{ $deal->reference }} and refund the deposit?">Decline and refund</button>
                </div>
            </form>
        </div>
    @endif

    @if ($deal->stage === 'cancelled')
        <div class="decline-done">
            <b>This reservation is off.</b>
            {{ $deal->deposit_status === 'refunded' ? 'The $' . number_format($deal->deposit_in_cents / 100) . ' deposit was refunded to the buyer’s card.' : 'There was no card deposit to refund.' }}
        </div>
    @endif

    {{-- ───────────── OVERVIEW ───────────── --}}
    @if ($tab === 'overview')
        @if ($nextAction)
            <div class="next">
                <span class="next-ic">{!! $svg($nextAction['tab'] === 'documents' ? 'doc' : ($nextAction['tab'] === 'financing' ? 'calc' : 'cal')) !!}</span>
                <div class="next-main">
                    <small>Next action</small>
                    <b>{{ $nextAction['title'] }}</b>
                    <p>{{ $nextAction['body'] }}</p>
                </div>
                @if ($nextAction['tab'])
                    <button type="button" class="next-cta" wire:click="$set('tab', '{{ $nextAction['tab'] }}')">{{ $nextAction['cta'] }} {!! $svg('arrow') !!}</button>
                @else
                    <button type="button" class="next-cta" wire:click="advanceStage" wire:loading.attr="disabled" wire:target="advanceStage">{{ $nextAction['cta'] }} {!! $svg('arrow') !!}</button>
                @endif
            </div>
        @endif

        <div class="ov-grid">
            <div class="stack">
                <div class="card">
                    <div class="card-head"><h3>Vehicle</h3>@if ($vehicle->stock_number)<span class="ph-right">Stock # {{ $vehicle->stock_number }}</span>@endif</div>
                    <div class="veh">
                        <div class="veh-photo">@if ($vehicle->primary_photo_url)<img src="{{ $vehicle->primary_photo_url }}" alt="">@endif</div>
                        <div>
                            <div class="veh-name">{{ $vehicle->model_year }} {{ $vehicle->make }} {{ $vehicle->model }}</div>
                            @if ($specs)<div class="veh-specs">{{ $specs }}</div>@endif
                            <div class="veh-price">{{ $money($vehicle->price_in_cents) }}</div>
                            <a class="link-btn" href="{{ $listingUrl }}" target="_blank" rel="noopener">View listing {!! $svg('out') !!}</a>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-head"><h3>Before pickup</h3></div>
                    <div class="check">
                        {!! $svg('calc') !!}<b>Financing</b>
                        <span class="tone {{ $financingStatus[1] }}">{{ $financingStatus[0] }}</span>
                        @if ($this->takesFinancing())<button type="button" class="link-btn" wire:click="$set('tab', 'financing')">Review {!! $svg('arrow') !!}</button>@else<span></span>@endif
                    </div>
                    <div class="check">
                        {!! $svg('doc') !!}<b>Buyer documents</b>
                        <span class="ring">
                            <svg viewBox="0 0 20 20"><circle cx="10" cy="10" r="8" fill="none" stroke="rgba(7,20,55,.12)" stroke-width="3"/><circle cx="10" cy="10" r="8" fill="none" stroke="{{ $received === $documentRows->count() && $received > 0 ? '#17C653' : '#1B84FF' }}" stroke-width="3" stroke-dasharray="{{ $documentRows->count() ? round(50.27 * $received / $documentRows->count(), 2) : 0 }} 50.27"/></svg>
                            {{ $received }} of {{ $documentRows->count() }} received
                        </span>
                        <button type="button" class="link-btn" wire:click="$set('tab', 'documents')">View documents {!! $svg('arrow') !!}</button>
                    </div>
                    @if ($deal->tradeIn)
                        <div class="check">
                            {!! $svg('swap') !!}<b>Trade-in</b>
                            <span class="tone info">{{ $deal->tradeIn->model_year }} {{ $deal->tradeIn->make }} {{ $deal->tradeIn->model }} · confirm after inspection</span>
                            <button type="button" class="link-btn" wire:click="$set('tab', 'financing')">View {!! $svg('arrow') !!}</button>
                        </div>
                    @endif
                    @if ($this->hasCoverageInterest())
                        <div class="check">
                            {!! $svg('shield') !!}<b>Protection</b>
                            <span class="tone info">{{ count($deal->protection_snapshot ?? []) ?: count(array_filter([$deal->warranty_plan, ...($deal->extras_interest ?? [])])) }} in the buyer's estimate</span>
                            <button type="button" class="link-btn" wire:click="$set('tab', 'financing')">Confirm {!! $svg('arrow') !!}</button>
                        </div>
                    @endif
                </div>
            </div>

            <div class="stack">
                <div class="card">
                    <div class="card-head">
                        <h3>Customer</h3>
                        @if ($deal->identity_verified_at)
                            <span class="tone good">{!! $svg('check') !!} Identity verified</span>
                        @else
                            <span class="tone warn">Not verified</span>
                        @endif
                    </div>
                    <div class="who">
                        <span class="who-av">{{ strtoupper(mb_substr($deal->first_name, 0, 1) . mb_substr($deal->last_name, 0, 1)) }}</span>
                        <b>{{ $deal->customer_full_name }}</b>
                    </div>
                    <div class="contact">
                        <a href="tel:{{ $deal->phone }}">{!! $svg('phone') !!} {{ $deal->phone }}</a>
                        <a href="mailto:{{ $deal->email }}">{!! $svg('mail') !!} {{ $deal->email }}</a>
                        @if ($deal->street_address || $deal->city)
                            <span>{!! $svg('pin') !!} {{ collect([$deal->street_address, $deal->city, $deal->province, $deal->postal_code])->filter()->implode(', ') }}</span>
                        @endif
                    </div>
                </div>

                <div class="card">
                    <div class="card-head"><h3>{{ $deal->handover_mode === 'delivery' ? 'Requested delivery' : 'Requested pickup' }}</h3></div>
                    <div class="stack" style="gap:8px;">
                        @if ($deal->pickup_at)
                            <div class="side-line">{!! $svg('cal') !!} {{ $deal->local_pickup_at->format('D M j, g:i a T') }}</div>
                        @endif
                        @if ($deal->pickup_location)
                            <div class="side-line">{!! $svg('pin') !!} {{ $deal->pickup_location }}</div>
                        @endif
                        @if ($deal->rooftop_label && $deal->dealer?->rooftops()->count() > 1)
                            <div class="side-line">{!! $svg('car') !!} {{ $deal->rooftop_label }}</div>
                        @endif
                        <p class="note" style="margin:2px 0 0;">Confirm the exact time with the buyer.</p>
                    </div>
                </div>

                <div class="card">
                    <div class="card-head"><h3>Deposit</h3></div>
                    <div class="side-line" style="align-items:center;">{!! $svg('coins') !!} <span class="big-money">{{ $money($deal->deposit_in_cents) }}</span>
                        <span class="tone {{ $deal->deposit_status === 'refunded' ? 'muted' : 'good' }}">{{ $deal->deposit_status === 'refunded' ? 'Refunded' : 'Held · refundable' }}</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="card" style="margin-top:14px;">
            <div class="card-head"><h3>Latest activity</h3></div>
            @if ($latest = $activityFeed->first())
                <div class="latest">
                    <div>
                        <div class="act-body">{{ $latest->body }}</div>
                        <div class="act-meta">{{ $latest->author_name ?? 'TruCars' }} · {{ $latest->created_at->diffForHumans() }}</div>
                    </div>
                    <button type="button" class="link-btn" wire:click="$set('tab', 'activity')">View activity {!! $svg('arrow') !!}</button>
                </div>
            @else
                <p class="empty-line">No activity yet.</p>
            @endif
        </div>
    @endif

    {{-- ───────────── FINANCING & PRICING ───────────── --}}
    @if ($tab === 'financing')
        <div class="stack">
            <div class="card">
                <div class="strip">
                    <div class="veh-photo">@if ($vehicle->primary_photo_url)<img src="{{ $vehicle->primary_photo_url }}" alt="">@endif</div>
                    <div>
                        <div class="veh-name">{{ $vehicle->model_year }} {{ $vehicle->make }} {{ $vehicle->model }}</div>
                        <div class="veh-specs">{{ collect([$vehicle->stock_number ? 'Stock ' . $vehicle->stock_number : null, $specs])->filter()->implode(' · ') }}</div>
                        <div class="veh-specs">Buyer chose: <b style="color:var(--ink);">{{ $this->purchaseTypeLabel() }}</b>@if ($this->takesFinancing() && $deal->term_months) · {{ $deal->term_months }} months @endif @if ($this->takesFinancing() && $deal->down_payment_in_cents !== null) · {{ $money($deal->down_payment_in_cents) }} down @endif</div>
                    </div>
                    <div class="veh-price">{{ $money($vehicle->price_in_cents) }}</div>
                </div>
            </div>

            @if ($this->takesFinancing())
                <div class="card">
                    <div class="card-head">
                        <h3>Lender offers</h3>
                        @if ($latestDecision)
                            <span class="src"><i></i> Dealertrack · last decision {{ \Illuminate\Support\Carbon::parse($latestDecision)->diffForHumans() }}</span>
                        @else
                            <span class="src off"><i></i> Waiting on Dealertrack</span>
                        @endif
                    </div>
                    <p class="card-sub">Lender decisions from Dealertrack land here on their own. Pick the one to show the buyer; only the offer you publish appears in their My Garage.</p>

                    @if ($offers->isEmpty())
                        <p class="empty-line">No lender decisions yet. Once the credit application is submitted in Dealertrack, each lender's answer shows up here.</p>
                    @else
                        <div class="offers-wrap">
                            <table class="offers">
                                <thead><tr><th></th><th>Lender</th><th>Decision</th><th>APR</th><th>Term</th><th>Payment</th><th>Conditions</th></tr></thead>
                                <tbody>
                                    @foreach ($offers as $offer)
                                        <tr wire:key="offer-{{ $offer->id }}" class="{{ $offer->isPublishable() ? 'pick' : 'off' }} {{ $selectedOfferId === $offer->id ? 'on' : '' }}"
                                            @if ($offer->isPublishable()) wire:click="$set('selectedOfferId', {{ $offer->id }})" @endif>
                                            <td><input type="radio" name="offer" value="{{ $offer->id }}" @checked($selectedOfferId === $offer->id) @disabled(! $offer->isPublishable()) aria-label="Choose {{ $offer->lender }}"></td>
                                            <td class="lender">{{ $offer->lender }}<span class="src-tag">{{ $offer->source === 'manual' ? 'Entered by hand' : 'Dealertrack' }}@if ($offer->isPublished()) · published @endif</span></td>
                                            <td><span class="tone {{ ['approved' => 'good', 'conditional' => 'warn', 'pending' => 'muted', 'declined' => 'bad'][$offer->decision] ?? 'muted' }}">{{ $offer->decision_label }}</span></td>
                                            <td>{{ $offer->apr_label ?? '—' }}</td>
                                            <td>{{ $offer->term_months ? $offer->term_months . ' months' : '—' }}</td>
                                            <td class="pay">{{ $offer->payment_label ?? '—' }}</td>
                                            <td class="cond">{{ $offer->conditions ?? ($offer->decision === 'pending' ? 'Awaiting decision' : '—') }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    @if ($selectedOffer && $selectedOffer->isPublishable())
                        @php($stale = $selectedOffer->changedSincePublished($deal))
                        <div class="publish-bar">
                            <div class="sel">
                                Selected: <b>{{ $selectedOffer->lender }}</b>
                                @if ($selectedOffer->isPublished() && ! $stale)
                                    <span class="tone good">{!! $svg('check') !!} In My Garage</span>
                                @elseif ($stale)
                                    <span class="tone warn">Lender changed the terms since you published</span>
                                @else
                                    <span class="tone muted">Not published</span>
                                @endif
                            </div>
                            @unless ($closed)
                                <button type="button" class="publish-btn" wire:click="publishOffer" wire:loading.attr="disabled" wire:target="publishOffer"
                                        @disabled($selectedOffer->isPublished() && ! $stale)>
                                    {{ $stale ? 'Publish the new terms' : ($deal->hasFinancingApproval() && ! $selectedOffer->isPublished() ? 'Publish instead' : 'Publish to My Garage') }}
                                </button>
                            @endunless
                        </div>
                    @endif
                </div>

                @if ($selectedOffer && $selectedOffer->isPublishable())
                    <div class="card">
                        <div class="card-head">
                            <h3>My Garage preview</h3>
                            @if ($selectedOffer->isPublished() && $selectedOffer->source === 'manual' && ! $closed)
                                <button type="button" class="mini-btn ghost" wire:click="editFinancing">Edit terms</button>
                            @endif
                        </div>
                        <div class="preview">
                            <div>
                                <div class="preview-lender">{{ $selectedOffer->lender }}</div>
                                <span class="tone {{ $selectedOffer->decision === 'approved' ? 'good' : 'warn' }}" style="margin-top:6px;">{{ $selectedOffer->decision === 'approved' ? 'Approved' : 'Approved with conditions' }}</span>
                                <p class="note tight">Subject to the lender's final review and the signed agreement.</p>
                            </div>
                            <div>
                                <div class="preview-pay">${{ number_format($selectedOffer->payment_in_cents / 100, 2) }} <small>{{ strtolower(App\Models\Deal::PAYMENT_FREQUENCIES[$selectedOffer->frequency ?? 'biweekly'] ?? '') }}</small></div>
                                <div class="preview-terms">{{ $selectedOffer->apr_label }} APR · {{ $selectedOffer->term_months }} months @if ($selectedOffer->down_payment_in_cents !== null) · {{ $money($selectedOffer->down_payment_in_cents) }} down @endif</div>
                                <div class="preview-terms">Amount financed <b style="color:var(--ink);">{{ $money($selectedOffer->amount_in_cents, 2) }}</b></div>
                                @if ($selectedOffer->conditions)<div class="preview-cond">Conditions: {{ $selectedOffer->conditions }}</div>@endif
                            </div>
                        </div>
                        @if ($selectedOffer->isPublished() && ! $selectedOffer->changedSincePublished($deal))
                            <p class="note">The buyer sees these terms in My Garage. Published {{ $selectedOffer->published_at->diffForHumans() }}.</p>
                        @else
                            <p class="note">Only this offer will be shown to the buyer, and they're emailed when you publish it.</p>
                        @endif
                    </div>
                @endif

                @unless ($closed)
                    <details class="sect" @if ($editingFinancing || ($offers->isEmpty() && ! $deal->hasFinancingApproval())) open @endif>
                        <summary>{!! $svg('doc', 'ic') !!}<b>Enter an offer by hand</b><span>For a lender outside Dealertrack</span></summary>
                        <div class="sect-body">
                            <p class="note" style="margin:0 0 10px;">Type the approval in as the lender sent it. Saving publishes it to the buyer's My Garage and emails them the terms.</p>
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
                                <label>Conditions (optional)
                                    <input type="text" wire:model="financingConditions" maxlength="500" placeholder="e.g. Proof of income">
                                    @error('financingConditions') <span class="fin-err">{{ $message }}</span> @enderror
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
                        </div>
                    </details>
                @endunless
            @else
                <div class="card">
                    <div class="card-head"><h3>Cash purchase</h3></div>
                    <p class="note" style="margin:0;">Cash purchase, so there's no bank approval to enter. The buyer settles the balance with you directly.</p>
                </div>
            @endif

            {{-- Pricing, frozen at reserve --}}
            <details class="sect" open>
                <summary>{!! $svg('calc', 'ic') !!}<b>Pricing &amp; fees</b><span>{{ $money($vehicle->price_in_cents) }} advertised price</span></summary>
                <div class="sect-body">
                    @if (empty($feeBuckets['included']) && empty($feeBuckets['passThrough']))
                        <p class="empty-line">No fee schedule was frozen onto this reservation.</p>
                    @else
                        @if (! empty($feeBuckets['included']))
                            <div class="fee-group-label">Included in the advertised price</div>
                            @foreach ($feeBuckets['included'] as $includedFee)
                                <div class="fee-row"><span class="fee-label">{{ $includedFee['label'] ?? 'Fee' }}</span><span class="fee-amt">{{ $money($includedFee['amount_in_cents'] ?? 0) }}</span></div>
                            @endforeach
                        @endif
                        @if (! empty($feeBuckets['passThrough']))
                            <div class="fee-group-label">Added at delivery (at cost)</div>
                            @foreach ($feeBuckets['passThrough'] as $passThroughFee)
                                <div class="fee-row"><span class="fee-label">{{ $passThroughFee['label'] ?? 'Charge' }}</span><span class="fee-amt">{{ $money($passThroughFee['amount_in_cents'] ?? 0) }}</span></div>
                            @endforeach
                            <div class="fee-total"><span>Added at delivery</span><span>{{ $money($feeBuckets['passThroughTotalInCents']) }}</span></div>
                        @endif
                        <p class="note">Frozen at checkout. Included fees sit inside the advertised all-in price; only at-cost government charges are added at delivery, alongside HST.</p>
                    @endif
                </div>
            </details>

            @if ($this->hasCoverageInterest())
                <details class="sect">
                    <summary>{!! $svg('shield', 'ic') !!}<b>Protection &amp; add-ons</b><span>In the buyer's estimate · confirm pricing</span></summary>
                    <div class="sect-body">
                        @if (! empty($deal->protection_snapshot))
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
                                @if ($deal->warranty_plan)<span class="chip warranty">Warranty: {{ ucwords(str_replace(['_', '-'], ' ', $deal->warranty_plan)) }}</span>@endif
                                @foreach ($deal->extras_interest ?? [] as $coverageKey)<span class="chip">{{ ucwords(str_replace(['_', '-'], ' ', $coverageKey)) }}</span>@endforeach
                            </div>
                        @endif
                        <p class="note">The buyer added these at checkout, and their payment estimate included them at the prices shown. Nothing was charged for them; confirm availability and final pricing with the buyer.</p>
                    </div>
                </details>
            @endif

            @if ($deal->tradeIn)
                <details class="sect">
                    <summary>{!! $svg('swap', 'ic') !!}<b>Trade-in</b><span>{{ $deal->tradeIn->model_year }} {{ $deal->tradeIn->make }} {{ $deal->tradeIn->model }} · preliminary</span></summary>
                    <div class="sect-body">
                        <div class="kv">
                            <div class="row"><span class="k">Trade vehicle</span><span class="v">{{ $deal->tradeIn->model_year }} {{ $deal->tradeIn->make }} {{ $deal->tradeIn->model }}{{ $deal->tradeIn->trim ? ' ' . $deal->tradeIn->trim : '' }}</span></div>
                            <div class="row"><span class="k">Odometer</span><span class="v">{{ number_format($deal->tradeIn->kilometres) }} km</span></div>
                            @if ($deal->tradeIn->exterior_colour)<div class="row"><span class="k">Colour</span><span class="v">{{ ucfirst($deal->tradeIn->exterior_colour) }}</span></div>@endif
                            <div class="row"><span class="k">Accident history</span><span class="v">{{ ucwords(str_replace('_', ' ', $deal->tradeIn->accident_history ?? 'not stated')) }}</span></div>
                            <div class="row"><span class="k">Owners</span><span class="v">{{ $deal->tradeIn->owner_count ?? '—' }}</span></div>
                            <div class="row"><span class="k">Title</span><span class="v">{{ ucwords(str_replace('_', ' ', $deal->tradeIn->title_status ?? 'not stated')) }}</span></div>
                            <div class="row"><span class="k">Keys</span><span class="v">{{ $deal->tradeIn->key_count ?? '—' }}</span></div>
                            @if (($deal->tradeIn->lien_owing_in_cents ?? 0) > 0)<div class="row"><span class="k">Lien owing</span><span class="v">{{ $money($deal->tradeIn->lien_owing_in_cents) }}</span></div>@endif
                        </div>
                        @if ($deal->tradeIn->estimated_value_low_in_cents && $deal->tradeIn->estimated_value_high_in_cents)
                            <div class="estimate-line">
                                <span class="lbl">Preliminary estimate (non-binding)</span>
                                <span class="amt">{{ $money($deal->tradeIn->estimated_value_low_in_cents) }} – {{ $money($deal->tradeIn->estimated_value_high_in_cents) }}</span>
                            </div>
                        @endif
                        @if ($deal->tradeIn->customer_notes)<p class="note">Buyer note: {{ $deal->tradeIn->customer_notes }}</p>@endif
                        <p class="note tight">Self-reported by the buyer. The final offer is set by the dealership after inspection.</p>
                    </div>
                </details>
            @endif

            <details class="sect">
                <summary>{!! $svg('coins', 'ic') !!}<b>Reservation deposit</b><span>{{ $money($deal->deposit_in_cents) }} {{ $deal->deposit_status === 'refunded' ? 'refunded' : 'held' }}</span></summary>
                <div class="sect-body">
                    <p class="note" style="margin:0;">{{ $deal->deposit_status === 'refunded' ? 'The deposit was refunded to the buyer’s card.' : 'Held on the buyer’s card. It comes off the price when they buy, and is refundable until they take delivery.' }}</p>
                </div>
            </details>
        </div>
    @endif

    {{-- ───────────── DOCUMENTS ───────────── --}}
    @if ($tab === 'documents')
        <div class="card">
            <div class="card-head">
                <div><h3>Buyer documents</h3><div class="card-sub" style="margin:4px 0 0;">{{ $received }} of {{ $documentRows->count() }} received</div></div>
            </div>
            @if ($documentRows->isEmpty())
                <p class="empty-line">No documents on this reservation.</p>
            @else
                <table class="docs">
                    <thead><tr><th>Document</th><th>Status</th><th>Action</th></tr></thead>
                    <tbody>
                        @foreach ($documentRows as $row)
                            <tr wire:key="doc-{{ $row['id'] }}">
                                <td>
                                    <div class="doc-name">{!! $svg($docIcon($row['name'])) !!}<div>{{ $row['name'] }}<small>{{ $row['status'] }}@if ($row['uploadedLabel']) · uploaded {{ $row['uploadedLabel'] }}@endif</small></div></div>
                                </td>
                                <td>
                                    @switch($row['state'])
                                        @case('approved') <span class="tone good">{!! $svg('check') !!} Approved</span> @break
                                        @case('cleared') <span class="tone good">{!! $svg('check') !!} Verified</span> @break
                                        @case('uploaded') <span class="tone info">{!! $svg('eye') !!} Ready to review</span> @break
                                        @case('rejected') <span class="tone warn">{!! $svg('warn') !!} Awaiting re-upload</span> @break
                                        @case('later') <span class="tone muted">Asked for at Documents</span> @break
                                        @default <span class="tone warn">{!! $svg('clock') !!} Waiting on buyer</span>
                                    @endswitch
                                </td>
                                <td>
                                    <div class="doc-actions">
                                        @if ($row['state'] === 'uploaded' || $row['state'] === 'approved')
                                            <button type="button" class="mini-btn ghost" wire:click="downloadDocument({{ $row['id'] }})" wire:loading.attr="disabled" wire:target="downloadDocument({{ $row['id'] }})">Download</button>
                                        @endif
                                        @if ($row['state'] === 'uploaded')
                                            <button type="button" class="mini-btn approve" wire:click="approveDocument({{ $row['id'] }})" wire:loading.attr="disabled" wire:target="approveDocument({{ $row['id'] }})">Approve</button>
                                            <button type="button" class="mini-btn flag" wire:click="startRejection({{ $row['id'] }})">Request re-upload</button>
                                        @endif
                                        @if ($row['withdrawable'])
                                            <button type="button" class="mini-btn ghost" wire:click="withdrawRequest({{ $row['id'] }})" wire:confirm="Stop asking the buyer for {{ $row['name'] }}?">Withdraw</button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                            @if ($documentBeingRejected === $row['id'])
                                <tr class="reject-row" wire:key="reject-{{ $row['id'] }}">
                                    <td colspan="3">
                                        <div class="reject-box">
                                            <label for="reason-{{ $row['id'] }}">Reason for re-upload (optional — the buyer gets this by email)</label>
                                            <textarea id="reason-{{ $row['id'] }}" wire:model="rejectionReason" placeholder="e.g. The licence photo is cut off — please re-upload showing all four corners."></textarea>
                                            @error('rejectionReason') <div class="reject-err">{{ $message }}</div> @enderror
                                            <div class="reject-box-actions">
                                                <button type="button" class="mini-btn ghost" wire:click="cancelRejection">Cancel</button>
                                                <button type="button" class="mini-btn flag" wire:click="requestReupload({{ $row['id'] }})" wire:loading.attr="disabled" wire:target="requestReupload({{ $row['id'] }})">Send back to buyer</button>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            @endif

            @unless ($closed)
                <div class="ask">
                    <span class="ask-label">Ask for more:</span>
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
            @endunless
        </div>
    @endif

    {{-- ───────────── ACTIVITY ───────────── --}}
    @if ($tab === 'activity')
        <div class="card">
            <div class="card-head"><h3>Activity</h3><span class="ph-right">{{ $activityFeed->count() }}</span></div>
            <div class="act-list">
                @forelse ($activityFeed as $activity)
                    <div class="act-item" wire:key="act-{{ $activity->id }}">
                        <span class="act-dot {{ $activity->kind }}"></span>
                        <div>
                            <div class="act-body">{{ $activity->body }}</div>
                            <div class="act-meta">{{ $activity->author_name ?? 'TruCars' }} · {{ $activity->created_at->diffForHumans() }}</div>
                        </div>
                    </div>
                @empty
                    <p class="empty-line">No activity yet.</p>
                @endforelse
            </div>
        </div>
    @endif
</div>
