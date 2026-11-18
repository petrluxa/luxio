@use('App\Support\Cz')
@use('App\Enums\BookingStatus')
@use('App\Enums\SegmentType')
@extends('layouts.admin')

@section('title', 'Rozvrh')

@php
    $week = $view === 'tyden';
    $hourHeight = 68;                              // px na hodinu
    $half = $hourHeight / 2;                       // řádek mřížky = půl hodiny
    $dayStart = $hourFrom * 60;
    $dayEnd = $hourTo * 60;
    $height = ($hourTo - $hourFrom) * $hourHeight;
    // Poloha času na ose; co přesahuje zobrazené hodiny (rezervace přes půlnoc), se ořízne.
    $y = fn ($time, $day) => round((max($dayStart, min($dayEnd, $time->isSameDay($day) ? $time->hour * 60 + $time->minute : ($time < $day ? 0 : 1440))) - $dayStart) * $hourHeight / 60, 1);
    // Odkazy v rozvrhu si nesou, koho rozvrh ukazuje (jeden člověk / celý salon) a skupinu.
    $keep = ['kadernice' => $who, 'obor' => $group];
    $link = fn (array $change = []) => route('admin.calendar', array_filter($change + $keep + ['datum' => $date->toDateString()], fn ($value) => $value !== null));
    $now = now();
    $today = $now->startOfDay();
    $first = $columns[0]['date'];
    // Jeden člověk: jeho jméno a sedm dní od–do. Celý salon: den v týdnu a datum.
    $title = $week ? $staff->name : mb_convert_case(Cz::weekdayName($date->dayOfWeekIso), MB_CASE_TITLE);
    $subtitle = $week ? Cz::date($first).' – '.Cz::date($first->addDays(6)) : Cz::dateFull($date);
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
        <a class="sched-arrow" href="{{ $link(['datum' => $back->toDateString()]) }}" aria-label="{{ $week ? 'O týden zpět' : 'Předchozí den' }}">‹</a>
        <div class="top-day-title">
            <h1>{{ $title }}</h1>
            <span>{{ $subtitle }}</span>
        </div>
        <a class="sched-arrow" href="{{ $link(['datum' => $step->toDateString()]) }}" aria-label="{{ $week ? 'O týden dál' : 'Další den' }}">›</a>
        <a class="chip chip-sm @if ($date->equalTo($today)) is-on @endif" href="{{ $link(['datum' => $today->toDateString()]) }}">Dnes</a>
        @unless ($week)
            <a class="chip chip-sm top-day-extra @if ($date->equalTo($today->addDay())) is-on @endif" href="{{ $link(['datum' => $today->addDay()->toDateString()]) }}">Zítra</a>
        @endunless
        {{-- Skok na libovolný den (třeba za měsíc): ikona otevře kalendář zařízení. --}}
        <label class="top-day-pick" x-data="{ go(value) { if (value) { window.location = @js($link(['datum' => '__DEN__'])).replace('__DEN__', value) } } }">
            @include('partials.icon', ['name' => 'calendar', 'size' => 20])
            <span class="sr-only">Vybrat den v kalendáři</span>
            <input type="date" value="{{ $date->toDateString() }}" aria-label="Vybrat den v kalendáři"
                   x-on:click="$el.showPicker && $el.showPicker()" x-on:change="go($el.value)">
        </label>
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

            <div class="chips" role="group" aria-label="Skupina">
                {{-- Na začátku to, co se týká přihlášeného: vybraný člověk (ťuknutím se vrátí celý salon nebo
                     skupina) a cesta zpět ke svým sedmi dnům. --}}
                @if (! $all && $staffList->count() > 1)
                    <a class="chip chip-sm is-on" href="{{ $link(['kadernice' => $group ? null : 'vse']) }}" aria-label="{{ $staff->name }} – zpět na {{ $group ? 'skupinu' : 'celý salon' }}">{{ $staff->name }} ×</a>
                @endif
                @if ($me->is_bookable && ($all || ! $staff->is($me)))
                    <a class="chip chip-sm" href="{{ $link(['kadernice' => $me->id, 'obor' => null]) }}">Můj rozvrh</a>
                @endif

                {{-- Skupiny podle zaměření: kadeřnice, manikúra, pedikúra, kosmetika… --}}
                @if ($groups->isNotEmpty())
                    <a class="chip chip-sm @if (! $group && $all) is-on @endif" href="{{ $link(['obor' => null, 'kadernice' => 'vse']) }}" @if (! $group && $all) aria-current="true" @endif>Celý salon</a>
                    @foreach ($groups as $key => $label)
                        <a class="chip chip-sm @if ($group === $key && $all) is-on @endif" href="{{ $link(['obor' => $key, 'kadernice' => null]) }}"
                           @if ($group === $key && $all) aria-current="true" @endif>{{ mb_convert_case(mb_substr($label, 0, 1), MB_CASE_UPPER).mb_substr($label, 1) }}</a>
                    @endforeach
                @elseif ($staffList->count() > 1)
                    <a class="chip chip-sm @if ($all) is-on @endif" href="{{ $link(['kadernice' => 'vse']) }}" @if ($all) aria-current="true" @endif>Celý salon</a>
                @endif

                <button type="button" class="chip chip-sm mini-cal-toggle" x-on:click="open = !open" :aria-expanded="open">Kalendář</button>
            </div>
        </div>
    </section>

    @if ($holiday)
        <div class="banner mb-3">Státní svátek: {{ $holiday }} – zavřeno</div>
    @endif

    {{-- Mřížka: sloupce = lidé z týmu (u jednoho člověka jeho sedm dní), řádky po půl hodině. Rezervace jdou přetáhnout.
         Sloupec má všude stejnou nejmenší šířku; co se nevejde, posouvá se do strany (šipkami v záhlaví, na telefonu prstem). --}}
    <div class="cal-frame"
         x-data="calendarBoard(@js([
             'dayStart' => $dayStart,
             'dayEnd' => $dayEnd,
             'hourHeight' => $hourHeight,
             'snap' => $slotStep,
             'moveUrl' => route('admin.bookings.drop', ['booking' => '__ID__']),
         ]))">
        {{-- Záhlaví sloupců: u jednoho člověka dny, jinak lidé s pracovní dobou. Drží se pod horní lištou
             a do strany se posouvá spolu s mřížkou (hlídá to skript). --}}
        <div class="cal-headbar">
        <div class="cal-headbar-scroll" x-ref="headbar">
        <div class="cal @if ($week) cal-week @endif" style="--cal-cols: {{ count($columns) }}">
            <div class="cal-corner">Čas</div>
            @foreach ($columns as $column)
                @if ($week)
                    {{-- Den vede správce na rozvrh celého salonu v ten den; kadeřnici posune její týden na ten den.
                         Tlačítko + zapíše další zákaznici i na čas, kde už někdo je. --}}
                    <div class="cal-head @if ($column['date']->equalTo($today)) is-today @endif">
                        <a class="cal-head-day"
                           href="{{ $wholeSalon ? route('admin.calendar', ['kadernice' => 'vse', 'datum' => $column['date']->toDateString()]) : $link(['datum' => $column['date']->toDateString()]) }}"
                           aria-label="{{ Cz::dateLong($column['date']) }}@if ($wholeSalon) – celý salon @endif">
                            <b>{{ $column['date']->equalTo($today) ? 'Dnes' : mb_convert_case(Cz::dayShort($column['date']), MB_CASE_TITLE) }} {{ $column['date']->day }}. {{ $column['date']->month }}.</b>
                            <span>{{ $hoursLabel($column) }}</span>
                        </a>
                        <a class="cal-add" href="{{ route('admin.bookings.create', ['datum' => $column['date']->toDateString(), 'kadernice' => $column['staff']->id]) }}"
                           aria-label="Nová rezervace – {{ Cz::dateLong($column['date']) }}">@include('partials.icon', ['name' => 'plus', 'size' => 18])</a>
                    </div>
                @else
                    <div class="cal-staff">
                        <span class="cal-staff-dot" style="background: {{ $column['staff']->color }}" aria-hidden="true"></span>
                        {{-- Jméno vede na rozvrh jen tohoto člověka (jeho sedm dní). --}}
                        <a class="cal-staff-name" href="{{ $link(['kadernice' => $column['staff']->id]) }}" aria-label="{{ $column['staff']->name }} – jen její rozvrh">
                            <b>{{ $column['staff']->name }}</b>
                            <span>{{ $headLabel($column) }}</span>
                        </a>
                        <a class="cal-add" href="{{ route('admin.bookings.create', ['datum' => $column['date']->toDateString(), 'kadernice' => $column['staff']->id]) }}"
                           aria-label="Nová rezervace – {{ $column['staff']->name }}">@include('partials.icon', ['name' => 'plus', 'size' => 20])</a>
                    </div>
                @endif
            @endforeach
        </div>
        </div>
            <button type="button" class="cal-pan cal-pan-prev" style="display: none" x-show="pan.left" x-on:click="panBy(-1)" aria-label="Posunout rozvrh doleva">‹</button>
            <button type="button" class="cal-pan cal-pan-next" style="display: none" x-show="pan.right" x-on:click="panBy(1)" aria-label="Posunout rozvrh doprava">›</button>
        </div>

        <div class="cal-scroll" x-ref="scroll">
        <div class="cal @if ($week) cal-week @endif" style="--cal-cols: {{ count($columns) }}; --cal-half: {{ $half }}px" x-ref="board">
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

                    {{-- Rezervace je jeden plný kus přes celou šířku sloupce: práce, působení barvy a dokončení
                         na sebe navazují (úklid po zákaznici se nekreslí). Když se dvě kryjí, stojí vedle sebe
                         (pruhy z CalendarLanes). --}}
                    @foreach ($column['bookings'] as $booking)
                        @php
                            $firstWork = true;
                            $place = $column['lanes'][$booking->id] ?? ['lane' => 0, 'lanes' => 1];
                            $box = 'left: calc(2px + (100% - 4px) * '.$place['lane'].' / '.$place['lanes'].'); '
                                .'width: calc((100% - 4px) / '.$place['lanes'].' - '.($place['lanes'] > 1 ? 2 : 0).'px);';
                            $pending = $booking->status === BookingStatus::Pending;
                            $done = ! $pending && ($booking->isPaid() || $booking->status === BookingStatus::Completed || $booking->ends_at->isPast());
                            $state = match (true) {
                                $pending => 'čeká',
                                $booking->isPaid() => 'zaplaceno',
                                default => '',
                            };
                            // V bloku je jen příjmení, vpravo nahoře telefon a vpravo dole úkon; celé jméno je v popisku.
                            $surname = $booking->customer->surname();
                            $phone = $booking->customer->phone ? Cz::phoneShort($booking->customer->phone) : '';
                            $what = $booking->servicesLabel();
                            $parts = $booking->segments->reject(fn ($segment) => $segment->type === SegmentType::Buffer)->values();
                            $stateClasses = ['is-pending' => $pending, 'is-done' => $done, 'is-no-show' => $booking->status === BookingStatus::NoShow];
                        @endphp
                        @foreach ($parts as $segment)
                            @php
                                $top = $y($segment->starts_at, $day);
                                // Pod koncem rezervace zůstává tenká mezera, aby se dvě po sobě nespojily v jednu.
                                $segmentHeight = max(4, $y($segment->ends_at, $day) - $top - ($loop->last ? 2 : 0));
                            @endphp
                            @if ($segment->type === SegmentType::Work)
                                {{-- Nízký blok (do 40 min) má příjmení a úkon na jednom řádku, nejnižší (do 25 min) jen záhlaví s příjmením. --}}
                                <a @class(['cal-block', 'is-first' => $loop->first, 'is-last' => $loop->last,
                                        'is-tiny' => $segmentHeight < 30, 'is-short' => $segmentHeight >= 30 && $segmentHeight < 50] + $stateClasses)
                                   style="top: {{ $top }}px; height: {{ $segmentHeight }}px; {{ $box }}" href="{{ route('admin.bookings.show', $booking) }}"
                                   data-booking="{{ $booking->id }}" data-start="{{ $booking->starts_at->hour * 60 + $booking->starts_at->minute }}"
                                   data-duration="{{ $booking->durationMinutes() }}" data-name="{{ $booking->customer->name }}"
                                   @if ($booking->status->isActive()) data-draggable draggable="false" @endif
                                   title="{{ $booking->customer->name }}@if ($phone) · {{ $phone }}@endif · {{ $what }}"
                                   aria-label="{{ Cz::time($booking->starts_at) }} {{ $booking->customer->name }}, {{ $what }}@if ($state), {{ $state }}@endif">
                                    @if ($firstWork)
                                        <span class="cal-block-head">
                                            <span class="cal-block-time">
                                                <span>{{ Cz::time($booking->starts_at) }}<span class="cal-block-dur"> ({{ $short($booking->durationMinutes()) }})</span></span>
                                                <b>{{ $surname }}</b>
                                                @if ($state)<i class="cal-block-state">@include('partials.icon', ['name' => $pending ? 'clock' : 'pending', 'size' => 12])<span>{{ $state }}</span></i>@endif
                                            </span>
                                            @if ($phone)<span class="cal-block-tag">@include('partials.icon', ['name' => 'phone', 'size' => 11])<span>{{ $phone }}</span></span>@endif
                                        </span>
                                        <span class="cal-block-body">
                                            <span class="cal-block-who">@include('partials.icon', ['name' => 'customers', 'size' => 13])<b>{{ $surname }}</b></span>
                                            <span class="cal-block-what">{{ $what }}</span>
                                        </span>
                                    @else
                                        <span class="cal-block-head">
                                            <span class="cal-block-time"><span>{{ Cz::time($segment->starts_at) }} · dokončení</span><b>{{ $surname }}</b></span>
                                        </span>
                                        <span class="cal-block-body">
                                            <span class="cal-block-who">@include('partials.icon', ['name' => 'customers', 'size' => 13])<b>{{ $surname }}</b></span>
                                        </span>
                                    @endif
                                </a>
                                @php $firstWork = false; @endphp
                            @else
                                <div @class(['cal-pause', 'is-first' => $loop->first, 'is-last' => $loop->last] + $stateClasses)
                                     style="top: {{ $top }}px; height: {{ $segmentHeight }}px; {{ $box }}">působí barva</div>
                            @endif
                        @endforeach
                    @endforeach

                    @if ($day->equalTo($today) && $now->hour >= $hourFrom && $now->hour < $hourTo)
                        <div class="cal-now" style="top: {{ $y($now, $day) }}px"></div>
                    @endif
                </div>
            @endforeach
        </div>
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
        Rezervaci přetáhnete myší na jiný čas{{ $week ? ' nebo den' : ' nebo k jiné kadeřnici' }} (na telefonu ji nejdřív chvíli podržte).
        @if ($week)Další dny na telefonu posunete prstem do strany, šipky nahoře posouvají o týden.
        @else Když se všechny sloupce nevejdou, posunete rozvrh do strany šipkami v záhlaví (na telefonu prstem).@endif
        Kliknutím na volné místo vytvoříte novou, kliknutím na rezervaci otevřete detail a vyúčtování.
        Další zákaznici na čas, kde už někdo je, přidáte tlačítkem + v záhlaví sloupce.
    </p>
@endsection
