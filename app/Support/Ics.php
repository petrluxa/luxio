<?php

namespace App\Support;

use App\Models\Booking;
use DateTimeZone;

/**
 * Událost do kalendáře (.ics) pro přílohu e-mailu.
 */
final class Ics
{
    public static function forBooking(Booking $booking): string
    {
        $salon = $booking->salon;
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'luxio';
        $utc = new DateTimeZone('UTC');
        $stamp = fn ($time) => $time->setTimezone($utc)->format('Ymd\THis\Z');

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Luxio//Rezervace//CS',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            "UID:rezervace-{$booking->id}@{$host}",
            'DTSTAMP:'.$stamp(now()),
            'DTSTART:'.$stamp($booking->starts_at),
            'DTEND:'.$stamp($booking->ends_at),
            'SUMMARY:'.self::escape($booking->servicesLabel().' – '.$salon->name),
            'DESCRIPTION:'.self::escape('Kadeřnice: '.$booking->staff->name.($salon->phone ? "\nTelefon: ".Cz::phone($salon->phone) : '')),
        ];

        if ($salon->address) {
            $lines[] = 'LOCATION:'.self::escape($salon->name.', '.$salon->address);
        }

        array_push($lines, 'STATUS:CONFIRMED', 'END:VEVENT', 'END:VCALENDAR');

        return implode("\r\n", array_map(self::fold(...), $lines))."\r\n";
    }

    private static function escape(string $text): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\,', '\n', '\n'], $text);
    }

    /** Řádky delší než 75 oktetů se podle RFC 5545 zalamují s mezerou na začátku pokračování. */
    private static function fold(string $line): string
    {
        $out = '';
        $length = 0;

        foreach (mb_str_split($line) as $char) {
            $bytes = strlen($char);

            if ($length + $bytes > 74) {
                $out .= "\r\n ";
                $length = 1;
            }

            $out .= $char;
            $length += $bytes;
        }

        return $out;
    }
}
