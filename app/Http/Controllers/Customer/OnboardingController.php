<?php

namespace App\Http\Controllers\Customer;

use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Onboarding po první rezervaci: přidat aplikaci na plochu → povolit notifikace.
 */
class OnboardingController extends CustomerController
{
    public function __invoke(Request $request): View
    {
        $customer = $this->customer($request);

        $booking = is_numeric($request->query('rezervace'))
            ? $customer->bookings()->with('items', 'staff')->find((int) $request->query('rezervace'))
            : null;

        return view('customer.onboarding', [
            'booking' => $booking,
            'vapidPublicKey' => config('webpush.vapid.public_key'),
        ]);
    }
}
