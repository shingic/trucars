<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\Dealer;
use App\Models\Rooftop;
use App\Models\Vehicle;
use App\Support\CanadianAddress;
use App\Support\CpoRules;
use App\Support\FeedText;
use App\Support\Feeds\HomeNetRow;
use Illuminate\Console\Command;

class ImportDealerInventory extends Command
{
    protected $signature = 'vehicles:import
        {path : Path to the dealer inventory CSV}
        {--dealer= : Link the feed to this existing dealer id (e.g. one created before rooftop ids were stored)}
        {--new-dealer : Create a new dealer even though one with the same name already exists}
        {--new-rooftop : With --dealer, add the feed as another rooftop of that dealer}
        {--keep-missing : Leave cars that are no longer in the feed as they are (default: unpublish them)}
        {--brand= : Set the dealer franchise brand (e.g. Nissan) before importing, so its certified cars count as manufacturer CPO}
        {--publish : Publish the cars this import adds (and ones never listed before), instead of leaving them for review}
        {--activate : Make the dealer live once its address is complete}';

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

        $fileHandle = $this->openAsUtf8($pathToCsv);

        $delimiter = $this->detectDelimiter($fileHandle);
        $headerColumns = array_map(fn ($column) => FeedText::clean($column), fgetcsv($fileHandle, separator: $delimiter, escape: "\\") ?: []);

        // A HomeNet (Cox) export is read through its own column map; the store
        // is named after the file, since HomeNet doesn't put one in the rows.
        $this->homeNetStore = HomeNetRow::matches($headerColumns) ? HomeNetRow::storeIdFor($pathToCsv) : null;

        $sharedPhotos = $this->photosOnMoreThanOneCar($fileHandle, $delimiter, $headerColumns);

        $dealer = null;
        $rooftop = null;
        $feedRooftopId = null;
        $importedCount = 0;
        $newCount = 0;
        $skippedForNoPrice = 0;
        $skippedForNotACar = 0;
        $skippedForOtherRooftop = 0;
        $skippedForVinOnOtherRooftop = 0;
        $skippedForNotCpo = 0;
        $skippedForNew = 0;
        $certifyAtPickupCount = 0;
        $feesAddedInCents = 0;

        /** @var array<string, true> every VIN this rooftop's rows mention, imported or not (non-CPO rows excepted) */
        $vinsInFeed = [];
        $rowsForRooftop = 0;

        /** @var array<int, int> cars this run added, for --publish */
        $newVehicleIds = [];

