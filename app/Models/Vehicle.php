<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Casts\Attribute;

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
            'photos' => 'array',
        ];
    }

    public function dealer(): BelongsTo
    {
        return $this->belongsTo(Dealer::class);
    }

    /**
     * The first photo from the feed, used as the card image on the floor.
     */
    protected function primaryPhotoUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->photos[0] ?? null);
    }

    /**
     * Price formatted for display, e.g. "$39,490".
     */
    protected function displayPrice(): Attribute
    {
        return Attribute::get(fn (): string => '$' . number_format($this->price_in_cents / 100));
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

            return (int) round($taxedPrice * $biweeklyRate / (1 - (1 + $biweeklyRate) ** -$numberOfPayments));
        });
    }

    /**
     * Mileage shown the mockup's way: "42k km", but spelled out for near-new cars.
     */
    protected function displayKilometres(): Attribute
    {
        return Attribute::get(fn (): string => $this->kilometres < 1000
            ? number_format($this->kilometres) . ' km'
            : round($this->kilometres / 1000) . 'k km');
    }

    public function leads(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Lead::class);
    }
}
