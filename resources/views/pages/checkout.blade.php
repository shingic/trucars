<?php

use App\Models\Deal;
use App\Models\Vehicle;
use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\DB;

new #[Layout('layouts.checkout')] class extends Component {
    public Vehicle $vehicle;

    public int $stepIndex = 0;

    public array $steps = [
        ['key' => 'trade',   'label' => 'Trade'],
        ['key' => 'buyer',   'label' => 'About you'],
        ['key' => 'plan',    'label' => 'Plan'],
        ['key' => 'id',      'label' => 'ID check'],
        ['key' => 'review',  'label' => 'Review'],
        ['key' => 'reserve', 'label' => 'Reserve'],
        ['key' => 'done',    'label' => 'Done'],
    ];

    // ----- Trade-in (optional, self-reported; the dealer appraises it later) -----
    public ?bool $wantsTrade = null;
    public $tradeYear = '';
    public string $tradeMake = '';
    public string $tradeModel = '';
    public string $tradeTrim = '';
    public $tradeKilometres = '';
    public string $tradeCondition = 'good';
    public $tradeLienOwing = '';
    public string $tradeNotes = '';

    // ----- Buyer -----
    public string $firstName = '';
    public string $lastName = '';
    public string $email = '';
    public string $phone = '';
    public string $streetAddress = '';
    public string $city = '';
    public string $province = 'Ontario';
    public string $postalCode = '';

    // ----- Plan & payment -----
    public string $purchaseType = 'finance';
    public int $termMonths = 72;
    public $downPayment = 0;
    public ?string $warrantyPlan = null;

    public array $warrantyOptions = [
        'openroad' => ['name' => 'Open Road',        'blurb' => 'Core powertrain coverage for the essentials.', 'popular' => false],
        'safebet'  => ['name' => 'Safe Bet',         'blurb' => 'Longer term, comprehensive component cover.',   'popular' => true],
        'bumper'   => ['name' => 'Bumper-to-Bumper', 'blurb' => 'The widest cover the dealership offers.',       'popular' => false],
    ];

    public array $provinceOptions = ['Ontario', 'Alberta', 'British Columbia', 'Manitoba', 'Quebec', 'Saskatchewan'];

    // ----- Identity & reservation -----
    public bool $identityVerified = false;
    public ?string $dealReference = null;

    public function mount(Vehicle $vehicle): void
    {
        // Consumers can only reserve a car that's actually live on the marketplace.
        abort_unless($vehicle->is_published, 404);

        $this->vehicle = $vehicle;
    }

    /* ---------------------------------------------------------------------
       Navigation

       NOTE: stepKey / canAdvance / ctaLabel are deliberately PLAIN methods,
       not #[Computed]. goNext() reads the current key before mutating
       stepIndex; a memoized computed would cache the pre-increment value and
       leave the rendered content a step behind the stepper (the "click twice"
       bug). Plain methods recompute on every call, so a single click advances.
       --------------------------------------------------------------------- */
    public function stepKey(): string
    {
        return $this->steps[$this->stepIndex]['key'];
    }

    public function canAdvance(): bool
    {
        return match ($this->stepKey()) {
            'trade' => $this->wantsTrade !== null,
            'id'    => $this->identityVerified,
            default => true,
        };
    }

    public function ctaLabel(): string
    {
        return match ($this->stepKey()) {
            'review' => 'Looks good — reserve',
            default  => 'Continue',
        };
    }

    public function goNext(): void
    {
        $passed = match ($this->stepKey()) {
            'trade' => $this->passesTradeStep(),
            'buyer' => $this->passesBuyerStep(),
            'plan'  => $this->passesPlanStep(),
            'id'    => $this->identityVerified,
            default => true,
        };

        if ($passed && $this->stepIndex < count($this->steps) - 1) {
            $this->stepIndex++;
            $this->dispatch('checkout-step-changed');
        }
    }

    public function goBack(): void
    {
        if ($this->stepIndex > 0) {
            $this->stepIndex--;
            $this->dispatch('checkout-step-changed');
        }
    }

    public function jumpTo(int $index): void
    {
        // The stepper only lets you hop back to a step you've already cleared.
        if ($index >= 0 && $index < $this->stepIndex) {
            $this->stepIndex = $index;
            $this->dispatch('checkout-step-changed');
        }
    }

    /* ---------------------------------------------------------------------
       Step inputs
       --------------------------------------------------------------------- */
    public function setWantsTrade(bool $wants): void
    {
        $this->wantsTrade = $wants;
        $this->resetErrorBag();
    }

    public function setPurchaseType(string $type): void
    {
        if (in_array($type, ['finance', 'cash'], true)) {
            $this->purchaseType = $type;
        }
    }

    public function adjustTerm(int $direction): void
    {
        $proposedTerm = $this->termMonths + ($direction * 12);
        $this->termMonths = max(36, min(96, $proposedTerm));
    }

    public function selectWarranty(?string $planKey): void
    {
        // Tapping the selected tier again clears it back to no added coverage.
        $this->warrantyPlan = ($this->warrantyPlan === $planKey) ? null : $planKey;
    }

    public function verifyIdentity(): void
    {
        // Stub for the identity partner (Persona / Paays — vendor still to be chosen).
        $this->identityVerified = true;
    }

    /* ---------------------------------------------------------------------
       Validation per step
       --------------------------------------------------------------------- */
    protected function passesTradeStep(): bool
    {
        $this->validate(
            ['wantsTrade' => ['required', 'boolean']],
            ['wantsTrade.required' => 'Let us know whether you have a car to trade.'],
        );

        if ($this->wantsTrade === true) {
            $this->validate([
                'tradeYear'       => ['required', 'integer', 'min:1990', 'max:' . ((int) date('Y') + 1)],
                'tradeMake'       => ['required', 'string', 'max:60'],
                'tradeModel'      => ['required', 'string', 'max:60'],
                'tradeTrim'       => ['nullable', 'string', 'max:60'],
                'tradeKilometres' => ['required', 'integer', 'min:0', 'max:999999'],
                'tradeCondition'  => ['required', 'in:excellent,good,fair,poor'],
                'tradeLienOwing'  => ['nullable', 'numeric', 'min:0'],
                'tradeNotes'      => ['nullable', 'string', 'max:500'],
            ]);
        }

        return true;
    }

    protected function passesBuyerStep(): bool
    {
        $this->validate([
            'firstName'     => ['required', 'string', 'max:60'],
            'lastName'      => ['required', 'string', 'max:60'],
            'email'         => ['required', 'email', 'max:120'],
            'phone'         => ['required', 'string', 'max:30'],
            'streetAddress' => ['required', 'string', 'max:120'],
            'city'          => ['required', 'string', 'max:80'],
            'province'      => ['required', 'string', 'max:40'],
            'postalCode'    => ['required', 'regex:/^[A-Za-z]\d[A-Za-z][ ]?\d[A-Za-z]\d$/'],
        ], [
            'postalCode.regex' => 'Enter a valid Canadian postal code.',
        ]);

        return true;
    }

    protected function passesPlanStep(): bool
    {
        $this->validate([
            'purchaseType' => ['required', 'in:finance,cash'],
        ]);

        if ($this->purchaseType === 'finance') {
            $this->validate([
                'termMonths'  => ['required', 'integer', 'min:36', 'max:96'],
                'downPayment' => ['nullable', 'numeric', 'min:0', 'max:' . $this->priceDollars],
            ], [
                'downPayment.max' => 'Your down payment cannot be more than the price of the car.',
            ]);
        }

        return true;
    }

    /* ---------------------------------------------------------------------
       Live numbers (estimate-only — the dealer's F&I office confirms the real rate)
       --------------------------------------------------------------------- */
    #[Computed]
    public function priceDollars(): float
    {
        return $this->vehicle->price_in_cents / 100;
    }

    #[Computed]
    public function financedDollars(): float
    {
        return max(0, $this->priceDollars - (float) $this->downPayment);
    }

    #[Computed]
    public function estimatedBiweekly(): float
    {
        // Same shape as the Vehicle::estimatedBiweekly accessor, but responsive to the
        // term and down payment the buyer picks here. Illustrative only.
        $taxedPrincipal = $this->financedDollars * 1.13;
        $biweeklyRate = 0.075 / 26;
        $periods = ($this->termMonths / 12) * 26;

        if ($periods <= 0) {
            return 0;
        }

        return $taxedPrincipal * $biweeklyRate / (1 - pow(1 + $biweeklyRate, -$periods));
    }

    #[Computed]
    public function heroPhoto(): ?string
    {
        $photos = $this->vehicle->photos;

        return is_array($photos) && count($photos) > 0 ? $photos[0] : null;
    }

    public function asMoney($amount): string
    {
        return '$' . number_format((float) $amount, 0);
    }

    /* ---------------------------------------------------------------------
       Create the reservation
       --------------------------------------------------------------------- */
    public function reserve(): void
    {
        $newDeal = DB::transaction(function () {
            $deal = Deal::create([
                'dealer_id'             => $this->vehicle->dealer_id,
                'vehicle_id'            => $this->vehicle->id,
                'purchase_type'         => $this->purchaseType,
                'term_months'           => $this->purchaseType === 'finance' ? $this->termMonths : null,
                'down_payment_in_cents' => $this->purchaseType === 'finance' ? (int) round((float) $this->downPayment * 100) : null,
                'warranty_plan'         => $this->warrantyPlan,
                'deposit_in_cents'      => 15000,
                'deposit_status'        => 'held',
                'first_name'            => $this->firstName,
                'last_name'             => $this->lastName,
                'email'                 => $this->email,
                'phone'                 => $this->phone,
                'street_address'        => $this->streetAddress,
                'city'                  => $this->city,
                'province'              => $this->province,
                'postal_code'           => $this->postalCode,
                'identity_verified_at'  => now(),
            ]);

            if ($this->wantsTrade === true) {
                $deal->tradeIn()->create([
                    'model_year'          => (int) $this->tradeYear,
                    'make'                => $this->tradeMake,
                    'model'               => $this->tradeModel,
                    'trim'                => $this->tradeTrim !== '' ? $this->tradeTrim : null,
                    'kilometres'          => (int) $this->tradeKilometres,
                    'condition'           => $this->tradeCondition,
                    'lien_owing_in_cents' => (int) round((float) $this->tradeLienOwing * 100),
                    'customer_notes'      => $this->tradeNotes !== '' ? $this->tradeNotes : null,
                ]);
            }

            // The opening activity trail the dealer sees the moment this lands.
            $deal->recordActivity('system', 'Reservation created through TruCars checkout.');
            $deal->recordActivity('system', '$150 refundable deposit held — credited to the purchase price.');
            $deal->recordActivity('system', 'Identity verified.');
            $deal->recordActivity(
                'sms',
                'Hi ' . $this->firstName . ' — your ' . $this->vehicle->model_year . ' ' . $this->vehicle->make . ' ' . $this->vehicle->model
                . ' is reserved! Reference ' . $deal->reference . '. The dealership will reach out shortly to confirm financing and next steps. — Trueleads',
                null,
                'outbound',
            );

            return $deal;
        });

        $this->dealReference = $newDeal->reference;
        $this->stepIndex = count($this->steps) - 1;
        $this->dispatch('checkout-step-changed');
    }
}; ?>

