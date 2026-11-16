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
        'name' => 'Kadeřnictví Eva', 'owner' => 'Eva', 'login' => 'Eva', 'password' => 'dlouhe-heslo-123', 'staff' => "Sabina\nŠárka\n",
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

    test('formulář založí salon, správce s heslem, které si zvolil, a kadeřnice v rozvrhu', function () use ($form) {
        config(['luxio.setup_key' => 'tajny-klic']);

        $this->post('/instalace/tajny-klic', $form())->assertOk()
            ->assertSee('Salon je založený')
            ->assertSee('/s/kadernictvi-eva')
            ->assertSee('/admin/login?jmeno=eva')
            ->assertDontSee('dlouhe-heslo-123');

        $salon = Salon::sole();
        $eva = User::where('login', 'eva')->sole();

        expect($salon)->name->toBe('Kadeřnictví Eva')->slug->toBe('kadernictvi-eva')
            // Správce s kadeřnicemi je recepce: zákaznice se k němu neobjednávají.
            ->and($eva)->role->toBe(UserRole::Owner)->is_bookable->toBeFalse()
            ->and(Hash::check('dlouhe-heslo-123', $eva->password))->toBeTrue()
            ->and($salon->staff()->pluck('name')->all())->toBe(['Sabina', 'Šárka'])
            ->and($salon->staff()->get()->every(fn (User $u) => ! $u->canLogIn() && $u->workingHours()->count() === 5 && $u->services()->count() > 0))->toBeTrue()
            // Vzorový ceník a katalog přípravků, ať jde aplikace hned vyzkoušet.
            ->and($salon->services()->count())->toBeGreaterThan(3)
            ->and($salon->products()->count())->toBeGreaterThan(100);

        // Přihlášení do administrace funguje hned a vede na rozvrh.
        $this->post('/admin/login', ['login' => 'eva', 'password' => 'dlouhe-heslo-123'])->assertRedirect('/admin');
        $this->get('/admin')->assertRedirect('/admin/kalendar');
        $this->get('/admin/kalendar')->assertOk()->assertSee('Sabina')->assertSee('Šárka');

        // Stránka pro zákaznice má z čeho nabízet: služby i obě kadeřnice.
        $this->get('/s/kadernictvi-eva')->assertOk();

        $options = app(App\Services\Booking\BookingWizard::class)->options($salon);

        expect(array_column($options['services'], 'name'))->toContain('Dámský střih')
            ->and(array_column($options['staff'], 'name'))->toBe(['Sabina', 'Šárka']);
    });

    test('bez kadeřnic je salonem jedné kadeřnice sám správce', function () use ($form) {
        config(['luxio.setup_key' => 'tajny-klic']);

        $this->post('/instalace/tajny-klic', $form(['staff' => '']))->assertOk()->assertSee('Salon je založený');

        expect(User::sole())->login->toBe('eva')->is_bookable->toBeTrue()
            ->and(User::sole()->workingHours()->count())->toBe(5);
    });

    test('krátké heslo nebo špatné údaje salon nezaloží a heslo se do stránky nevrací', function () use ($form) {
        config(['luxio.setup_key' => 'tajny-klic']);

        $this->post('/instalace/tajny-klic', $form(['login' => 'má mezery', 'password' => 'kratke']))
            ->assertStatus(422)
            ->assertSee('Založit salon')
            ->assertSee('Přihlašovací jméno smí mít 3–60 znaků')
            ->assertDontSee('kratke');

        expect(Salon::count())->toBe(0)->and(User::count())->toBe(0);
    });

    test('jakmile salon existuje, další účet už přes instalaci nikdo nezaloží', function () use ($form) {
        config(['luxio.setup_key' => 'tajny-klic']);
        [$salon, $petra] = salonWithStaff();
        staffMember($salon);
        service($salon);

        $this->get('/instalace/tajny-klic')->assertOk()->assertSee('Salon už existuje')
            ->assertDontSee('name="password"', false)->assertDontSee('Dovybavit salon');
        $this->post('/instalace/tajny-klic', $form())->assertOk()->assertSee('Salon už existuje');

        expect(Salon::count())->toBe(1)->and(User::count())->toBe(2)->and($petra->fresh()->is_bookable)->toBeTrue();
    });

    test('prázdný salon ze starší instalace jde jednou dovybavit: kadeřnice, ceník, pracovní doba a přípravky', function () {
        config(['luxio.setup_key' => 'tajny-klic']);
        $salon = Salon::factory()->create(['name' => 'Starý salon']);
        $owner = User::factory()->owner()->create(['salon_id' => $salon->id, 'name' => 'Petr', 'login' => 'petr@example.com']);

        $this->get('/instalace/tajny-klic')->assertOk()->assertSee('Salon už existuje')->assertSee('Dovybavit salon')
            ->assertDontSee('name="password"', false)->assertDontSee('name="login"', false);

        // Přihlašovací údaje se touto cestou založit nedají, ani když je někdo pošle.
        $this->post('/instalace/tajny-klic', ['staff' => "Sabina\nŠárka", 'login' => 'vetrelec', 'password' => 'tajne-heslo-123', 'owner' => 'Vetřelec'])
            ->assertOk()->assertSee('Doplněno')->assertDontSee('Dovybavit salon');

        expect($salon->staff()->pluck('name')->all())->toBe(['Sabina', 'Šárka'])
            ->and(User::whereNotNull('login')->pluck('login')->all())->toBe(['petr@example.com'])
            // Zakladatel je teď správce bez vlastních zákaznic a jeho přihlášení se nezměnilo.
            ->and($owner->fresh())->is_bookable->toBeFalse()->role->toBe(UserRole::Owner)
            ->and($salon->services()->count())->toBeGreaterThan(3)
            ->and($salon->products()->count())->toBeGreaterThan(100)
            ->and($salon->staff()->get()->every(fn (User $u) => $u->workingHours()->count() === 5 && $u->services()->count() > 0))->toBeTrue();

        // Jakmile má salon ceník i kadeřnice, podruhé už nic nepřidá.
        $counts = [User::count(), $salon->services()->count(), $salon->products()->count()];
        $this->post('/instalace/tajny-klic', ['staff' => 'Další'])->assertOk()->assertDontSee('Doplněno');

        expect([User::count(), $salon->services()->count(), $salon->products()->count()])->toBe($counts);

        // Původní přihlášení e-mailem funguje dál jako přihlašovací jméno.
        $this->post('/admin/login', ['login' => 'Petr@Example.com', 'password' => 'password'])->assertRedirect('/admin');
    });

    test('salonu jen se zakladatelem jdou kadeřnice doplnit, i když už ceník má', function () {
        config(['luxio.setup_key' => 'tajny-klic']);
        [$salon, $martina] = salonWithStaff();
        $martina->update(['role' => UserRole::Owner, 'name' => 'Martina']);
        $cut = service($salon);

        $this->get('/instalace/tajny-klic')->assertOk()->assertSee('Dovybavit salon');
        $this->post('/instalace/tajny-klic', ['staff' => "Sabina\nŠárka"])->assertOk()->assertSee('Doplněno');

        expect($salon->staff()->pluck('name')->all())->toBe(['Sabina', 'Šárka'])
            ->and($martina->fresh()->is_bookable)->toBeFalse()
            // Ceník zůstal, jak byl, a nové kadeřnice ho dostaly.
            ->and($salon->services()->pluck('id')->all())->toBe([$cut->id])
            ->and($salon->staff()->get()->every(fn (User $u) => $u->services()->pluck('services.id')->all() === [$cut->id]))->toBeTrue();
    });

    test('jména v poškozeném kódování se nepřidají', function () {
        config(['luxio.setup_key' => 'tajny-klic']);
        $salon = Salon::factory()->create();
        User::factory()->owner()->create(['salon_id' => $salon->id]);

        $this->post('/instalace/tajny-klic', ['staff' => "Sabina\n\x8A\xE1rka"])->assertOk();

        expect(User::count())->toBe(1);
    });

    test('salon s rezervacemi se dovybavit nedá, i kdyby neměl ceník', function () {
        config(['luxio.setup_key' => 'tajny-klic']);
        $this->travelTo(TEST_NOW);
        [$salon, $petra] = salonWithStaff();
        $cut = service($salon);
        app(BookingManager::class)->create(customer($salon), [$cut], at('10:00'), $petra);
        $salon->services()->delete();

        $this->get('/instalace/tajny-klic')->assertOk()->assertDontSee('Dovybavit salon');
        $this->post('/instalace/tajny-klic', ['staff' => 'Sabina'])->assertOk()->assertDontSee('Doplněno');

        expect(User::count())->toBe(1)->and($salon->services()->count())->toBe(0);
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
