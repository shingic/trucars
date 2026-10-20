<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An inspection checklist, set up by TruCars in the Command Centre. Shown on
 * used cars as "N-point inspection" with every point listed — N is just the
 * number of points entered.
 *
 *   dealer_id set    a dealer's own checklist, for that dealer's cars
 *   dealer_id null   a brand checklist (make set): applies automatically to
 *                    that make's cars at every dealer franchised for it
 *
 *   applies_to = all_used        every used car at the dealer
 *   applies_to = feed_certified  only cars the dealer's feed marks certified
 *                                (e.g. a manufacturer CPO checklist)
 */
class InspectionProgram extends Model
{
    public const APPLIES_TO_ALL_USED = 'all_used';
    public const APPLIES_TO_FEED_CERTIFIED = 'feed_certified';

    public const APPLIES_TO_LABELS = [
        self::APPLIES_TO_ALL_USED       => 'All used cars',
        self::APPLIES_TO_FEED_CERTIFIED => 'Only cars the feed marks certified',
    ];

    /** Match the column defaults, so a just-created program behaves like a loaded one. */
    protected $attributes = ['applies_to' => self::APPLIES_TO_ALL_USED, 'is_active' => true];

    protected $fillable = ['dealer_id', 'make', 'name', 'badge_label', 'summary', 'applies_to', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function dealer(): BelongsTo
    {
        return $this->belongsTo(Dealer::class);
    }

    public function points(): HasMany
    {
        return $this->hasMany(InspectionPoint::class)->orderBy('position')->orderBy('id');
    }

    public function badge(): string
    {
        return $this->badge_label ?: $this->name;
    }

    public function isBrand(): bool
    {
        return $this->dealer_id === null;
    }

    public function appliesTo(Vehicle $vehicle): bool
    {
        if (! $this->is_active || strtoupper((string) $vehicle->condition) !== 'USED') {
            return false;
        }

        if ($this->applies_to === self::APPLIES_TO_FEED_CERTIFIED && ! $vehicle->is_manufacturer_certified) {
            return false;
        }

        if (! $this->isBrand()) {
            return $this->dealer_id === $vehicle->dealer_id;
        }

        $make = mb_strtolower(trim((string) $this->make));

        return $make !== ''
            && mb_strtolower(trim((string) $vehicle->make)) === $make
            && mb_strtolower(trim((string) $vehicle->dealer?->franchise_make)) === $make;
    }
}
