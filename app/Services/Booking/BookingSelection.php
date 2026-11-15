<?php

namespace App\Services\Booking;

use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Ověřený výběr z rezervačního formuláře.
 */
final class BookingSelection
{
    /**
     * @param  Collection<int, Service>  $services  v pořadí ceníku
     * @param  User|null  $staff  null = „Kdokoliv“
     */
    public function __construct(
        public readonly Collection $services,
        public readonly ?User $staff,
        public readonly CarbonImmutable $start,
        public readonly ?string $note,
    ) {}
}
