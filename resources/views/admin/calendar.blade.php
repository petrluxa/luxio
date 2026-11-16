@use('App\Support\Cz')
@use('App\Enums\BookingStatus')
@use('App\Enums\SegmentType')
@extends('layouts.admin')

@section('title', 'Rozvrh')

@php
    $week = $view === 'tyden';
    $hourHeight = $week ? 52 : 68;                 // px na hodinu
    $half = $hourHeight / 2;                       // řádek mřížky = půl hodiny
    $dayStart = $hourFrom * 60;
    $dayEnd = $hourTo * 60;
    $height = ($hourTo - $hourFrom) * $hourHeight;
    // Poloha času na ose; co přesahuje zobrazené hodiny (rezervace přes půlnoc), se ořízne.
    $y = fn ($time, $day) => round((max($dayStart, min($dayEnd, $time->isSameDay($day) ? $time->hour * 60 + $time->minute : ($time < $day ? 0 : 1440))) - $dayStart) * $hourHeight / 60, 1);
    // Odkazy v rozvrhu drží zvolené zobrazení a kadeřnici.
    $keep = ['zobrazeni' => $week ? 'tyden' : null, 'kadernice' => $all ? null : $staff->id, 'obor' => $group];
    $link = fn (array $change = []) => route('admin.calendar', array_filter($change + $keep + ['datum' => $date->toDateString()], fn ($value) => $value !== null));
    $now = now();
    $today = $now->startOfDay();
    $first = $columns[0]['date'];
    $title = $week ? 'Týden' : mb_convert_case(Cz::weekdayName($date->dayOfWeekIso), MB_CASE_TITLE);
    $subtitle = $week
        ? $first->day.'. '.$first->month.'. – '.Cz::dateFull($first->addDays(6)).($staffList->count() > 1 ? ' · '.$staff->name : '')
        : Cz::dateFull($date);
    // Začátek a konec pracovního dne kadeřnice (polední pauza je vidět v mřížce).
    $hoursLabel = fn (array $column) => match (true) {
        (bool) $column['holiday'] => 'svátek',
        $column['open'] === [] => 'volno',
        default => Cz::time($column['open'][0]->start).'–'.Cz::time($column['open'][count($column['open']) - 1]->end),
    };
    // Pod jménem v záhlaví: zaměření (když jich je v salonu víc) a pracovní doba.
    // V hustém rozvrhu (8 a víc sloupců) zbývá místo jen na pracovní dobu; zaměření je vidět ve skupinách.
    $headLabel = fn (array $column) => ($groups->isNotEmpty() && count($columns) < 8 && $column['staff']->positionKey() ? $column['staff']->position.' · ' : '').$hoursLabel($column);
    // Délka jako v rozvrhu kurtů: 45m, 1h, 1h30m
    $short = fn (int $minutes) => ($minutes >= 60 ? intdiv($minutes, 60).'h' : '').($minutes % 60 || $minutes < 60 ? ($minutes % 60).'m' : '');
@endphp

{{-- Den se šipkami patří do horní lišty (místo názvu salonu – ten je na ceduli v panelu). --}}
@section('top-left')
    <div class="top-day">
        <a class="sched-arrow" href="{{ $link(['datum' => $back->toDateString()]) }}" aria-label="{{ $week ? 'Předchozí týden' : 'Předchozí den' }}">‹</a>
        <div class="top-day-title">
            <h1>{{ $title }}</h1>
            <span>{{ $subtitle }}</span>
        </div>
        <a class="sched-arrow" href="{{ $link(['datum' => $step->toDateString()]) }}" aria-label="{{ $week ? 'Další týden' : 'Další den' }}">›</a>
        <a class="chip chip-sm @if ($date->equalTo($today)) is-on @endif" href="{{ $link(['datum' => $today->toDateString()]) }}">Dnes</a>
        <a class="chip chip-sm top-day-extra @if ($date->equalTo($today->addDay())) is-on @endif" href="{{ $link(['datum' => $today->addDay()->toDateString()]) }}">Zítra</a>
        {{-- Týden jednoho člověka; druhé ťuknutí vrací den. --}}
        <a class="chip chip-sm @if ($week) is-on @endif" href="{{ $week ? $link(['zobrazeni' => null]) : $link(['zobrazeni' => 'tyden', 'kadernice' => $staff->id]) }}"
           @if ($week) aria-current="true" @endif>Týden</a>
    </div>
@endsection

