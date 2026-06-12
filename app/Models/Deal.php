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
        'dealer_id', 'vehicle_id', 'stage',
        'purchase_type', 'term_months', 'down_payment_in_cents', 'warranty_plan',
        'deposit_in_cents', 'deposit_status',
        'first_name', 'last_name', 'email', 'phone',
        'street_address', 'city', 'province', 'postal_code',
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
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Deal $deal) {
            $deal->reference ??= self::freshReference();
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

    public function tradeIn(): HasOne
    {
        return $this->hasOne(DealTradeIn::class);
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
}
