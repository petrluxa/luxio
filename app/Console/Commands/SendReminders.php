<?php

namespace App\Console\Commands;

use App\Enums\BookingNotice;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Salon;
use App\Notifications\BookingNotification;
use Illuminate\Console\Command;

/**
 * Připomínky termínů (SPEC 6). Spouští se každých 5 minut a vybere potvrzené
 * rezervace, které začínají za X hodin (výchozí 48 h, volitelně ještě druhá
 * připomínka např. 2 h předem) a připomínku ještě nedostaly.
 */
class SendReminders extends Command
{
    protected $signature = 'bookings:send-reminders';

    protected $description = 'Pošle připomínky termínů (push, případně e-mail)';

    /**
     * Šířka okna v minutách. Úloha běží po 5 minutách; okno je dvojnásobné, aby
     * jedno zpožděné spuštění připomínku nevynechalo. Dvakrát se nepošle –
     * hlídá to `reminder_sent_at`.
     */
    private const WINDOW_MINUTES = 10;

    public function handle(): int
    {
        $sent = 0;

        foreach (Salon::all() as $salon) {
            $rules = $salon->rules();

            $sent += $this->remind($salon, $rules->reminderHours, 'reminder_sent_at');

            if ($rules->secondReminderHours) {
                $sent += $this->remind($salon, $rules->secondReminderHours, 'second_reminder_sent_at');
            }
        }

        $this->info("Odeslaných připomínek: {$sent}");

        return self::SUCCESS;
    }

    private function remind(Salon $salon, int $hoursBefore, string $column): int
    {
        $until = now()->addHours($hoursBefore);

        $bookings = Booking::query()
            ->where('salon_id', $salon->id)
            ->where('status', BookingStatus::Confirmed)
            ->whereNull($column)
            ->where('starts_at', '>', $until->subMinutes(self::WINDOW_MINUTES))
            ->where('starts_at', '<=', $until)
            ->with('customer', 'staff', 'items', 'salon')
            ->get();

        foreach ($bookings as $booking) {
            // Nejdřív označit, pak poslat – při pádu uprostřed nehrozí dvojí připomínka.
            $booking->forceFill([$column => now()])->save();

            $booking->customer->notify(new BookingNotification($booking, BookingNotice::Reminder));
        }

        return $bookings->count();
    }
}
