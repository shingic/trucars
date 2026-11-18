<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// A $150 deposit that was paid but never became a reservation goes back to
// the buyer's card. Needs the scheduler: * * * * * php artisan schedule:run
Schedule::command('deposits:reconcile')->everyFifteenMinutes()->withoutOverlapping();

// Buyers who haven't sent the documents they were asked for get one nudge,
// during the day only.
Schedule::command('deals:remind-documents')->hourly()->between('9:00', '19:00')->timezone('America/Toronto')->withoutOverlapping();

// Follow-ups to shoppers who started a checkout but didn't pay the deposit.
Schedule::command('checkouts:send-recovery')->everyFifteenMinutes()->withoutOverlapping();

// Emails about saved cars: price drops, a saved car gone, a nudge 3 days after saving.
Schedule::command('saved:send-alerts')->everyFifteenMinutes()->withoutOverlapping();

// The Email Centre's log keeps 12 months.
Schedule::command('email-log:prune')->daily();

// Car view counts keep 90 days.
Schedule::command('vehicles:views --prune')->daily();

// Dealer inventory from HomeNet's FTP: pull and import the files that changed.
Schedule::command('feeds:sync')->hourly()->withoutOverlapping(30);
