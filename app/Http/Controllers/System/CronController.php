<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

/**
 * Pravidelné úlohy pro hosting bez cronu: adresu `/cron/{klíč}` volá časovač hostingu
 * (typicky každých 5 minut). Udělá totéž co plánovač – pošle připomínky, uzavře proběhlé
 * termíny a vyřídí frontu upozornění. Úlohy jsou napsané tak, že opakované volání nevadí.
 *
 * Plánovač (`schedule:run`) se tu nepoužívá schválně: časovač hostingu netrefí přesnou
 * minutu, takže by úlohy naplánované „každých 5 minut“ nemusely proběhnout nikdy.
 */
class CronController extends Controller
{
    public function __invoke(string $key): Response
    {
        $expected = (string) config('luxio.cron_key');

        abort_unless($expected !== '' && hash_equals($expected, $key), 404);

        // Dvě volání přes sebe (pomalý server) si nesmějí lézt do práce.
        $lock = Cache::lock('luxio-cron', 240);

        if (! $lock->get()) {
            return $this->text('BEZI');
        }

        try {
            Artisan::call('bookings:send-reminders');
            Artisan::call('bookings:complete-past');

            if (config('queue.default') !== 'sync') {
                Artisan::call('queue:work', ['--stop-when-empty' => true, '--max-time' => 20, '--tries' => 3]);
            }
        } finally {
            $lock->release();
        }

        return $this->text('OK');
    }

    private function text(string $body): Response
    {
        return response($body, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
