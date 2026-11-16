<?php

use App\Http\Controllers\System\CronController;
use App\Http\Controllers\System\SetupController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Hosting bez příkazové řádky
|--------------------------------------------------------------------------
| Tyto adresy běží mimo skupinu `web` (bez session a cookies) a bez tajného klíče
| z konfigurace neexistují (404). Klíče: LUXIO_SETUP_KEY a LUXIO_CRON_KEY.
*/

Route::get('/instalace/{key}', [SetupController::class, 'show'])->name('system.setup');
Route::post('/instalace/{key}', [SetupController::class, 'store'])->name('system.setup.store');

Route::get('/cron/{key}', CronController::class)->name('system.cron');
