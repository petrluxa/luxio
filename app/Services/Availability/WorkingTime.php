<?php

namespace App\Services\Availability;

use App\Models\TimeOff;
use App\Models\WorkingHour;
use App\Models\WorkingHourOverride;
use Carbon\CarbonImmutable;

/**
 * Kdy má kadeřnice otevřeno: běžná pracovní doba podle dne v týdnu, výjimky
 * pro konkrétní den, státní svátky a volno. Data pro celé období se načtou
 * jedním průchodem, aby výpočet na 14 dní nedělal dotaz na každý den.
 */
final class WorkingTime
{
    /**
     * @param  array<int, array<int, list<array{0: string, 1: string}>>>  $weekly  [staffId][weekday] => [[od, do], …]
     * @param  array<int, array<string, list<array{0: string, 1: string}>>>  $overrides  [staffId][Y-m-d] => [[od, do], …]
     * @param  array<int, list<TimeRange>>  $timeOffs  [staffId] => úseky volna
     */
    private function __construct(
        private readonly HolidayCalendar $holidays,
        private readonly array $weekly,
        private readonly array $overrides,
        private readonly array $timeOffs,
    ) {}

    /**
     * @param  list<int>  $staffIds
     */
    public static function load(HolidayCalendar $holidays, array $staffIds, CarbonImmutable $from, CarbonImmutable $to): self
    {
        $weekly = [];
        $overrides = [];
        $timeOffs = [];

        foreach (WorkingHour::whereIn('user_id', $staffIds)->orderBy('starts_at')->get() as $row) {
            $weekly[$row->user_id][$row->weekday][] = [$row->starts_at, $row->ends_at];
        }

        $overrideRows = WorkingHourOverride::whereIn('user_id', $staffIds)
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->orderBy('starts_at')
            ->get();

        foreach ($overrideRows as $row) {
            $overrides[$row->user_id][$row->date->toDateString()][] = [$row->starts_at, $row->ends_at];
        }

        $timeOffRows = TimeOff::whereIn('user_id', $staffIds)
            ->where('starts_at', '<', $to->endOfDay())
            ->where('ends_at', '>', $from->startOfDay())
            ->get();

        foreach ($timeOffRows as $row) {
            $timeOffs[$row->user_id][] = new TimeRange($row->starts_at, $row->ends_at);
        }

        return new self($holidays, $weekly, $overrides, $timeOffs);
    }

    /**
     * Souvislé úseky, kdy kadeřnice daný den pracuje (po odečtení volna).
     * Prázdné pole = zavřeno.
     *
     * @return list<TimeRange>
     */
    public function openIntervals(int $staffId, CarbonImmutable $date): array
    {
        $day = $date->startOfDay();
        $key = $day->toDateString();

        if (isset($this->overrides[$staffId][$key])) {
            $hours = $this->overrides[$staffId][$key];
        } elseif ($this->holidays->isHoliday($day)) {
            return [];
        } else {
            $hours = $this->weekly[$staffId][$day->dayOfWeekIso] ?? [];
        }

        $intervals = [];

        foreach ($hours as [$startsAt, $endsAt]) {
            $intervals[] = new TimeRange($day->setTimeFromTimeString($startsAt), $day->setTimeFromTimeString($endsAt));
        }

        $intervals = TimeRange::merge($intervals);

        foreach ($this->timeOffs[$staffId] ?? [] as $timeOff) {
            $rest = [];

            foreach ($intervals as $interval) {
                array_push($rest, ...$interval->subtract($timeOff));
            }

            $intervals = $rest;
        }

        return TimeRange::merge($intervals);
    }

    /** Je daný den u kadeřnice svátek bez výjimky? (pro popisek v kalendáři) */
    public function closedForHoliday(int $staffId, CarbonImmutable $date): ?string
    {
        if (isset($this->overrides[$staffId][$date->toDateString()])) {
            return null;
        }

        return $this->holidays->name($date);
    }
}
