<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Canada's postal areas (the first three characters of a postal code), each
 * with a town and a centre point. Works offline: no API key, no network.
 *
 * Used to put a shopper's postal code or browser location on the map, and to
 * place a store from its postal code, when Google's geocoder is unavailable.
 * Points are area centres, so distances are approximate (a few km in cities).
 * Data: GeoNames, CC BY 4.0. See database/data/ca-postal-areas.md.
 */
class PostalAreas
{
    /** @var array<string, array{fsa: string, city: string, province: string, latitude: float, longitude: float}>|null */
    private static ?array $areas = null;

    /** @return array{fsa: string, city: string, province: string, latitude: float, longitude: float}|null */
    public static function forPostalCode(?string $postalCode): ?array
    {
        $fsa = strtoupper(substr(preg_replace('/\s+/', '', (string) $postalCode), 0, 3));

        return self::all()[$fsa] ?? null;
    }

    /**
     * The postal area whose centre is closest to a point, if one is within
     * reach (so a point outside Canada doesn't get a Canadian town).
     *
     * @return array{fsa: string, city: string, province: string, latitude: float, longitude: float}|null
     */
    public static function nearest(float $latitude, float $longitude, float $withinKm = 60): ?array
    {
        $best = null;
        $bestKm = INF;

        foreach (self::all() as $area) {
            $km = self::km($latitude, $longitude, $area['latitude'], $area['longitude']);

            if ($km < $bestKm) {
                $best = $area;
                $bestKm = $km;
            }
        }

        return $bestKm <= $withinKm ? $best : null;
    }

    private static function km(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $a = sin(deg2rad($lat2 - $lat1) / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin(deg2rad($lng2 - $lng1) / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /** @return array<string, array{fsa: string, city: string, province: string, latitude: float, longitude: float}> */
    private static function all(): array
    {
        if (self::$areas !== null) {
            return self::$areas;
        }

        self::$areas = [];
        $handle = fopen(database_path('data/ca-postal-areas.csv'), 'r');
        fgetcsv($handle);

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) === 5) {
                self::$areas[$row[0]] = ['fsa' => $row[0], 'city' => $row[1], 'province' => $row[2], 'latitude' => (float) $row[3], 'longitude' => (float) $row[4]];
            }
        }

        fclose($handle);

        return self::$areas;
    }
}
