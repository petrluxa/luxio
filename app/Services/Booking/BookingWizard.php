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
