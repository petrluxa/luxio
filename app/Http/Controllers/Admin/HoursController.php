<?php

namespace App\Http\Controllers\Admin;

use App\Models\TimeOff;
use App\Models\WorkingHourOverride;
use App\Services\Availability\HolidayCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Pracovní doba (týdenní rozvrh: den je zavřený, nebo má až dva intervaly = polední pauza),
 * volno / blokace a výjimky pro jeden den (pracovní sobota, otevřeno ve svátek).
 */
class HoursController extends AdminController
{
    public function edit(Request $request, HolidayCalendar $holidays): View
    {
        $staff = $this->editableStaff($request);
        $week = $staff->workingHours()->get()->groupBy('weekday');

        // Nejbližší státní svátky, aby bylo vidět, kdy bude automaticky zavřeno.
        $upcomingHolidays = [];

        for ($day = CarbonImmutable::today(); count($upcomingHolidays) < 4 && $day < CarbonImmutable::today()->addYear(); $day = $day->addDay()) {
            if ($name = $holidays->name($day)) {
                $upcomingHolidays[] = ['date' => $day, 'name' => $name];
            }
        }

        return view('admin.hours', [
            'tab' => 'more',
            'staff' => $staff,
            'staffList' => $this->user()->isOwner() ? $this->staffList() : collect([$staff]),
            'week' => $week,
            'timeOffs' => $staff->timeOffs()->where('ends_at', '>=', now())->get(),
            'overrides' => $staff->workingHourOverrides()->whereDate('date', '>=', today())->get(),
            'upcomingHolidays' => $upcomingHolidays,
        ]);
    }

    /** Uloží celý týdenní rozvrh najednou. */
    public function update(Request $request): RedirectResponse
    {
        $staff = $this->editableStaff($request);
        $rows = [];

        foreach (range(1, 7) as $weekday) {
            // Den označený „Zavřeno“ se ukládá bez úseků, ať v polích zůstalo cokoliv.
            if ($request->boolean("closed.$weekday")) {
                continue;
            }

            foreach ((array) $request->input("hours.$weekday", []) as $i => $interval) {
                $from = trim((string) ($interval['from'] ?? ''));
                $to = trim((string) ($interval['to'] ?? ''));

                if ($from === '' && $to === '') {
                    continue;
                }

                if (! $this->isTime($from) || ! $this->isTime($to) || $from >= $to) {
                    throw ValidationException::withMessages([
                        "hours.$weekday" => 'Zadejte čas od–do tak, aby konec byl po začátku.',
                    ]);
                }

                $rows[] = ['user_id' => $staff->id, 'weekday' => $weekday, 'starts_at' => $from, 'ends_at' => $to];
            }
        }

        DB::transaction(function () use ($staff, $rows) {
            $staff->workingHours()->delete();
            $staff->workingHours()->insert($rows);
        });

        return back()->with('status', 'Pracovní doba je uložená.');
    }

    public function storeTimeOff(Request $request): RedirectResponse
    {
        $staff = $this->editableStaff($request);

        $data = $request->validate([
            'from_date' => ['required', 'date_format:Y-m-d'],
            'to_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:from_date'],
            'from_time' => ['nullable', 'date_format:H:i'],
            'to_time' => ['nullable', 'date_format:H:i'],
            'reason' => ['nullable', 'string', 'max:255'],
        ], ['to_date.after_or_equal' => 'Konec volna nesmí být před začátkem.']);

        // Bez časů = celé dny.
        $from = CarbonImmutable::parse($data['from_date'].' '.($data['from_time'] ?? '00:00'));
        $to = isset($data['to_time'])
            ? CarbonImmutable::parse($data['to_date'].' '.$data['to_time'])
            : CarbonImmutable::parse($data['to_date'])->addDay();

        if ($to <= $from) {
            throw ValidationException::withMessages(['to_time' => 'Konec volna musí být po začátku.']);
        }

        $staff->timeOffs()->create(['starts_at' => $from, 'ends_at' => $to, 'reason' => $data['reason'] ?? null]);

        return back()->with('status', 'Volno je přidané. Rezervace, které do něj spadají, zůstávají – zkontrolujte kalendář.');
    }

    public function destroyTimeOff(TimeOff $timeOff): RedirectResponse
    {
        $this->authorizeOwnerOf($timeOff->user_id);
        $timeOff->delete();

        return back()->with('status', 'Volno je smazané.');
    }

    public function storeOverride(Request $request): RedirectResponse
    {
        $staff = $this->editableStaff($request);

        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'from' => ['required', 'date_format:H:i'],
            'to' => ['required', 'date_format:H:i', 'after:from'],
        ], ['to.after' => 'Konec musí být po začátku.']);

        $staff->workingHourOverrides()->create(['date' => $data['date'], 'starts_at' => $data['from'], 'ends_at' => $data['to']]);

        return back()->with('status', 'Výjimka je přidaná.');
    }

    public function destroyOverride(WorkingHourOverride $override): RedirectResponse
    {
        $this->authorizeOwnerOf($override->user_id);
        $override->delete();

        return back()->with('status', 'Výjimka je smazaná.');
    }

    /** Záznam smí smazat jeho kadeřnice, nebo správce téhož salonu. */
    private function authorizeOwnerOf(int $staffId): void
    {
        $me = $this->user();

        abort_unless(
            $staffId === $me->id || ($me->isOwner() && $me->salon->users()->whereKey($staffId)->exists()),
            404,
        );
    }

    private function isTime(string $value): bool
    {
        return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value);
    }
}
