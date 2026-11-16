<?php

namespace App\Notifications;

use App\Enums\BookingNotice;
use App\Models\Booking;
use App\Models\Customer;
use App\Support\Cz;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Upozornění k rezervaci pro zákaznici i kadeřnici (SPEC 6).
 *
 * Jediný kanál je Web Push. Žádné e-maily, žádné SMS: kdo nemá upozornění zapnutá,
 * vidí změny v aplikaci – a kadeřnice se v administraci dozví, že mu má dát vědět sama.
 */
class BookingNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  bool  $withStaff  upozornění pro správce salonu (recepci): v textu je i jméno kadeřnice
     */
    public function __construct(
        public Booking $booking,
        public BookingNotice $notice,
        public ?CarbonImmutable $previousStart = null,
        public bool $withStaff = false,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        if ($notifiable instanceof Customer && ! $notifiable->notifications_enabled) {
            return [];
        }

        return $notifiable->pushSubscriptions()->exists() ? [WebPushChannel::class] : [];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        // Zákaznice: cestu doplní service worker o její osobní odkaz (token server nezná).
        $target = $this->notice->forCustomer()
            ? ['path' => '/terminy/'.$this->booking->id]
            : ['url' => route('admin.bookings.show', $this->booking, false)];

        return (new WebPushMessage)
            ->title($this->title())
            ->body($this->body())
            // Cesty bez domény: service worker si je doplní podle toho, kde aplikace běží.
            ->icon('icons/icon-192.png')
            ->badge('icons/badge-96.png')
            ->lang('cs')
            ->tag('rezervace-'.$this->booking->id)
            ->renotify()
            ->data($target)
            ->options(['TTL' => 60 * 60 * 12, 'urgency' => 'high']);
    }

    public function title(): string
    {
        return match ($this->notice) {
            BookingNotice::Confirmed => 'Termín potvrzen',
            BookingNotice::Rejected => 'Termín bohužel nelze potvrdit',
            BookingNotice::Reminder => 'Připomínka termínu',
            BookingNotice::CancelledByStaff => 'Termín zrušen',
            BookingNotice::MovedByStaff => 'Termín přesunut',
            BookingNotice::NewBooking => 'Nová rezervace',
            BookingNotice::Pending => 'Ke schválení',
            BookingNotice::CancelledByCustomer => 'Zrušený termín',
            BookingNotice::MovedByCustomer => 'Přesunutý termín',
        };
    }

    public function body(): string
    {
        $b = $this->booking;
        $when = Cz::dateTime($b->starts_at);                         // st 19. 11. v 10:30
        $short = Cz::date($b->starts_at).' '.Cz::time($b->starts_at); // st 19. 11. 10:30
        $reason = $b->cancel_reason ? ' – '.$b->cancel_reason : '';
        $name = $b->customer->name;
        $a = Cz::isFemaleName($name) ? 'a' : '';                   // zrušila / zrušil
        $staff = $this->withStaff ? ' · '.$b->staff->name : '';     // správce vidí, ke komu rezervace patří

        return match ($this->notice) {
            BookingNotice::Confirmed => "{$when} – {$b->servicesLabel()}",
            BookingNotice::Rejected => "Termín {$when} bohužel nelze potvrdit{$reason}",
            BookingNotice::Reminder => $this->lead()." máte termín: {$when} u ".Cz::genitive($b->staff->name),
            BookingNotice::CancelledByStaff => "Váš termín {$when} byl zrušen{$reason}",
            BookingNotice::MovedByStaff => "Váš termín byl přesunut na {$when}".($this->previousStart ? ' (původně '.Cz::dateTime($this->previousStart).')' : ''),
            BookingNotice::NewBooking => "{$name}, {$short} – {$b->servicesLabel()}{$staff}",
            BookingNotice::Pending => ($b->customer->is_verified ? 'Rezervace čeká na schválení: ' : 'Nová zákaznice čeká na schválení: ')."{$name}, {$short}{$staff}",
            BookingNotice::CancelledByCustomer => "{$name} zrušil{$a} termín {$short}{$staff}",
            BookingNotice::MovedByCustomer => "{$name} přesunul{$a} termín".($this->previousStart ? ' z '.Cz::date($this->previousStart).' '.Cz::time($this->previousStart) : '')." na {$short}{$staff}",
        };
    }

    /** „Za 2 dny“ / „Zítra“ / „Za 2 hodiny“ podle toho, kolik času do termínu zbývá. */
    private function lead(): string
    {
        $hours = (int) round(now()->diffInMinutes($this->booking->starts_at) / 60);

        return match (true) {
            $hours >= 36 => 'Za '.(int) round($hours / 24).' dny',
            $hours >= 20 => 'Zítra',
            $hours >= 5 => "Za {$hours} hodin",
            $hours >= 2 => "Za {$hours} hodiny",
            default => 'Za chvíli',
        };
    }
}
