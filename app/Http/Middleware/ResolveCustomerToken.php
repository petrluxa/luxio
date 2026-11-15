<?php

namespace App\Http\Middleware;

use App\Models\CustomerAccessToken;
use App\Support\CustomerCookie;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Osobní odkaz `/k/{token}`: podle tokenu najde zákaznici (v DB je jen SHA-256 hash).
 * Neplatný nebo zrušený token dostane obecnou chybu bez detailu a počítá se
 * do limitu pokusů na IP adresu.
 */
class ResolveCustomerToken
{
    private const MAX_INVALID_PER_MINUTE = 20;

    public function handle(Request $request, Closure $next): Response
    {
        $plain = (string) $request->route('token');
        $limiterKey = 'invalid-token:'.$request->ip();

        if (RateLimiter::tooManyAttempts($limiterKey, self::MAX_INVALID_PER_MINUTE)) {
            return response()->view('errors.link', status: 429);
        }

        $token = CustomerAccessToken::findValid($plain);
        $customer = $token?->customer;

        if (! $token || ! $customer) {
            RateLimiter::hit($limiterKey, 60);

            return response()->view('errors.link', status: 404)
                ->withCookie(CustomerCookie::forget());
        }

        // Čas posledního použití stačí zapsat jednou za čas, ne při každém požadavku.
        if (! $token->last_used_at || $token->last_used_at->lt(now()->subMinutes(10))) {
            $token->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        $customer->setRelation('salon', $customer->salon);

        $request->attributes->set('customer', $customer);
        $request->attributes->set('accessToken', $token);
        $request->route()->forgetParameter('token');

        // Odkazy uvnitř zákaznické části si token doplní samy.
        URL::defaults(['token' => $plain]);

        View::share([
            'customer' => $customer,
            'salon' => $customer->salon,
            'token' => $plain,
            'appTitle' => $customer->salon->name,
        ]);

        $response = $next($request);

        // Dlouhodobé zapamatování v prohlížeči (1 rok) – veřejný odkaz salonu pak pozná stálou zákaznici.
        if (method_exists($response, 'withCookie')) {
            $response->withCookie(CustomerCookie::make($plain));
        }

        return $response;
    }
}
