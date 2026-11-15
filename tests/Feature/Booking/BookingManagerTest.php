<?php

use App\Enums\BookingStatus;
use App\Enums\SegmentType;
use App\Events\BookingCancelled;
use App\Events\BookingCreated;
use App\Events\BookingMoved;
use App\Exceptions\BookingActionException;
use App\Exceptions\SlotUnavailableException;
use App\Models\Booking;
use App\Models\BookingSegment;
use App\Models\Salon;
use App\Services\Availability\SlotFinder;
use App\Services\Booking\BookingManager;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->travelTo(TEST_NOW);
    $this->manager = app(BookingManager::class);
    $this->finder = app(SlotFinder::class);
});

test('rezervace uloží snapshot služeb a úseky v kalendáři', function () {
    [$salon, $staff] = salonWithStaff();
    $color = coloring($salon, ['buffer_after' => 10]);
    $blowdry = service($salon, ['name' => 'Foukaná', 'work_1' => 30, 'price_from' => 350]);

    $booking = $this->manager->create(customer($salon), [$color, $blowdry], at('09:00'), $staff);

    expect($booking->status)->toBe(BookingStatus::Confirmed)
        ->and($booking->ends_at->format('H:i'))->toBe('11:40')
        ->and($booking->total_price)->toBe(1750)
        ->and($booking->items->pluck('name')->all())->toBe(['Barvení a střih', 'Foukaná'])
        ->and($booking->priceLabel())->toStartWith('od ')
        ->and($booking->segments->map(fn (BookingSegment $s) => $s->type->value.' '.$s->starts_at->format('H:i').'-'.$s->ends_at->format('H:i'))->all())
        ->toBe(['work 09:00-09:30', 'pause 09:30-10:10', 'work 10:10-11:40', 'buffer 11:40-11:50']);
});

test('změna ceníku nemění historii', function () {
    [$salon, $staff] = salonWithStaff();
    $cut = service($salon);

    $booking = $this->manager->create(customer($salon), [$cut], at('10:00'), $staff);
    $cut->update(['name' => 'Střih deluxe', 'price_from' => 900, 'work_1' => 90]);

    $booking->refresh();

    expect($booking->total_price)->toBe(550)
        ->and($booking->items[0]->name)->toBe('Dámský střih')
        ->and($booking->items[0]->work_1)->toBe(45);
});

test('vlastní cena a délka kadeřnice se propíše do rezervace', function () {
    [$salon, $staff] = salonWithStaff();
    $cut = service($salon);
    $staff->services()->updateExistingPivot($cut->id, ['work_1' => 60, 'price_from' => 700]);

    $booking = $this->manager->create(customer($salon), [$cut], at('10:00'), $staff->fresh());

    expect($booking->total_price)->toBe(700)
        ->and($booking->ends_at->format('H:i'))->toBe('11:00');
});

