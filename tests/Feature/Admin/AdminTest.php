<?php

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\CustomerAccessToken;
use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use App\Services\Availability\SlotFinder;
use App\Services\Booking\BookingManager;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->travelTo(TEST_NOW);
    $this->salon = Salon::factory()->create();
    $this->petra = staffMember($this->salon, attributes: ['name' => 'Petra', 'login' => 'petra', 'role' => UserRole::Owner]);
    $this->cut = service($this->salon);
    $this->jana = customer($this->salon, ['name' => 'Jana Nováková', 'phone' => '+420603123456']);
    $this->manager = app(BookingManager::class);
});

function bookingAt($test, string $time, BookingStatus $status = BookingStatus::Confirmed, ?Customer $customer = null): Booking
{
    return $test->manager->create($customer ?? $test->jana, [$test->cut], at($time), $test->petra, $status);
}

describe('přihlášení', function () {
    test('nepřihlášenou pošle na přihlášení', function () {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/admin/zakaznice')->assertRedirect('/admin/login');
    });

    test('jméno a heslo přihlásí, „zapamatovat“ nastaví trvalou cookie', function () {
        // Velká písmena ani mezery kolem jména nevadí.
        $this->post('/admin/login', ['login' => ' Petra ', 'password' => 'password', 'remember' => '1'])
            ->assertRedirect('/admin');

        $this->assertAuthenticatedAs($this->petra);
        expect($this->petra->fresh()->remember_token)->not->toBeNull();
    });

    test('špatné heslo nepustí a po pěti pokusech se přihlášení na chvíli zamkne', function () {
        foreach (range(1, 5) as $i) {
            $this->post('/admin/login', ['login' => 'petra', 'password' => 'spatne'])->assertSessionHasErrors('login');
        }

        $this->post('/admin/login', ['login' => 'petra', 'password' => 'password'])->assertSessionHasErrors('login');
        $this->assertGuest();
    });

    test('kadeřnice bez přihlašovacích údajů se nepřihlásí ani s prázdným heslem', function () {
        $sabina = staffMember($this->salon, attributes: ['name' => 'Sabina', 'login' => null, 'password' => null]);

        expect($sabina->canLogIn())->toBeFalse();

        $this->post('/admin/login', ['login' => '', 'password' => ''])->assertSessionHasErrors('login');
        $this->post('/admin/login', ['login' => 'sabina', 'password' => 'cokoliv'])->assertSessionHasErrors('login');
        $this->assertGuest();
    });

    test('přihlašovací stránka chce jméno, ne e-mail', function () {
        $this->get('/admin/login?jmeno=Petra')->assertOk()
            ->assertSee('Přihlašovací jméno')
            ->assertSee('name="login"', false)
            ->assertSee('value="petra"', false)
            ->assertDontSee('type="email"', false);
    });

    test('odhlášení', function () {
        $this->actingAs($this->petra)->post('/admin/logout')->assertRedirect('/admin/login');
        $this->assertGuest();
    });
});

test('všechny obrazovky administrace se vykreslí', function (string $path) {
    $pending = bookingAt($this, '13:00', BookingStatus::Pending, customer($this->salon, ['is_verified' => false]));
    $booking = bookingAt($this, '10:00');

    $path = str_replace(['{booking}', '{customer}', '{service}', '{member}'], [$booking->id, $this->jana->id, $this->cut->id, $this->petra->id], $path);

    $this->actingAs($this->petra)->get($path)->assertOk();
})->with([
    '/admin/dnes',
    '/admin/kalendar',
    '/admin/kalendar?zobrazeni=tyden&datum='.TEST_DAY,
    '/admin/kalendar?datum='.TEST_DAY,
    '/admin/kalendar?datum=2026-11-17',
    '/admin/ke-schvaleni',
    '/admin/rezervace/nova',
    '/admin/rezervace/nova?datum='.TEST_DAY.'&cas=9:30',
    '/admin/rezervace/{booking}',
    '/admin/rezervace/{booking}/presun',
    '/admin/zakaznice',
    '/admin/zakaznice?q=jana',
    '/admin/zakaznice/nova',
    '/admin/zakaznice/{customer}',
    '/admin/sluzby',
    '/admin/sluzby/nova',
    '/admin/sluzby/{service}',
    '/admin/pracovni-doba',
    '/admin/vice',
    '/admin/ucet',
    '/admin/sluzby?kadernice={member}',
    '/admin/kalendar?mesic=2026-12&datum='.TEST_DAY,
    '/admin/nastaveni',
    '/admin/kadernice',
    '/admin/kadernice/nova',
    '/admin/kadernice/{member}',
    '/admin/kalendar?kadernice={member}&datum='.TEST_DAY,
    '/admin/rezervace/{booking}/vyuctovani',
    '/admin/pripravky',
    '/admin/pripravky?q=majirel&druh=barvy',
    '/admin/pripravky/novy',
]);

