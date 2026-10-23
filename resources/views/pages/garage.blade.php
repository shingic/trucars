<?php

use App\Models\Deal;
use App\Models\DealDocument;
use Illuminate\Support\Collection;
use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\WithFileUploads;

new #[Layout('layouts.checkout')] class extends Component {
    use WithFileUploads;

    public ?int $selectedDealId = null;

    /**
     * Per-document temporary uploads, keyed by deal_document id. Livewire holds
     * the browser's file here as a TemporaryUploadedFile until we validate it and
     * move it onto the private disk; keying by id lets each outstanding row drive
     * its own file input independently.
     *
     * @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile>
     */
    public array $pendingFiles = [];

    /**
     * How far along each stage sits on the garage's four-milestone track:
     * Reserved → Financing → Documents → Delivery. The early CRM stages
     * (contacted, appointment set) are dealer workflow — to the buyer they
     * all read as "the dealership is working on your financing".
     */
    protected const STAGE_RANK = [
        'reserved'           => 0,
        'contacted'          => 0,
        'appointment_set'    => 0,
        'financing'          => 1,
        'documents'          => 2,
        'ready_for_delivery' => 3,
        'delivered'          => 4,
    ];

    public function mount(): void
    {
        if (! Auth::check()) {
            session()->put('url.intended', request()->fullUrl());
            $this->redirectRoute('buyer.login');
        }
    }

    /* ---------------------------------------------------------------------
       Data

       My Garage is a consumer surface: a buyer's vehicles span every
       dealership they've ever bought from, so the DealerScope comes off
       explicitly. Ownership is enforced by user_id instead.
       --------------------------------------------------------------------- */
    #[Computed]
    public function garageDeals(): Collection
    {
        // User::deals() already drops the DealerScope — a garage spans every
        // dealership the buyer has reserved from. Ownership is the user_id filter
        // baked into the relation.
        return Auth::user()->deals()
            ->with([
                'vehicle' => fn ($vehicleQuery) => $vehicleQuery->withoutGlobalScopes()->with('dealer'),
                'documents',
            ])
            ->orderByRaw("stage = 'cancelled' asc")
            ->latest()
            ->get();
    }

    #[Computed]
    public function featuredDeal(): ?Deal
    {
        if ($this->selectedDealId !== null) {
            $selectedDeal = $this->garageDeals->firstWhere('id', $this->selectedDealId);

            if ($selectedDeal !== null) {
                return $selectedDeal;
            }
        }

        return $this->garageDeals->first();
    }

    public function showDeal(int $dealId): void
    {
        $this->selectedDealId = $dealId;
        $this->dispatch('garage-deal-changed');
    }

    public function signOut(): void
    {
        Auth::logout();
        session()->invalidate();
        session()->regenerateToken();
        $this->redirect('/');
    }

    /* ---------------------------------------------------------------------
       Per-deal display helpers
       --------------------------------------------------------------------- */
    public function stageRank(Deal $deal): int
    {
        return self::STAGE_RANK[$deal->stage] ?? 0;
    }

    public function financingConfirmed(Deal $deal): bool
    {
        // Locked once the bank's approval is entered, or the dealer moves the deal past financing.
        return $deal->hasFinancingApproval() || $this->stageRank($deal) >= 2;
    }

    public function trackSteps(Deal $deal): array
    {
        $rank = $this->stageRank($deal);

        return [
            [
                'label'  => 'Reserved',
                'state'  => 'done',
                'sub'    => $deal->created_at->format('M j'),
            ],
            [
                'label'  => 'Financing',
                'state'  => $this->financingConfirmed($deal) ? 'done' : 'current',
                'sub'    => $deal->hasFinancingApproval() ? 'Approved' : ($rank >= 2 ? 'Confirmed' : 'In review'),
            ],
            [
                'label'  => 'Documents',
                'state'  => $rank >= 3 ? 'done' : ($rank === 2 ? 'current' : 'next'),
                'sub'    => $rank >= 3 ? 'Done' : ($rank === 2 ? 'In progress' : 'Next'),
            ],
            [
                'label'  => 'Delivery',
                'state'  => $rank >= 4 ? 'done' : ($rank === 3 ? 'current' : 'next'),
                'sub'    => $rank >= 4 ? 'Delivered' : ($rank === 3 ? 'Being prepped' : 'Next'),
            ],
        ];
    }

    public function heroBadgeLabel(Deal $deal): string
    {
        return match (true) {
            $deal->stage === 'cancelled' => 'Cancelled',
            $deal->stage === 'delivered' => 'Delivered',
            default                      => 'Reserved',
        };
    }

    /**
     * The Deal's STAGE_LABELS speak dealer-console CRM ("New reservation",
     * "Appointment set"). The buyer sees their deal in their own terms.
     */
    public function consumerStageLabel(Deal $deal): string
    {
        return match ($deal->stage) {
            'reserved', 'contacted', 'appointment_set' => 'Reserved',
            'financing'                                => $deal->hasFinancingApproval() ? 'Financing approved' : 'Financing in review',
            'documents'                                => 'Paperwork in progress',
            'ready_for_delivery'                       => 'Ready for delivery',
            'delivered'                                => 'Delivered',
            'cancelled'                                => 'Cancelled',
            default                                    => $deal->stage_label,
        };
    }

    public function maskedVin(Deal $deal): string
    {
        $vin = (string) $deal->vehicle->vin;

        if (strlen($vin) < 10) {
            return $vin;
        }

        return substr($vin, 0, 5) . '•••••' . substr($vin, -4);
    }

    public function financingFactLabel(Deal $deal): string
    {
        if ($deal->purchase_type === 'cash') {
            return 'Cash purchase';
        }

        if ($deal->hasFinancingApproval()) {
            return $deal->financing_lender . ' · ' . $deal->financing_term_months . ' mo';
        }

        return ($deal->purchase_type === 'lease' ? 'Lease' : 'Finance') . ' · ' . ($deal->term_months ?? 72) . ' mo';
    }

    public function estimatedBiweeklyFor(Deal $deal): float
    {
        // Same illustrative math as the checkout, including what the buyer
        // added there — the dealership's finance office sets the real number.
        return \App\Support\PaymentEstimate::biweekly(
            $deal->vehicle->price_in_cents / 100,
            ($deal->down_payment_in_cents ?? 0) / 100,
            $deal->term_months ?? 72,
            $deal->protection_snapshot ?? [],
        );
    }

    /**
     * The lender's approved terms as label => value rows for the Financing card,
     * exactly as the dealership entered them. Optional rows drop out when blank.
     *
     * @return array<string, string>
     */
    public function approvedTerms(Deal $deal): array
    {
        $money = fn (int $cents) => '$' . number_format($cents / 100, 2);

        return array_filter([
            'Lender'          => $deal->financing_lender,
            'Payment'         => $money($deal->financing_payment_in_cents) . ' ' . strtolower($deal->financing_frequency_label),
            'Rate'            => rtrim(rtrim(number_format((float) $deal->financing_apr, 2), '0'), '.') . '% APR',
            'Term'            => $deal->financing_term_months . ' months' . ($deal->financing_payment_count ? ' · ' . $deal->financing_payment_count . ' payments' : ''),
            'Amount financed' => $money($deal->financing_amount_in_cents),
            'Down payment'    => $deal->financing_down_payment_in_cents !== null ? $money($deal->financing_down_payment_in_cents) : null,
            'First payment'   => $deal->financing_first_payment_on?->format('M j, Y'),
        ]);
    }

    public function heroPhotoFor(Deal $deal): ?string
    {
        $photos = $deal->vehicle->photos;

        return is_array($photos) && count($photos) > 0 ? $photos[0] : null;
    }

    public function asMoney($amount): string
    {
        return '$' . number_format((float) $amount, 0);
    }

    /* ---------------------------------------------------------------------
       Documents
       --------------------------------------------------------------------- */
    public function documentsDone(Deal $deal): int
    {
        return $deal->documents->where('is_done', true)->count();
    }

    /** Documents the buyer has been asked for so far; insurance joins once the deal is finalized. */
    public function documentsAsked(Deal $deal): int
    {
        return $deal->documents->filter(fn (DealDocument $document) => $document->isRequested() || $document->is_done)->count();
    }

    /**
     * The status line shown under each document. Buyer-uploaded items carry an
     * uploaded_at, so we append a friendly relative time ("Uploaded · 2 minutes
     * ago"); the auto-verified licence and any still-pending items just show
     * their stored status copy unchanged.
     */
    public function documentStatusLine(DealDocument $document): string
    {
        if ($document->uploaded_at !== null) {
            return $document->status . ' · ' . $document->uploaded_at->diffForHumans();
        }

        return $document->status;
    }

    /**
     * Livewire fires this the moment a file finishes its temporary upload. Every
     * outstanding document binds its input to pendingFiles.{documentId}, so we
     * recover the id from the property name and hand off to the store routine —
     * that's what lets each row upload on its own without a shared submit button.
     */
    public function updated(string $propertyName): void
    {
        if (! str_starts_with($propertyName, 'pendingFiles.')) {
            return;
        }

        $documentId = (int) str_replace('pendingFiles.', '', $propertyName);

        $this->storeDocument($documentId);
    }

    /**
     * Validate one freshly-uploaded file against the KYC constraints, move it
     * onto the private deal_documents disk, and flip the checklist row to done.
     * A re-upload replaces the previous file in place. Everything is fenced to
     * the buyer's own deals, and a DealActivity is written so the dealer sees
     * the document land in their feed.
     */
    public function storeDocument(int $documentId): void
    {
        $uploadedFile = $this->pendingFiles[$documentId] ?? null;

        if ($uploadedFile === null) {
            return;
        }

        $this->validate([
            "pendingFiles.$documentId" => [
                'required',
                'file',
                'mimetypes:application/pdf,image/jpeg,image/png,image/heic,image/webp',
                'extensions:pdf,jpg,jpeg,png,heic,webp',
                'max:10240',
            ],
        ], [
            "pendingFiles.$documentId.mimetypes"  => 'That file type isn\'t accepted — please upload a PDF or a photo.',
            "pendingFiles.$documentId.extensions" => 'That file type isn\'t accepted — please upload a PDF or a photo.',
            "pendingFiles.$documentId.max"        => 'That file is over 10 MB — please upload a smaller copy.',
        ]);

        // Ownership fence: the row must belong to one of this buyer's own deals.
        // The deal itself is read from the already-loaded garage collection,
        // which has the DealerScope dropped, so there are no scoping surprises.
        $document = DealDocument::query()
            ->whereKey($documentId)
            ->whereIn('deal_id', Auth::user()->deals()->pluck('id'))
            ->whereNotNull('requested_at')
            ->first();

        if ($document === null) {
            unset($this->pendingFiles[$documentId]);

            return;
        }

        $owningDeal = $this->garageDeals->firstWhere('id', $document->deal_id);
        $previousFilePath = $document->file_path;

        // Land the file in a per-deal folder on the private disk. store() returns
        // the path relative to the disk root, which is exactly what we persist.
        $storedFilePath = $uploadedFile->store('deal-' . $document->deal_id, 'deal_documents');

        $document->update([
            'is_done'     => true,
            'status'      => 'Uploaded',
            'file_path'   => $storedFilePath,
            'uploaded_at' => now(),
        ]);

        // On a replace, drop the file the buyer just superseded.
        if ($previousFilePath !== null && $previousFilePath !== $storedFilePath) {
            Storage::disk('deal_documents')->delete($previousFilePath);
        }

        if ($owningDeal !== null) {
            $owningDeal->recordActivity('document', "Buyer uploaded {$document->name}", $owningDeal->customer_full_name, 'in');
        }

        unset($this->pendingFiles[$documentId]);
        unset($this->garageDeals, $this->featuredDeal);
    }
}; ?>