@push('styles')
    <style>
        .field-error { color:var(--bad); font-size:12.5px; font-weight:600; margin:7px 0 0; }

        .trade-grid { display:grid; grid-template-columns:90px 1fr 1fr; gap:16px; }
        @media (max-width:560px){ .trade-grid { grid-template-columns:1fr; } }

        .money-input { display:flex; align-items:center; gap:8px; padding:0 16px; border:1.5px solid var(--line-strong); border-radius:var(--radius-sm); background:var(--card); transition:border-color .15s ease, box-shadow .15s ease; }
        .money-input:focus-within { border-color:var(--primary); box-shadow:0 0 0 4px var(--primary-soft); }
        .money-input input { flex:1; min-width:0; border:none; outline:none; background:none; padding:14px 0; font-size:15px; }
        .money-input .dollar { color:var(--ink-3); font-weight:600; }

        .finance-controls { display:flex; gap:18px; flex-wrap:wrap; align-items:flex-end; margin-bottom:4px; }
        .fc-cell { display:flex; flex-direction:column; gap:8px; }
        .fc-cell .field-label { margin:0; }
        .fc-grow { flex:1; min-width:180px; }

        .term-stepper { display:inline-flex; align-items:center; gap:6px; }
        .step-btn { width:42px; height:42px; border-radius:12px; background:var(--bg-2); border:1.5px solid var(--line-strong); font-size:20px; font-weight:700; color:var(--ink); display:grid; place-items:center; line-height:1; transition:all .15s ease; }
        .step-btn:hover { border-color:var(--primary); color:var(--primary); background:var(--primary-soft); }
        .step-val { min-width:74px; text-align:center; font-weight:700; font-size:16px; }

        .estimate-note { padding:20px 22px; margin-top:20px; }
        .en-title { font-size:15px; font-weight:700; margin-bottom:8px; }

        .plan-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:14px; }
        @media (max-width:680px){ .plan-grid { grid-template-columns:1fr; } }
        .plan { position:relative; text-align:left; display:flex; flex-direction:column; gap:8px; padding:22px 20px; border:1.5px solid var(--line-strong); border-radius:var(--radius); background:var(--card); box-shadow:var(--shadow-sm); cursor:pointer; transition:border-color .15s ease, transform .12s ease, background .15s ease; }
        .plan:hover { border-color:var(--primary); transform:translateY(-2px); }
        .plan.is-on { border-color:var(--primary); border-width:2px; background:var(--primary-soft); }
        .plan-badge { position:absolute; top:-11px; left:20px; font-size:11px; font-weight:700; color:#fff; background:var(--primary); padding:4px 11px; border-radius:var(--radius-pill); }
        .plan-name { font-size:16px; font-weight:700; }
        .plan-tag { font-size:13px; color:var(--ink-2); line-height:1.4; flex:1; }
        .plan-pick { font-size:13px; font-weight:700; color:var(--primary); }

        .id-card { max-width:460px; margin:8px auto 0; text-align:center; padding:34px 28px; border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow-md); background:var(--card); }
        .id-illus { width:84px; height:84px; border-radius:50%; background:var(--primary-soft); display:grid; place-items:center; margin:0 auto 18px; }

        .rev-card { display:flex; justify-content:space-between; align-items:center; gap:16px; padding:18px 22px; margin-bottom:13px; border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow-sm); background:var(--card); }
        .rev-label { font-size:12.5px; color:var(--ink-3); font-weight:600; text-transform:uppercase; letter-spacing:.05em; }
        .rev-value { font-weight:600; margin-top:3px; }

        .pay-card { max-width:520px; padding:24px; border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow-md); background:var(--card); }
        .reassure { max-width:520px; margin-top:18px; display:flex; flex-direction:column; gap:10px; }
        .reassure-line { display:flex; align-items:center; gap:10px; font-size:14px; color:var(--ink-2); }

        .done { max-width:620px; margin:0 auto; text-align:center; }
        .done-badge { width:94px; height:94px; border-radius:50%; background:var(--good-soft); display:grid; place-items:center; margin:10px auto 22px; }
        .done h1 { font-size:40px; font-weight:800; letter-spacing:-.03em; margin:0 0 12px; }
        .ref-chip { display:inline-block; font-family:var(--font-mono); font-size:14px; font-weight:600; background:var(--bg-2); border:1px solid var(--line); padding:9px 18px; border-radius:var(--radius-pill); margin:6px 0 26px; }
        .ref-chip b { color:var(--primary); }
        .timeline-next { text-align:left; max-width:460px; margin:0 auto; padding:22px 24px; border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow-sm); background:var(--card); }
        .tn-head { font-weight:700; margin-bottom:6px; }
        .tn-step { display:flex; gap:14px; padding:13px 0; border-bottom:1px solid var(--line); }
        .tn-step:last-child { border-bottom:none; }
        .tn-n { width:28px; height:28px; border-radius:50%; background:var(--primary-soft); color:var(--primary); font-weight:700; display:grid; place-items:center; flex-shrink:0; font-size:13px; }
        .tn-title { font-weight:600; }
        .tn-sub { font-size:13.5px; }
    </style>
