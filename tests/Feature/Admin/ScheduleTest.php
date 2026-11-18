<?php

use App\Enums\BookingNotice;
use App\Enums\UserRole;
use App\Events\BookingMoved;
use App\Models\Booking;
use App\Models\Salon;
use App\Notifications\BookingNotification;
use App\Services\Availability\SlotFinder;
use App\Services\Booking\BookingManager;
use App\Support\CalendarLanes;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

/*
 * Rozvrh salonu: správce (recepce) vidí den se sloupcem pro každou kadeřnici, kadeřnice
 * si zákaznici zapíše i na obsazený čas a rezervaci jde předat jiné kadeřnici.
 */

beforeEach(function () {
    $this->travelTo(TEST_NOW);
    $this->salon = Salon::factory()->create();
    $this->manager = app(BookingManager::class);

    // Správce bez vlastních zákaznic a dvě kadeřnice (po–pá 9–12 a 13–17).
    $this->spravce = staffMember($this->salon, hours: [], attributes: ['name' => 'Petr', 'login' => 'luxa', 'role' => UserRole::Owner, 'is_bookable' => false]);
    $this->sabina = staffMember($this->salon, attributes: ['name' => 'Sabina', 'login' => 'sabina']);
    $this->sarka = staffMember($this->salon, attributes: ['name' => 'Šárka', 'login' => 'sarka']);

    $this->cut = service($this->salon, [], $this->sabina, $this->sarka);
    $this->jana = customer($this->salon, ['name' => 'Jana Nováková', 'phone' => '+420603123456']);
    $this->eva = customer($this->salon, ['name' => 'Eva Malá', 'phone' => '+420777111222']);
});

