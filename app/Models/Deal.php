<?php

declare(strict_types=1);

namespace App\Models;

use App\Mail\InsuranceRequested;
use App\Mail\ReadyForHandover;
use App\Models\Concerns\CarriesCampaignAttribution;
use App\Models\Scopes\DealerScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

#[ScopedBy(DealerScope::class)]
class Deal extends Model
{
    use CarriesCampaignAttribution;

    public const STAGE_LABELS = [
        'reserved'           => 'New reservation',
        'contacted'          => 'Contacted',
        'appointment_set'    => 'Appointment set',
        'financing'          => 'Financing',
        'documents'          => 'Documents',
        'ready_for_delivery' => 'Ready for delivery',
        'delivered'          => 'Delivered',
        'cancelled'          => 'Cancelled',
    ];

    public const NEXT_STAGE = [
        'reserved'           => 'contacted',
        'contacted'          => 'appointment_set',
        'appointment_set'    => 'financing',
        'financing'          => 'documents',
        'documents'          => 'ready_for_delivery',
        'ready_for_delivery' => 'delivered',
    ];

    protected $fillable = [
        'dealer_id', 'rooftop_id', 'user_id', 'vehicle_id', 'campaign_id', 'attribution', 'stage',
        'purchase_type', 'term_months', 'down_payment_in_cents', 'warranty_plan', 'extras_interest', 'fees_snapshot', 'offers_snapshot', 'dealer_snapshot', 'rooftop_snapshot',
        'deposit_in_cents', 'deposit_status',
        'first_name', 'last_name', 'email', 'phone',
        'street_address', 'city', 'province', 'postal_code',
        'handover_mode', 'pickup_location', 'pickup_at',
        'identity_verified_at',
        'financing_lender', 'financing_apr', 'financing_term_months', 'financing_payment_in_cents', 'financing_frequency',
        'financing_amount_in_cents', 'financing_down_payment_in_cents', 'financing_first_payment_on', 'financing_approved_at',
    ];

    protected $attributes = [
        'stage'            => 'reserved',
        'deposit_in_cents' => 15000,
        'deposit_status'   => 'held',
    ];

    protected function casts(): array
    {
        return [
            'identity_verified_at'       => 'datetime',
            'documents_reminded_at'      => 'datetime',
            'financing_apr'              => 'decimal:2',
            'financing_first_payment_on' => 'date',
            'financing_approved_at'      => 'datetime',
            'pickup_at'                  => 'datetime',
            'extras_interest'            => 'array',
            'fees_snapshot'              => 'array',
            'offers_snapshot'            => 'array',
            'dealer_snapshot'            => 'array',
            'rooftop_snapshot'           => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Deal $deal) {
            $deal->reference ??= self::freshReference();

            // Freeze the seller as it is right now — name, address, registration,
            // timezone — so later dealer-profile edits never rewrite this record.
            $deal->dealer_snapshot ??= Dealer::find($deal->dealer_id)?->toSnapshot();

            // And the store the car is at, which must be one of the seller's.
            $deal->rooftop_id ??= Vehicle::withoutGlobalScopes()->whereKey($deal->vehicle_id)->value('rooftop_id');
            $rooftop = $deal->rooftop_id ? Rooftop::find($deal->rooftop_id) : null;

            if ($rooftop !== null && (int) $rooftop->dealer_id !== (int) $deal->dealer_id) {
                throw new \DomainException("Rooftop #{$rooftop->id} doesn't belong to dealer #{$deal->dealer_id}.");
            }

            $deal->rooftop_snapshot ??= $rooftop?->toSnapshot();
        });

        // A cancelled reservation puts the car back on sale: ring the bell for
        // everyone waiting on it — once the cancellation has committed.
        static::updated(function (Deal $deal) {
            if ($deal->wasChanged('stage') && $deal->stage === 'cancelled' && $deal->vehicle_id) {
                DB::afterCommit(fn () => ($vehicle = Vehicle::find($deal->vehicle_id)) && VehicleAlert::notifyWaiting($vehicle));
            }

            // Moving to Documents means the deal is finalized: ask for insurance.
            if ($deal->wasChanged('stage') && $deal->stage === 'documents') {
                $deal->requestInsurance();
            }

            if ($deal->wasChanged('stage') && $deal->stage === 'ready_for_delivery') {
                DB::afterCommit(fn () => $deal->emailBuyer(new ReadyForHandover($deal->fresh()), 'Ready-for-handover email'));
            }
        });

