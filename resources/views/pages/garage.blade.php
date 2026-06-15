<?php

use App\Models\Deal;
use App\Models\DealDocument;
use Illuminate\Support\Collection;
use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\Auth;

new #[Layout('layouts.checkout')] class extends Component {
    public ?int $selectedDealId = null;

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
        // Once the dealer moves the deal past financing, the rate is locked.
        return $this->stageRank($deal) >= 2;
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
                'state'  => $rank >= 2 ? 'done' : 'current',
                'sub'    => $rank >= 2 ? 'Confirmed' : 'In review',
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
            'financing'                                => 'Financing in review',
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

        return 'Finance · ' . ($deal->term_months ?? 72) . ' mo';
    }

    public function estimatedBiweeklyFor(Deal $deal): float
    {
        // Same illustrative math as the checkout — the dealership's finance
        // office sets the real number; we never show a fabricated rate.
        $vehiclePriceDollars = $deal->vehicle->price_in_cents / 100;
        $downPaymentDollars = ($deal->down_payment_in_cents ?? 0) / 100;
        $taxedPrincipal = max(0, $vehiclePriceDollars - $downPaymentDollars) * 1.13;
        $biweeklyRate = 0.075 / 26;
        $periods = (($deal->term_months ?? 72) / 12) * 26;

        if ($periods <= 0) {
            return 0;
        }

        return $taxedPrincipal * $biweeklyRate / (1 - pow(1 + $biweeklyRate, -$periods));
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

    /**
     * Placeholder upload: marks the document received so the buyer watches the
     * checklist advance. Real file capture (Livewire WithFileUploads, a storage
     * disk, validation) is the next slice — wire it here once the disk is chosen.
     * Scoped to the buyer's own deals so no one can touch another buyer's docs.
     */
    public function uploadDocument(int $documentId): void
    {
        $document = DealDocument::whereKey($documentId)
            ->whereIn('deal_id', Auth::user()->deals()->pluck('id'))
            ->first();

        if ($document === null) {
            return;
        }

        $document->update([
            'is_done'     => true,
            'status'      => 'Received',
            'uploaded_at' => now(),
        ]);

        unset($this->garageDeals, $this->featuredDeal);
    }
}; ?>

