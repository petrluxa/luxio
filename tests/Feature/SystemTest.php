<?php

use App\Enums\BookingStatus;
use App\Enums\UserRole;
use App\Models\Salon;
use App\Models\User;
use App\Services\Booking\BookingManager;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/*
 * Hosting bez příkazové řádky: instalace a pravidelné úlohy přes tajnou adresu.
 */

describe('instalace přes prohlížeč', function () {
    $form = fn (array $overrides = []) => $overrides + [
        'name' => 'Kadeřnictví Eva', 'slug' => 'Eva', 'owner' => 'Eva', 'email' => 'Eva@Example.com', 'password' => 'dlouhe-heslo-123',
    ];

    test('bez klíče v nastavení ani se špatným klíčem adresa neexistuje', function () use ($form) {
        config(['luxio.setup_key' => null]);
        $this->get('/instalace/cokoliv')->assertNotFound();
        $this->post('/instalace/cokoliv', $form())->assertNotFound();

        config(['luxio.setup_key' => 'tajny-klic']);
        $this->get('/instalace/jiny-klic')->assertNotFound();
        $this->post('/instalace/jiny-klic', $form())->assertNotFound();

        expect(Salon::count())->toBe(0);
    });

    test('správný klíč připraví databázi a nabídne založení salonu', function () {
        config(['luxio.setup_key' => 'tajny-klic']);

        $this->get('/instalace/tajny-klic')->assertOk()
            ->assertSee('Databáze je připravená')
            ->assertSee('Založit salon')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    });

    test('formulář založí salon a majitelku s heslem, které si zvolila', function () use ($form) {
        config(['luxio.setup_key' => 'tajny-klic']);

        $this->post('/instalace/tajny-klic', $form())->assertOk()
            ->assertSee('Salon je založený')
            ->assertSee('/s/eva')
            ->assertDontSee('dlouhe-heslo-123');

        $eva = User::sole();

        expect(Salon::sole())->name->toBe('Kadeřnictví Eva')->slug->toBe('eva')
            ->and($eva)->email->toBe('eva@example.com')->role->toBe(UserRole::Owner)->is_bookable->toBeTrue()
            ->and(Hash::check('dlouhe-heslo-123', $eva->password))->toBeTrue();

        // Přihlášení do administrace funguje hned.
        $this->post('/admin/login', ['email' => 'eva@example.com', 'password' => 'dlouhe-heslo-123'])->assertRedirect('/admin');
    });

    test('krátké heslo nebo špatné údaje salon nezaloží a heslo se do stránky nevrací', function () use ($form) {
        config(['luxio.setup_key' => 'tajny-klic']);

        $this->post('/instalace/tajny-klic', $form(['slug' => 'má mezery', 'email' => 'neplatny', 'password' => 'kratke']))
            ->assertStatus(422)
            ->assertSee('Založit salon')
            ->assertSee('Adresa smí obsahovat jen malá písmena')
            ->assertDontSee('kratke');

        expect(Salon::count())->toBe(0)->and(User::count())->toBe(0);
    });

    test('jakmile salon existuje, další účet už přes instalaci nikdo nezaloží', function () use ($form) {
        config(['luxio.setup_key' => 'tajny-klic']);
        Salon::factory()->create(['name' => 'První salon']);

        $this->get('/instalace/tajny-klic')->assertOk()->assertSee('Salon už existuje')->assertDontSee('name="password"', false);
        $this->post('/instalace/tajny-klic', $form())->assertOk()->assertSee('Salon už existuje');

        expect(Salon::count())->toBe(1)->and(User::count())->toBe(0);
    });
});

describe('pravidelné úlohy přes adresu', function () {
    test('bez klíče v nastavení ani se špatným klíčem adresa neexistuje', function () {
        config(['luxio.cron_key' => null]);
        $this->get('/cron/cokoliv')->assertNotFound();

        config(['luxio.cron_key' => 'cron-klic']);
        $this->get('/cron/jiny-klic')->assertNotFound();
    });

    test('správný klíč pošle připomínky a uzavře proběhlé termíny', function () {
        config(['luxio.cron_key' => 'cron-klic']);
        Notification::fake();
        $this->travelTo(TEST_NOW);

        [$salon, $petra] = salonWithStaff();
        $jana = customer($salon);
        $jana->updatePushSubscription('https://push.example.com/jana', 'klic', 'tajemstvi', 'aes128gcm');

        // Středa 11. 11. v 10:00 → připomínka 48 h předem = pondělí 9. 11. v 10:00.
        $booking = app(BookingManager::class)->create($jana, [service($salon)], at('10:00'), $petra);

        $this->travelTo('2026-11-09 10:03:00');
        $this->get('/cron/cron-klic')->assertOk()->assertSeeText('OK')->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        expect($booking->fresh()->reminder_sent_at)->not->toBeNull();

        // Opakované volání připomínku nepošle podruhé.
        $this->get('/cron/cron-klic')->assertOk();
        Notification::assertSentToTimes($jana, App\Notifications\BookingNotification::class, 2); // potvrzení + jedna připomínka

        $this->travelTo('2026-11-11 12:00:00');
        $this->get('/cron/cron-klic')->assertOk();

        expect($booking->fresh()->status)->toBe(BookingStatus::Completed);
    });
});

