<?php

namespace App\Listeners;

use App\Models\NotificationLog;
use App\Notifications\BookingNotification;
use Illuminate\Events\Dispatcher;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Minishlink\WebPush\MessageSentReport;

/**
 * Zápis odeslaných (a neodeslaných) upozornění do tabulky `notification_log`.
 */
class LogNotifications
{
    public function onSent(NotificationSent $event): void
    {
        $this->log($event->notifiable, $event->notification, $event->channel, $this->refusal($event->response));
    }

    /**
     * „Odesláno“ znamená, že upozornění přijala služba aspoň jednoho zařízení (Apple, Google, Microsoft).
     * Když ho odmítly všechny, je to chyba – i když samotné odeslání proběhlo bez výjimky.
     */
    private function refusal(mixed $response): ?string
    {
        $reports = collect(is_array($response) ? $response : [])->filter(fn ($report) => $report instanceof MessageSentReport);

        if ($reports->isEmpty() || $reports->contains(fn (MessageSentReport $report) => $report->isSuccess())) {
            return null;
        }

        $first = $reports->first();

        return 'Služba upozornění zprávu odmítla'
            .($first->getResponse() ? ' (kód '.$first->getResponse()->getStatusCode().')' : '')
            .': '.preg_replace('#https?://\S+#', '…', $first->getReason());
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
            'channel' => 'push',
            'booking_id' => $notification->booking->id,
            'sent_at' => $error === null ? now() : null,
            'error' => $error ? mb_substr($error, 0, 1000) : null,
        ]);
    }
}
