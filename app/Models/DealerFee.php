<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\DealerScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single line on a dealer's fee schedule.
 *
 * Two kinds, and the kind is the whole point:
 *  - INCLUDED      — the dealer's own cost (freight, PDI, admin). Under OMVIC
 *                    all-in pricing this is already baked into the advertised
 *                    price, so it's disclosed on the breakdown but adds nothing.
 *  - PASS_THROUGH  — an at-cost government charge (licensing, registration)
 *                    collected at delivery. Shown separately and never financed.
 */
#[ScopedBy(DealerScope::class)]
class DealerFee extends Model
{
    public const KIND_INCLUDED = 'included';
    public const KIND_PASS_THROUGH = 'pass_through';

    public const KIND_LABELS = [
        self::KIND_INCLUDED     => 'Inside the all-in price',
        self::KIND_PASS_THROUGH => 'Added at delivery (at cost)',
    ];

    protected $fillable = [
        'dealer_id',
        'label',
        'kind',
        'amount_in_cents',
        'sort_order',
        'is_active',
    ];

    protected $attributes = [
        'kind'            => self::KIND_INCLUDED,
        'amount_in_cents' => 0,
        'sort_order'      => 0,
        'is_active'       => true,
    ];

    protected function casts(): array
    {
        return [
            'amount_in_cents' => 'integer',
            'sort_order'      => 'integer',
            'is_active'       => 'boolean',
        ];
    }

    public function dealer(): BelongsTo
    {
        return $this->belongsTo(Dealer::class);
    }

    public function getIsPassThroughAttribute(): bool
    {
        return $this->kind === self::KIND_PASS_THROUGH;
    }

    public function getKindLabelAttribute(): string
    {
        return self::KIND_LABELS[$this->kind] ?? ucfirst($this->kind);
    }

    /**
     * The frozen form stored on a Deal at reserve. Only the three fields the
     * breakdown needs — no ids, timestamps, or active flag, since an inactive
     * fee never makes it into a snapshot in the first place.
     */
    public function toSnapshotEntry(): array
    {
        return [
            'label'           => $this->label,
            'kind'            => $this->kind,
            'amount_in_cents' => $this->amount_in_cents,
        ];
    }
}
