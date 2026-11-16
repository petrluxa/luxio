<?php

namespace App\Listeners;

use App\Enums\BookingNotice;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Events\BookingApproved;
use App\Events\BookingCancelled;
use App\Events\BookingCreated;
use App\Events\BookingMoved;
use App\Events\BookingRejected;
use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\User;
use App\Notifications\BookingNotification;
use Carbon\CarbonImmutable;
use Illuminate\Events\Dispatcher;
use Throwable;

/**
 * Kdo se o čem dozví (SPEC 6):
 *
 *  nová potvrzená rezervace      → zákaznice „Termín potvrzen“, kadeřnice „Nová rezervace“
 *  rezervace čeká na schválení   → kadeřnice „Ke schválení“
 *  schváleno / zamítnuto         → zákaznice
 *  zrušení zákaznicí             → kadeřnice
 *  zrušení / přesun kadeřnicí    → zákaznice
 *  přesun zákaznicí              → kadeřnice
 *
 * O tom, co kadeřnice udělala sama v administraci, už upozornění nedostává.
 *
 * Co jde kadeřnici, dostane i správce salonu, který sám zákaznice nemá (recepce) – a to
 * se jménem kadeřnice. Jinak by se o rezervaci u kadeřnice bez vlastního přihlášení nikdo nedozvěděl.
 */
class SendBookingNotifications
{
    public function onCreated(BookingCreated $event): void
    {
        $booking = $event->booking;

        if ($booking->status === BookingStatus::Pending) {
            $this->toStaff($booking, BookingNotice::Pending);

            return;
        }

        $this->toCustomer($booking, BookingNotice::Confirmed);

        if ($booking->source === BookingSource::Online) {
            $this->toStaff($booking, BookingNotice::NewBooking);
        }
    }

    public function onApproved(BookingApproved $event): void
    {
        $this->toCustomer($event->booking, BookingNotice::Confirmed);
    }

    public function onRejected(BookingRejected $event): void
    {
        $this->toCustomer($event->booking, BookingNotice::Rejected);
    }

    public function onCancelled(BookingCancelled $event): void
    {
        $event->byCustomer
            ? $this->toStaff($event->booking, BookingNotice::CancelledByCustomer)
            : $this->toCustomer($event->booking, BookingNotice::CancelledByStaff);
    }

    public function onMoved(BookingMoved $event): void
    {
        if (! $event->byCustomer) {
            $this->toCustomer($event->booking, BookingNotice::MovedByStaff, $event->previousStart);

            return;
        }

        $notice = $event->booking->status === BookingStatus::Pending ? BookingNotice::Pending : BookingNotice::MovedByCustomer;

        $this->toStaff($event->booking, $notice, $event->previousStart);
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            BookingCreated::class => 'onCreated',
            BookingApproved::class => 'onApproved',
            BookingRejected::class => 'onRejected',
            BookingCancelled::class => 'onCancelled',
            BookingMoved::class => 'onMoved',
        ];
    }

    private function toCustomer(Booking $booking, BookingNotice $notice, ?CarbonImmutable $previousStart = null): void
    {
        $this->send($booking->customer, new BookingNotification($booking, $notice, $previousStart));
    }

    private function toStaff(Booking $booking, BookingNotice $notice, ?CarbonImmutable $previousStart = null): void
    {
        $this->send($booking->staff, new BookingNotification($booking, $notice, $previousStart));

        $reception = User::query()
            ->where('salon_id', $booking->salon_id)
            ->where('role', UserRole::Owner)
            ->where('is_bookable', false)
            ->whereKeyNot($booking->staff_id)
            ->get();

        foreach ($reception as $manager) {
            $this->send($manager, new BookingNotification($booking, $notice, $previousStart, withStaff: true));
        }
    }

    /**
     * Upozornění je doplněk: když se ho nepodaří odeslat (nebo zařadit do fronty),
     * rezervace – která už je uložená – kvůli tomu nesmí skončit chybou.
     */
    private function send(object $notifiable, BookingNotification $notification): void
    {
        try {
            $notifiable->notify($notification);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