@push('styles')
    <style>
        .g-top { border-bottom:1px solid var(--line-strong); background:var(--card); position:sticky; top:0; z-index:20; }
        .g-top-inner { max-width:1080px; margin:0 auto; padding:0 26px; display:flex; align-items:center; gap:14px; height:64px; }
        .g-top .sep { color:var(--ink-3); font-weight:400; margin:0 2px; }
        .g-top .garage-word { font-family:var(--font-display); font-size:20px; font-weight:600; text-transform:uppercase; line-height:1; color:var(--ink-2); white-space:nowrap; }
        .g-account { margin-left:auto; display:flex; align-items:center; gap:14px; }
        .g-avatar { width:34px; height:34px; border-radius:3px; background:var(--ink); color:#fff; display:grid; place-items:center; font-family:var(--font-display); font-weight:700; font-size:15px; }
        .g-signout { font-size:14px; font-weight:600; color:var(--ink-2); background:none; border:none; cursor:pointer; text-decoration:underline; text-underline-offset:3px; }
        .g-signout:hover { color:var(--ink); }

        .g-wrap { max-width:1080px; margin:0 auto; padding:36px 26px 70px; }

        /* the car up top: a trimmed photo beside the plain facts */
        .g-hero { display:grid; grid-template-columns:1.1fr 1fr; gap:32px; align-items:start; margin-bottom:44px; }
        @media (max-width:880px){ .g-hero { grid-template-columns:1fr; gap:22px; } }
        .g-hero-car { border-radius:3px; background:var(--hero-grad); position:relative; overflow:hidden; aspect-ratio:4/2.8; display:flex; align-items:flex-end; justify-content:center; padding:26px; }
        /* dealer photos are 4:3 with a vendor badge along the bottom edge; the 4:2.8 frame, pinned to the top, trims just that strip */
        .g-hero-car.has-photo { background-color:var(--bg-2); background-size:cover; background-position:center top; background-repeat:no-repeat; }
        .g-hero-badge { position:absolute; top:0; left:0; z-index:2; display:inline-flex; align-items:center; gap:6px; background:var(--ink); color:#fff; font-family:var(--font-display); font-size:14px; font-weight:600; text-transform:uppercase; line-height:1.2; padding:5px 11px; }
        .g-hero-badge.cancelled { background:#FFEEF3; color:#D81A48; }
        .g-hero-art { width:100%; position:relative; z-index:1; display:flex; justify-content:center; }
        .g-hero-info { border-top:2px solid var(--ink); padding-top:16px; display:flex; flex-direction:column; min-width:0; }
        .g-welcome { font-size:15px; color:var(--ink-2); }
        .g-veh-name { font-family:var(--font-display); font-size:38px; font-weight:700; text-transform:uppercase; line-height:1; letter-spacing:0; margin:8px 0 6px; }
        .g-veh-trim { font-size:15px; color:var(--ink-2); }
        .g-facts { display:grid; grid-template-columns:1fr 1fr; border-top:1px solid var(--line-strong); margin-top:20px; }
        .g-fact { padding:11px 0; border-bottom:1px solid var(--line-strong); min-width:0; }
        .g-fact:nth-child(even) { padding-left:16px; border-left:1px solid var(--line-strong); }
        .g-fact:nth-child(odd) { padding-right:16px; }
        .g-fact .k { font-size:13px; color:var(--ink-3); }
        .g-fact .v { font-weight:600; font-size:15px; margin-top:2px; overflow-wrap:anywhere; }
        .g-hero-pay { margin-top:auto; padding-top:18px; display:flex; align-items:baseline; justify-content:space-between; gap:12px; flex-wrap:wrap; }
        .g-hero-pay .amt { font-family:var(--font-display); font-weight:700; font-size:32px; line-height:1; color:var(--ink); letter-spacing:0; }
        .g-hero-pay .amt .per { font-family:var(--font-ui); font-size:13px; color:var(--ink-3); font-weight:500; }

        /* each part of the deal is a ruled section, not a card */
        .g-card { border-top:2px solid var(--ink); padding-top:16px; margin-bottom:44px; }
        .g-card-head { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; margin-bottom:16px; }
        .g-card-head h3 { margin:0; font-family:var(--font-display); font-size:24px; font-weight:700; text-transform:uppercase; line-height:1; letter-spacing:0; }
        .g-card-sub { display:block; font-size:14px; color:var(--ink-2); margin-top:6px; }
        .g-status { font-family:var(--font-display); font-size:14px; font-weight:600; text-transform:uppercase; line-height:1.2; padding:4px 10px; border-radius:2px; white-space:nowrap; }
        .g-status.pending { background:#E6F1FF; color:var(--primary-press); }
        .g-status.confirmed { background:#E3F7EA; color:#0B7355; }
        .g-fin-pending { display:flex; gap:14px; align-items:center; }
        .g-fin-spinner { width:26px; height:26px; border-radius:50%; border:3px solid var(--line-strong); border-top-color:var(--primary); animation:g-spin .9s linear infinite; flex-shrink:0; }
        @keyframes g-spin { to { transform:rotate(360deg); } }
        .g-fin-title { font-size:15px; font-weight:600; }
        .g-fin-sub { font-size:14px; color:var(--ink-2); margin-top:3px; }
        .g-fin-confirmed { display:flex; align-items:flex-start; gap:10px; font-size:15px; color:var(--ink); font-weight:500; }
        .g-fin-confirmed svg { color:#0B7355; flex-shrink:0; margin-top:1px; }
        /* approved terms: a ruled strip, a label over each figure */
        .g-terms { display:flex; flex-wrap:wrap; gap:1px; background:var(--line-strong); border-top:1px solid var(--line-strong); border-bottom:1px solid var(--line-strong); margin:0; }
        .g-terms > div { flex:1 1 200px; background:var(--card); padding:12px 16px 12px 0; min-width:0; }
        .g-terms > div + div { padding-left:16px; }
        .g-terms dt { font-size:13px; color:var(--ink-3); }
        .g-terms dd { margin:4px 0 0; font-family:var(--font-display); font-weight:700; font-size:22px; line-height:1.1; color:var(--ink); overflow-wrap:anywhere; }
        .g-terms .lead dd { font-size:28px; color:var(--primary-press); }
        .g-terms-note { font-size:14px; color:var(--ink-2); margin:12px 0 0; line-height:1.5; }
        @media (max-width:640px) { .g-terms > div { flex-basis:140px; } }
        .g-fin-foot { margin-top:16px; padding-top:14px; border-top:1px solid var(--line-strong); display:flex; align-items:center; flex-wrap:wrap; gap:4px 9px; font-size:14px; color:var(--ink-2); }
        .g-fin-foot svg { color:var(--ink-3); flex-shrink:0; }

        /* deal progress: a flat ruled list of steps */
        .g-track { display:flex; flex-direction:column; border-top:1px solid var(--line-strong); }
        .g-t-step { display:grid; grid-template-columns:36px minmax(0, 1fr) auto; align-items:baseline; gap:12px; padding:12px 0; border-bottom:1px solid var(--line-strong); }
        .g-t-dot { font-family:var(--font-display); font-size:20px; font-weight:700; line-height:1; color:var(--ink-3); }
        .g-t-step.done .g-t-dot { color:#0B7355; }
        .g-t-step.current { border-left:3px solid var(--primary); padding-left:11px; }
        .g-t-step.current .g-t-dot { color:var(--primary); }
        .g-t-lbl { font-size:15.5px; font-weight:600; color:var(--ink-3); }
        .g-t-step.done .g-t-lbl { color:var(--ink); }
        .g-t-step.current .g-t-lbl { color:var(--ink); font-weight:700; }
        .g-t-sub { font-size:13.5px; color:var(--ink-3); text-align:right; }

        .g-docs { border-top:1px solid var(--line-strong); }
        .g-doc { padding:14px 0; border-bottom:1px solid var(--line-strong); }
        .g-doc-row { display:flex; align-items:center; gap:14px; }
        .g-doc-check { width:28px; display:grid; place-items:center; flex-shrink:0; }
        .g-doc-check.done { color:#0B7355; }
        .g-doc-check.todo { color:var(--ink-3); }
        .g-doc-meta { min-width:0; }
        .g-doc-name { font-weight:600; font-size:15px; }
        .g-doc-status { font-size:13.5px; color:var(--ink-3); margin-top:1px; }
        .g-doc-action { margin-left:auto; flex-shrink:0; }
        .g-doc-done { font-size:14px; font-weight:600; color:#0B7355; }
        .g-doc-later { font-size:14px; font-weight:600; color:var(--ink-3); }
        .g-doc-upload { padding:8px 16px; font-size:14px; border-radius:3px; }
        .g-doc-input { display:none; }
        .g-doc-done-wrap { display:flex; align-items:center; gap:14px; }
        .g-doc-replace { font-size:13.5px; font-weight:600; color:var(--ink-2); cursor:pointer; text-decoration:underline; text-underline-offset:3px; }
        .g-doc-replace:hover { color:var(--ink); }
        .g-doc-error { font-size:13px; font-weight:600; color:#D81A48; margin:8px 0 0 42px; }

        .g-cancelled-note { display:flex; gap:12px; align-items:flex-start; padding:16px 18px; border:1px solid var(--line-strong); border-radius:3px; background:var(--card); font-size:15px; color:var(--ink-2); line-height:1.55; }
        .g-cancelled-note svg { flex-shrink:0; color:var(--ink-3); margin-top:3px; }

        .g-others .g-other-row { display:flex; align-items:center; gap:15px; padding:12px 0; border-bottom:1px solid var(--line-strong); width:100%; background:none; border-left:none; border-right:none; border-top:none; cursor:pointer; text-align:left; }
        .g-others .g-card-head + .g-other-row { border-top:1px solid var(--line-strong); }
        /* same trim as the hero: 4:2.8, pinned to the top, so the badge strip is cut */
        .g-other-thumb { width:72px; aspect-ratio:4/2.8; border-radius:2px; background-color:var(--hero-grad); background-size:cover; background-position:center top; background-repeat:no-repeat; flex-shrink:0; }
        .g-other-name { font-weight:700; font-size:15px; }
        .g-other-sub { font-size:13.5px; color:var(--ink-3); margin-top:2px; }
        .g-other-stage { margin-left:auto; font-family:var(--font-display); font-size:14px; font-weight:600; text-transform:uppercase; line-height:1.2; padding:4px 10px; border-radius:2px; background:var(--bg-2); border:1px solid var(--line-strong); color:var(--ink-2); white-space:nowrap; }
        .g-other-row:hover .g-other-name { color:var(--primary-press); }

        .g-lifecycle { margin-top:12px; border-top:2px solid var(--ink); padding-top:16px; }
        .g-lifecycle h3 { margin:0 0 8px; font-family:var(--font-display); font-size:24px; font-weight:700; text-transform:uppercase; line-height:1; letter-spacing:0; }
        .g-lifecycle p { margin:0 0 16px; font-size:15px; color:var(--ink-2); }
        .g-lc-row { display:flex; overflow-x:auto; border-top:1px solid var(--line-strong); border-bottom:1px solid var(--line-strong); }
        .g-lc-item { flex:1; min-width:150px; padding:14px 16px; }
        .g-lc-item:first-child { padding-left:0; }
        .g-lc-item + .g-lc-item { border-left:1px solid var(--line-strong); }
        .g-lc-item .yr { font-family:var(--font-display); font-size:15px; font-weight:600; color:var(--primary-press); text-transform:uppercase; }
        .g-lc-item .ev { font-size:15px; font-weight:600; margin-top:4px; }
        .g-lc-item .de { font-size:13px; color:var(--ink-3); margin-top:2px; }

        .g-foot { border-top:1px solid var(--line-strong); margin-top:50px; padding:26px 0; }
        .g-foot-note { max-width:1080px; margin:0 auto; padding:0 26px; font-size:13px; color:var(--ink-3); line-height:1.6; }

        .g-empty { max-width:620px; margin:40px 0 0; padding:28px 28px 30px; border:1.5px dashed var(--line-strong); border-radius:3px; }
        .g-empty h1 { font-family:var(--font-display); font-size:40px; font-weight:700; text-transform:uppercase; line-height:1; letter-spacing:0; margin:0 0 12px; }
        .g-empty p { font-size:16px; color:var(--ink-2); line-height:1.6; margin:0 0 24px; }
        .g-empty .btn { font-family:var(--font-display); font-size:18px; font-weight:600; text-transform:uppercase; border-radius:3px; }

        @media (max-width:560px) {
            .g-top-inner, .g-wrap, .g-foot-note { padding-left:16px; padding-right:16px; }
            .g-top .sep, .g-top .garage-word { display:none; }
            .g-veh-name { font-size:32px; }
            .g-empty { padding:22px 18px; }
            .g-empty h1 { font-size:32px; }
            .g-t-step { grid-template-columns:30px minmax(0, 1fr); }
            .g-t-sub { grid-column:2; text-align:left; }
            .g-doc-row { flex-wrap:wrap; }
            .g-doc-meta { flex:1; }
        }
    </style>
@endpush

<div x-data @garage-deal-changed.window="window.scrollTo({ top: 0, behavior: 'smooth' })">
    <header class="g-top">
        <div class="g-top-inner">
            <a href="/" class="brand"><span class="wordmark">Tru<b>Cars</b></span></a>
            <span class="sep">·</span>
            <span class="garage-word">My Garage</span>
            <div class="g-account">
                <button type="button" class="g-signout" wire:click="signOut">Sign out</button>
                <span class="g-avatar">{{ Auth::user()->initials }}</span>
            </div>
        </div>
    </header>

    <main class="g-wrap">
        @if ($this->featuredDeal === null)

            <div class="g-empty">
                <h1>Your garage is waiting</h1>
                <p>Reserve a car and it lands here, {{ Auth::user()->first_name }} — live deal status, your documents, and a direct line to the dealership, all in one place.</p>
                <a href="{{ route('shop') }}" class="btn btn-primary btn-lg">Browse cars →</a>
            </div>

        @else

            <section class="g-hero">
                <div class="g-hero-car {{ $this->heroPhotoFor($this->featuredDeal) ? 'has-photo' : '' }}"
                     @if ($this->heroPhotoFor($this->featuredDeal)) style="background-image:url('{{ $this->heroPhotoFor($this->featuredDeal) }}');" @endif>
                    <span class="g-hero-badge {{ $this->featuredDeal->stage === 'cancelled' ? 'cancelled' : '' }}">
                        {{ $this->heroBadgeLabel($this->featuredDeal) }}
                    </span>
                    @unless ($this->heroPhotoFor($this->featuredDeal))
                        <div class="g-hero-art">
                            <svg viewBox="0 0 320 130" width="260" xmlns="http://www.w3.org/2000/svg" style="max-width:90%;">
                                <path d="M20 92 C20 78 34 74 50 72 L74 50 C82 40 96 34 116 34 L196 34 C220 34 236 42 250 58 L286 70 C300 74 304 80 304 92 L304 96 C304 100 300 102 296 102 L24 102 C20 102 20 98 20 96 Z" fill="#ffffff" fill-opacity="0.92"/>
                                <path d="M86 50 C92 42 102 38 116 38 L156 38 L156 64 L74 64 Z" fill="rgba(255,255,255,.30)"/>
                                <path d="M164 38 L194 38 C214 38 228 44 240 58 L240 64 L164 64 Z" fill="rgba(255,255,255,.30)"/>
                                <circle cx="92" cy="100" r="22" fill="#1a1a1a"/><circle cx="92" cy="100" r="10" fill="#888"/>
                                <circle cx="240" cy="100" r="22" fill="#1a1a1a"/><circle cx="240" cy="100" r="10" fill="#888"/>
                            </svg>
                        </div>
                    @endunless
                </div>
                <div class="g-hero-info">
                    <div class="g-welcome">Welcome to your garage, {{ Auth::user()->first_name }}</div>
                    <div class="g-veh-name">{{ $this->featuredDeal->vehicle->model_year }} {{ $this->featuredDeal->vehicle->make }} {{ $this->featuredDeal->vehicle->model }}</div>
                    <div class="g-veh-trim">
                        {{ collect([$this->featuredDeal->vehicle->trim, $this->featuredDeal->vehicle->colour, $this->featuredDeal->vehicle->displayKilometres])->filter()->implode(' · ') }}
                    </div>
                    <div class="g-facts">
                        <div class="g-fact"><div class="k">VIN</div><div class="v" style="font-family:var(--font-mono);">{{ $this->maskedVin($this->featuredDeal) }}</div></div>
                        <div class="g-fact"><div class="k">Status</div><div class="v">{{ $this->consumerStageLabel($this->featuredDeal) }}</div></div>
                        <div class="g-fact"><div class="k">Financing</div><div class="v">{{ $this->financingFactLabel($this->featuredDeal) }}</div></div>
                        <div class="g-fact"><div class="k">Province</div><div class="v">{{ $this->featuredDeal->province }}</div></div>
                    </div>
                    <div class="g-hero-pay">
                        @if ($this->featuredDeal->hasFinancingApproval())
                            <span class="muted" style="font-size:13px;">Your payment</span>
                            <span class="amt">${{ number_format($this->featuredDeal->financing_payment_in_cents / 100, 2) }}<span class="per"> {{ strtolower($this->featuredDeal->financing_frequency_label) }}</span></span>
                        @elseif ($this->featuredDeal->purchase_type === 'finance')
                            <span class="muted" style="font-size:13px;">Estimated payment</span>
                            <span class="amt">Est. {{ $this->asMoney($this->estimatedBiweeklyFor($this->featuredDeal)) }}<span class="per"> /biweekly</span></span>
                        @elseif ($this->featuredDeal->purchase_type === 'lease')
                            <span class="muted" style="font-size:13px;">Lease payment</span>
                            <span class="amt" style="font-size:18px;">Set by the dealership</span>
                        @else
                            <span class="muted" style="font-size:13px;">Pay in full</span>
                            <span class="amt">{{ $this->asMoney($this->featuredDeal->vehicle->price_in_cents / 100) }}</span>
                        @endif
                    </div>
                </div>
            </section>

            @if ($this->featuredDeal->stage === 'cancelled')

                <section class="g-card">
                    <div class="g-cancelled-note">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 16v-4M12 8h.01"/></svg>
                        <div>
                            @if ($this->featuredDeal->deposit_status === 'refunded')
                                This reservation was cancelled and your $150 deposit has been refunded to your card — it can take 5–10 business days to show. Reference <b>{{ $this->featuredDeal->reference }}</b>.
                            @else
                                This reservation was cancelled. Your $150 deposit is fully refundable — {{ ucwords(strtolower($this->featuredDeal->seller_name)) }} will confirm your refund. Reference <b>{{ $this->featuredDeal->reference }}</b>.
                            @endif
                        </div>
                    </div>
                </section>

            @else

                <section class="g-card">
                    <div class="g-card-head">
                        <div>
                            <h3>Financing</h3>
                            <span class="g-card-sub">
                                @if ($this->featuredDeal->purchase_type === 'cash')
                                    Paying in full
                                @elseif ($this->featuredDeal->hasFinancingApproval())
                                    Approved by {{ $this->featuredDeal->financing_lender }}
                                @else
                                    {{ $this->financingConfirmed($this->featuredDeal) ? 'Confirmed by the dealership' : 'With the dealership\'s finance office' }}
                                @endif
                            </span>
                        </div>
                        @if ($this->featuredDeal->hasFinancingApproval())
                            <span class="g-status confirmed">Approved</span>
                        @elseif ($this->featuredDeal->purchase_type !== 'cash')
                            <span class="g-status {{ $this->financingConfirmed($this->featuredDeal) ? 'confirmed' : 'pending' }}">
                                {{ $this->financingConfirmed($this->featuredDeal) ? 'Confirmed' : 'Pending' }}
                            </span>
                        @endif
                    </div>

                    @if ($this->featuredDeal->hasFinancingApproval())
                        <dl class="g-terms">
                            @foreach ($this->approvedTerms($this->featuredDeal) as $label => $value)
                                <div class="{{ $label === 'Payment' ? 'lead' : '' }}"><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
                            @endforeach
                        </dl>
                        <p class="g-terms-note">These are the terms the bank approved, as {{ ucwords(strtolower($this->featuredDeal->seller_name)) }} entered them. You'll review and sign the full agreement before anything is final.</p>
                    @elseif ($this->featuredDeal->purchase_type === 'cash')
                        <div class="g-fin-confirmed">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="9"/><path d="m8 12 2.5 2.5L16 9"/></svg>
                            No financing needed. You'll settle the balance directly with {{ ucwords(strtolower($this->featuredDeal->seller_name)) }}.
                        </div>
                    @elseif ($this->financingConfirmed($this->featuredDeal))
                        <div class="g-fin-confirmed">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="9"/><path d="m8 12 2.5 2.5L16 9"/></svg>
                            {{ ucwords(strtolower($this->featuredDeal->seller_name)) }}'s finance office has confirmed your rate, term and payment — the final numbers are in your purchase agreement.
                        </div>
                    @else
                        <div class="g-fin-pending">
                            <div class="g-fin-spinner"></div>
                            <div>
                                <div class="g-fin-title">Your deal is with <b>{{ ucwords(strtolower($this->featuredDeal->seller_name)) }}</b>'s finance office</div>
                                <div class="g-fin-sub">They'll send it to the bank. Once it's approved, your lender, rate and payment show up right here.</div>
                            </div>
                        </div>
                    @endif

                    <div class="g-fin-foot">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 8v5l3 2"/></svg>
                        $150 refundable deposit held — credited to your purchase price. Reference <b>&nbsp;{{ $this->featuredDeal->reference }}</b>
                    </div>
                </section>

                <section class="g-card">
                    <div class="g-card-head"><h3>Your deal</h3></div>
                    <div class="g-track">
                        @foreach ($this->trackSteps($this->featuredDeal) as $stepNumber => $trackStep)
                            <div class="g-t-step {{ $trackStep['state'] === 'next' ? '' : $trackStep['state'] }}">
                                <div class="g-t-dot">{{ $trackStep['state'] === 'done' ? '✓' : $stepNumber + 1 }}</div>
                                <div class="g-t-lbl">{{ $trackStep['label'] }}</div>
                                <div class="g-t-sub">{{ $trackStep['sub'] }}</div>
                            </div>
                        @endforeach
                    </div>
                </section>

                <section class="g-card">
                    <div class="g-card-head">
                        <div>
                            <h3>Documents</h3>
                            <span class="g-card-sub">A few items for the dealership. The sooner they're in, the sooner your car is ready.</span>
                        </div>
                        <span class="g-status {{ $this->documentsDone($this->featuredDeal) === $this->documentsAsked($this->featuredDeal) ? 'confirmed' : 'pending' }}">
                            {{ $this->documentsDone($this->featuredDeal) }} of {{ $this->documentsAsked($this->featuredDeal) }} done
                        </span>
                    </div>

                    <div class="g-docs">
                        @foreach ($this->featuredDeal->documents as $document)
                            <div class="g-doc" wire:key="doc-{{ $document->id }}">
                                <div class="g-doc-row">
                                    <span class="g-doc-check {{ $document->is_done ? 'done' : 'todo' }}">
                                        @if ($document->is_done)
                                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="m5 12 4.5 4.5L19 7"/></svg>
                                        @else
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 5v14M5 12h14"/></svg>
                                        @endif
                                    </span>
                                    <div class="g-doc-meta">
                                        <div class="g-doc-name">{{ $document->name }}</div>
                                        <div class="g-doc-status">{{ $this->documentStatusLine($document) }}</div>
                                    </div>
                                    <div class="g-doc-action">
                                        @if ($document->is_done)
                                            <div class="g-doc-done-wrap">
                                                <span class="g-doc-done">Done</span>
                                                @if (filled($document->file_path))
                                                    <label class="g-doc-replace">
                                                        <span wire:loading.remove wire:target="pendingFiles.{{ $document->id }}">Replace</span>
                                                        <span wire:loading wire:target="pendingFiles.{{ $document->id }}">Uploading…</span>
                                                        <input type="file" class="g-doc-input" wire:model="pendingFiles.{{ $document->id }}" accept=".pdf,.jpg,.jpeg,.png,.heic,.webp">
                                                    </label>
                                                @endif
                                            </div>
                                        @elseif (! $document->isRequested())
                                            <span class="g-doc-later">Later</span>
                                        @else
                                            <label class="btn btn-primary g-doc-upload">
                                                <span wire:loading.remove wire:target="pendingFiles.{{ $document->id }}">Upload</span>
                                                <span wire:loading wire:target="pendingFiles.{{ $document->id }}">Uploading…</span>
                                                <input type="file" class="g-doc-input" wire:model="pendingFiles.{{ $document->id }}" accept=".pdf,.jpg,.jpeg,.png,.heic,.webp">
                                            </label>
                                        @endif
                                    </div>
                                </div>
                                @error('pendingFiles.' . $document->id)
                                <div class="g-doc-error">{{ $message }}</div>
                                @enderror
                            </div>
                        @endforeach
                    </div>
                </section>

            @endif

            @if ($this->garageDeals->count() > 1)
                <section class="g-card g-others">
                    <div class="g-card-head"><h3>Also in your garage</h3></div>
                    @foreach ($this->garageDeals->reject(fn ($otherDeal) => $otherDeal->id === $this->featuredDeal->id) as $otherDeal)
                        <button type="button" class="g-other-row" wire:click="showDeal({{ $otherDeal->id }})">
                            <span class="g-other-thumb" @if ($this->heroPhotoFor($otherDeal)) style="background-image:url('{{ $this->heroPhotoFor($otherDeal) }}');" @endif></span>
                            <span>
                                <span class="g-other-name">{{ $otherDeal->vehicle->model_year }} {{ $otherDeal->vehicle->make }} {{ $otherDeal->vehicle->model }}</span>
                                <span class="g-other-sub" style="display:block;">{{ $otherDeal->reference }} · {{ ucwords(strtolower($otherDeal->seller_name)) }}</span>
                            </span>
                            <span class="g-other-stage">{{ $this->consumerStageLabel($otherDeal) }}</span>
                        </button>
                    @endforeach
                </section>
            @endif

            <section class="g-lifecycle">
                <h3>Your garage grows with you</h3>
                <p>The profile behind your reservation keeps working for you — quietly, for years.</p>
                <div class="g-lc-row">
                    <div class="g-lc-item"><div class="yr">Now</div><div class="ev">Insurance &amp; protection</div><div class="de">Get covered before delivery</div></div>
                    <div class="g-lc-item"><div class="yr">Year 2</div><div class="ev">Warranty renewal</div><div class="de">Reminders before it lapses</div></div>
                    <div class="g-lc-item"><div class="yr">Year 3</div><div class="ev">Trade-in offer</div><div class="de">A real number, unprompted</div></div>
                    <div class="g-lc-item"><div class="yr">Year 4</div><div class="ev">Upgrade picks</div><div class="de">Matched to your history</div></div>
                    <div class="g-lc-item"><div class="yr">Year 5</div><div class="ev">Sell it for you</div><div class="de">List or sell to TruCars</div></div>
                </div>
            </section>

        @endif
    </main>

    <footer class="g-foot">
        <div class="g-foot-note">
            My Garage is your ownership hub for as long as you own your vehicle. Payments shown before the dealership's finance office confirms your deal are estimates only — your final rate, term and payment come from the dealership.
        </div>
    </footer>
</div>
