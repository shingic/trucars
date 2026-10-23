<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Something a buyer can add at checkout: a coverage plan, GAP, a protection
 * add-on or an accessory. Its price counts in the buyer's payment estimate
 * (PaymentEstimate); nothing is charged online, and the dealership's F&I
 * office confirms the final numbers after the reservation.
 *
 * No dealer_id = a TruCars default, offered at every dealership. A dealership
 * can hide a default or set its own price for it (DealerProtectionSetting),
 * and can add items of its own (dealer_id set).
 *
 * Not dealer-scoped on purpose: defaults belong to no dealer. Every page that
 * edits these fences by dealer id itself.
 */
class ProtectionProduct extends Model
{
    public const KIND_PLAN = 'plan';
    public const KIND_GAP = 'gap';
    public const KIND_ADDON = 'addon';
    public const KIND_ACCESSORY = 'accessory';

    /** In checkout order. */
    public const KINDS = [
        self::KIND_PLAN      => 'Coverage plans',
        self::KIND_GAP       => 'GAP protection',
        self::KIND_ADDON     => 'Protection add-ons',
        self::KIND_ACCESSORY => 'Accessories',
    ];

    public const BASIS_BIWEEKLY = 'biweekly';
    public const BASIS_ONE_TIME = 'one_time';

    public const BASES = [
        self::BASIS_BIWEEKLY => 'Biweekly estimate',
        self::BASIS_ONE_TIME => 'One-time price',
    ];

    protected $fillable = [
        'dealer_id', 'kind', 'name', 'description', 'features', 'amount_in_cents',
        'price_basis', 'is_featured', 'position', 'is_active',
    ];

    protected $attributes = [
        'price_basis' => self::BASIS_BIWEEKLY,
        'is_featured' => false,
        'position'    => 0,
        'is_active'   => true,
    ];

    protected function casts(): array
    {
        return [
            'features'        => 'array',
            'amount_in_cents' => 'integer',
            'is_featured'     => 'boolean',
            'position'        => 'integer',
            'is_active'       => 'boolean',
        ];
    }

    public function dealer(): BelongsTo
    {
        return $this->belongsTo(Dealer::class);
    }

    public function dealerSettings(): HasMany
    {
        return $this->hasMany(DealerProtectionSetting::class);
    }

    /** TruCars defaults: the rows no dealer owns. */
    public function scopeDefaults(Builder $query): Builder
    {
        return $query->whereNull('dealer_id');
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderByRaw("case kind when 'plan' then 1 when 'gap' then 2 when 'addon' then 3 else 4 end")
            ->orderBy('position')->orderBy('id');
    }

    public function isDefault(): bool
    {
        return $this->dealer_id === null;
    }

    /**
     * What one dealership offers at checkout: every active default it hasn't
     * hidden, at its own price where it set one, plus its own active items.
     * Each product comes back with `offered_amount_in_cents` filled in.
     *
     * @return Collection<int, ProtectionProduct>
     */
    public static function menuFor(Dealer $dealer): Collection
    {
        $settings = DealerProtectionSetting::where('dealer_id', $dealer->id)->get()->keyBy('protection_product_id');

        return static::query()
            ->where('is_active', true)
            ->where(fn (Builder $query) => $query->whereNull('dealer_id')->orWhere('dealer_id', $dealer->id))
            ->ordered()
            ->get()
            ->reject(fn (self $product) => $product->isDefault() && $settings->get($product->id)?->is_enabled === false)
            ->each(function (self $product) use ($settings) {
                $override = $product->isDefault() ? $settings->get($product->id)?->amount_in_cents : null;
                $product->offered_amount_in_cents = $override ?? $product->amount_in_cents;
            })
            ->values();
    }

    /** "$18/biweekly" or "$249", from a price in cents; null when no price is set. */
    public static function priceLabel(?int $cents, string $basis): ?string
    {
        if ($cents === null) {
            return null;
        }

        $dollars = '$' . number_format($cents / 100, $cents % 100 === 0 ? 0 : 2);

        return $basis === self::BASIS_BIWEEKLY ? $dollars . '/biweekly' : $dollars;
    }

    /** What the deal keeps of this item, frozen at the moment of reserving. */
    public function toSnapshot(): array
    {
        return [
            'kind'            => $this->kind,
            'name'            => $this->name,
            'amount_in_cents' => $this->offered_amount_in_cents ?? $this->amount_in_cents,
            'price_basis'     => $this->price_basis,
        ];
    }
}
