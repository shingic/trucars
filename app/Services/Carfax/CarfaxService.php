<?php

namespace App\Services\Carfax;

use App\Models\Vehicle;
use App\Services\Carfax\Providers\CarfaxProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The CARFAX report for a car on its vehicle page, under the selling
 * dealership's own CARFAX account.
 *
 * Nothing is shown unless the dealership has CARFAX switched on with an
 * account id and the car has a full 17-character VIN. Reports are cached, so
 * page views don't each call CARFAX. A failed lookup is logged and cached
 * briefly, and the page loads without the section.
 */
class CarfaxService
{
    public const FOUND_TTL = 60 * 60 * 12;
    public const MISS_TTL = 60 * 60;
    public const ERROR_TTL = 60 * 15;

    public function __construct(private readonly CarfaxProvider $provider) {}

    public function forVehicle(Vehicle $vehicle): ?CarfaxReport
    {
        $dealer = $vehicle->dealer;
        $accountId = trim((string) $dealer?->carfax_account_id);
        $vin = strtoupper(trim((string) $vehicle->vin));

        if (! $dealer?->carfax_enabled || $accountId === '' || ! preg_match('/^[A-HJ-NPR-Z0-9]{17}$/', $vin)) {
            return null;
        }

        $key = 'carfax:' . sha1($accountId) . ':' . $vin;
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached['report'] === null ? null : CarfaxReport::fromArray($cached['report']);
        }

        try {
            $report = $this->provider->lookup($accountId, $vin);
            Cache::put($key, ['report' => $report?->toArray()], $report ? self::FOUND_TTL : self::MISS_TTL);
        } catch (Throwable $e) {
            Log::warning('CARFAX lookup failed', ['dealer_id' => $dealer->id, 'vin' => $vin, 'error' => $e->getMessage()]);
            Cache::put($key, ['report' => null], self::ERROR_TTL);

            return null;
        }

        return $report;
    }

    /** Drop cached reports for a dealer's cars, e.g. after its CARFAX account changes. */
    public static function forget(string $accountId, iterable $vins): void
    {
        foreach ($vins as $vin) {
            Cache::forget('carfax:' . sha1(trim($accountId)) . ':' . strtoupper(trim((string) $vin)));
        }
    }
}
