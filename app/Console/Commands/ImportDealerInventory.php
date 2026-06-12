<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Dealer;
use Illuminate\Console\Command;

class ImportDealerInventory extends Command
{
    protected $signature = 'vehicles:import {path : Path to the dealer inventory CSV}';

    protected $description = 'Import a dealer inventory CSV into the dealers and vehicles tables';

    /**
     * Makes that aren't road cars (boats, RVs, motorcycles) — skipped on a car marketplace.
     */
    private const NON_CAR_MAKES = ['WELLCRAFT', 'Bayliner', 'Forest River', 'Triumph', 'Husqvarna'];

    public function handle(): int
    {
        $pathToCsv = $this->argument('path');

        if (! is_readable($pathToCsv)) {
            $this->error("Couldn't read a file at: {$pathToCsv}");
            return self::FAILURE;
        }

        $fileHandle = fopen($pathToCsv, 'r');

        $headerColumns = fgetcsv($fileHandle);
        // Some exports prepend a hidden UTF-8 marker to the first header — strip it.
        $headerColumns[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headerColumns[0]);

        $dealer = null;
        $importedCount = 0;
        $skippedForNoPrice = 0;
        $skippedForNotACar = 0;

        while (($rowValues = fgetcsv($fileHandle)) !== false) {
            if (count($rowValues) !== count($headerColumns)) {
                continue; // a malformed line — leave it out rather than guess
            }

            $row = array_combine($headerColumns, $rowValues);

            // Create the dealership once, from the dealer columns in the file.
            $dealer ??= Dealer::firstOrCreate(
                ['name' => $row['dealer_name']],
                ['city' => trim("{$row['dealer_city']}, {$row['dealer_region_code']}", ', ')],
            );

            if (in_array($row['make'], self::NON_CAR_MAKES, true)) {
                $skippedForNotACar++;
                continue;
            }

            $priceInCents = $this->priceToCents($row['price']);
            if ($priceInCents === null) {
                $skippedForNoPrice++;
                continue;
            }

            $condition = strtoupper(trim($row['condition']));
            $modelYear = (int) $row['year'];
            $kilometres = (int) $row['mileage'];

            $dealer->vehicles()->updateOrCreate(
                ['vin' => $row['vin']],
                [
                    'stock_number'   => $row['stockNumber'] ?: null,
                    'condition'      => $condition,
                    'model_year'     => $modelYear,
                    'make'           => $row['make'],
                    'model'          => $row['model'],
                    'trim'           => $row['trim'] ?: null,
                    'body_type'      => $row['body_type'] ?: null,
                    'colour'         => $row['exterior_color'] ?: null,
                    'kilometres'     => $kilometres,
                    'price_in_cents' => $priceInCents,
                    'transmission'   => $row['transmission'] ?: null,
                    'drivetrain'     => $row['drivetrain'] ?: null,
                    'fuel_type'      => $row['fuel_type'] ?: null,
                    'photos'         => $this->splitPhotoUrls($row['photos']),
                    'is_certified'   => $this->looksCertifiable($condition, $modelYear, $kilometres),
                    'is_published'   => false, // the doorman decides this later
                ],
            );

            $importedCount++;
        }

        fclose($fileHandle);

        $this->info("Dealer: {$dealer->name}");
        $this->info("Imported / updated: {$importedCount} vehicles");
        $this->line("Skipped (no price): {$skippedForNoPrice}");
        $this->line("Skipped (not a car): {$skippedForNotACar}");
        $this->info('Marked certified (dev stand-in): ' . $dealer->vehicles()->where('is_certified', true)->count());

        return self::SUCCESS;
    }

    private function priceToCents(string $price): ?int
    {
        $clean = trim($price);

        if ($clean === '' || ! is_numeric($clean) || (float) $clean <= 0) {
            return null;
        }

        return (int) round(((float) $clean) * 100);
    }

    /**
     * @return array<int, string>
     */
    private function splitPhotoUrls(string $photos): array
    {
        return array_values(array_filter(array_map('trim', explode('|', $photos))));
    }

    /**
     * Dev stand-in for the real per-brand programs: a recent, low-km used car
     * counts as certified for now. The doorman will replace this with each
     * manufacturer's actual age/kilometre rules later.
     */
    private function looksCertifiable(string $condition, int $modelYear, int $kilometres): bool
    {
        $ageInYears = (int) date('Y') - $modelYear;

        return $condition === 'USED' && $ageInYears <= 6 && $kilometres < 120000;
    }
}
