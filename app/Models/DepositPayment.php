<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A $150 reservation deposit taken through Stripe — see App\Support\Deposits. */
class DepositPayment extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_REFUNDED = 'refunded';
    public const STATUS_CANCELED = 'canceled';

    protected $fillable = ['user_id', 'vehicle_id', 'stripe_payment_intent_id', 'amount_in_cents', 'currency'];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    protected function casts(): array
    {
        return [
            'paid_at'     => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    /** Taken in demo mode — no card was charged, nothing to refund at Stripe. */
    public function isDemo(): bool
    {
        return str_starts_with($this->stripe_payment_intent_id, 'demo_');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class)->withoutGlobalScopes();
    }
}
