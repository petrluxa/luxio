<?php

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Salon;
use App\Services\Booking\BookingManager;

beforeEach(function () {
    $this->travelTo(TEST_NOW);
    [$this->salon, $this->staff] = salonWithStaff();
    $this->cut = service($this->salon);
    $this->jana = customer($this->salon);
    $this->token = tokenFor($this->jana);
});

function pick(array $overrides = []): array
{
    return $overrides + ['date' => TEST_DAY, 'time' => '10:00'];
}

test('průvodce nabídne služby salonu', function () {
    $this->get(k($this->token, '/rezervace'))
        ->assertOk()
        ->assertSee('Nová rezervace')
        ->assertViewHas('wizard', fn (array $wizard) => $wizard['services'][0]['name'] === 'Dámský střih'
            && $wizard['selected']['staffId'] === null)
        ->assertSee('Rezervovat');
});

test('nabídka termínů vrací 14 dní s volnými časy', function () {
    $response = $this->getJson(k($this->token, '/rezervace/terminy?services[]='.$this->cut->id))->assertOk();

    expect($response->json('days'))->toHaveCount(14)
        ->and($response->json('days.0.date'))->toBe('2026-11-09')
        ->and($response->json('days.0.chip'))->toBe('Po 9')
        ->and($response->json('days.0.times.0'))->toBe('10:00')          // dnes nejdřív za 2 hodiny
        ->and($response->json('days.2.times.0'))->toBe('9:00')
        ->and($response->json('days.5.times'))->toBe([])                 // sobota
        ->and($response->json('summary.duration'))->toContain('45')
        ->and($response->json('prev'))->toBeNull()
        ->and($response->json('next'))->toBe('2026-11-23');
});

test('ověřená zákaznice má termín potvrzený hned', function () {
    $this->post(k($this->token, '/rezervace'), pick(['services' => [$this->cut->id], 'staff' => $this->staff->id, 'note' => 'Prosím kratší ofinu']))
        ->assertRedirect()
        ->assertSessionHas('status', 'Termín je rezervovaný.');

    $booking = Booking::sole();

    expect($booking->status)->toBe(BookingStatus::Confirmed)
        ->and($booking->customer_id)->toBe($this->jana->id)
        ->and($booking->starts_at->format('Y-m-d H:i'))->toBe('2026-11-11 10:00')
        ->and($booking->customer_note)->toBe('Prosím kratší ofinu');

    $this->get(k($this->token, "/terminy/{$booking->id}"))->assertOk()->assertSee('Dámský střih')->assertSee('Zrušit termín');
    $this->get(k($this->token))->assertSee('Příští návštěva');
});

test('nová (neověřená) zákaznice čeká na schválení', function () {
    $this->jana->update(['is_verified' => false]);

    $this->post(k($this->token, '/rezervace'), pick(['services' => [$this->cut->id]]))->assertRedirect();

    expect(Booking::sole()->status)->toBe(BookingStatus::Pending);
});

test('když salon schvalování vypne, je i nová zákaznice potvrzená hned', function () {
    $this->salon->update(['settings' => ['approve_new_customers' => false]]);
    $this->jana->update(['is_verified' => false]);

    $this->post(k($this->token, '/rezervace'), pick(['services' => [$this->cut->id]]))->assertRedirect();

    expect(Booking::sole()->status)->toBe(BookingStatus::Confirmed);
});

test('obsazený termín vrátí zákaznici zpět s chybou a výběrem', function () {
    app(BookingManager::class)->create(customer($this->salon), [$this->cut], at('10:00'), $this->staff);

    $this->from(k($this->token, '/rezervace'))
        ->post(k($this->token, '/rezervace'), pick(['services' => [$this->cut->id]]))
        ->assertRedirect(k($this->token, '/rezervace'))
        ->assertSessionHas('error')
        ->assertSessionHasInput('time', '10:00');

    expect(Booking::count())->toBe(1);
});

test('bez služby nebo času rezervace neprojde', function () {
    $this->post(k($this->token, '/rezervace'), ['date' => TEST_DAY, 'time' => '10:00'])->assertSessionHasErrors('services');
    $this->post(k($this->token, '/rezervace'), ['services' => [$this->cut->id], 'date' => TEST_DAY])->assertSessionHasErrors('time');
    $this->post(k($this->token, '/rezervace'), pick(['services' => [999]]))->assertSessionHasErrors('services');

    expect(Booking::count())->toBe(0);
});