test('dnes ukazuje další zákaznici a rychlé akce', function () {
    $this->travelTo(TEST_DAY.' 07:30:00');
    bookingAt($this, '10:00');

    $this->actingAs($this->petra)->get('/admin/dnes')
        ->assertOk()
        ->assertSee('Další zákaznice')
        ->assertSee('Jana Nováková')
        ->assertSee('tel:+420603123456', false)
        ->assertSee('Nepřišla');
});

test('data cizího salonu se tváří jako neexistující', function () {
    [$otherSalon, $otherStaff] = salonWithStaff();
    $otherService = service($otherSalon);
    $otherCustomer = customer($otherSalon);
    $otherBooking = $this->manager->create($otherCustomer, [$otherService], at('10:00'), $otherStaff);

    $this->actingAs($this->petra);

    $this->get("/admin/rezervace/{$otherBooking->id}")->assertNotFound();
    $this->post("/admin/rezervace/{$otherBooking->id}/zrusit")->assertNotFound();
    $this->get("/admin/zakaznice/{$otherCustomer->id}")->assertNotFound();
    $this->post("/admin/zakaznice/{$otherCustomer->id}/odkaz")->assertNotFound();
    $this->get("/admin/sluzby/{$otherService->id}")->assertNotFound();
    $this->get("/admin/kadernice/{$otherStaff->id}")->assertNotFound();

    expect($otherBooking->fresh()->status)->toBe(BookingStatus::Confirmed);
});

test('nastavení a správa kadeřnic jsou jen pro majitelku', function () {
    $lucie = staffMember($this->salon, attributes: ['role' => UserRole::Staff]);

    $this->actingAs($lucie);

    $this->get('/admin/nastaveni')->assertForbidden();
    $this->post('/admin/nastaveni', [])->assertForbidden();
    $this->get('/admin/kadernice')->assertForbidden();
    $this->get('/admin/kadernice/nova')->assertForbidden();
    $this->post('/admin/pripravky/katalog')->assertForbidden();
    $this->get('/admin/vice')->assertOk()->assertSee('Můj ceník')->assertDontSee('Pravidla rezervací')->assertDontSee('Údaje salonu');
    $this->get('/admin/ucet')->assertOk()->assertSee('Změna hesla');
});

describe('ke schválení', function () {
    test('schválení potvrdí termín a zákaznice je příště ověřená', function () {
        $eva = customer($this->salon, ['is_verified' => false]);
        $booking = bookingAt($this, '10:00', BookingStatus::Pending, $eva);

        $this->actingAs($this->petra)->get('/admin/ke-schvaleni')->assertSee($eva->name)->assertSee('nová zákaznice');
        $this->post("/admin/rezervace/{$booking->id}/schvalit")->assertSessionHas('status');

        expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed)
            ->and($eva->fresh()->is_verified)->toBeTrue();
    });

    test('zamítnutí s důvodem uvolní termín', function () {
        $booking = bookingAt($this, '10:00', BookingStatus::Pending);

        $this->actingAs($this->petra)->post("/admin/rezervace/{$booking->id}/zamitnout", ['reason' => 'Mám plno'])->assertSessionHas('status');

        expect($booking->fresh()->status)->toBe(BookingStatus::CancelledByStaff)
            ->and($booking->fresh()->cancel_reason)->toBe('Mám plno')
            ->and($booking->segments()->count())->toBe(0);
    });
});