@push('styles')
    <style>
        .g-top { border-bottom:1px solid var(--line); background:var(--card); position:sticky; top:0; z-index:20; }
        .g-top-inner { max-width:1080px; margin:0 auto; padding:0 26px; display:flex; align-items:center; gap:16px; height:64px; }
        .g-top .sep { color:var(--ink-3); font-weight:400; margin:0 2px; }
        .g-top .garage-word { font-size:18px; font-weight:800; letter-spacing:-.02em; }
        .g-account { margin-left:auto; display:flex; align-items:center; gap:14px; }
        .g-avatar { width:34px; height:34px; border-radius:50%; background:var(--hero-grad); color:#fff; display:grid; place-items:center; font-weight:700; font-size:13px; }
        .g-signout { font-size:13px; font-weight:600; color:var(--ink-2); background:none; border:none; cursor:pointer; }
        .g-signout:hover { color:var(--primary); }

        .g-wrap { max-width:1080px; margin:0 auto; padding:36px 26px 70px; }

        .g-hero { display:grid; grid-template-columns:1.1fr 1fr; gap:26px; align-items:stretch; margin-bottom:14px; }
        @media (max-width:880px){ .g-hero { grid-template-columns:1fr; } }
        .g-hero-car { border-radius:var(--radius); background:var(--hero-grad); position:relative; overflow:hidden; min-height:260px; display:flex; align-items:flex-end; padding:26px; box-shadow:var(--shadow-md); }
        .g-hero-car::before { content:""; position:absolute; inset:0; background:radial-gradient(420px 280px at 75% 15%, rgba(255,255,255,.22), transparent 60%); }
        .g-hero-car.has-photo { background-size:cover; background-position:center; }
        .g-hero-car.has-photo::before { background:linear-gradient(180deg, rgba(0,0,0,.04), rgba(0,0,0,.12)); }
        .g-hero-badge { position:absolute; top:22px; left:22px; z-index:2; display:inline-flex; align-items:center; gap:7px; background:rgba(255,255,255,.92); color:var(--ink); font-size:12px; font-weight:700; padding:6px 12px; border-radius:var(--radius-pill); }
        .g-hero-badge .dot { width:7px; height:7px; border-radius:50%; background:var(--good); }
        .g-hero-badge.cancelled .dot { background:var(--bad); }
        .g-hero-art { width:100%; position:relative; z-index:1; filter:drop-shadow(0 22px 26px rgba(0,0,0,.4)); display:flex; justify-content:center; }
        .g-hero-info { background:var(--card); border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow-sm); padding:26px; display:flex; flex-direction:column; }
        .g-welcome { font-size:13px; color:var(--ink-3); font-weight:600; }
        .g-veh-name { font-size:27px; font-weight:800; letter-spacing:-.02em; margin:4px 0 2px; }
        .g-veh-trim { font-size:14px; color:var(--ink-2); }
        .g-facts { display:grid; grid-template-columns:1fr 1fr; gap:1px; background:var(--line); border-radius:var(--radius-sm); overflow:hidden; margin-top:20px; }
        .g-fact { background:var(--card); padding:13px 15px; }
        .g-fact .k { font-size:11px; text-transform:uppercase; letter-spacing:.07em; color:var(--ink-3); }
        .g-fact .v { font-weight:600; font-size:14.5px; margin-top:2px; }
        .g-hero-pay { margin-top:auto; padding-top:18px; display:flex; align-items:baseline; justify-content:space-between; }
        .g-hero-pay .amt { font-family:var(--font-mono); font-weight:700; font-size:24px; letter-spacing:-.02em; }
        .g-hero-pay .amt .per { font-size:13px; color:var(--ink-3); font-weight:500; }

        .g-card { background:var(--card); border:1px solid var(--line); border-radius:var(--radius); padding:24px 26px; box-shadow:var(--shadow-sm); margin-bottom:22px; }
        .g-card-head { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; margin-bottom:18px; }
        .g-card-head h3 { margin:0; font-size:18px; font-weight:800; letter-spacing:-.01em; }
        .g-card-sub { font-size:13px; color:var(--ink-2); }
        .g-status { font-size:12px; font-weight:700; padding:5px 12px; border-radius:var(--radius-pill); white-space:nowrap; }
        .g-status.pending { background:rgba(255,138,61,.16); color:#C85A14; }
        .g-status.confirmed { background:var(--good-soft); color:#0E5A43; }
        .g-fin-pending { display:flex; gap:14px; align-items:center; }
        .g-fin-spinner { width:30px; height:30px; border-radius:50%; border:3px solid var(--line); border-top-color:var(--primary); animation:g-spin .9s linear infinite; flex-shrink:0; }
        @keyframes g-spin { to { transform:rotate(360deg); } }
        .g-fin-title { font-size:14px; font-weight:600; }
        .g-fin-sub { font-size:12.5px; color:var(--ink-2); margin-top:3px; }
        .g-fin-confirmed { display:flex; align-items:center; gap:10px; font-size:14px; color:var(--ink); font-weight:500; }
        .g-fin-confirmed svg { color:var(--good); flex-shrink:0; }
        .g-fin-foot { margin-top:14px; padding-top:14px; border-top:1px dashed var(--line); display:flex; align-items:center; gap:9px; font-size:12.5px; color:var(--ink-2); }
        .g-fin-foot svg { color:var(--ink-3); flex-shrink:0; }

        .g-track { display:flex; align-items:flex-start; }
        .g-t-step { flex:1; text-align:center; position:relative; }
        .g-t-step::before { content:""; position:absolute; top:15px; left:-50%; width:100%; height:3px; background:var(--line-strong); z-index:0; }
        .g-t-step:first-child::before { display:none; }
        .g-t-step.done::before, .g-t-step.current::before { background:var(--primary); }
        .g-t-dot { position:relative; z-index:1; width:32px; height:32px; border-radius:50%; margin:0 auto 9px; display:grid; place-items:center; background:var(--card); border:3px solid var(--line-strong); color:var(--ink-3); font-weight:700; font-size:12px; }
        .g-t-step.done .g-t-dot { background:var(--primary); border-color:var(--primary); color:#fff; }
        .g-t-step.current .g-t-dot { background:var(--card); border-color:var(--primary); color:var(--primary); box-shadow:0 0 0 5px var(--primary-soft); }
        .g-t-lbl { font-size:13px; font-weight:600; }
        .g-t-sub { font-size:11.5px; color:var(--ink-3); }

        .g-doc-row { display:flex; align-items:center; gap:14px; padding:15px 0; border-bottom:1px solid var(--line); }
        .g-doc-row:last-child { border-bottom:none; }
        .g-doc-check { width:28px; height:28px; border-radius:50%; display:grid; place-items:center; flex-shrink:0; }
        .g-doc-check.done { background:var(--good); color:#fff; }
        .g-doc-check.todo { border:2px dashed var(--line-strong); color:var(--ink-3); }
        .g-doc-name { font-weight:600; font-size:14.5px; }
        .g-doc-status { font-size:12.5px; color:var(--ink-3); margin-top:1px; }
        .g-doc-action { margin-left:auto; }
        .g-doc-done { font-size:13px; font-weight:600; color:var(--good); }
        .g-doc-upload { padding:7px 16px; font-size:13px; }

        .g-cancelled-note { display:flex; gap:12px; align-items:flex-start; padding:16px 18px; border:1px solid var(--line); border-radius:var(--radius-sm); background:var(--bg-2); font-size:13.5px; color:var(--ink-2); line-height:1.55; }
        .g-cancelled-note svg { flex-shrink:0; color:var(--ink-3); margin-top:1px; }

        .g-others .g-other-row { display:flex; align-items:center; gap:15px; padding:14px 0; border-bottom:1px solid var(--line); width:100%; background:none; border-left:none; border-right:none; border-top:none; cursor:pointer; text-align:left; }
        .g-others .g-other-row:last-child { border-bottom:none; }
        .g-other-thumb { width:62px; height:44px; border-radius:10px; background:var(--hero-grad); background-size:cover; background-position:center; flex-shrink:0; }
        .g-other-name { font-weight:700; font-size:14.5px; }
        .g-other-sub { font-size:12.5px; color:var(--ink-3); margin-top:2px; }
        .g-other-stage { margin-left:auto; font-size:12px; font-weight:700; padding:5px 12px; border-radius:var(--radius-pill); background:var(--bg-2); color:var(--ink-2); white-space:nowrap; }
        .g-other-row:hover .g-other-name { color:var(--primary); }

        .g-lifecycle { margin-top:40px; border:1px dashed var(--line-strong); border-radius:var(--radius); padding:24px; background:var(--bg-2); }
        .g-lifecycle h3 { margin:0 0 4px; font-size:17px; font-weight:700; }
        .g-lifecycle p { margin:0 0 18px; font-size:13.5px; color:var(--ink-2); }
        .g-lc-row { display:flex; gap:10px; overflow-x:auto; padding-bottom:4px; }
        .g-lc-item { flex:1; min-width:150px; border:1px solid var(--line); border-radius:var(--radius-sm); padding:14px 16px; background:var(--card); }
        .g-lc-item .yr { font-size:11.5px; font-weight:700; color:var(--primary); letter-spacing:.04em; text-transform:uppercase; }
        .g-lc-item .ev { font-size:14px; font-weight:600; margin-top:4px; }
        .g-lc-item .de { font-size:12px; color:var(--ink-3); margin-top:2px; }

        .g-foot { border-top:1px solid var(--line); margin-top:50px; padding:26px 0; }
        .g-foot-note { max-width:1080px; margin:0 auto; padding:0 26px; font-size:12px; color:var(--ink-3); line-height:1.6; }

        .g-empty { max-width:480px; margin:60px auto 0; text-align:center; }
        .g-empty-badge { width:94px; height:94px; border-radius:50%; background:var(--primary-soft); display:grid; place-items:center; margin:0 auto 22px; }
        .g-empty h1 { font-size:30px; font-weight:800; letter-spacing:-.025em; margin:0 0 10px; }
        .g-empty p { font-size:15px; color:var(--ink-2); line-height:1.6; margin:0 0 26px; }
    </style>
@endpush

<div x-data @garage-deal-changed.window="window.scrollTo({ top: 0, behavior: 'smooth' })">
    <header class="g-top">
        <div class="g-top-inner">
            <a href="/" class="brand"><span class="glyph">T</span> Trueleads</a>
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
                <div class="g-empty-badge">
                    <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="1.8"><path d="M5 17h14M6 17l1.5-5.5C8 9.5 9.5 8.5 11.5 8.5h1c2 0 3.5 1 4 3L18 17M7 17a2 2 0 1 0 0 .01M17 17a2 2 0 1 0 0 .01"/></svg>
                </div>
                <h1>Your garage is waiting</h1>
                <p>Reserve a car and it lands here, {{ Auth::user()->first_name }} — live deal status, your documents, and a direct line to the dealership, all in one place.</p>
                <a href="/" class="btn btn-primary btn-lg">Browse cars →</a>
            </div>

        @else

            <section class="g-hero">
                <div class="g-hero-car {{ $this->heroPhotoFor($this->featuredDeal) ? 'has-photo' : '' }}"
                     @if ($this->heroPhotoFor($this->featuredDeal)) style="background-image:url('{{ $this->heroPhotoFor($this->featuredDeal) }}');" @endif>
                    <span class="g-hero-badge {{ $this->featuredDeal->stage === 'cancelled' ? 'cancelled' : '' }}">
                        <span class="dot"></span> {{ $this->heroBadgeLabel($this->featuredDeal) }}
                    </span>
                    @unless ($this->heroPhotoFor($this->featuredDeal))
                        <div class="g-hero-art">
                            <svg viewBox="0 0 320 130" width="260" xmlns="http://www.w3.org/2000/svg" style="max-width:90%;">
                                <ellipse cx="160" cy="118" rx="128" ry="8" fill="rgba(0,0,0,.10)"/>
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
                        @if ($this->featuredDeal->purchase_type === 'finance')
                            <span class="muted" style="font-size:13px;">{{ $this->financingConfirmed($this->featuredDeal) ? 'Payment' : 'Estimated payment' }}</span>
                            <span class="amt">{{ $this->financingConfirmed($this->featuredDeal) ? '' : 'Est. ' }}{{ $this->asMoney($this->estimatedBiweeklyFor($this->featuredDeal)) }}<span class="per"> /biweekly</span></span>
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
                            This reservation was cancelled. Your $150 deposit is fully refundable — {{ ucwords(strtolower($this->featuredDeal->vehicle->dealer->name)) }} will confirm your refund. Reference <b>{{ $this->featuredDeal->reference }}</b>.
                        </div>
                    </div>
                </section>

            @else

                <section class="g-card">
                    <div class="g-card-head">
                        <div>
                            <h3>Financing</h3>
                            <span class="g-card-sub">
                                {{ $this->financingConfirmed($this->featuredDeal) ? 'Confirmed by the dealership' : 'With the dealership\'s finance office' }}
                            </span>
                        </div>
                        <span class="g-status {{ $this->financingConfirmed($this->featuredDeal) ? 'confirmed' : 'pending' }}">
                            {{ $this->financingConfirmed($this->featuredDeal) ? 'Confirmed' : 'Pending' }}
                        </span>
                    </div>

                    @if ($this->financingConfirmed($this->featuredDeal))
                        <div class="g-fin-confirmed">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="9"/><path d="m8 12 2.5 2.5L16 9"/></svg>
                            {{ ucwords(strtolower($this->featuredDeal->vehicle->dealer->name)) }}'s finance office has confirmed your rate, term and payment — the final numbers are in your purchase agreement.
                        </div>
                    @else
                        <div class="g-fin-pending">
                            <div class="g-fin-spinner"></div>
                            <div>
                                <div class="g-fin-title">Your deal is with <b>{{ ucwords(strtolower($this->featuredDeal->vehicle->dealer->name)) }}</b>'s finance office</div>
                                <div class="g-fin-sub">They'll confirm your rate, term and payment. The payment above is an estimate until then.</div>
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
                            <span class="g-card-sub">A few items to finalize your financing — the sooner they're in, the sooner the dealership locks your rate.</span>
                        </div>
                        <span class="g-status {{ $this->documentsDone($this->featuredDeal) === $this->featuredDeal->documents->count() ? 'confirmed' : 'pending' }}">
                            {{ $this->documentsDone($this->featuredDeal) }} of {{ $this->featuredDeal->documents->count() }} done
                        </span>
                    </div>

                    <div class="g-docs">
                        @foreach ($this->featuredDeal->documents as $document)
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
                                    <div class="g-doc-status">{{ $document->status }}</div>
                                </div>
                                <div class="g-doc-action">
                                    @if ($document->is_done)
                                        <span class="g-doc-done">Done</span>
                                    @else
                                        <button type="button" class="btn btn-primary g-doc-upload" wire:click="uploadDocument({{ $document->id }})">Upload</button>
                                    @endif
                                </div>
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
                                <span class="g-other-sub" style="display:block;">{{ $otherDeal->reference }} · {{ ucwords(strtolower($otherDeal->vehicle->dealer->name)) }}</span>
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
                    <div class="g-lc-item"><div class="yr">Year 5</div><div class="ev">Sell it for you</div><div class="de">List or sell to Trueleads</div></div>
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
