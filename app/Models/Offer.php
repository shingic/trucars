<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A CPO offer or incentive — a brand setting. It applies to the brand's
 * eligible cars at every dealer of that brand, the same way for all of them.
 *
 * Offers switch on and off by date — starts_on and ends_on are inclusive — so
 * next month's programs can be entered ahead of time and nothing on a vehicle
 * ever needs editing. Ended and archived offers stay on record.
 */
class Offer extends Model
{
    public const TYPE_APR = 'apr';
    public const TYPE_CASH = 'cash';
    public const TYPE_WARRANTY = 'warranty';

    public const TYPE_LABELS = [
        self::TYPE_APR      => 'Special financing rate',
        self::TYPE_CASH     => 'Cash discount',
        self::TYPE_WARRANTY => 'Warranty or service',
    ];

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED = 'archived';

    public const PROVINCES = ['AB', 'BC', 'MB', 'NB', 'NL', 'NS', 'NT', 'NU', 'ON', 'PE', 'QC', 'SK', 'YT'];

    protected $attributes = [
        'status'         => self::STATUS_DRAFT,
        'certified_only' => true,
        'combinable'     => false,
    ];

    protected $fillable = [
        'brand_id', 'type', 'title', 'models', 'year_min', 'year_max', 'provinces',
        'certified_only', 'apr', 'term_months', 'lender', 'cash_amount_in_cents', 'benefit', 'conditions',
        'starts_on', 'ends_on', 'combinable', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'models'         => 'array',
            'provinces'      => 'array',
            'certified_only' => 'boolean',
            'combinable'     => 'boolean',
            'apr'            => 'decimal:2',
            'starts_on'      => 'date',
            'ends_on'        => 'date',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** Published and inside its dates today. */
    public function scopeLive(Builder $query): void
    {
        $today = now()->toDateString();

        $query->where('status', self::STATUS_PUBLISHED)->whereDate('starts_on', '<=', $today)->whereDate('ends_on', '>=', $today);
    }

    /** live | scheduled | ended | draft | archived */
    public function state(): string
    {
        if ($this->status !== self::STATUS_PUBLISHED) {
            return $this->status;
        }

        $today = now()->startOfDay();

        return match (true) {
            $this->starts_on->gt($today) => 'scheduled',
            $this->ends_on->lt($today)   => 'ended',
            default                      => 'live',
        };
    }

    /**
     * Whether this car qualifies: a used car of the offer's brand, at a dealer
     * of that brand, matching the models, years, province and certification.
     * Dates and status are the caller's (live()).
     */
    public function appliesTo(Vehicle $vehicle): bool
    {
        $brand = mb_strtolower(trim((string) $this->brand?->name));

        if ($brand === '' || $vehicle->dealer?->brand_id !== $this->brand_id
            || strtoupper((string) $vehicle->condition) !== 'USED'
            || mb_strtolower(trim((string) $vehicle->make)) !== $brand) {
            return false;
        }

        if ($this->models && ! in_array(mb_strtolower(trim((string) $vehicle->model)), array_map(fn ($model) => mb_strtolower(trim($model)), $this->models), true)) {
            return false;
        }

        if (($this->year_min && $vehicle->model_year < $this->year_min) || ($this->year_max && $vehicle->model_year > $this->year_max)) {
            return false;
        }

        if ($this->provinces && ! in_array($vehicle->dealer?->province, $this->provinces, true)) {
            return false;
        }

        return ! $this->certified_only || $vehicle->certification() !== null;
    }

    /** The big number: "3.99% APR", "$1,500 off", or the warranty benefit. */
    public function headline(): string
    {
        return match ($this->type) {
            self::TYPE_APR  => rtrim(rtrim(number_format((float) $this->apr, 2), '0'), '.') . '% APR',
            self::TYPE_CASH => '$' . number_format(($this->cash_amount_in_cents ?? 0) / 100) . ' off',
            default         => (string) $this->benefit,
        };
    }

    public function detail(): ?string
    {
        return match ($this->type) {
            self::TYPE_APR  => $this->term_months ? "For up to {$this->term_months} months" . ($this->lender ? " through {$this->lender}" : '') . '.' : null,
            self::TYPE_CASH => 'Taken off the all-in price.',
            default         => null,
        };
    }

    /** What a customer saw, frozen onto their reservation. */
    public function toSnapshot(): array
    {
        return [
            'offer_id'   => $this->id,
            'brand'      => $this->brand?->name,
            'type'       => $this->type,
            'title'      => $this->title,
            'headline'   => $this->headline(),
            'detail'     => $this->detail(),
            'apr'        => $this->apr !== null ? (float) $this->apr : null,
            'term_months'=> $this->term_months,
            'cash_amount_in_cents' => $this->cash_amount_in_cents,
            'conditions' => $this->conditions,
            'ends_on'    => $this->ends_on->toDateString(),
            'shown_at'   => now()->toIso8601String(),
        ];
    }
}
