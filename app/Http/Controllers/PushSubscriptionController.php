<?php

namespace App\Http\Controllers;

use App\Notifications\TestNotification;
use App\Support\SingleDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use NotificationChannels\WebPush\WebPushChannel;
use Throwable;

/**
 * Push odběry zařízení – stejné pro zákaznici (za osobním odkazem) i kadeřnici
 * (po přihlášení). Jedna osoba může mít víc zařízení.
 */
class PushSubscriptionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'url', 'starts_with:https://', 'max:500'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'contentEncoding' => ['nullable', 'string', 'in:aesgcm,aes128gcm'],
        ]);

        $owner = $this->owner($request);

        $owner->updatePushSubscription(
            $data['endpoint'],
            $data['keys']['p256dh'],
            $data['keys']['auth'],
            $data['contentEncoding'] ?? 'aes128gcm',
        );

        // Zapnutím upozornění na zařízení dává zákaznice najevo, že je chce.
        if ($request->attributes->has('customer') && ! $owner->notifications_enabled) {
            $owner->update(['notifications_enabled' => true]);
        }

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:500']]);

        $this->owner($request)->deletePushSubscription($data['endpoint']);

        return response()->json(['ok' => true]);
    }

    /**
     * Zkušební upozornění na zařízení, ze kterého přišla žádost. Odpověď říká, jak dopadlo
     * předání službě upozornění (Apple, Google…) – podle toho obrazovka poradí, co dál.
     */
    public function test(Request $request, WebPushChannel $channel): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:500']]);

        $subscription = $this->owner($request)->pushSubscriptions()->where('endpoint', $data['endpoint'])->first();

        // Server o zařízení neví (odběr mezitím zanikl) – obrazovka ho založí znovu a zkusí to ještě jednou.
        if ($subscription === null) {
            return response()->json(['ok' => false, 'expired' => true, 'status' => null, 'reason' => 'Server toto zařízení nezná.']);
        }

        $target = $request->attributes->has('customer')
            ? ['path' => '']
            : ['url' => route('admin.account', [], false)];

        try {
            $report = $channel->send(new SingleDevice($subscription), new TestNotification($target))[0] ?? null;
        } catch (Throwable $e) {
            report($e);

            return response()->json(['ok' => false, 'expired' => false, 'status' => null, 'reason' => class_basename($e).': '.mb_substr($e->getMessage(), 0, 300)]);
        }

        if ($report === null) {
            return response()->json(['ok' => false, 'expired' => false, 'status' => null, 'reason' => 'Upozornění se nepodařilo předat k odeslání.']);
        }

        return response()->json([
            'ok' => $report->isSuccess(),
            'expired' => $report->isSubscriptionExpired(),
            'status' => $report->getResponse()?->getStatusCode(),
            'reason' => $report->isSuccess() ? null : mb_substr($report->getReason(), 0, 300),
        ]);
    }

    /** Zákaznice z osobního odkazu, jinak přihlášená kadeřnice. */
    private function owner(Request $request)
    {
        return $request->attributes->get('customer') ?? $request->user() ?? abort(403);
    }
}