describe('rozvrh dne', function () {
    test('správce po přihlášení vidí den se sloupcem pro každou kadeřnici, její pracovní dobu, jména a telefony', function () {
        $this->manager->create($this->jana, [$this->cut], at('10:00'), $this->sabina);

        $this->actingAs($this->spravce);

        $this->get('/admin')->assertRedirect('/admin/kalendar');

        $this->get('/admin/kalendar?datum='.TEST_DAY)->assertOk()
            ->assertSee('--cal-cols: 2', false)
            ->assertSeeInOrder(['Sabina', '9:00–17:00', 'Šárka', '9:00–17:00'])
            ->assertSee('10:00 (45m)')
            ->assertSee('Jana Nováková')
            ->assertSee('603 123 456')
            // Den se šipkami v horní liště, název salonu na ceduli a měsíční kalendář vedle rozvrhu.
            ->assertSeeInOrder(['top-day', 'Středa', App\Support\Cz::dateFull(at('00:00')), 'Dnes', 'Zítra', 'Vybrat den v kalendáři'], false)
            ->assertSee('class="sign-title">'.e($this->salon->name), false)
            // Na ceduli není vypínač – vzhled se přepíná tlačítkem v liště.
            ->assertDontSee('sign-switch', false)
            ->assertSee('Přepnout na tmavou verzi', false)
            ->assertSee('Listopad 2026')
            // Osobní přehled „Můj den“ správce bez vlastního sloupce v nabídce nemá.
            ->assertDontSee('<span>Můj den</span>', false)
            ->assertDontSee('Můj rozvrh');
    });

    test('kadeřnice po přihlášení vidí svých sedm dní, první sloupec je dnešek', function () {
        $this->travelTo(TEST_DAY.' 07:00:00');   // středa 11. 11.
        $today = $this->manager->create($this->jana, [$this->cut], at('10:00'), $this->sabina);
        $later = $this->manager->create($this->eva, [$this->cut], at('09:00', '2026-11-16'), $this->sabina);   // pondělí za 5 dní
        $this->manager->create(customer($this->salon, ['name' => 'Cizí Zákaznice']), [$this->cut], at('10:00'), $this->sarka);

        $this->post('/admin/login', ['login' => 'sabina', 'password' => 'password'])->assertRedirect('/admin');
        $this->get('/admin')->assertRedirect('/admin/kalendar');

        $this->get('/admin/kalendar')->assertOk()
            ->assertSee('--cal-cols: 7', false)
            // V liště její jméno a rozsah sedmi dnů, v záhlaví sloupců dny od dneška.
            ->assertSeeInOrder(['top-day', 'Sabina', 'st 11.', 'út 17.'], false)
            ->assertSeeInOrder(['Dnes 11. 11.', 'Čt 12. 11.', 'Pá 13. 11.', 'So 14. 11.', 'Ne 15. 11.', 'Po 16. 11.', 'Út 17. 11.'])
            ->assertSee('data-booking="'.$today->id.'"', false)
            ->assertSee('data-booking="'.$later->id.'"', false)
            ->assertSee('Jana Nováková')
            // Sobota a neděle bez pracovní doby, úterý 17. 11. je svátek.
            ->assertSeeInOrder(['So 14. 11.', 'volno', 'Út 17. 11.', 'svátek'])
            // Zákaznice kolegyně v jejím rozvrhu nejsou.
            ->assertDontSee('Cizí Zákaznice')
            ->assertSee('<span>Můj den</span>', false)
            ->assertDontSee('Zítra');

        // Šipka posouvá o týden, ikona kalendáře a měsíční kalendář skočí na libovolný den.
        $this->get('/admin/kalendar?datum=2026-12-16')->assertOk()
            ->assertSee('--cal-cols: 7', false)
            ->assertSeeInOrder(['St 16. 12.', 'Čt 17. 12.', 'Út 22. 12.'])
            ->assertSee('datum=2026-12-23', false)
            ->assertSee('datum=2026-12-09', false)
            ->assertSee('type="date"', false);
    });

    test('kadeřnice se přepne na celý salon a zpět na svůj rozvrh', function () {
        $this->actingAs($this->sabina);

        $this->get('/admin/kalendar?kadernice=vse&datum='.TEST_DAY)->assertOk()
            ->assertSee('--cal-cols: 2', false)->assertSee('Šárka')
            ->assertSee('Můj rozvrh')
            // Odkazy si „celý salon“ nesou dál, jinak by ji další den vrátil k jejímu týdnu.
            ->assertSee('kadernice=vse', false);

        $this->get("/admin/kalendar?kadernice={$this->sarka->id}&datum=".TEST_DAY)->assertOk()
            ->assertSee('--cal-cols: 7', false)->assertSee('Šárka ×')->assertSee('Můj rozvrh');

        $this->get('/admin/dnes')->assertOk()->assertSee('Můj den');
    });

    test('den, kdy kadeřnice nepracuje, má v záhlaví „volno“ a svátek „svátek“', function () {
        $this->sarka->workingHours()->where('weekday', 3)->delete();   // středa

        $this->actingAs($this->spravce);

        $this->get('/admin/kalendar?datum='.TEST_DAY)->assertOk()->assertSeeInOrder(['Sabina', '9:00–17:00', 'Šárka', 'volno']);
        $this->get('/admin/kalendar?datum=2026-11-17')->assertOk()->assertSee('Státní svátek')->assertSee('svátek');
    });

    test('správce nastavuje pracovní dobu kadeřnicím, ne sobě', function () {
        $this->actingAs($this->spravce);

        $this->get('/admin/pracovni-doba')->assertOk()->assertSee('Sabina')->assertSee('Šárka');

        // Sobota pro Šárku.
        $this->post("/admin/pracovni-doba?kadernice={$this->sarka->id}", ['hours' => [6 => [['from' => '09:00', 'to' => '12:00']]]])
            ->assertSessionHas('status');

        expect($this->sarka->workingHours()->pluck('weekday')->all())->toBe([6])
            ->and($this->spravce->workingHours()->count())->toBe(0);
    });

    test('kadeřnice si pracovní dobu včetně víkendu spravuje sama a cizí měnit nesmí', function () {
        $this->actingAs($this->sabina);

        $this->post("/admin/pracovni-doba?kadernice={$this->sarka->id}", ['hours' => [7 => [['from' => '10:00', 'to' => '14:00']]]])
            ->assertSessionHas('status');

        // Parametr se u kadeřnice ignoruje – uložila si neděli sobě.
        expect($this->sabina->workingHours()->pluck('weekday')->all())->toBe([7])
            ->and($this->sarka->workingHours()->count())->toBe(10);
    });
});

