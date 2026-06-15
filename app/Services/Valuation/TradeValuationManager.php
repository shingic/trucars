<?php

namespace App\Services\Valuation;

use App\Models\Dealer;
use App\Services\Valuation\Providers\ConservativeStubProvider;
use App\Services\Valuation\Providers\TradeValuationProvider;

/**
 * Resolves which base-value provider a given dealer uses. Each dealer carries a
 * `valuation_provider` column; one store can run on the conservative stub while
 * another runs on a contracted vendor.
 *
 * Anything unknown or unconfigured falls back to the conservative stub, so a
 * misconfigured dealer can never break checkout — they just get the safe floor.
 */
class TradeValuationManager
{
    /**
     * Registered providers keyed by their stable provider key.
     *
     * Vendor adapters register here as they're contracted, e.g.
     *   'cbb'    => CanadianBlackBookProvider::class,
     *   'carfax' => CarfaxCanadaProvider::class,
     *   'vauto'  => VautoProvider::class,
     *
     * @var array<string, class-string<TradeValuationProvider>>
     */
    private array $providers = [
        'conservative' => ConservativeStubProvider::class,
    ];

    public function for(Dealer $dealer): TradeValuationProvider
    {
        return $this->resolve($dealer->valuation_provider ?? 'conservative');
    }

    public function resolve(string $providerKey): TradeValuationProvider
    {
        $providerClass = $this->providers[$providerKey] ?? $this->providers['conservative'];

        return app($providerClass);
    }

    /** Register or override a provider at runtime (used in tests and by service providers). */
    public function register(string $providerKey, string $providerClass): void
    {
        $this->providers[$providerKey] = $providerClass;
    }
}
