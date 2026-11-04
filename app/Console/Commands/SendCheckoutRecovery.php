<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Marketing\CheckoutRecovery;
use Illuminate\Console\Command;

class SendCheckoutRecovery extends Command
{
    protected $signature = 'checkouts:send-recovery';

    protected $description = 'Email shoppers who started a checkout but didn\'t pay the deposit (1 hour, 1 day, 3 days, 7 days)';

    public function handle(CheckoutRecovery $recovery): int
    {
        if ($reason = CheckoutRecovery::blockedReason()) {
            $this->warn('Paused: ' . $reason);

            return self::SUCCESS;
        }

        $this->info('Recovery emails sent: ' . $recovery->run());

        return self::SUCCESS;
    }
}
