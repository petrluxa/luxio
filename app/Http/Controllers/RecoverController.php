<?php

namespace App\Http\Controllers;

use App\Models\Salon;
use App\Support\CustomerCookie;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Ztracený odkaz. Aplikace neposílá e-maily ani SMS, nový odkaz proto zákaznici
 * pošle kadeřnice (karta zákaznice → Poslat odkaz). Tady se jen dozví, na koho se obrátit.
 */
class RecoverController extends Controller
{
    public function __invoke(Request $request): View|RedirectResponse
    {
        // V tomto prohlížeči odkaz pořád je (uložili jsme ho při první návštěvě).
        if ($token = CustomerCookie::token($request)) {
            return redirect()->route('customer.home', ['token' => $token]);
        }

        $salon = $request->filled('salon')
            ? Salon::where('slug', (string) $request->query('salon'))->first()
            : null;

        return view('recover', ['salon' => $salon, 'appTitle' => $salon?->name]);
    }
}
