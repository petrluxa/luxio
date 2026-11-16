<?php

use App\Enums\BookingStatus;
use App\Enums\PaymentMethod;
use App\Enums\ProductCategory;
use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\Product;
use App\Models\Salon;
use App\Services\Booking\BookingManager;
use App\Services\Products\ProductCatalog;
use App\Support\Cz;

/*
 * Přípravky, vyúčtování návštěvy a doklad o zaplacení.
 */

beforeEach(function () {
    $this->travelTo(TEST_NOW);
    $this->salon = Salon::factory()->create(['name' => 'Salon Petra', 'company' => 'Petra Nová', 'ico' => '12345678', 'address' => 'Pražská 1, Liberec']);
    $this->petra = staffMember($this->salon, attributes: ['name' => 'Petra', 'login' => 'petra', 'role' => UserRole::Owner]);
    $this->cut = service($this->salon);
    $this->jana = customer($this->salon, ['name' => 'Jana Nováková', 'phone' => '+420603123456', 'is_verified' => false]);
    $this->manager = app(BookingManager::class);

    $product = fn (array $attributes) => Product::create($attributes + ['salon_id' => $this->salon->id, 'brand' => "L'Oréal Professionnel", 'category' => ProductCategory::Care]);

    $this->majirel = $product(['name' => 'Majirel', 'line' => 'Majirel', 'kind' => 'permanentní barva', 'category' => ProductCategory::Colors, 'size' => '50 ml']);
    $this->shampoo = $product(['name' => 'Absolut Repair Shampoo', 'line' => 'Serie Expert Absolut Repair', 'kind' => 'šampon', 'category' => ProductCategory::Shampoos]);
    $this->bain = $product(['brand' => 'Kérastase', 'name' => 'Bain Satin', 'line' => 'Nutritive', 'kind' => 'šampon', 'category' => ProductCategory::Shampoos]);

    // Proběhlá návštěva: středa 11. 11. v 10:00, teď je středa 11:00.
    $this->booking = $this->manager->create($this->jana, [$this->cut], at('10:00'), $this->petra);
    $this->travelTo(TEST_DAY.' 11:00:00');
    $this->actingAs($this->petra);
});