describe('rezervace', function () {
    test('ruční rezervace pro novou zákaznici (volá do salonu)', function () {
        $this->actingAs($this->petra)->post('/admin/rezervace', [
            'services' => [$this->cut->id], 'staff' => $this->petra->id, 'date' => TEST_DAY, 'time' => '10:00',
            'customer_name' => 'Eva Malá', 'customer_phone' => '777 888 999', 'note' => 'Volala v pondělí',
        ])->assertRedirect();

        $eva = Customer::where('name', 'Eva Malá')->sole();
        $booking = Booking::sole();

        expect($eva->phone)->toBe('+420777888999')
            ->and($eva->is_verified)->toBeTrue()
            ->and($booking->customer_id)->toBe($eva->id)
            ->and($booking->status)->toBe(BookingStatus::Confirmed)
            ->and($booking->source)->toBe(BookingSource::Manual)
            ->and($booking->staff_note)->toBe('Volala v pondělí');
    });

    test('ruční rezervace pro stávající zákaznici obejde lhůty', function () {
        // Dnes v 9:00 (za hodinu) – zákaznice online by nemohla, kadeřnice ano.
        $this->actingAs($this->petra)->post('/admin/rezervace', [
            'services' => [$this->cut->id], 'staff' => $this->petra->id, 'date' => '2026-11-09', 'time' => '9:00',
            'customer_id' => $this->jana->id,
        ])->assertRedirect();

        expect(Booking::sole()->customer_id)->toBe($this->jana->id)->and(Customer::count())->toBe(1);
    });

    test('bez zákaznice rezervace nevznikne', function () {
        $this->actingAs($this->petra)->post('/admin/rezervace', [
            'services' => [$this->cut->id], 'date' => TEST_DAY, 'time' => '10:00',
        ])->assertSessionHasErrors('customer_name');

        expect(Booking::count())->toBe(0);
    });

    test('nová zákaznice musí mít telefon; vyplněné údaje při chybě zůstanou', function () {
        $form = ['services' => [$this->cut->id], 'staff' => $this->petra->id, 'date' => TEST_DAY, 'time' => '10:00', 'customer_name' => 'Eva Malá'];

        foreach ([[], ['customer_phone' => ''], ['customer_phone' => '12345'], ['customer_phone' => 'nemá']] as $phone) {
            $this->actingAs($this->petra)->from('/admin/rezervace/nova')->post('/admin/rezervace', $form + $phone)
                ->assertRedirect('/admin/rezervace/nova')
                ->assertSessionHasErrors(['customer_phone' => 'Zadejte telefon nové zákaznice – 9 číslic.'])
                ->assertSessionHasInput('customer_name', 'Eva Malá');
        }

        expect(Booking::count())->toBe(0)->and(Customer::where('name', 'Eva Malá')->count())->toBe(0);

        // Stávající zákaznice (i bez telefonu) se vybírá ze seznamu – tam se telefon nevyžaduje.
        $this->jana->update(['phone' => null]);
        $this->actingAs($this->petra)->post('/admin/rezervace', ['customer_id' => $this->jana->id] + $form)->assertSessionDoesntHaveErrors();

        expect(Booking::sole()->customer_id)->toBe($this->jana->id);

        // Formulář pole označuje jako povinné.
        $this->get('/admin/rezervace/nova')->assertOk()->assertSee('Telefon nové zákaznice (povinné)')->assertDontSee('(nepovinné)');
    });

    test('nabídka termínů pro přesun počítá s délkou rezervace a ignoruje ji samotnou', function () {
        $booking = bookingAt($this, '10:00');

        $response = $this->actingAs($this->petra)
            ->getJson("/admin/rezervace/terminy?presun={$booking->id}&staff={$this->petra->id}&from=".TEST_DAY)
            ->assertOk();

        expect($response->json('days.0.times'))->toContain('10:00', '10:15');
    });

    test('přesun termínu', function () {
        $booking = bookingAt($this, '10:00');

        $this->actingAs($this->petra)
            ->post("/admin/rezervace/{$booking->id}/presun", ['staff' => $this->petra->id, 'date' => '2026-11-12', 'time' => '14:30'])
            ->assertRedirect("/admin/rezervace/{$booking->id}");

        expect($booking->fresh()->starts_at->format('Y-m-d H:i'))->toBe('2026-11-12 14:30');
    });

    test('přesun na obsazený čas vrátí chybu', function () {
        $booking = bookingAt($this, '10:00');
        bookingAt($this, '13:00', customer: customer($this->salon));

        $this->actingAs($this->petra)
            ->post("/admin/rezervace/{$booking->id}/presun", ['date' => TEST_DAY, 'time' => '13:15'])
            ->assertSessionHas('error');

        expect($booking->fresh()->starts_at->format('H:i'))->toBe('10:00');
    });

    test('zrušení s důvodem, poznámka a „nepřišla“', function () {
        $booking = bookingAt($this, '10:00');
        $second = bookingAt($this, '13:00', customer: customer($this->salon));

        $this->actingAs($this->petra);

        $this->post("/admin/rezervace/{$booking->id}/poznamka", ['staff_note' => 'Chce kratší ofinu'])->assertSessionHas('status');
        $this->post("/admin/rezervace/{$booking->id}/zrusit", ['reason' => 'Nemoc'])->assertSessionHas('status');
        $this->post("/admin/rezervace/{$second->id}/neprisla")->assertSessionHas('status');

        expect($booking->fresh())->staff_note->toBe('Chce kratší ofinu')->status->toBe(BookingStatus::CancelledByStaff)->cancel_reason->toBe('Nemoc')
            ->and($second->fresh()->status)->toBe(BookingStatus::NoShow);

        // Zrušenou už znovu zrušit nejde.
        $this->post("/admin/rezervace/{$booking->id}/zrusit")->assertSessionHas('error');
    });

    test('po zrušení nebo přesunu se kadeřnice dozví, jestli má dát zákaznici vědět sama', function () {
        Notification::fake(); // push se v testu doopravdy neposílá

        $first = bookingAt($this, '10:00');
        $second = bookingAt($this, '13:00');

        $this->actingAs($this->petra);

        // Jana upozornění zapnutá nemá – a e-mail ani SMS jí aplikace nepošle.
        $this->get("/admin/rezervace/{$first->id}")->assertSee('o změně termínu jí dejte vědět sama');
        $this->post("/admin/rezervace/{$first->id}/zrusit")
            ->assertSessionHas('status', 'Rezervace je zrušená. Zákaznice nemá zapnutá upozornění – dejte jí prosím vědět sama.');

        // S upozorněním na telefonu to aplikace vyřídí sama.
        $this->jana->updatePushSubscription('https://push.example.com/jana', 'klic', 'tajemstvi', 'aes128gcm');

        $this->get("/admin/rezervace/{$second->id}")->assertSee('Upozornění jí chodí na telefon.');
        $this->post("/admin/rezervace/{$second->id}/presun", ['date' => TEST_DAY, 'time' => '14:30'])
            ->assertSessionHas('status', 'Termín je přesunutý. Zákaznici jde upozornění na telefon.');
        $this->get("/admin/zakaznice/{$this->jana->id}")->assertSee('Upozornění jí chodí na telefon.');
    });
});