describe('tým s různým zaměřením', function () {
    beforeEach(function () {
        $this->sabina->update(['position' => 'kadeřnice']);
        $this->sarka->update(['position' => 'Kadeřnice']);          // velké písmeno je tatáž skupina
        $this->ivana = staffMember($this->salon, attributes: ['name' => 'Ivana', 'position' => 'pedikúra', 'login' => null, 'password' => null]);
        $this->nikola = staffMember($this->salon, attributes: ['name' => 'Nikola', 'position' => 'manikúra']);
        $this->monika = staffMember($this->salon, attributes: ['name' => 'Monika', 'position' => 'manikúra']);
        $this->pedicure = service($this->salon, ['name' => 'Pedikúra', 'price_from' => 450, 'work_1' => 60], $this->ivana);
    });

    test('rozvrh nabízí skupiny podle zaměření a u jména ukazuje, kdo co dělá', function () {
        $this->manager->create($this->jana, [$this->pedicure], at('10:00'), $this->ivana);
        $this->actingAs($this->spravce);

        $this->get('/admin/kalendar?datum='.TEST_DAY)->assertOk()
            ->assertSee('--cal-cols: 5', false)
            ->assertSeeInOrder(['Celý salon', 'Kadeřnice', 'Pedikúra', 'Manikúra'])
            ->assertSee('pedikúra · 9:00–17:00');

        // Skupina manikúra: jen Nikola a Monika, pedikúra v ní není vidět.
        $this->get('/admin/kalendar?obor='.urlencode('manikúra').'&datum='.TEST_DAY)->assertOk()
            ->assertSee('--cal-cols: 2', false)
            ->assertSee('Nikola')->assertSee('Monika')
            ->assertDontSee('Jana Nováková');

        // Skupina s jedním člověkem je rovnou jeho sedm dní; neznámá skupina = celý salon.
        $this->get('/admin/kalendar?obor='.urlencode('pedikúra').'&datum='.TEST_DAY)->assertOk()
            ->assertSee('--cal-cols: 7', false)->assertSee('Jana Nováková');
        $this->get('/admin/kalendar?obor=neexistuje&datum='.TEST_DAY)->assertOk()->assertSee('--cal-cols: 5', false);

        // Sedm dní jednoho člověka ze skupiny.
        $this->get('/admin/kalendar?obor='.urlencode('manikúra')."&kadernice={$this->monika->id}&zobrazeni=tyden&datum=".TEST_DAY)
            ->assertOk()->assertSee('Monika');
    });

    test('bez zaměření se skupiny nenabízejí', function () {
        App\Models\User::query()->update(['position' => null]);

        $this->actingAs($this->spravce)->get('/admin/kalendar?datum='.TEST_DAY)->assertOk()->assertSee('Celý salon')->assertDontSee('obor=', false);
    });

    test('nový člen týmu jde na konec rozvrhu a správce pořadí sloupců mění šipkami', function () {
        $order = fn () => $this->salon->staff()->pluck('name')->all();

        expect($order())->toBe(['Sabina', 'Šárka', 'Ivana', 'Nikola', 'Monika']);

        $this->actingAs($this->spravce);

        $this->post('/admin/kadernice', ['name' => 'Dana', 'position' => ' kosmetika ', 'role' => 'staff', 'color' => '#8E7A73', 'is_bookable' => '1'])->assertRedirect();

        expect($order())->toBe(['Sabina', 'Šárka', 'Ivana', 'Nikola', 'Monika', 'Dana'])
            ->and($this->salon->staff()->where('name', 'Dana')->sole()->position)->toBe('kosmetika');

        $this->post("/admin/kadernice/{$this->monika->id}/poradi", ['direction' => 'up']);
        $this->post("/admin/kadernice/{$this->sabina->id}/poradi", ['direction' => 'down']);
        $this->post("/admin/kadernice/{$this->sarka->id}/poradi", ['direction' => 'up']);   // první už výš nejde

        expect($order())->toBe(['Šárka', 'Sabina', 'Ivana', 'Monika', 'Nikola', 'Dana']);

        $this->get('/admin/kadernice')->assertOk()->assertSeeInOrder(['Šárka', 'Sabina', 'Ivana', 'Monika', 'Nikola', 'Dana'])->assertSee('pedikúra');
        $this->get('/admin/kalendar?datum='.TEST_DAY)->assertOk()->assertSeeInOrder(['Ivana', 'Monika', 'Nikola', 'Dana']);

        // Řadit smí jen správce.
        $this->actingAs($this->sabina)->post("/admin/kadernice/{$this->sabina->id}/poradi", ['direction' => 'up'])->assertForbidden();
    });

    test('zákaznice vidí u jména zaměření a nabídku služeb podle toho, koho vybere', function () {
        $options = app(App\Services\Booking\BookingWizard::class)->options($this->salon);

        expect($options['staffHeading'])->toBe('Ke komu')
            ->and(array_column($options['staff'], 'label'))->toBe(['Sabina · kadeřnice', 'Šárka · Kadeřnice', 'Ivana · pedikúra', 'Nikola · manikúra', 'Monika · manikúra'])
            ->and(collect($options['services'])->firstWhere('name', 'Pedikúra')['staffIds'])->toBe([$this->ivana->id]);

        // Veřejná stránka se s tím vykreslí.
        $this->get('/s/'.$this->salon->slug)->assertOk();
    });
});

