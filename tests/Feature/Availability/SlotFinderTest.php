<?php

use App\Models\Salon;
use App\Models\User;
use App\Services\Availability\SlotFinder;
use App\Services\Booking\BookingManager;
use Illuminate\Support\Collection;

/*
| Výpočet volných termínů (SPEC 4.4). Čas je zamrzlý na pondělí 9. 11. 2026 8:00,
| testovací den je středa 11. 11. – kadeřnice pracuje 9–12 a 13–17, krok 15 min.
*/

beforeEach(fn () => $this->travelTo(TEST_NOW));

function finder(): SlotFinder
{
    return app(SlotFinder::class);
}

function manager(): BookingManager
{
    return app(BookingManager::class);
}

/** Volné časy ve středu 11. 11. jako ['9:00', '9:15', …]. */
function freeTimes(Salon $salon, array $services, ?User $staff = null, string $date = TEST_DAY, bool $ignoreLimits = false): array
{
    return times(finder()->forDay($salon, $services, at('00:00', $date), $staff, $ignoreLimits));
}

/** Jen dopolední časy (před polední pauzou). */
function morning(array $times): array
{
    return array_values(array_filter($times, fn (string $t) => (int) explode(':', $t)[0] < 12));
}

describe('pracovní doba', function () {
    test('nabízí začátky po 15 minutách v rámci pracovní doby', function () {
        [$salon, $staff] = salonWithStaff();
        $cut = service($salon); // 45 min

        $times = freeTimes($salon, [$cut], $staff);

        expect($times)->toHaveCount(24)
            ->and(morning($times))->toBe(['9:00', '9:15', '9:30', '9:45', '10:00', '10:15', '10:30', '10:45', '11:00', '11:15'])
            ->and($times[10])->toBe('13:00')
            ->and(end($times))->toBe('16:15');
    });

    test('hranice dne: služba musí skončit nejpozději s koncem pracovní doby', function () {
        [$salon, $staff] = salonWithStaff();

        $exact = service($salon, ['work_1' => 45]);   // 16:15 → 17:00 přesně
        $over = service($salon, ['work_1' => 50]);    // 16:15 → 17:05 už ne

        expect(freeTimes($salon, [$exact], $staff))->toContain('16:15')
            ->and(freeTimes($salon, [$over], $staff))->toContain('16:00')->not->toContain('16:15');
    });

    test('úklid po poslední zákaznici smí přesáhnout konec pracovní doby', function () {
        [$salon, $staff] = salonWithStaff();
        $cut = service($salon, ['work_1' => 45, 'buffer_after' => 15]);

        expect(freeTimes($salon, [$cut], $staff))->toContain('16:15');
    });

    test('polední pauza: služba se nesmí přes pauzu natáhnout', function () {
        [$salon, $staff] = salonWithStaff();
        $long = service($salon, ['work_1' => 60]);

        $times = freeTimes($salon, [$long], $staff);

        // Poslední dopolední začátek je 11:00 (konec ve 12:00), další až ve 13:00.
        expect(morning($times))->toBe(['9:00', '9:15', '9:30', '9:45', '10:00', '10:15', '10:30', '10:45', '11:00'])
            ->and($times[9])->toBe('13:00');
    });

    test('o víkendu a mimo pracovní dny je zavřeno', function () {
        [$salon, $staff] = salonWithStaff();
        $cut = service($salon);

        expect(freeTimes($salon, [$cut], $staff, '2026-11-14'))->toBe([]); // sobota
    });

    test('začátky se zarovnávají na mřížku kroku, i když směna začíná jindy', function () {
        $salon = Salon::factory()->create();
        $staff = staffMember($salon, [3 => [['09:10', '12:00']]]);
        $cut = service($salon);

        expect(freeTimes($salon, [$cut], $staff)[0])->toBe('9:15');
    });

    test('krok nabídky časů se řídí nastavením salonu', function () {
        [$salon, $staff] = salonWithStaff(['slot_step' => 30]);
        $cut = service($salon);

        expect(morning(freeTimes($salon, [$cut], $staff)))->toBe(['9:00', '9:30', '10:00', '10:30', '11:00']);
    });
});