describe('běh v podsložce webu', function () {
    test('stránka ví, v jaké podsložce běží, a ikony i manifest na ni míří', function () {
        [$salon] = salonWithStaff();
        $token = tokenFor(customer($salon));

        // Stejně se aplikace chová, když na serveru leží ve složce /luxio.
        $server = ['SCRIPT_NAME' => '/luxio/index.php', 'SCRIPT_FILENAME' => base_path('public/index.php')];
        $root = rtrim(config('app.url'), '/').'/luxio';   // např. http://localhost:8000/luxio

        // Úplné adresy: zkrácenou by si test po prvním dotazu doplnil o podsložku podruhé.
        $this->call('GET', "{$root}/k/{$token}", server: $server)->assertOk()
            ->assertSee('data-root="/luxio"', false)
            ->assertSee('data-base="/k/'.$token.'"', false)
            ->assertSee("{$root}/k/{$token}/manifest.webmanifest", false)
            ->assertSee("{$root}/icons/apple-touch-icon.png", false);

        $this->call('GET', "{$root}/k/{$token}/manifest.webmanifest", server: $server)->assertOk()
            ->assertJsonPath('start_url', "{$root}/k/{$token}")
            ->assertJsonPath('scope', "{$root}/")
            ->assertJsonPath('icons.0.src', "{$root}/icons/icon-192.png");
    });

    test('v kořeni domény je podsložka prázdná', function () {
        [$salon] = salonWithStaff();
        $token = tokenFor(customer($salon));

        $this->get(k($token))->assertOk()->assertSee('data-root=""', false);
    });
});

describe('návrat po odeslání formuláře', function () {
    // Prohlížeč nám kvůli „no-referrer“ neposílá, odkud přišel – aplikace se vrací na poslední
    // otevřenou obrazovku. Požadavky na pozadí ji nesmějí přepsat.

    test('manifest, nabídka termínů ani offline stránka nezmění, kam se zákaznice po uložení vrátí', function () {
        $this->travelTo(TEST_NOW);
        [$salon] = salonWithStaff();
        $cut = service($salon);
        $token = tokenFor(customer($salon));

        $this->get(k($token, '/profil'))->assertOk();

        // To, co si prohlížeč a stránka stahují samy:
        $this->get(k($token, '/manifest.webmanifest'))->assertOk();
        $this->get(k($token, "/rezervace/terminy?services[]={$cut->id}"))->assertOk();
        $this->get('/offline')->assertOk();

        $this->post(k($token, '/profil'), ['name' => 'Jana Nová', 'phone' => '777 111 222'])
            ->assertRedirect(k($token, '/profil'));
    });

    test('nepovedený přesun v administraci vrátí kadeřnici na formulář přesunu, ne na surová data', function () {
        $this->travelTo(TEST_NOW);
        [$salon, $petra] = salonWithStaff();
        $cut = service($salon);
        $manager = app(BookingManager::class);
        $booking = $manager->create(customer($salon), [$cut], at('10:00'), $petra);
        $manager->create(customer($salon), [$cut], at('13:00'), $petra);

        $this->actingAs($petra);
        $this->get("/admin/rezervace/{$booking->id}/presun")->assertOk();
        $this->get("/admin/rezervace/terminy?presun={$booking->id}&staff={$petra->id}&from=".TEST_DAY)->assertOk();
        $this->get('/admin/manifest.webmanifest')->assertOk();
        $this->get('/admin/zakaznice/hledat?q=ja')->assertOk();

        // 13:00 je obsazeno druhou rezervací.
        $this->post("/admin/rezervace/{$booking->id}/presun", ['staff' => $petra->id, 'date' => TEST_DAY, 'time' => '13:00'])
            ->assertRedirect("/admin/rezervace/{$booking->id}/presun")
            ->assertSessionHas('error');
    });
});