describe('cedule salonu a vzhled', function () {
    test('vlastní nápis a podtitul se nastaví v údajích salonu a ukážou se v rozvrhu i v liště', function () {
        $this->actingAs($this->spravce);

        $this->post('/admin/nastaveni', [
            'name' => $this->salon->name, 'slug' => $this->salon->slug, 'sign_title' => ' Aura ', 'sign_subtitle' => 'Beauty Studio',
            'slot_step' => 15, 'min_notice_hours' => 2, 'max_days_ahead' => 60, 'cancel_hours' => 24, 'reminder_hours' => 48,
        ])->assertSessionHas('status');

        expect($this->salon->fresh())->sign_title->toBe('Aura')->sign_subtitle->toBe('Beauty Studio')
            ->and($this->salon->fresh()->signTitle())->toBe('Aura');

        $this->get('/admin/kalendar?datum='.TEST_DAY)->assertOk()
            ->assertSee('class="sign-title">Aura', false)
            ->assertSee('Beauty Studio')
            ->assertSee('--sign-chars: 4', false);

        // Na ostatních stránkách je nápis v liště místo dne.
        $this->get('/admin/vice')->assertOk()->assertSee('top-brand-sign', false)->assertSee('Aura')->assertDontSee('top-day', false);

        // Prázdný nápis = zpět na název salonu.
        $this->post('/admin/nastaveni', [
            'name' => $this->salon->name, 'slug' => $this->salon->slug, 'sign_title' => '', 'sign_subtitle' => '',
            'slot_step' => 15, 'min_notice_hours' => 2, 'max_days_ahead' => 60, 'cancel_hours' => 24, 'reminder_hours' => 48,
        ]);

        expect($this->salon->fresh())->sign_title->toBeNull()->and($this->salon->fresh()->signTitle())->toBe($this->salon->name);
    });

    test('jméno ve sloupci vede na rozvrh jednoho člověka a výběr jde zrušit', function () {
        $this->actingAs($this->spravce);

        $this->get('/admin/kalendar?datum='.TEST_DAY)->assertOk()
            ->assertSee('kadernice='.$this->sarka->id, false);

        $this->get("/admin/kalendar?kadernice={$this->sarka->id}&datum=".TEST_DAY)->assertOk()
            ->assertSee('--cal-cols: 7', false)
            ->assertSee('Šárka ×');

        $this->get("/admin/kalendar?kadernice={$this->sarka->id}&zobrazeni=tyden&datum=".TEST_DAY)->assertOk()
            ->assertSeeInOrder(['top-day', 'Šárka', 'st 11.', 'út 17.'], false);
    });

    test('světlá a tmavá verze jdou přepnout ručně – stránka si volbu načte hned', function () {
        $this->actingAs($this->spravce);

        $this->get('/admin/vice')->assertOk()->assertSee("localStorage.getItem('luxio-theme')", false)->assertSee('$store.theme.toggle()', false);
        $this->get('/admin/ucet')->assertOk()->assertSee('Vzhled')->assertSee('Podle zařízení');
    });
});

