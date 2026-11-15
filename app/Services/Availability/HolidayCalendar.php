<?php

namespace App\Services\Availability;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Státní svátky ČR (zákon č. 245/2000 Sb.) – pevná data + Velký pátek
 * a Velikonoční pondělí. Ve svátek je salon automaticky zavřený,
 * pokud kadeřnice nemá na ten den výjimku.
 */
final class HolidayCalendar
{
    private const FIXED = [
        '01-01' => 'Nový rok',
        '05-01' => 'Svátek práce',
        '05-08' => 'Den vítězství',
        '07-05' => 'Den slovanských věrozvěstů Cyrila a Metoděje',
        '07-06' => 'Den upálení mistra Jana Husa',
        '09-28' => 'Den české státnosti',
        '10-28' => 'Den vzniku samostatného československého státu',
        '11-17' => 'Den boje za svobodu a demokracii',
        '12-24' => 'Štědrý den',
        '12-25' => '1. svátek vánoční',
        '12-26' => '2. svátek vánoční',
    ];

    /** @var array<int, array<string, string>> */
    private array $easterCache = [];

    public function isHoliday(CarbonInterface $date): bool
    {
        return $this->name($date) !== null;
    }

    public function name(CarbonInterface $date): ?string
    {
        return self::FIXED[$date->format('m-d')] ?? $this->easterHolidays($date->year)[$date->format('m-d')] ?? null;
    }

    /**
     * @return array<string, string> klíč `m-d`
     */
    private function easterHolidays(int $year): array
    {
        return $this->easterCache[$year] ??= (function () use ($year) {
            $sunday = self::easterSunday($year);

            return [
                $sunday->subDays(2)->format('m-d') => 'Velký pátek',
                $sunday->addDay()->format('m-d') => 'Velikonoční pondělí',
            ];
        })();
    }

    /** Velikonoční neděle – gregoriánský (Meeusův) algoritmus, bez závislosti na ext-calendar. */
    public static function easterSunday(int $year): CarbonImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::create($year, $month, $day, 0, 0, 0);
    }
}