@endpush

<div id="checkout-app" x-data @checkout-step-changed.window="window.scrollTo({ top: 0, behavior: 'smooth' })">
    <header class="top">
        <div class="top-inner">
            <a href="/" class="brand"><span class="glyph">T</span> Trueleads</a>
            <div class="veh-chip">
                <span class="thumb" @if ($this->heroPhoto) style="background-image:url('{{ $this->heroPhoto }}');" @endif></span>
                <span><span class="vt">{{ $vehicle->model_year }} {{ $vehicle->make }} {{ $vehicle->model }}</span> &nbsp;<span class="vp">{{ $this->asMoney($this->priceDollars) }}</span></span>
            </div>
        </div>
        <nav class="stepper">
            @foreach ($steps as $index => $step)
                <button type="button"
                        class="st-step {{ $index < $stepIndex ? 'done' : ($index === $stepIndex ? 'current' : '') }}"
                        @if ($index < $stepIndex) wire:click="jumpTo({{ $index }})" @endif>
                    <span class="st-node">
                        @if ($index < $stepIndex)
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="m5 12 4.5 4.5L19 7"/></svg>
                        @else
                            {{ $index + 1 }}
                        @endif
                    </span>
                    <span class="st-label">{{ $step['label'] }}</span>
                </button>
            @endforeach
        </nav>
    </header>

    <main class="layout {{ $this->stepKey() === 'done' ? 'layout-solo' : '' }}">
        <div class="content">
            @if ($this->stepKey() === 'trade')
                <div class="step-meta">Step 1 · Your trade</div>
                <h1 class="h1">Got a car to trade?</h1>
                <p class="lede">Add it now and the dealership rolls a firm appraisal into your deal — lowering your price and the tax you pay. No number is locked in until the dealer confirms it.</p>

                <button type="button" class="choice {{ $wantsTrade === true ? 'is-on' : '' }}" wire:click="setWantsTrade(true)">
                    <span class="radio"></span>
                    <div><div class="choice-title">Yes, I have a trade-in</div><div class="choice-sub">Tell us a few details — the dealership appraises it before delivery</div></div>
                </button>
                <button type="button" class="choice {{ $wantsTrade === false ? 'is-on' : '' }}" wire:click="setWantsTrade(false)">
                    <span class="radio"></span>
                    <div><div class="choice-title">No trade this time</div><div class="choice-sub">Skip ahead — you can always add one later</div></div>
                </button>
                @error('wantsTrade') <p class="field-error">{{ $message }}</p> @enderror

                @if ($wantsTrade === true)
                    <div class="section-label">About your trade-in</div>
                    <p class="section-note">Self-reported for now. The dealership confirms condition and final value before you take delivery.</p>

                    <div class="trade-grid">
                        <div class="field"><label class="field-label">Year</label><input type="number" class="field-input" wire:model="tradeYear" placeholder="2019">@error('tradeYear') <p class="field-error">{{ $message }}</p> @enderror</div>
                        <div class="field"><label class="field-label">Make</label><input type="text" class="field-input" wire:model="tradeMake" placeholder="Honda">@error('tradeMake') <p class="field-error">{{ $message }}</p> @enderror</div>
                        <div class="field"><label class="field-label">Model</label><input type="text" class="field-input" wire:model="tradeModel" placeholder="CR-V">@error('tradeModel') <p class="field-error">{{ $message }}</p> @enderror</div>
                    </div>

                    <div class="field-row">
                        <div class="field"><label class="field-label">Trim <span class="muted">(optional)</span></label><input type="text" class="field-input" wire:model="tradeTrim" placeholder="EX-L AWD"></div>
                        <div class="field"><label class="field-label">Odometer</label><div class="money-input"><input type="number" wire:model="tradeKilometres" placeholder="64200"><span class="dollar">km</span></div>@error('tradeKilometres') <p class="field-error">{{ $message }}</p> @enderror</div>
                    </div>

                    <div class="field">
                        <label class="field-label">Overall condition</label>
                        <div class="seg seg-wrap">
                            @foreach (['excellent' => 'Excellent', 'good' => 'Good', 'fair' => 'Fair', 'poor' => 'Poor'] as $value => $label)
                                <button type="button" class="seg-btn {{ $tradeCondition === $value ? 'is-active' : '' }}" wire:click="$set('tradeCondition', '{{ $value }}')">{{ $label }}</button>
                            @endforeach
                        </div>
                    </div>

                    <div class="field"><label class="field-label">Outstanding loan or lease balance <span class="muted">(optional)</span></label><div class="money-input"><span class="dollar">$</span><input type="number" wire:model="tradeLienOwing" placeholder="0"></div>@error('tradeLienOwing') <p class="field-error">{{ $message }}</p> @enderror</div>

                    <div class="field"><label class="field-label">Anything else the dealer should know? <span class="muted">(optional)</span></label><textarea class="field-input" rows="3" wire:model="tradeNotes" placeholder="New tires last spring, one small door ding…"></textarea>@error('tradeNotes') <p class="field-error">{{ $message }}</p> @enderror</div>
                @endif

            @elseif ($this->stepKey() === 'buyer')
                <div class="step-meta">Step 2 · About you</div>
                <h1 class="h1">Let's start with you</h1>
                <p class="lede">Use your name exactly as it reads on your licence — that's who the car gets registered to.</p>

                <div class="field-row">
                    <div class="field"><label class="field-label">First name</label><input type="text" class="field-input" wire:model="firstName">@error('firstName') <p class="field-error">{{ $message }}</p> @enderror</div>
                    <div class="field"><label class="field-label">Last name</label><input type="text" class="field-input" wire:model="lastName">@error('lastName') <p class="field-error">{{ $message }}</p> @enderror</div>
                </div>
                <div class="field"><label class="field-label">Email</label><input type="email" class="field-input" wire:model="email" placeholder="you@example.com">@error('email') <p class="field-error">{{ $message }}</p> @enderror</div>
                <div class="field"><label class="field-label">Mobile number</label><input type="text" class="field-input" wire:model="phone" placeholder="(647) 555-0123">@error('phone') <p class="field-error">{{ $message }}</p> @enderror</div>

                <div class="section-label">Where do you call home?</div>
                <p class="section-note">For registration and billing. Your delivery spot can differ.</p>
                <div class="field"><label class="field-label">Street address</label><input type="text" class="field-input" wire:model="streetAddress">@error('streetAddress') <p class="field-error">{{ $message }}</p> @enderror</div>
                <div class="field-row">
                    <div class="field"><label class="field-label">City</label><input type="text" class="field-input" wire:model="city">@error('city') <p class="field-error">{{ $message }}</p> @enderror</div>
                    <div class="field"><label class="field-label">Province</label><select class="field-select" wire:model="province">@foreach ($provinceOptions as $option)<option value="{{ $option }}">{{ $option }}</option>@endforeach</select>@error('province') <p class="field-error">{{ $message }}</p> @enderror</div>
                </div>
                <div class="field" style="max-width:240px;"><label class="field-label">Postal code</label><input type="text" class="field-input" wire:model="postalCode" placeholder="M5J 2T7">@error('postalCode') <p class="field-error">{{ $message }}</p> @enderror</div>

            @elseif ($this->stepKey() === 'plan')
                <div class="step-meta">Step 3 · Plan &amp; payment</div>
                <h1 class="h1">How would you like to pay?</h1>
                <p class="lede">Finance to own over time, or pay in full today. The payment shown is an estimate — the dealership's finance office confirms your real rate after you reserve.</p>

                <div class="seg" style="margin-bottom:24px;">
                    <button type="button" class="seg-btn {{ $purchaseType === 'finance' ? 'is-active' : '' }}" wire:click="setPurchaseType('finance')">Finance</button>
                    <button type="button" class="seg-btn {{ $purchaseType === 'cash' ? 'is-active' : '' }}" wire:click="setPurchaseType('cash')">Cash</button>
                </div>

                @if ($purchaseType === 'finance')
                    <div class="finance-controls">
                        <div class="fc-cell">
                            <span class="field-label">Term</span>
                            <div class="term-stepper">
                                <button type="button" class="step-btn" wire:click="adjustTerm(-1)" aria-label="Shorter term">−</button>
                                <span class="step-val">{{ $termMonths }} mo</span>
                                <button type="button" class="step-btn" wire:click="adjustTerm(1)" aria-label="Longer term">+</button>
                            </div>
                        </div>
                        <div class="fc-cell fc-grow">
                            <span class="field-label">Down payment</span>
                            <div class="money-input"><span class="dollar">$</span><input type="number" wire:model.blur="downPayment" placeholder="0"></div>
                        </div>
                    </div>
                    @error('downPayment') <p class="field-error">{{ $message }}</p> @enderror

                    <div class="card estimate-note">
                        <div class="en-title">This payment is an estimate</div>
                        <p class="section-note" style="margin:0;">When you reserve, your deal goes to the dealership's finance office — they confirm your actual rate, term and payment, and the final numbers reach you before delivery. No credit check to see this estimate, and no rate shopping.</p>
                    </div>
                @else
                    <div class="card estimate-note">
                        <div class="en-title">Paying in full</div>
                        <p class="section-note" style="margin:0;">{{ $this->asMoney($this->priceDollars) }} all-in, plus HST &amp; licensing. The dealership confirms the final out-the-door total before delivery.</p>
                    </div>
                @endif

                <div class="section-label">Optional coverage</div>
                <p class="section-note">Extended protection is optional and never required to buy. Pick a tier to flag your interest — the dealership's F&amp;I office reviews pricing with you later. Nothing is added to your reservation today.</p>

                <div class="plan-grid">
                    @foreach ($warrantyOptions as $key => $option)
                        <button type="button" class="plan {{ $warrantyPlan === $key ? 'is-on' : '' }}" wire:click="selectWarranty('{{ $key }}')">
                            @if ($option['popular'])<span class="plan-badge">★ Most chosen</span>@endif
                            <div class="plan-name">{{ $option['name'] }}</div>
                            <div class="plan-tag">{{ $option['blurb'] }}</div>
                            <div class="plan-pick">{{ $warrantyPlan === $key ? 'Selected ✓' : 'Choose' }}</div>
                        </button>
                    @endforeach
                </div>
                <div style="margin-top:16px;">
                    <button type="button" class="text-link subtle" wire:click="selectWarranty(null)">No added coverage</button>
                </div>

            @elseif ($this->stepKey() === 'id')
                <div class="step-meta" style="text-align:center;">Step 4 · ID check</div>
                <h1 class="h1" style="text-align:center;">Prove it's really you</h1>
                <p class="lede" style="text-align:center;">A quick, encrypted licence check keeps your purchase secure and the registration clean. Under a minute.</p>

                <div class="id-card">
                    <div class="id-illus">
                        <svg width="54" height="54" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="1.6"><rect x="2" y="5" width="20" height="14" rx="2"/><circle cx="8" cy="12" r="2"/><path d="M13 10h5M13 14h5"/></svg>
                    </div>
                    <p class="muted" style="font-size:13.5px;max-width:380px;margin:0 auto 18px;">Verified through our trusted identity partner. Your scan is encrypted, never shared, and kept only as long as the law requires.</p>
                    <button type="button" class="btn {{ $identityVerified ? 'btn-soft' : 'btn-primary' }} btn-block btn-lg" wire:click="verifyIdentity" wire:loading.attr="disabled" wire:target="verifyIdentity">
                        <span wire:loading.remove wire:target="verifyIdentity">{{ $identityVerified ? "✓ You're verified" : 'Verify my licence' }}</span>
                        <span wire:loading wire:target="verifyIdentity">Verifying…</span>
                    </button>
                </div>

            @elseif ($this->stepKey() === 'review')
                <div class="step-meta">Step 5 · Review</div>
                <h1 class="h1">The final once-over</h1>
                <p class="lede">Give it a glance. Anything to change? Hit edit. All good? Let's hold your {{ $vehicle->make }} {{ $vehicle->model }}.</p>

                <div class="rev-card">
                    <div>
                        <div class="rev-label">Trade-in</div>
                        <div class="rev-value">{{ $wantsTrade === true ? $tradeYear . ' ' . $tradeMake . ' ' . $tradeModel . ' · appraised by dealer' : 'None' }}</div>
                    </div>
                    <button type="button" class="text-link" wire:click="jumpTo(0)">Edit</button>
                </div>
                <div class="rev-card">
                    <div>
                        <div class="rev-label">You</div>
                        <div class="rev-value">{{ $firstName }} {{ $lastName }} · {{ $city }}, {{ $province }}</div>
                    </div>
                    <button type="button" class="text-link" wire:click="jumpTo(1)">Edit</button>
                </div>
                <div class="rev-card">
                    <div>
                        <div class="rev-label">Plan &amp; payment</div>
                        <div class="rev-value">{{ $purchaseType === 'finance' ? 'Finance · ' . $termMonths . ' mo · ' . $this->asMoney($downPayment) . ' down · est. ' . $this->asMoney($this->estimatedBiweekly) . '/biweekly' : 'Cash · ' . $this->asMoney($this->priceDollars) }}{{ $warrantyPlan ? ' · ' . $warrantyOptions[$warrantyPlan]['name'] . ' coverage' : '' }}</div>
                    </div>
                    <button type="button" class="text-link" wire:click="jumpTo(2)">Edit</button>
                </div>
                <div class="rev-card">
                    <div>
                        <div class="rev-label">Identity</div>
                        <div class="rev-value">{{ $identityVerified ? 'Verified' : 'Not verified' }}</div>
                    </div>
                    <button type="button" class="text-link" wire:click="jumpTo(3)">Edit</button>
                </div>

            @elseif ($this->stepKey() === 'reserve')
                <div class="step-meta">Step 6 · Reserve</div>
                <h1 class="h1">Hold it in your name</h1>
                <p class="lede">A $150 refundable deposit locks this exact {{ $vehicle->make }} {{ $vehicle->model }} so no one else can grab it while you finish. It comes straight off your total.</p>

                <div class="pay-card">
                    <div class="field"><label class="field-label">Name on card</label><input type="text" class="field-input" value="{{ $firstName }} {{ $lastName }}" readonly></div>
                    <div class="field"><label class="field-label">Card number</label><input type="text" class="field-input" placeholder="1234 5678 9012 3456" inputmode="numeric"></div>
                    <div class="field-row">
                        <div class="field"><label class="field-label">Expiry</label><input type="text" class="field-input" placeholder="MM / YY"></div>
                        <div class="field"><label class="field-label">CVC</label><input type="text" class="field-input" placeholder="123"></div>
                    </div>
                    <p class="muted" style="font-size:12px;text-align:center;margin:4px 0 0;">Securely processed · refundable any time before delivery</p>
                </div>

                <div class="reassure">
                    @foreach (['Locks this exact car for you', 'Fully refundable, no questions', 'Credited straight to your total'] as $line)
                        <div class="reassure-line"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="var(--good)" stroke-width="2.4"><path d="m5 12 4.5 4.5L19 7"/></svg> {{ $line }}</div>
                    @endforeach
                </div>

            @elseif ($this->stepKey() === 'done')
                <div class="done">
                    <div class="done-badge"><svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="var(--good)" stroke-width="2.2"><circle cx="12" cy="12" r="9"/><path d="m8 12 2.5 2.5L16 9"/></svg></div>
                    <h1>The keys are basically yours, {{ $firstName }}.</h1>
                    <p class="lede">Your {{ $vehicle->model_year }} {{ $vehicle->make }} {{ $vehicle->model }} is held in your name. We've emailed your confirmation and the $150 deposit receipt.</p>
                    <div class="ref-chip">Reservation <b>{{ $dealReference }}</b></div>
                    <div class="timeline-next">
                        <div class="tn-head">From here</div>
                        <div class="tn-step"><span class="tn-n">1</span><div><div class="tn-title">The dealership confirms your financing</div><div class="muted tn-sub">Final approval and your real rate — usually within a day.</div></div></div>
                        <div class="tn-step"><span class="tn-n">2</span><div><div class="tn-title">They prep your car</div><div class="muted tn-sub">Detailed, inspected, and ready for pickup or delivery.</div></div></div>
                        <div class="tn-step"><span class="tn-n">3</span><div><div class="tn-title">Track it all in My Garage</div><div class="muted tn-sub">Live status and a direct line to the dealership — coming soon.</div></div></div>
                    </div>
                </div>
            @endif
        </div>

        @if ($this->stepKey() !== 'done')
            <aside class="rail">
                <div class="rail-card">
                    <div class="rail-photo" @if ($this->heroPhoto) style="background-image:url('{{ $this->heroPhoto }}');background-size:cover;background-position:center;" @endif>
                        @unless ($this->heroPhoto)
                            <div class="art">
                                <svg viewBox="0 0 320 130" width="210" xmlns="http://www.w3.org/2000/svg" style="max-width:90%;">
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
                    <div class="rail-body">
                        <div class="rail-veh">{{ $vehicle->model_year }} {{ $vehicle->make }} {{ $vehicle->model }} {{ $vehicle->trim }}</div>
                        <div class="rail-km">{{ $vehicle->displayKilometres }} · {{ $this->asMoney($this->priceDollars) }} all-in</div>

                        <div class="rail-group">
                            @if ($purchaseType === 'finance')
                                <div class="rail-line head"><span>Estimated payment</span><span></span></div>
                                <div class="rail-line sub"><span>Finance · {{ $termMonths }} mo</span><span>{{ $this->asMoney($downPayment) }} down</span></div>
                                <div class="rail-total">
                                    <span class="lbl">Biweekly</span>
                                    <span class="amt">{{ $this->asMoney($this->estimatedBiweekly) }}<span class="per"> /biweekly</span></span>
                                </div>
                            @else
                                <div class="rail-line head"><span>Pay in full</span><span></span></div>
                                <div class="rail-total">
                                    <span class="lbl">Total</span>
                                    <span class="amt">{{ $this->asMoney($this->priceDollars) }}</span>
                                </div>
                            @endif
                        </div>

                        @if ($wantsTrade === true || $warrantyPlan)
                            <div class="rail-group">
                                @if ($wantsTrade === true)
                                    <div class="rail-line sub"><span>Trade-in</span><span>Appraised by dealer</span></div>
                                @endif
                                @if ($warrantyPlan)
                                    <div class="rail-line sub"><span>Coverage interest</span><span>{{ $warrantyOptions[$warrantyPlan]['name'] }}</span></div>
                                @endif
                            </div>
                        @endif

                        <div class="rail-due">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 8v5l3 2"/></svg>
                            Due today: <b>&nbsp;$150</b>&nbsp;— refundable, credited to your total
                        </div>
                        <div class="rail-cash">
                            @if ($purchaseType === 'finance')
                                Estimate only — the dealership's finance office confirms your real rate. Plus HST &amp; licensing.
                            @else
                                Plus HST &amp; licensing.
                            @endif
                        </div>
                    </div>
                </div>
            </aside>
        @endif
    </main>

    <div class="paybar">
        <div class="paybar-inner">
            @if ($stepIndex > 0 && $this->stepKey() !== 'done')
                <button type="button" class="paybar-back" wire:click="goBack">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M11 6l-6 6 6 6"/></svg> Back
                </button>
            @else
                <span style="width:60px;"></span>
            @endif

            @if ($this->stepKey() !== 'done')
                <div class="paybar-price">
                    <span class="lbl">{{ $purchaseType === 'cash' ? 'Total' : 'Est. payment' }}</span>
                    <span class="amt">{{ $purchaseType === 'cash' ? $this->asMoney($this->priceDollars) : $this->asMoney($this->estimatedBiweekly) }}<span class="per">{{ $purchaseType === 'cash' ? '' : ' /biweekly' }}</span></span>
                </div>
            @endif

            <div class="paybar-cta">
                @if ($this->stepKey() === 'reserve')
                    <button type="button" class="btn btn-primary btn-lg" wire:click="reserve" wire:loading.attr="disabled" wire:target="reserve">
                        <span wire:loading.remove wire:target="reserve">Pay $150 &amp; reserve →</span>
                        <span wire:loading wire:target="reserve">Reserving…</span>
                    </button>
                @elseif ($this->stepKey() === 'done')
                    <a href="/" class="btn btn-primary btn-lg">Back to browsing →</a>
                @else
                    <button type="button" class="btn btn-primary btn-lg" wire:click="goNext" @disabled(! $this->canAdvance())>{{ $this->ctaLabel() }} →</button>
                @endif
            </div>
        </div>
    </div>
</div>
