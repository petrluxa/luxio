<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

/**
 * PWA: manifesty a offline stránka.
 *
 * Manifest zákaznice se generuje pro její osobní odkaz – `start_url` obsahuje token,
 * protože iOS nesdílí cookies mezi Safari a aplikací na ploše (SPEC 7).
 *
 * Všechny adresy jsou úplné (včetně domény a případné podsložky), aby manifest
 * fungoval i tam, kde aplikace neběží v kořeni domény.
 */
class PwaController extends Controller
{
    public function customerManifest(): JsonResponse
    {
        $customer = request()->attributes->get('customer');

        return $this->manifest($customer->salon->name, route('customer.home'));
    }

    public function adminManifest(): JsonResponse
    {
        return $this->manifest(config('app.name').' – salon', route('admin.today'));
    }

    public function offline(): View
    {
        return view('offline');
    }

    private function manifest(string $name, string $startUrl): JsonResponse
    {
        return response()->json([
            'name' => $name,
            'short_name' => mb_strlen($name) > 12 ? config('app.name') : $name,
            'id' => $startUrl,
            'start_url' => $startUrl,
            'scope' => url('/').'/',
            'display' => 'standalone',
            'orientation' => 'portrait',
            'lang' => 'cs',
            'background_color' => '#EFE7DB',
            'theme_color' => '#EFE7DB',
            'icons' => [
                ['src' => asset('icons/icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => asset('icons/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => asset('icons/maskable-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ], headers: [
            'Content-Type' => 'application/manifest+json',
            'Cache-Control' => 'private, no-store',
        ], options: JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
