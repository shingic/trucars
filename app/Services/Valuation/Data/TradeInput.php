<?php

namespace App\Services\Valuation\Data;

/**
 * A normalised snapshot of everything the customer told us about their trade-in.
 *
 * This is the single shape that flows into the valuation pipeline. Building it
 * from the Livewire checkout state (or from a persisted DealTradeIn) keeps the
 * providers and the protection logic free of Eloquent and request concerns.
 */
class TradeInput
{
    /**
     * @param  array<string, bool>  $features  keyed by feature slug (sunroof, leather, …)
     */
    public function __construct(
        public readonly int $year,
        public readonly string $make,
        public readonly string $model,
        public readonly ?string $trim,
        public readonly int $kilometres,
        public readonly string $exteriorCondition,    // excellent | good | fair | poor
        public readonly string $interiorCondition,    // excellent | good | fair | poor
        public readonly string $tireCondition,        // new | good | worn
        public readonly string $mechanicalCondition,  // perfect | good | minor | warning
        public readonly string $accidentHistory,      // none | minor | major
        public readonly string $ownerCount,           // 1 | 2 | 3+
        public readonly string $titleStatus,          // clean | rebuilt
        public readonly bool $wasSmokedIn,
        public readonly bool $carriedPets,
        public readonly bool $hasAftermarketMods,
        public readonly int $keyCount,
        public readonly array $features = [],
        public readonly int $lienOwingInCents = 0,
    ) {
    }

    public function hasFeature(string $slug): bool
    {
        return ! empty($this->features[$slug]);
    }
}
