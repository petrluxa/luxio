<?php

namespace App\Services\Availability;

use App\Enums\SegmentType;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Časový plán jedné rezervace: služby seřazené za sebou a rozložené na fáze
 * práce – pauza – práce. Sousední fáze stejného typu se slévají, po poslední
 * službě následuje jeden úklid (nejdelší `buffer_after` z vybraných služeb).
 *
 * Příklad „Barvení a střih“ 30 / 40 / 60 + úklid 10:
 *   work 30 min → pause 40 min → work 60 min → buffer 10 min
 */
final class ServicePlan
{
    /**
     * @param  list<array{type: SegmentType, minutes: int}>  $phases
     * @param  list<EffectiveService>  $services
     */
    private function __construct(
        private readonly array $phases,
        public readonly int $bufferMinutes,
        public readonly array $services,
    ) {}

    /**
     * @param  iterable<Service>  $services  v pořadí, v jakém půjdou za sebou
     */
    public static function for(iterable $services, ?User $staff = null): self
    {
        $effective = [];

        foreach ($services as $service) {
            $effective[] = EffectiveService::for($service, $staff);
        }

        return self::fromEffective($effective);
    }

    /**
     * @param  list<EffectiveService>  $services
     */
    public static function fromEffective(array $services): self
    {
        $phases = [];
        $buffer = 0;

        $push = function (SegmentType $type, int $minutes) use (&$phases): void {
            if ($minutes <= 0) {
                return;
            }

            $last = count($phases) - 1;

            if ($last >= 0 && $phases[$last]['type'] === $type) {
                $phases[$last]['minutes'] += $minutes;

                return;
            }

            $phases[] = ['type' => $type, 'minutes' => $minutes];
        };

        foreach ($services as $service) {
            $push(SegmentType::Work, $service->work1);
            $push(SegmentType::Pause, $service->pause);
            $push(SegmentType::Work, $service->work2);
            $buffer = max($buffer, $service->bufferAfter);
        }

        return new self($phases, $buffer, array_values($services));
    }

    public function isEmpty(): bool
    {
        return $this->phases === [];
    }

    /** Délka pro zákaznici – od začátku do konce poslední fáze, bez úklidu. */
    public function serviceMinutes(): int
    {
        return array_sum(array_column($this->phases, 'minutes'));
    }

    public function totalPrice(): int
    {
        return array_sum(array_map(fn (EffectiveService $s) => $s->priceFrom, $this->services));
    }

    public function endsAt(CarbonImmutable $start): CarbonImmutable
    {
        return $start->addMinutes($this->serviceMinutes());
    }

    /** Úsek, který musí celý ležet v pracovní době (práce i pauzy, bez úklidu). */
    public function serviceRange(CarbonImmutable $start): TimeRange
    {
        return new TimeRange($start, $this->endsAt($start));
    }

    /**
     * Všechny úseky rezervace od daného začátku (včetně pauz a úklidu).
     *
     * @return list<array{type: SegmentType, range: TimeRange}>
     */
    public function segmentsAt(CarbonImmutable $start): array
    {
        $segments = [];
        $cursor = $start;

        foreach ($this->phases as $phase) {
            $end = $cursor->addMinutes($phase['minutes']);
            $segments[] = ['type' => $phase['type'], 'range' => new TimeRange($cursor, $end)];
            $cursor = $end;
        }

        if ($this->bufferMinutes > 0) {
            $segments[] = [
                'type' => SegmentType::Buffer,
                'range' => new TimeRange($cursor, $cursor->addMinutes($this->bufferMinutes)),
            ];
        }

        return $segments;
    }

    /**
     * Jen úseky, které kadeřnici blokují (práce a úklid).
     *
     * @return list<TimeRange>
     */
    public function blockingRangesAt(CarbonImmutable $start): array
    {
        $blocking = [];

        foreach ($this->segmentsAt($start) as $segment) {
            if ($segment['type']->blocks()) {
                $blocking[] = $segment['range'];
            }
        }

        return $blocking;
    }
}