@section('content')
    <section class="sched-top" x-data="{ open: false }">
        {{-- Měsíční kalendář: skok na jiný den, tečka = den s rezervacemi --}}
        <div class="mini-cal" :class="{ 'is-open': open }">
            <div class="mini-cal-head">
                <a class="sched-arrow" href="{{ $link(['mesic' => $mini['prev']]) }}" aria-label="Předchozí měsíc">‹</a>
                <b>{{ mb_convert_case(Cz::monthYear($mini['month']), MB_CASE_TITLE) }}</b>
                <a class="sched-arrow" href="{{ $link(['mesic' => $mini['next']]) }}" aria-label="Další měsíc">›</a>
            </div>
            <div class="mini-cal-grid" role="grid" aria-label="Kalendář">
                @foreach (['po', 'út', 'st', 'čt', 'pá', 'so', 'ne'] as $dow)
                    <span class="mini-cal-dow">{{ $dow }}</span>
                @endforeach
                @foreach ($mini['weeks'] as $days)
                    @foreach ($days as $day)
                        <a href="{{ $link(['datum' => $day['date']->toDateString()]) }}"
                           @class(['mini-day', 'is-out' => ! $day['inMonth'], 'is-today' => $day['date']->equalTo($today), 'is-on' => $day['date']->equalTo($date), 'has-bookings' => $day['count'] > 0])
                           aria-label="{{ Cz::dateLong($day['date']) }}@if ($day['count'] > 0), rezervací: {{ $day['count'] }}@endif"
                           @if ($day['date']->equalTo($date)) aria-current="date" @endif>{{ $day['date']->day }}</a>
                    @endforeach
                @endforeach
            </div>
        </div>

        <div class="sched-head">
            {{-- Cedule salonu: podsvícený nápis na stěně; v tmavé verzi je „zhasnuto“. --}}
            <div class="sign" style="--sign-chars: {{ max(4, mb_strlen($salon->signTitle())) }}">
                <span class="sign-glow sign-glow-top" aria-hidden="true"></span>
                <span class="sign-glow sign-glow-left" aria-hidden="true"></span>
                <span class="sign-glow sign-glow-right" aria-hidden="true"></span>
                <b class="sign-title">{{ $salon->signTitle() }}</b>
                @if ($salon->sign_subtitle)<span class="sign-sub">{{ $salon->sign_subtitle }}</span>@endif
            </div>

            <div class="chips justify-center" role="group" aria-label="Skupina">
                {{-- Skupiny podle zaměření: kadeřnice, manikúra, pedikúra, kosmetika… --}}
                @if ($groups->isNotEmpty())
                    <a class="chip chip-sm @if (! $group && $all) is-on @endif" href="{{ $link(['obor' => null, 'kadernice' => null, 'zobrazeni' => null]) }}" @if (! $group && $all) aria-current="true" @endif>Celý salon</a>
                    @foreach ($groups as $key => $label)
                        <a class="chip chip-sm @if ($group === $key && $all) is-on @endif" href="{{ $link(['obor' => $key, 'kadernice' => null, 'zobrazeni' => null]) }}"
                           @if ($group === $key && $all) aria-current="true" @endif>{{ mb_convert_case(mb_substr($label, 0, 1), MB_CASE_UPPER).mb_substr($label, 1) }}</a>
                    @endforeach
                @elseif ($staffList->count() > 1)
                    <a class="chip chip-sm @if ($all) is-on @endif" href="{{ $link(['kadernice' => null, 'zobrazeni' => null]) }}" @if ($all) aria-current="true" @endif>Celý salon</a>
                @endif

                {{-- Vybraný jeden člověk (kliknutím na jméno ve sloupci): ťuknutím se výběr zruší. --}}
                @if (! $all && $staffList->count() > 1)
                    <a class="chip chip-sm is-on" href="{{ $link(['kadernice' => null, 'zobrazeni' => null]) }}" aria-label="{{ $staff->name }} – zrušit výběr">{{ $staff->name }} ×</a>
                @endif

                <button type="button" class="chip chip-sm mini-cal-toggle" x-on:click="open = !open" :aria-expanded="open">Kalendář</button>
            </div>
        </div>
    </section>

    @if ($holiday)
        <div class="banner mb-3">Státní svátek: {{ $holiday }} – zavřeno</div>
    @endif

    {{-- Mřížka: sloupce = kadeřnice (v týdnu dny), řádky po půl hodině. Rezervace jdou přetáhnout. --}}
    <div class="cal-scroll"
         x-data="calendarBoard(@js([
             'dayStart' => $dayStart,
             'dayEnd' => $dayEnd,
             'hourHeight' => $hourHeight,
             'snap' => $slotStep,
             'moveUrl' => route('admin.bookings.drop', ['booking' => '__ID__']),
         ]))">
        <div class="cal @if ($week) cal-week @elseif (count($columns) >= 8) cal-dense @endif" style="--cal-cols: {{ count($columns) }}; --cal-half: {{ $half }}px" x-ref="board">
            {{-- Záhlaví sloupců: v týdnu dny, ve dni kadeřnice s pracovní dobou --}}
            <div class="cal-corner">Čas</div>
            @foreach ($columns as $column)
                @if ($week)
                    <a class="cal-head @if ($column['date']->equalTo($today)) is-today @endif" href="{{ route('admin.calendar', ['kadernice' => $staff->id, 'datum' => $column['date']->toDateString()]) }}">
                        <b>{{ mb_convert_case(Cz::dayShort($column['date']), MB_CASE_TITLE) }} {{ $column['date']->day }}.</b>
                        <span>{{ $hoursLabel($column) }}</span>
                    </a>
                @else
                    <div class="cal-staff">
                        <span class="cal-staff-dot" style="background: {{ $column['staff']->color }}" aria-hidden="true"></span>
                        {{-- Jméno vede na rozvrh jen tohoto člověka (den, odtud i týden). --}}
                        <a class="cal-staff-name" href="{{ $link(['kadernice' => $column['staff']->id]) }}" aria-label="{{ $column['staff']->name }} – jen její rozvrh">
                            <b>{{ $column['staff']->name }}</b>
                            <span>{{ $headLabel($column) }}</span>
                        </a>
                        <a class="cal-add" href="{{ route('admin.bookings.create', ['datum' => $column['date']->toDateString(), 'kadernice' => $column['staff']->id]) }}"
                           aria-label="Nová rezervace – {{ $column['staff']->name }}">@include('partials.icon', ['name' => 'plus', 'size' => 20])</a>
                    </div>
                @endif
            @endforeach

            {{-- Osa času po půl hodině --}}
            <div class="cal-hours" style="height: {{ $height }}px">
                @for ($minute = $dayStart; $minute < $dayEnd; $minute += 30)
                    <span class="cal-hour @if ($minute % 60) is-half @endif" style="top: {{ ($minute - $dayStart) * $hourHeight / 60 }}px">{{ sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60) }}</span>
                @endfor
            </div>

            @foreach ($columns as $column)
                @php $day = $column['date']; @endphp
                <div class="cal-col" style="height: {{ $height }}px; --staff: {{ $column['staff']->color }}"
                     data-staff="{{ $column['staff']->id }}" data-date="{{ $day->toDateString() }}"
                     data-label="{{ $week ? Cz::date($day) : $column['staff']->name }}">
                    {{-- Pracovní doba (světlé pozadí) --}}
                    @foreach ($column['open'] as $interval)
                        <div class="cal-open" style="top: {{ $y($interval->start, $day) }}px; height: {{ $y($interval->end, $day) - $y($interval->start, $day) }}px"></div>
                    @endforeach

                    {{-- Místa k ťuknutí: i mimo pracovní dobu, kadeřnice si zákaznici přidá kdykoliv --}}
                    @for ($minute = $dayStart; $minute < $dayEnd; $minute += 30)
                        @php $cell = $day->addMinutes($minute); @endphp
                        <a class="cal-slot @if ($minute % 60) is-half @endif" style="top: {{ ($minute - $dayStart) * $hourHeight / 60 }}px; height: {{ $half }}px"
                           href="{{ route('admin.bookings.create', ['datum' => $day->toDateString(), 'cas' => $cell->format('G:i'), 'kadernice' => $column['staff']->id]) }}"
                           aria-label="Nová rezervace {{ Cz::date($day) }} v {{ Cz::time($cell) }}@if (! $week) – {{ $column['staff']->name }}@endif"></a>
                    @endfor

                    {{-- Rezervace po úsecích: práce, pauza (působí barva), úklid.
                         Když se dvě kryjí, stojí vedle sebe (pruhy z CalendarLanes). --}}
                    @foreach ($column['bookings'] as $booking)
                        @php
                            $firstWork = true;
                            $place = $column['lanes'][$booking->id] ?? ['lane' => 0, 'lanes' => 1];
                            $box = 'left: calc(2px + (100% - var(--cal-gutter)) * '.$place['lane'].' / '.$place['lanes'].'); '
                                .'width: calc((100% - var(--cal-gutter)) / '.$place['lanes'].' - 3px);';
                            $pending = $booking->status === BookingStatus::Pending;
                            $done = ! $pending && ($booking->isPaid() || $booking->status === BookingStatus::Completed || $booking->ends_at->isPast());
                            $state = match (true) {
                                $pending => 'čeká',
                                $booking->isPaid() => 'zaplaceno',
                                default => '',
                            };
                        @endphp
                        @foreach ($booking->segments as $segment)
                            @php
                                $top = $y($segment->starts_at, $day);
                                $segmentHeight = max(4, $y($segment->ends_at, $day) - $top);
                            @endphp
                            @if ($segment->type === SegmentType::Work)
                                <a @class(['cal-block', 'is-pending' => $pending, 'is-done' => $done, 'is-no-show' => $booking->status === BookingStatus::NoShow])
                                   style="top: {{ $top }}px; height: {{ $segmentHeight }}px; {{ $box }}" href="{{ route('admin.bookings.show', $booking) }}"
                                   data-booking="{{ $booking->id }}" data-start="{{ $booking->starts_at->hour * 60 + $booking->starts_at->minute }}"
                                   data-duration="{{ $booking->durationMinutes() }}" data-name="{{ $booking->customer->name }}"
                                   @if ($booking->status->isActive()) data-draggable draggable="false" @endif
                                   aria-label="{{ Cz::time($booking->starts_at) }} {{ $booking->customer->name }}, {{ $booking->servicesLabel() }}">
                                    @if ($week)
                                        <span class="cal-block-head"><span>{{ Cz::time($segment->starts_at) }}</span></span>
                                        <b>{{ $booking->customer->name }}</b>
                                    @elseif ($firstWork)
                                        <span class="cal-block-head">
                                            <span>{{ Cz::time($booking->starts_at) }} ({{ $short($booking->durationMinutes()) }})</span>
                                            @if ($state)<i>{{ $state }}</i>@endif
                                        </span>
                                        <b>{{ $booking->customer->name }}</b>
                                        <span class="cal-block-meta">@if ($booking->customer->phone){{ Cz::phoneShort($booking->customer->phone) }} · @endif{{ $booking->servicesLabel() }}</span>
                                    @else
                                        <span class="cal-block-head"><span>{{ Cz::time($segment->starts_at) }} · dokončení</span></span>
                                        <b>{{ $booking->customer->name }}</b>
                                    @endif
                                </a>
                                @php $firstWork = false; @endphp
                            @elseif ($segment->type === SegmentType::Pause)
                                <div class="cal-pause" style="top: {{ $top }}px; height: {{ $segmentHeight }}px; {{ $box }}">
                                    @if (! $week)<span>působí barva · {{ $booking->customer->name }}</span>@endif
                                </div>
                            @else
                                <div class="cal-buffer" style="top: {{ $top }}px; height: {{ $segmentHeight }}px; {{ $box }}" title="Úklid"></div>
                            @endif
                        @endforeach
                    @endforeach

                    @if ($day->equalTo($today) && $now->hour >= $hourFrom && $now->hour < $hourTo)
                        <div class="cal-now" style="top: {{ $y($now, $day) }}px"></div>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- Potvrzení přesunu po přetažení --}}
        <dialog class="sheet" x-ref="dialog" x-on:cancel.prevent="cancel()">
            <b class="block text-[18px] font-semibold">Přesunout rezervaci?</b>
            <p class="mt-2 text-[16px] font-semibold" x-text="move ? move.name : ''"></p>
            <p class="label" x-text="move ? 'teď: ' + move.from : ''"></p>
            <p class="mt-1" x-text="move ? 'nově: ' + move.to : ''"></p>
            <p class="banner mt-3 font-normal" x-show="move && move.warning" x-text="move ? move.warning : ''"></p>
            <p class="field-error" x-show="error" x-text="error" role="alert"></p>
            <div class="mt-4 flex gap-2">
                <button type="button" class="btn btn-outline flex-1" x-on:click="cancel()">Zpět</button>
                <button type="button" class="btn btn-primary flex-1" :disabled="saving" x-on:click="confirm()">Přesunout</button>
            </div>
            <p class="label mt-3">Zákaznice dostane upozornění o novém čase, pokud je má zapnutá. Jinak jí dejte vědět sama.</p>
        </dialog>
    </div>

    <p class="label mt-3">
        Rezervaci přetáhnete myší na jiný čas nebo k jiné kadeřnici (na telefonu ji nejdřív chvíli podržte).
        Kliknutím na volné místo vytvoříte novou, kliknutím na rezervaci otevřete detail a vyúčtování.
        @if (! $week)Další zákaznici na stejný čas přidáte tlačítkem + u jména kadeřnice.@endif
    </p>
@endsection
