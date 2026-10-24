<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Dealer;
use App\Models\Rooftop;

/**
 * Where a shopper says they are, for distances and "nearest first" — held in
 * their session only, never on a dealer or user record. Entirely optional:
 * browsing works the same without it, and it never filters inventory out.
 *
 * Two ways in: the browser's location (after the shopper taps "Use my
 * location"), rounded to ~1 km before it's kept; or a typed city or postal
 * code. The town is looked up for the browser's point and for a postal code
 * (AddressLookup), so the shop can say "for sale in Chilliwack"; typed input
 * still gives a city / province match rather than kilometres.
 */
class ShopperLocation
{
    public const SESSION_KEY = 'shopper_location';

    /** @return array{source: string, label: string, latitude?: float, longitude?: float, city?: string, province?: string, postal_code?: string}|null */
    public static function current(): ?array
    {
        $location = session(self::SESSION_KEY);

        return is_array($location) && isset($location['source'], $location['label']) ? $location : null;
    }

    public static function setFromBrowser(float $latitude, float $longitude): void
    {
        $place = app(AddressLookup::class)->cityAt(round($latitude, 2), round($longitude, 2));

        session()->put(self::SESSION_KEY, array_filter([
            'source'    => 'browser',
            'label'     => $place ? self::placeLabel($place['city'], $place['province']) : 'your location',
            'latitude'  => round($latitude, 2),
            'longitude' => round($longitude, 2),
            'city'      => $place['city'] ?? null,
            'province'  => $place['province'] ?? null,
        ], fn ($value) => $value !== null));
    }

    private static function placeLabel(string $city, ?string $province): string
    {
        return $province ? "{$city}, {$province}" : $city;
    }

    /**
     * A postal code ("m5v2t6") or a city ("Toronto", "Chilliwack, BC").
     * Returns false when the input is neither, so the caller can say so.
     */
    public static function setManual(string $input): bool
    {
        $input = trim($input);
        $postalCode = CanadianAddress::normalizePostalCode($input);

        if ($postalCode !== null) {
            $place = app(AddressLookup::class)->cityForPostalCode($postalCode);

            session()->put(self::SESSION_KEY, array_filter([
                'source'      => 'manual',
                'label'       => $place ? self::placeLabel($place['city'], $place['province'] ?? CanadianAddress::provinceForPostalCode($postalCode)) : $postalCode,
                'postal_code' => $postalCode,
                'city'        => $place['city'] ?? null,
                'province'    => $place['province'] ?? CanadianAddress::provinceForPostalCode($postalCode),
            ], fn ($value) => $value !== null));

            return true;
        }

        if (! preg_match("/^([\\p{L}][\\p{L} .'\\-]{1,50})(?:,\\s*([\\p{L} .]{2,30}))?$/u", $input, $parts)) {
            return false;
        }

        $city = mb_convert_case(trim($parts[1]), MB_CASE_TITLE); // "chilliwack" → "Chilliwack"
        $province = isset($parts[2]) ? CanadianAddress::normalizeProvince($parts[2]) : null;

        session()->put(self::SESSION_KEY, array_filter([
            'source'   => 'manual',
            'label'    => $province ? "{$city}, {$province}" : $city,
            'city'     => $city,
            'province' => $province,
        ]));

        return true;
    }

    public static function clear(): void
    {
        session()->forget(self::SESSION_KEY);
    }

    /** Straight-line km from the shopper to the car's rooftop, when both have coordinates. */
    public static function distanceKm(?array $location, Rooftop|Dealer|null $dealer): ?float
    {
        if (! isset($location['latitude'], $location['longitude']) || $dealer === null || ! $dealer->hasCoordinates()) {
            return null;
        }

        $earthRadiusKm = 6371;
        $latDelta = deg2rad($dealer->latitude - $location['latitude']);
        $lngDelta = deg2rad($dealer->longitude - $location['longitude']);

        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($location['latitude'])) * cos(deg2rad($dealer->latitude)) * sin($lngDelta / 2) ** 2;

        return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Coarse closeness for typed locations: 0 same city, 1 same province,
     * 2 anywhere else. Used for "nearest first" when there are no coordinates.
     */
    public static function proximityRank(?array $location, Rooftop|Dealer|null $dealer): int
    {
        if ($location === null || $dealer === null) {
            return 2;
        }

        if (isset($location['city']) && $dealer->city !== null && strcasecmp(trim($dealer->city), $location['city']) === 0
            && (! isset($location['province']) || $location['province'] === $dealer->province)) {
            return 0;
        }

        return isset($location['province']) && $location['province'] === $dealer->province ? 1 : 2;
    }
}