test('služba cizího salonu se rezervovat nedá', function () {
    $other = Salon::factory()->create();
    staffMember($other);
    $foreign = service($other);

    $this->post(k($this->token, '/rezervace'), pick(['services' => [$foreign->id]]))->assertSessionHasErrors('services');
});

test('zablokovaná zákaznice online rezervovat nemůže', function () {
    $this->jana->update(['is_blocked' => true]);

    $this->get(k($this->token, '/rezervace'))->assertOk()->assertSee('není dostupná');
    $this->post(k($this->token, '/rezervace'), pick(['services' => [$this->cut->id]]))->assertSessionHas('error');

    expect(Booking::count())->toBe(0);
});

test('token omezený na kadeřnici rezervuje jen u ní', function () {
    $lucie = staffMember($this->salon);
    $lucie->services()->attach($this->cut->id);
    $scoped = tokenFor($this->jana, $lucie->id);

    // V požadavku je Petra, ale token platí jen pro Lucii.
    $this->post(k($scoped, '/rezervace'), pick(['services' => [$this->cut->id], 'staff' => $this->staff->id]))->assertRedirect();

    expect(Booking::sole()->staff_id)->toBe($lucie->id);
});

describe('moje termíny', function () {
    beforeEach(function () {
        $this->booking = app(BookingManager::class)->create($this->jana, [$this->cut], at('10:00'), $this->staff);
    });

    test('seznam ukazuje nadcházející termíny', function () {
        $this->get(k($this->token, '/terminy'))->assertOk()->assertSee('Nadcházející')->assertSee('Dámský střih');
    });

    test('zrušení ve lhůtě', function () {
        $this->post(k($this->token, "/terminy/{$this->booking->id}/zrusit"))
            ->assertRedirect(k($this->token, '/terminy'))
            ->assertSessionHas('status', 'Termín je zrušený.');

        expect($this->booking->fresh()->status)->toBe(BookingStatus::CancelledByCustomer);
    });

    test('po lhůtě místo tlačítka text „Pro zrušení zavolejte“ a telefon', function () {
        $this->travelTo('2026-11-10 11:00:00'); // do termínu zbývá 23 h

        $this->get(k($this->token, "/terminy/{$this->booking->id}"))
            ->assertOk()
            ->assertSee('Pro zrušení zavolejte')
            ->assertSee('tel:'.$this->salon->phone, false)
            ->assertDontSee('Zrušit termín');

        $this->post(k($this->token, "/terminy/{$this->booking->id}/zrusit"))->assertSessionHas('error');

        expect($this->booking->fresh()->status)->toBe(BookingStatus::Confirmed);
    });

    test('přesun v jednom kroku: starý termín se zruší, nový vznikne', function () {
        $this->get(k($this->token, '/rezervace?presun='.$this->booking->id))->assertOk()->assertSee('Přesouváte termín');

        $this->post(k($this->token, '/rezervace'), [
            'services' => [$this->cut->id], 'staff' => $this->staff->id,
            'date' => TEST_DAY, 'time' => '10:30', 'replaces' => $this->booking->id,
        ])->assertRedirect()->assertSessionHas('status', 'Termín je přesunutý.');

        expect($this->booking->fresh()->status)->toBe(BookingStatus::CancelledByCustomer)
            ->and(Booking::whereIn('status', BookingStatus::active())->sole()->starts_at->format('H:i'))->toBe('10:30');
    });

    test('po lhůtě už přesun online nejde', function () {
        $this->travelTo('2026-11-10 11:00:00');

        $this->get(k($this->token, '/rezervace?presun='.$this->booking->id))
            ->assertRedirect(k($this->token, '/terminy'))
            ->assertSessionHas('error');
    });
});

test('profil: změna kontaktu a vypnutí notifikací', function () {
    $this->post(k($this->token, '/profil'), ['name' => 'Jana Nová', 'phone' => '777 111 222', 'email' => 'jana.nova@example.com'])
        ->assertSessionHas('status');

    expect($this->jana->fresh())
        ->name->toBe('Jana Nová')
        ->phone->toBe('+420777111222')
        ->notifications_enabled->toBeFalse();
});
