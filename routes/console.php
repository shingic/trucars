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