describe('přípravky', function () {
    test('přehled jde filtrovat podle značky, druhu a hledat', function () {
        $this->get('/admin/pripravky')->assertOk()->assertSee('Majirel')->assertSee('Bain Satin')->assertSee('Šampony');
        $this->get('/admin/pripravky?znacka='.urlencode('Kérastase'))->assertOk()->assertSee('Bain Satin')->assertDontSee('Absolut Repair Shampoo');
        $this->get('/admin/pripravky?druh=barvy')->assertOk()->assertSee('Majirel')->assertDontSee('Bain Satin');
        $this->get('/admin/pripravky?q=absolut')->assertOk()->assertSee('Absolut Repair Shampoo')->assertDontSee('Majirel</b>', false);
    });

    test('našeptávač hledá podle více slov napříč značkou a názvem a skryté nenabízí', function () {
        $this->getJson('/admin/pripravky/hledat?q=m')->assertOk()->assertExactJson([]);

        $this->getJson('/admin/pripravky/hledat?q='.urlencode('kerastase bain'))->assertOk()->assertExactJson([]);   // bez háčku se nenajde značka…
        $this->getJson('/admin/pripravky/hledat?q='.urlencode('satin nutri'))->assertOk()                             // …ale název a řada ano
            ->assertJsonCount(1)->assertJsonPath('0.name', 'Kérastase Bain Satin')->assertJsonPath('0.id', $this->bain->id);

        $this->getJson('/admin/pripravky/hledat?q=majirel')->assertJsonCount(1)->assertJsonPath('0.meta', 'permanentní barva · 50 ml');

        $this->majirel->update(['is_active' => false]);
        $this->getJson('/admin/pripravky/hledat?q=majirel')->assertExactJson([]);

        // Zástupné znaky v dotazu se berou doslova.
        $this->getJson('/admin/pripravky/hledat?q='.urlencode('%%'))->assertExactJson([]);
    });

    test('přidání vlastního přípravku a skrytí nepoužívaného', function () {
        $this->post('/admin/pripravky', ['brand' => 'Wella', 'name' => 'Koleston Perfect', 'category' => 'barvy', 'size' => '60 ml'])
            ->assertRedirect('/admin/pripravky');

        $wella = Product::where('brand', 'Wella')->sole();

        expect($wella)->salon_id->toBe($this->salon->id)->category->toBe(ProductCategory::Colors)->is_active->toBeTrue();

        $this->post("/admin/pripravky/{$wella->id}", ['brand' => 'Wella', 'name' => 'Koleston Perfect', 'category' => 'barvy', 'hidden' => '1'])
            ->assertRedirect('/admin/pripravky');

        expect($wella->fresh()->is_active)->toBeFalse();

        $this->get('/admin/pripravky')->assertDontSee('Koleston Perfect')->assertSee('Skryté (1)');
        $this->get('/admin/pripravky?skryte=1')->assertSee('Koleston Perfect');

        $this->post('/admin/pripravky', ['brand' => '', 'name' => '', 'category' => 'neznamy'])->assertSessionHasErrors(['brand', 'name', 'category']);
    });

    test('výchozí katalog se doplní jen jednou a nepřepíše vlastní úpravy', function () {
        $items = app(ProductCatalog::class)->items();

        expect(count($items))->toBeGreaterThan(150)
            ->and(collect($items)->pluck('brand')->unique()->sort()->values()->all())->toBe(['Kérastase', "L'Oréal Professionnel"])
            ->and(collect($items)->pluck('category')->unique()->sort()->values()->all())->toContain('barvy', 'sampony', 'pece', 'styling', 'ampule');

        $this->post('/admin/pripravky/katalog')->assertSessionHas('status');
        $count = $this->salon->products()->count();

        // Majirel a Bain Satin už salon měl – nepřibyly podruhé.
        expect($count)->toBeGreaterThan(150)
            ->and($this->salon->products()->where('name', 'Majirel')->count())->toBe(1);

        $this->majirel->update(['is_active' => false]);
        $this->post('/admin/pripravky/katalog')->assertSessionHas('status', 'Všechny přípravky z katalogu už v přehledu máte.');

        expect($this->salon->products()->count())->toBe($count)
            ->and($this->majirel->fresh()->is_active)->toBeFalse();
    });

    test('přípravek cizího salonu se tváří jako neexistující', function () {
        $cizi = Product::create(['salon_id' => Salon::factory()->create()->id, 'brand' => 'Cizí', 'name' => 'Tajný', 'category' => ProductCategory::Care]);

        $this->get("/admin/pripravky/{$cizi->id}")->assertNotFound();
        $this->post("/admin/pripravky/{$cizi->id}", ['brand' => 'x', 'name' => 'y', 'category' => 'pece'])->assertNotFound();
        $this->getJson('/admin/pripravky/hledat?q=tajny')->assertExactJson([]);
    });
});

