<?php

namespace App\Services\Carfax\Providers;

use App\Services\Carfax\CarfaxReport;

/** No CARFAX connection configured: every lookup finds nothing, so the section stays hidden. */
class NullCarfaxProvider implements CarfaxProvider
{
    public function lookup(string $accountId, string $vin): ?CarfaxReport
    {
        return null;
    }
}
