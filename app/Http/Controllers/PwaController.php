<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * PWA: manifesty a offline stránka.
 *
 * Manifest zákaznice se generuje pro její osobní odkaz – `start_url` obsahuje token,
 * protože iOS nesdílí cookies mezi Safari a aplikací na ploše (SPEC 7).
 */
class PwaController extends Controller
{
    public function customerManifest(Request $request): JsonResponse
    {
        $customer = $request->attributes->get('customer');
        $home = route('customer.home', absolute: false);

        return $this->manifest($customer->salon->name, $home, $home);
    }

    public function adminManifest(): JsonResponse
    {
        return $this->manifest(config('app.name').' – salon', '/admin', '/admin');
    }

    public function offline(): View
    {
        return view('offline');
    }

    private function manifest(string $name, string $startUrl, string $id): JsonResponse
    {
        return response()->json([
            'name' => $name,
            'short_name' => mb_strlen($name) > 12 ? config('app.name') : $name,
            'id' => $id,
            'start_url' => $startUrl,
            'scope' => '/',
            'display' => 'standalone',
            'orientation' => 'portrait',
            'lang' => 'cs',
            'background_color' => '#F6EFE8',
            'theme_color' => '#F6EFE8',
            'icons' => [
                ['src' => '/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/icons/maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ], headers: [
            'Content-Type' => 'application/manifest+json',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
