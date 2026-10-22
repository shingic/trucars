<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DealDocument extends Model
{
    public const INSURANCE = 'proof_of_insurance';

    protected $fillable = [
        'deal_id', 'slug', 'name', 'status', 'is_done', 'sort_order',
        'file_path', 'uploaded_at', 'requested_at',
    ];

    protected $attributes = [
        'is_done'    => false,
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'is_done'      => 'boolean',
            'uploaded_at'  => 'datetime',
            'requested_at' => 'datetime',
        ];
    }

    /**
     * The documents a deal needs, in display order. They're asked for in two
     * rounds so the buyer isn't hit with everything at once:
     *
     *   1. At reserve: pay stubs and a void cheque, for the lender. A cash
     *      buyer has no lender, so they skip this round entirely.
     *   2. Once the deal is finalized (the dealer moves it to Documents):
     *      proof of insurance, needed before the car leaves the lot.
     *
     * The driver's licence arrives pre-cleared when identity was verified at
     * reserve. requested_at is when the buyer was asked; null means not yet.
     *
     * @return array<int, array{slug:string, name:string, status:string, is_done:bool, requested_at:?\Illuminate\Support\Carbon}>
     */
    public static function blueprintFor(Deal $deal): array
    {
        $licenceVerified = $deal->identity_verified_at !== null;
        $borrowing = $deal->purchase_type !== 'cash';

        return array_values(array_filter([
            [
                'slug'         => 'driver_licence',
                'name'         => "Driver's licence",
                'status'       => $licenceVerified ? 'Verified' : 'Pending verification',
                'is_done'      => $licenceVerified,
                'requested_at' => now(),
            ],
            $borrowing ? [
                'slug'         => 'proof_of_income',
                'name'         => 'Recent pay stubs',
                'status'       => 'Your last two. One PDF or photo with both is fine',
                'is_done'      => false,
                'requested_at' => now(),
            ] : null,
            $borrowing ? [
                'slug'         => 'void_cheque',
                'name'         => 'Void cheque',
                'status'       => 'For your car payments',
                'is_done'      => false,
                'requested_at' => now(),
            ] : null,
            [
                'slug'         => self::INSURANCE,
                'name'         => 'Proof of insurance',
                'status'       => "We'll ask once your deal is finalized",
                'is_done'      => false,
                'requested_at' => null,
            ],
        ]));
    }

    /** Asked for, and not yet in (never uploaded, or sent back for a re-upload). */
    public function scopeOutstanding(Builder $query): void
    {
        $query->whereNotNull('requested_at')->where('is_done', false);
    }

    public function isRequested(): bool
    {
        return $this->requested_at !== null;
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }
}
