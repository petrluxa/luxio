<?php

use App\Http\Controllers\Admin\LoginController;
use App\Http\Controllers\Customer;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PublicBookingController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\PwaController;
use App\Http\Controllers\RecoverController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');
Route::get('/offline', [PwaController::class, 'offline'])->name('offline');

/*
|--------------------------------------------------------------------------
| Veřejný odkaz salonu – první rezervace nové zákaznice
|--------------------------------------------------------------------------
*/
Route::prefix('s/{salon}')->name('salon.')->group(function () {
    Route::get('/', [PublicBookingController::class, 'create'])->name('show');
    Route::get('/terminy', [PublicBookingController::class, 'slots'])->middleware('throttle:slots')->name('slots');
    Route::post('/', [PublicBookingController::class, 'store'])->middleware('throttle:6,1')->name('store');
    Route::get('/odeslano', [PublicBookingController::class, 'sent'])->name('sent');
});

/*
|--------------------------------------------------------------------------
| Osobní odkaz zákaznice (token místo hesla)
|--------------------------------------------------------------------------
*/
Route::prefix('k/{token}')->name('customer.')->middleware('customer.token')->group(function () {
    // Pomocné požadavky stránky (nepočítají se do limitu 20/min na otevírání obrazovek).
    Route::middleware('throttle:slots')->group(function () {
        Route::get('/rezervace/terminy', [Customer\BookingController::class, 'slots'])->name('booking.slots');
        Route::get('/manifest.webmanifest', [PwaController::class, 'customerManifest'])->name('manifest');
        Route::post('/push', [PushSubscriptionController::class, 'store'])->name('push.store');
        Route::delete('/push', [PushSubscriptionController::class, 'destroy'])->name('push.destroy');
    });

    Route::middleware('throttle:customer-link')->group(function () {
        Route::get('/', Customer\HomeController::class)->name('home');

        Route::get('/rezervace', [Customer\BookingController::class, 'create'])->name('booking.create');
        Route::post('/rezervace', [Customer\BookingController::class, 'store'])->name('booking.store');

        Route::get('/terminy', [Customer\BookingController::class, 'index'])->name('bookings');
        Route::get('/terminy/{booking}', [Customer\BookingController::class, 'show'])->whereNumber('booking')->name('booking.show');
        Route::post('/terminy/{booking}/zrusit', [Customer\BookingController::class, 'cancel'])->whereNumber('booking')->name('booking.cancel');

        Route::get('/profil', [Customer\ProfileController::class, 'edit'])->name('profile');
        Route::post('/profil', [Customer\ProfileController::class, 'update'])->name('profile.update');

        Route::get('/aplikace', Customer\OnboardingController::class)->name('onboarding');
    });
});

/*
|--------------------------------------------------------------------------
| Ztracený odkaz
|--------------------------------------------------------------------------
*/
Route::get('/obnovit', [RecoverController::class, 'create'])->name('recover');
Route::post('/obnovit', [RecoverController::class, 'store'])->middleware('throttle:5,1')->name('recover.store');
Route::get('/obnovit/{customer}', [RecoverController::class, 'show'])->middleware('signed')->name('recover.claim');
Route::post('/obnovit/{customer}', [RecoverController::class, 'claim'])->middleware('signed')->name('recover.claim.store');

/*
|--------------------------------------------------------------------------
| Administrace kadeřnice
|--------------------------------------------------------------------------
*/
Route::prefix('admin')->name('admin.')->group(function () {
    // Manifest si prohlížeč stahuje bez přihlášení.
    Route::get('/manifest.webmanifest', [PwaController::class, 'adminManifest'])->name('manifest');

    Route::middleware('guest')->group(function () {
        Route::get('/login', [LoginController::class, 'create'])->name('login');
        Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:20,1')->name('login.store');
    });

    Route::middleware('auth')->group(function () {
        Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
        Route::post('/push', [PushSubscriptionController::class, 'store'])->name('push.store');
        Route::delete('/push', [PushSubscriptionController::class, 'destroy'])->name('push.destroy');

        require __DIR__.'/admin.php';
    });
});
