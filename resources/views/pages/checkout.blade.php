<?php

use App\Mail\ReservationConfirmed;
use App\Mail\ReservationSubmitted;
use App\Models\Deal;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Valuation\Data\TradeInput;
use App\Services\Valuation\TradeValuation;
use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

new #[Layout('layouts.checkout')] class extends Component {
    public Vehicle $vehicle;

    public int $stepIndex = 0;

    public array $steps = [
        ['key' => 'trade',   'label' => 'Trade'],
        ['key' => 'buyer',   'label' => 'About you'],
        ['key' => 'plan',    'label' => 'Plan'],
        ['key' => 'extras',  'label' => 'Extras'],
        ['key' => 'handover','label' => 'Handover'],
        ['key' => 'id',      'label' => 'ID check'],
        ['key' => 'review',  'label' => 'Review'],
        ['key' => 'reserve', 'label' => 'Reserve'],
        ['key' => 'done',    'label' => 'Done'],
    ];

    /* ---------------------------------------------------------------------
       Trade-in (optional, self-reported; the dealer appraises it later)

       The trade step runs its own sub-flow: choice → details → condition →
       estimate. Sub-state lives in $tradeSubStep — these are NOT new
       top-level steps, so the stepper stays on "Trade" throughout.
       --------------------------------------------------------------------- */
    public ?bool $wantsTrade = null;
    public string $tradeSubStep = 'choice';

    // Details sub-step
    public $tradeYear = '';
    public string $tradeMake = '';
    public string $tradeModel = '';
    public string $tradeTrim = '';
    public $tradeKilometres = '';
    public string $tradeExteriorColour = '';
    public string $tradeKeyCount = '2';

    public array $tradeFeatureOptions = [
        'sunroof' => 'Sunroof / moonroof',
        'leather' => 'Leather seats',
        'heated'  => 'Heated seats',
        'nav'     => 'Navigation',
        'carplay' => 'Apple CarPlay / Android Auto',
        'tow'     => 'Tow package',
        'winter'  => 'Winter tire set',
    ];

    public array $tradeFeatures = [
        'sunroof' => false,
        'leather' => false,
        'heated'  => false,
        'nav'     => false,
        'carplay' => false,
        'tow'     => false,
        'winter'  => false,
    ];

    // Condition sub-step
    public string $tradeExteriorCondition = 'good';
    public string $tradeInteriorCondition = 'good';
    public string $tradeTireCondition = 'good';
    public string $tradeMechanicalCondition = 'good';
    public string $tradeAccidentHistory = 'none';
    public string $tradeOwnerCount = '1';
    public string $tradeTitleStatus = 'clean';
    public string $tradeWasSmokedIn = 'no';
    public string $tradeCarriedPets = 'no';
    public string $tradeHasAftermarketMods = 'no';
    public $tradeLienOwing = '';
    public string $tradeNotes = '';

    // Saved estimate (computed once the condition sub-step is cleared).
    // Everything here is preliminary and non-binding — the dealership's
    // inspection sets the real number. Persisted onto the DealTradeIn at reserve.
    public ?int $tradeEstimatePointInCents = null;
    public ?int $tradeEstimateLowInCents = null;
    public ?int $tradeEstimateHighInCents = null;
    public array $tradeEstimateLines = [];
    public array $tradeEstimateBreakdown = [];
    public ?string $tradeEstimateProviderKey = null;
    public ?string $tradeEstimatedAt = null;

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

    // Coverage tiers the buyer can flag interest in. The dealership's F&I office
    // confirms what's available and prices it later — nothing here is binding,
    // tied to the sale, or added to the reservation. The feature list and the
    // "popular" flag are presentation only.
    public array $warrantyOptions = [
        'openroad' => [
            'name'    => 'Open Road',
            'tag'     => 'Get rolling with the must-haves covered.',
            'popular' => false,
            'feats'   => [
                ['label' => 'Refundable deposit',           'included' => true],
                ['label' => '3-month / 5,000 km warranty',  'included' => true],
                ['label' => 'Roadside assistance',          'included' => true],
                ['label' => 'Tire & rim cover',             'included' => false],
                ['label' => 'Rust & paint shield',          'included' => false],
            ],
        ],
        'safebet'  => [
            'name'    => 'Safe Bet',
            'tag'     => 'Our pick — longer cover, no surprise bills.',
            'popular' => true,
            'feats'   => [
                ['label' => 'Refundable deposit',           'included' => true],
                ['label' => '3-year / 60,000 km warranty',  'included' => true],
                ['label' => 'Roadside assistance',          'included' => true],
                ['label' => 'Free delivery or pickup',      'included' => true],
                ['label' => 'Rust & paint shield',          'included' => false],
            ],
        ],
        'bumper'   => [
            'name'    => 'Bumper-to-Bumper',
            'tag'     => 'Everything in. Drive without a second thought.',
            'popular' => false,
            'feats'   => [
                ['label' => 'Refundable deposit',           'included' => true],
                ['label' => '3-year / 60,000 km warranty',  'included' => true],
                ['label' => 'Roadside + tire & rim',        'included' => true],
                ['label' => 'Rust & paint shield',          'included' => true],
                ['label' => 'Free delivery or pickup',      'included' => true],
            ],
        ],
    ];

    // Rows for the "full feature comparison" table. A value is either a boolean
    // (renders a tick/cross) or a short string (renders as-is). Column order
    // matches the $warrantyOptions order: Open Road, Safe Bet, Bumper-to-Bumper.
    public array $comparisonRows = [
        ['label' => 'Refundable deposit', 'values' => [true,  true,  true]],
        ['label' => 'Warranty',           'values' => ['3 mo', '3 yr', '3 yr']],
        ['label' => 'Roadside',           'values' => [true,  true,  true]],
        ['label' => 'Tire & rim',         'values' => [false, false, true]],
        ['label' => 'Rust & paint',       'values' => [false, false, true]],
    ];

    // ----- Extras (GAP + stackable add-ons) -----
    // Same education and menu as the mockup, but every figure here is an
    // illustrative biweekly estimate the dealer's F&I office confirms. Toggling
    // flags interest only — nothing is priced into the reservation or due today.
    public bool $wantsGap = false;
    public array $selectedExtras = [];

    public int $gapBiweekly = 18;

    // The write-off scenario the GAP explainer illustrates (all figures fixed —
    // this is an educational example, not the buyer's own numbers).
    public array $gapScenario = [
        'oweLabel'        => '$28,400',
        'insurancePays'   => '$22,150',
        'insuranceWidth'  => 78,
        'gapCovers'       => '$6,250',
        'gapWidth'        => 22,
    ];

    public array $extrasCatalog = [
        'theft'   => ['name' => 'Theft recovery & tracking', 'desc' => "Hidden device + recovery service if it's ever taken",   'biweekly' => 7],
        'tirerim' => ['name' => 'Tire & rim cover',          'desc' => 'Pothole and curb damage, repaired or replaced',         'biweekly' => 9],
        'shield'  => ['name' => 'Paint & interior shield',   'desc' => 'Stains, scuffs, and fade protection inside and out',     'biweekly' => 6],
        'maint'   => ['name' => 'Prepaid maintenance',       'desc' => 'Oil, filters, and scheduled service at a locked rate',   'biweekly' => 12],
        'keys'    => ['name' => 'Lost key cover',            'desc' => 'Replacement smart keys without the dealer markup',       'biweekly' => 4],
    ];

    public array $provinceOptions = ['Ontario', 'Alberta', 'British Columbia', 'Manitoba', 'Quebec', 'Saskatchewan'];

    // ----- Handover (pickup or delivery, with a day + time window) -----
    // Both options are free to the buyer; pickup also names a hub, delivery
    // goes to the address from the "About you" step. The chosen day + window
    // is combined into a single timestamp (pickup_at) when the deal is created.
    public string $handoverMode = 'pickup';
    public int $pickupSpotIndex = 0;
    public ?string $handoverDate = null;     // 'Y-m-d' from handoverDateOptions()
    public string $handoverTimeSlot = '10:00';

    // The hubs a buyer can collect from. Distance is illustrative copy, matching
    // the prototype — the dealership confirms the exact address when they reach out.
    public array $pickupSpots = [
        ['name' => 'Mississauga hub',  'distance' => '55 km'],
        ['name' => 'Downtown Toronto', 'distance' => '38 km'],
    ];

    // Two-hour collection/delivery windows. The key is the window's start time
    // (24h) so it slots straight into the pickup_at timestamp.
    public array $handoverTimeSlots = [
        '10:00' => 'Morning · 10am – 12pm',
        '12:00' => 'Midday · 12 – 2pm',
        '14:00' => 'Afternoon · 2 – 4pm',
        '16:00' => 'Evening · 4 – 6pm',
    ];

    // ----- Identity & reservation -----
    public bool $identityVerified = false;
    public ?string $dealReference = null;

    public function mount(Vehicle $vehicle): void
    {
        // Consumers can only reserve a car that's actually live on the marketplace.
        abort_unless($vehicle->is_published, 404);

        $this->vehicle = $vehicle;

        // Account-first: the buyer signs in (or registers) before the journey
        // begins, so every Deal is born with an owner. A guest who lands here
        // directly is sent to login and bounced straight back to this car.
        // (Identity verification later in the journey is a separate step —
        // that's "prove who you are", this is "have an account".)
        if (! auth()->check()) {
            session()->put('url.intended', request()->fullUrl());
            $this->redirectRoute('buyer.login');

            return;
        }

        // The buyer is already signed in by the time the journey starts — seed the
        // "About you" step from their account so they aren't retyping known details.
        $signedInBuyer = auth()->user();

        if ($signedInBuyer !== null) {
            $nameParts = preg_split('/\s+/', trim($signedInBuyer->name), 2);

            $this->firstName = $nameParts[0] ?? '';
            $this->lastName  = $nameParts[1] ?? '';
            $this->email     = $signedInBuyer->email;
        }

        // Pre-select the first available handover day so the step opens ready to go.
        $this->handoverDate = $this->handoverDateOptions()[0]['date'] ?? null;
    }

    /* ---------------------------------------------------------------------
       Navigation

       NOTE: stepKey / canAdvance / ctaLabel are deliberately PLAIN methods,
       not #[Computed]. goNext() reads the current key before mutating
       stepIndex; a memoized computed would cache the pre-increment value and
       leave the rendered content a step behind the stepper (the "click twice"
       bug). Plain methods recompute on every call, so a single click advances.
       The same rule applies to every trade sub-step helper below.
       --------------------------------------------------------------------- */
    public function stepKey(): string
    {
        return $this->steps[$this->stepIndex]['key'];
    }

    public function indexOfStep(string $stepKey): int
    {
        foreach ($this->steps as $index => $step) {
            if ($step['key'] === $stepKey) {
                return $index;
            }
        }

        return 0;
    }

    public function canAdvance(): bool
    {
        return match ($this->stepKey()) {
            'trade' => $this->tradeSubStep !== 'choice' || $this->wantsTrade !== null,
            'id'    => $this->identityVerified,
            default => true,
        };
    }

    public function canGoBack(): bool
    {
        if ($this->stepIndex > 0) {
            return true;
        }

        return $this->stepKey() === 'trade'
            && $this->wantsTrade === true
            && $this->tradeSubStep !== 'choice';
    }

    public function ctaLabel(): string
    {
        if ($this->stepKey() === 'trade' && $this->wantsTrade === true) {
            return match ($this->tradeSubStep) {
                'condition' => 'See my estimate',
                default     => 'Continue',
            };
        }

        return match ($this->stepKey()) {
            'review' => 'Looks good — reserve',
            default  => 'Continue',
        };
    }

    public function goNext(): void
    {
        if ($this->stepKey() === 'trade' && ! $this->finishTradeStep()) {
            return; // moved within a trade sub-step instead
        }

        $passed = match ($this->stepKey()) {
            'trade' => true, // finishTradeStep() already validated everything
            'buyer' => $this->passesBuyerStep(),
            'plan'  => $this->passesPlanStep(),
            'handover' => $this->passesHandoverStep(),
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
        // Inside the trade questionnaire, "back" walks the sub-steps first.
        if ($this->stepKey() === 'trade' && $this->wantsTrade === true && $this->tradeSubStep !== 'choice') {
            $this->tradeSubStep = match ($this->tradeSubStep) {
                'estimate'  => 'condition',
                'condition' => 'details',
                default     => 'choice',
            };
            $this->dispatch('checkout-step-changed');

            return;
        }

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
       Trade sub-flow

       finishTradeStep() returns true only when the trade step is fully done
       and the checkout should advance to "About you". Returning false means
       we moved to (or stayed on) a sub-step. Validation failures throw, which
       also keeps us on the current sub-step with the errors showing.
       --------------------------------------------------------------------- */
    protected function finishTradeStep(): bool
    {
        $this->validate(
            ['wantsTrade' => ['required', 'boolean']],
            ['wantsTrade.required' => 'Let us know whether you have a car to trade.'],
        );

        if ($this->wantsTrade === false) {
            return true;
        }

        if ($this->tradeSubStep === 'choice') {
            $this->tradeSubStep = 'details';
            $this->dispatch('checkout-step-changed');

            return false;
        }

        if ($this->tradeSubStep === 'details') {
            $this->validateTradeDetails();
            $this->tradeSubStep = 'condition';
            $this->dispatch('checkout-step-changed');

            return false;
        }

        if ($this->tradeSubStep === 'condition') {
            $this->validateTradeCondition();
            $this->buildTradeEstimate();
            $this->tradeSubStep = 'estimate';
            $this->dispatch('checkout-step-changed');

            return false;
        }

        // Sub-step 'estimate' — the questionnaire is complete, carry on.
        return true;
    }

    protected function validateTradeDetails(): void
    {
        $this->validate([
            'tradeYear'           => ['required', 'integer', 'min:1990', 'max:' . ((int) date('Y') + 1)],
            'tradeMake'           => ['required', 'string', 'max:60'],
            'tradeModel'          => ['required', 'string', 'max:60'],
            'tradeTrim'           => ['nullable', 'string', 'max:60'],
            'tradeKilometres'     => ['required', 'integer', 'min:0', 'max:999999'],
            'tradeExteriorColour' => ['nullable', 'string', 'max:60'],
            'tradeKeyCount'       => ['required', 'in:1,2'],
        ]);
    }

    protected function validateTradeCondition(): void
    {
        $this->validate([
            'tradeExteriorCondition'   => ['required', 'in:excellent,good,fair,poor'],
            'tradeInteriorCondition'   => ['required', 'in:excellent,good,fair,poor'],
            'tradeTireCondition'       => ['required', 'in:new,good,worn'],
            'tradeMechanicalCondition' => ['required', 'in:perfect,good,minor,warning'],
            'tradeAccidentHistory'     => ['required', 'in:none,minor,major'],
            'tradeOwnerCount'          => ['required', 'in:1,2,3+'],
            'tradeTitleStatus'         => ['required', 'in:clean,rebuilt'],
            'tradeWasSmokedIn'         => ['required', 'in:no,yes'],
            'tradeCarriedPets'         => ['required', 'in:no,yes'],
            'tradeHasAftermarketMods'  => ['required', 'in:no,yes'],
            'tradeLienOwing'           => ['nullable', 'numeric', 'min:0'],
            'tradeNotes'               => ['nullable', 'string', 'max:500'],
        ]);
    }

    protected function buildTradeEstimate(): void
    {
        $tradeInput = new TradeInput(
            year: (int) $this->tradeYear,
            make: $this->tradeMake,
            model: $this->tradeModel,
            trim: $this->tradeTrim !== '' ? $this->tradeTrim : null,
            kilometres: (int) $this->tradeKilometres,
            exteriorCondition: $this->tradeExteriorCondition,
            interiorCondition: $this->tradeInteriorCondition,
            tireCondition: $this->tradeTireCondition,
            mechanicalCondition: $this->tradeMechanicalCondition,
            accidentHistory: $this->tradeAccidentHistory,
            ownerCount: $this->tradeOwnerCount,
            titleStatus: $this->tradeTitleStatus,
            wasSmokedIn: $this->tradeWasSmokedIn === 'yes',
            carriedPets: $this->tradeCarriedPets === 'yes',
            hasAftermarketMods: $this->tradeHasAftermarketMods === 'yes',
            keyCount: (int) $this->tradeKeyCount,
            features: array_map(fn ($isPresent) => (bool) $isPresent, $this->tradeFeatures),
            lienOwingInCents: $this->tradeLienInCents(),
        );

        $estimate = app(TradeValuation::class)->estimate($this->vehicle->dealer, $tradeInput);

        $this->tradeEstimatePointInCents = $estimate->pointInCents;
        $this->tradeEstimateLowInCents   = $estimate->lowInCents;
        $this->tradeEstimateHighInCents  = $estimate->highInCents;
        $this->tradeEstimateProviderKey  = $estimate->providerKey;
        $this->tradeEstimateBreakdown    = $estimate->toBreakdownArray();
        $this->tradeEstimatedAt          = now()->toIso8601String();

        // Normalised copy of the lines for rendering — keeps the Blade free
        // of any assumptions about the persisted breakdown's array shape.
        $this->tradeEstimateLines = collect($estimate->lines)
            ->map(fn ($valuationLine) => [
                'label'         => $valuationLine->label,
                'amountInCents' => $valuationLine->amountInCents,
                'isBase'        => $valuationLine->isBase,
            ])
            ->all();
    }

    protected function clearTradeEstimate(): void
    {
        $this->tradeEstimatePointInCents = null;
        $this->tradeEstimateLowInCents   = null;
        $this->tradeEstimateHighInCents  = null;
        $this->tradeEstimateLines        = [];
        $this->tradeEstimateBreakdown    = [];
        $this->tradeEstimateProviderKey  = null;
        $this->tradeEstimatedAt          = null;
    }

    /* ---------------------------------------------------------------------
       Step inputs
       --------------------------------------------------------------------- */
    public function setWantsTrade(bool $wantsTrade): void
    {
        $this->wantsTrade = $wantsTrade;

        if ($wantsTrade === false) {
            $this->tradeSubStep = 'choice';
            $this->clearTradeEstimate();
        }

        $this->resetErrorBag();
    }

    public function toggleTradeFeature(string $featureSlug): void
    {
        if (array_key_exists($featureSlug, $this->tradeFeatures)) {
            $this->tradeFeatures[$featureSlug] = ! $this->tradeFeatures[$featureSlug];
        }
    }

    public function removeTradeIn(): void
    {
        // The "not now, maybe later" escape hatch on the estimate screen.
        $this->wantsTrade = false;
        $this->tradeSubStep = 'choice';
        $this->clearTradeEstimate();
        $this->stepIndex = $this->indexOfStep('buyer');
        $this->dispatch('checkout-step-changed');
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

    public function toggleGap(): void
    {
        $this->wantsGap = ! $this->wantsGap;
    }

    public function toggleExtra(string $extraKey): void
    {
        if (! array_key_exists($extraKey, $this->extrasCatalog)) {
            return;
        }

        if (in_array($extraKey, $this->selectedExtras, true)) {
            $this->selectedExtras = array_values(array_diff($this->selectedExtras, [$extraKey]));
        } else {
            $this->selectedExtras[] = $extraKey;
        }
    }

    public function verifyIdentity(): void
    {
        // Stub for the identity partner (Persona / Paays — vendor still to be chosen).
        $this->identityVerified = true;
    }

    /* ---------------------------------------------------------------------
       Handover inputs
       --------------------------------------------------------------------- */
    public function setHandoverMode(string $mode): void
    {
        if (in_array($mode, ['pickup', 'delivery'], true)) {
            $this->handoverMode = $mode;
        }
    }

    public function setPickupSpot(int $spotIndex): void
    {
        if (array_key_exists($spotIndex, $this->pickupSpots)) {
            $this->pickupSpotIndex = $spotIndex;
        }
    }

    public function setHandoverDate(string $date): void
    {
        $availableDates = array_column($this->handoverDateOptions(), 'date');

        if (in_array($date, $availableDates, true)) {
            $this->handoverDate = $date;
        }
    }

    public function setHandoverTimeSlot(string $slotKey): void
    {
        if (array_key_exists($slotKey, $this->handoverTimeSlots)) {
            $this->handoverTimeSlot = $slotKey;
        }
    }

    /* ---------------------------------------------------------------------
       Validation per step
       --------------------------------------------------------------------- */
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

    protected function passesHandoverStep(): bool
    {
        $availableDates = array_column($this->handoverDateOptions(), 'date');

        $this->validate([
            'handoverMode'     => ['required', 'in:pickup,delivery'],
            'handoverDate'     => ['required', 'in:' . implode(',', $availableDates)],
            'handoverTimeSlot' => ['required', 'in:' . implode(',', array_keys($this->handoverTimeSlots))],
        ], [
            'handoverDate.required' => 'Pick a day that works for you.',
            'handoverDate.in'       => 'Pick a day that works for you.',
        ]);

        if ($this->handoverMode === 'pickup' && ! array_key_exists($this->pickupSpotIndex, $this->pickupSpots)) {
            $this->pickupSpotIndex = 0;
        }

        return true;
    }
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

    /**
     * This dealership's active fee schedule, in display order. The breakdown
     * reads it live during checkout; the same set is frozen onto the deal at
     * reserve (fees_snapshot) so later console edits don't rewrite this buyer's
     * numbers. Empty collection if the dealer hasn't configured any fees yet.
     */
    protected function dealerFees()
    {
        return $this->vehicle->dealer?->activeFees ?? collect();
    }

    /**
     * The estimated payment broken into the lines the rail's accordion shows,
     * with the dealer's fees sorted into the two OMVIC buckets:
     *
     *  - includedFees    — the dealer's own costs (freight, PDI, admin), already
     *                      inside the all-in price. Disclosed as "included"; they
     *                      add NOTHING, so the headline biweekly/cash figure is
     *                      unchanged by their presence.
     *  - passThroughFees — at-cost charges (licensing, registration) collected at
     *                      delivery. Shown separately with their dollar amounts;
     *                      never financed, never folded into the headline.
     *
     * vehicleBiweekly + taxesBiweekly still sum to estimatedBiweekly (finance).
     */
    #[Computed]
    public function paymentBreakdown(): array
    {
        $total = $this->estimatedBiweekly;
        $vehicleBiweekly = $total / 1.13;
        $taxesBiweekly = $total - $vehicleBiweekly;

        $includedFees = [];
        $passThroughFees = [];
        $passThroughTotalInCents = 0;

        foreach ($this->dealerFees() as $fee) {
            if ($fee->is_pass_through) {
                $passThroughFees[] = [
                    'label'  => $fee->label,
                    'amount' => $fee->amount_in_cents / 100,
                ];
                $passThroughTotalInCents += $fee->amount_in_cents;
            } else {
                $includedFees[] = ['label' => $fee->label];
            }
        }

        return [
            'vehicleBiweekly'  => $vehicleBiweekly,
            'taxesBiweekly'    => $taxesBiweekly,
            'total'            => $total,
            'includedFees'     => $includedFees,
            'passThroughFees'  => $passThroughFees,
            'passThroughTotal' => $passThroughTotalInCents / 100,
        ];
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

    public function centsAsMoney(int $amountInCents): string
    {
        return '$' . number_format($amountInCents / 100, 0);
    }

    public function tradeLienInCents(): int
    {
        return (int) round((float) $this->tradeLienOwing * 100);
    }

    public function tradeEstimateRangeLabel(): ?string
    {
        if ($this->tradeEstimateLowInCents === null || $this->tradeEstimateHighInCents === null) {
            return null;
        }

        return $this->centsAsMoney($this->tradeEstimateLowInCents)
            . ' – '
            . $this->centsAsMoney($this->tradeEstimateHighInCents);
    }

    public function tradeSummaryLabel(): string
    {
        if ($this->wantsTrade !== true) {
            return 'None';
        }

        $tradeName = trim($this->tradeYear . ' ' . $this->tradeMake . ' ' . $this->tradeModel);
        $rangeLabel = $this->tradeEstimateRangeLabel();

        if ($rangeLabel !== null) {
            return $tradeName . ' · est. ' . $rangeLabel . ' (non-binding)';
        }

        return $tradeName . ' · appraised by dealer';
    }

    /**
     * Keys for every coverage item the buyer flagged interest in — the warranty
     * tier, GAP, and any add-ons. Persisted on the Deal so the dealer's F&I
     * office can pick up the conversation. Nothing here is priced or binding.
     */
    public function coverageInterestKeys(): array
    {
        $keys = [];

        if ($this->warrantyPlan !== null) {
            $keys[] = $this->warrantyPlan;
        }

        if ($this->wantsGap) {
            $keys[] = 'gap';
        }

        foreach ($this->selectedExtras as $extraKey) {
            $keys[] = $extraKey;
        }

        return $keys;
    }

    /** Human-readable names for the same flagged coverage, for the activity trail. */
    public function coverageInterestLabels(): array
    {
        $labels = [];

        if ($this->warrantyPlan !== null) {
            $labels[] = $this->warrantyOptions[$this->warrantyPlan]['name'] . ' plan';
        }

        if ($this->wantsGap) {
            $labels[] = 'GAP protection';
        }

        foreach ($this->selectedExtras as $extraKey) {
            $labels[] = $this->extrasCatalog[$extraKey]['name'];
        }

        return $labels;
    }

    public function hasCoverageInterest(): bool
    {
        return $this->warrantyPlan !== null || $this->wantsGap || count($this->selectedExtras) > 0;
    }

    /* ---------------------------------------------------------------------
       Handover helpers
       --------------------------------------------------------------------- */

    /**
     * The next six collection/delivery days, generated from tomorrow so the
     * options never go stale. Each row carries the parts the date chip renders.
     */
    public function handoverDateOptions(): array
    {
        $options = [];

        for ($dayOffset = 1; $dayOffset <= 6; $dayOffset++) {
            $day = now()->addDays($dayOffset);

            $options[] = [
                'date'    => $day->format('Y-m-d'),
                'month'   => $day->format('M'),
                'day'     => $day->format('j'),
                'weekday' => $day->format('D'),
            ];
        }

        return $options;
    }

    public function selectedPickupSpotName(): string
    {
        return $this->pickupSpots[$this->pickupSpotIndex]['name'] ?? $this->pickupSpots[0]['name'];
    }

    /** Where the car changes hands — a named hub for pickup, the buyer's city for delivery. */
    public function handoverLocationLabel(): string
    {
        if ($this->handoverMode === 'pickup') {
            return $this->selectedPickupSpotName();
        }

        $cityProvince = trim($this->city . ', ' . $this->province, ', ');

        return 'Home delivery' . ($cityProvince !== '' ? ' · ' . $cityProvince : '');
    }

    /** The chosen day + window as one timestamp, stored on the deal as pickup_at. */
    public function handoverAt(): ?\Illuminate\Support\Carbon
    {
        if ($this->handoverDate === null) {
            return null;
        }

        return \Illuminate\Support\Carbon::parse($this->handoverDate . ' ' . $this->handoverTimeSlot);
    }

    /** A one-line summary for the review card and the dealer's activity trail. */
    public function handoverSummaryLabel(): string
    {
        $when = $this->handoverAt();
        $window = $this->handoverTimeSlots[$this->handoverTimeSlot] ?? '';
        $modeLabel = $this->handoverMode === 'pickup'
            ? 'Pickup · ' . $this->selectedPickupSpotName()
            : $this->handoverLocationLabel();

        if ($when === null) {
            return $modeLabel;
        }

        return $modeLabel . ' · ' . $when->format('D M j') . ' · ' . $window;
    }

    /**
     * The legacy single "condition" column gets the worse of the two cabin
     * answers so older dealer-console surfaces keep showing something honest.
     */
    protected function overallTradeCondition(): string
    {
        $conditionRank = ['excellent' => 0, 'good' => 1, 'fair' => 2, 'poor' => 3];

        return $conditionRank[$this->tradeExteriorCondition] >= $conditionRank[$this->tradeInteriorCondition]
            ? $this->tradeExteriorCondition
            : $this->tradeInteriorCondition;
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
                'user_id'               => auth()->id(),
                'purchase_type'         => $this->purchaseType,
                'term_months'           => $this->purchaseType === 'finance' ? $this->termMonths : null,
                'down_payment_in_cents' => $this->purchaseType === 'finance' ? (int) round((float) $this->downPayment * 100) : null,
                'warranty_plan'         => $this->warrantyPlan,
                'extras_interest'       => $this->coverageInterestKeys(),
                'fees_snapshot'         => $this->dealerFees()->map->toSnapshotEntry()->values()->all(),
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
                'handover_mode'         => $this->handoverMode,
                'pickup_location'       => $this->handoverLocationLabel(),
                'pickup_at'             => $this->handoverAt(),
                'identity_verified_at'  => now(),
            ]);

            if ($this->wantsTrade === true) {
                $deal->tradeIn()->create([
                    'model_year'                    => (int) $this->tradeYear,
                    'make'                          => $this->tradeMake,
                    'model'                         => $this->tradeModel,
                    'trim'                          => $this->tradeTrim !== '' ? $this->tradeTrim : null,
                    'kilometres'                    => (int) $this->tradeKilometres,
                    'condition'                     => $this->overallTradeCondition(),
                    'lien_owing_in_cents'           => $this->tradeLienInCents(),
                    'customer_notes'                => $this->tradeNotes !== '' ? $this->tradeNotes : null,
                    'exterior_colour'               => $this->tradeExteriorColour !== '' ? $this->tradeExteriorColour : null,
                    'key_count'                     => (int) $this->tradeKeyCount,
                    'features'                      => $this->tradeFeatures,
                    'exterior_condition'            => $this->tradeExteriorCondition,
                    'interior_condition'            => $this->tradeInteriorCondition,
                    'tire_condition'                => $this->tradeTireCondition,
                    'mechanical_condition'          => $this->tradeMechanicalCondition,
                    'accident_history'              => $this->tradeAccidentHistory,
                    'owner_count'                   => $this->tradeOwnerCount,
                    'title_status'                  => $this->tradeTitleStatus,
                    'was_smoked_in'                 => $this->tradeWasSmokedIn === 'yes',
                    'carried_pets'                  => $this->tradeCarriedPets === 'yes',
                    'has_aftermarket_mods'          => $this->tradeHasAftermarketMods === 'yes',
                    'estimated_value_in_cents'      => $this->tradeEstimatePointInCents,
                    'estimated_value_low_in_cents'  => $this->tradeEstimateLowInCents,
                    'estimated_value_high_in_cents' => $this->tradeEstimateHighInCents,
                    'valuation_breakdown'           => $this->tradeEstimateBreakdown,
                    'valuation_provider'            => $this->tradeEstimateProviderKey,
                    'valuated_at'                   => $this->tradeEstimatedAt,
                    'estimate_is_binding'           => false,
                ]);
            }

            // The opening activity trail the dealer sees the moment this lands.
            $deal->recordActivity('system', 'Reservation created through TruCars checkout.');
            $deal->recordActivity('system', '$150 refundable deposit held — credited to the purchase price.');
            $deal->recordActivity('system', 'Identity verified.');
            $deal->recordActivity('system', 'Handover scheduled — ' . $this->handoverSummaryLabel() . '. The dealership confirms the exact time with the customer.');

            if ($this->hasCoverageInterest()) {
                $deal->recordActivity(
                    'system',
                    'Customer flagged coverage interest (non-binding, for F&I review): '
                    . implode(', ', $this->coverageInterestLabels())
                    . '. No coverage has been priced or added — the dealership confirms availability and pricing with the customer.',
                );
            }

            if ($this->wantsTrade === true && $this->tradeEstimateRangeLabel() !== null) {
                $deal->recordActivity(
                    'system',
                    'Customer completed the trade-in questionnaire — preliminary estimate '
                    . $this->tradeEstimateRangeLabel()
                    . ' (non-binding, self-reported). Final value is set after the dealership inspects the vehicle.',
                );
            }

            $deal->recordActivity(
                'sms',
                'Hi ' . $this->firstName . ' — your ' . $this->vehicle->model_year . ' ' . $this->vehicle->make . ' ' . $this->vehicle->model
                . ' is reserved! Reference ' . $deal->reference . '. The dealership will reach out shortly to confirm financing and next steps. — TruCars',
                null,
                'outbound',
            );

            return $deal;
        });

        // The reservation is committed. Send the buyer their confirmation now —
        // kept outside the transaction above so a Postmark hiccup can never undo
        // a reservation the buyer has already paid for.
        $this->emailReservationConfirmation($newDeal);
        $this->emailDealerReservation($newDeal);

        $this->dealReference = $newDeal->reference;
        $this->stepIndex = count($this->steps) - 1;
        $this->dispatch('checkout-step-changed');
    }

    /**
     * Email the buyer their reservation confirmation through Postmark.
     *
     * Runs after the reservation has committed, so the buyer is already
     * reserved no matter what happens here. If Postmark is unreachable we
     * swallow the failure, log it, and leave a note on the deal so the
     * dealership can follow up directly instead of the customer hitting a 500.
     */
    private function emailReservationConfirmation(Deal $newDeal): void
    {
        try {
            Mail::to($newDeal->email)->send(new ReservationConfirmed($newDeal));

            $newDeal->recordActivity(
                'system',
                'Reservation confirmation emailed to ' . $newDeal->email . '.',
            );
        } catch (\Throwable $sendFailure) {
            Log::error('Reservation confirmation email failed to send.', [
                'deal_reference' => $newDeal->reference,
                'buyer_email'    => $newDeal->email,
                'error'          => $sendFailure->getMessage(),
            ]);

            $newDeal->recordActivity(
                'system',
                'Reservation confirmation email could not be sent to ' . $newDeal->email
                . ' — the dealership may want to follow up directly.',
            );
        }
    }

    /**
     * Notify the dealership that a reservation just landed, through Postmark.
     *
     * Same posture as the buyer confirmation: runs after the reservation has
     * committed, so a Postmark hiccup can never undo a paid reservation. There
     * are no staff roles yet, so every user attached to the deal's dealer is
     * notified — in practice the F&I user. When a second staff member exists
     * and the team shouldn't all be pinged, this is where a dealer
     * notification_email or a role filter would slot in.
     */
    private function emailDealerReservation(Deal $newDeal): void
    {
        $dealerStaff = User::where('dealer_id', $newDeal->dealer_id)->get();

        if ($dealerStaff->isEmpty()) {
            $newDeal->recordActivity(
                'system',
                'No staff are attached to this dealership, so the new-reservation email was not sent — the deal is still in the console.',
            );

            return;
        }

        try {
            Mail::to($dealerStaff)->send(new ReservationSubmitted($newDeal));

            $recipientCount = $dealerStaff->count();

            $newDeal->recordActivity(
                'system',
                'New-reservation email sent to the dealership (' . $recipientCount
                . ' recipient' . ($recipientCount === 1 ? '' : 's') . ').',
            );
        } catch (\Throwable $sendFailure) {
            Log::error('Dealer new-reservation email failed to send.', [
                'deal_reference' => $newDeal->reference,
                'dealer_id'      => $newDeal->dealer_id,
                'error'          => $sendFailure->getMessage(),
            ]);

            $newDeal->recordActivity(
                'system',
                'New-reservation email to the dealership could not be sent — the deal is still visible in the console.',
            );
        }
    }
}; ?>

