<?php

namespace App\Services\Carfax\Providers;

use App\Services\Carfax\CarfaxReport;

/**
 * Sample reports for local development and demos (CARFAX_DRIVER=fake). The
 * same VIN always gets the same sample. Never use in production: the figures
 * are made up.
 */
class FakeCarfaxProvider implements CarfaxProvider
{
    public function lookup(string $accountId, string $vin): ?CarfaxReport
    {
        $seed = crc32($vin);
        $accidents = $seed % 4 === 0 ? 1 : 0;
        $owners = 1 + $seed % 2;

        return new CarfaxReport(
            reportUrl: 'https://www.carfax.ca/',
            accidentCount: $accidents,
            ownerCount: $owners,
            serviceRecordCount: 3 + $seed % 9,
            registeredIn: ['Ontario'],
            stolen: false,
            badges: array_values(array_filter([
                $accidents === 0 ? 'No Reported Accidents' : null,
                $owners === 1 ? 'One Owner' : null,
            ])),
        );
    }
}
