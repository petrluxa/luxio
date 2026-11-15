<?php

namespace App\Events;

use App\Models\Booking;
use Illuminate\Foundation\Events\Dispatchable;

class BookingApproved
{
    use Dispatchable;

    public function __construct(public readonly Booking $booking) {}
}