describe('souběžná rezervace', function () {
    test('stejný termín dostane jen první, druhá zákaznice uvidí chybu', function () {
        [$salon, $staff] = salonWithStaff();
        $cut = service($salon);

        // Obě zákaznice vidí 10:00 jako volné…
        expect(times($this->finder->forDay($salon, [$cut], at('00:00'), $staff)))->toContain('10:00');

        // …ale uložení dostupnost znovu ověří.
        $this->manager->create(customer($salon), [$cut], at('10:00'), $staff);

        expect(fn () => $this->manager->create(customer($salon), [$cut], at('10:00'), $staff))
            ->toThrow(SlotUnavailableException::class)
            ->and(fn () => $this->manager->create(customer($salon), [$cut], at('10:30'), $staff))
            ->toThrow(SlotUnavailableException::class)
            ->and(Booking::count())->toBe(1);

        $this->manager->create(customer($salon), [$cut], at('10:45'), $staff);

        expect(Booking::count())->toBe(2);
    });

    test('u „Kdokoliv“ se termín rozdá mezi kadeřnice a pak už není', function () {
        $salon = Salon::factory()->create();
        $petra = staffMember($salon);
        $lucie = staffMember($salon);
        $cut = service($salon);

        $first = $this->manager->create(customer($salon), [$cut], at('10:00'));
        $second = $this->manager->create(customer($salon), [$cut], at('10:00'));

        expect([$first->staff_id, $second->staff_id])->toEqualCanonicalizing([$petra->id, $lucie->id])
            ->and(fn () => $this->manager->create(customer($salon), [$cut], at('10:00')))
            ->toThrow(SlotUnavailableException::class);
    });

    test('čekající rezervace drží místo stejně jako potvrzená', function () {
        [$salon, $staff] = salonWithStaff();
        $cut = service($salon);

        $this->manager->create(customer($salon), [$cut], at('10:00'), $staff, BookingStatus::Pending);

        expect(times($this->finder->forDay($salon, [$cut], at('00:00'), $staff)))->not->toContain('10:00');
    });

    test('mimo pracovní dobu ani v minulosti rezervaci vytvořit nejde', function () {
        [$salon, $staff] = salonWithStaff();
        $cut = service($salon);

        expect(fn () => $this->manager->create(customer($salon), [$cut], at('12:15'), $staff))
            ->toThrow(SlotUnavailableException::class)
            ->and(fn () => $this->manager->create(customer($salon), [$cut], at('09:00', '2026-11-06'), $staff))
            ->toThrow(SlotUnavailableException::class);
    });
});

describe('zrušení', function () {
    test('zrušením se termín uvolní', function () {
        [$salon, $staff] = salonWithStaff();
        $cut = service($salon);
        $booking = $this->manager->create(customer($salon), [$cut], at('10:00'), $staff);

        $this->manager->cancelByStaff($booking, 'Nemoc');

        expect($booking->fresh()->status)->toBe(BookingStatus::CancelledByStaff)
            ->and($booking->fresh()->cancel_reason)->toBe('Nemoc')
            ->and(BookingSegment::count())->toBe(0)
            ->and(times($this->finder->forDay($salon, [$cut], at('00:00'), $staff)))->toContain('10:00');
    });

    test('zákaznice smí zrušit nejpozději 24 hodin předem', function () {
        [$salon, $staff] = salonWithStaff();
        $cut = service($salon);
        $booking = $this->manager->create(customer($salon), [$cut], at('09:00', '2026-11-10'), $staff);

        expect($booking->canBeCancelledByCustomer())->toBeTrue(); // zbývá 25 h

        $this->travelTo('2026-11-09 10:00:00'); // zbývá 23 h

        expect($booking->fresh()->canBeCancelledByCustomer())->toBeFalse()
            ->and(fn () => $this->manager->cancelByCustomer($booking->fresh()))->toThrow(BookingActionException::class)
            ->and($booking->fresh()->status)->toBe(BookingStatus::Confirmed);
    });

    test('zrušení zákaznicí ve lhůtě projde a vyvolá událost', function () {
        Event::fake([BookingCancelled::class]);
        [$salon, $staff] = salonWithStaff();
        $booking = $this->manager->create(customer($salon), [service($salon)], at('10:00'), $staff);

        $this->manager->cancelByCustomer($booking);

        expect($booking->fresh()->status)->toBe(BookingStatus::CancelledByCustomer);
        Event::assertDispatched(BookingCancelled::class, fn ($e) => $e->byCustomer && $e->booking->is($booking));
    });
});

describe('schválení', function () {
    test('čekající rezervaci lze schválit', function () {
        [$salon, $staff] = salonWithStaff();
        $booking = $this->manager->create(customer($salon), [service($salon)], at('10:00'), $staff, BookingStatus::Pending);

        $this->manager->approve($booking);

        expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed)
            ->and(fn () => $this->manager->approve($booking->fresh()))->toThrow(BookingActionException::class);
    });

    test('zamítnutí uvolní termín', function () {
        [$salon, $staff] = salonWithStaff();
        $cut = service($salon);
        $booking = $this->manager->create(customer($salon), [$cut], at('10:00'), $staff, BookingStatus::Pending);

        $this->manager->reject($booking, 'Plno');

        expect($booking->fresh()->status)->toBe(BookingStatus::CancelledByStaff)
            ->and(times($this->finder->forDay($salon, [$cut], at('00:00'), $staff)))->toContain('10:00');
    });
});

