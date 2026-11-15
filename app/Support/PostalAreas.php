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

    /** @var array<string, array{city: string, province: string, latitude: float, longitude: float, areas: int, key: string}>|null */
    private static ?array $cities = null;

    /**
     * A town by name ("newmarket", "Montreal", with or without province),
     * placed at the middle of its postal areas. When the name is in more than
     * one province and none is given, the bigger town wins.
     *
     * @return array{city: string, province: string, latitude: float, longitude: float}|null
     */
    public static function city(string $name, ?string $province = null): ?array
    {
        $key = self::fold($name);

        $match = collect(self::cities())
            ->filter(fn ($city) => $city['key'] === $key && ($province === null || $city['province'] === $province))
            ->sortByDesc('areas')
            ->first();

        return $match ? array_intersect_key($match, array_flip(['city', 'province', 'latitude', 'longitude'])) : null;
    }

    /**
     * "Start typing" suggestions for the location prompt: towns by name, or
     * postal areas when it looks like a postal code. Offline, so it's free
     * and instant.
     *
     * @return array<int, array{value: string, label: string, hint: string}>
     */
    public static function suggest(string $term, int $limit = 6): array
    {
        $term = trim($term);
        $compact = strtoupper(preg_replace('/\s+/', '', $term));

        // A postal code, or the start of one: L, L3, L3X, L3X 3E3.
        if (preg_match('/^[A-Z](\d([A-Z](\d[A-Z]?\d?)?)?)?$/', $compact) && preg_match('/\d/', $compact)) {
            if (strlen($compact) === 6) {
                $area = self::forPostalCode($compact);

                return $area ? [[
                    'value' => substr($compact, 0, 3) . ' ' . substr($compact, 3),
                    'label' => substr($compact, 0, 3) . ' ' . substr($compact, 3),
                    'hint'  => "{$area['city']}, {$area['province']}",
                ]] : [];
            }

            return collect(self::all())
                ->filter(fn ($area) => str_starts_with($area['fsa'], substr($compact, 0, 3)))
                ->take($limit)
                ->map(fn ($area) => ['value' => $area['fsa'], 'label' => $area['fsa'], 'hint' => "{$area['city']}, {$area['province']}"])
                ->values()
                ->all();
        }

        $key = self::fold($term);
        if (mb_strlen($key) < 2) {
            return [];
        }

        // Towns starting with what's typed first, then towns with a word that does; bigger towns first.
        return collect(self::cities())
            ->map(fn ($city) => $city + ['rank' => str_starts_with($city['key'], $key) ? 0 : (str_contains($city['key'], ' ' . $key) || str_contains($city['key'], '-' . $key) ? 1 : 2)])
            ->filter(fn ($city) => $city['rank'] < 2)
            ->sortBy([['rank', 'asc'], ['areas', 'desc'], ['city', 'asc']])
            ->take($limit)
            ->map(fn ($city) => [
                'value' => "{$city['city']}, {$city['province']}",
                'label' => $city['city'],
                'hint'  => CanadianAddress::PROVINCES[$city['province']] ?? $city['province'],
            ])
            ->values()
            ->all();
    }

    /**
     * The town a postal area's name is about: "Kamloops Central and" and
     * "Saint John Northeast, Renforth" are parts of Kamloops and Saint John.
     * Government-office areas aren't towns at all.
     */
    private static function townName(string $name): ?string
    {
        if (stripos($name, 'government') !== false) {
            return null;
        }

        $name = trim(explode(',', $name)[0]);
        $name = preg_replace('/(?<=\S)\s+(Central|North|South|East|West|Northeast|Northwest|Southeast|Southwest|Northern|Southern)\b.*$/i', '', $name);

        return trim(preg_replace('/\s+(and|&)$/i', '', $name)) ?: null;
    }

    /** "Montréal" and "montreal " both become "montreal". */
    private static function fold(string $text): string
    {
        return trim(mb_strtolower(\Illuminate\Support\Str::ascii($text)));
    }

    /** @return array<string, array{city: string, province: string, latitude: float, longitude: float, areas: int, key: string}> */
    private static function cities(): array
    {
        if (self::$cities !== null) {
            return self::$cities;
        }

        $groups = [];
        foreach (self::all() as $area) {
            $name = self::townName($area['city']);
            if ($name !== null) {
                $groups[$name . '|' . $area['province']][] = $area + ['town' => $name];
            }
        }

        self::$cities = [];
        foreach ($groups as $id => $areas) {
            self::$cities[$id] = [
                'city'      => $areas[0]['town'],
                'province'  => $areas[0]['province'],
                'latitude'  => round(array_sum(array_column($areas, 'latitude')) / count($areas), 4),
                'longitude' => round(array_sum(array_column($areas, 'longitude')) / count($areas), 4),
                'areas'     => count($areas),
                'key'       => self::fold($areas[0]['town']),
            ];
        }

        return self::$cities;
    }

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
        fgetcsv($handle, null, ',', '"', '\\');

        while (($row = fgetcsv($handle, null, ',', '"', '\\')) !== false) {
            if (count($row) === 5) {
                self::$areas[$row[0]] = ['fsa' => $row[0], 'city' => $row[1], 'province' => $row[2], 'latitude' => (float) $row[3], 'longitude' => (float) $row[4]];
            }
        }

        fclose($handle);

        return self::$areas;
    }
}
