<?php

namespace Database\Seeders;

use App\Models\Dealer;
use App\Models\DealerFee;
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

        $this->seedDefaultFees();
    }

    /**
     * Give every dealer a starter fee schedule so the checkout breakdown has
     * something to show out of the box. Idempotent — a dealer that already has
     * fees is left untouched, so re-running migrate:fresh --seed stays green and
     * never doubles anyone's fees.
     *
     * The split follows OMVIC all-in pricing: freight/PDI and admin are the
     * dealer's own costs and live INSIDE the advertised price ('included');
     * licensing and registration are at-cost government charges added at
     * delivery ('pass_through'). Amounts are in cents, like the rest of the schema.
     */
    protected function seedDefaultFees(): void
    {
        $defaultFees = [
            ['label' => 'Freight & PDI', 'kind' => DealerFee::KIND_INCLUDED,     'amount_in_cents' => 14900],
            ['label' => 'Dealer admin',  'kind' => DealerFee::KIND_INCLUDED,     'amount_in_cents' => 49900],
            ['label' => 'Licensing',     'kind' => DealerFee::KIND_PASS_THROUGH, 'amount_in_cents' => 5900],
            ['label' => 'Registration',  'kind' => DealerFee::KIND_PASS_THROUGH, 'amount_in_cents' => 13900],
        ];

        $dealersSeeded = 0;

        foreach (Dealer::all() as $dealer) {
            if ($dealer->fees()->exists()) {
                continue;
            }

            foreach ($defaultFees as $sortOrder => $fee) {
                $dealer->fees()->create($fee + ['sort_order' => $sortOrder]);
            }

            $dealersSeeded++;
        }

        $this->command->info("Seeded a default fee schedule for {$dealersSeeded} dealer(s).");
    }
}
