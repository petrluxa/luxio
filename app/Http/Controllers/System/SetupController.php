<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Models\Salon;
use App\Services\Setup\SalonInstaller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

/**
 * Instalace přes prohlížeč pro hosting bez příkazové řádky: `/instalace/{klíč}`.
 *
 *  - otevření adresy připraví databázi (spustí chybějící migrace),
 *  - dokud žádný salon neexistuje, nabídne formulář na jeho založení (účet správce a kadeřnice),
 *  - jakmile salon existuje, už jen ukazuje stav – nikdo přes ni nezaloží další účet,
 *  - prázdný salon (bez ceníku a rezervací, např. ze starší verze instalace) jde jednou dovybavit:
 *    kadeřnice do rozvrhu, vzorový ceník, pracovní doba a katalog přípravků.
 *
 * Adresa existuje jen s klíčem `LUXIO_SETUP_KEY` a běží mimo skupinu `web`
 * (bez session), protože před první migrací ještě nejsou tabulky pro session ani cache.
 */
class SetupController extends Controller
{
    public function __construct(private readonly SalonInstaller $installer) {}

    public function show(string $key): Response
    {
        $this->authorizeKey($key);

        return $this->page($this->prepare());
    }

    public function store(Request $request, string $key): Response
    {
        $this->authorizeKey($key);

        $state = $this->prepare();

        if ($state['error']) {
            return $this->page($state);
        }

        if ($state['salon']) {
            if ($state['incomplete']) {
                $state['completed'] = $this->installer->complete($state['salon'], Str::limit(trim((string) $request->input('staff')), 600, ''));
                $state['incomplete'] = false;
            }

            return $this->page($state);
        }

        $input = [
            'name' => trim((string) $request->input('name')),
            'owner' => trim((string) $request->input('owner')),
            'login' => Str::lower(trim((string) $request->input('login'))),
            'password' => (string) $request->input('password'),
            'staff' => trim((string) $request->input('staff')),
        ];

        $validator = Validator::make($input, $this->installer->rules(), $this->installer->messages());

        if ($validator->fails()) {
            // Heslo se do formuláře nevrací.
            return $this->page($state + ['problems' => $validator->errors()->all(), 'old' => ['password' => ''] + $input], 422);
        }

        $state['salon'] = $this->installer->install($validator->validated());
        $state['created'] = true;
        $state['login'] = $input['login'];

        return $this->page($state);
    }

    private function authorizeKey(string $key): void
    {
        $expected = (string) config('luxio.setup_key');

        abort_unless($expected !== '' && hash_equals($expected, $key), 404);
    }

    /**
     * Připraví databázi a zjistí stav instalace.
     *
     * @return array{error: ?string, migrations: string, salon: ?Salon, incomplete: bool, php: string, database: string}
     */
    private function prepare(): array
    {
        // Po nahrání nové verze může server ještě chvíli držet staré soubory v paměti.
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        $state = ['error' => null, 'migrations' => '', 'salon' => null, 'incomplete' => false, 'php' => PHP_VERSION, 'database' => ''];

        try {
            $pdo = DB::connection()->getPdo();
            $state['database'] = DB::connection()->getDriverName().' '.$pdo->getAttribute(\PDO::ATTR_SERVER_VERSION);

            Artisan::call('migrate', ['--force' => true]);
            $state['migrations'] = trim(Artisan::output());

            $state['salon'] = Salon::query()->orderBy('id')->first();
            $state['incomplete'] = $state['salon'] !== null && $this->installer->isIncomplete($state['salon']);
        } catch (Throwable $e) {
            report($e);

            // Chybová hláška databáze může obsahovat jméno serveru nebo uživatele, heslo nikdy.
            $state['error'] = Str::limit($e->getMessage(), 600);
        }

        return $state;
    }

    private function page(array $state, int $status = 200): Response
    {
        return response()
            ->view('system.setup', $state + ['problems' => [], 'old' => [], 'created' => false, 'completed' => null, 'login' => null, 'action' => url()->current()], $status)
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
