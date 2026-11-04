<?php

declare(strict_types=1);

namespace App\Support\Marketing;

use App\Models\Vehicle;
use App\Support\ShopperLocation;
use Illuminate\Support\Collection;

/**
 * Cars to suggest to a shopper who looked at $car: the same model first,
 * then the same body style within 15% of its price. Only listed cars that
 * nobody has reserved, nearest to the shopper first when we know where they are.
 */
class Recommendations
{
    public const PRICE_BAND = 0.15;

    /** @return Collection<int, Vehicle> */
    public static function similarTo(Vehicle $car, int $limit = 3, ?array $shopperLocation = null): Collection
    {
        $low = (int) round($car->price_in_cents * (1 - self::PRICE_BAND));
        $high = (int) round($car->price_in_cents * (1 + self::PRICE_BAND));

        $candidates = Vehicle::query()
            ->listed()
            ->withReservedFlag()
            ->with(['rooftop', 'dealer'])
            ->whereKeyNot($car->id)
            ->where(fn ($query) => $query
                ->where(fn ($sameModel) => $sameModel->where('make', $car->make)->where('model', $car->model))
                ->when($car->body_type, fn ($q) => $q->orWhere(fn ($sameBody) => $sameBody
                    ->where('body_type', $car->body_type)->whereBetween('price_in_cents', [$low, $high]))))
            ->limit(60)
            ->get()
            ->reject(fn (Vehicle $other) => (bool) $other->getAttribute('is_reserved'));

        return $candidates
            ->sortBy(fn (Vehicle $other) => [
                $other->make === $car->make && $other->model === $car->model ? 0 : 1,
                ShopperLocation::distanceKm($shopperLocation, $other->locatedAt()) ?? PHP_INT_MAX,
                abs($other->price_in_cents - $car->price_in_cents),
            ])
            ->take($limit)
            ->values();
    }
}
