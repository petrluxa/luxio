<?php

namespace App\Services\Booking;

use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use App\Services\Availability\EffectiveService;
use App\Services\Availability\ServicePlan;
use App\Services\Availability\Slot;
use App\Services\Availability\SlotFinder;
use App\Support\Cz;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Společná logika rezervačního průvodce (zákaznice, veřejný odkaz i administrace):
 * data pro výběr kadeřnice a služeb, nabídka dnů a časů a ověření odeslaného výběru.
 */
final class BookingWizard
{
    public const WINDOW_DAYS = 14;

    /** Kolik dní jedné kadeřnice ukazuje rozvrh pro zákaznice. */
    public const BOARD_WEEK = 7;

    public function __construct(private readonly SlotFinder $slots) {}

    /**
     * Kadeřnice a služby pro průvodce.
     *
     * @param  int|null  $scopeStaffId  token omezený na jednu kadeřnici
     * Každá kadeřnice má vlastní ceník, proto má služba popisek (délka · cena) pro každou zvlášť
     * a pro volbu „Kdokoliv“ souhrnný („od“ nejnižší ceny).
     *
     * @return array{staff: list<array{id: int, name: string, label: string}>, services: list<array<string, mixed>>, staffHeading: string}
     */
    public function options(Salon $salon, ?int $scopeStaffId = null): array
    {
        $staff = $salon->staff()->with('services:id')->get()
            ->when($scopeStaffId, fn (Collection $all) => $all->where('id', $scopeStaffId))
            ->values();

        $services = $salon->services()->where('is_active', true)->get()
            ->map(function (Service $service) use ($staff) {
                $own = $staff->filter(fn (User $u) => $u->services->contains('id', $service->id))
                    ->mapWithKeys(fn (User $u) => [$u->id => EffectiveService::for($service, $u)]);

                return [
                    'id' => $service->id,
                    'name' => $service->name,
                    'meta' => $this->commonMeta($service, $own),
                    'metaByStaff' => $own->map(fn (EffectiveService $s) => Cz::duration($s->totalMinutes()).' · '.Cz::price($s->priceFrom, $s->priceTo))->all(),
                    'staffIds' => $own->keys()->all(),
                ];
            })
            ->filter(fn (array $service) => $service['staffIds'] !== [])
            ->values();

        // Když má salon víc zaměření, je u jména vidět, kdo co dělá („Lena · pedikúra“).
        $mixed = $staff->map(fn (User $u) => $u->positionKey())->filter()->unique()->count() > 1;

        return [
            'staff' => $staff->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'label' => $u->name.($mixed && $u->positionKey() ? ' · '.trim($u->position) : ''),
                'position' => $mixed && $u->positionKey() ? trim($u->position) : '',
            ])->all(),
            'services' => $services->all(),
            'staffHeading' => $mixed ? 'Ke komu' : 'Kadeřnice',
        ];
    }

    /**
     * Nabídka dnů a časů pro okno 14 dní (odpověď pro průvodce).
     *
     * @param  array<int>  $serviceIds
     * @param  ServicePlan|null  $fixedPlan  hotový plán při přesunu existující rezervace (délky ze snapshotu)
     */
    public function slots(
        Salon $salon,
        array $serviceIds,
        ?int $staffId,
        ?string $from = null,
        bool $ignoreLimits = false,
        ?int $ignoreBookingId = null,
        ?ServicePlan $fixedPlan = null,
    ): array {
        $services = $fixedPlan ? collect() : $this->services($salon, $serviceIds);
        $staff = $staffId ? $salon->staff()->with('services')->whereKey($staffId)->first() : null;

        $today = CarbonImmutable::today();
        $lastDay = $ignoreLimits ? $today->addYear() : $today->addDays($salon->rules()->maxDaysAhead);
        $firstDay = $ignoreLimits ? $today->subMonths(3) : $today;

        $start = $this->parseDate($from) ?? $today;
        $start = $start->max($firstDay)->min($lastDay);

        $found = ($staffId && ! $staff) || (! $fixedPlan && $services->isEmpty())
            ? []
            : $this->slots->forDays($salon, $fixedPlan ?? $services, $start, self::WINDOW_DAYS, $staff, $ignoreLimits, $ignoreBookingId);

        $days = [];

        for ($i = 0; $i < self::WINDOW_DAYS; $i++) {
            $day = $start->addDays($i);

            if ($day > $lastDay) {
                break;
            }

            $days[] = [
                'date' => $day->toDateString(),
                'chip' => mb_convert_case(Cz::dayShort($day), MB_CASE_TITLE).' '.$day->day,
                'label' => Cz::date($day),
                'month' => Cz::monthYear($day),
                'times' => ($found[$day->toDateString()] ?? collect())->map(fn (Slot $slot) => $slot->time())->all(),
            ];
        }

        $next = $start->addDays(self::WINDOW_DAYS);
        $prev = $start->subDays(self::WINDOW_DAYS)->max($firstDay);

        return [
            'from' => $start->toDateString(),
            'prev' => $start > $firstDay ? $prev->toDateString() : null,
            'next' => $next <= $lastDay ? $next->toDateString() : null,
            'days' => $days,
            'summary' => $this->summary($services, $staff),
        ];
    }

    /**
     * Rozvrh pro zákaznici. Bez vybrané kadeřnice je to jeden den celého salonu (sloupec = člověk),
     * s vybranou kadeřnicí jejích sedm dní (sloupec = den). U každého sloupce je pracovní doba,
     * půlhodiny, od kterých se dá objednat (`starts`), a půlhodiny, které jsou obsazené (`taken` –
     * bez jmen, bez služeb a bez toho, kolik rezervací v nich je). Časy jsou v minutách od půlnoci.
     *
     * @param  int|null  $scopeStaffId  token omezený na jednu kadeřnici
     * @param  int|null  $staffId  kadeřnice, jejíž týden se má ukázat
     * @return array<string, mixed>
     */
    public function board(Salon $salon, ?string $date = null, ?int $scopeStaffId = null, ?int $staffId = null): array
    {
        $today = CarbonImmutable::today();
        $lastDay = $today->addDays($salon->rules()->maxDaysAhead);
        $day = ($this->parseDate($date) ?? $today)->max($today)->min($lastDay);

        $staff = $salon->staff()->with('services')->get()
            ->when($scopeStaffId, fn (Collection $all) => $all->where('id', $scopeStaffId))
            ->values();

        $member = $staffId ? $staff->firstWhere('id', $staffId) : null;
        $step = $member ? self::BOARD_WEEK : 1;
        $days = (int) min($step, $day->diffInDays($lastDay) + 1);
        $shown = $member ? $staff->where('id', $member->id)->values() : $staff;

        $overview = $this->slots->overview($salon, $shown, $day, $days);
        $pairs = fn (array $ranges, CarbonImmutable $base) => array_map(
            fn ($range) => [max(0, (int) $base->diffInMinutes($range->start)), min(24 * 60, (int) $base->diffInMinutes($range->end))],
            $ranges,
        );

        $column = function (User $who, CarbonImmutable $date) use ($overview, $pairs, $today, $member) {
            $cell = $overview[$who->id][$date->toDateString()];
            $open = $pairs($cell['open'], $date);

            return [
                'key' => $who->id.'-'.$date->toDateString(),
                'staffId' => $who->id,
                'date' => $date->toDateString(),
                'short' => Cz::date($date),
                'today' => $date->equalTo($today),
                // Den celého salonu: jméno a zaměření. Týden: den a pracovní doba.
                'title' => $member
                    ? ($date->equalTo($today) ? 'Dnes' : mb_convert_case(Cz::dayShort($date), MB_CASE_TITLE)).' '.$date->day.'. '.$date->month.'.'
                    : $who->name,
                'subtitle' => $member
                    ? ($open ? $this->clock(min(array_column($open, 0))).'–'.$this->clock(max(array_column($open, 1))) : 'zavřeno')
                    : trim((string) $who->position),
                'open' => $open,
                'starts' => $cell['starts'],
                'taken' => $cell['taken'],
            ];
        };

        $columns = $member
            ? collect(range(0, $days - 1))->map(fn (int $i) => $column($member, $day->addDays($i)))->all()
            : $staff->map(fn (User $who) => $column($who, $day))->all();

        // Osa času: od první otevírací hodiny po poslední zavírací (celé hodiny); když je všude zavřeno, 9–17.
        $opens = $columns ? array_merge(...array_column($columns, 'open')) : [];
        $from = $opens ? intdiv(min(array_column($opens, 0)), 60) * 60 : 9 * 60;
        $to = $opens ? (int) ceil(max(array_column($opens, 1)) / 60) * 60 : 17 * 60;
        $last = $day->addDays($days - 1);

        return [
            'mode' => $member ? 'week' : 'day',
            'staffId' => $member?->id,
            'date' => $day->toDateString(),
            'label' => $member ? Cz::date($day).' – '.Cz::date($last) : Cz::dateLong($day),
            'isToday' => $day->equalTo($today),
            'isTomorrow' => ! $member && $day->equalTo($today->addDay()),
            'today' => $today->toDateString(),
            'tomorrow' => ! $member && $today->addDay() <= $lastDay ? $today->addDay()->toDateString() : null,
            'prev' => $day > $today ? $day->subDays($step)->max($today)->toDateString() : null,
            'next' => $day->addDays($step) <= $lastDay ? $day->addDays($step)->toDateString() : null,
            'max' => $lastDay->toDateString(),
            'from' => $from,
            'to' => $to,
            'closed' => $opens === [],
            'columns' => $columns,
        ];
    }

    /**
     * Měsíc pro kalendář, kterým zákaznice skáče na jiný den: celé týdny od pondělí, u každého dne,
     * jestli se na něj dá skočit (dnešek až nejzazší den objednání) a jestli salon – nebo vybraná
     * kadeřnice – ten den pracuje.
     *
     * @param  string|null  $month  `Y-m`
     * @return array<string, mixed>
     */
    public function month(Salon $salon, ?string $month = null, ?int $scopeStaffId = null, ?int $staffId = null): array
    {
        $today = CarbonImmutable::today();
        $lastDay = $today->addDays($salon->rules()->maxDaysAhead);
        $first = ($this->parseDate($month ? $month.'-01' : null) ?? $today)->startOfMonth()
            ->max($today->startOfMonth())
            ->min($lastDay->startOfMonth());

        $staffIds = $salon->staff()->pluck('id')
            ->when($scopeStaffId, fn (Collection $ids) => $ids->intersect([$scopeStaffId]))
            ->when($staffId, fn (Collection $ids) => $ids->contains($staffId) ? $ids->intersect([$staffId]) : $ids)
            ->values()->all();

        $from = $first->startOfWeek(CarbonImmutable::MONDAY);
        $to = $first->endOfMonth()->endOfWeek(CarbonImmutable::SUNDAY)->startOfDay();
        $open = $this->slots->openDays($staffIds, $from, $to);
        $days = [];

        for ($day = $from; $day <= $to; $day = $day->addDay()) {
            $days[] = [
                'date' => $day->toDateString(),
                'day' => $day->day,
                'label' => Cz::dateLong($day),
                'inMonth' => $day->month === $first->month,
                'enabled' => $day >= $today && $day <= $lastDay,
                'open' => $open[$day->toDateString()],
                'today' => $day->equalTo($today),
            ];
        }

        return [
            'month' => $first->format('Y-m'),
            'label' => mb_convert_case(Cz::monthYear($first), MB_CASE_TITLE),
            'prev' => $first > $today->startOfMonth() ? $first->subMonth()->format('Y-m') : null,
            'next' => $first->addMonth() <= $lastDay ? $first->addMonth()->format('Y-m') : null,
            'days' => $days,
        ];
    }

    /** Minuty od půlnoci → 9:00. */
    private function clock(int $minute): string
    {
        return intdiv($minute, 60).':'.str_pad((string) ($minute % 60), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Ověří odeslaný výběr (služby, kadeřnice, den a čas).
     *
     * @throws ValidationException
     */
    public function resolve(Salon $salon, array $input, ?int $scopeStaffId = null): BookingSelection
    {
        $data = Validator::make($input, [
            'services' => ['required', 'array', 'min:1', 'max:6'],
            'services.*' => ['integer'],
            'staff' => ['nullable', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'regex:/^([01]?\d|2[0-3]):[0-5]\d$/'],
            'note' => ['nullable', 'string', 'max:500', 'not_regex:/https?:|www\.|<a\s|\[url/i'],
        ], [
            'note.not_regex' => 'Do poznámky prosím nevkládejte odkazy.',
            'services.required' => 'Vyberte prosím službu.',
            'date.required' => 'Vyberte prosím den.',
            'time.required' => 'Vyberte prosím čas.',
        ])->validate();

        $ids = array_values(array_unique(array_map('intval', $data['services'])));
        $services = $this->services($salon, $ids);

        if ($services->count() !== count($ids)) {
            throw ValidationException::withMessages(['services' => 'Vybraná služba už není v nabídce.']);
        }

        $staffId = $scopeStaffId ?? ($data['staff'] ?? null);
        $staff = null;

        if ($staffId) {
            $staff = $salon->staff()->with('services')->whereKey($staffId)->first();

            if (! $staff) {
                throw ValidationException::withMessages(['staff' => 'Vybraná kadeřnice už nepřijímá rezervace.']);
            }
        }

        return new BookingSelection(
            services: $services,
            staff: $staff,
            start: CarbonImmutable::parse($data['date'].' '.$data['time']),
            note: isset($data['note']) && trim($data['note']) !== '' ? trim($data['note']) : null,
        );
    }

    /**
     * Popisek služby pro volbu „Kdokoliv“: délka a cena napříč kadeřnicemi, které ji dělají.
     *
     * @param  Collection<int, EffectiveService>  $own  služba tak, jak ji dělají jednotlivé kadeřnice
     */
    private function commonMeta(Service $service, Collection $own): string
    {
        if ($own->isEmpty()) {
            return $service->durationLabel().' · '.$service->priceLabel();
        }

        $minutes = $own->map(fn (EffectiveService $s) => $s->totalMinutes());
        $prices = $own->map(fn (EffectiveService $s) => $s->priceFrom);
        $from = $prices->min() !== $prices->max()
            || $own->contains(fn (EffectiveService $s) => $s->priceTo !== null && $s->priceTo > $s->priceFrom);

        return ($minutes->min() === $minutes->max() ? Cz::duration($minutes->min()) : Cz::duration($minutes->min()).' až '.Cz::duration($minutes->max()))
            .' · '.($from ? 'od ' : '').Cz::money($prices->min());
    }

    /**
     * Aktivní služby salonu v pořadí ceníku.
     *
     * @param  array<int>  $ids
     * @return Collection<int, Service>
     */
    private function services(Salon $salon, array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return $salon->services()->where('is_active', true)->whereIn('id', $ids)->get();
    }

    /** Souhrn délky a ceny – u konkrétní kadeřnice podle jejích vlastních hodnot. */
    private function summary(Collection $services, ?User $staff): ?array
    {
        if ($services->isEmpty()) {
            return null;
        }

        $plan = ServicePlan::for($services, $staff);
        $hasRange = collect($plan->services)->contains(fn (EffectiveService $s) => $s->priceTo !== null && $s->priceTo > $s->priceFrom);

        return [
            'duration' => Cz::duration($plan->serviceMinutes()),
            'minutes' => $plan->serviceMinutes(),
            'price' => ($hasRange ? 'od ' : '').Cz::money($plan->totalPrice()),
        ];
    }

    private function parseDate(?string $value): ?CarbonImmutable
    {
        if (! $value || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }
}