describe('dvě zákaznice najednou', function () {
    $form = fn ($test, array $overrides = []) => $overrides + [
        'customer_id' => $test->eva->id, 'services' => [$test->cut->id], 'staff' => $test->sabina->id, 'date' => TEST_DAY, 'time' => '10:00',
    ];

    test('na obsazený čas zapíše jen výslovně zvolený „jiný čas“', function () use ($form) {
        $this->manager->create($this->jana, [$this->cut], at('10:00'), $this->sabina);

        $this->actingAs($this->sabina);

        $this->post('/admin/rezervace', $form($this))->assertSessionHas('error');
        expect(Booking::count())->toBe(1);

        $this->post('/admin/rezervace', $form($this, ['force' => '1']))->assertRedirect()
            ->assertSessionHas('status', fn (string $status) => str_contains($status, 'mimo volné termíny'));

        expect(Booking::where('staff_id', $this->sabina->id)->where('starts_at', at('10:00'))->count())->toBe(2);

        // Zákaznicím se obsazený čas dál nenabízí.
        expect(times(app(SlotFinder::class)->forDay($this->salon, [$this->cut], at('00:00'), $this->sabina)))->not->toContain('10:00');

        // V rozvrhu stojí rezervace vedle sebe, každá na půl sloupce.
        $this->get('/admin/kalendar?datum='.TEST_DAY)->assertOk()
            ->assertSee('* 0 / 2)', false)
            ->assertSee('* 1 / 2)', false)
            ->assertSee('Eva Malá');
    });

    test('volný čas se zapíše bez upozornění, i když je „jiný čas“ zaškrtnutý', function () use ($form) {
        $this->actingAs($this->sabina)->post('/admin/rezervace', $form($this, ['force' => '1']))->assertRedirect()
            ->assertSessionHas('status', 'Rezervace je vytvořená.');
    });

    test('mimo pracovní dobu jde zapsat taky jen s „jiným časem“', function () use ($form) {
        $this->actingAs($this->sabina);

        $this->post('/admin/rezervace', $form($this, ['time' => '19:10']))->assertSessionHas('error');
        $this->post('/admin/rezervace', $form($this, ['time' => '19:10', 'force' => '1']))->assertRedirect();

        expect(Booking::sole()->starts_at->format('H:i'))->toBe('19:10');
    });

    test('volba „Kdokoliv“ na obsazený čas nezapíše – chce konkrétní kadeřnici', function () use ($form) {
        $this->manager->create($this->jana, [$this->cut], at('10:00'), $this->sabina);
        $this->manager->create(customer($this->salon), [$this->cut], at('10:00'), $this->sarka);

        $this->actingAs($this->spravce)->post('/admin/rezervace', $form($this, ['staff' => '', 'force' => '1']))->assertSessionHas('error');

        expect(Booking::count())->toBe(2);
    });

    test('zákaznice si přes svůj odkaz obsazený čas vynutit nemůže', function () use ($form) {
        $this->manager->create($this->jana, [$this->cut], at('10:00'), $this->sabina);
        $token = tokenFor($this->eva);

        $this->post(k($token, '/rezervace'), ['services' => [$this->cut->id], 'staff' => $this->sabina->id, 'date' => TEST_DAY, 'time' => '10:00', 'force' => '1']);

        expect(Booking::count())->toBe(1);
    });

    test('přesun na obsazený čas projde jen s „jiným časem“', function () {
        $booking = $this->manager->create($this->jana, [$this->cut], at('10:00'), $this->sabina);
        $this->manager->create($this->eva, [$this->cut], at('13:00'), $this->sabina);

        $this->actingAs($this->sabina);

        $this->post("/admin/rezervace/{$booking->id}/presun", ['staff' => $this->sabina->id, 'date' => TEST_DAY, 'time' => '13:00'])->assertSessionHas('error');
        $this->post("/admin/rezervace/{$booking->id}/presun", ['staff' => $this->sabina->id, 'date' => TEST_DAY, 'time' => '13:00', 'force' => '1'])
            ->assertSessionHas('status');

        expect($booking->fresh()->starts_at)->toEqual(at('13:00'));
    });

    test('rezervace v pauze jiné (působí barva) zůstává na plnou šířku, souběh práce se dělí na pruhy', function () {
        $coloring = coloring($this->salon);   // 9:00 práce, 9:30–10:10 pauza, 10:10–11:10 práce
        $a = $this->manager->create($this->jana, [$coloring], at('09:00'), $this->sabina);
        $b = $this->manager->create($this->eva, [service($this->salon, ['work_1' => 30], $this->sabina)], at('09:30'), $this->sabina);

        $load = fn () => Booking::with('segments')->orderBy('starts_at')->get();

        expect(CalendarLanes::assign($load()))->toBe([
            $a->id => ['lane' => 0, 'lanes' => 1],
            $b->id => ['lane' => 0, 'lanes' => 1],
        ]);

        // Třetí zákaznice přes práci první: dostane vlastní pruh a všechny tři se dělí o sloupec.
        $c = $this->manager->create(customer($this->salon), [$this->cut], at('09:00'), $this->sabina, allowOverlap: true);

        expect(CalendarLanes::assign($load()))->toBe([
            $a->id => ['lane' => 0, 'lanes' => 2],
            $c->id => ['lane' => 1, 'lanes' => 2],
            $b->id => ['lane' => 0, 'lanes' => 2],
        ]);
    });
});

