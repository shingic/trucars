<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Offer;
use App\Models\Vehicle;
use Illuminate\Support\Collection;

/**
 * The offers a car qualifies for today. Live offers are loaded once per request,
 * so a page of listing cards doesn't query per car. Manufacturer offers come
 * first. Offers aren't stacked: each is shown with its own conditions, and only
 * the first is featured on listing cards.
 */
final class VehicleOffers
{
    /** @return Collection<int, Offer> */
    public static function for(Vehicle $vehicle): Collection
    {
        return self::live()->filter(fn (Offer $offer) => $offer->appliesTo($vehicle))->values();
    }

    /** @return Collection<int, Offer> */
    private static function live(): Collection
    {
        return once(fn () => Offer::query()->live()->with('participants:id')
            ->orderByRaw("case when source = 'manufacturer' then 0 else 1 end")
            ->orderBy('ends_on')
            ->get());
    }
}
