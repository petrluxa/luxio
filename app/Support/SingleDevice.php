<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Collection;
use NotificationChannels\WebPush\PushSubscription;

/**
 * Příjemce upozornění, který má jediné zařízení – pro zkušební upozornění, které nemá
 * zazvonit na všech telefonech téhož člověka.
 */
class SingleDevice
{
    public function __construct(private PushSubscription $subscription) {}

    /**
     * @return Collection<int, PushSubscription>
     */
    public function routeNotificationFor(string $channel, mixed $notification = null): Collection
    {
        return new Collection([$this->subscription]);
    }
}
