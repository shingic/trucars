<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A checkout a shopper started but hasn't paid the deposit on. The shopper
 * belongs to the platform until they pay: this is only ever shown in the
 * Command Centre, never to a dealer. Paying turns it into a Deal (status
 * "reserved"), and that's the moment the dealer gets the customer.
 */
class CheckoutDraft extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_RESERVED = 'reserved';   // they paid: it's a deal now
    public const STATUS_CAR_GONE = 'car_gone';   // someone else reserved the car

    /** Checkout step keys, as the buyer saw them. */
    public const STEP_LABELS = [
        'trade' => 'Trade-in', 'buyer' => 'About you', 'plan' => 'Plan', 'extras' => 'Extras',
        'handover' => 'Pickup', 'id' => 'ID check', 'review' => 'Review', 'reserve' => 'Reserve (payment)',
    ];

    protected $fillable = ['user_id', 'vehicle_id', 'step_key', 'data', 'shopper_location', 'status', 'deal_id', 'staff_note', 'last_active_at'];

    protected function casts(): array
    {
        return [
            'data'             => 'array',
            'shopper_location' => 'array',
            'last_active_at'   => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class)->withoutGlobalScopes();
    }

    public function emails(): HasMany
    {
        return $this->hasMany(MarketingEmail::class)->orderBy('sent_at');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE);
    }

    public function stepLabel(): string
    {
        return self::STEP_LABELS[$this->step_key] ?? ucfirst($this->step_key);
    }

    /** The phone they typed, if they got as far as "About you". */
    public function phone(): ?string
    {
        return filled($this->data['phone'] ?? null) ? (string) $this->data['phone'] : null;
    }

    /** One click back to where they left off (they sign in first if needed). */
    public function resumeUrl(): string
    {
        return route('checkout', ['vehicle' => $this->vehicle_id]);
    }
}
