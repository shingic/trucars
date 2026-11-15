<?php

declare(strict_types=1);

namespace App\Services\Vin;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A VIN to year, make, model and trim, for "paste your VIN" on the trade-in
 * step. Uses the US government's free vPIC decoder (NHTSA), which reads the
 * VIN on most cars sold in Canada. No key, no cost; results are cached.
 * Any failure returns null and the shopper types the car in themselves.
 */
class VinDecoder
{
    private const URL = 'https://vpic.nhtsa.dot.gov/api/vehicles/DecodeVinValues/%s?format=json';

    /** Makes written in capitals, not title case. */
    private const UPPER_MAKES = ['BMW', 'GMC', 'RAM', 'MINI', 'FIAT', 'KIA', 'MG', 'BYD'];

    /** "1hgcm82633a004352 " → "1HGCM82633A004352". */
    public static function clean(string $vin): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $vin));
    }

    /** 17 characters, no I, O or Q, and the check digit adds up (required on North American VINs). */
    public static function isValid(string $vin): bool
    {
        if (! preg_match('/^[A-HJ-NPR-Z0-9]{17}$/', $vin)) {
            return false;
        }

        $values = array_combine(str_split('ABCDEFGHJKLMNPRSTUVWXYZ'), [1, 2, 3, 4, 5, 6, 7, 8, 1, 2, 3, 4, 5, 7, 9, 2, 3, 4, 5, 6, 7, 8, 9]);
        $weights = [8, 7, 6, 5, 4, 3, 2, 10, 0, 9, 8, 7, 6, 5, 4, 3, 2];
        $sum = 0;

        foreach (str_split($vin) as $i => $char) {
            $sum += (ctype_digit($char) ? (int) $char : $values[$char]) * $weights[$i];
        }

        $check = $sum % 11;

        return $vin[8] === ($check === 10 ? 'X' : (string) $check);
    }

    /** @return array{year: int, make: string, model: string, trim: string}|null */
    public function decode(string $vin): ?array
    {
        $vin = self::clean($vin);
        if (! self::isValid($vin)) {
            return null;
        }

        return Cache::remember('vin:' . $vin, now()->addDays(30), function () use ($vin) {
            try {
                $row = Http::timeout(6)->acceptJson()->get(sprintf(self::URL, $vin))->throw()->json('Results.0') ?? [];
            } catch (Throwable $e) {
                Log::warning('VIN decode failed', ['error' => $e->getMessage()]);

                return null;
            }

            $year = (int) ($row['ModelYear'] ?? 0);
            $make = trim((string) ($row['Make'] ?? ''));
            $model = trim((string) ($row['Model'] ?? ''));

            if ($year < 1980 || $make === '' || $model === '') {
                return null;
            }

            return [
                'year'  => $year,
                'make'  => in_array(strtoupper($make), self::UPPER_MAKES, true) ? strtoupper($make) : ucwords(strtolower($make), " -"),
                'model' => $model,
                'trim'  => trim((string) (($row['Trim'] ?? '') ?: ($row['Series'] ?? ''))),
            ];
        }) ?: null;
    }
}