@push('styles')
    <style>
        [x-cloak] { display:none !important; }
        .field-error { color:var(--bad); font-size:12.5px; font-weight:600; margin:7px 0 0; }

        .trade-grid { display:grid; grid-template-columns:90px 1fr 1fr; gap:16px; }
        @media (max-width:560px){ .trade-grid { grid-template-columns:1fr; } }

        .money-input { display:flex; align-items:center; gap:8px; padding:0 16px; border:1.5px solid var(--line-strong); border-radius:var(--radius-sm); background:var(--card); transition:border-color .15s ease, box-shadow .15s ease; }
        .money-input:focus-within { border-color:var(--primary); box-shadow:0 0 0 4px var(--primary-soft); }
        .money-input input { flex:1; min-width:0; border:none; outline:none; background:none; padding:14px 0; font-size:15px; }
        .money-input .dollar { color:var(--ink-3); font-weight:600; }

        .chips { display:flex; flex-wrap:wrap; gap:9px; }
        .chip { padding:10px 16px; border:1.5px solid var(--line-strong); border-radius:var(--radius-pill); background:var(--card); font-size:13.5px; font-weight:600; color:var(--ink-2); cursor:pointer; transition:all .15s ease; }
        .chip:hover { border-color:var(--primary); color:var(--primary); }
        .chip.is-on { border-color:var(--primary); background:var(--primary-soft); color:var(--primary); }

        .offer-reveal { text-align:center; padding:34px 24px 26px; border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow-md); background:var(--card); margin-bottom:14px; }
        .offer-amount { font-size:44px; font-weight:800; letter-spacing:-.03em; line-height:1.05; }
        @media (max-width:560px){ .offer-amount { font-size:34px; } }
        .offer-pill { display:inline-block; font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--ink-2); background:var(--bg-2); border:1px solid var(--line); padding:5px 13px; border-radius:var(--radius-pill); margin-bottom:14px; }

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

        .plan-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:16px; }
        @media (max-width:680px){ .plan-grid { grid-template-columns:1fr; } }
        .plan { position:relative; text-align:left; display:flex; flex-direction:column; padding:24px 22px; border:1.5px solid var(--line-strong); border-radius:var(--radius); background:var(--card); box-shadow:var(--shadow-sm); cursor:pointer; transition:border-color .15s ease, transform .15s ease, box-shadow .15s ease; }
        .plan:hover { transform:translateY(-4px); box-shadow:var(--shadow-md); }
        .plan.is-on { border-color:var(--primary); border-width:2px; box-shadow:0 0 0 5px var(--primary-soft), var(--shadow-md); }
        .plan-badge { position:absolute; top:-12px; left:50%; transform:translateX(-50%); font-size:11px; font-weight:700; color:#fff; background:var(--primary); padding:5px 13px; border-radius:var(--radius-pill); letter-spacing:.03em; white-space:nowrap; box-shadow:0 6px 16px var(--primary-soft); }
        .plan-name { font-size:19px; font-weight:800; letter-spacing:-.01em; }
        .plan-tag { font-size:13px; color:var(--ink-2); margin:5px 0 4px; min-height:36px; }
        .plan-feats { list-style:none; padding:0; margin:14px 0 18px; }
        .plan-feats li { display:flex; gap:9px; align-items:flex-start; font-size:13.5px; padding:5px 0; color:var(--ink-2); }
        .plan-feats li svg { flex-shrink:0; margin-top:2px; }
        .plan-feats li.off { color:var(--ink-3); }
        .plan-pick { margin-top:auto; width:100%; padding:12px; border-radius:var(--radius-pill); font-weight:700; font-size:14px; border:1.5px solid var(--line-strong); background:var(--card); color:var(--ink); transition:all .15s ease; cursor:pointer; }
        .plan-pick:hover { border-color:var(--primary); color:var(--primary); }
        .plan.is-on .plan-pick { background:var(--primary); border-color:var(--primary); color:#fff; }

        .compare-wrap { text-align:center; margin-top:24px; }
        .compare-table { margin-top:16px; border:1px solid var(--line); border-radius:var(--radius); overflow:hidden; background:var(--card); text-align:left; }
        .ct-row { display:grid; grid-template-columns:1.4fr 1fr 1fr 1fr; border-bottom:1px solid var(--line); }
        .ct-row:last-child { border-bottom:none; }
        .ct-row > div { padding:13px 16px; font-size:13.5px; }
        .ct-row.head > div { font-weight:700; background:var(--bg-2); }
        .ct-row > div:not(:first-child) { text-align:center; border-left:1px solid var(--line); }

        .gap-hero { border:2px solid var(--primary); border-radius:var(--radius); padding:24px; margin-bottom:24px; background:linear-gradient(180deg, var(--primary-soft), var(--card) 40%); }
        .gap-hero-top { display:flex; align-items:flex-start; gap:16px; }
        .gap-hero-top .ic { width:46px; height:46px; border-radius:13px; background:var(--primary); color:#fff; display:grid; place-items:center; flex-shrink:0; }
        .gap-hero h3 { margin:0; font-size:19px; font-weight:800; letter-spacing:-.01em; }
        .gap-hero p { font-size:14px; color:var(--ink-2); margin:6px 0 0; line-height:1.55; }
        .gap-scenario { margin:20px 0 4px; }
        .gap-scenario-cap { font-size:13px; font-weight:700; color:var(--ink); margin-bottom:12px; }
        .gap-bar-row { margin-bottom:12px; }
        .gap-bar-name { font-size:12.5px; font-weight:600; color:var(--ink-2); margin-bottom:6px; }
        .gap-bar-track { display:flex; height:42px; border-radius:11px; overflow:hidden; background:var(--bg-2); }
        .gap-bar-fill { height:100%; display:flex; align-items:center; }
        .gap-bar-fill.owe { width:100%; background:var(--ink); justify-content:flex-end; }
        .gap-bar-fill.pays { background:var(--good); justify-content:flex-end; }
        .gap-bar-fill.gap { background:repeating-linear-gradient(45deg, var(--primary), var(--primary) 7px, #FF8A3D 7px, #FF8A3D 14px); justify-content:center; color:#fff; font-size:11.5px; font-weight:800; letter-spacing:.04em; }
        .gap-bar-amt { color:#fff; font-weight:700; font-size:13.5px; font-family:var(--font-mono); padding:0 14px; }
        .gap-scenario-foot { display:flex; align-items:center; gap:9px; font-size:13px; color:var(--ink-2); margin-top:14px; }
        .gap-scenario-foot b { color:var(--ink); }
        .hatch-dot { width:14px; height:14px; border-radius:4px; flex-shrink:0; background:repeating-linear-gradient(45deg, var(--primary), var(--primary) 4px, #FF8A3D 4px, #FF8A3D 8px); }
        .gap-cta { display:flex; align-items:center; justify-content:space-between; gap:16px; padding-top:18px; margin-top:18px; border-top:1px solid var(--line); }
        .gap-cta .price { font-weight:800; font-size:18px; }
        .gap-cta .price .per { font-size:13px; font-weight:500; color:var(--ink-3); }

        .extras-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
        @media (max-width:560px){ .extras-grid { grid-template-columns:1fr; } }
        .extra { display:flex; align-items:flex-start; gap:14px; padding:18px; border:1.5px solid var(--line-strong); border-radius:var(--radius-sm); cursor:pointer; transition:border-color .15s ease, background .15s ease, transform .12s ease; background:var(--card); text-align:left; }
        .extra:hover { border-color:var(--primary); transform:translateY(-2px); }
        .extra.is-on { border-color:var(--primary); background:var(--primary-soft); }
        .extra .check { width:24px; height:24px; border-radius:8px; border:2px solid var(--line-strong); display:grid; place-items:center; flex-shrink:0; color:#fff; transition:all .15s ease; margin-top:1px; }
        .extra.is-on .check { background:var(--primary); border-color:var(--primary); }
        .extra-name { font-weight:600; font-size:15px; }
        .extra-desc { font-size:12.5px; color:var(--ink-3); margin-top:3px; }
        .extra-price { font-weight:700; font-size:14px; white-space:nowrap; margin-left:auto; color:var(--primary); }

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

        /* Right-rail breakdown accordion */
        .rail-breakdown { margin-top:14px; padding-top:14px; border-top:1px solid var(--line); }
        .rail-breakdown-toggle { display:flex; align-items:center; justify-content:space-between; gap:12px; width:100%; padding:0; background:none; border:none; cursor:pointer; text-align:left; }
        .rbt-head { display:flex; flex-direction:column; gap:2px; }
        .rbt-label { font-size:12.5px; color:var(--ink-3); font-weight:600; }
        .rbt-amt { font-family:var(--font-mono); font-weight:700; font-size:22px; letter-spacing:-.02em; }
        .rbt-amt .per { font-size:12.5px; color:var(--ink-3); font-weight:500; }
        .rbt-chevron { color:var(--ink-3); flex-shrink:0; transition:transform .2s ease; }
        .rbt-chevron.is-open { transform:rotate(180deg); }
        .rail-breakdown-body .rail-group:first-child { margin-top:14px; }
        .rail-line.credit { color:var(--good); font-weight:600; }
        .rail-included { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--ink-3); margin-bottom:4px; }

        /* Handover — pickup spots, day chips, time windows */
        .spot-grid { display:flex; flex-wrap:wrap; gap:11px; margin-top:8px; }
        .spot { display:flex; flex-direction:column; gap:3px; padding:14px 18px; border:1.5px solid var(--line-strong); border-radius:var(--radius-sm); background:var(--card); cursor:pointer; transition:all .15s ease; text-align:left; }
        .spot:hover { border-color:var(--primary); }
        .spot.is-on { border-color:var(--primary); background:var(--primary-soft); }
        .spot-name { font-weight:600; font-size:14.5px; }
        .spot-distance { font-size:12.5px; color:var(--ink-3); }
        .spot.is-on .spot-distance { color:var(--primary); }

        .date-grid { display:flex; gap:11px; flex-wrap:wrap; margin-top:8px; }
        .date-chip { width:76px; padding:14px 0; border:1.5px solid var(--line-strong); border-radius:var(--radius-sm); text-align:center; cursor:pointer; transition:all .15s ease; background:var(--card); }
        .date-chip:hover { border-color:var(--primary); }
        .date-chip.is-on { border-color:var(--primary); background:var(--primary); color:#fff; box-shadow:var(--shadow-primary); }
        .date-chip .mo { font-size:11.5px; text-transform:uppercase; letter-spacing:.05em; color:var(--ink-3); }
        .date-chip.is-on .mo { color:rgba(255,255,255,.8); }
        .date-chip .dy { font-size:21px; font-weight:800; line-height:1.1; }
        .date-chip .wd { font-size:11.5px; color:var(--ink-3); }
        .date-chip.is-on .wd { color:rgba(255,255,255,.8); }

        .slot-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:11px; margin-top:8px; }
        @media (max-width:560px){ .slot-grid { grid-template-columns:1fr; } }
        .slot { padding:14px 16px; border:1.5px solid var(--line-strong); border-radius:var(--radius-sm); background:var(--card); font-size:14px; font-weight:600; color:var(--ink-2); cursor:pointer; transition:all .15s ease; text-align:left; }
        .slot:hover { border-color:var(--primary); color:var(--primary); }
        .slot.is-on { border-color:var(--primary); background:var(--primary-soft); color:var(--primary); }
    </style>
@endpush

<div id="checkout-app" x-data @checkout-step-changed.window="window.scrollTo({ top: 0, behavior: 'smooth' })">
    <header class="top">
        <div class="top-inner">
            <a href="/" class="brand"><span class="glyph">T</span> TruCars</a>
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

        <div class="stepper-mini">
            <div class="smini-track">
                <div class="smini-fill" style="width: {{ round((($stepIndex + 1) / count($steps)) * 100) }}%"></div>
            </div>
            <div class="smini-meta">
                <span class="smini-count">Step {{ $stepIndex + 1 }} of {{ count($steps) }}</span>
                <span class="smini-dot">·</span>
                <span class="smini-step">{{ $steps[$stepIndex]['label'] }}</span>
            </div>
        </div>
    </header>

    <main class="layout {{ $this->stepKey() === 'done' ? 'layout-solo' : '' }}">
        <div class="content">
            @if ($this->stepKey() === 'trade')

                @if ($wantsTrade !== true || $tradeSubStep === 'choice')
                    <div class="step-meta">Step 1 · Your trade</div>
                    <h1 class="h1">Got a car to trade?</h1>
                    <p class="lede">Add it now and roll its value into your deal — lowering your price and the tax you pay. We'll give you a preliminary estimate today; the dealership confirms the final number after inspecting it.</p>

                    <button type="button" class="choice {{ $wantsTrade === true ? 'is-on' : '' }}" wire:click="setWantsTrade(true)">
                        <span class="radio"></span>
                        <div><div class="choice-title">Yes, value my car</div><div class="choice-sub">A few quick questions — preliminary estimate in about a minute</div></div>
                    </button>
                    <button type="button" class="choice {{ $wantsTrade === false ? 'is-on' : '' }}" wire:click="setWantsTrade(false)">
                        <span class="radio"></span>
                        <div><div class="choice-title">No trade this time</div><div class="choice-sub">Skip ahead — you can always add one later</div></div>
                    </button>
                    @error('wantsTrade') <p class="field-error">{{ $message }}</p> @enderror

                @elseif ($tradeSubStep === 'details')
                    <div class="step-meta">Step 1 · Your trade</div>
                    <h1 class="h1">Tell us about your trade</h1>
                    <p class="lede">The more you share, the closer your estimate lands to the dealership's final number — no surprises at handover.</p>

                    <div class="trade-grid">
                        <div class="field"><label class="field-label">Year</label><input type="number" class="field-input" wire:model="tradeYear" placeholder="2019">@error('tradeYear') <p class="field-error">{{ $message }}</p> @enderror</div>
                        <div class="field"><label class="field-label">Make</label><input type="text" class="field-input" wire:model="tradeMake" placeholder="Honda">@error('tradeMake') <p class="field-error">{{ $message }}</p> @enderror</div>
                        <div class="field"><label class="field-label">Model</label><input type="text" class="field-input" wire:model="tradeModel" placeholder="CR-V">@error('tradeModel') <p class="field-error">{{ $message }}</p> @enderror</div>
                    </div>

                    <div class="field"><label class="field-label">Trim <span class="muted">(optional)</span></label><input type="text" class="field-input" wire:model="tradeTrim" placeholder="EX-L AWD"></div>

                    <div class="field-row">
                        <div class="field"><label class="field-label">Odometer</label><div class="money-input"><input type="number" wire:model="tradeKilometres" placeholder="64200" inputmode="numeric"><span class="dollar">km</span></div>@error('tradeKilometres') <p class="field-error">{{ $message }}</p> @enderror</div>
                        <div class="field"><label class="field-label">Exterior colour <span class="muted">(optional)</span></label><input type="text" class="field-input" wire:model="tradeExteriorColour" placeholder="Lunar Silver Metallic">@error('tradeExteriorColour') <p class="field-error">{{ $message }}</p> @enderror</div>
                    </div>

                    <div class="field">
                        <label class="field-label">How many keys do you have?</label>
                        <div class="seg">
                            @foreach (['2' => '2 keys', '1' => '1 key'] as $value => $label)
                                <button type="button" class="seg-btn {{ $tradeKeyCount === $value ? 'is-active' : '' }}" wire:click="$set('tradeKeyCount', '{{ $value }}')">{{ $label }}</button>
                            @endforeach
                        </div>
                    </div>

                    <div class="field">
                        <label class="field-label">Features &amp; options <span class="muted" style="font-weight:400;">— tap all that apply</span></label>
                        <div class="chips">
                            @foreach ($tradeFeatureOptions as $featureSlug => $featureLabel)
                                <button type="button" class="chip {{ $tradeFeatures[$featureSlug] ? 'is-on' : '' }}" wire:click="toggleTradeFeature('{{ $featureSlug }}')">{{ $tradeFeatures[$featureSlug] ? '✓ ' : '' }}{{ $featureLabel }}</button>
                            @endforeach
                        </div>
                    </div>

                @elseif ($tradeSubStep === 'condition')
                    <div class="step-meta">Step 1 · Your trade</div>
                    <h1 class="h1">How's it holding up?</h1>
                    <p class="lede">Honest answers keep your estimate realistic — the dealership confirms everything when they inspect the car.</p>

                    <div class="section-label">Condition</div>
                    <div class="field">
                        <label class="field-label">Exterior — paint, body, rust</label>
                        <div class="seg seg-wrap">
                            @foreach (['excellent' => 'Excellent', 'good' => 'Good', 'fair' => 'Fair', 'poor' => 'Poor'] as $value => $label)
                                <button type="button" class="seg-btn {{ $tradeExteriorCondition === $value ? 'is-active' : '' }}" wire:click="$set('tradeExteriorCondition', '{{ $value }}')">{{ $label }}</button>
                            @endforeach
                        </div>
                    </div>
                    <div class="field">
                        <label class="field-label">Interior — seats, trim, electronics</label>
                        <div class="seg seg-wrap">
                            @foreach (['excellent' => 'Excellent', 'good' => 'Good', 'fair' => 'Fair', 'poor' => 'Poor'] as $value => $label)
                                <button type="button" class="seg-btn {{ $tradeInteriorCondition === $value ? 'is-active' : '' }}" wire:click="$set('tradeInteriorCondition', '{{ $value }}')">{{ $label }}</button>
                            @endforeach
                        </div>
                    </div>
                    <div class="field">
                        <label class="field-label">Tires</label>
                        <div class="seg seg-wrap">
                            @foreach (['new' => 'Like new', 'good' => 'Good', 'worn' => 'Worn'] as $value => $label)
                                <button type="button" class="seg-btn {{ $tradeTireCondition === $value ? 'is-active' : '' }}" wire:click="$set('tradeTireCondition', '{{ $value }}')">{{ $label }}</button>
                            @endforeach
                        </div>
                    </div>
                    <div class="field">
                        <label class="field-label">Mechanical &amp; warning lights</label>
                        <div class="seg seg-wrap">
                            @foreach (['perfect' => 'Flawless', 'good' => 'Good', 'minor' => 'Minor issues', 'warning' => 'Warning lights'] as $value => $label)
                                <button type="button" class="seg-btn {{ $tradeMechanicalCondition === $value ? 'is-active' : '' }}" wire:click="$set('tradeMechanicalCondition', '{{ $value }}')">{{ $label }}</button>
                            @endforeach
                        </div>
                    </div>

                    <div class="section-label">History &amp; ownership</div>
                    <p class="section-note">Your answers here, including accident history, are your own disclosure — the dealership verifies them at inspection.</p>
                    <div class="field">
                        <label class="field-label">Any reported accidents or damage?</label>
                        <div class="seg seg-wrap">
                            @foreach (['none' => 'None', 'minor' => 'Minor', 'major' => 'Major'] as $value => $label)
                                <button type="button" class="seg-btn {{ $tradeAccidentHistory === $value ? 'is-active' : '' }}" wire:click="$set('tradeAccidentHistory', '{{ $value }}')">{{ $label }}</button>
                            @endforeach
                        </div>
                    </div>
                    <div class="field-row">
                        <div class="field">
                            <label class="field-label">Number of owners</label>
                            <div class="seg seg-wrap">
                                @foreach (['1' => '1', '2' => '2', '3+' => '3+'] as $value => $label)
                                    <button type="button" class="seg-btn {{ $tradeOwnerCount === $value ? 'is-active' : '' }}" wire:click="$set('tradeOwnerCount', '{{ $value }}')">{{ $label }}</button>
                                @endforeach
                            </div>
                        </div>
                        <div class="field">
                            <label class="field-label">Title status</label>
                            <div class="seg seg-wrap">
                                @foreach (['clean' => 'Clean', 'rebuilt' => 'Rebuilt'] as $value => $label)
                                    <button type="button" class="seg-btn {{ $tradeTitleStatus === $value ? 'is-active' : '' }}" wire:click="$set('tradeTitleStatus', '{{ $value }}')">{{ $label }}</button>
                                @endforeach
                            </div>
                        </div>
                    </div>
                    <div class="field-row">
                        <div class="field">
                            <label class="field-label">Smoked in?</label>
                            <div class="seg seg-wrap">
                                @foreach (['no' => 'No', 'yes' => 'Yes'] as $value => $label)
                                    <button type="button" class="seg-btn {{ $tradeWasSmokedIn === $value ? 'is-active' : '' }}" wire:click="$set('tradeWasSmokedIn', '{{ $value }}')">{{ $label }}</button>
                                @endforeach
                            </div>
                        </div>
                        <div class="field">
                            <label class="field-label">Pets transported?</label>
                            <div class="seg seg-wrap">
                                @foreach (['no' => 'No', 'yes' => 'Yes'] as $value => $label)
                                    <button type="button" class="seg-btn {{ $tradeCarriedPets === $value ? 'is-active' : '' }}" wire:click="$set('tradeCarriedPets', '{{ $value }}')">{{ $label }}</button>
                                @endforeach
                            </div>
                        </div>
                    </div>
                    <div class="field">
                        <label class="field-label">Aftermarket modifications?</label>
                        <div class="seg seg-wrap">
                            @foreach (['no' => 'No', 'yes' => 'Yes'] as $value => $label)
                                <button type="button" class="seg-btn {{ $tradeHasAftermarketMods === $value ? 'is-active' : '' }}" wire:click="$set('tradeHasAftermarketMods', '{{ $value }}')">{{ $label }}</button>
                            @endforeach
                        </div>
                    </div>

                    <div class="section-label">Money still owing</div>
                    <div class="field"><label class="field-label">Outstanding loan or lease balance <span class="muted">(optional)</span></label><div class="money-input"><span class="dollar">$</span><input type="number" wire:model="tradeLienOwing" placeholder="0" inputmode="numeric"></div>@error('tradeLienOwing') <p class="field-error">{{ $message }}</p> @enderror</div>
                    <p class="section-note">Still owe something? No problem — the dealership pays off your lender directly and applies whatever equity is left to your {{ $vehicle->model }}.</p>

                    <div class="field"><label class="field-label">Anything else the dealer should know? <span class="muted">(optional)</span></label><textarea class="field-input" rows="3" wire:model="tradeNotes" placeholder="New tires last spring, one small door ding…"></textarea>@error('tradeNotes') <p class="field-error">{{ $message }}</p> @enderror</div>

                @elseif ($tradeSubStep === 'estimate')
                    <div class="step-meta">Step 1 · Your trade</div>
                    <h1 class="h1">Here's your preliminary range</h1>
                    <p class="lede">Based on what you've told us about your {{ $tradeYear }} {{ $tradeMake }} {{ $tradeModel }}. This is preliminary and non-binding — your final offer comes after the dealership inspects the car.</p>

                    <div class="offer-reveal">
                        <div class="offer-pill">Preliminary — not a firm offer</div>
                        <div class="offer-amount">{{ $this->tradeEstimateRangeLabel() }}</div>
                        <p class="muted" style="margin-top:10px;">{{ number_format((int) $tradeKilometres) }} km · {{ $tradeExteriorCondition }} exterior · {{ $tradeAccidentHistory === 'none' ? 'no reported accidents' : $tradeAccidentHistory . ' accident reported' }}</p>
                    </div>

                    <div class="card" style="padding:18px 20px;margin-top:8px;">
                        <div style="font-weight:700;font-size:14px;margin-bottom:10px;">What shaped your estimate</div>
                        @foreach ($tradeEstimateLines as $line)
                            <div style="display:flex;justify-content:space-between;font-size:13.5px;padding:4px 0;{{ $line['isBase'] ? 'border-bottom:1px solid var(--line);margin-bottom:4px;padding-bottom:8px;' : '' }}">
                                <span style="color:var(--ink-2);">{{ $line['label'] }}</span>
                                <span style="font-weight:600;color:{{ $line['isBase'] ? 'var(--ink)' : ($line['amountInCents'] > 0 ? 'var(--good)' : 'var(--ink-2)') }};">{{ $line['isBase'] ? '' : ($line['amountInCents'] > 0 ? '+' : '−') }}{{ $this->centsAsMoney(abs($line['amountInCents'])) }}</span>
                            </div>
                        @endforeach
                    </div>

                    @if ($this->tradeLienInCents() > 0)
                        <div class="card" style="padding:18px 20px;margin-top:12px;background:var(--primary-soft);border-color:var(--primary-line);">
                            <div style="display:flex;justify-content:space-between;font-size:13.5px;padding:3px 0;"><span class="muted">The dealership pays off your loan</span><span style="font-weight:600;">−{{ $this->centsAsMoney($this->tradeLienInCents()) }}</span></div>
                            <div style="display:flex;justify-content:space-between;font-size:15px;padding:6px 0 0;font-weight:800;"><span>Estimated equity toward your {{ $vehicle->model }}</span><span>{{ $this->centsAsMoney(max(0, $tradeEstimateLowInCents - $this->tradeLienInCents())) }} – {{ $this->centsAsMoney(max(0, $tradeEstimateHighInCents - $this->tradeLienInCents())) }}</span></div>
                        </div>
                    @endif

                    <p class="section-note" style="margin-top:16px;">These figures are preliminary estimates built from your self-reported answers, including accident history. Nothing here is binding — the dealership inspects your vehicle and confirms a written offer before anything is final.</p>

                    <div style="text-align:center;margin-top:16px;"><button type="button" class="text-link subtle" wire:click="removeTradeIn">Not now, maybe later</button></div>
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
                            <div class="money-input"><span class="dollar">$</span><input type="number" wire:model.live.debounce.400ms="downPayment" placeholder="0" inputmode="numeric"></div>
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

                <div class="section-label">Pick your safety net</div>
                <p class="section-note">Every plan starts with your fully refundable deposit and the dealership's certified warranty. Step up for longer cover and fewer surprise bills. Extended coverage is optional and never required to buy — pick a tier to flag your interest, and the dealership's F&amp;I office reviews availability and pricing with you later. Nothing is added to your reservation today.</p>

                <div class="plan-grid">
                    @foreach ($warrantyOptions as $key => $option)
                        <button type="button" class="plan {{ $warrantyPlan === $key ? 'is-on' : '' }}" wire:click="selectWarranty('{{ $key }}')">
                            @if ($option['popular'])<span class="plan-badge">★ Most chosen</span>@endif
                            <div class="plan-name">{{ $option['name'] }}</div>
                            <div class="plan-tag">{{ $option['tag'] }}</div>
                            <ul class="plan-feats">
                                @foreach ($option['feats'] as $feat)
                                    <li class="{{ $feat['included'] ? '' : 'off' }}">
                                        @if ($feat['included'])
                                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--good)" stroke-width="2.6"><path d="m5 12 4.5 4.5L19 7"/></svg>
                                        @else
                                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--ink-3)" stroke-width="2.2"><path d="M6 6l12 12M18 6 6 18"/></svg>
                                        @endif
                                        {{ $feat['label'] }}
                                    </li>
                                @endforeach
                            </ul>
                            <span class="plan-pick">{{ $warrantyPlan === $key ? 'Selected ✓' : 'Choose ' . $option['name'] }}</span>
                        </button>
                    @endforeach
                </div>

                <div class="compare-wrap" x-data="{ open: false }">
                    <button type="button" class="text-link" x-on:click="open = !open">
                        <span x-show="!open">See the full feature comparison ↓</span>
                        <span x-show="open" x-cloak>Hide the comparison ↑</span>
                    </button>
                    <div class="compare-table" x-show="open" x-cloak>
                        <div class="ct-row head">
                            <div>Feature</div>
                            @foreach ($warrantyOptions as $option)
                                <div>{{ $option['name'] }}</div>
                            @endforeach
                        </div>
                        @foreach ($comparisonRows as $row)
                            <div class="ct-row">
                                <div>{{ $row['label'] }}</div>
                                @foreach ($row['values'] as $value)
                                    <div>
                                        @if (is_bool($value))
                                            @if ($value)
                                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--good)" stroke-width="2.6"><path d="m5 12 4.5 4.5L19 7"/></svg>
                                            @else
                                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--ink-3)" stroke-width="2.2"><path d="M6 6l12 12M18 6 6 18"/></svg>
                                            @endif
                                        @else
                                            {{ $value }}
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                </div>

                <div style="margin-top:16px;">
                    <button type="button" class="text-link subtle" wire:click="selectWarranty(null)">No added coverage</button>
                </div>

            @elseif ($this->stepKey() === 'extras')
                <div class="step-meta">Step 4 · Extras</div>
                <h1 class="h1">Want a little extra armour?</h1>
                <p class="lede">All optional. Flag what gives you peace of mind, skip the rest. Every figure here is an illustrative biweekly estimate — the dealership's F&amp;I office confirms what's available and the real price. Nothing is added to your reservation today.</p>

                <div class="gap-hero">
                    <div class="gap-hero-top">
                        <div class="ic"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3 4 6v6c0 5 8 9 8 9s8-4 8-9V6Z"/></svg></div>
                        <div>
                            <h3>Don't get stuck owing on a car you no longer have</h3>
                            <p>Write a financed car off early and a standard insurer only pays today's value — often less than your loan balance. GAP covers that shortfall, so a bad day doesn't become a bad year.</p>
                        </div>
                    </div>
                    <div class="gap-scenario">
                        <div class="gap-scenario-cap">Say it's written off in year 2 —</div>
                        <div class="gap-bar-row">
                            <div class="gap-bar-name">You still owe the lender</div>
                            <div class="gap-bar-track"><div class="gap-bar-fill owe"><span class="gap-bar-amt">{{ $gapScenario['oweLabel'] }}</span></div></div>
                        </div>
                        <div class="gap-bar-row">
                            <div class="gap-bar-name">Standard insurance pays</div>
                            <div class="gap-bar-track">
                                <div class="gap-bar-fill pays" style="width:{{ $gapScenario['insuranceWidth'] }}%;"><span class="gap-bar-amt">{{ $gapScenario['insurancePays'] }}</span></div>
                                <div class="gap-bar-fill gap" style="width:{{ $gapScenario['gapWidth'] }}%;">GAP</div>
                            </div>
                        </div>
                        <div class="gap-scenario-foot"><span class="hatch-dot"></span> GAP pays the <b>{{ $gapScenario['gapCovers'] }}</b> shortfall — you walk away owing nothing.</div>
                    </div>
                    <div class="gap-cta">
                        <span class="price">{{ $this->asMoney($gapBiweekly) }}<span class="per"> /biweekly · est.</span></span>
                        <button type="button" class="btn {{ $wantsGap ? 'btn-soft' : 'btn-primary' }}" wire:click="toggleGap">{{ $wantsGap ? 'Interest flagged ✓ — remove' : 'I’m interested in GAP' }}</button>
                    </div>
                </div>

                <div class="section-label">More you can stack on</div>
                <div class="extras-grid">
                    @foreach ($extrasCatalog as $key => $extra)
                        <button type="button" class="extra {{ in_array($key, $selectedExtras, true) ? 'is-on' : '' }}" wire:click="toggleExtra('{{ $key }}')">
                            <span class="check">
                                @if (in_array($key, $selectedExtras, true))
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="m5 12 4.5 4.5L19 7"/></svg>
                                @endif
                            </span>
                            <div>
                                <div class="extra-name">{{ $extra['name'] }}</div>
                                <div class="extra-desc">{{ $extra['desc'] }}</div>
                            </div>
                            <span class="extra-price">+{{ $this->asMoney($extra['biweekly']) }}</span>
                        </button>
                    @endforeach
                </div>
                <p class="section-note" style="margin-top:18px;">These are estimates to help you weigh your options — they're not priced into your reservation and you're never required to take any of them. The dealership's F&amp;I office confirms what's available and the actual cost with you before anything is final.</p>

            @elseif ($this->stepKey() === 'handover')
                <div class="step-meta">Step 5 · Handover</div>
                <h1 class="h1">How should we get it to you?</h1>
                <p class="lede">Both options are on us. Collect it yourself from a nearby hub, or we'll bring it to your door — keys in hand, paperwork done on the spot.</p>

                <button type="button" class="choice {{ $handoverMode === 'pickup' ? 'is-on' : '' }}" wire:click="setHandoverMode('pickup')">
                    <span class="radio"></span>
                    <div>
                        <div class="choice-title">I'll pick it up <span class="tag-free">FREE</span></div>
                        <div class="choice-sub">{{ count($pickupSpots) }} hubs near you · closest {{ $pickupSpots[0]['distance'] }} · we cover up to $100 of your ride there</div>
                    </div>
                </button>
                <button type="button" class="choice {{ $handoverMode === 'delivery' ? 'is-on' : '' }}" wire:click="setHandoverMode('delivery')">
                    <span class="radio"></span>
                    <div>
                        <div class="choice-title">Bring it to me <span class="tag-free">FREE</span></div>
                        <div class="choice-sub">Dropped at {{ $city !== '' ? $city : 'your address' }}, keys in hand, paperwork done on the spot</div>
                    </div>
                </button>

                @if ($handoverMode === 'pickup')
                    <div class="section-label">Which hub?</div>
                    <div class="spot-grid">
                        @foreach ($pickupSpots as $spotIndex => $spot)
                            <button type="button" class="spot {{ $pickupSpotIndex === $spotIndex ? 'is-on' : '' }}" wire:click="setPickupSpot({{ $spotIndex }})">
                                <span class="spot-name">{{ $spot['name'] }}</span>
                                <span class="spot-distance">{{ $spot['distance'] }} away</span>
                            </button>
                        @endforeach
                    </div>
                @endif

                <div class="section-label">Which day works?</div>
                <div class="date-grid">
                    @foreach ($this->handoverDateOptions() as $dateOption)
                        <button type="button" class="date-chip {{ $handoverDate === $dateOption['date'] ? 'is-on' : '' }}" wire:click="setHandoverDate('{{ $dateOption['date'] }}')">
                            <div class="mo">{{ $dateOption['month'] }}</div>
                            <div class="dy">{{ $dateOption['day'] }}</div>
                            <div class="wd">{{ $dateOption['weekday'] }}</div>
                        </button>
                    @endforeach
                </div>
                @error('handoverDate') <p class="field-error">{{ $message }}</p> @enderror

                <div class="section-label">Pick a time window</div>
                <div class="slot-grid">
                    @foreach ($handoverTimeSlots as $slotKey => $slotLabel)
                        <button type="button" class="slot {{ $handoverTimeSlot === $slotKey ? 'is-on' : '' }}" wire:click="setHandoverTimeSlot('{{ $slotKey }}')">{{ $slotLabel }}</button>
                    @endforeach
                </div>
                <p class="section-note" style="margin-top:16px;">Your window is a request — the dealership confirms the exact time when they reach out. Plenty of room to reschedule before the day.</p>

            @elseif ($this->stepKey() === 'id')
                <div class="step-meta" style="text-align:center;">Step 6 · ID check</div>
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
                <div class="step-meta">Step 7 · Review</div>
                <h1 class="h1">The final once-over</h1>
                <p class="lede">Give it a glance. Anything to change? Hit edit. All good? Let's hold your {{ $vehicle->make }} {{ $vehicle->model }}.</p>

                <div class="rev-card">
                    <div>
                        <div class="rev-label">Trade-in</div>
                        <div class="rev-value">{{ $this->tradeSummaryLabel() }}</div>
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
                        <div class="rev-value">{{ $purchaseType === 'finance' ? 'Finance · ' . $termMonths . ' mo · ' . $this->asMoney($downPayment) . ' down · est. ' . $this->asMoney($this->estimatedBiweekly) . '/biweekly' : 'Cash · ' . $this->asMoney($this->priceDollars) }}{{ $warrantyPlan ? ' · ' . $warrantyOptions[$warrantyPlan]['name'] . ' coverage interest' : '' }}</div>
                    </div>
                    <button type="button" class="text-link" wire:click="jumpTo(2)">Edit</button>
                </div>
                <div class="rev-card">
                    <div>
                        <div class="rev-label">Extras</div>
                        <div class="rev-value">{{ ($wantsGap || count($selectedExtras) > 0) ? collect($this->coverageInterestLabels())->reject(fn ($label) => str_ends_with($label, ' plan'))->join(', ') . ' · interest only' : 'None' }}</div>
                    </div>
                    <button type="button" class="text-link" wire:click="jumpTo(3)">Edit</button>
                </div>
                <div class="rev-card">
                    <div>
                        <div class="rev-label">Handover</div>
                        <div class="rev-value">{{ $this->handoverSummaryLabel() }}</div>
                    </div>
                    <button type="button" class="text-link" wire:click="jumpTo(4)">Edit</button>
                </div>
                <div class="rev-card">
                    <div>
                        <div class="rev-label">Identity</div>
                        <div class="rev-value">{{ $identityVerified ? 'Verified' : 'Not verified' }}</div>
                    </div>
                    <button type="button" class="text-link" wire:click="jumpTo(5)">Edit</button>
                </div>

            @elseif ($this->stepKey() === 'reserve')
                <div class="step-meta">Step 8 · Reserve</div>
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
                        <div class="tn-step"><span class="tn-n">3</span><div><div class="tn-title">Track it all in My Garage</div><div class="muted tn-sub">Live deal status, your documents, and a direct line to the dealership.</div></div></div>
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

                        <div class="rail-breakdown" x-data="{ open: false }">
                            <button type="button" class="rail-breakdown-toggle" x-on:click="open = !open" :aria-expanded="open">
                                <span class="rbt-head">
                                    <span class="rbt-label">{{ $purchaseType === 'finance' ? 'Estimated payment' : 'Pay in full' }}</span>
                                    <span class="rbt-amt">
                                        @if ($purchaseType === 'finance')
                                            {{ $this->asMoney($this->estimatedBiweekly) }}<span class="per"> /biweekly</span>
                                        @else
                                            {{ $this->asMoney($this->priceDollars) }}
                                        @endif
                                    </span>
                                </span>
                                <svg class="rbt-chevron" :class="{ 'is-open': open }" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="m6 9 6 6 6-6"/></svg>
                            </button>

                            <div class="rail-breakdown-body" x-show="open" x-collapse x-cloak>
                                @if ($purchaseType === 'finance')
                                    <div class="rail-group">
                                        <div class="rail-line head"><span>Vehicle &amp; financing</span><span>{{ $this->asMoney($this->paymentBreakdown['vehicleBiweekly']) }}</span></div>
                                        <div class="rail-line sub"><span>{{ $termMonths }}-month term (est.)</span><span></span></div>
                                        <div class="rail-line sub"><span>Rate confirms after submission</span><span>est.</span></div>
                                        @if ((float) $downPayment > 0)
                                            <div class="rail-line credit"><span>Down payment</span><span>−{{ $this->asMoney($downPayment) }}</span></div>
                                        @endif
                                    </div>

                                    @if (count($this->paymentBreakdown['includedFees']) > 0)
                                        <div class="rail-group">
                                            <div class="rail-included">Inside your all-in price</div>
                                            @foreach ($this->paymentBreakdown['includedFees'] as $includedFee)
                                                <div class="rail-line sub"><span>{{ $includedFee['label'] }}</span><span>included</span></div>
                                            @endforeach
                                        </div>
                                    @endif

                                    <div class="rail-group">
                                        <div class="rail-line head"><span>HST (13%)</span><span>{{ $this->asMoney($this->paymentBreakdown['taxesBiweekly']) }}</span></div>
                                    </div>

                                    @if (count($this->paymentBreakdown['passThroughFees']) > 0)
                                        <div class="rail-group">
                                            <div class="rail-included">Added at delivery — at cost</div>
                                            @foreach ($this->paymentBreakdown['passThroughFees'] as $passThroughFee)
                                                <div class="rail-line sub"><span>{{ $passThroughFee['label'] }}</span><span>{{ $this->asMoney($passThroughFee['amount']) }}</span></div>
                                            @endforeach
                                        </div>
                                    @endif

                                    <div class="rail-total">
                                        <span class="lbl">Est. payment</span>
                                        <span class="amt">{{ $this->asMoney($this->estimatedBiweekly) }}<span class="per"> /biweekly</span></span>
                                    </div>
                                @else
                                    <div class="rail-group">
                                        <div class="rail-line head"><span>All-in price</span><span>{{ $this->asMoney($this->priceDollars) }}</span></div>
                                        @if (count($this->paymentBreakdown['includedFees']) > 0)
                                            <div class="rail-included">Inside your all-in price</div>
                                            @foreach ($this->paymentBreakdown['includedFees'] as $includedFee)
                                                <div class="rail-line sub"><span>{{ $includedFee['label'] }}</span><span>included</span></div>
                                            @endforeach
                                        @endif
                                    </div>

                                    @if (count($this->paymentBreakdown['passThroughFees']) > 0)
                                        <div class="rail-group">
                                            <div class="rail-included">Added at delivery — at cost</div>
                                            @foreach ($this->paymentBreakdown['passThroughFees'] as $passThroughFee)
                                                <div class="rail-line sub"><span>{{ $passThroughFee['label'] }}</span><span>{{ $this->asMoney($passThroughFee['amount']) }}</span></div>
                                            @endforeach
                                        </div>
                                    @endif

                                    <div class="rail-total">
                                        <span class="lbl">Total</span>
                                        <span class="amt">{{ $this->asMoney($this->priceDollars) }}</span>
                                    </div>
                                @endif
                            </div>
                        </div>

                        @if ($wantsTrade === true || $warrantyPlan || $wantsGap || count($selectedExtras) > 0)
                            <div class="rail-group">
                                @if ($wantsTrade === true)
                                    <div class="rail-line sub"><span>Trade-in (preliminary)</span><span>{{ $this->tradeEstimateRangeLabel() ?? 'Appraised by dealer' }}</span></div>
                                @endif
                                @if ($warrantyPlan)
                                    <div class="rail-line sub"><span>Coverage interest</span><span>{{ $warrantyOptions[$warrantyPlan]['name'] }}</span></div>
                                @endif
                                @if ($wantsGap)
                                    <div class="rail-line sub"><span>GAP protection</span><span>Interest only</span></div>
                                @endif
                                @foreach ($selectedExtras as $extraKey)
                                    <div class="rail-line sub"><span>{{ $extrasCatalog[$extraKey]['name'] }}</span><span>Interest only</span></div>
                                @endforeach
                            </div>
                        @endif

                        <div class="rail-due">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 8v5l3 2"/></svg>
                            <span>Due today: <b>$150</b> — refundable, credited to your total</span>
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
            @if ($this->canGoBack() && $this->stepKey() !== 'done')
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
                    <a href="{{ route('garage') }}" wire:navigate class="btn btn-primary btn-lg">Open My Garage →</a>
                @else
                    <button type="button" class="btn btn-primary btn-lg" wire:click="goNext" @disabled(! $this->canAdvance())>{{ $this->ctaLabel() }} →</button>
                @endif
            </div>
        </div>
    </div>
</div>
