<?php

namespace App\Http\Controllers;

use App\Models\Salon;
use App\Support\CustomerCookie;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Úvodní adresa: stálou zákaznici pošle na její účet, jinak na veřejný odkaz salonu.
 */
class HomeController extends Controller
{
    public function __invoke(Request $request): View|RedirectResponse
    {
        if ($token = CustomerCookie::token($request)) {
            return redirect()->route('customer.home', ['token' => $token]);
        }

        if (Salon::count() === 1) {
            return redirect()->route('salon.show', Salon::first());
        }

        return view('welcome');
    }
}
