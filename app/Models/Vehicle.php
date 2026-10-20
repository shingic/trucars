<?php


namespace App\Models;

use App\Models\Scopes\DealerScope;
use App\Support\VehicleCertification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Validation\ValidationException;

class Vehicle extends Model
{
    protected $fillable = [
        'dealer_id',
        'vin',
        'model_year',
        'make',
        'model',
        'trim',
        'body_type',
        'colour',
        'kilometres',
        'price_in_cents',
        'is_published',
        'stock_number',
        'condition',
        'is_certified',
        'is_manufacturer_certified',
        'transmission',
        'drivetrain',
        'fuel_type',
        'photos',
    ];

    protected function casts(): array
    {
        return [
            'model_year' => 'integer',
            'kilometres' => 'integer',
            'price_in_cents' => 'integer',
            'is_published' => 'boolean',
            'is_certified' => 'boolean',
            'is_manufacturer_certified' => 'boolean',
            'photos' => 'array',
        ];
    }

    /**
     * A car can't go live until its dealer has an address — every car is at its
     * dealer's rooftop, so without one there's no honest answer to "where is it?".
     * Checked on publish and on a move to another dealer. (Bulk query-builder
     * updates skip model events; publish through the model.)
     */
    protected static function booted(): void
    {
        static::saving(function (Vehicle $vehicle) {
            if (! $vehicle->is_published || ! $vehicle->isDirty(['is_published', 'dealer_id'])) {
                return;
            }

            $dealer = $vehicle->dealer()->first();

            if ($dealer === null || ! $dealer->hasCompleteAddress()) {
                throw ValidationException::withMessages([
                    'is_published' => 'This vehicle can’t be published until its dealer has a complete address (missing: '
                        . implode(', ', $dealer?->missingAddressFields() ?? ['dealer']) . ').',
                ]);
            }
        });
    }

    public function dealer(): BelongsTo
    {
        return $this->belongsTo(Dealer::class);
    }

    /**
     * What shoppers may see on the open marketplace: published cars from active
     * dealers. A dealer switched back to draft drops off without anyone having
     * to unpublish each car.
     */
    public function scopeListed(Builder $query): void
    {
        $query->where('vehicles.is_published', true)
            ->whereHas('dealer', fn (Builder $dealer) => $dealer->where('status', Dealer::STATUS_ACTIVE));
    }

    public function isListed(): bool
    {
        return $this->is_published && $this->dealer?->isActive();
    }

    /** Where the car is — always its dealer's address, e.g. "Chilliwack, BC". */
    protected function displayLocation(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->dealer?->display_location);
    }

    /**
     * The first photo from the feed, used as the card image on the floor.
     */
    protected function primaryPhotoUrl(): Attribute
    {
        return Attribute::get(fn(): ?string => $this->photos[0] ?? null);
    }

    /**
     * Price formatted for display, e.g. "$39,490".
     */
    protected function displayPrice(): Attribute
    {
        return Attribute::get(fn(): string => '$' . number_format($this->price_in_cents / 100));
    }

    /**
     * Estimated biweekly payment, matching the mockup's illustrative terms:
     * roughly tax-in, 7.5% APR, 72-month term.
     */
    protected function estimatedBiweekly(): Attribute
    {
        return Attribute::get(function (): int {
            $taxedPrice = ($this->price_in_cents / 100) * 1.13;
            $biweeklyRate = 0.075 / 26;
            $numberOfPayments = 72 / 12 * 26;

            return (int)round($taxedPrice * $biweeklyRate / (1 - (1 + $biweeklyRate) ** -$numberOfPayments));
        });
    }

    /**
     * Mileage shown the mockup's way: "42k km", but spelled out for near-new cars.
     */
    protected function displayKilometres(): Attribute
    {
        return Attribute::get(fn(): string => $this->kilometres < 1000
            ? number_format($this->kilometres) . ' km'
            : round($this->kilometres / 1000) . 'k km');
    }

    public function leads(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    /** The dealer's inspection checklist this car is shown with, if any. */
    public function certification(): ?VehicleCertification
    {
        return VehicleCertification::for($this);
    }

    /**
     * The one rule for what a dealer campaign may show: this dealer's own cars
     * that are published, used, flagged manufacturer-certified, of the dealer's
     * confirmed franchise brand, and not already held or sold through a deal.
     *
     * A dealer with no confirmed franchise brand has no eligible stock at all —
     * we'd rather show nothing than present generic used cars as factory CPO.
     * is_certified is deliberately not consulted: it's an age/kilometre guess,
     * not evidence of a manufacturer program.
     */
    public function scopeEligibleForDealerCpo(Builder $query, Dealer $dealer): void
    {
        if (blank($dealer->franchise_make)) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where('vehicles.dealer_id', $dealer->id)
            ->where('is_published', true)
            ->where('condition', 'USED')
            ->where('is_manufacturer_certified', true)
            ->whereRaw('lower(make) = ?', [mb_strtolower(trim($dealer->franchise_make))])
            // Any live deal (reserved through delivered) takes the car off the
            // shelf. The scope is lifted so a staff preview sees the same truth.
            ->whereDoesntHave('deals', fn (Builder $deals) => $deals
                ->withoutGlobalScope(DealerScope::class)
                ->where('stage', '!=', 'cancelled'));
    }

    public function isEligibleForDealerCpo(Dealer $dealer): bool
    {
        return self::query()->whereKey($this->id)->eligibleForDealerCpo($dealer)->exists();
    }
}
