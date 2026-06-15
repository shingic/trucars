<?php

namespace App\Services\Valuation;

use App\Models\Dealer;
use App\Services\Valuation\Data\ProviderQuote;
use App\Services\Valuation\Data\TradeEstimate;
use App\Services\Valuation\Data\TradeInput;

/**
 * Turns a raw provider quote into a dealer-safe, non-binding estimate.
 *
 * The governing rule — "the dealer can never lose" — lives entirely here, above
 * the providers, so every provider (stub or vendor) is held to the same posture:
 *
 *   1. Take the provider's conservative base + adjustments.
 *   2. Apply a safety-margin discount to the point value.
 *   3. Round the point value DOWN to a clean $50 boundary.
 *   4. Present it as a RANGE, not a single firm number — the customer sees a
 *      low/high band whose TOP still sits at-or-below what a dealer could pay.
 *
 * The estimate is an anchor the dealer can meet or beat, never a floor they're
 * committed to. It is always non-binding: final offer follows the dealership's
 * own inspection.
 */
class TradeValuation
{
    /**
     * Haircut applied to the provider's number before it's ever shown. Keeps the
     * customer-facing point value comfortably under a realistic wholesale figure.
     */
    private const SAFETY_MARGIN = 0.92; // show 92% of the provider's value

    /** Half-widths of the presented range around the point value. */
    private const RANGE_BELOW = 0.96; // low end sits 4% under the point
    private const RANGE_ABOVE = 1.05; // high end sits 5% over, still beatable

    /** Clean rounding boundary for every customer-facing figure. */
    private const ROUND_TO_CENTS = 5000; // $50

    public function __construct(
        private readonly TradeValuationManager $manager,
    ) {
    }

    public function estimate(Dealer $dealer, TradeInput $trade): TradeEstimate
    {
        $provider = $this->manager->for($dealer);
        $quote = $provider->quote($trade);

        return $this->protect($quote, $trade);
    }

    /**
     * Apply the safety margin, round down, and build the presented range.
     */
    private function protect(ProviderQuote $quote, TradeInput $trade): TradeEstimate
    {
        $discounted = $quote->rawValueInCents * self::SAFETY_MARGIN;

        // Round the anchor DOWN — we never round up into territory the dealer
        // might not match.
        $pointInCents = $this->roundDownToFifty($discounted);

        // Build the band, then round each edge to a clean $50. The low end
        // rounds down and the high end also rounds down, so even the top of the
        // range stays conservative.
        $lowInCents = $this->roundDownToFifty($pointInCents * self::RANGE_BELOW);
        $highInCents = $this->roundDownToFifty($pointInCents * self::RANGE_ABOVE);

        return new TradeEstimate(
            pointInCents: $pointInCents,
            lowInCents: $lowInCents,
            highInCents: $highInCents,
            lines: $quote->lines,
            providerKey: $quote->providerKey,
            lienOwingInCents: $trade->lienOwingInCents,
        );
    }

    private function roundDownToFifty(int|float $amountInCents): int
    {
        return (int) (floor($amountInCents / self::ROUND_TO_CENTS) * self::ROUND_TO_CENTS);
    }
}
