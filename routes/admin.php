<?php

use App\Http\Controllers\Admin\TodayController;
use Illuminate\Support\Facades\Route;

// Načítá se z routes/web.php uvnitř skupiny /admin (přihlášená kadeřnice).

Route::get('/', TodayController::class)->name('today');
