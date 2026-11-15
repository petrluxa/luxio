<?php

namespace App\Services\Availability;

use App\Enums\BookingStatus;
use App\Enums\SegmentType;
use App\Models\Booking;
use App\Models\BookingSegment;
use Carbon\CarbonImmutable;

/**
 * Obsazenost kalendáře: blokující úseky (práce a úklid) existujících rezervací.
 * Pauzy se sem nenačítají – do pauzy se smí vejít jiná rezervace.
 */
final class Occupancy
{
    /**
     * @param  array<int, list<TimeRange>>  $blocking  [staffId] => úseky
     * @param  array<int, array<string, int>>  $counts  [staffId][Y-m-d] => počet rezervací
     */
    private function __construct(
        private readonly array $blocking,
        private readonly array $counts,
    ) {}

    /**
     * @param  list<int>  $staffIds
     * @param  int|null  $ignoreBookingId  rezervace, která se právě přesouvá – její vlastní úseky nevadí
     */
    public static function load(array $staffIds, CarbonImmutable $from, CarbonImmutable $to, ?int $ignoreBookingId = null): self
    {
        $blocking = [];
        $counts = [];

        // Úklid po poslední službě může přesáhnout do dalšího dne, proto s rezervou.
        $segments = BookingSegment::query()
            ->whereIn('staff_id', $staffIds)
            ->whereIn('type', [SegmentType::Work, SegmentType::Buffer])
            ->where('starts_at', '<', $to->endOfDay())
            ->where('ends_at', '>', $from->startOfDay())
            ->when($ignoreBookingId, fn ($q) => $q->where('booking_id', '!=', $ignoreBookingId))
            ->get(['staff_id', 'starts_at', 'ends_at']);

        foreach ($segments as $segment) {
            $blocking[$segment->staff_id][] = new TimeRange($segment->starts_at, $segment->ends_at);
        }

        $bookings = Booking::query()
            ->whereIn('staff_id', $staffIds)
            ->whereIn('status', BookingStatus::occupying())
            ->where('starts_at', '>=', $from->startOfDay())
            ->where('starts_at', '<=', $to->endOfDay())
            ->when($ignoreBookingId, fn ($q) => $q->whereKeyNot($ignoreBookingId))
            ->get(['staff_id', 'starts_at']);

        foreach ($bookings as $booking) {
            $key = $booking->starts_at->toDateString();
            $counts[$booking->staff_id][$key] = ($counts[$booking->staff_id][$key] ?? 0) + 1;
        }

        return new self($blocking, $counts);
    }

    /**
     * Koliduje některý z úseků s existující prací nebo úklidem kadeřnice?
     *
     * @param  list<TimeRange>  $ranges
     */
    public function collides(int $staffId, array $ranges): bool
    {
        foreach ($this->blocking[$staffId] ?? [] as $busy) {
            foreach ($ranges as $range) {
                if ($busy->overlaps($range)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Počet rezervací kadeřnice v daný den (pro přiřazení u volby „Kdokoliv“). */
    public function bookingCount(int $staffId, CarbonImmutable $date): int
    {
        return $this->counts[$staffId][$date->toDateString()] ?? 0;
    }
}
