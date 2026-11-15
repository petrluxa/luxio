<?php

namespace App\Services\Availability;

use Carbon\CarbonImmutable;

/**
 * Polouzavřený časový úsek <start, end). Dva úseky, které na sebe jen navazují
 * (jeden končí v 10:00 a druhý v 10:00 začíná), se nepřekrývají.
 */
final class TimeRange
{
    public function __construct(
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
    ) {}

    public function overlaps(self $other): bool
    {
        return $this->start < $other->end && $other->start < $this->end;
    }

    public function contains(self $other): bool
    {
        return $this->start <= $other->start && $other->end <= $this->end;
    }

    public function isEmpty(): bool
    {
        return $this->end <= $this->start;
    }

    public function minutes(): int
    {
        return (int) $this->start->diffInMinutes($this->end);
    }

    /**
     * Odečte jiný úsek; výsledkem je 0–2 zbylých úseků.
     *
     * @return list<self>
     */
    public function subtract(self $other): array
    {
        if (! $this->overlaps($other)) {
            return [$this];
        }

        $rest = [];

        if ($this->start < $other->start) {
            $rest[] = new self($this->start, $other->start);
        }

        if ($other->end < $this->end) {
            $rest[] = new self($other->end, $this->end);
        }

        return $rest;
    }

    /**
     * Seřadí úseky a spojí ty, které se překrývají nebo na sebe přímo navazují.
     *
     * @param  iterable<self>  $ranges
     * @return list<self>
     */
    public static function merge(iterable $ranges): array
    {
        $sorted = [];

        foreach ($ranges as $range) {
            if (! $range->isEmpty()) {
                $sorted[] = $range;
            }
        }

        usort($sorted, fn (self $a, self $b) => $a->start <=> $b->start);

        $merged = [];

        foreach ($sorted as $range) {
            $last = end($merged);

            if ($last !== false && $range->start <= $last->end) {
                if ($range->end > $last->end) {
                    $merged[count($merged) - 1] = new self($last->start, $range->end);
                }

                continue;
            }

            $merged[] = $range;
        }

        return $merged;
    }
}