describe('přetažení rezervace v rozvrhu', function () {
    test('na jiný čas a ke kolegyni: rezervace se přesune a zákaznice dostane oznámení', function () {
        Event::fake([BookingMoved::class]);
        $booking = $this->manager->create($this->jana, [$this->cut], at('10:00'), $this->sabina);

        $this->actingAs($this->sabina)->postJson("/admin/rezervace/{$booking->id}/pretazeni", ['staff' => $this->sarka->id, 'date' => TEST_DAY, 'time' => '13:30'])
            ->assertOk()->assertJsonPath('ok', true)->assertJsonPath('overlap', false);

        $booking->refresh();

        expect($booking->staff_id)->toBe($this->sarka->id)
            ->and($booking->starts_at)->toEqual(at('13:30'))
            ->and($booking->segments()->pluck('staff_id')->unique()->all())->toBe([$this->sarka->id]);

        Event::assertDispatched(BookingMoved::class);

        // Rozvrh po načtení ukáže, co se stalo, a rezervace jde táhnout znovu.
        $this->get('/admin/kalendar?kadernice=vse&datum='.TEST_DAY)->assertOk()
            ->assertSee('Jana Nováková: st 11.')
            ->assertSee('data-draggable', false)
            ->assertSee('data-booking="'.$booking->id.'"', false);
    });

    test('pustit jde i na obsazený čas nebo mimo pracovní dobu – odpověď na to upozorní', function () {
        $booking = $this->manager->create($this->jana, [$this->cut], at('10:00'), $this->sabina);
        $this->manager->create($this->eva, [$this->cut], at('14:00'), $this->sabina);

        $this->actingAs($this->spravce);

        $this->postJson("/admin/rezervace/{$booking->id}/pretazeni", ['staff' => $this->sabina->id, 'date' => TEST_DAY, 'time' => '14:15'])
            ->assertOk()->assertJsonPath('overlap', true);
        $this->postJson("/admin/rezervace/{$booking->id}/pretazeni", ['staff' => $this->sabina->id, 'date' => TEST_DAY, 'time' => '7:00'])
            ->assertOk()->assertJsonPath('overlap', true);

        expect($booking->fresh()->starts_at)->toEqual(at('07:00'));
    });

    test('v týdnu jde přetáhnout na jiný den', function () {
        $booking = $this->manager->create($this->jana, [$this->cut], at('10:00'), $this->sabina);

        $this->actingAs($this->sabina)->postJson("/admin/rezervace/{$booking->id}/pretazeni", ['staff' => $this->sabina->id, 'date' => '2026-11-13', 'time' => '9:00'])
            ->assertOk();

        expect($booking->fresh()->starts_at)->toEqual(at('09:00', '2026-11-13'));
    });

    test('zrušená nebo proběhlá rezervace, cizí kadeřnice a nesmyslný čas neprojdou', function () {
        [$cizi, $ciziKadernice] = salonWithStaff();
        $booking = $this->manager->create($this->jana, [$this->cut], at('10:00'), $this->sabina);
        $jina = $this->manager->create(customer($cizi), [service($cizi)], at('10:00'), $ciziKadernice);

        $this->actingAs($this->sabina);
        $drop = fn (array $data, $target = null) => $this->postJson('/admin/rezervace/'.($target ?? $booking)->id.'/pretazeni', $data + ['staff' => $this->sarka->id, 'date' => TEST_DAY, 'time' => '13:00']);

        $drop(['staff' => $ciziKadernice->id])->assertStatus(422);
        $drop(['staff' => $this->spravce->id])->assertStatus(422);          // správce nemá sloupec v rozvrhu
        $drop(['time' => '25:00'])->assertStatus(422);
        $drop(['date' => 'zítra'])->assertStatus(422);
        $drop([], $jina)->assertNotFound();

        $this->manager->cancelByStaff($booking);
        $drop([])->assertStatus(422);

        expect($booking->fresh()->staff_id)->toBe($this->sabina->id);
        $this->get('/admin/kalendar?kadernice=vse&datum='.TEST_DAY)->assertOk()->assertDontSee('data-draggable', false);
    });

    test('bez přihlášení přetažení nejde', function () {
        $booking = $this->manager->create($this->jana, [$this->cut], at('10:00'), $this->sabina);

        $this->postJson("/admin/rezervace/{$booking->id}/pretazeni", ['staff' => $this->sarka->id, 'date' => TEST_DAY, 'time' => '13:00'])->assertUnauthorized();

        expect($booking->fresh()->staff_id)->toBe($this->sabina->id);
    });
});

