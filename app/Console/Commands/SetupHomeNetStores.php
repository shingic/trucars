<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\Dealer;
use App\Models\DealerFee;
use App\Models\Rooftop;
use App\Support\CanadianAddress;
use App\Support\Feeds\HomeNetRow;
use App\Support\PostalAreas;
use Illuminate\Console\Command;

/**
 * Creates the stores Trumo gets HomeNet feeds for (database/data/feeds/
 * homenet-stores.php): the dealer and its store, franchise brand, fee
 * schedule (with the fees its feed price leaves out marked to be added) and
 * the feed file to import. Stores start as drafts; they go live from the
 * Command Centre. Safe to run again: a store that exists is left as it is,
 * apart from linking its feed file if that's missing.
 *
 * A dealer already on Trumo at the same street address and postal code (one
 * set up from an earlier feed) is the same store: the HomeNet feed is linked
 * to it instead of creating a second one, so its cars aren't split in two.
 */
class SetupHomeNetStores extends Command
{
    protected $signature = 'feeds:setup-homenet
        {--file= : Use another store list instead of database/data/feeds/homenet-stores.php}';

    protected $description = 'Create the dealers and stores Trumo receives HomeNet inventory feeds for';

    public function handle(): int
    {
        $path = $this->option('file') ?: database_path('data/feeds/homenet-stores.php');
        $stores = require $path;
        $created = 0;

        foreach ($stores as $store) {
            $feedId = HomeNetRow::storeIdFor($store['file']);
            $existing = Rooftop::where('feed_rooftop_id', $feedId)->first();

            if ($existing !== null) {
                if (blank($existing->feed_file)) {
                    $existing->update(['feed_file' => $store['file']]);
                }
                $this->fillRegistration($existing->dealer, $store);
                $this->line("Already set up: {$existing->dealer->name}");

                continue;
            }

            $brand = Brand::whereRaw('lower(name) = ?', [mb_strtolower($store['brand'])])->first();
            if ($brand === null) {
                $this->error("No brand called {$store['brand']} (for {$store['name']}). Add it under Brands first.");

                return self::FAILURE;
            }

            $postalCode = CanadianAddress::normalizePostalCode($store['postal_code']);

            if (($sameStore = $this->dealerAt($store['address'], $postalCode)) !== null) {
                $this->linkExisting($sameStore, $store, $feedId, $brand);

                continue;
            }

            $area = $postalCode ? PostalAreas::forPostalCode($postalCode) : null;
            $province = CanadianAddress::normalizeProvince($store['province']);

            $dealer = Dealer::create([
                'feed_rooftop_id'     => $feedId,
                'name'                => $store['name'],
                'street_address'      => $store['address'],
                'city'                => $store['city'],
                'province'            => $province,
                'postal_code'         => $postalCode,
                'country'             => CanadianAddress::COUNTRY,
                'latitude'            => $area['latitude'] ?? null,
                'longitude'           => $area['longitude'] ?? null,
                'timezone'            => CanadianAddress::defaultTimezoneFor($province),
                'phone'               => $store['phone'],
                'registration_number' => $store['registration'] ?? null,
                'brand_id'            => $brand->id,
                'certifies_at_pickup' => true,
                'status'              => Dealer::STATUS_DRAFT,
            ]);

            $dealer->syncPrimaryRooftop()->update(['feed_file' => $store['file']]);

            $this->addFees($dealer, $store['fees']);

            $added = collect($store['fees'])->filter(fn ($fee) => $fee[2])->sum(fn ($fee) => $fee[1]);
            $this->info("Created: {$dealer->name} ({$dealer->city}, {$province}) · {$brand->name}" . ($added ? ' · adds $' . number_format($added / 100) . ' to each feed price' : ' · feed price already all-in'));
            $created++;
        }

        $this->newLine();
        if ($this->linked > 0) {
            $this->info("{$this->linked} existing " . ($this->linked === 1 ? 'store' : 'stores') . ' linked to their HomeNet feed.');
        }
        $this->info("{$created} new " . ($created === 1 ? 'store' : 'stores') . ', as drafts. Import their feeds (php artisan feeds:sync), check the fees, then make each one live from the Command Centre.');

        return self::SUCCESS;
    }

    private int $linked = 0;

    /** A dealer whose main store is at this street address and postal code. */
    private function dealerAt(string $address, ?string $postalCode): ?Dealer
    {
        if ($postalCode === null) {
            return null;
        }

        $street = fn (?string $line) => preg_replace('/[^a-z0-9]/', '', strtr(mb_strtolower((string) $line), ['avenue' => 'ave', 'road' => 'rd', 'street' => 'st', 'highway' => 'hwy']));

        return Dealer::where('postal_code', $postalCode)->get()
            ->first(fn (Dealer $dealer) => $street($dealer->street_address) === $street($address));
    }

    /**
     * Point an existing dealer at its HomeNet feed: the feed id (on the dealer
     * and its main store), the feed file, its brand and certify-at-pickup.
     * Its name and status are left alone; fees are added only if it has none.
     *
     * @param  array<string, mixed>  $store
     */
    private function linkExisting(Dealer $dealer, array $store, string $feedId, Brand $brand): void
    {
        $dealer->feed_rooftop_id = $feedId;
        $dealer->certifies_at_pickup = true;
        if (blank($dealer->registration_number)) {
            $dealer->registration_number = $store['registration'] ?? null;
        }
        if ($dealer->brand_id === null) {
            $dealer->brand_id = $brand->id;
        }
        $dealer->save();
        $dealer->syncPrimaryRooftop()->update(['feed_file' => $store['file']]);

        if ($dealer->fees()->doesntExist()) {
            $this->addFees($dealer, $store['fees']);
        }

        $this->info("Linked: {$dealer->name} (#{$dealer->id}) is the same store as {$store['name']}; its feed is now {$store['file']}.");
        $this->linked++;
    }

    /** The dealer number from the store list, onto a store set up before it was recorded. */
    private function fillRegistration(?Dealer $dealer, array $store): void
    {
        if ($dealer !== null && blank($dealer->registration_number) && filled($store['registration'] ?? null)) {
            $dealer->update(['registration_number' => $store['registration']]);
            $this->info("Dealer number added: {$dealer->name} #{$store['registration']}");
        }
    }

    /** @param  array<int, array{0: string, 1: int, 2: bool}>  $fees */
    private function addFees(Dealer $dealer, array $fees): void
    {
        foreach (array_values($fees) as $i => [$label, $cents, $added]) {
            DealerFee::create([
                'dealer_id'           => $dealer->id,
                'label'               => $label,
                'kind'                => DealerFee::KIND_INCLUDED,
                'added_to_feed_price' => $added,
                'amount_in_cents'     => $cents,
                'sort_order'          => $i,
            ]);
        }
    }
}
