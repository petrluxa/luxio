<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Zkušební upozornění: po zapnutí si ho člověk pošle sám, aby viděl, že na jeho zařízení chodí.
 * Posílá se hned (ne přes frontu) a jen na zařízení, ze kterého o něj požádal.
 */
class TestNotification extends Notification
{
    /**
     * @param  array<string, string>  $target  kam vede ťuknutí (`url` v administraci, `path` u zákaznice)
     */
    public function __construct(public array $target) {}

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return (new WebPushMessage)
            ->title('Zkušební upozornění')
            ->body('Funguje to. Takhle vám na tomto zařízení budou chodit zprávy o rezervacích.')
            ->icon('icons/icon-192.png')
            ->badge('icons/badge-96.png')
            ->lang('cs')
            ->tag('zkouska')
            ->renotify()
            ->data($this->target)
            ->options(['TTL' => 120, 'urgency' => 'high']);
    }
}
