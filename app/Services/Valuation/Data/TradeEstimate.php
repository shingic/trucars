<?php

namespace App\Services\Valuation\Data;

/**
 * The finished, dealer-safe estimate. This is what gets shown to the customer
 * and persisted on the DealTradeIn. The headline number is always presented as
 * a non-binding RANGE; `pointInCents` is the conservative anchor inside it.
 *
 * @property-read list<ValuationLine> $lines
 */
class TradeEstimate
{
    /**
     * @param  list<ValuationLine>  $lines
     */
    public function __construct(
        public readonly int $pointInCents,
        public readonly int $lowInCents,
        public readonly int $highInCents,
        public readonly array $lines,
        public readonly string $providerKey,
        public readonly int $lienOwingInCents = 0,
    ) {
    }

    /** Equity toward the new car after we pay off any outstanding lien. */
    public function netEquityInCents(): int
    {
        return max(0, $this->pointInCents - $this->lienOwingInCents);
    }

    /** Shape persisted into the deal_trade_ins.valuation_breakdown JSON column. */
    public function toBreakdownArray(): array
    {
        return [
            'provider' => $this->providerKey,
            'point_in_cents' => $this->pointInCents,
            'low_in_cents' => $this->lowInCents,
            'high_in_cents' => $this->highInCents,
            'lien_owing_in_cents' => $this->lienOwingInCents,
            'net_equity_in_cents' => $this->netEquityInCents(),
            'lines' => array_map(fn (ValuationLine $line) => $line->toArray(), $this->lines),
        ];
    }
}