describe('přesun termínu', function () {
    test('kadeřnice přesune rezervaci i přes její vlastní původní čas', function () {
        Event::fake([BookingMoved::class]);
        [$salon, $staff] = salonWithStaff();
        $booking = $this->manager->create(customer($salon), [service($salon)], at('10:00'), $staff);

        $this->manager->move($booking, at('10:15'));

        expect($booking->fresh()->starts_at->format('H:i'))->toBe('10:15')
            ->and($booking->fresh()->ends_at->format('H:i'))->toBe('11:00')
            ->and($booking->segments()->first()->starts_at->format('H:i'))->toBe('10:15');

        Event::assertDispatched(BookingMoved::class, fn ($e) => ! $e->byCustomer && $e->previousStart->format('H:i') === '10:00');
    });

    test('přesun na obsazený čas neprojde a nic nezmění', function () {
        [$salon, $staff] = salonWithStaff();
        $cut = service($salon);
        $booking = $this->manager->create(customer($salon), [$cut], at('10:00'), $staff);
        $this->manager->create(customer($salon), [$cut], at('13:00'), $staff);

        expect(fn () => $this->manager->move($booking, at('13:15')))->toThrow(SlotUnavailableException::class)
            ->and($booking->fresh()->starts_at->format('H:i'))->toBe('10:00');
    });

    test('přesun počítá s délkou ze snapshotu, ne z aktuálního ceníku', function () {
        [$salon, $staff] = salonWithStaff();
        $cut = service($salon);
        $booking = $this->manager->create(customer($salon), [$cut], at('10:00'), $staff);
        $cut->update(['work_1' => 120]);

        $this->manager->move($booking->fresh(), at('16:15')); // 45 min se do 17:00 vejde

        expect($booking->fresh()->ends_at->format('H:i'))->toBe('17:00');
    });

    test('zákaznice přeplánuje v jednom kroku: starý termín se zruší, nový vznikne', function () {
        Event::fake([BookingMoved::class, BookingCreated::class, BookingCancelled::class]);
        [$salon, $staff] = salonWithStaff();
        $cut = service($salon);
        $jana = customer($salon);
        $old = $this->manager->create($jana, [$cut], at('10:00'), $staff);

        // Nový čas se překrývá s původním – vlastní starý termín nesmí překážet.
        $new = $this->manager->create($jana, [$cut], at('10:30'), $staff, replaces: $old);

        expect($old->fresh()->status)->toBe(BookingStatus::CancelledByCustomer)
            ->and($new->status)->toBe(BookingStatus::Confirmed)
            ->and(BookingSegment::where('type', SegmentType::Work)->count())->toBe(1);

        Event::assertDispatched(BookingMoved::class, fn ($e) => $e->byCustomer && $e->booking->is($new));
        Event::assertNotDispatched(BookingCancelled::class);
    });

    test('když nový termín není volný, původní zůstane', function () {
        [$salon, $staff] = salonWithStaff();
        $cut = service($salon);
        $jana = customer($salon);
        $old = $this->manager->create($jana, [$cut], at('10:00'), $staff);
        $this->manager->create(customer($salon), [$cut], at('13:00'), $staff);

        expect(fn () => $this->manager->create($jana, [$cut], at('13:00'), $staff, replaces: $old))
            ->toThrow(SlotUnavailableException::class)
            ->and($old->fresh()->status)->toBe(BookingStatus::Confirmed)
            ->and($old->segments()->count())->toBe(1);
    });
});
