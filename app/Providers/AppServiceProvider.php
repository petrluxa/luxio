<?php

namespace App\Providers;

use App\Enums\BookingStatus;
use App\Listeners\LogNotifications;
use App\Listeners\SendBookingNotifications;
use App\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Data a časy se nemění „pod rukama“ – každá úprava vrací novou hodnotu.
        Date::use(CarbonImmutable::class);

        Model::shouldBeStrict(! $this->app->isProduction());

        // Ostrý provoz jen přes HTTPS (push upozornění i PWA ho vyžadují).
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        // Na ostrém serveru nejde databázi smazat příkazem (migrate:fresh, db:wipe) –
        // na sdíleném hostingu v ní mohou být i tabulky jiné aplikace.
        DB::prohibitDestructiveCommands($this->app->isProduction());

        // Za proxy nebo CDN: skutečnou adresu návštěvníka číst z hlaviček, jinak by
        // limity podle IP adresy počítaly všechny návštěvníky dohromady.
        if ($proxies = config('luxio.trusted_proxies')) {
            TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }

        // SPEC 3.2: 20 požadavků za minutu na IP adresu pro osobní odkazy /k/*.
        RateLimiter::for('customer-link', fn (Request $request) => Limit::perMinute(20)->by('k:'.$request->ip()));

        // Načítání volných termínů v průvodci (každá změna výběru = jeden dotaz).
        RateLimiter::for('slots', fn (Request $request) => Limit::perMinute(90)->by('slots:'.$request->ip()));

        // Upozornění k rezervacím (push) a jejich záznam.
        Event::subscribe(SendBookingNotifications::class);
        Event::subscribe(LogNotifications::class);

        // Počet rezervací ke schválení ve spodní nabídce administrace.
        View::composer('layouts.admin', function ($view) {
            $user = auth()->user();

            $view->with('pendingCount', $user
                ? Booking::where('salon_id', $user->salon_id)
                    ->where('status', BookingStatus::Pending)
                    ->when(! $user->isOwner(), fn ($q) => $q->where('staff_id', $user->id))
                    ->count()
                : 0);
        });
    }
}
