<?php

use App\Services\Booking\BookingManager;

/*
 * Rozvrh dne celého salonu pro zákaznice: sloupec pro každou kadeřnici, volno a obsazeno bez jmen.
 * Zákaznice ťukne na volný čas u té, ke které chce, a pak teprve vybere službu.
 *
 * Časy v odpovědi jsou minuty od půlnoci (9:00 = 540).
 */

beforeEach(function () {
    $this->travelTo(TEST_NOW);
    [$this->salon, $this->petra] = salonWithStaff();
    $this->petra->update(['name' => 'Petra']);
    $this->dana = staffMember($this->salon, null, ['name' => 'Dana']);
    $this->cut = service($this->salon);                                               // 45 min, obě
    $this->blow = service($this->salon, ['name' => 'Foukaná', 'work_1' => 30], $this->petra); // 30 min, jen Petra
    $this->jana = customer($this->salon, ['name' => 'Jana Nováková', 'phone' => '+420603123456']);
});

function board(string $slug, string $day = TEST_DAY): array
{
    return test()->getJson("/s/{$slug}/rozvrh?den={$day}")->assertOk()->json();
}

function column(array $board, int $staffId): array
{
    return collect($board['columns'])->firstWhere('staffId', $staffId);
}

test('rozvrh má sloupec pro každou kadeřnici s její pracovní dobou a volnými časy po půl hodině', function () {
    $board = board($this->salon->slug);

    expect($board['date'])->toBe(TEST_DAY)
        ->and($board['label'])->toContain('středa')
        ->and($board['from'])->toBe(540)->and($board['to'])->toBe(1020)
        ->and($board['closed'])->toBeFalse()
        ->and($board['mode'])->toBe('day')
        ->and(array_column($board['columns'], 'staffId'))->toBe([$this->petra->id, $this->dana->id])
        ->and(array_column($board['columns'], 'title'))->toBe(['Petra', 'Dana']);

    $petra = column($board, $this->petra->id);

    expect($petra['open'])->toBe([[540, 720], [780, 1020]])
        ->and($petra['taken'])->toBe([])
        ->and($petra['starts'])->toContain(540, 690, 780, 990)
        ->and($petra['starts'])->not->toContain(720, 750);   // polední pauza
});

test('volný čas znamená, že se od něj vejde aspoň jedna služba té kadeřnice', function () {
    $board = board($this->salon->slug);

    // 11:30 – do polední pauzy zbývá půl hodiny: Petře se vejde foukaná, Daně (jen střih 45 min) nic,
    // takže u Dany je to pole obsazené – objednat se tam nedá.
    expect(column($board, $this->petra->id)['starts'])->toContain(690)
        ->and(column($board, $this->dana->id)['starts'])->not->toContain(690)
        ->and(column($board, $this->dana->id)['taken'])->toContain(690)
        ->and(column($board, $this->dana->id)['starts'])->toContain(660);
});

test('obsazený čas je v rozvrhu vidět, ale bez jména, telefonu a služby', function () {
    app(BookingManager::class)->create($this->jana, [$this->cut], at('10:00'), $this->petra);

    $response = $this->getJson("/s/{$this->salon->slug}/rozvrh?den=".TEST_DAY)->assertOk();
    $petra = column($response->json(), $this->petra->id);

    // Rezervace 10:00–10:45 zabere dvě půlhodiny rozvrhu; od 11:00 je zase volno.
    expect($petra['taken'])->toBe([600, 630])
        ->and($petra['starts'])->not->toContain(600, 630)
        ->and($petra['starts'])->toContain(570, 660)
        ->and(column($response->json(), $this->dana->id)['taken'])->not->toContain(600);

    expect($response->getContent())
        ->not->toContain('Jana')->not->toContain('Nováková')->not->toContain('603123456')->not->toContain('Dámský');
});

test('dnes se nenabízí čas, který už nejde stihnout, a zavřený den je označený', function () {
    // Pondělí 8:00, objednat se jde nejdřív dvě hodiny předem → od 10:00.
    $today = board($this->salon->slug, '2026-11-09');

    expect($today['isToday'])->toBeTrue()
        ->and($today['prev'])->toBeNull()
        ->and(column($today, $this->petra->id)['starts'])->not->toContain(540, 570)
        ->and(column($today, $this->petra->id)['taken'])->not->toContain(540, 570)   // zavřeno, ne obsazeno
        ->and(column($today, $this->petra->id)['starts'])->toContain(600);

    $sunday = board($this->salon->slug, '2026-11-15');

    expect($sunday['closed'])->toBeTrue()
        ->and(column($sunday, $this->petra->id)['starts'])->toBe([]);
});

