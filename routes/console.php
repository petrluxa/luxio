<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Plánovač
|--------------------------------------------------------------------------
| Na serveru stačí jediný cron každou minutu:
|   * * * * * cd /cesta/k/aplikaci && php artisan schedule:run >> /dev/null 2>&1
*/

// Připomínky termínů (48 h předem, volitelně druhá).
Schedule::command('bookings:send-reminders')->everyFiveMinutes()->withoutOverlapping();

// Skončené termíny označit jako proběhlé.
Schedule::command('bookings:complete-past')->hourly();

// Fronta (push upozornění). Na hostingu bez trvale běžícího procesu ji každou minutu
// vyřídí plánovač; kde běží `queue:work` pod supervizorem, stačí tento řádek vypnout
// nastavením QUEUE_VIA_SCHEDULER=false.
if (config('luxio.queue_via_scheduler')) {
    Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')
        ->everyMinute()
        ->withoutOverlapping(5);
}

Schedule::command('queue:prune-failed --hours=336')->daily();