describe('zákaznice', function () {
    test('hledání podle jména a telefonu', function () {
        customer($this->salon, ['name' => 'Eva Malá', 'phone' => '+420777888999']);

        $this->actingAs($this->petra);

        $this->get('/admin/zakaznice?q=nov')->assertSee('Jana Nováková')->assertDontSee('Eva Malá');
        $this->get('/admin/zakaznice?q=777 888')->assertSee('Eva Malá')->assertDontSee('Jana Nováková');
        $this->getJson('/admin/zakaznice/hledat?q=ja')->assertOk()->assertJsonPath('0.name', 'Jana Nováková')->assertJsonCount(1);
    });

    test('karta: receptura barvy, poznámky a kontakty', function () {
        $this->actingAs($this->petra)->post("/admin/zakaznice/{$this->jana->id}", [
            'name' => 'Jana Nováková', 'phone' => '603 123 456',
            'color_recipe' => 'Koleston 7/1 + 6 %', 'note' => 'Alergie na amoniak',
        ])->assertSessionHas('status');

        expect($this->jana->fresh())
            ->color_recipe->toBe('Koleston 7/1 + 6 %')
            ->note->toBe('Alergie na amoniak')
            ->phone->toBe('+420603123456');
    });

    test('„Poslat odkaz“ vydá funkční osobní odkaz a ukáže ho jen jednou', function () {
        $this->actingAs($this->petra);

        $response = $this->from("/admin/zakaznice/{$this->jana->id}")->post("/admin/zakaznice/{$this->jana->id}/odkaz");
        $link = session('link');

        expect($link)->toContain('/k/')->and(CustomerAccessToken::count())->toBe(1);

        $this->get("/admin/zakaznice/{$this->jana->id}")->assertSee('Nový odkaz je připravený');
        $this->get("/admin/zakaznice/{$this->jana->id}")->assertDontSee('Nový odkaz je připravený');

        auth()->logout();
        $this->get($link)->assertOk()->assertSee('Nová rezervace');
    });

    test('výměna odkazu zruší ty dosavadní', function () {
        $old = tokenFor($this->jana);

        $this->actingAs($this->petra)->post("/admin/zakaznice/{$this->jana->id}/odkaz", ['replace' => '1']);

        expect(CustomerAccessToken::findValid($old))->toBeNull()
            ->and(CustomerAccessToken::whereNull('revoked_at')->count())->toBe(1);
    });

    test('odkaz jen pro rezervace u mě', function () {
        $this->actingAs($this->petra)->post("/admin/zakaznice/{$this->jana->id}/odkaz", ['only_me' => '1']);

        expect(CustomerAccessToken::sole()->scope_staff_id)->toBe($this->petra->id);
    });

    test('blokace zruší odkazy a zabrání online rezervaci', function () {
        $token = tokenFor($this->jana);

        $this->actingAs($this->petra)->post("/admin/zakaznice/{$this->jana->id}/blokace")->assertSessionHas('status');

        expect($this->jana->fresh()->is_blocked)->toBeTrue()
            ->and(CustomerAccessToken::findValid($token))->toBeNull();

        $this->post("/admin/zakaznice/{$this->jana->id}/odkaz")->assertSessionHas('error');
        $this->post("/admin/zakaznice/{$this->jana->id}/blokace");

        expect($this->jana->fresh()->is_blocked)->toBeFalse();
    });

    test('přidání zákaznice ručně', function () {
        $this->actingAs($this->petra)->post('/admin/zakaznice', ['name' => 'Eva Malá', 'phone' => '777888999'])->assertRedirect();

        expect(Customer::where('name', 'Eva Malá')->sole())->is_verified->toBeTrue()->phone->toBe('+420777888999');

        $this->post('/admin/zakaznice', ['name' => 'X', 'phone' => '12'])->assertSessionHasErrors(['name', 'phone']);
    });
});