        while (($rowValues = fgetcsv($fileHandle, separator: $delimiter, escape: "\\")) !== false) {
            if (count($rowValues) !== count($headerColumns)) {
                continue; // a malformed line — leave it out rather than guess
            }

            // Whatever encoding the dealer's system used, every value is plain
            // UTF-8 before anything reads it ("4Ã—4" → "4×4").
            $row = $this->rowFrom($headerColumns, $rowValues);

            if ($row === null) {
                $skippedForNotACar++;
                continue;
            }

            // Resolve the rooftop (and so its dealer) once, from the dealer columns in the file.
            if ($rooftop === null) {
                $feedRooftopId = trim($row['rooftop_id'] ?? '') ?: null;
                $rooftop = $this->resolveRooftop($row, $feedRooftopId);

                if ($rooftop === null) {
                    fclose($fileHandle);

                    return self::FAILURE;
                }

                $dealer = $rooftop->dealer;

                if ($this->option('brand') !== null && ! $this->applyBrand($dealer)) {
                    fclose($fileHandle);

                    return self::FAILURE;
                }

                $feesAddedInCents = $this->feesAddedToFeedPrice($dealer);
            }

            // One file, one rooftop. A row from another rooftop isn't this
            // dealer's car, whatever the name column says.
            if ($feedRooftopId !== null && trim($row['rooftop_id'] ?? '') !== $feedRooftopId) {
                $skippedForOtherRooftop++;
                continue;
            }

            $rowsForRooftop++;

            // Trumo lists certified pre-owned cars only: flagged certified by
            // the feed, or (for a dealer that certifies at pickup) its own
            // brand's used car within that brand's program limits. New cars
            // and other used cars are turned away, and don't count as "in the
            // feed" — so one already listed comes off the marketplace below.
            $flaggedCertified = $this->isCertifiedPreOwned($row);
            $atPickup = ! $flaggedCertified && $this->isCertifiableAtPickup($row, $dealer);

            if (! $flaggedCertified && ! $atPickup) {
                $skippedForNotCpo++;
                continue;
            }

            // Some feeds send brand-new cars as "Used" + certified, with a
            // placeholder odometer (e.g. 90 km). A car that's barely been
            // driven is new, not pre-owned, so it's turned away too.
            if ($this->isReallyNew($row)) {
                $skippedForNew++;
                continue;
            }

            $vinsInFeed[strtoupper(trim($row['vin']))] = true;

            // A VIN already at another rooftop — this dealer's or anyone's — is
            // left there. An import never moves a car between stores.
            $existing = Vehicle::where('vin', $row['vin'])->first();

            if ($existing !== null && ((int) $existing->dealer_id !== $dealer->id || ($existing->rooftop_id !== null && (int) $existing->rooftop_id !== $rooftop->id))) {
                $skippedForVinOnOtherRooftop++;
                continue;
            }

            if (in_array($row['make'], self::NON_CAR_MAKES, true)) {
                $skippedForNotACar++;
                continue;
            }

            $feedPriceInCents = $this->priceToCents($row['price']);
            if ($feedPriceInCents === null) {
                $skippedForNoPrice++;
                continue;
            }

            // All-in: the fees this dealer leaves out of its feed price go on top.
            $priceInCents = $feedPriceInCents + $feesAddedInCents;

            $condition = 'USED'; // every CPO car is a used car, whatever word the feed used
            $modelYear = (int) $row['year'];
            $kilometres = (int) $row['mileage'];

            $vehicle = $existing ?? new Vehicle(['vin' => $row['vin']]);

            $vehicle->fill([
                'dealer_id'      => $dealer->id,
                'rooftop_id'     => $rooftop->id,
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
                'feed_price_in_cents' => $feedPriceInCents,
                'transmission'   => $row['transmission'] ?: null,
                'drivetrain'     => $row['drivetrain'] ?: null,
                'fuel_type'      => $row['fuel_type'] ?: null,
                'photos'         => $this->ownPhotos($row, $sharedPhotos),
                'is_certified'   => $this->looksCertifiable($condition, $modelYear, $kilometres),
                'is_manufacturer_certified' => $atPickup || $this->isFlaggedManufacturerCertified($row, $condition, $dealer),
                'certify_at_pickup' => $atPickup,
            ]);

            if ($atPickup) {
                $certifyAtPickupCount++;
            }

            // New cars arrive unpublished for someone to review. A car that's
            // already listed stays listed — re-importing a feed must never take a
            // dealer's live inventory off the marketplace.
            if (! $vehicle->exists) {
                $vehicle->is_published = false;
                $newCount++;
            }

            // --publish covers new cars and ones that have never been listed
            // (e.g. imported for review from an earlier feed). A car that was
            // live and then taken down keeps first_listed_at, so stays down.
            $neverListed = ! $vehicle->exists || (! $vehicle->is_published && $vehicle->first_listed_at === null);
            $vehicle->save();

            if ($neverListed) {
                $newVehicleIds[] = $vehicle->id;
            }

            $importedCount++;
        }

        fclose($fileHandle);

        if ($rooftop === null) {
            $this->warn('The file had no inventory rows.');

            return self::SUCCESS;
        }

        $this->info("Dealer: {$dealer->name} (#{$dealer->id}" . ($rooftop->feed_rooftop_id ? ", rooftop {$rooftop->feed_rooftop_id}" : '') . ')');

        if (! $rooftop->is_primary) {
            $this->info("Store: {$rooftop->display_name}");
        }

