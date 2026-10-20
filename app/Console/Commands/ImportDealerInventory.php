<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Dealer;
use App\Models\Vehicle;
use App\Support\CanadianAddress;
use Illuminate\Console\Command;

class ImportDealerInventory extends Command
{
    protected $signature = 'vehicles:import
        {path : Path to the dealer inventory CSV}
        {--dealer= : Link the feed to this existing dealer id (e.g. one created before rooftop ids were stored)}
        {--new-dealer : Create a new dealer even though one with the same name already exists}';

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
        $feedRooftopId = null;
        $importedCount = 0;
        $skippedForNoPrice = 0;
        $skippedForNotACar = 0;
        $skippedForOtherRooftop = 0;
        $skippedForVinOnOtherDealer = 0;

        while (($rowValues = fgetcsv($fileHandle)) !== false) {
            if (count($rowValues) !== count($headerColumns)) {
                continue; // a malformed line — leave it out rather than guess
            }

            $row = array_combine($headerColumns, $rowValues);

            // Resolve the dealership once, from the dealer columns in the file.
            if ($dealer === null) {
                $feedRooftopId = trim($row['rooftop_id'] ?? '') ?: null;
                $dealer = $this->resolveDealer($row, $feedRooftopId);

                if ($dealer === null) {
                    fclose($fileHandle);

                    return self::FAILURE;
                }
            }

            // One file, one rooftop. A row from another rooftop isn't this
            // dealer's car, whatever the name column says.
            if ($feedRooftopId !== null && trim($row['rooftop_id'] ?? '') !== $feedRooftopId) {
                $skippedForOtherRooftop++;
                continue;
            }

            // A VIN already on another dealer's lot is left there — never moved
            // between rooftops by an import.
            if (Vehicle::where('vin', $row['vin'])->where('dealer_id', '!=', $dealer->id)->exists()) {
                $skippedForVinOnOtherDealer++;
                continue;
            }

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
                    'is_manufacturer_certified' => $this->isFlaggedManufacturerCertified($row, $condition, $dealer),
                    'is_published'   => false, // the doorman decides this later
                ],
            );

            $importedCount++;
        }

        fclose($fileHandle);

        if ($dealer === null) {
            $this->warn('The file had no inventory rows.');

            return self::SUCCESS;
        }

        $this->info("Dealer: {$dealer->name} (#{$dealer->id}" . ($dealer->feed_rooftop_id ? ", rooftop {$dealer->feed_rooftop_id}" : '') . ')');

        if (! $dealer->hasCompleteAddress()) {
            $this->warn('Dealer address incomplete (missing: ' . implode(', ', $dealer->missingAddressFields()) . ') — its vehicles can’t be published until an admin completes it.');
        }

        $this->info("Imported / updated: {$importedCount} vehicles");
        $this->line("Skipped (no price): {$skippedForNoPrice}");
        $this->line("Skipped (not a car): {$skippedForNotACar}");
        $this->line("Skipped (another rooftop in the file): {$skippedForOtherRooftop}");
        $this->line("Skipped (VIN belongs to another dealer): {$skippedForVinOnOtherDealer}");
        $this->info('Marked certified (dev stand-in): ' . $dealer->vehicles()->where('is_certified', true)->count());
        $this->info('Manufacturer CPO (feed-flagged, franchise brand): ' . $dealer->vehicles()->where('is_manufacturer_certified', true)->count());

        if (blank($dealer->franchise_make)) {
            $this->warn('No franchise brand confirmed for this dealer — none of its stock can qualify as manufacturer CPO.');
        }

        return self::SUCCESS;
    }

    /**
     * Find or create the dealer this feed belongs to.
     *
     * The feed's rooftop id is the identity. Display names are not: two rooftops
     * can share one, so a name match alone never merges dealers when the feed
     * gives us an id. A dealer created before ids were stored is linked
     * explicitly with --dealer. Only a feed without a rooftop id falls back to
     * the old name match.
     *
     * @param  array<string, string>  $row
     */
    private function resolveDealer(array $row, ?string $feedRooftopId): ?Dealer
    {
        if ($this->option('dealer') !== null) {
            $dealer = Dealer::find($this->option('dealer'));

            if ($dealer === null) {
                $this->error("No dealer with id {$this->option('dealer')}.");

                return null;
            }

            if ($feedRooftopId !== null) {
                $linkedElsewhere = Dealer::where('feed_rooftop_id', $feedRooftopId)->whereKeyNot($dealer->id)->first();

                if ($dealer->feed_rooftop_id !== null && $dealer->feed_rooftop_id !== $feedRooftopId) {
                    $this->error("Dealer #{$dealer->id} is already linked to rooftop {$dealer->feed_rooftop_id}, not {$feedRooftopId}.");

                    return null;
                }

                if ($linkedElsewhere !== null) {
                    $this->error("Rooftop {$feedRooftopId} is already linked to dealer #{$linkedElsewhere->id} ({$linkedElsewhere->name}).");

                    return null;
                }

                $dealer->feed_rooftop_id = $feedRooftopId;
            }

            return $this->fillBlanksFromFeed($dealer, $row);
        }

        if ($feedRooftopId === null) {
            return $this->fillBlanksFromFeed(Dealer::firstOrNew(['name' => $row['dealer_name']]), $row);
        }

        $dealer = Dealer::where('feed_rooftop_id', $feedRooftopId)->first();

        if ($dealer !== null) {
            return $this->fillBlanksFromFeed($dealer, $row);
        }

        $sameName = Dealer::whereNull('feed_rooftop_id')->where('name', $row['dealer_name'])->first();

        if ($sameName !== null && ! $this->option('new-dealer')) {
            $this->error("Dealer #{$sameName->id} is also called “{$sameName->name}” but has no rooftop id, so it may or may not be this rooftop.");
            $this->line("  Same rooftop:      php artisan vehicles:import {$this->argument('path')} --dealer={$sameName->id}");
            $this->line("  Different rooftop: php artisan vehicles:import {$this->argument('path')} --new-dealer");

            return null;
        }

        return $this->fillBlanksFromFeed(new Dealer(['feed_rooftop_id' => $feedRooftopId]), $row);
    }

    /**
     * Copy the feed's dealer details into fields that are still empty. Values an
     * admin has already set or corrected are never overwritten by a re-import.
     * A new dealer starts as a draft for an admin to review and activate.
     *
     * @param  array<string, string>  $row
     */
    private function fillBlanksFromFeed(Dealer $dealer, array $row): Dealer
    {
        $province = CanadianAddress::normalizeProvince($row['dealer_region_code'] ?? null);
        $postalCode = CanadianAddress::normalizePostalCode($row['dealer_postal_code'] ?? null);
        $latitude = is_numeric($row['dealer_latitude'] ?? null) ? (float) $row['dealer_latitude'] : null;
        $longitude = is_numeric($row['dealer_longitude'] ?? null) ? (float) $row['dealer_longitude'] : null;

        $fromFeed = [
            'name'           => trim($row['dealer_name'] ?? '') ?: null,
            'street_address' => trim($row['dealer_address'] ?? '') ?: null,
            'city'           => trim($row['dealer_city'] ?? '') ?: null,
            'province'       => $province,
            'postal_code'    => $postalCode,
            // Only claim Canada when the feed's own province and postal code say so.
            'country'        => $province !== null && $postalCode !== null ? CanadianAddress::COUNTRY : null,
            'phone'          => trim($row['dealer_phone'] ?? '') ?: null,
            'email'          => filter_var(trim($row['dealer_email'] ?? ''), FILTER_VALIDATE_EMAIL) ?: null,
        ];

        // Coordinates travel as a pair and only when both are plausible.
        if ($latitude !== null && $longitude !== null && abs($latitude) <= 90 && abs($longitude) <= 180 && ($latitude != 0.0 || $longitude != 0.0)) {
            $fromFeed['latitude'] = $latitude;
            $fromFeed['longitude'] = $longitude;
        }

        foreach ($fromFeed as $field => $value) {
            if ($value !== null && blank($dealer->{$field})) {
                $dealer->{$field} = $value;
            }
        }

        // Province's usual zone as a starting value; an admin confirms or corrects it.
        if (blank($dealer->timezone)) {
            $dealer->timezone = CanadianAddress::defaultTimezoneFor($dealer->province);
        }

        $dealer->save();

        return $dealer;
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
     * Manufacturer CPO only when the feed itself flags the unit certified, it's
     * used, and it's the dealer's confirmed franchise brand — a Mitsubishi
     * store's certified Tiguan can't be Volkswagen CPO. Without a confirmed
     * franchise brand nothing qualifies.
     *
     * @param  array<string, string>  $row
     */
    private function isFlaggedManufacturerCertified(array $row, string $condition, Dealer $dealer): bool
    {
        $feedSaysCertified = in_array(strtoupper(trim($row['certified'] ?? '')), ['TRUE', '1', 'YES'], true);

        return $feedSaysCertified
            && $condition === 'USED'
            && filled($dealer->franchise_make)
            && strcasecmp(trim($row['make']), trim($dealer->franchise_make)) === 0;
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
