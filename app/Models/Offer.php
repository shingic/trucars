<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A CPO offer or incentive.
 *
 *   source = manufacturer   An OEM program, created and controlled centrally by
 *                           TruCars. Shows at a dealer only once that dealer
 *                           opts in (unless requires_opt_in is off).
 *   source = dealer         A dealer-funded promotion for that dealer's own cars.
 *                           Goes live only after TruCars approves it.
 *
 * Offers switch on and off by date — starts_on and ends_on are inclusive — so
 * next month's programs can be entered ahead of time and nothing on a vehicle
 * ever needs editing. Ended offers stay on record.
 */
class Offer extends Model
{
    public const SOURCE_MANUFACTURER = 'manufacturer';
    public const SOURCE_DEALER = 'dealer';

    public const TYPE_APR = 'apr';
    public const TYPE_CASH = 'cash';
    public const TYPE_WARRANTY = 'warranty';

    public const TYPE_LABELS = [
        self::TYPE_APR      => 'Special financing rate',
        self::TYPE_CASH     => 'Cash discount',
        self::TYPE_WARRANTY => 'Warranty or service',
    ];

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING = 'pending';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_ARCHIVED = 'archived';

    public const PROVINCES = ['AB', 'BC', 'MB', 'NB', 'NL', 'NS', 'NT', 'NU', 'ON', 'PE', 'QC', 'SK', 'YT'];

    protected $attributes = [
        'status'          => self::STATUS_DRAFT,
        'certified_only'  => true,
        'requires_opt_in' => true,
        'combinable'      => false,
    ];

    protected $fillable = [
        'source', 'dealer_id', 'type', 'title', 'make', 'models', 'year_min', 'year_max', 'provinces',
        'certified_only', 'apr', 'term_months', 'lender', 'cash_amount_in_cents', 'benefit', 'conditions',
        'starts_on', 'ends_on', 'requires_opt_in', 'combinable', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'models'          => 'array',
            'provinces'       => 'array',
            'certified_only'  => 'boolean',
            'requires_opt_in' => 'boolean',
            'combinable'      => 'boolean',
            'apr'             => 'decimal:2',
            'starts_on'       => 'date',
            'ends_on'         => 'date',
            'reviewed_at'     => 'datetime',
        ];
    }

    public function dealer(): BelongsTo
    {
        return $this->belongsTo(Dealer::class);
    }

    /** Dealers who opted in to this manufacturer offer. */
    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(Dealer::class)->withPivot('opted_in_by_user_id')->withTimestamps();
    }

    public function isManufacturer(): bool
    {
        return $this->source === self::SOURCE_MANUFACTURER;
    }

    /** Published and inside its dates today. */
    public function scopeLive(Builder $query): void
    {
        $today = now()->toDateString();

        $query->where('status', self::STATUS_PUBLISHED)->whereDate('starts_on', '<=', $today)->whereDate('ends_on', '>=', $today);
    }

    /** live | scheduled | ended | draft | pending | rejected | archived */
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
     * Whether this car qualifies — make, model, years, province, certification,
     * and the dealer's participation. Dates and status are the caller's (live()).
     */
    public function appliesTo(Vehicle $vehicle): bool
    {
        if (strtoupper((string) $vehicle->condition) !== 'USED' || mb_strtolower(trim((string) $vehicle->make)) !== mb_strtolower(trim($this->make))) {
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

        if ($this->certified_only && $vehicle->certification() === null) {
            return false;
        }

        if (! $this->isManufacturer()) {
            return $this->dealer_id === $vehicle->dealer_id;
        }

        return ! $this->requires_opt_in || $this->participants->contains('id', $vehicle->dealer_id);
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
            'source'     => $this->source,
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
