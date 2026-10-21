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