describe('vyúčtování', function () {
    $pay = fn ($test, array $overrides = [], ?Booking $booking = null) => $test->post('/admin/rezervace/'.($booking ?? $test->booking)->id.'/vyuctovani', $overrides + [
        'products' => [
            ['id' => $test->majirel->id, 'name' => 'cokoliv', 'note' => '7.1, 30 g'],
            ['id' => '', 'name' => 'Wella Koleston', 'note' => ''],
        ],
        'amount' => '1250',
        'method' => 'card',
    ]);

    test('zápis přípravků a částky vystaví doklad s číslem a návštěvu uzavře', function () use ($pay) {
        $pay($this)->assertRedirect("/admin/rezervace/{$this->booking->id}")
            ->assertSessionHas('status', fn (string $status) => str_contains($status, '2026-0001'));

        $booking = $this->booking->fresh();

        expect($booking)->paid_amount->toBe(1250)->payment_method->toBe(PaymentMethod::Card)->receipt_number->toBe('2026-0001')
            ->status->toBe(BookingStatus::Completed)
            ->and($booking->paid_at)->not->toBeNull()
            ->and($booking->products->map(fn ($p) => [$p->product_id, $p->name, $p->note])->all())->toBe([
                [$this->majirel->id, "L'Oréal Professionnel Majirel", '7.1, 30 g'],   // název z katalogu, ne z formuláře
                [null, 'Wella Koleston', null],                                        // vlastní zápis mimo katalog
            ])
            // Kdo přišel a zaplatil, příště nečeká na schválení.
            ->and($this->jana->fresh()->is_verified)->toBeTrue();

        $this->get("/admin/rezervace/{$booking->id}")->assertOk()
            ->assertSee('Zaplaceno '.Cz::money(1250))
            ->assertSee('doklad č. 2026-0001')
            ->assertSee('https://wa.me/420603123456?text=', false)
            ->assertSee('7.1, 30 g');

        // Karta zákaznice: příště je vidět, co se použilo.
        $this->get("/admin/zakaznice/{$this->jana->id}")->assertOk()->assertSee('Použito:')->assertSee('7.1, 30 g');

        // Další návštěva nabídne „totéž co minule“.
        $next = $this->manager->create($this->jana, [$this->cut], at('13:00'), $this->petra);
        $this->get("/admin/rezervace/{$next->id}/vyuctovani")->assertOk()->assertSee('Minule')->assertSee('Použít totéž co minule');
    });

    test('čísla dokladů jdou za sebou a pro každý salon zvlášť', function () use ($pay) {
        $second = $this->manager->create(customer($this->salon), [$this->cut], at('09:00'), $this->petra, ignoreLimits: true);

        $pay($this);
        $pay($this, booking: $second);

        expect($this->booking->fresh()->receipt_number)->toBe('2026-0001')
            ->and($second->fresh()->receipt_number)->toBe('2026-0002');

        // Jiný salon začíná od jedničky.
        [$otherSalon, $otherStaff] = salonWithStaff();
        $other = $this->manager->create(customer($otherSalon), [service($otherSalon)], at('09:00'), $otherStaff, ignoreLimits: true);
        app(App\Services\Booking\Checkout::class)->save($other, [], 500);

        expect($other->fresh()->receipt_number)->toBe('2026-0001');
    });

    test('„jen přípravky“ uloží záznam bez platby a bez dokladu', function () use ($pay) {
        $pay($this, ['only_products' => '1'])->assertSessionHas('status', 'Použité přípravky jsou uložené.');

        $booking = $this->booking->fresh();

        expect($booking->isPaid())->toBeFalse()
            ->and($booking->receipt_number)->toBeNull()
            ->and($booking->products)->toHaveCount(2)
            ->and($booking->receiptUrl())->toBeNull();

        $this->get("/admin/rezervace/{$booking->id}")->assertSee('Zatím nezaplaceno')->assertDontSee('wa.me');
    });

    test('oprava částky číslo dokladu nemění a zrušení zaplacení ho nevrací do řady', function () use ($pay) {
        $pay($this);
        $pay($this, ['amount' => '1400', 'method' => 'cash', 'products' => []]);

        $booking = $this->booking->fresh();

        expect($booking)->paid_amount->toBe(1400)->payment_method->toBe(PaymentMethod::Cash)->receipt_number->toBe('2026-0001')
            ->and($booking->products)->toHaveCount(0);

        $this->post("/admin/rezervace/{$booking->id}/vyuctovani/zrusit")->assertSessionHas('status');

        expect($booking->fresh()->isPaid())->toBeFalse()->and($booking->fresh()->receipt_number)->toBe('2026-0001');

        // Další doklad dostane další číslo, ne znovu jedničku.
        $second = $this->manager->create(customer($this->salon), [$this->cut], at('09:00'), $this->petra, ignoreLimits: true);
        $pay($this, booking: $second);

        expect($second->fresh()->receipt_number)->toBe('2026-0002');
    });

    test('budoucí rezervace zaplacená předem zůstává v rozvrhu jako potvrzená', function () use ($pay) {
        $future = $this->manager->create($this->jana, [$this->cut], at('15:00'), $this->petra);

        $pay($this, booking: $future);

        expect($future->fresh())->status->toBe(BookingStatus::Confirmed)->paid_amount->toBe(1250);
    });

    test('zrušenou rezervaci ani „nepřišla“ vyúčtovat nejde', function () use ($pay) {
        $this->manager->markNoShow($this->booking);

        $this->get("/admin/rezervace/{$this->booking->id}/vyuctovani")->assertRedirect("/admin/rezervace/{$this->booking->id}");
        $pay($this)->assertSessionHas('error');

        expect($this->booking->fresh()->isPaid())->toBeFalse()->and($this->booking->products()->count())->toBe(0);
    });

    test('částka musí být celé nezáporné číslo a přípravek z cizího salonu se z katalogu nevezme', function () use ($pay) {
        $pay($this, ['amount' => '-5'])->assertSessionHasErrors('amount');
        $pay($this, ['amount' => 'dvanáct'])->assertSessionHasErrors('amount');
        $pay($this, ['method' => 'bitcoin'])->assertSessionHasErrors('method');

        $cizi = Product::create(['salon_id' => Salon::factory()->create()->id, 'brand' => 'Cizí', 'name' => 'Tajný', 'category' => ProductCategory::Care]);

        $pay($this, ['products' => [['id' => $cizi->id, 'name' => '', 'note' => 'x']]])->assertRedirect();

        expect($this->booking->products()->count())->toBe(0);
    });

    test('vyúčtování cizí rezervace se tváří jako neexistující', function () {
        [$otherSalon, $otherStaff] = salonWithStaff();
        $other = $this->manager->create(customer($otherSalon), [service($otherSalon)], at('09:00'), $otherStaff, ignoreLimits: true);

        $this->get("/admin/rezervace/{$other->id}/vyuctovani")->assertNotFound();
        $this->post("/admin/rezervace/{$other->id}/vyuctovani", ['amount' => '1'])->assertNotFound();
        $this->post("/admin/rezervace/{$other->id}/vyuctovani/zrusit")->assertNotFound();
    });
});