        if (! $rooftop->hasCompleteAddress()) {
            $this->warn('Dealer address incomplete (missing: ' . implode(', ', $rooftop->missingAddressFields()) . ') — its vehicles can’t be published until an admin completes it.');
        }

        $this->info("Imported / updated: {$importedCount} vehicles ({$newCount} new" . ($this->option('publish') ? ')' : ', unpublished until reviewed)'));
        $this->line("Skipped (not certified pre-owned): {$skippedForNotCpo}");
        $this->line('Skipped (new car sent as used, under ' . self::NEW_CAR_MAX_KM . " km): {$skippedForNew}");
        $this->line("Skipped (no price): {$skippedForNoPrice}");
        $this->line("Skipped (not a car): {$skippedForNotACar}");
        $this->line("Skipped (another rooftop in the file): {$skippedForOtherRooftop}");
        $this->line("Skipped (VIN belongs to another rooftop): {$skippedForVinOnOtherRooftop}");
        $this->info('Marked certified (dev stand-in): ' . $rooftop->vehicles()->where('is_certified', true)->count());
        $this->info('Manufacturer CPO (feed-flagged, franchise brand): ' . $rooftop->vehicles()->where('is_manufacturer_certified', true)->where('certify_at_pickup', false)->count());

        if ($dealer->certifies_at_pickup) {
            $this->info("Certified at pickup (own brand, within the program's age/km limits): {$certifyAtPickupCount}");
        }

        if ($feesAddedInCents > 0) {
            $this->info('Fees added to each feed price (all-in): $' . number_format($feesAddedInCents / 100, 2));
        }

        if (blank($dealer->franchise_make)) {
            $this->warn('No franchise brand confirmed for this dealer — none of its stock can qualify as manufacturer CPO.');
        }

        $this->unpublishCarsMissingFromFeed($rooftop, $vinsInFeed, $rowsForRooftop);

        if ($this->option('activate')) {
            $this->activate($dealer->fresh());
        }

        if ($this->option('publish')) {
            $this->publishNew($newVehicleIds, $rooftop);
        }

