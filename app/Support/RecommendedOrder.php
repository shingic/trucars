<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Vehicle;
use Illuminate\Support\Collection;

/**
 * The marketplace's default order: dealers take turns, so one dealer's big
 * feed import can't push everyone else off the first pages. Page 1 shows one
 * car from each dealer, then a second from each, and so on; each dealer's own
 * cars stay newest first, and the dealer with the newest car goes first.
 *
 * With a shopper location, closer dealers still come first: dealers are put in
 * distance bands (or same city / same province / elsewhere for a typed place
 * without coordinates), and take turns only within their band.
 */
final class RecommendedOrder
{
    /** Upper edges of the distance bands, in km. Beyond the last is one band. */
    private const BANDS_KM = [50, 150, 400];

    /** Dealers whose distance can't be worked out, after every known band. */
    private const UNKNOWN_BAND = 99;

    /**
     * @param  Collection<int, Vehicle>  $vehicles  already newest first
     * @param  array<string, mixed>|null  $location  ShopperLocation::current()
     * @return Collection<int, Vehicle>
     */
    public static function apply(Collection $vehicles, ?array $location = null): Collection
    {
        return $vehicles
            ->groupBy(fn (Vehicle $vehicle) => self::band($vehicle, $location), preserveKeys: false)
            ->sortKeys()
            ->flatMap(fn (Collection $band) => self::takeTurns($band))
            ->values();
    }

    /** Round robin across dealers: each dealer's 1st car, then each one's 2nd, … */
    private static function takeTurns(Collection $vehicles): Collection
    {
        // groupBy keeps first-seen order, so the dealer with the newest car leads each round.
        $queues = $vehicles->groupBy('dealer_id')->map(fn (Collection $cars) => $cars->values())->values();
        $rounds = $queues->max(fn (Collection $cars) => $cars->count()) ?? 0;

        $ordered = [];
        for ($round = 0; $round < $rounds; $round++) {
            foreach ($queues as $cars) {
                if (isset($cars[$round])) {
                    $ordered[] = $cars[$round];
                }
            }
        }

        return collect($ordered);
    }

    private static function band(Vehicle $vehicle, ?array $location): int
    {
        if ($location === null) {
            return 0;
        }

        $store = $vehicle->locatedAt();
        $km = ShopperLocation::distanceKm($location, $store);

        if ($km !== null) {
            foreach (self::BANDS_KM as $index => $edge) {
                if ($km <= $edge) {
                    return $index;
                }
            }

            return count(self::BANDS_KM);
        }

        // The shopper has coordinates but this dealer doesn't: after every known distance.
        if (isset($location['latitude'], $location['longitude'])) {
            return self::UNKNOWN_BAND;
        }

        // A typed place without coordinates: same city, same province, elsewhere.
        return ShopperLocation::proximityRank($location, $store);
    }
}
