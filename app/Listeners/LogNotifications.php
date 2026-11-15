<?php

namespace App\Listeners;

use App\Models\NotificationLog;
use App\Notifications\BookingNotification;
use Illuminate\Events\Dispatcher;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use NotificationChannels\WebPush\WebPushChannel;

/**
 * Zápis odeslaných (a neodeslaných) upozornění do tabulky `notification_log`.
 */
class LogNotifications
{
    public function onSent(NotificationSent $event): void
    {
        $this->log($event->notifiable, $event->notification, $event->channel, null);
    }

    public function onFailed(NotificationFailed $event): void
    {
        $error = $event->data['message'] ?? $event->data['error'] ?? 'Odeslání se nepodařilo.';

        $this->log($event->notifiable, $event->notification, $event->channel, is_string($error) ? $error : json_encode($error));
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            NotificationSent::class => 'onSent',
            NotificationFailed::class => 'onFailed',
        ];
    }

    private function log(mixed $notifiable, mixed $notification, string $channel, ?string $error): void
    {
        if (! $notification instanceof BookingNotification || ! is_object($notifiable)) {
            return;
        }

        NotificationLog::create([
            'notifiable_type' => $notifiable->getMorphClass(),
            'notifiable_id' => $notifiable->getKey(),
            'type' => $notification->notice->value,
            'channel' => $channel === WebPushChannel::class ? 'push' : 'mail',
            'booking_id' => $notification->booking->id,
            'sent_at' => $error === null ? now() : null,
            'error' => $error ? mb_substr($error, 0, 1000) : null,
        ]);
    }
}
