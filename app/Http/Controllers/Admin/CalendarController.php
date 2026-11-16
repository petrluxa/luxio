<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use App\Services\Availability\HolidayCalendar;
use App\Services\Availability\TimeRange;
use App\Services\Availability\WorkingTime;
use App\Support\CalendarLanes;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Rozvrh: den se sloupcem pro každou kadeřnici (přehled pro správce, recepci i kadeřnice),
 * den jedné kadeřnice, nebo její týden. Vlevo měsíční kalendář pro skok na jiný den.
 * Ťuknutí na volné místo vede na novou rezervaci v daném čase, ťuknutí na rezervaci
 * na její detail; rezervaci jde myší nebo prstem přetáhnout na jiný čas či ke kolegyni.
 */
class CalendarController extends AdminController
{
    private const DEFAULT_FROM = 8;

    private const DEFAULT_TO = 18;

    public function __invoke(Request $request, HolidayCalendar $holidays): View
    {
        $staffList = $this->staffList();
        $view = $request->query('zobrazeni') === 'tyden' ? 'tyden' : 'den';
        $date = $this->date($request->query('datum'));

        // Den ukazuje všechny kadeřnice vedle sebe, dokud si přihlášený nevybere jednu.
        // Týden je vždy jedné kadeřnice.
        $picked = $staffList->firstWhere('id', $request->integer('kadernice'));
        $staff = $picked ?? $this->currentStaff($request);
        $all = $view === 'den' && ! $picked && $staffList->count() > 1;

        $from = $view === 'tyden' ? $date->startOfWeek() : $date;
        $to = $view === 'tyden' ? $from->addDays(6) : $date;
        $members = $all ? $staffList : new EloquentCollection([$staff]);

        $bookings = Booking::query()
            ->where('salon_id', $this->salon()->id)
            ->whereIn('staff_id', $members->modelKeys())
            ->whereIn('status', BookingStatus::occupying())
            ->whereBetween('starts_at', [$from->startOfDay(), $to->endOfDay()])
            ->with('customer', 'items', 'segments')
            ->orderBy('starts_at')
            ->get()
            ->groupBy(fn (Booking $b) => $b->staff_id.'|'.$b->starts_at->toDateString());

        $workingTime = WorkingTime::load($holidays, $members->modelKeys(), $from, $to);
        $columns = [];

        foreach ($members as $member) {
            for ($day = $from; $day <= $to; $day = $day->addDay()) {
                $own = $bookings[$member->id.'|'.$day->toDateString()] ?? collect();

                $columns[] = [
                    'staff' => $member,
                    'date' => $day,
                    'open' => $workingTime->openIntervals($member->id, $day),
                    'holiday' => $workingTime->closedForHoliday($member->id, $day),
                    'bookings' => $own,
                    'lanes' => CalendarLanes::assign($own),
                ];
            }
        }

        [$hourFrom, $hourTo] = $this->hourRange($columns);

        return view('admin.calendar', [
            'tab' => 'calendar',
            'width' => 'full',
            'staff' => $staff,
            'staffList' => $staffList,
            'all' => $all,
            'view' => $view,
            'date' => $date,
            'columns' => $columns,
            'holiday' => $view === 'den' ? $holidays->name($date) : null,
            'hourFrom' => $hourFrom,
            'hourTo' => $hourTo,
            'step' => $view === 'tyden' ? $date->addWeek() : $date->addDay(),
            'back' => $view === 'tyden' ? $date->subWeek() : $date->subDay(),
            'mini' => $this->miniCalendar($request, $date, $members->modelKeys()),
            'slotStep' => $this->salon()->rules()->slotStep,
        ]);
    }

    /**
     * Měsíční kalendář vedle rozvrhu: týdny po–ne s počtem rezervací v jednotlivých dnech.
     * Měsíc jde listovat (?mesic=2026-12), aniž se změní zobrazený den.
     *
     * @param  list<int>  $staffIds
     * @return array{month: CarbonImmutable, prev: string, next: string, weeks: list<list<array{date: CarbonImmutable, inMonth: bool, count: int}>>}
     */
    private function miniCalendar(Request $request, CarbonImmutable $date, array $staffIds): array
    {
        $month = $date->startOfMonth();
        $wanted = (string) $request->query('mesic');

        if (preg_match('/^\d{4}-\d{2}$/', $wanted)) {
            try {
                $month = CarbonImmutable::createFromFormat('!Y-m', $wanted);
            } catch (\Throwable) {
                // neplatný měsíc v adrese → měsíc zobrazeného dne
            }
        }

        $first = $month->startOfWeek();
        $last = $month->endOfMonth()->endOfWeek()->startOfDay();

        $counts = Booking::query()
            ->where('salon_id', $this->salon()->id)
            ->whereIn('staff_id', $staffIds)
            ->whereIn('status', BookingStatus::occupying())
            ->whereBetween('starts_at', [$first, $last->endOfDay()])
            ->get(['starts_at'])
            ->countBy(fn (Booking $b) => $b->starts_at->toDateString());

        $weeks = [];

        for ($day = $first; $day <= $last; $day = $day->addDay()) {
            $weeks[intdiv((int) $first->diffInDays($day), 7)][] = [
                'date' => $day,
                'inMonth' => $day->month === $month->month,
                'count' => (int) ($counts[$day->toDateString()] ?? 0),
            ];
        }

        return [
            'month' => $month,
            'prev' => $month->subMonth()->format('Y-m'),
            'next' => $month->addMonth()->format('Y-m'),
            'weeks' => array_values($weeks),
        ];
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
     * @param  list<array{open: list<TimeRange>, bookings: Collection<int, Booking>, staff: User}>  $columns
     * @return array{0: int, 1: int}
     */
    private function hourRange(array $columns): array
    {
        $from = null;
        $to = null;

        foreach ($columns as $column) {
            foreach ($column['open'] as $interval) {
                $from = min($from ?? 24, $interval->start->hour);
                $to = max($to ?? 0, $interval->end->hour + ($interval->end->minute > 0 ? 1 : 0));
            }

            foreach ($column['bookings'] as $booking) {
                $end = $booking->segments->max('ends_at') ?? $booking->ends_at;
                $from = min($from ?? 24, $booking->starts_at->hour);
                // Rezervace přes půlnoc se kreslí do konce dne.
                $to = max($to ?? 0, $end->isSameDay($booking->starts_at) ? $end->hour + ($end->minute > 0 ? 1 : 0) : 24);
            }
        }

        // Hodina navíc na obou koncích: je kam přetáhnout rezervaci před otevřením nebo po zavření.
        $from = max(0, ($from ?? self::DEFAULT_FROM) - 1);
        $to = min(24, max($to ?? self::DEFAULT_TO, $from + 2) + 1);

        return [$from, $to];
    }
}