describe('doklad o zaplacení', function () {
    beforeEach(function () {
        app(App\Services\Booking\Checkout::class)->save($this->booking, [['id' => $this->majirel->id, 'note' => '7.1']], 1250, PaymentMethod::Cash);
        $this->booking->refresh();
        auth()->logout();   // zákaznice doklad otevírá bez přihlášení
    });

    test('otevře se přes odkaz a má náležitosti dokladu, použité přípravky na něm nejsou', function () {
        $this->get($this->booking->receiptUrl())->assertOk()
            ->assertSee('Doklad o zaplacení')
            ->assertSee('Salon Petra')
            ->assertSee('Petra Nová')
            ->assertSee('IČO 12345678')
            ->assertSee('2026-0001')
            ->assertSee(Cz::dateFull($this->booking->starts_at))
            ->assertSee('Jana Nováková')
            ->assertSee('Dámský střih')
            ->assertSee('Zaplaceno hotově')
            ->assertSee(Cz::money(1250))
            ->assertDontSee('Majirel')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeader('Cache-Control', 'no-store, private');
    });

    test('bez správného kódu doklad neexistuje', function () {
        $code = $this->booking->receiptCode();
        $wrong = substr($code, 0, -1).($code[-1] === 'a' ? 'b' : 'a');

        $this->get("/doklad/{$this->booking->id}/{$wrong}")->assertNotFound();
        $this->get("/doklad/{$this->booking->id}/".str_repeat('0', 32))->assertNotFound();
        $this->get("/doklad/{$this->booking->id}/kratky")->assertNotFound();
        $this->get('/doklad/999999/'.$code)->assertNotFound();

        // Kód jedné rezervace neotevře doklad jiné.
        $other = $this->manager->create(customer($this->salon), [$this->cut], at('09:00'), $this->petra, ignoreLimits: true);
        app(App\Services\Booking\Checkout::class)->save($other, [], 800);

        $this->get("/doklad/{$other->id}/{$code}")->assertNotFound();
    });

    test('po zrušení zaplacení odkaz přestane platit', function () {
        $url = $this->booking->receiptUrl();

        app(App\Services\Booking\Checkout::class)->revoke($this->booking);

        $this->get($url)->assertNotFound();
    });
});
