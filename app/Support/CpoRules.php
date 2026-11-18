<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Brand;

/**
 * Manufacturer certified-program rules: which makes share one program (a
 * Chrysler-Dodge-Jeep-Ram store's Jeep is its own brand; so is a GM store's
 * GMC), and the age/kilometre limits a car must be within to be certifiable.
 * The limits live on each Brand (cpo_max_model_years, cpo_max_km), editable
 * in the Command Centre; a brand without them has no rule, so only cars the
 * feed flags certified count.
 */
final class CpoRules
{
    /** Makes sold and certified under one franchise. */
    private const FAMILIES = [
        ['chrysler', 'dodge', 'jeep', 'ram', 'fiat'],
        ['chevrolet', 'buick', 'gmc'],
    ];

    /** Every make in the same franchise as this one, lower case (just itself when it has no family). @return list<string> */
    public static function family(?string $make): array
    {
        $make = mb_strtolower(trim((string) $make));

        if ($make === '') {
            return [];
        }

        foreach (self::FAMILIES as $family) {
            if (in_array($make, $family, true)) {
                return $family;
            }
        }

        return [$make];
    }

    /** A car of this make is the dealer's own brand (same franchise). */
    public static function sameFranchise(?string $carMake, ?string $dealerMake): bool
    {
        $dealerFamily = self::family($dealerMake);

        return $dealerFamily !== [] && in_array(mb_strtolower(trim((string) $carMake)), $dealerFamily, true);
    }

    /**
     * Within the brand's certified-program limits: no older than the last N
     * model years (this year's counted) and under the kilometre cap. False
     * when the car's brand has no limits entered.
     */
    public static function fitsRules(?string $make, int $modelYear, int $kilometres, ?int $currentYear = null): bool
    {
        $brand = self::brand($make);

        if ($brand === null || $brand->cpo_max_model_years === null || $brand->cpo_max_km === null) {
            return false;
        }

        $currentYear ??= (int) date('Y');

        return $modelYear >= $currentYear - ((int) $brand->cpo_max_model_years - 1)
            && $kilometres < (int) $brand->cpo_max_km;
    }

    private static function brand(?string $make): ?Brand
    {
        $make = mb_strtolower(trim((string) $make));

        return once(fn () => Brand::all()->keyBy(fn (Brand $brand) => mb_strtolower($brand->name)))->get($make);
    }
}
