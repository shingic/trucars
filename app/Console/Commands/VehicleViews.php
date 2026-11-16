<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The most-viewed cars, so you can pick a sensible HOT_VIEWS_THRESHOLD
 * before switching the "Popular today" tag on. --prune clears old rows.
 */
class VehicleViews extends Command
{
    protected $signature = 'vehicles:views
        {--days=7 : Look back this many days}
        {--top=20 : How many cars to list}
        {--prune : Delete view rows older than 90 days instead}';

    protected $description = 'Show the most-viewed cars (one view per visitor per car per day)';

    public function handle(): int
    {
        if ($this->option('prune')) {
            $deleted = DB::table('vehicle_views')->where('viewed_on', '<', today()->subDays(90)->toDateString())->delete();
            $this->info("Deleted {$deleted} old view rows.");

            return self::SUCCESS;
        }

        $days = max(1, (int) $this->option('days'));
        $today = today()->toDateString();

        $rows = DB::table('vehicle_views')
            ->join('vehicles', 'vehicles.id', '=', 'vehicle_views.vehicle_id')
            ->leftJoin('dealers', 'dealers.id', '=', 'vehicles.dealer_id')
            ->where('viewed_on', '>=', today()->subDays($days - 1)->toDateString())
            ->groupBy('vehicles.id', 'vehicles.model_year', 'vehicles.make', 'vehicles.model', 'dealers.name')
            ->orderByDesc('total')
            ->limit(max(1, (int) $this->option('top')))
            ->get([
                'vehicles.id', 'vehicles.model_year', 'vehicles.make', 'vehicles.model', 'dealers.name as dealer',
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN viewed_on = '{$today}' THEN 1 ELSE 0 END) as today"),
            ]);

        if ($rows->isEmpty()) {
            $this->line("No views in the last {$days} days.");

            return self::SUCCESS;
        }

        $this->table(['Car', 'Dealer', 'Today', "Last {$days} days"], $rows->map(fn ($r) => [
            "#{$r->id} {$r->model_year} {$r->make} {$r->model}", $r->dealer, $r->today, $r->total,
        ]));

        $threshold = config('site.hot_views_threshold');
        $this->line($threshold ? "\"Popular today\" shows at {$threshold}+ visitors in a day." : '"Popular today" is off (HOT_VIEWS_THRESHOLD not set).');

        return self::SUCCESS;
    }
}
