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
    /** Tax a trade-in reduces on a used car bought from a dealer, by province. */
    public const RATES = [
        'ON' => 0.13,     // HST
        'NB' => 0.15, 'NL' => 0.15, 'PE' => 0.15, // HST
        'NS' => 0.14,     // HST (14% from April 2025)
        'QC' => 0.14975,  // GST + QST
        'BC' => 0.12,     // GST + PST
        'MB' => 0.12,     // GST + RST
        'SK' => 0.11,     // GST + PST
        'AB' => 0.05, 'NT' => 0.05, 'NU' => 0.05, 'YT' => 0.05, // GST only
    ];

    /** What the tax is called there, for "about $761 less HST". */
    public static function taxName(?string $province): string
    {
        return match ($province) {
            'ON', 'NB', 'NL', 'NS', 'PE' => 'HST',
            'AB', 'NT', 'NU', 'YT'       => 'GST',
            default                      => 'sales tax',
        };
    }

    public static function savingsInCents(?string $province, int $tradeValueInCents): int
    {
        $rate = self::RATES[$province ?? 'ON'] ?? self::RATES['ON'];

        return (int) round(max(0, $tradeValueInCents) * $rate);
    }
}
