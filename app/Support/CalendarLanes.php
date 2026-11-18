<?php

namespace App\Support;

use App\Enums\SegmentType;
use App\Models\Booking;

/**
 * Rozmístění rezervací ve sloupci rozvrhu, když se kryjí (kadeřnice dělá dvě zákaznice najednou).
 *
 * Rezervace, jejichž práce se překrývá, dostanou každá svůj pruh vedle sebe. Rezervace, která se
 * vejde do pauzy jiné (působí barva), zůstává ve stejném pruhu – nic se nekryje, takže má plnou šířku.
 *
 * Rozhoduje jen to, co se v rozvrhu kreslí. Úklid po zákaznici se nekreslí, takže rezervace, do které
 * zasahuje jen úklid té předchozí, zůstává pod ní na plnou šířku.
 */
final class CalendarLanes
{
    /**
     * @param  iterable<Booking>  $bookings  rezervace jednoho sloupce (s načtenými úseky)
     * @return array<int, array{lane: int, lanes: int}> [id rezervace] => pruh a počet pruhů vedle sebe
     */
    public static function assign(iterable $bookings): array
    {
        $items = [];

        foreach ($bookings as $booking) {
            $busy = [];
            $end = $booking->ends_at->getTimestamp();

            foreach ($booking->segments as $segment) {
                if ($segment->type === SegmentType::Buffer) {
                    continue;
                }

                $end = max($end, $segment->ends_at->getTimestamp());

                if ($segment->type !== SegmentType::Pause) {
                    $busy[] = [$segment->starts_at->getTimestamp(), $segment->ends_at->getTimestamp()];
                }
            }

            $items[] = [
                'id' => $booking->id,
                'from' => $booking->starts_at->getTimestamp(),
                'to' => $end,
                'busy' => $busy ?: [[$booking->starts_at->getTimestamp(), $end]],
            ];
        }

        usort($items, fn (array $a, array $b) => [$a['from'], $a['id']] <=> [$b['from'], $b['id']]);

        $result = [];
        $group = [];      // rezervace, které na sebe časově navazují překryvem
        $lanes = [];      // [pruh] => obsazené úseky
        $groupEnd = null;

        $flush = function () use (&$result, &$group, &$lanes) {
            foreach ($group as [$id, $lane]) {
                $result[$id] = ['lane' => $lane, 'lanes' => max(1, count($lanes))];
            }

            $group = [];
            $lanes = [];
        };

        foreach ($items as $item) {
            if ($group !== [] && $item['from'] >= $groupEnd) {
                $flush();
                $groupEnd = null;
            }

            $lane = 0;

            while (isset($lanes[$lane]) && self::collides($lanes[$lane], $item['busy'])) {
                $lane++;
            }

            $lanes[$lane] = array_merge($lanes[$lane] ?? [], $item['busy']);
            $group[] = [$item['id'], $lane];
            $groupEnd = max($groupEnd ?? 0, $item['to']);
        }

        $flush();

        return $result;
    }

    /**
     * @param  list<array{0: int, 1: int}>  $taken
     * @param  list<array{0: int, 1: int}>  $wanted
     */
    private static function collides(array $taken, array $wanted): bool
    {
        foreach ($taken as [$from, $to]) {
            foreach ($wanted as [$start, $end]) {
                if ($start < $to && $end > $from) {
                    return true;
                }
            }
        }

        return false;
    }
}