describe('ceník', function () {
    $row = fn (array $overrides = []) => $overrides + ['offers' => '1', 'price' => 550, 'work_1' => 45, 'pause' => 0, 'work_2' => 0];

    test('správce přidá službu a vybere, které kadeřnice ji dělají', function () {
        $lucie = staffMember($this->salon);
        $eva = staffMember($this->salon);

        $this->actingAs($this->petra)->post('/admin/sluzby', [
            'name' => 'Barvení a střih', 'price_from' => 1400, 'price_to' => 2200,
            'work_1' => 30, 'pause' => 40, 'work_2' => 60, 'buffer_after' => 10, 'is_active' => '1',
            'staff' => [$this->petra->id, $lucie->id],
        ])->assertRedirect('/admin/sluzby');

        $service = Service::where('name', 'Barvení a střih')->sole();

        expect($service->totalMinutes())->toBe(130)
            ->and($service->sort)->toBeGreaterThan($this->cut->sort)
            ->and($service->staff->modelKeys())->toEqualCanonicalizing([$this->petra->id, $lucie->id])
            ->and($eva->services()->whereKey($service->id)->exists())->toBeFalse();
    });

    test('kadeřnice si přidá vlastní službu – dostane ji jen ona a smí ji upravit', function () {
        $lucie = staffMember($this->salon, attributes: ['role' => UserRole::Staff]);

        // Pokus přidělit službu i kolegyni se u kadeřnice ignoruje.
        $this->actingAs($lucie)->post('/admin/sluzby', [
            'name' => 'Modelace nehtů', 'price_from' => 900, 'work_1' => 105, 'is_active' => '1', 'staff' => [$this->petra->id],
        ])->assertRedirect('/admin/sluzby');

        $nails = Service::where('name', 'Modelace nehtů')->sole();

        expect($nails->staff->modelKeys())->toBe([$lucie->id]);

        $this->get("/admin/sluzby/{$nails->id}")->assertOk();
        $this->post("/admin/sluzby/{$nails->id}", ['name' => 'Modelace nehtů – nová', 'price_from' => 950, 'work_1' => 110, 'is_active' => '1'])
            ->assertRedirect('/admin/sluzby');

        expect($nails->fresh())->name->toBe('Modelace nehtů – nová')->price_from->toBe(950);
    });

    test('společnou službu kadeřnice nepřepíše, jen si u ní nastaví svoji cenu', function () use ($row) {
        $lucie = staffMember($this->salon, attributes: ['role' => UserRole::Staff]);
        $lucie->services()->attach($this->cut->id);

        $this->actingAs($lucie);

        $this->get("/admin/sluzby/{$this->cut->id}")->assertRedirect('/admin/sluzby');
        $this->post("/admin/sluzby/{$this->cut->id}", ['name' => 'Přepsáno', 'price_from' => 1, 'work_1' => 5])->assertForbidden();
        $this->post("/admin/sluzby/{$this->cut->id}/poradi", ['direction' => 'up'])->assertForbidden();

        $this->post('/admin/sluzby/cenik', ['rows' => [$this->cut->id => $row(['price' => 700, 'work_1' => 60])]])->assertSessionHas('status');

        $pivot = $lucie->services()->whereKey($this->cut->id)->first()->pivot;

        expect($this->cut->fresh())->name->toBe('Dámský střih')->price_from->toBe(550)
            ->and($pivot->price_from)->toBe(700)
            ->and($pivot->work_1)->toBe(60)
            // Co je stejné jako výchozí hodnota, se neukládá.
            ->and($pivot->pause)->toBeNull()->and($pivot->work_2)->toBeNull()
            // Petřin ceník se nezměnil.
            ->and($this->petra->services()->whereKey($this->cut->id)->first()->pivot->price_from)->toBeNull();
    });

    test('ceník se ukládá celý najednou: co není zaškrtnuté, kadeřnice nedělá', function () use ($row) {
        $blow = service($this->salon, ['name' => 'Foukaná', 'price_from' => 350, 'work_1' => 30]);
        $color = coloring($this->salon);

        $this->actingAs($this->petra)->post('/admin/sluzby/cenik', ['rows' => [
            $this->cut->id => $row(),
            $blow->id => ['price' => 400, 'work_1' => 30, 'pause' => 0, 'work_2' => 0],   // nezaškrtnuto
            $color->id => $row(['price' => 1400, 'work_1' => 30, 'pause' => 45, 'work_2' => 60]),
        ]])->assertSessionHas('status');

        $mine = $this->petra->services()->get()->keyBy('id');

        expect($mine->keys()->all())->toEqualCanonicalizing([$this->cut->id, $color->id])
            ->and($mine[$this->cut->id]->pivot->price_from)->toBeNull()
            ->and($mine[$color->id]->pivot->pause)->toBe(45)
            ->and($mine[$color->id]->pivot->price_from)->toBeNull();

        $this->get('/admin/sluzby')->assertOk()->assertSee('Foukaná')->assertSee('Uložit ceník');
    });

    test('správce nastaví ceník kterékoliv kadeřnici, kadeřnice jen sobě', function () use ($row) {
        $lucie = staffMember($this->salon, attributes: ['name' => 'Lucie', 'role' => UserRole::Staff]);

        $this->actingAs($this->petra);
        $this->get("/admin/sluzby?kadernice={$lucie->id}")->assertOk()->assertSee('Ceník – Lucie');
        $this->post("/admin/sluzby/cenik?kadernice={$lucie->id}", ['rows' => [$this->cut->id => $row(['price' => 600])]]);

        expect($lucie->services()->first()->pivot->price_from)->toBe(600);

        // Lucie s cizím číslem v adrese uloží ceník sobě, ne Petře.
        $this->actingAs($lucie)->post("/admin/sluzby/cenik?kadernice={$this->petra->id}", ['rows' => []]);

        expect($lucie->services()->count())->toBe(0)->and($this->petra->services()->count())->toBe(1);
    });

    test('zákaznice vidí u služby cenu a délku vybrané kadeřnice', function () use ($row) {
        $lucie = staffMember($this->salon, attributes: ['name' => 'Lucie']);
        $lucie->services()->attach($this->cut->id, ['price_from' => 700, 'work_1' => 60]);

        $service = app(App\Services\Booking\BookingWizard::class)->options($this->salon)['services'][0];
        $nbsp = "\u{00A0}";

        expect($service['metaByStaff'][$this->petra->id])->toBe("45{$nbsp}min · 550{$nbsp}Kč")
            ->and($service['metaByStaff'][$lucie->id])->toBe("1{$nbsp}h · 700{$nbsp}Kč")
            // „Kdokoliv“: rozpětí délky a cena od nejlevnější.
            ->and($service['meta'])->toBe("45{$nbsp}min až 1{$nbsp}h · od 550{$nbsp}Kč");
    });

    test('cena „do“ musí být vyšší než „od“ a práce aspoň 5 minut', function () use ($row) {
        $this->actingAs($this->petra)->post('/admin/sluzby', ['name' => 'X', 'price_from' => 500, 'price_to' => 400, 'work_1' => 0])
            ->assertSessionHasErrors(['price_to', 'work_1']);

        $this->post('/admin/sluzby/cenik', ['rows' => [$this->cut->id => $row(['price' => -1, 'work_1' => 2])]])
            ->assertSessionHasErrors(["rows.{$this->cut->id}.price", "rows.{$this->cut->id}.work_1"]);
    });

    test('pořadí v ceníku', function () {
        $second = service($this->salon, ['name' => 'Foukaná', 'sort' => 5]);

        $this->actingAs($this->petra)->post("/admin/sluzby/{$second->id}/poradi", ['direction' => 'up']);

        expect($this->salon->services()->pluck('name')->all())->toBe(['Foukaná', 'Dámský střih']);
    });
});

