<?php

namespace App\Services\Valuation\Data;

/**
 * What a TradeValuationProvider returns: a single BASE value plus the
 * breakdown lines that justify it. Providers deliberately do NOT decide how
 * generous the final estimate is — they only supply the starting number and
 * the condition/history adjustments. The "dealer can never lose" safety
 * margin, rounding, and range are applied above them in TradeValuation.
 *
 * @property-read list<ValuationLine> $lines
 */
class ProviderQuote
{
    /**
     * @param  list<ValuationLine>  $lines
     */
    public function __construct(
        public readonly string $providerKey,
        public readonly int $rawValueInCents,
        public readonly array $lines,
    ) {
    }
}
