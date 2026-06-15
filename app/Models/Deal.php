<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\DealerScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[ScopedBy(DealerScope::class)]
class Deal extends Model
{
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
        'dealer_id', 'user_id', 'vehicle_id', 'stage',
        'purchase_type', 'term_months', 'down_payment_in_cents', 'warranty_plan', 'extras_interest', 'fees_snapshot',
        'deposit_in_cents', 'deposit_status',
        'first_name', 'last_name', 'email', 'phone',
        'street_address', 'city', 'province', 'postal_code',
        'handover_mode', 'pickup_location', 'pickup_at',
        'identity_verified_at',
    ];

    protected $attributes = [
        'stage'            => 'reserved',
        'deposit_in_cents' => 15000,
        'deposit_status'   => 'held',
    ];

    protected function casts(): array
    {
        return [
            'identity_verified_at' => 'datetime',
            'pickup_at'            => 'datetime',
            'extras_interest'      => 'array',
            'fees_snapshot'        => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Deal $deal) {
            $deal->reference ??= self::freshReference();
        });

        // Every new deal gets the canonical four documents up front so the
        // buyer always sees the full checklist in My Garage. The driver's
        // licence arrives pre-cleared when identity was verified at reserve;
        // the rest start pending. Created (not creating) so the deal has an id.
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

    public function getCustomerFullNameAttribute(): string
    {
        return trim($this->first_name . ' ' . $this->last_name);
    }

    public function getStageLabelAttribute(): string
    {
        return self::STAGE_LABELS[$this->stage] ?? ucfirst($this->stage);
    }

    /** One-line handover summary for the reservations inbox and deal view. */
    public function getHandoverSummaryAttribute(): string
    {
        $modeLabel = $this->handover_mode === 'delivery' ? 'Delivery' : 'Pickup';
        $where = $this->pickup_location ? ' · ' . $this->pickup_location : '';
        $when = $this->pickup_at ? ' · ' . $this->pickup_at->format('D M j, g:i a') : '';

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