describe('pracovní doba a volno', function () {
    test('uložení týdenního rozvrhu s polední pauzou', function () {
        $this->actingAs($this->petra)->post('/admin/pracovni-doba', ['hours' => [
            1 => [['from' => '08:00', 'to' => '12:00'], ['from' => '13:00', 'to' => '16:00']],
            2 => [['from' => '', 'to' => ''], ['from' => '', 'to' => '']],
            6 => [['from' => '09:00', 'to' => '12:00']],
        ]])->assertSessionHas('status');

        $hours = $this->petra->workingHours()->get();

        expect($hours)->toHaveCount(3)
            ->and($hours->where('weekday', 1)->pluck('starts_at')->map(fn ($t) => substr($t, 0, 5))->all())->toBe(['08:00', '13:00'])
            ->and($hours->where('weekday', 6))->toHaveCount(1);
    });

    test('den označený „Zavřeno“ se uloží bez pracovní doby, i když v polích zůstaly časy', function () {
        $this->actingAs($this->petra)->post('/admin/pracovni-doba', [
            'closed' => [3 => '1', 6 => '1', 7 => '1'],
            'hours' => [
                1 => [['from' => '09:00', 'to' => '17:00']],
                3 => [['from' => '09:00', 'to' => '12:00'], ['from' => '13:00', 'to' => '17:00']],   // zavřeno má přednost
                6 => [['from' => '', 'to' => '']],
            ],
        ])->assertSessionHas('status');

        expect($this->petra->workingHours()->pluck('weekday')->unique()->values()->all())->toBe([1]);

        // Stránka pak ukazuje zavřené dny zaškrtnuté a otevřený s časy.
        $this->get('/admin/pracovni-doba')->assertOk()
            ->assertSee('Zavřeno')
            ->assertSee('name="closed[3]"', false)
            ->assertSee('Přidat polední pauzu');

        // Středeční termíny se zákaznicím přestanou nabízet.
        expect(app(SlotFinder::class)->forDay($this->salon, [$this->cut], at('00:00'), $this->petra, true))->toBeEmpty();
    });

    test('konec před začátkem neprojde a rozvrh zůstane', function () {
        $this->actingAs($this->petra)->post('/admin/pracovni-doba', ['hours' => [1 => [['from' => '12:00', 'to' => '08:00']]]])
            ->assertSessionHasErrors('hours.1');

        expect($this->petra->workingHours()->count())->toBe(10);
    });

    test('volno na celé dny i na část dne, smazání', function () {
        $this->actingAs($this->petra);

        $this->post('/admin/volno', ['from_date' => '2026-12-21', 'to_date' => '2026-12-23', 'reason' => 'Dovolená'])->assertSessionHas('status');
        $this->post('/admin/volno', ['from_date' => TEST_DAY, 'to_date' => TEST_DAY, 'from_time' => '10:00', 'to_time' => '11:00'])->assertSessionHas('status');

        [$holiday, $dentist] = $this->petra->timeOffs()->get()->sortByDesc('starts_at')->values()->all();

        expect($holiday->starts_at->format('Y-m-d H:i'))->toBe('2026-12-21 00:00')
            ->and($holiday->ends_at->format('Y-m-d H:i'))->toBe('2026-12-24 00:00')
            ->and($dentist->ends_at->format('H:i'))->toBe('11:00');

        $this->post("/admin/volno/{$dentist->id}/smazat")->assertSessionHas('status');

        expect($this->petra->timeOffs()->count())->toBe(1);
    });

    test('výjimka otevře i ve státní svátek', function () {
        $this->actingAs($this->petra)->post('/admin/vyjimky', ['date' => '2026-11-17', 'from' => '09:00', 'to' => '12:00'])->assertSessionHas('status');

        $slots = app(SlotFinder::class)->forDay($this->salon, [$this->cut], at('00:00', '2026-11-17'), $this->petra);

        expect($slots)->not->toBeEmpty();
    });

    test('cizí volno smazat nejde', function () {
        [, $stranger] = salonWithStaff();
        $timeOff = $stranger->timeOffs()->create(['starts_at' => at('10:00'), 'ends_at' => at('11:00')]);

        $this->actingAs($this->petra)->post("/admin/volno/{$timeOff->id}/smazat")->assertNotFound();
    });
});

