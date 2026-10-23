<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ProtectionProduct;

/**
 * The illustrative payment math checkout and My Garage share, so both show the
 * same number. The dealership's finance office sets the real rate; these are
 * estimates only (7.5% APR, 13% HST, $0 down unless the buyer enters one).
 *
 * Protection products the buyer added count at the dealership's prices:
 *  - one-time prices (accessories, a lump-sum plan) are rolled into the amount
 *    financed, or added to the total when paying in full
 *  - biweekly prices (GAP, most add-ons) are added straight onto the payment,
 *    and only apply when financing
 */
final class PaymentEstimate
{
    public const APR = 0.075;

    public const HST = 0.13;

    /** Biweekly payment on an amount financed (before tax) over a term. */
    public static function loanBiweekly(float $financedDollars, int $termMonths): float
    {
        $periods = ($termMonths / 12) * 26;

        if ($periods <= 0 || $financedDollars <= 0) {
            return 0;
        }

        $rate = self::APR / 26;

        return $financedDollars * (1 + self::HST) * $rate / (1 - pow(1 + $rate, -$periods));
    }

    /**
     * What the added items come to, in dollars.
     *
     * @param  array<int, array{amount_in_cents?: ?int, price_basis?: ?string}>  $items
     * @return array{oneTime: float, biweekly: float}
     */
    public static function protectionTotals(array $items): array
    {
        $totals = ['oneTime' => 0.0, 'biweekly' => 0.0];

        foreach ($items as $item) {
            $cents = $item['amount_in_cents'] ?? null;

            if ($cents === null) {
                continue;
            }

            $basis = ($item['price_basis'] ?? ProtectionProduct::BASIS_BIWEEKLY) === ProtectionProduct::BASIS_ONE_TIME ? 'oneTime' : 'biweekly';
            $totals[$basis] += $cents / 100;
        }

        return $totals;
    }

    /** The full biweekly estimate: the loan (car plus one-time items, less down) plus biweekly items. */
    public static function biweekly(float $priceDollars, float $downDollars, int $termMonths, array $items): float
    {
        $totals = self::protectionTotals($items);

        return self::loanBiweekly(max(0, $priceDollars + $totals['oneTime'] - $downDollars), $termMonths) + $totals['biweekly'];
    }
}