describe('státní svátky a výjimky', function () {
    test('ve státní svátek je automaticky zavřeno', function () {
        [$salon, $staff] = salonWithStaff();
        $cut = service($salon);

        expect(freeTimes($salon, [$cut], $staff, '2026-11-17'))->toBe([]); // úterý 17. listopadu
    });

    test('výjimka svátek přepíše', function () {
        [$salon, $staff] = salonWithStaff();
        $cut = service($salon);
        $staff->workingHourOverrides()->create(['date' => '2026-11-17', 'starts_at' => '09:00', 'ends_at' => '12:00']);

        $times = freeTimes($salon, [$cut], $staff, '2026-11-17');

        expect($times[0])->toBe('9:00')->and(end($times))->toBe('11:15');
    });

    test('výjimka v běžný den nahradí obvyklou pracovní dobu', function () {
        [$salon, $staff] = salonWithStaff();
        $cut = service($salon);
        $staff->workingHourOverrides()->create(['date' => TEST_DAY, 'starts_at' => '14:00', 'ends_at' => '16:00']);

        expect(freeTimes($salon, [$cut], $staff))->toBe(['14:00', '14:15', '14:30', '14:45', '15:00', '15:15']);
    });
});

describe('volno (time_off)', function () {
    test('blokace části dne vyřadí kolidující časy', function () {
        [$salon, $staff] = salonWithStaff();
        $cut = service($salon);
        $staff->timeOffs()->create(['starts_at' => at('10:00'), 'ends_at' => at('11:00'), 'reason' => 'Zubař']);

        expect(morning(freeTimes($salon, [$cut], $staff)))->toBe(['9:00', '9:15', '11:00', '11:15']);
    });

    test('dovolená přes několik dní zavře celé dny', function () {
        [$salon, $staff] = salonWithStaff();
        $cut = service($salon);
        $staff->timeOffs()->create(['starts_at' => at('00:00', '2026-11-10'), 'ends_at' => at('23:59', '2026-11-12')]);

        expect(freeTimes($salon, [$cut], $staff))->toBe([])
            ->and(freeTimes($salon, [$cut], $staff, '2026-11-13'))->not->toBe([]);
    });

    test('ani pauza na působení barvy nesmí zasahovat do volna', function () {
        [$salon, $staff] = salonWithStaff();
        $color = coloring($salon); // 30 / 40 / 60
        $staff->timeOffs()->create(['starts_at' => at('09:40'), 'ends_at' => at('09:50')]);

        // Od 9:00 by pauza 9:30–10:10 překryla volno; po volnu už se 130 min do 12:00 nevejde.
        expect(morning(freeTimes($salon, [$color], $staff)))->toBe([])
            ->and(freeTimes($salon, [$color], $staff)[0])->toBe('13:00');
    });
});

