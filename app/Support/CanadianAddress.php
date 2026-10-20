<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeZone;

/**
 * Normalisation rules for Canadian dealer and shopper addresses. Pure lookups —
 * no network calls, no guessing beyond what the input itself determines.
 */
class CanadianAddress
{
    public const COUNTRY = 'CA';

    /** Two-letter Canada Post codes, keyed to their display names. */
    public const PROVINCES = [
        'AB' => 'Alberta',
        'BC' => 'British Columbia',
        'MB' => 'Manitoba',
        'NB' => 'New Brunswick',
        'NL' => 'Newfoundland and Labrador',
        'NS' => 'Nova Scotia',
        'NT' => 'Northwest Territories',
        'NU' => 'Nunavut',
        'ON' => 'Ontario',
        'PE' => 'Prince Edward Island',
        'QC' => 'Quebec',
        'SK' => 'Saskatchewan',
        'YT' => 'Yukon',
    ];

    /**
     * The zone most of each province observes. Only a starting value for an
     * admin to confirm: a few areas differ (north-west Ontario, north-east BC,
     * Lloydminster, Labrador, the Quebec Lower North Shore). Nunavut spans three
     * zones, so it has no default and must be chosen.
     */
    private const DEFAULT_TIMEZONES = [
        'AB' => 'America/Edmonton',
        'BC' => 'America/Vancouver',
        'MB' => 'America/Winnipeg',
        'NB' => 'America/Moncton',
        'NL' => 'America/St_Johns',
        'NS' => 'America/Halifax',
        'NT' => 'America/Edmonton', // Yellowknife is an alias of Edmonton in current tzdata
        'ON' => 'America/Toronto',
        'PE' => 'America/Halifax',
        'QC' => 'America/Toronto',
        'SK' => 'America/Regina',
        'YT' => 'America/Whitehorse',
    ];

    /** The licensing body named next to a dealer's registration number. */
    private const DEALER_REGULATORS = [
        'AB' => 'AMVIC',
        'BC' => 'VSA',
        'ON' => 'OMVIC',
        'QC' => 'OPC',
    ];

    /** First letter of a postal code → province (X is shared by NT and NU). */
    private const POSTAL_PREFIX_PROVINCES = [
        'A' => 'NL', 'B' => 'NS', 'C' => 'PE', 'E' => 'NB',
        'G' => 'QC', 'H' => 'QC', 'J' => 'QC',
        'K' => 'ON', 'L' => 'ON', 'M' => 'ON', 'N' => 'ON', 'P' => 'ON',
        'R' => 'MB', 'S' => 'SK', 'T' => 'AB', 'V' => 'BC', 'Y' => 'YT',
    ];

    private const POSTAL_PATTERN = '/^[ABCEGHJ-NPRSTVXY]\d[ABCEGHJ-NPRSTV-Z]\d[ABCEGHJ-NPRSTV-Z]\d$/';

    /** "bc", "B.C.", "British Columbia" → "BC". Null when it isn't a province. */
    public static function normalizeProvince(?string $province): ?string
    {
        $cleaned = strtoupper(str_replace(['.', ' '], '', trim((string) $province)));

        if ($cleaned === '') {
            return null;
        }

        if (isset(self::PROVINCES[$cleaned])) {
            return $cleaned;
        }

        foreach (self::PROVINCES as $code => $name) {
            if ($cleaned === strtoupper(str_replace(' ', '', $name))) {
                return $code;
            }
        }

        return $cleaned === 'PQ' ? 'QC' : null;
    }

    /** "v2p2m9" → "V2P 2M9". Null when it isn't a valid Canadian postal code. */
    public static function normalizePostalCode(?string $postalCode): ?string
    {
        $compact = strtoupper(preg_replace('/[\s-]+/', '', trim((string) $postalCode)));

        if (! preg_match(self::POSTAL_PATTERN, $compact)) {
            return null;
        }

        return substr($compact, 0, 3) . ' ' . substr($compact, 3);
    }

    public static function provinceForPostalCode(?string $postalCode): ?string
    {
        $normalized = self::normalizePostalCode($postalCode);

        return $normalized === null ? null : (self::POSTAL_PREFIX_PROVINCES[$normalized[0]] ?? null);
    }

    public static function defaultTimezoneFor(?string $province): ?string
    {
        return self::DEFAULT_TIMEZONES[self::normalizeProvince($province) ?? ''] ?? null;
    }

    public static function regulatorFor(?string $province): ?string
    {
        return self::DEALER_REGULATORS[self::normalizeProvince($province) ?? ''] ?? null;
    }

    /** Every IANA zone in use in Canada — the only values a dealer may hold. */
    public static function timezones(): array
    {
        return DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, self::COUNTRY);
    }
}