test('den mimo povolené rozmezí se srovná na dnešek nebo na poslední možný den', function () {
    expect(board($this->salon->slug, '2020-01-01')['date'])->toBe('2026-11-09')
        ->and(board($this->salon->slug, '2030-01-01')['date'])->toBe('2027-01-08')   // 60 dní dopředu
        ->and(board($this->salon->slug, '2030-01-01')['next'])->toBeNull()
        ->and(board($this->salon->slug, 'nesmysl')['date'])->toBe('2026-11-09');
});

test('stránka salonu s víc lidmi začíná rozvrhem, salon s jednou kadeřnicí výběrem dne', function () {
    $this->get('/s/'.$this->salon->slug)
        ->assertOk()
        ->assertViewHas('wide', true)
        ->assertViewHas('wizard', fn (array $wizard) => str_ends_with($wizard['boardUrl'], "/s/{$this->salon->slug}/rozvrh"))
        ->assertSee('Ťukněte na volný čas')
        ->assertSee('pub-board', false)
        ->assertSee('pub-other', false);     // nová zákaznice má pod rozvrhem odkaz „Už mám účet“

    [$small] = salonWithStaff();
    service($small);

    $this->get('/s/'.$small->slug)
        ->assertOk()
        ->assertViewHas('wide', false)
        ->assertViewHas('wizard', fn (array $wizard) => $wizard['boardUrl'] === null)
        ->assertDontSee('pub-board', false);
});

test('stálá zákaznice vidí rozvrh ze svého odkazu; odkaz vázaný na jednu kadeřnici ukáže jen ji', function () {
    $token = tokenFor($this->jana);

    // Odkaz na účet má nahoře – pod rozvrhem se neopakuje.
    $this->get(k($token, '/rezervace'))->assertOk()->assertSee('pub-board', false)->assertDontSee('pub-other', false);

    expect(array_column($this->getJson(k($token, '/rezervace/rozvrh?den='.TEST_DAY))->assertOk()->json('columns'), 'staffId'))
        ->toBe([$this->petra->id, $this->dana->id]);

    $scoped = tokenFor($this->jana, $this->dana->id);

    $this->get(k($scoped, '/rezervace'))->assertOk()->assertDontSee('pub-board', false);

    expect(array_column($this->getJson(k($scoped, '/rezervace/rozvrh?den='.TEST_DAY))->assertOk()->json('columns'), 'staffId'))
        ->toBe([$this->dana->id]);
});

test('přesun termínu rozvrh neukazuje – služby jsou dané a vybírá se jen den a čas', function () {
    $booking = app(BookingManager::class)->create($this->jana, [$this->cut], at('10:00'), $this->petra);

    $this->get(k(tokenFor($this->jana), '/rezervace?presun='.$booking->id))->assertOk()->assertDontSee('pub-board', false);
});

test('ťuknutí na kadeřnici ukáže jejích sedm dní – sloupec pro každý den', function () {
    app(BookingManager::class)->create($this->jana, [$this->cut], at('10:00'), $this->dana);

    $week = test()->getJson("/s/{$this->salon->slug}/rozvrh?den=".TEST_DAY."&kadernice={$this->dana->id}")->assertOk()->json();

    expect($week['mode'])->toBe('week')
        ->and($week['staffId'])->toBe($this->dana->id)
        ->and(array_column($week['columns'], 'date'))->toBe(['2026-11-11', '2026-11-12', '2026-11-13', '2026-11-14', '2026-11-15', '2026-11-16', '2026-11-17'])
        ->and(array_unique(array_column($week['columns'], 'staffId')))->toBe([$this->dana->id])
        ->and($week['columns'][0]['title'])->toBe('St 11. 11.')
        ->and($week['columns'][0]['subtitle'])->toBe('9:00–17:00')
        // Dana dělá jen střih na 45 minut: obsazená je rezervace 10:00–10:45, půlhodina před ní,
        // půlhodina před polední pauzou a poslední půlhodina dne – tam se střih nevejde.
        ->and($week['columns'][0]['taken'])->toBe([570, 600, 630, 690, 990])
        ->and($week['columns'][1]['taken'])->toBe([690, 990])
        ->and($week['columns'][3]['subtitle'])->toBe('zavřeno')               // sobota
        ->and($week['columns'][6]['subtitle'])->toBe('zavřeno')               // úterý 17. 11. je svátek
        ->and($week['prev'])->toBe('2026-11-09')                              // o týden zpět, nejdřív dnešek
        ->and($week['next'])->toBe('2026-11-18')
        ->and($week['tomorrow'])->toBeNull();

    // Týden začínající dnes má první sloupec „Dnes“.
    $now = test()->getJson("/s/{$this->salon->slug}/rozvrh?kadernice={$this->dana->id}")->assertOk()->json();

    expect($now['columns'][0]['title'])->toBe('Dnes 9. 11.')->and($now['columns'][0]['today'])->toBeTrue()->and($now['prev'])->toBeNull();
});

