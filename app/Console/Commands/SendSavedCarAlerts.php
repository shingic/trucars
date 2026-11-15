<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Marketing\CheckoutRecovery;
use App\Support\Marketing\SavedCarAlerts;
use Illuminate\Console\Command;

class SendSavedCarAlerts extends Command
{
    protected $signature = 'saved:send-alerts';

    protected $description = 'Email shoppers about cars they saved: price drops, cars gone, and a nudge 3 days after saving';

    public function handle(SavedCarAlerts $alerts): int
    {
        if ($reason = CheckoutRecovery::blockedReason()) {
            $this->warn('Paused: ' . $reason);

            return self::SUCCESS;
        }

        $this->info('Saved car emails sent: ' . $alerts->run());

        return self::SUCCESS;
    }
}
