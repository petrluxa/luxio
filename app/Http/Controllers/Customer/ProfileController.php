<?php

namespace App\Http\Controllers\Customer;

use App\Support\Cz;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProfileController extends CustomerController
{
    public function edit(): View
    {
        return view('customer.profile');
    }

    public function update(Request $request): RedirectResponse
    {
        $request->merge(['phone' => Cz::normalizePhone($request->input('phone'))]);

        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'phone' => ['required', 'string'],
        ], ['phone.required' => 'Zadejte prosím platné telefonní číslo.']);

        $this->customer($request)->update($data + [
            'notifications_enabled' => $request->boolean('notifications_enabled'),
        ]);

        return back()->with('status', 'Údaje jsou uložené.');
    }
}
