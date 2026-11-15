<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    /** Zákaznice z osobního odkazu, jinak přihlášená kadeřnice. */
    private function owner(Request $request)
    {
        return $request->attributes->get('customer') ?? $request->user() ?? abort(403);
    }
}
