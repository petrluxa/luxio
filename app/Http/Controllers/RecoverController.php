<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerAccessToken;
use App\Services\Customers\AccessLinks;
use App\Support\CustomerCookie;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Ztracený odkaz: zákaznice zadá e-mail a dostane dočasný podepsaný odkaz (30 min).
 * Po jeho otevření a potvrzení vznikne nový token; staré tokeny zůstávají platné.
 */
class RecoverController extends Controller
{
    public function create(): View
    {
        return view('recover.create');
    }

    public function store(Request $request, AccessLinks $links): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email']]);

        Customer::where('email', Str::lower(trim($data['email'])))
            ->where('is_blocked', false)
            ->get()
            ->each(fn (Customer $customer) => $links->sendClaim($customer, 'recover'));

        // Stejná odpověď, ať už e-mail známe, nebo ne.
        return back()->with('status', 'Pokud tento e-mail známe, poslali jsme na něj nový odkaz. Platí 30 minut.');
    }

    /** Mezikrok: token nevzniká už při otevření odkazu (e-mailové programy odkazy samy načítají). */
    public function show(Request $request, Customer $customer): View
    {
        return view('recover.claim', [
            'customer' => $customer,
            'salon' => $customer->salon,
            'appTitle' => $customer->salon->name,
            'action' => $request->fullUrl(),
        ]);
    }

    public function claim(Customer $customer): RedirectResponse
    {
        abort_if($customer->is_blocked, 403);

        if (! $customer->email_verified_at) {
            $customer->forceFill(['email_verified_at' => now()])->save();
        }

        $token = CustomerAccessToken::issue($customer);

        return redirect()
            ->route('customer.home', ['token' => $token])
            ->withCookie(CustomerCookie::make($token));
    }
}
