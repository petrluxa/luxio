<?php

namespace App\Http\Controllers\Customer;

use App\Enums\BookingStatus;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HomeController extends CustomerController
{
    public function __invoke(Request $request): View
    {
        $customer = $this->customer($request);

        return view('customer.home', [
            'next' => $customer->nextBooking()?->load('items', 'staff'),
            'pendingCount' => $customer->bookings()->upcoming()->where('status', BookingStatus::Pending)->count(),
        ]);
    }
}
