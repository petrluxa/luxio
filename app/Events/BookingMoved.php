<?php

namespace App\Events;

use App\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Termín se přesunul. U přesunu zákaznicí je `$booking` nová rezervace
 * (původní se v témže kroku zrušila), u přesunu kadeřnicí tatáž rezervace.
 */
class BookingMoved
{
    use Dispatchable;

    public function __construct(
        public readonly Booking $booking,
        public readonly CarbonImmutable $previousStart,
        public readonly bool $byCustomer,
    ) {}
}
