<?php

use App\Services\Availability\HolidayCalendar;
use App\Support\Cz;
use Carbon\CarbonImmutable;

test('velikonoční neděle vychází správně', function (int $year, string $expected) {
    expect(HolidayCalendar::easterSunday($year)->toDateString())->toBe($expected);
})->with([
    [2024, '2024-03-31'],
    [2025, '2025-04-20'],
    [2026, '2026-04-05'],
    [2027, '2027-03-28'],
    [2038, '2038-04-25'],
]);

test('pozná státní svátky ČR včetně Velikonoc', function (string $date, bool $holiday) {
    expect((new HolidayCalendar)->isHoliday(CarbonImmutable::parse($date)))->toBe($holiday);
})->with([
    'Nový rok' => ['2027-01-01', true],
    'Velký pátek 2026' => ['2026-04-03', true],
    'Velikonoční pondělí 2026' => ['2026-04-06', true],
    'Velikonoční neděle není svátek navíc' => ['2026-04-05', false],
    '17. listopad' => ['2026-11-17', true],
    'Štědrý den' => ['2026-12-24', true],
    'běžná středa' => ['2026-11-11', false],
]);

test('české formáty data, času, ceny a délky', function () {
    $d = CarbonImmutable::parse('2026-11-20 10:30');
    $nbsp = "\u{00A0}";

    expect(Cz::date($d))->toBe("pá 20.{$nbsp}11.")
        ->and(Cz::time($d))->toBe('10:30')
        ->and(Cz::time(CarbonImmutable::parse('2026-11-20 09:05')))->toBe('9:05')
        ->and(Cz::dateTime($d))->toBe("pá 20.{$nbsp}11. v{$nbsp}10:30")
        ->and(Cz::money(1400))->toBe("1{$nbsp}400{$nbsp}Kč")
        ->and(Cz::price(550))->toBe("550{$nbsp}Kč")
        ->and(Cz::price(1400, 2200))->toBe("od 1{$nbsp}400{$nbsp}Kč")
        ->and(Cz::duration(45))->toBe("45{$nbsp}min")
        ->and(Cz::duration(130))->toBe("2{$nbsp}h 10{$nbsp}min")
        ->and(Cz::duration(120))->toBe("2{$nbsp}h");
});

test('telefon se sjednotí na mezinárodní tvar', function (string $input, ?string $expected) {
    expect(Cz::normalizePhone($input))->toBe($expected);
})->with([
    ['603 123 456', '+420603123456'],
    ['+420 603 123 456', '+420603123456'],
    ['00420603123456', '+420603123456'],
    ['603-123-456', '+420603123456'],
    ['+421 903 123 456', '+421903123456'],
    ['123', null],
    ['', null],
]);
