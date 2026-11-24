<?php

namespace App\Services\Availability;

use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Výpočet volných termínů – jádro systému (SPEC 4.4).
 *
 * Termín je volný, když:
 *  1. celá služba (práce i pauzy, bez úklidu) leží v jednom souvislém úseku pracovní doby
 *     – tím je ošetřen konec dne, polední pauza i volno,
 *  2. žádná práce ani úklid nové rezervace nekoliduje s prací nebo úklidem existující rezervace
 *     – pauza (působení barvy) neblokuje, takže se do ní vejde jiná zákaznice,
 *  3. začátek respektuje lhůty „nejdřív“ a „nejdál“ z nastavení salonu.
 *
 * Kandidátní začátky jdou po kroku (výchozí 15 min) zarovnaném na celé hodiny.
 */
final class SlotFinder
{
    public function __construct(private readonly HolidayCalendar $holidays) {}

    /**
     * Volné termíny pro jeden den.
     *
     * @param  iterable<Service>|ServicePlan  $services  v pořadí, v jakém půjdou za sebou
     * @param  User|null  $staff  konkrétní kadeřnice, nebo null = „Kdokoliv“
     * @return Collection<int, Slot>
     */
    public function forDay(
        Salon $salon,
        iterable|ServicePlan $services,
        CarbonImmutable $date,
        ?User $staff = null,
        bool $ignoreLimits = false,
        ?int $ignoreBookingId = null,
    ): Collection {
        return $this->forDays($salon, $services, $date, 1, $staff, $ignoreLimits, $ignoreBookingId)[$date->toDateString()];
    }

    /**
     * Volné termíny pro několik dní najednou (nabídka dnů v rezervaci).
     *
     * @param  iterable<Service>|ServicePlan  $services
     * @return array<string, Collection<int, Slot>> klíč `Y-m-d`, i pro dny bez volného termínu
     */
    public function forDays(
        Salon $salon,
        iterable|ServicePlan $services,
        CarbonImmutable $from,
        int $days,
        ?User $staff = null,
        bool $ignoreLimits = false,
        ?int $ignoreBookingId = null,
    ): array {
        $from = $from->startOfDay();
        $to = $from->addDays(max(1, $days) - 1);
        // Hotový plán (snapshot při přesunu existující rezervace) se použije tak, jak je;
        // jinak se plán sestaví pro každou kadeřnici zvlášť podle jejích vlastních délek.
        $fixedPlan = $services instanceof ServicePlan ? $services : null;
        $services = $fixedPlan ? collect() : collect($services)->values();
        $serviceIds = $fixedPlan
            ? collect($fixedPlan->services)->map(fn (EffectiveService $s) => $s->serviceId)->filter()
            : $services->pluck('id');

        /** @var array<string, array<string, list<int>>> $found [Y-m-d][H:i] => kadeřnice, které mají čas volný */
        $found = [];

        for ($day = $from; $day <= $to; $day = $day->addDay()) {
            $found[$day->toDateString()] = [];
        }

        $hasPlan = $fixedPlan ? ! $fixedPlan->isEmpty() : $services->isNotEmpty();
        $candidates = $hasPlan ? $this->eligibleStaff($salon, $serviceIds, $staff) : collect();

        if ($candidates->isEmpty()) {
            return array_map(fn () => collect(), $found);
        }

        $rules = $salon->rules();
        $step = $rules->slotStep;
        $now = CarbonImmutable::now();
        $earliest = $ignoreLimits ? null : $now->addHours($rules->minNoticeHours);
        $latest = $ignoreLimits ? null : $now->startOfDay()->addDays($rules->maxDaysAhead)->endOfDay();

        $staffIds = $candidates->modelKeys();
        $workingTime = WorkingTime::load($this->holidays, $staffIds, $from, $to);
        $occupancy = Occupancy::load($staffIds, $from, $to, $ignoreBookingId);

        foreach ($candidates as $member) {
            $plan = $fixedPlan ?? ServicePlan::for($services, $member);
            $length = $plan->serviceMinutes();

            if ($length === 0) {
                continue;
            }

            for ($day = $from; $day <= $to; $day = $day->addDay()) {
                foreach ($workingTime->openIntervals($member->id, $day) as $interval) {
                    for (
                        $start = $this->alignToStep($interval->start, $step);
                        $start->addMinutes($length) <= $interval->end;
                        $start = $start->addMinutes($step)
                    ) {
                        if ($earliest && $start < $earliest) {
                            continue;
                        }

                        if ($latest && $start > $latest) {
                            break;
                        }

                        if ($occupancy->collides($member->id, $plan->blockingRangesAt($start))) {
                            continue;
                        }

                        $found[$day->toDateString()][$start->format('H:i')][] = $member->id;
                    }
                }
            }
        }

        // Sjednocení přes kadeřnice: čas → seznam těch, které ho mají volný.
        $result = [];

        foreach ($found as $date => $times) {
            ksort($times);

            $result[$date] = collect($times)
                ->map(fn (array $ids, string $time) => new Slot(CarbonImmutable::parse("$date $time"), $ids))
                ->values();
        }

        return $result;
    }