        return self::SUCCESS;
    }

    /** --brand: link the dealer to its franchise brand; never silently switch one already set. */
    private function applyBrand(Dealer $dealer): bool
    {
        $brand = Brand::whereRaw('lower(name) = ?', [mb_strtolower(trim((string) $this->option('brand')))])->first();

        if ($brand === null) {
            $this->error("No brand called “{$this->option('brand')}”. Add it under Platform settings › Brands first.");

            return false;
        }

        if ($dealer->brand_id !== null && (int) $dealer->brand_id !== $brand->id) {
            $this->error("{$dealer->name} is already a {$dealer->franchise_make} dealer. Change its brand on the dealer profile if that's wrong.");

            return false;
        }

        if ($dealer->brand_id === null) {
            $dealer->update(['brand_id' => $brand->id]);
            $this->info("Brand: {$brand->name}");
        }

        return true;
    }

    /** --activate: live once the address and time zone are there (the same rule as the dealer page). */
    private function activate(Dealer $dealer): void
    {
        if ($dealer->isActive()) {
            return;
        }

        if (! $dealer->hasCompleteAddress() || blank($dealer->timezone)) {
            $this->warn("Not activated: {$dealer->name}'s address is incomplete. Finish it on the dealer page.");

            return;
        }

        $dealer->update(['status' => Dealer::STATUS_ACTIVE]);
        \App\Models\AuditLog::record('dealer.activated_by_import', $dealer, null, ['status' => Dealer::STATUS_ACTIVE]);
        $this->info("Activated: {$dealer->name} is live.");
    }

    /** @param  array<int, int>  $vehicleIds */
    private function publishNew(array $vehicleIds, Rooftop $rooftop): void
    {
        if (! $rooftop->hasCompleteAddress()) {
            $this->warn('Not published: the store address is incomplete.');

            return;
        }

        $published = 0;

        foreach (Vehicle::whereKey($vehicleIds)->get() as $vehicle) {
            $vehicle->update(['is_published' => true]);
            $published++;
        }

        $this->info("Published: {$published} new " . ($published === 1 ? 'car' : 'cars'));
    }

    /**
     * The feed is this rooftop's current stock, so a published car of this
     * rooftop that isn't in it any more has been sold or pulled — take it off
     * the marketplace. Cars at the dealer's other rooftops are never touched.
     * Unpublished, never deleted: reservations and history stay intact, and
     * it's back the moment it reappears in a feed and someone republishes it.
     *
     * A file with no rows for this rooftop is treated as broken, not as "the
     * dealer sold everything", so nothing is touched. A file whose rows are all
     * new or plain used cars is real, though: none of it is CPO, so nothing of
     * this rooftop's stays listed.
     *
     * @param  array<string, true>  $vinsInFeed
     */
    private function unpublishCarsMissingFromFeed(Rooftop $rooftop, array $vinsInFeed, int $rowsForRooftop): void
    {
        if ($this->option('keep-missing')) {
            $this->line('Cars no longer in the feed: left as they are (--keep-missing).');

            return;
        }

        if ($rowsForRooftop === 0) {
            $this->warn('No rows for this rooftop in the file — not unpublishing anything.');

            return;
        }

        $missing = $rooftop->vehicles()
            ->where('is_published', true)
            ->get()
            ->reject(fn (Vehicle $vehicle) => isset($vinsInFeed[strtoupper(trim($vehicle->vin))]));

        $missing->each(fn (Vehicle $vehicle) => $vehicle->update(['is_published' => false]));

        $this->info("Unpublished (no longer in the feed): {$missing->count()}");
    }

    /**
     * Find or create the rooftop this feed belongs to, and with it the dealer.
     *
     * The feed's rooftop id is the identity. Display names are not: two rooftops
     * can share one, so a name match alone never merges dealers when the feed
     * gives us an id. A dealer created before ids were stored is linked
     * explicitly with --dealer, and a dealer's second store is added with
     * --dealer and --new-rooftop. Only a feed without a rooftop id falls back
     * to the old name match, onto that dealer's main store.
     *
     * @param  array<string, string>  $row
     */
    private function resolveRooftop(array $row, ?string $feedRooftopId): ?Rooftop
    {
        $known = $feedRooftopId === null ? null : Rooftop::where('feed_rooftop_id', $feedRooftopId)->first();

        if ($this->option('dealer') !== null) {
            $dealer = Dealer::find($this->option('dealer'));

            if ($dealer === null) {
                $this->error("No dealer with id {$this->option('dealer')}.");

                return null;
            }

            if ($known !== null) {
                if ((int) $known->dealer_id !== $dealer->id) {
                    $this->error("Rooftop {$feedRooftopId} is already linked to dealer #{$known->dealer_id} ({$known->dealer->name}).");

                    return null;
                }

                return $this->fillBlanksFromFeed($known, $row);
            }

            $main = $dealer->primaryRooftop ?? $dealer->syncPrimaryRooftop();

            if ($feedRooftopId === null || $main->feed_rooftop_id === null) {
                $dealer->feed_rooftop_id ??= $feedRooftopId;

                return $this->fillBlanksFromFeed($dealer, $row);
            }

            if (! $this->option('new-rooftop')) {
                $this->error("Dealer #{$dealer->id} is already linked to rooftop {$main->feed_rooftop_id}, not {$feedRooftopId}.");
                $this->line("  Another store of this dealer: php artisan vehicles:import {$this->argument('path')} --dealer={$dealer->id} --new-rooftop");

                return null;
            }

            return $this->fillBlanksFromFeed(new Rooftop(['dealer_id' => $dealer->id, 'feed_rooftop_id' => $feedRooftopId]), $row);
        }

        if ($known !== null) {
            return $this->fillBlanksFromFeed($known, $row);
        }

        if ($feedRooftopId === null) {
            return $this->fillBlanksFromFeed(Dealer::firstOrNew(['name' => $row['dealer_name']]), $row);
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
     * A dealer is filled for its main store (mirrored onto the primary
     * rooftop); any other rooftop is filled directly.
     *
     * @param  array<string, string>  $row
     */
    private function fillBlanksFromFeed(Dealer|Rooftop $dealer, array $row): Rooftop
    {
        if ($dealer instanceof Rooftop && $dealer->is_primary) {
            return $this->fillBlanksFromFeed($dealer->dealer, $row);
        }

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

        // No coordinates in the feed: the centre of the store's postal area
        // (a few km at most), so distances work. An admin can refine them.
        if (! isset($fromFeed['latitude']) && $postalCode !== null && ($area = \App\Support\PostalAreas::forPostalCode($postalCode)) !== null) {
            $fromFeed['latitude'] = $area['latitude'];
            $fromFeed['longitude'] = $area['longitude'];
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

        return $dealer instanceof Dealer ? $dealer->syncPrimaryRooftop() : $dealer;
    }

    /**
     * Excel's "Unicode text" exports are UTF-16, which CSV parsing can't read
     * as is — convert those to UTF-8 first. Everything else is cleaned value
     * by value (FeedText::clean), BOMs included.
     */
    private function openAsUtf8(string $path)
    {
        $start = (string) file_get_contents($path, length: 2);

        if ($start !== "\xFF\xFE" && $start !== "\xFE\xFF") {
            return fopen($path, 'r');
        }

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, mb_convert_encoding((string) file_get_contents($path), 'UTF-8', 'UTF-16'));
        rewind($stream);

        return $stream;
    }

    /** Commas usually; tabs from Excel's text exports, semicolons from European-locale ones. */
    private function detectDelimiter($fileHandle): string
    {
        $firstLine = (string) fgets($fileHandle);
        rewind($fileHandle);

        $counts = [',' => substr_count($firstLine, ','), "\t" => substr_count($firstLine, "\t"), ';' => substr_count($firstLine, ';')];
        arsort($counts);

        return reset($counts) > 0 ? array_key_first($counts) : ',';
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
     * Images that appear on more than one car in the file — the dealer's
     * promo slides, award badges, "every CPO car includes" panels. They're
     * not pictures of the car, so no car keeps them. Leaves the file
     * positioned just after the header.
     *
     * @param  array<int, string>  $headerColumns
     * @return array<string, true>
     */
    private function photosOnMoreThanOneCar($fileHandle, string $delimiter, array $headerColumns): array
    {
        $start = ftell($fileHandle);
        $carsByPhoto = [];

        while (($values = fgetcsv($fileHandle, separator: $delimiter, escape: "\\")) !== false) {
            if (count($values) !== count($headerColumns)) {
                continue;
            }

            $row = $this->rowFrom($headerColumns, $values);
            if ($row === null) {
                continue;
            }
            $vin = strtoupper(trim($row['vin'] ?? ''));

            foreach (array_unique(array_map([$this, 'photoKey'], $this->splitPhotoUrls($row['photos'] ?? ''))) as $key) {
                $carsByPhoto[$key][$vin] = true;
            }
        }

        fseek($fileHandle, $start);

        return array_map(fn () => true, array_filter($carsByPhoto, fn ($cars) => count($cars) > 1));
    }

    /**
     * This car's own photos: none when the feed says they're the maker's
     * stock images, and never an image other cars share.
     *
     * @param  array<string, string>  $row
     * @param  array<string, true>  $sharedPhotos
     * @return array<int, string>
     */
    private function ownPhotos(array $row, array $sharedPhotos): array
    {
        if (str_contains(strtolower($row['photo_source'] ?? ''), 'stock')) {
            return [];
        }

        return array_values(array_filter(
            array_unique($this->splitPhotoUrls($row['photos'])),
            fn (string $url) => ! isset($sharedPhotos[$this->photoKey($url)]),
        ));
    }

    /**
     * The image itself: host and path, minus the CDN's resize/quality segments
     * ("w_1920,h_1440,q_75,c_l", "v1"), so the same image at two sizes matches.
     * The whole path, not just the file name — some feeds name every car's
     * photos 1.jpg, 2.jpg… in a folder per car.
     */
    private function photoKey(string $url): string
    {
        $segments = array_filter(
            explode('/', (string) parse_url($url, PHP_URL_PATH)),
            fn (string $segment) => $segment !== '' && ! str_contains($segment, ',') && preg_match('/^v\d+$/', $segment) !== 1,
        );

        return strtolower(parse_url($url, PHP_URL_HOST) . '/' . implode('/', $segments));
    }

    /**
     * @return array<int, string>
     */
    private function splitPhotoUrls(string $photos): array
    {
        return array_values(array_filter(array_map('trim', explode('|', $photos))));
    }

    /** Under this many km a car is new, whatever the feed calls it. */
    public const NEW_CAR_MAX_KM = 500;

    /** @param  array<string, string>  $row */
    private function isReallyNew(array $row): bool
    {
        $km = trim((string) ($row['mileage'] ?? ''));

        return $km !== '' && (int) $km < self::NEW_CAR_MAX_KM;
    }

    /**
     * Certified pre-owned, the only kind of car Trumo lists: the feed flags it
     * certified and it isn't new, or the feed's condition itself says CPO.
     *
     * @param  array<string, string>  $row
     */
    private function isCertifiedPreOwned(array $row): bool
    {
        $condition = strtoupper(trim($row['condition'] ?? ''));

        if (in_array($condition, ['CPO', 'CERTIFIED', 'CERTIFIED PRE-OWNED', 'CERTIFIED PREOWNED'], true)) {
            return true;
        }

        return $condition !== 'NEW' && in_array(strtoupper(trim($row['certified'] ?? '')), ['TRUE', '1', 'YES'], true);
    }

    /**
     * Manufacturer CPO: a certified pre-owned row (the only kind imported) of the
     * dealer's confirmed franchise brand — a Mitsubishi store's certified Tiguan
     * can't be Volkswagen CPO. Without a confirmed franchise brand nothing qualifies.
     *
     * @param  array<string, string>  $row
     */
    private function isFlaggedManufacturerCertified(array $row, string $condition, Dealer $dealer): bool
    {
        return $this->isCertifiedPreOwned($row)
            && $condition === 'USED'
            && CpoRules::sameFranchise($row['make'], $dealer->franchise_make);
    }

    /**
     * A used car the dealer will certify before handover: the dealer has
     * agreed to certify at pickup, it's the dealer's own brand (any make of
     * the franchise), and it's within that brand's program age/km limits.
     *
     * @param  array<string, string>  $row
     */
    private function isCertifiableAtPickup(array $row, Dealer $dealer): bool
    {
        return $dealer->certifies_at_pickup
            && strtoupper(trim($row['condition'] ?? '')) !== 'NEW'
            && CpoRules::sameFranchise($row['make'], $dealer->franchise_make)
            && CpoRules::fitsRules($row['make'], (int) $row['year'], (int) $row['mileage']);
    }

    /** The included fees this dealer's feed price leaves out, added on top so the price is all-in. */
    private function feesAddedToFeedPrice(Dealer $dealer): int
    {
        return (int) $dealer->fees()
            ->where('is_active', true)
            ->where('kind', \App\Models\DealerFee::KIND_INCLUDED)
            ->where('added_to_feed_price', true)
            ->sum('amount_in_cents');
    }

    /** The store a HomeNet file belongs to, or null for any other layout. */
    private ?string $homeNetStore = null;

    /**
     * One file row as the importer's columns: cleaned to UTF-8, and mapped
     * from HomeNet's layout when it's a HomeNet file. Null for a row that
     * isn't a car for sale.
     *
     * @param  array<int, string>  $headers
     * @param  array<int, string>  $values
     * @return array<string, string>|null
     */
    private function rowFrom(array $headers, array $values): ?array
    {
        $row = array_combine($headers, array_map(fn ($value) => FeedText::clean($value), $values));

        return $this->homeNetStore === null ? $row : HomeNetRow::toImportRow($row, $this->homeNetStore);
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
