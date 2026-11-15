<?php

namespace App\Services\Availability;

use App\Models\Service;
use App\Models\User;

/**
 * Služba tak, jak ji dělá konkrétní kadeřnice: její vlastní cena a délky
 * z pivotu `staff_service` přebijí hodnoty z ceníku.
 */
final class EffectiveService
{
    public function __construct(
        public readonly ?int $serviceId,
        public readonly string $name,
        public readonly int $priceFrom,
        public readonly ?int $priceTo,
        public readonly int $work1,
        public readonly int $pause,
        public readonly int $work2,
        public readonly int $bufferAfter,
    ) {}

    public static function for(Service $service, ?User $staff = null): self
    {
        $pivot = $staff?->services->firstWhere('id', $service->id)?->pivot;

        $priceFrom = $pivot?->price_from ?? $service->price_from;

        return new self(
            serviceId: $service->id,
            name: $service->name,
            priceFrom: (int) $priceFrom,
            // Vlastní cena kadeřnice je pevná částka, rozpětí „od“ zůstává jen u ceny z ceníku.
            priceTo: $pivot?->price_from !== null ? null : $service->price_to,
            work1: (int) ($pivot?->work_1 ?? $service->work_1),
            pause: (int) ($pivot?->pause ?? $service->pause),
            work2: (int) ($pivot?->work_2 ?? $service->work_2),
            bufferAfter: (int) $service->buffer_after,
        );
    }

    public function totalMinutes(): int
    {
        return $this->work1 + $this->pause + $this->work2;
    }
}
