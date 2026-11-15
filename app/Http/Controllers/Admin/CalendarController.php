<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use App\Services\Availability\HolidayCalendar;
use App\Services\Availability\TimeRange;
use App\Services\Availability\WorkingTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Kalendář: den nebo týden jedné kadeřnice. Ťuknutí na volné místo vede
 * na novou rezervaci v daném čase, ťuknutí na rezervaci na její detail.
 */
class CalendarController extends AdminController
{
    private const DEFAULT_FROM = 8;

    private const DEFAULT_TO = 18;

    public function __invoke(Request $request, HolidayCalendar $holidays): View
    {
        $staff = $this->currentStaff($request);
        $view = $request->query('zobrazeni') === 'tyden' ? 'tyden' : 'den';
        $date = $this->date($request->query('datum'));

        $from = $view === 'tyden' ? $date->startOfWeek() : $date;
        $to = $view === 'tyden' ? $from->addDays(6) : $date;

        $bookings = Booking::query()
            ->where('staff_id', $staff->id)
            ->whereIn('status', BookingStatus::occupying())
            ->whereBetween('starts_at', [$from->startOfDay(), $to->endOfDay()])
            ->with('customer', 'items', 'segments')
            ->orderBy('starts_at')
            ->get()
            ->groupBy(fn (Booking $b) => $b->starts_at->toDateString());

        $workingTime = WorkingTime::load($holidays, [$staff->id], $from, $to);
        $days = [];

        for ($day = $from; $day <= $to; $day = $day->addDay()) {
            $days[] = [
                'date' => $day,
                'open' => $workingTime->openIntervals($staff->id, $day),
                'holiday' => $workingTime->closedForHoliday($staff->id, $day),
                'bookings' => $bookings[$day->toDateString()] ?? collect(),
            ];
        }

        [$hourFrom, $hourTo] = $this->hourRange($days);

        return view('admin.calendar', [
            'tab' => 'calendar',
            'staff' => $staff,
            'staffList' => $this->staffList(),
            'view' => $view,
            'date' => $date,
            'days' => $days,
            'hourFrom' => $hourFrom,
            'hourTo' => $hourTo,
            'step' => $view === 'tyden' ? $date->addWeek() : $date->addDay(),
            'back' => $view === 'tyden' ? $date->subWeek() : $date->subDay(),
        ]);
    }

    private function date(?string $value): CarbonImmutable
    {
        if ($value && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            try {
                return CarbonImmutable::createFromFormat('!Y-m-d', $value);
            } catch (\Throwable) {
                // neplatné datum v adrese → dnešek
            }
        }

        return CarbonImmutable::today();
    }

    /**
     * Rozsah hodin na ose: od první do poslední pracovní hodiny nebo rezervace.
     *
     * @param  list<array{open: list<TimeRange>, bookings: Collection<int, Booking>}>  $days
     * @return array{0: int, 1: int}
     */
    private function hourRange(array $days): array
    {
        $from = null;
        $to = null;

        foreach ($days as $day) {
            foreach ($day['open'] as $interval) {
                $from = min($from ?? 24, $interval->start->hour);
                $to = max($to ?? 0, $interval->end->hour + ($interval->end->minute > 0 ? 1 : 0));
            }

            foreach ($day['bookings'] as $booking) {
                $end = $booking->segments->max('ends_at') ?? $booking->ends_at;
                $from = min($from ?? 24, $booking->starts_at->hour);
                $to = max($to ?? 0, $end->hour + ($end->minute > 0 ? 1 : 0));
            }
        }

        return [$from ?? self::DEFAULT_FROM, max($to ?? self::DEFAULT_TO, ($from ?? self::DEFAULT_FROM) + 1)];
    }
}
