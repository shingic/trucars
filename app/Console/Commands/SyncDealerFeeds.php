<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\FeedStale;
use App\Models\Rooftop;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Imports each store's inventory file from the folder HomeNet uploads to
 * (config filesystems.disks.homenet) when it has changed since the last run. A store whose file can't be read keeps
 * its cars exactly as they are; the error is recorded on the store. A store
 * whose file hasn't changed in STALE_HOURS gets flagged by email once a day,
 * so sold cars don't quietly stay listed.
 */
class SyncDealerFeeds extends Command
{
    protected $signature = 'feeds:sync
        {--status : Show each store\'s feed, without pulling anything}
        {--force : Import every file, even ones that haven\'t changed}';

    protected $description = 'Import the dealer inventory files HomeNet uploaded that changed since the last run';

    public const STALE_HOURS = 48;

    public function handle(): int
    {
        $stores = Rooftop::query()->whereNotNull('feed_file')->with('dealer')->orderBy('id')->get();

        if ($this->option('status')) {
            $this->showStatus($stores);

            return self::SUCCESS;
        }

        if ($stores->isEmpty()) {
            $this->line('No stores have a feed file. Run php artisan feeds:setup-homenet first.');

            return self::SUCCESS;
        }

        $folder = (string) config('filesystems.disks.homenet.root');
        if (! is_dir($folder)) {
            $this->error("HomeNet's upload folder isn't there: {$folder}. Set HOMENET_FEED_PATH in .env.");

            return self::FAILURE;
        }

        $failed = 0;
        foreach ($stores as $store) {
            if (! $this->syncStore($store)) {
                $failed++;
            }
        }

        $this->alertStaleFeeds($stores->each->refresh());

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function syncStore(Rooftop $store): bool
    {
        $name = $store->dealer?->name ?? "Store #{$store->id}";

        try {
            $disk = Storage::disk('homenet');

            if (! $disk->exists($store->feed_file)) {
                throw new \RuntimeException("{$store->feed_file} isn't in HomeNet's upload folder.");
            }

            $modifiedAt = Carbon::createFromTimestamp($disk->lastModified($store->feed_file));

            if (! $this->option('force') && $store->feed_file_modified_at !== null && $modifiedAt->lte($store->feed_file_modified_at)) {
                $store->update(['feed_synced_at' => now(), 'feed_error' => null]);
                $this->line("{$name}: unchanged since {$modifiedAt->toDayDateTimeString()}");

                return true;
            }

            $localPath = $disk->path($store->feed_file);

            // New cars are published straight away; nothing shows while the
            // store is still a draft. Sold cars come off as usual.
            $exitCode = Artisan::call('vehicles:import', ['path' => $localPath, '--publish' => true]);
            $output = trim(Artisan::output());

            if ($exitCode !== 0) {
                throw new \RuntimeException('Import failed: ' . mb_substr($output, -400));
            }

            $store->update([
                'feed_file_modified_at' => $modifiedAt,
                'feed_synced_at'        => now(),
                'feed_error'            => null,
                'feed_stale_alerted_at' => null,
            ]);
            $this->info("{$name}: imported ({$modifiedAt->toDayDateTimeString()})");
            $this->line(preg_replace('/^/m', '    ', $output));

            return true;
        } catch (\Throwable $e) {
            $store->update(['feed_error' => mb_substr($e->getMessage(), 0, 500)]);
            Log::warning('Feed sync failed', ['rooftop_id' => $store->id, 'file' => $store->feed_file, 'error' => $e->getMessage()]);
            $this->error("{$name}: {$e->getMessage()}");

            return false;
        }
    }

    /** One email a day listing the stores whose file has stopped changing (or can't be read). */
    private function alertStaleFeeds($stores): void
    {
        $cutoff = now()->subHours(self::STALE_HOURS);

        $stale = $stores->filter(fn (Rooftop $store) => ($store->feed_file_modified_at ?? $store->created_at)?->lt($cutoff)
            && ($store->feed_stale_alerted_at === null || $store->feed_stale_alerted_at->lt(now()->subDay())));

        if ($stale->isEmpty()) {
            return;
        }

        try {
            Mail::to(config('site.contact_email'))->send(new FeedStale($stale->values()));
            Rooftop::whereKey($stale->modelKeys())->update(['feed_stale_alerted_at' => now()]);
            $this->warn("Stale feeds emailed: {$stale->count()}");
        } catch (\Throwable $e) {
            Log::warning('Stale feed email failed', ['error' => $e->getMessage()]);
        }
    }

    private function showStatus($stores): void
    {
        if ($stores->isEmpty()) {
            $this->line('No stores have a feed file yet.');

            return;
        }

        $this->table(['Store', 'Status', 'File', 'File changed', 'Last checked', 'Problem'], $stores->map(fn (Rooftop $store) => [
            $store->dealer?->name,
            $store->dealer?->status,
            $store->feed_file,
            $store->feed_file_modified_at?->diffForHumans() ?? 'never',
            $store->feed_synced_at?->diffForHumans() ?? 'never',
            $store->feed_error ?? '',
        ])->all());
    }
}
