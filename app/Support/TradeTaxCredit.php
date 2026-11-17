<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The sales tax a trade-in saves. When a dealer takes a trade, tax is charged
 * on the price minus the trade's value, so the buyer saves that province's
 * rate on the trade value. The car is picked up at the dealership, so it's
 * the dealership's province. An estimate for the buyer; the dealer's bill of
 * sale is what counts.
 */
final class TradeTaxCredit
{
    /** What the tax is called there, for "about $761 less HST". */
    public static function taxName(?string $province): string
    {
        return ProvinceRules::salesTax($province)['label'];
    }

    /** Every province lets a dealer trade-in reduce the tax (BC, SK and MB with conditions). BC's rate depends on the car's price. */
    public static function savingsInCents(?string $province, int $tradeValueInCents, ?int $carPriceInCents = null): int
    {
        $rate = ProvinceRules::salesTax($province, $carPriceInCents)['rate'];

        return (int) round(max(0, $tradeValueInCents) * $rate);
    }
}
