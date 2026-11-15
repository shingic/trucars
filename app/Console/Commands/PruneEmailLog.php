<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\EmailLog;
use Illuminate\Console\Command;

class PruneEmailLog extends Command
{
    protected $signature = 'email-log:prune';

    protected $description = 'Delete email log rows older than 12 months';

    public function handle(): int
    {
        $deleted = EmailLog::where('sent_at', '<', now()->subMonths(12))->delete();
        $this->info("Email log rows deleted: {$deleted}");

        return self::SUCCESS;
    }
}
