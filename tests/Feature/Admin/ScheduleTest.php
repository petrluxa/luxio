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
            // Velký název salonu nahoře a měsíční kalendář vedle rozvrhu.
            ->assertSee($this->salon->name)
            ->assertSee('Listopad 2026')
            // Osobní přehled „Dnes“ správce bez zákaznic v nabídce nemá.
            ->assertDontSee('<span>Dnes</span>', false);
    });

    test('kadeřnice má svůj přehled „Dnes“ a v rozvrhu vidí i kolegyni', function () {
        $this->actingAs($this->sabina);

        $this->get('/admin')->assertOk()->assertSee('<span>Dnes</span>', false);
        $this->get('/admin/kalendar?datum='.TEST_DAY)->assertOk()->assertSee('--cal-cols: 2', false)->assertSee('Šárka');
        $this->get("/admin/kalendar?kadernice={$this->sabina->id}&datum=".TEST_DAY)->assertOk()->assertSee('--cal-cols: 1', false);
        $this->get("/admin/kalendar?kadernice={$this->sarka->id}&zobrazeni=tyden&datum=".TEST_DAY)->assertOk();
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
        $this->get('/admin/kalendar?datum='.TEST_DAY)->assertOk()
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
        $this->get('/admin/kalendar?datum='.TEST_DAY)->assertOk()->assertDontSee('data-draggable', false);
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
