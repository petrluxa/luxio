<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Services\Availability\HolidayCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Dnes: další zákaznice nahoře, pod ní časová osa dne s rychlými akcemi.
 */
class TodayController extends AdminController
{
    public function __invoke(Request $request, HolidayCalendar $holidays): View
    {
        $staff = $this->currentStaff($request);
        $today = CarbonImmutable::today();

        $bookings = Booking::query()
            ->where('staff_id', $staff->id)
            ->whereIn('status', BookingStatus::occupying())
            ->whereBetween('starts_at', [$today, $today->endOfDay()])
            ->with('customer', 'items', 'segments')
            ->orderBy('starts_at')
            ->get();

        return view('admin.today', [
            'tab' => 'today',
            'staff' => $staff,
            'staffList' => $this->staffList(),
            'today' => $today,
            'holiday' => $holidays->name($today),
            'bookings' => $bookings,
            // Další zákaznice = první rezervace, která ještě neskončila.
            'upNext' => $bookings->first(fn (Booking $b) => $b->status->isActive() && $b->ends_at->isFuture()),
        ]);
    }
}
