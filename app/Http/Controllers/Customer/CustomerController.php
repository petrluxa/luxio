<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\CustomerAccessToken;
use Illuminate\Http\Request;

/**
 * Základ pro obrazovky zákaznice za osobním odkazem `/k/{token}`.
 * Zákaznici a token připravuje middleware ResolveCustomerToken.
 */
abstract class CustomerController extends Controller
{
    protected function customer(Request $request): Customer
    {
        return $request->attributes->get('customer');
    }

    protected function accessToken(Request $request): CustomerAccessToken
    {
        return $request->attributes->get('accessToken');
    }

    /** Rezervace musí patřit přihlášené zákaznici – cizí se tváří jako neexistující. */
    protected function own(Request $request, Booking $booking): Booking
    {
        abort_unless($booking->customer_id === $this->customer($request)->id, 404);

        return $booking;
    }
}