test('neznámá kadeřnice nebo cizí odkaz týden neukážou – zůstane den celého salonu', function () {
    expect(test()->getJson("/s/{$this->salon->slug}/rozvrh?kadernice=999999")->assertOk()->json('mode'))->toBe('day');

    // Odkaz vázaný na Danu nepustí k týdnu Petry.
    $scoped = tokenFor($this->jana, $this->dana->id);
    $board = $this->getJson(k($scoped, "/rezervace/rozvrh?kadernice={$this->petra->id}"))->assertOk()->json();

    expect($board['mode'])->toBe('day')->and(array_column($board['columns'], 'staffId'))->toBe([$this->dana->id]);
});

test('i stálá zákaznice začíná rozvrhem celého salonu – týden kadeřnice si vybere sama', function () {
    $this->jana->update(['preferred_staff_id' => $this->dana->id]);

    $this->get(k(tokenFor($this->jana), '/rezervace'))->assertOk()
        ->assertViewHas('wizard', fn (array $wizard) => ! array_key_exists('boardStaff', $wizard));
});

test('kalendář „Jiný den“ ukáže měsíc: kam jde skočit a kdy se pracuje', function () {
    $month = test()->getJson("/s/{$this->salon->slug}/rozvrh/mesic")->assertOk()->json();
    $day = fn (string $date) => collect($month['days'])->firstWhere('date', $date);

    expect($month['month'])->toBe('2026-11')
        ->and($month['label'])->toBe('Listopad 2026')
        ->and($month['prev'])->toBeNull()                       // do minulosti se nelistuje
        ->and($month['next'])->toBe('2026-12')
        ->and($month['days'])->toHaveCount(42)                  // celé týdny od pondělí 26. 10. do neděle 6. 12.
        ->and($month['days'][0]['date'])->toBe('2026-10-26')
        ->and($day('2026-10-30'))->toMatchArray(['inMonth' => false, 'enabled' => false])
        ->and($day('2026-11-06'))->toMatchArray(['inMonth' => true, 'enabled' => false])   // minulý pátek
        ->and($day('2026-11-09'))->toMatchArray(['today' => true, 'enabled' => true, 'open' => true])
        ->and($day('2026-11-14'))->toMatchArray(['enabled' => true, 'open' => false])      // sobota
        ->and($day('2026-11-17'))->toMatchArray(['enabled' => true, 'open' => false])      // státní svátek
        ->and($day('2026-12-01'))->toMatchArray(['inMonth' => false, 'enabled' => true, 'open' => true]);
});

test('kalendář se drží v rozmezí, kdy se dá objednat, a umí měsíc jedné kadeřnice', function () {
    $slug = $this->salon->slug;

    // 60 dní dopředu = do 8. 1. 2027: leden je poslední měsíc, dál ani zpět do minulosti to nejde.
    expect(test()->getJson("/s/{$slug}/rozvrh/mesic?mesic=2020-05")->json('month'))->toBe('2026-11')
        ->and(test()->getJson("/s/{$slug}/rozvrh/mesic?mesic=2027-06")->json('month'))->toBe('2027-01')
        ->and(test()->getJson("/s/{$slug}/rozvrh/mesic?mesic=2027-01")->json('next'))->toBeNull()
        ->and(test()->getJson("/s/{$slug}/rozvrh/mesic?mesic=nesmysl")->json('month'))->toBe('2026-11');

    // Sobotní kadeřnice: salon má v sobotu otevřeno díky ní, Dana sama ne.
    $sobotni = staffMember($this->salon, [6 => [['09:00', '13:00']]], ['name' => 'Soňa']);
    $saturday = fn (string $query) => collect(test()->getJson("/s/{$slug}/rozvrh/mesic{$query}")->json('days'))->firstWhere('date', '2026-11-14')['open'];

    expect($saturday(''))->toBeTrue()
        ->and($saturday("?kadernice={$this->dana->id}"))->toBeFalse()
        ->and($saturday("?kadernice={$sobotni->id}"))->toBeTrue();

    // Stálá zákaznice má stejný kalendář ve svém odkazu.
    $this->getJson(k(tokenFor($this->jana), '/rezervace/rozvrh/mesic'))->assertOk()->assertJsonPath('month', '2026-11');
});