describe('pauza na působení barvy', function () {
    beforeEach(function () {
        [$this->salon, $this->staff] = salonWithStaff();
        $this->color = coloring($this->salon);                                              // 30 / 40 / 60
        $this->blowdry = service($this->salon, ['name' => 'Foukaná', 'work_1' => 30, 'price_from' => 350]);
        $this->cut = service($this->salon);                                                 // 45 min
    });

    test('do pauzy existujícího barvení se vejde krátká služba', function () {
        // Barvení v 9:00 → práce 9:00–9:30, pauza 9:30–10:10, práce 10:10–11:10
        manager()->create(customer($this->salon), [$this->color], at('09:00'), $this->staff);

        expect(morning(freeTimes($this->salon, [$this->blowdry], $this->staff)))->toBe(['9:30', '11:15', '11:30']);
    });

    test('služba, která se do pauzy celá nevejde, v ní nabídnuta není', function () {
        manager()->create(customer($this->salon), [$this->color], at('09:00'), $this->staff);

        // Střih 45 min od 9:30 by skončil v 10:15 – to už kadeřnice zase barví.
        expect(morning(freeTimes($this->salon, [$this->cut], $this->staff)))->toBe(['11:15']);
    });

    test('do pauzy se musí vejít i úklid', function () {
        manager()->create(customer($this->salon), [$this->color], at('09:00'), $this->staff);
        $withBuffer = service($this->salon, ['name' => 'Foukaná s úklidem', 'work_1' => 30, 'buffer_after' => 15]);

        // 9:30–10:00 + úklid do 10:15 koliduje s prací od 10:10.
        expect(freeTimes($this->salon, [$withBuffer], $this->staff))->not->toContain('9:30');
    });

    test('úklid po existující rezervaci blokuje další začátek', function () {
        $withBuffer = service($this->salon, ['name' => 'Střih s úklidem', 'work_1' => 45, 'buffer_after' => 15]);
        manager()->create(customer($this->salon), [$withBuffer], at('09:00'), $this->staff);

        expect(freeTimes($this->salon, [$this->blowdry], $this->staff)[0])->toBe('10:00');
    });

    test('pauza nové rezervace smí překrýt práci na jiné zákaznici', function () {
        // Foukaná 9:30–10:00. Nové barvení od 9:00: práce 9:00–9:30, pauza 9:30–10:10, práce 10:10–11:10.
        manager()->create(customer($this->salon), [$this->blowdry], at('09:30'), $this->staff);

        expect(morning(freeTimes($this->salon, [$this->color], $this->staff)))->toBe(['9:00']);
    });

    test('dvě barvení se nesmí srazit pracovními úseky', function () {
        manager()->create(customer($this->salon), [$this->color], at('09:00'), $this->staff);

        // Další barvení dopoledne nejde (9:30 → druhá práce 10:40–11:40 koliduje), až odpoledne.
        expect(morning(freeTimes($this->salon, [$this->color], $this->staff)))->toBe([])
            ->and(freeTimes($this->salon, [$this->color], $this->staff)[0])->toBe('13:00');
    });
});

describe('víc služeb v jedné rezervaci', function () {
    test('délky se sčítají', function () {
        [$salon, $staff] = salonWithStaff();
        $cut = service($salon);
        $blowdry = service($salon, ['name' => 'Foukaná', 'work_1' => 30, 'price_from' => 350]);

        // 75 min → poslední dopolední začátek 10:45
        expect(morning(freeTimes($salon, [$cut, $blowdry], $staff)))
            ->toContain('9:00', '10:45')->not->toContain('11:00');
    });

    test('barvení + foukaná: 160 minut s volnou pauzou uprostřed', function () {
        [$salon, $staff] = salonWithStaff();
        $color = coloring($salon);
        $blowdry = service($salon, ['name' => 'Foukaná', 'work_1' => 30, 'price_from' => 350]);

        expect(morning(freeTimes($salon, [$color, $blowdry], $staff)))->toBe(['9:00', '9:15']);
    });
});

describe('lhůty rezervace', function () {
    test('nejdřív lze rezervovat 2 hodiny předem', function () {
        [$salon, $staff] = salonWithStaff();
        $cut = service($salon);

        // Je pondělí 8:00 → dnes nejdřív v 10:00.
        expect(freeTimes($salon, [$cut], $staff, '2026-11-09')[0])->toBe('10:00');
    });

    test('nejdál lze rezervovat podle nastavení salonu', function () {
        [$salon, $staff] = salonWithStaff(['max_days_ahead' => 2]);
        $cut = service($salon);

        expect(freeTimes($salon, [$cut], $staff, '2026-11-11'))->not->toBe([])
            ->and(freeTimes($salon, [$cut], $staff, '2026-11-12'))->toBe([]);
    });

    test('kadeřnice v administraci lhůty obejde', function () {
        [$salon, $staff] = salonWithStaff(['max_days_ahead' => 2]);
        $cut = service($salon);

        expect(freeTimes($salon, [$cut], $staff, '2026-11-09', ignoreLimits: true)[0])->toBe('9:00')
            ->and(freeTimes($salon, [$cut], $staff, '2026-11-12', ignoreLimits: true))->not->toBe([]);
    });
});