describe('upozornění pro správce', function () {
    test('o nové rezervaci u kadeřnice se dozví i správce na recepci, a to se jménem kadeřnice', function () {
        Notification::fake();
        $this->spravce->updatePushSubscription('https://push.example.com/spravce', 'klic', 'tajemstvi', 'aes128gcm');
        $this->sabina->updatePushSubscription('https://push.example.com/sabina', 'klic', 'tajemstvi', 'aes128gcm');
        $this->sarka->updatePushSubscription('https://push.example.com/sarka', 'klic', 'tajemstvi', 'aes128gcm');

        $booking = $this->manager->create($this->jana, [$this->cut], at('10:00'), $this->sabina);

        Notification::assertSentTo($this->sabina, BookingNotification::class, fn (BookingNotification $n) => ! str_contains($n->body(), 'Sabina'));
        Notification::assertSentTo($this->spravce, BookingNotification::class, fn (BookingNotification $n) => $n->notice === BookingNotice::NewBooking
            && str_contains($n->body(), 'Jana Nováková') && str_ends_with($n->body(), '· Sabina'));
        // Kolegyně, které se rezervace netýká, nic nedostane.
        Notification::assertNotSentTo($this->sarka, BookingNotification::class);

        // Zrušení zákaznicí jde taky oběma.
        $this->manager->cancelByCustomer($booking);

        Notification::assertSentTo($this->spravce, BookingNotification::class, fn (BookingNotification $n) => $n->notice === BookingNotice::CancelledByCustomer);
    });

    test('správce, který je sám kadeřnicí, cizí rezervace na telefon nedostává', function () {
        Notification::fake();
        $this->spravce->update(['is_bookable' => true]);
        $this->spravce->updatePushSubscription('https://push.example.com/spravce', 'klic', 'tajemstvi', 'aes128gcm');

        $this->manager->create($this->jana, [$this->cut], at('10:00'), $this->sabina);

        Notification::assertNotSentTo($this->spravce, BookingNotification::class);
    });
});

