<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Deposits;
use Illuminate\Console\Command;

class ReconcileDeposits extends Command
{
    protected $signature = 'deposits:reconcile';

    protected $description = 'Refund deposits that were paid but never became a reservation, and close abandoned ones';

    public function handle(Deposits $deposits): int
    {
        $this->info('Deposits reconciled: ' . $deposits->reconcile());

        return self::SUCCESS;
    }
}
