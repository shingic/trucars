<?php

namespace Database\Seeders;

use App\Models\Vehicle;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class DealerInventorySeeder extends Seeder
{
    public function run(): void
    {
        $inventoryCsvPath = database_path('data/Chilliwack_Mitsubishi.csv');

        if (! is_readable($inventoryCsvPath)) {
            $this->command->warn("Inventory CSV not found at {$inventoryCsvPath} — skipping import.");
            return;
        }

        Artisan::call('vehicles:import', [
            'path' => $inventoryCsvPath,
        ], $this->command->getOutput());

        // The importer leaves everything unpublished. For local dev, publish the
        // certified cars so the certified-only marketplace actually has stock.
        $publishedCount = Vehicle::where('is_certified', true)
            ->update(['is_published' => true]);

        $this->command->info("Published {$publishedCount} certified vehicles to the marketplace.");
    }
}