        // Every new deal gets its document checklist up front so the buyer sees
        // the whole list in My Garage; DealDocument::blueprintFor decides what's
        // asked for now and what waits. Created (not creating) so the deal has an id.
        static::created(function (Deal $deal) {
            foreach (DealDocument::blueprintFor($deal) as $position => $document) {
                $deal->documents()->create($document + ['sort_order' => $position]);
            }
        });
    }

    public static function freshReference(): string
    {
        // No 0/O/1/I — these codes get read over the phone.
        $friendlyAlphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

        do {
            $code = 'TL-';
            for ($i = 0; $i < 5; $i++) {
                $code .= $friendlyAlphabet[random_int(0, strlen($friendlyAlphabet) - 1)];
            }
        } while (self::withoutGlobalScope(DealerScope::class)->where('reference', $code)->exists());

        return $code;
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function dealer(): BelongsTo
    {
        return $this->belongsTo(Dealer::class);
    }

    /** The store the car is collected from. dealer stays the seller. */
    public function rooftop(): BelongsTo
    {
        return $this->belongsTo(Rooftop::class);
    }

    /** "Downtown store · Kelowna, BC" as frozen at reserve; null before rooftops existed. */
    public function getRooftopLabelAttribute(): ?string
    {
        $store = $this->rooftop_snapshot ?? null;

        if ($store === null) {
            return null;
        }

        return collect([$store['name'] ?? null, collect([$store['city'] ?? null, $store['province'] ?? null])->filter()->implode(', ')])
            ->filter()->implode(' · ') ?: null;
    }

    /** The buyer account that placed this reservation. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tradeIn(): HasOne
    {
        return $this->hasOne(DealTradeIn::class);
    }

    /** The four required documents, in display order for the My Garage card. */
    public function documents(): HasMany
    {
        return $this->hasMany(DealDocument::class)->orderBy('sort_order');
    }

    /** The $150 card deposit behind this reservation (none on older deals). */
    public function depositPayment(): HasOne
    {
        return $this->hasOne(DepositPayment::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(DealActivity::class);
    }

    public function recordActivity(string $kind, string $body, ?string $authorName = null, ?string $direction = null): DealActivity
    {
        return $this->activities()->create([
            'kind'        => $kind,
            'body'        => $body,
            'author_name' => $authorName,
            'direction'   => $direction,
        ]);
    }

    /**
     * Unlock the proof-of-insurance upload and email the buyer for it. Runs
     * once: a deal that already asked (or already has the file) is left alone.
     */
    public function requestInsurance(): void
    {
        $insurance = $this->documents()->where('slug', DealDocument::INSURANCE)->whereNull('requested_at')->first();

        if ($insurance === null) {
            return;
        }

        $insurance->update(['requested_at' => now(), 'status' => 'Needed before you take the car home']);

        DB::afterCommit(fn () => $this->emailBuyer(new InsuranceRequested($this->fresh()), 'Proof-of-insurance request'));
    }

    /**
     * Send the buyer one of their deal emails. Never throws: a mail outage
     * can't undo a stage change, so a failure is logged and noted on the
     * deal for the dealership to follow up instead.
     */
    public function emailBuyer(Mailable $mail, string $what): bool
    {
        try {
            Mail::to($this->email)->send($mail);
            $this->recordActivity('email', "{$what} emailed to {$this->email}.", null, 'outbound');

            return true;
        } catch (\Throwable $sendFailure) {
            Log::error('Buyer deal email failed to send.', [
                'deal_reference' => $this->reference,
                'email'          => $what,
                'error'          => $sendFailure->getMessage(),
            ]);
            $this->recordActivity('system', "{$what} could not be emailed to {$this->email}. The buyer may need a call.");

            return false;
        }
    }

    /** How often an approved loan or lease is paid, as the lender words it. */
    public const PAYMENT_FREQUENCIES = [
        'weekly'       => 'Weekly',
        'biweekly'     => 'Biweekly',
        'semi_monthly' => 'Semi-monthly',
        'monthly'      => 'Monthly',
    ];

    private const PAYMENTS_PER_YEAR = ['weekly' => 52, 'biweekly' => 26, 'semi_monthly' => 24, 'monthly' => 12];

    /** The lender's approval has been entered by the dealership. */
    public function hasFinancingApproval(): bool
    {
        return $this->financing_approved_at !== null;
    }

    /** "Biweekly", "Monthly" and so on. */
    public function getFinancingFrequencyLabelAttribute(): ?string
    {
        return self::PAYMENT_FREQUENCIES[$this->financing_frequency] ?? null;
    }

    /** How many payments the approved term works out to, e.g. 156 biweekly over 72 months. */
    public function getFinancingPaymentCountAttribute(): ?int
    {
        $perYear = self::PAYMENTS_PER_YEAR[$this->financing_frequency] ?? null;

        return $perYear && $this->financing_term_months ? (int) round($this->financing_term_months / 12 * $perYear) : null;
    }

    /** "2021 Mitsubishi Outlander", for subject lines and copy. */
    public function getVehicleLabelAttribute(): string
    {
        $vehicle = $this->vehicle()->withoutGlobalScopes()->first();

        return $vehicle ? trim("{$vehicle->model_year} {$vehicle->make} {$vehicle->model}") : 'car';
    }

    public function getCustomerFullNameAttribute(): string
    {
        return trim($this->first_name . ' ' . $this->last_name);
    }

    public function getStageLabelAttribute(): string
    {
        return self::STAGE_LABELS[$this->stage] ?? ucfirst($this->stage);
    }

    /** The selling dealer's name as it was at reserve; live name for older deals. */
    public function getSellerNameAttribute(): string
    {
        return $this->dealer_snapshot['name'] ?? $this->dealer?->name ?? 'The dealership';
    }

    /**
     * The zone handover windows were offered in: the rooftop's at reserve time,
     * else the seller's, else today's. pickup_at itself is stored in UTC.
     */
    public function dealerTimezone(): string
    {
        return ($this->rooftop_snapshot['timezone'] ?? null)
            ?: ($this->dealer_snapshot['timezone'] ?? null)
            ?: $this->rooftop?->timezone
            ?: $this->dealer?->localTimezone()
            ?: config('app.timezone');
    }

    /** pickup_at on the dealer's wall clock, for display. */
    public function getLocalPickupAtAttribute(): ?\Illuminate\Support\Carbon
    {
        return $this->pickup_at?->copy()->setTimezone($this->dealerTimezone());
    }

    /** One-line handover summary for the reservations inbox and deal view. */
    public function getHandoverSummaryAttribute(): string
    {
        $modeLabel = $this->handover_mode === 'delivery' ? 'Delivery' : 'Pickup';
        $where = $this->pickup_location ? ' · ' . $this->pickup_location : '';
        $when = $this->local_pickup_at ? ' · ' . $this->local_pickup_at->format('D M j, g:i a T') : '';

        return $modeLabel . $where . $when;
    }

    /**
     * The frozen fee schedule split into the two OMVIC buckets, ready for the
     * deal view to render without re-deriving anything: 'included' fees that
     * sit inside the agreed all-in price, and 'pass_through' charges added at
     * delivery (with their summed total in cents). Empty arrays when the deal
     * predates the snapshot.
     */
    public function getFeesByKindAttribute(): array
    {
        $included = [];
        $passThrough = [];
        $passThroughTotalInCents = 0;

        foreach ($this->fees_snapshot ?? [] as $fee) {
            if (($fee['kind'] ?? null) === DealerFee::KIND_PASS_THROUGH) {
                $passThrough[] = $fee;
                $passThroughTotalInCents += (int) ($fee['amount_in_cents'] ?? 0);
            } else {
                $included[] = $fee;
            }
        }

        return [
            'included'                => $included,
            'passThrough'             => $passThrough,
            'passThroughTotalInCents' => $passThroughTotalInCents,
        ];
    }
}
