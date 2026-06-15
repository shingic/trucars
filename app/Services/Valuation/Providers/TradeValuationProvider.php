<?php

namespace App\Services\Valuation\Providers;

use App\Services\Valuation\Data\ProviderQuote;
use App\Services\Valuation\Data\TradeInput;

/**
 * A source of trade-in BASE values. The conservative in-house stub implements
 * this today; Canadian Black Book, Carfax Canada, and vAuto adapters drop in
 * later without touching the pipeline above.
 *
 * Contract for every implementation:
 *  - return a single raw base value plus the lines that explain it
 *  - never apply the safety margin, rounding, or range — that lives in
 *    TradeValuation, so every provider is held to the same dealer-protection rules
 *  - be deterministic for the same TradeInput
 */
interface TradeValuationProvider
{
    /** Stable key persisted alongside the estimate (e.g. 'conservative', 'cbb'). */
    public function key(): string;

    public function quote(TradeInput $trade): ProviderQuote;
}
