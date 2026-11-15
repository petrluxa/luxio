<?php

use App\Enums\SegmentType;
use App\Services\Availability\EffectiveService;
use App\Services\Availability\ServicePlan;
use App\Services\Availability\TimeRange;
use Carbon\CarbonImmutable;

function effective(int $work1, int $pause = 0, int $work2 = 0, int $buffer = 0, int $price = 500): EffectiveService
{
    return new EffectiveService(1, 'Služba', $price, null, $work1, $pause, $work2, $buffer);
}

/** @return list<string> např. ['work 09:00-09:30', 'pause 09:30-10:10'] */
function describeSegments(ServicePlan $plan, string $start = '2026-11-11 09:00'): array
{
    return array_map(
        fn (array $s) => $s['type']->value.' '.$s['range']->start->format('H:i').'-'.$s['range']->end->format('H:i'),
        $plan->segmentsAt(CarbonImmutable::parse($start)),
    );
}

test('jednoduchá služba je jeden pracovní úsek', function () {
    $plan = ServicePlan::fromEffective([effective(45)]);

    expect($plan->serviceMinutes())->toBe(45)
        ->and(describeSegments($plan))->toBe(['work 09:00-09:45']);
});

test('barvení se rozloží na práci, pauzu a práci', function () {
    $plan = ServicePlan::fromEffective([effective(30, 40, 60)]);

    expect($plan->serviceMinutes())->toBe(130)
        ->and(describeSegments($plan))->toBe(['work 09:00-09:30', 'pause 09:30-10:10', 'work 10:10-11:10']);
});

test('úklid se přidá jako samostatný úsek za konec služby', function () {
    $plan = ServicePlan::fromEffective([effective(45, buffer: 10)]);

    expect($plan->serviceMinutes())->toBe(45)
        ->and($plan->bufferMinutes)->toBe(10)
        ->and(describeSegments($plan))->toBe(['work 09:00-09:45', 'buffer 09:45-09:55']);
});

test('víc služeb jde za sebou a sousední práce se slijí', function () {
    // střih 45 + foukaná 30 → jeden pracovní úsek 75 min
    $plan = ServicePlan::fromEffective([effective(45), effective(30)]);

    expect($plan->serviceMinutes())->toBe(75)
        ->and(describeSegments($plan))->toBe(['work 09:00-10:15']);
});

test('barvení a foukaná: druhá práce barvení se spojí s foukanou', function () {
    $plan = ServicePlan::fromEffective([effective(30, 40, 60), effective(30)]);

    expect($plan->serviceMinutes())->toBe(160)
        ->and(describeSegments($plan))->toBe(['work 09:00-09:30', 'pause 09:30-10:10', 'work 10:10-11:40']);
});

test('u víc služeb se uklízí jednou – podle nejdelšího úklidu', function () {
    $plan = ServicePlan::fromEffective([effective(45, buffer: 5), effective(30, buffer: 15)]);

    expect($plan->bufferMinutes)->toBe(15)
        ->and(describeSegments($plan))->toBe(['work 09:00-10:15', 'buffer 10:15-10:30']);
});

test('blokují jen práce a úklid, pauza ne', function () {
    $plan = ServicePlan::fromEffective([effective(30, 40, 60, buffer: 10)]);

    $blocking = array_map(
        fn (TimeRange $r) => $r->start->format('H:i').'-'.$r->end->format('H:i'),
        $plan->blockingRangesAt(CarbonImmutable::parse('2026-11-11 09:00')),
    );

    expect($blocking)->toBe(['09:00-09:30', '10:10-11:10', '11:10-11:20'])
        ->and(SegmentType::Pause->blocks())->toBeFalse();
});

test('cena je součet cen služeb', function () {
    $plan = ServicePlan::fromEffective([effective(45, price: 550), effective(30, price: 350)]);

    expect($plan->totalPrice())->toBe(900);
});

test('úseky, které na sebe jen navazují, se nepřekrývají', function () {
    $a = new TimeRange(CarbonImmutable::parse('2026-11-11 09:00'), CarbonImmutable::parse('2026-11-11 10:00'));
    $b = new TimeRange(CarbonImmutable::parse('2026-11-11 10:00'), CarbonImmutable::parse('2026-11-11 11:00'));
    $c = new TimeRange(CarbonImmutable::parse('2026-11-11 09:59'), CarbonImmutable::parse('2026-11-11 10:30'));

    expect($a->overlaps($b))->toBeFalse()
        ->and($a->overlaps($c))->toBeTrue()
        ->and(TimeRange::merge([$b, $a]))->toHaveCount(1);
});

test('odečtení volna uprostřed rozdělí úsek na dva', function () {
    $day = new TimeRange(CarbonImmutable::parse('2026-11-11 09:00'), CarbonImmutable::parse('2026-11-11 17:00'));
    $off = new TimeRange(CarbonImmutable::parse('2026-11-11 10:00'), CarbonImmutable::parse('2026-11-11 11:00'));

    $rest = $day->subtract($off);

    expect($rest)->toHaveCount(2)
        ->and($rest[0]->end->format('H:i'))->toBe('10:00')
        ->and($rest[1]->start->format('H:i'))->toBe('11:00');
});
