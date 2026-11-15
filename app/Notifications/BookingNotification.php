<?php

namespace App\Notifications;

use App\Enums\BookingNotice;
use App\Models\Booking;
use App\Models\Customer;
use App\Support\Cz;
use App\Support\Ics;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Upozornění k rezervaci pro zákaznici i kadeřnici (SPEC 6).
 *
 * Kanál: Web Push. E-mail je jen záloha – použije se, když adresát nemá žádný
 * aktivní push odběr. Žádné SMS.
 */
class BookingNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Booking $booking,
        public BookingNotice $notice,
        public ?CarbonImmutable $previousStart = null,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        $push = $notifiable->pushSubscriptions()->exists();

        if ($notifiable instanceof Customer && ! $notifiable->notifications_enabled) {
            // Vypnutá upozornění: nic neposílat, jen důležité změny e-mailem.
            if (! $this->notice->isCritical()) {
                return [];
            }

            $push = false;
        }

        if ($push) {
            return [WebPushChannel::class];
        }

        return $notifiable->email ? ['mail'] : [];
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
            ->icon('/icons/icon-192.png')
            ->badge('/icons/badge-96.png')
            ->lang('cs')
            ->tag('rezervace-'.$this->booking->id)
            ->renotify()
            ->data($target)
            ->options(['TTL' => 60 * 60 * 12, 'urgency' => 'high']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $salon = $this->booking->salon;

        $mail = (new MailMessage)
            ->subject($this->title().' – '.$salon->name)
            ->greeting('Dobrý den,')
            ->line($this->body().'.');

        if ($this->notice->forCustomer()) {
            $mail->line('Podrobnosti najdete ve svých rezervacích – otevřete je přes svůj osobní odkaz nebo ikonu na ploše telefonu.');

            if ($salon->phone) {
                $mail->line('Telefon do salonu: '.Cz::phone($salon->phone));
            }

            if ($this->notice->attachesCalendar()) {
                $mail->attachData(Ics::forBooking($this->booking), 'rezervace.ics', ['mime' => 'text/calendar; charset=utf-8']);
            }
        } else {
            $mail->action('Otevřít rezervaci', route('admin.bookings.show', $this->booking));
        }

        return $mail->salutation($salon->name);
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

        return match ($this->notice) {
            BookingNotice::Confirmed => "{$when} – {$b->servicesLabel()}",
            BookingNotice::Rejected => "Termín {$when} bohužel nelze potvrdit{$reason}",
            BookingNotice::Reminder => $this->lead()." máte termín: {$when} u ".Cz::genitive($b->staff->name),
            BookingNotice::CancelledByStaff => "Váš termín {$when} byl zrušen{$reason}",
            BookingNotice::MovedByStaff => "Váš termín byl přesunut na {$when}".($this->previousStart ? ' (původně '.Cz::dateTime($this->previousStart).')' : ''),
            BookingNotice::NewBooking => "{$name}, {$short} – {$b->servicesLabel()}",
            BookingNotice::Pending => ($b->customer->is_verified ? 'Rezervace čeká na schválení: ' : 'Nová zákaznice čeká na schválení: ')."{$name}, {$short}",
            BookingNotice::CancelledByCustomer => "{$name} zrušil{$a} termín {$short}",
            BookingNotice::MovedByCustomer => "{$name} přesunul{$a} termín".($this->previousStart ? ' z '.Cz::date($this->previousStart).' '.Cz::time($this->previousStart) : '')." na {$short}",
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
