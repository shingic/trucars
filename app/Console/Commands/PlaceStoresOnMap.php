<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Dealer;
use App\Models\Rooftop;
use App\Support\AddressLookup;
use Illuminate\Console\Command;

class PlaceStoresOnMap extends Command
{
    protected $signature = 'stores:place-on-map {--force : Re-place stores that already have coordinates}';

    protected $description = 'Look up map coordinates from the address for every dealer and store missing them, so shoppers see a distance';

    public function handle(AddressLookup $lookup): int
    {
        if (! $lookup->enabled()) {
            $this->warn('GOOGLE_PLACES_KEY is not set: stores are placed at the centre of their postal area (approximate).');
        }

        $placed = 0;
        $skipped = [];

        // Dealers first: saving one copies its point onto its primary rooftop.
        foreach ([fn () => Dealer::query()->orderBy('id')->get(), fn () => Rooftop::query()->with('dealer')->orderBy('id')->get()] as $load) {
            foreach ($load() as $store) {
                if (! $this->option('force') && $store->hasCoordinates()) {
                    continue;
                }

                $label = $store instanceof Rooftop ? "{$store->dealer?->name} › {$store->display_name}" : $store->name;

                if ($store->placeOnMapFromAddress(force: true)) {
                    $store->save();
                    $placed++;
                    $this->line("Placed {$label}: {$store->latitude}, {$store->longitude}");
                } else {
                    $skipped[] = [$label, $store->hasCompleteAddress() ? 'Address could not be placed' : 'Address incomplete: ' . implode(', ', $store->missingAddressFields())];
                }
            }
        }

        $this->info("Placed {$placed} " . ($placed === 1 ? 'store' : 'stores') . ' on the map.');

        if ($skipped !== []) {
            $this->warn('Still without coordinates (no distance shown for their cars):');
            $this->table(['Store', 'Why'], $skipped);
        }

        return self::SUCCESS;
    }
}
