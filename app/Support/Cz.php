<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * České formáty pro celé UI: datum „pá 21. 11.“, čas „10:30“, měna „1 400 Kč“.
 */
final class Cz
{
    private const NBSP = "\u{00A0}";

    private const DAYS_SHORT = [1 => 'po', 'út', 'st', 'čt', 'pá', 'so', 'ne'];

    private const DAYS_LONG = [1 => 'pondělí', 'úterý', 'středa', 'čtvrtek', 'pátek', 'sobota', 'neděle'];

    private const MONTHS_GENITIVE = [1 => 'ledna', 'února', 'března', 'dubna', 'května', 'června',
        'července', 'srpna', 'září', 'října', 'listopadu', 'prosince'];

    private const MONTHS = [1 => 'leden', 'únor', 'březen', 'duben', 'květen', 'červen',
        'červenec', 'srpen', 'září', 'říjen', 'listopad', 'prosinec'];

    /** pá 21. 11. */
    public static function date(CarbonInterface $d): string
    {
        return self::dayShort($d).' '.$d->day.'.'.self::NBSP.$d->month.'.';
    }

    /** pátek 21. listopadu */
    public static function dateLong(CarbonInterface $d): string
    {
        return self::DAYS_LONG[$d->dayOfWeekIso].' '.$d->day.'.'.self::NBSP.self::MONTHS_GENITIVE[$d->month];
    }

    /** 21. 11. 2026 */
    public static function dateFull(CarbonInterface $d): string
    {
        return $d->day.'.'.self::NBSP.$d->month.'.'.self::NBSP.$d->year;
    }

    /** listopad 2026 */
    public static function monthYear(CarbonInterface $d): string
    {
        return self::MONTHS[$d->month].' '.$d->year;
    }

    /** pá */
    public static function dayShort(CarbonInterface $d): string
    {
        return self::DAYS_SHORT[$d->dayOfWeekIso];
    }

    public static function weekdayName(int $isoWeekday): string
    {
        return self::DAYS_LONG[$isoWeekday];
    }

    /** 10:30 (bez úvodní nuly u hodin) */
    public static function time(CarbonInterface $d): string
    {
        return $d->format('G:i');
    }

    /** pá 21. 11. v 10:30 */
    public static function dateTime(CarbonInterface $d): string
    {
        return self::date($d).' v'.self::NBSP.self::time($d);
    }

    /** 1 400 Kč */
    public static function money(int $amount): string
    {
        return number_format($amount, 0, ',', self::NBSP).self::NBSP.'Kč';
    }

    /** 550 Kč, nebo „od 1 400 Kč“, když má služba rozpětí ceny. */
    public static function price(int $from, ?int $to = null): string
    {
        return ($to !== null && $to > $from ? 'od ' : '').self::money($from);
    }

    /** 45 min · 2 h · 2 h 10 min */
    public static function duration(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        if ($h === 0) {
            return $m.self::NBSP.'min';
        }

        return $h.self::NBSP.'h'.($m > 0 ? ' '.$m.self::NBSP.'min' : '');
    }

    /**
     * 2. pád křestního jména pro spojení „u Petry“. Jen jednoslovná jména končící
     * na -a (Petra → Petry, Dáša → Dáši, Maria → Marie); ostatní se nemění (Lucie, Ester).
     */
    public static function genitive(string $name): string
    {
        $name = trim($name);

        if ($name === '' || str_contains($name, ' ') || mb_substr($name, -1) !== 'a') {
            return $name;
        }

        $stem = mb_substr($name, 0, -1);
        $last = mb_strtolower(mb_substr($stem, -1));

        if ($last === 'i') {
            return $stem.'e';                                   // Maria → Marie
        }

        $softened = ['ň' => 'n', 'ď' => 'd', 'ť' => 't'];

        if (isset($softened[$last])) {
            return mb_substr($stem, 0, -1).$softened[$last].'i';  // Táňa → Táni
        }

        return $stem.(in_array($last, ['ž', 'š', 'č', 'ř', 'c', 'j'], true) ? 'i' : 'y');
    }

    /**
     * Odhad, jestli jde o ženské jméno (kvůli tvaru „zrušila“ / „zrušil“ v upozornění):
     * příjmení na -á (Nováková, Malá), jinak křestní jméno na -a / -e.
     */
    public static function isFemaleName(string $fullName): bool
    {
        $parts = preg_split('/\s+/u', trim($fullName)) ?: [];
        $last = mb_strtolower((string) end($parts));
        $first = mb_strtolower((string) reset($parts));

        if (count($parts) > 1 && mb_substr($last, -1) === 'á') {
            return true;
        }

        return in_array(mb_substr($first, -1), ['a', 'e'], true);
    }

    /** +420 603 123 456 */
    public static function phone(?string $phone): string
    {
        if (! $phone) {
            return '';
        }

        if (preg_match('/^\+(42[01])(\d{3})(\d{3})(\d{3})$/', $phone, $m)) {
            return "+{$m[1]} {$m[2]} {$m[3]} {$m[4]}";
        }

        return $phone;
    }

    /**
     * Sjednotí zápis telefonu na +420123456789. Vrací null, když číslo nedává smysl.
     */
    public static function normalizePhone(?string $phone): ?string
    {
        $digits = preg_replace('/[^\d+]/', '', (string) $phone);

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '00')) {
            $digits = '+'.substr($digits, 2);
        }

        if (! str_starts_with($digits, '+')) {
            $digits = strlen($digits) === 9 ? '+420'.$digits : '+'.$digits;
        }

        return preg_match('/^\+\d{9,15}$/', $digits) ? $digits : null;
    }
}