describe('nastavení', function () {
    function settingsPayload(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Salon Petra', 'slug' => 'salon-petra', 'phone' => '603 111 222', 'address' => 'Pražská 1',
            'slot_step' => 30, 'min_notice_hours' => 4, 'max_days_ahead' => 30, 'cancel_hours' => 12,
            'reminder_hours' => 24, 'second_reminder_hours' => 2,
        ];
    }

    test('pravidla salonu a údaje se uloží', function () {
        $this->actingAs($this->petra)->post('/admin/nastaveni', settingsPayload())->assertSessionHas('status');

        $salon = $this->salon->fresh();
        $rules = $salon->rules();

        expect($salon)->name->toBe('Salon Petra')->slug->toBe('salon-petra')->phone->toBe('+420603111222')
            ->and($rules->slotStep)->toBe(30)
            ->and($rules->minNoticeHours)->toBe(4)
            ->and($rules->maxDaysAhead)->toBe(30)
            ->and($rules->cancelHours)->toBe(12)
            ->and($rules->reminderHours)->toBe(24)
            ->and($rules->secondReminderHours)->toBe(2)
            ->and($rules->approveNewCustomers)->toBeFalse();   // nezaškrtnuto

        $this->post('/admin/nastaveni', settingsPayload(['approve_new_customers' => '1']));

        expect($this->salon->fresh()->rules()->approveNewCustomers)->toBeTrue();
    });

    test('adresa veřejného odkazu musí být jedinečná a bez diakritiky', function () {
        Salon::factory()->create(['slug' => 'obsazeno']);

        $this->actingAs($this->petra);

        $this->post('/admin/nastaveni', settingsPayload(['slug' => 'obsazeno']))->assertSessionHasErrors('slug');
        $this->post('/admin/nastaveni', settingsPayload(['slug' => 'Kadeřnictví Petra']))->assertSessionHasErrors('slug');
    });

    test('přidání kadeřnice s vlastním přihlášením: dostane služby salonu a přihlásí se', function () {
        $this->actingAs($this->petra)->post('/admin/kadernice', [
            'name' => 'Lucie', 'login' => 'Lucie', 'password' => 'tajneheslo1', 'role' => 'staff', 'color' => '#8E7A73', 'is_bookable' => '1',
        ])->assertRedirect();

        $lucie = User::where('login', 'lucie')->sole();

        expect($lucie->salon_id)->toBe($this->salon->id)
            ->and($lucie->role)->toBe(UserRole::Staff)
            ->and($lucie->services->modelKeys())->toBe([$this->cut->id]);

        auth()->logout();
        $this->post('/admin/login', ['login' => 'lucie', 'password' => 'tajneheslo1'])->assertRedirect('/admin');
    });

    test('kadeřnice jde přidat i bez přihlášení – je jen v rozvrhu', function () {
        $this->actingAs($this->petra)->post('/admin/kadernice', [
            'name' => 'Sabina', 'role' => 'staff', 'color' => '#8E7A73', 'is_bookable' => '1',
        ])->assertRedirect();

        $sabina = User::where('name', 'Sabina')->sole();

        expect($sabina->login)->toBeNull()->and($sabina->password)->toBeNull()->and($sabina->canLogIn())->toBeFalse();

        $this->get('/admin/kadernice')->assertOk()->assertSee('Sabina')->assertSee('bez vlastního přihlášení');
        $this->get('/admin/kalendar?kadernice=vse&datum='.TEST_DAY)->assertOk()->assertSee('Sabina');
    });

    test('přihlašovací jméno chce heslo, musí být jedinečné a bez háčků', function () {
        $this->actingAs($this->petra);
        $form = fn (array $overrides) => $overrides + ['name' => 'Šárka', 'role' => 'staff', 'color' => '#8E7A73', 'is_bookable' => '1'];

        $this->post('/admin/kadernice', $form(['login' => 'sarka']))->assertSessionHasErrors('password');
        $this->post('/admin/kadernice', $form(['login' => 'šárka', 'password' => 'tajneheslo1']))->assertSessionHasErrors('login');
        $this->post('/admin/kadernice', $form(['login' => 'petra', 'password' => 'tajneheslo1']))->assertSessionHasErrors('login');
        $this->post('/admin/kadernice', $form(['login' => 'sarka', 'password' => 'kratke']))->assertSessionHasErrors('password');

        expect(User::where('name', 'Šárka')->exists())->toBeFalse();
    });

    test('smazáním přihlašovacího jména kadeřnice o přístup přijde', function () {
        $lucie = staffMember($this->salon, attributes: ['name' => 'Lucie', 'login' => 'lucie']);

        $this->actingAs($this->petra)->post("/admin/kadernice/{$lucie->id}", [
            'name' => 'Lucie', 'login' => '', 'role' => 'staff', 'color' => '#8E7A73', 'is_bookable' => '1',
        ])->assertRedirect('/admin/kadernice');

        expect($lucie->fresh()->canLogIn())->toBeFalse();

        auth()->logout();
        $this->post('/admin/login', ['login' => 'lucie', 'password' => 'password'])->assertSessionHasErrors('login');
    });

    test('salon nesmí zůstat bez správce s přihlášením', function () {
        $this->actingAs($this->petra);

        $this->post("/admin/kadernice/{$this->petra->id}", [
            'name' => 'Petra', 'login' => 'petra', 'role' => 'staff', 'color' => '#B97D80', 'is_bookable' => '1',
        ])->assertSessionHas('error');

        $this->post("/admin/kadernice/{$this->petra->id}", [
            'name' => 'Petra', 'login' => '', 'role' => 'owner', 'color' => '#B97D80', 'is_bookable' => '1',
        ])->assertSessionHas('error');

        expect($this->petra->fresh())->role->toBe(UserRole::Owner)->login->toBe('petra');
    });

    test('změna vlastního hesla vyžaduje to současné', function () {
        $this->actingAs($this->petra);

        $this->post('/admin/heslo', ['current_password' => 'spatne', 'password' => 'noveheslo1', 'password_confirmation' => 'noveheslo1'])
            ->assertSessionHasErrors('current_password');
        $this->post('/admin/heslo', ['current_password' => 'password', 'password' => 'noveheslo1', 'password_confirmation' => 'noveheslo1'])
            ->assertSessionHas('status');

        expect(Hash::check('noveheslo1', $this->petra->fresh()->password))->toBeTrue();
    });
});
