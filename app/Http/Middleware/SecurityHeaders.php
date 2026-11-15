<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bezpečnostní hlavičky pro všechny stránky.
 *
 * Osobní token zákaznice je součástí adresy, proto:
 *  - `Referrer-Policy: no-referrer` – adresa neodejde na cizí web,
 *  - `X-Robots-Tag` – stránky se nemají dostat do vyhledávačů,
 *  - `Cache-Control: no-store` – stránku s termíny si neuloží sdílená cache ani prohlížeč.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->headers->set(
            'Content-Security-Policy',
            "frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'",
        );

        if (! $response->headers->hasCacheControlDirective('public')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