    /**
     * Přehled pro rozvrh, který vidí zákaznice (den celého salonu nebo týden jedné kadeřnice).
     * Rozvrh má řádky po půl hodině a každé pole pracovní doby je buď volné (`starts` – od tohoto
     * času se vejde aspoň jedna služba), nebo obsazené (`taken` – je tam jiná zákaznice, nebo mezera,
     * do které se nevejde nic). Čas, který už nejde stihnout, a čas za nejzazším dnem objednání
     * není ani jedno – je zavřený.
     *
     * Přesný začátek po kroku salonu si zákaznice doladí až po výběru služby. Všechno se počítá
     * z jednoho načtení pracovní doby a obsazenosti.
     *
     * @param  \Illuminate\Support\Collection<int, User>  $staff  s načtenými službami
     * @return array<int, array<string, array{open: list<TimeRange>, starts: list<int>, taken: list<int>}>>
     *                                                                                                        [id][Y-m-d], časy = minuty od půlnoci
     */
    public function overview(Salon $salon, Collection $staff, CarbonImmutable $from, int $days = 1): array
    {
        $from = $from->startOfDay();
        $to = $from->addDays(max(1, $days) - 1);
        $staffIds = $staff->modelKeys();

        if ($staffIds === []) {
            return [];
        }

        $rules = $salon->rules();
        $now = CarbonImmutable::now();
        $earliest = $now->addHours($rules->minNoticeHours);
        $latest = $now->startOfDay()->addDays($rules->maxDaysAhead)->endOfDay();

        $workingTime = WorkingTime::load($this->holidays, $staffIds, $from, $to);
        $occupancy = Occupancy::load($staffIds, $from, $to);
        $overview = [];

        foreach ($staff as $member) {
            $plans = $member->services
                ->where('is_active', true)
                ->map(fn (Service $service) => ServicePlan::for([$service], $member))
                ->filter(fn (ServicePlan $plan) => $plan->serviceMinutes() > 0)
                ->sortBy(fn (ServicePlan $plan) => $plan->serviceMinutes())
                ->values();

            for ($day = $from; $day <= $to; $day = $day->addDay()) {
                $open = $workingTime->openIntervals($member->id, $day);
                $starts = [];
                $taken = [];

                foreach ($open as $interval) {
                    for ($start = $this->alignToStep($interval->start, 30); $start < $interval->end; $start = $start->addMinutes(30)) {
                        if ($start < $earliest || $start > $latest) {
                            continue;
                        }

                        // Stačí, že se od tohoto času vejde aspoň jedna služba (zkouší se od nejkratší).
                        $fits = $plans->contains(fn (ServicePlan $plan) => $plan->endsAt($start) <= $interval->end
                            && ! $occupancy->collides($member->id, $plan->blockingRangesAt($start)));

                        if ($fits) {
                            $starts[] = $start->hour * 60 + $start->minute;
                        } else {
                            $taken[] = $start->hour * 60 + $start->minute;
                        }
                    }
                }

                $overview[$member->id][$day->toDateString()] = ['open' => $open, 'starts' => $starts, 'taken' => $taken];
            }
        }

        return $overview;
    }

