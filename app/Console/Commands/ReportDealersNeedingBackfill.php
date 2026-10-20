<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Dealer;
use Illuminate\Console\Command;

class ReportDealersNeedingBackfill extends Command
{
    protected $signature = 'dealers:needs-backfill';

    protected $description = 'List dealers missing address or timezone data, and how many published vehicles they carry';

    /**
     * Read-only. Lists what each dealer still needs so an admin can complete
     * it in /admin/dealers — nothing is filled in or guessed here.
     */
    public function handle(): int
    {
        $dealers = Dealer::query()
            ->withCount(['vehicles as published_vehicles_count' => fn ($query) => $query->where('is_published', true)])
            ->orderBy('id')
            ->get()
            ->filter(fn (Dealer $dealer) => ! $dealer->hasCompleteAddress() || blank($dealer->timezone));

        if ($dealers->isEmpty()) {
            $this->info('Every dealer has a complete address and a timezone.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Dealer', 'Status', 'Missing', 'Published vehicles'],
            $dealers->map(fn (Dealer $dealer) => [
                $dealer->id,
                $dealer->name,
                $dealer->status,
                implode(', ', [...$dealer->missingAddressFields(), ...(blank($dealer->timezone) ? ['timezone'] : [])]),
                $dealer->published_vehicles_count,
            ])->all(),
        );

        $this->warn("{$dealers->count()} dealer(s) need backfill. Published vehicles above stay listed, but no further vehicles can be published for a dealer until its address is complete.");

        return self::SUCCESS;
    }
}
