<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Models\Booking;
use Illuminate\Console\Command;

/**
 * Potvrzené termíny, které už skončily, se označí jako „proběhlo“.
 * (Pokud zákaznice nepřišla, kadeřnice to označí ručně – i dodatečně.)
 */
class CompletePastBookings extends Command
{
    protected $signature = 'bookings:complete-past';

    protected $description = 'Označí skončené potvrzené rezervace jako proběhlé';

    public function handle(): int
    {
        $count = Booking::query()
            ->where('status', BookingStatus::Confirmed)
            ->where('ends_at', '<', now()->subHour())
            ->update(['status' => BookingStatus::Completed]);

        $this->info("Označeno jako proběhlé: {$count}");

        return self::SUCCESS;
    }
}