    /**
     * Dny, kdy aspoň jedna z kadeřnic pracuje (pro měsíční kalendář zákaznice: zavřené dny jsou ztlumené).
     *
     * @param  list<int>  $staffIds
     * @return array<string, bool> klíč `Y-m-d`
     */
    public function openDays(array $staffIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $from = $from->startOfDay();
        $to = $to->startOfDay();
        $workingTime = $staffIds ? WorkingTime::load($this->holidays, $staffIds, $from, $to) : null;
        $days = [];

        for ($day = $from; $day <= $to; $day = $day->addDay()) {
            $days[$day->toDateString()] = $workingTime !== null
                && collect($staffIds)->contains(fn (int $id) => $workingTime->openIntervals($id, $day) !== []);
        }

        return $days;
    }

    /**
     * Je konkrétní začátek u konkrétní kadeřnice volný? Používá se při ukládání
     * rezervace (znovu ověřit v transakci) a při přesunu termínu.
     *
     * @param  iterable<Service>|ServicePlan  $services
     */
    public function isFree(
        Salon $salon,
        User $staff,
        iterable|ServicePlan $services,
        CarbonImmutable $start,
        bool $ignoreLimits = false,
        ?int $ignoreBookingId = null,
    ): bool {
        return $this->forDay($salon, $services, $start, $staff, $ignoreLimits, $ignoreBookingId)
            ->contains(fn (Slot $slot) => $slot->start->equalTo($start));
    }

    /**
     * „Kdokoliv“: z kadeřnic, které mají daný čas volný, vybere tu s nejméně
     * rezervacemi ten den (při shodě tu s nižším id).
     *
     * @param  iterable<Service>|ServicePlan  $services
     */
    public function pickStaff(
        Salon $salon,
        iterable|ServicePlan $services,
        CarbonImmutable $start,
        bool $ignoreLimits = false,
        ?int $ignoreBookingId = null,
    ): ?User {
        $slot = $this->forDay($salon, $services, $start, null, $ignoreLimits, $ignoreBookingId)
            ->first(fn (Slot $slot) => $slot->start->equalTo($start));

        if (! $slot) {
            return null;
        }

        $occupancy = Occupancy::load($slot->staffIds, $start, $start, $ignoreBookingId);

        $staffId = collect($slot->staffIds)
            ->sortBy(fn (int $id) => [$occupancy->bookingCount($id, $start), $id])
            ->first();

        return User::find($staffId);
    }

    /**
     * Kadeřnice, které přicházejí v úvahu: lze se k nim objednat a dělají
     * všechny vybrané služby.
     *
     * @param  iterable<int>  $serviceIds
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    public function eligibleStaff(Salon $salon, iterable $serviceIds, ?User $staff = null)
    {
        $serviceIds = collect($serviceIds)->unique()->values();

        return User::query()
            ->where('salon_id', $salon->id)
            ->where('is_bookable', true)
            ->when($staff, fn ($q) => $q->whereKey($staff->id))
            ->with('services')
            ->orderBy('id')
            ->get()
            ->filter(fn (User $member) => $serviceIds->diff($member->services->pluck('id'))->isEmpty())
            ->values();
    }

    /** Nejbližší začátek na mřížce kroku (počítáno od půlnoci), který není před $time. */
    private function alignToStep(CarbonImmutable $time, int $step): CarbonImmutable
    {
        $minutes = $time->hour * 60 + $time->minute + ($time->second > 0 ? 1 : 0);
        $aligned = (int) (ceil($minutes / $step) * $step);

        return $time->startOfDay()->addMinutes($aligned);
    }
}
