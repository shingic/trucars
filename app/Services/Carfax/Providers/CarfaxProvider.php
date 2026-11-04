<?php

namespace App\Services\Carfax\Providers;

use App\Services\Carfax\CarfaxReport;

/**
 * Looks up a VIN's history report under one dealership's CARFAX account.
 * Returns null when CARFAX has no report for the VIN. Throws on transport or
 * auth failures, which CarfaxService catches so a vehicle page never breaks.
 */
interface CarfaxProvider
{
    public function lookup(string $accountId, string $vin): ?CarfaxReport;
}
