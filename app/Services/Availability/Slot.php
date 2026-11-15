<?php

namespace App\Services\Availability;

use App\Support\Cz;
use Carbon\CarbonImmutable;

/**
 * Volný začátek rezervace + kadeřnice, které ho mají volný.
 */
final class Slot
{
    /**
     * @param  list<int>  $staffIds
     */
    public function __construct(
        public readonly CarbonImmutable $start,
        public readonly array $staffIds,
    ) {}

    /** 10:30 */
    public function time(): string
    {
        return Cz::time($this->start);
    }
}
