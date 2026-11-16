<?php

use App\Http\Controllers\Admin\BookingController;
use App\Http\Controllers\Admin\CalendarController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\HoursController;
use App\Http\Controllers\Admin\ServiceController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\TodayController;
use Illuminate\Support\Facades\Route;

// Načítá se z routes/web.php uvnitř skupiny /admin (přihlášená kadeřnice).

Route::get('/', TodayController::class)->name('today');
Route::get('/kalendar', CalendarController::class)->name('calendar');
Route::get('/ke-schvaleni', [BookingController::class, 'pending'])->name('pending');

// Rezervace
Route::prefix('rezervace')->name('bookings.')->group(function () {
    Route::get('/nova', [BookingController::class, 'create'])->name('create');
    Route::get('/terminy', [BookingController::class, 'slots'])->middleware('background')->name('slots');
    Route::post('/', [BookingController::class, 'store'])->name('store');

    Route::get('/{booking}', [BookingController::class, 'show'])->whereNumber('booking')->name('show');
    Route::post('/{booking}/poznamka', [BookingController::class, 'note'])->name('note');
    Route::post('/{booking}/schvalit', [BookingController::class, 'approve'])->name('approve');
    Route::post('/{booking}/zamitnout', [BookingController::class, 'reject'])->name('reject');
    Route::post('/{booking}/zrusit', [BookingController::class, 'cancel'])->name('cancel');
    Route::post('/{booking}/neprisla', [BookingController::class, 'noShow'])->name('no-show');
    Route::post('/{booking}/probehla', [BookingController::class, 'complete'])->name('complete');
    Route::get('/{booking}/presun', [BookingController::class, 'moveForm'])->name('move');
    Route::post('/{booking}/presun', [BookingController::class, 'move'])->name('move.store');
});

// Zákaznice
Route::prefix('zakaznice')->name('customers.')->group(function () {
    Route::get('/', [CustomerController::class, 'index'])->name('index');
    Route::get('/hledat', [CustomerController::class, 'lookup'])->middleware('background')->name('lookup');
    Route::get('/nova', [CustomerController::class, 'create'])->name('create');
    Route::post('/', [CustomerController::class, 'store'])->name('store');
    Route::get('/{customer}', [CustomerController::class, 'show'])->whereNumber('customer')->name('show');
    Route::post('/{customer}', [CustomerController::class, 'update'])->whereNumber('customer')->name('update');
    Route::post('/{customer}/blokace', [CustomerController::class, 'block'])->name('block');
    Route::post('/{customer}/odkaz', [CustomerController::class, 'issueLink'])->name('link');
    Route::post('/{customer}/odkazy/zrusit', [CustomerController::class, 'revokeLinks'])->name('links.revoke');
});

// Služby
Route::prefix('sluzby')->name('services.')->group(function () {
    Route::get('/', [ServiceController::class, 'index'])->name('index');
    Route::get('/nova', [ServiceController::class, 'create'])->name('create');
    Route::post('/', [ServiceController::class, 'store'])->name('store');
    Route::get('/{service}', [ServiceController::class, 'edit'])->whereNumber('service')->name('edit');
    Route::post('/{service}', [ServiceController::class, 'update'])->whereNumber('service')->name('update');
    Route::post('/{service}/poradi', [ServiceController::class, 'reorder'])->name('reorder');
});

// Pracovní doba a volno
Route::get('/pracovni-doba', [HoursController::class, 'edit'])->name('hours');
Route::post('/pracovni-doba', [HoursController::class, 'update'])->name('hours.update');
Route::post('/volno', [HoursController::class, 'storeTimeOff'])->name('time-off.store');
Route::post('/volno/{timeOff}/smazat', [HoursController::class, 'destroyTimeOff'])->name('time-off.destroy');
Route::post('/vyjimky', [HoursController::class, 'storeOverride'])->name('overrides.store');
Route::post('/vyjimky/{override}/smazat', [HoursController::class, 'destroyOverride'])->name('overrides.destroy');

// Více, nastavení, kadeřnice
Route::get('/vice', [SettingsController::class, 'more'])->name('more');
Route::post('/heslo', [SettingsController::class, 'password'])->name('password');
Route::get('/nastaveni', [SettingsController::class, 'edit'])->name('settings');
Route::post('/nastaveni', [SettingsController::class, 'update'])->name('settings.update');
Route::get('/kadernice/nova', [SettingsController::class, 'createStaff'])->name('staff.create');
Route::post('/kadernice', [SettingsController::class, 'storeStaff'])->name('staff.store');
Route::get('/kadernice/{member}', [SettingsController::class, 'editStaff'])->whereNumber('member')->name('staff.edit');
Route::post('/kadernice/{member}', [SettingsController::class, 'updateStaff'])->whereNumber('member')->name('staff.update');