describe('předání jiné kadeřnici', function () {
    test('rezervace přejde ke kolegyni ve stejný čas a zákaznici se neposílá oznámení o přesunu', function () {
        Event::fake([BookingMoved::class]);
        $booking = $this->manager->create($this->jana, [$this->cut], at('10:00'), $this->sabina);

        $this->actingAs($this->spravce);

        $this->get("/admin/rezervace/{$booking->id}")->assertOk()->assertSee('Předat jiné kadeřnici')->assertSee('Šárka');

        $this->post("/admin/rezervace/{$booking->id}/kadernice", ['kadernice' => $this->sarka->id])
            ->assertSessionHas('status', fn (string $status) => str_contains($status, 'Šárka') && ! str_contains($status, 'Pozor'));

        $booking->refresh();

        expect($booking->staff_id)->toBe($this->sarka->id)
            ->and($booking->starts_at)->toEqual(at('10:00'))
            ->and($booking->segments()->pluck('staff_id')->unique()->all())->toBe([$this->sarka->id]);

        Event::assertNotDispatched(BookingMoved::class);

        // Sabina má čas zase volný.
        expect(times(app(SlotFinder::class)->forDay($this->salon, [$this->cut], at('00:00'), $this->sabina)))->toContain('10:00');
    });

    test('předat jde i kadeřnici, která má v tu dobu plno – s upozorněním', function () {
        $booking = $this->manager->create($this->jana, [$this->cut], at('10:00'), $this->sabina);
        $this->manager->create($this->eva, [$this->cut], at('10:00'), $this->sarka);

        $this->actingAs($this->spravce)->post("/admin/rezervace/{$booking->id}/kadernice", ['kadernice' => $this->sarka->id])
            ->assertSessionHas('status', fn (string $status) => str_contains($status, 'Pozor'));

        expect(Booking::where('staff_id', $this->sarka->id)->count())->toBe(2);
    });

    test('kadeřnici z cizího salonu ani správci bez rozvrhu předat nejde', function () {
        [, $cizi] = salonWithStaff();
        $booking = $this->manager->create($this->jana, [$this->cut], at('10:00'), $this->sabina);

        $this->actingAs($this->spravce);

        $this->post("/admin/rezervace/{$booking->id}/kadernice", ['kadernice' => $cizi->id])->assertSessionHasErrors('kadernice');
        $this->post("/admin/rezervace/{$booking->id}/kadernice", ['kadernice' => $this->spravce->id])->assertSessionHasErrors('kadernice');

        expect($booking->fresh()->staff_id)->toBe($this->sabina->id);
    });
});
