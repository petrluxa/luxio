<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pomocné adresy, které si prohlížeč nebo stránka stahují na pozadí: nabídka termínů,
 * našeptávač zákaznic, manifest aplikace a offline stránka pro service worker.
 *
 * Nejsou to obrazovky, na které se má aplikace vracet. Po odeslání formuláře se totiž
 * vrací na „poslední otevřenou adresu“ (prohlížeč nám ji kvůli `Referrer-Policy:
 * no-referrer` neposílá, takže si ji pamatuje session) – a pomocný požadavek by ji přepsal:
 * místo formuláře s chybou by pak kadeřnice viděla surová data.
 *
 * Laravel si adresu nepamatuje u požadavků označených jako „na pozadí“, proto je tak
 * tady označíme bez ohledu na to, jestli to udělal prohlížeč.
 */
class BackgroundRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');

        return $next($request);
    }
}
