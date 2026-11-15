@use('App\Support\Cz')
@use('App\Enums\BookingStatus')
@use('App\Enums\SegmentType')
@extends('layouts.admin')

@section('title', 'Kalendář')

@php
    $week = $view === 'tyden';
    $hourHeight = $week ? 48 : 68;                 // px na hodinu
    $slotMinutes = $week ? 60 : 30;                // krok pro ťuknutí na volné místo
    $dayStart = $hourFrom * 60;
    $height = ($hourTo - $hourFrom) * $hourHeight;
    $y = fn ($time) => round((($time->hour * 60 + $time->minute) - $dayStart) * $hourHeight / 60, 1);
    $params = fn (array $extra = []) => $extra + ['datum' => $date->toDateString(), 'zobrazeni' => $view, 'kadernice' => $staff->id];
    $now = now();
    $rangeLabel = $week
        ? $days[0]['date']->day.'. '.$days[0]['date']->month.'. – '.Cz::dateFull($days[6]['date'])
        : Cz::dateLong($date).' '.$date->year;
@endphp

@section('content')
    <h1 class="screen-title">Kalendář</h1>

    @include('admin.partials.staff-switch', ['route' => 'admin.calendar', 'params' => ['datum' => $date->toDateString(), 'zobrazeni' => $view]])

    <div class="mb-3 flex items-center gap-2">
        <div class="chips" role="group" aria-label="Zobrazení">
            <a class="chip @if (! $week) is-on @endif" href="{{ route('admin.calendar', $params(['zobrazeni' => 'den'])) }}">Den</a>
            <a class="chip @if ($week) is-on @endif" href="{{ route('admin.calendar', $params(['zobrazeni' => 'tyden'])) }}">Týden</a>
        </div>
        <a class="chip ml-auto" href="{{ route('admin.calendar', $params(['datum' => now()->toDateString()])) }}">Dnes</a>
    </div>

    <div class="mb-3 flex items-center gap-2">
        <a class="chip" href="{{ route('admin.calendar', $params(['datum' => $back->toDateString()])) }}" aria-label="{{ $week ? 'Předchozí týden' : 'Předchozí den' }}">‹</a>
        <b class="flex-1 text-center text-[16px] font-semibold">{{ $rangeLabel }}</b>
        <a class="chip" href="{{ route('admin.calendar', $params(['datum' => $step->toDateString()])) }}" aria-label="{{ $week ? 'Další týden' : 'Další den' }}">›</a>
    </div>

    @if (! $week && $days[0]['holiday'])
        <div class="banner mb-3">Státní svátek: {{ $days[0]['holiday'] }} – zavřeno</div>
    @endif

    <div class="cal @if ($week) cal-week @endif">
        @if ($week)
            <div></div>
            @foreach ($days as $day)
                <a class="cal-head @if ($day['date']->isToday()) is-today @endif" href="{{ route('admin.calendar', $params(['datum' => $day['date']->toDateString(), 'zobrazeni' => 'den'])) }}">
                    <span>{{ mb_convert_case(Cz::dayShort($day['date']), MB_CASE_TITLE) }}</span>
                    <b>{{ $day['date']->day }}</b>
                </a>
            @endforeach
        @endif

        {{-- Osa hodin --}}
        <div class="cal-hours" style="height: {{ $height }}px">
            @for ($hour = $hourFrom; $hour <= $hourTo; $hour++)
                <span class="cal-hour" style="top: {{ ($hour - $hourFrom) * $hourHeight }}px">{{ $hour }}:00</span>
            @endfor
        </div>

        @foreach ($days as $day)
            <div class="cal-col" style="height: {{ $height }}px">
                {{-- Pracovní doba (světlé pozadí) a volná místa k ťuknutí --}}
                @foreach ($day['open'] as $interval)
                    <div class="cal-open" style="top: {{ $y($interval->start) }}px; height: {{ $y($interval->end) - $y($interval->start) }}px"></div>

                    @for ($cell = $interval->start; $cell < $interval->end; $cell = $cell->addMinutes($slotMinutes))
                        <a class="cal-slot" style="top: {{ $y($cell) }}px; height: {{ $slotMinutes * $hourHeight / 60 }}px"
                           href="{{ route('admin.bookings.create', ['datum' => $day['date']->toDateString(), 'cas' => $cell->format('G:i'), 'kadernice' => $staff->id]) }}"
                           aria-label="Nová rezervace {{ Cz::date($day['date']) }} v {{ Cz::time($cell) }}"></a>
                    @endfor
                @endforeach

                @for ($hour = $hourFrom + 1; $hour < $hourTo; $hour++)
                    <div class="cal-line" style="top: {{ ($hour - $hourFrom) * $hourHeight }}px"></div>
                @endfor

                {{-- Rezervace po úsecích: práce, pauza (působí barva), úklid --}}
                @foreach ($day['bookings'] as $booking)
                    @php($firstWork = true)
                    @foreach ($booking->segments as $segment)
                        @php($top = $y($segment->starts_at))
                        @php($segmentHeight = max(4, $y($segment->ends_at) - $top))
                        @if ($segment->type === SegmentType::Work)
                            <a class="cal-block @if ($booking->status === BookingStatus::Pending) is-pending @endif @if ($booking->status === BookingStatus::NoShow) is-no-show @endif"
                               style="top: {{ $top }}px; height: {{ $segmentHeight }}px" href="{{ route('admin.bookings.show', $booking) }}"
                               aria-label="{{ Cz::time($booking->starts_at) }} {{ $booking->customer->name }}, {{ $booking->servicesLabel() }}">
                                @if ($week)
                                    <span>{{ Cz::time($segment->starts_at) }}</span>
                                @elseif ($firstWork)
                                    <b>{{ Cz::time($booking->starts_at) }} · {{ $booking->customer->name }}</b>
                                    <span>{{ $booking->servicesLabel() }}@if ($booking->status === BookingStatus::Pending) · čeká na schválení @endif</span>
                                @else
                                    <span>{{ Cz::time($segment->starts_at) }} · {{ $booking->customer->name }} (dokončení)</span>
                                @endif
                            </a>
                            @php($firstWork = false)
                        @elseif ($segment->type === SegmentType::Pause)
                            <div class="cal-pause" style="top: {{ $top }}px; height: {{ $segmentHeight }}px">
                                @if (! $week)<span>působí barva · {{ $booking->customer->name }}</span>@endif
                            </div>
                        @else
                            <div class="cal-buffer" style="top: {{ $top }}px; height: {{ $segmentHeight }}px" title="Úklid"></div>
                        @endif
                    @endforeach
                @endforeach

                @if ($day['date']->isToday() && $now->hour >= $hourFrom && $now->hour < $hourTo)
                    <div class="cal-now" style="top: {{ $y($now) }}px"></div>
                @endif
            </div>
        @endforeach
    </div>

    <p class="label mt-3">Ťuknutím na volné místo vytvoříte rezervaci, ťuknutím na rezervaci otevřete detail (přesun, zrušení, poznámka).</p>
@endsection