describe('„Kdokoliv“ – víc kadeřnic', function () {
    test('nabídka je sjednocení časů všech kadeřnic', function () {
        $salon = Salon::factory()->create();
        $petra = staffMember($salon);
        $lucie = staffMember($salon, [3 => [['13:00', '19:00']]]);
        $cut = service($salon);

        manager()->create(customer($salon), [$cut], at('09:00'), $petra);

        $slots = finder()->forDay($salon, [$cut], at('00:00'))->keyBy(fn ($s) => $s->time());

        expect($slots->has('9:00'))->toBeFalse()                       // Petra má obsazeno, Lucie ještě nepracuje
            ->and($slots['10:00']->staffIds)->toBe([$petra->id])
            ->and($slots['14:00']->staffIds)->toBe([$petra->id, $lucie->id])
            ->and($slots['18:00']->staffIds)->toBe([$lucie->id]);
    });

    test('přiřadí se kadeřnice s nejméně rezervacemi ten den', function () {
        $salon = Salon::factory()->create();
        $petra = staffMember($salon);
        $lucie = staffMember($salon);
        $cut = service($salon);

        manager()->create(customer($salon), [$cut], at('09:00'), $petra);

        $first = manager()->create(customer($salon), [$cut], at('14:00'));   // Lucie má 0, Petra 1
        $second = manager()->create(customer($salon), [$cut], at('15:00'));  // obě 1 → nižší id

        expect($first->staff_id)->toBe($lucie->id)
            ->and($second->staff_id)->toBe($petra->id);
    });

    test('kadeřnice, která službu nedělá nebo nepřijímá objednávky, se nenabízí', function () {
        $salon = Salon::factory()->create();
        $petra = staffMember($salon);
        $lucie = staffMember($salon);
        $hidden = staffMember($salon, attributes: ['is_bookable' => false]);
        $color = service($salon, ['name' => 'Barvení', 'work_1' => 60], $petra, $hidden); // Lucie nebarví

        $slots = finder()->forDay($salon, [$color], at('00:00'));

        expect($slots->pluck('staffIds')->flatten()->unique()->values()->all())->toBe([$petra->id])
            ->and(freeTimes($salon, [$color], $lucie))->toBe([])
            ->and(freeTimes($salon, [$color], $hidden))->toBe([]);
    });

    test('vlastní délka služby u kadeřnice mění nabídku časů', function () {
        [$salon, $staff] = salonWithStaff();
        $cut = service($salon); // v ceníku 45 min
        $staff->services()->updateExistingPivot($cut->id, ['work_1' => 60, 'price_from' => 700]);

        expect(morning(freeTimes($salon, [$cut], $staff)))->toContain('11:00')->not->toContain('11:15');
    });
});

test('výpočet na 14 dní vrací každý den, i ten bez volného termínu', function () {
    [$salon, $staff] = salonWithStaff();
    $cut = service($salon);

    $days = finder()->forDays($salon, [$cut], at('00:00', '2026-11-09'), 14, $staff);

    expect($days)->toHaveCount(14)
        ->and($days['2026-11-14'])->toBeInstanceOf(Collection::class)->toBeEmpty()   // sobota
        ->and($days['2026-11-17'])->toBeEmpty()                                      // svátek
        ->and($days['2026-11-18'])->toHaveCount(24);
});

test('bez vybrané služby nejsou žádné termíny', function () {
    [$salon, $staff] = salonWithStaff();

    expect(freeTimes($salon, [], $staff))->toBe([]);
});
