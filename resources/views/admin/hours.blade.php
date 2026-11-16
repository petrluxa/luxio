@use('App\Support\Cz')
@extends('layouts.admin')

@section('title', 'Pracovní doba a volno')

@php
    $query = $staff->is(auth()->user()) ? [] : ['kadernice' => $staff->id];
@endphp

@section('content')
    <a class="back-link" href="{{ route('admin.more') }}">Admin</a>
    <h1 class="screen-title">Pracovní doba</h1>

    @include('admin.partials.staff-switch', ['route' => 'admin.hours'])

    {{-- Týdenní rozvrh: u každého dne zaškrtnutí „Zavřeno“, jinak čas od–do a případně polední pauza --}}
    <form method="post" action="{{ route('admin.hours.update', $query) }}" novalidate>
        @csrf
        <p class="label mb-2">Dny, kdy se nepracuje, označte jako Zavřeno. Polední pauza rozdělí den na dva úseky.</p>

        @foreach (range(1, 7) as $weekday)
            @php
                $rows = $week[$weekday] ?? collect();
                $time = fn (int $i, string $key, string $column) => (string) old("hours.$weekday.$i.$key", isset($rows[$i]) ? substr($rows[$i]->{$column}, 0, 5) : '');
                $state = [
                    // Po neúspěšném uložení platí, co bylo odesláno; jinak je zavřeno, když den nemá žádný úsek.
                    'closed' => old('_token') !== null ? (bool) old("closed.$weekday") : $rows->isEmpty(),
                    'f0' => $time(0, 'from', 'starts_at'),
                    't0' => $time(0, 'to', 'ends_at'),
                    'f1' => $time(1, 'from', 'starts_at'),
                    't1' => $time(1, 'to', 'ends_at'),
                ];
                $state['split'] = $state['f1'] !== '' || $state['t1'] !== '';
                $dayName = Cz::weekdayName($weekday);
            @endphp
            <fieldset class="card @if ($weekday > 1) mt-2 @endif"
                      x-data="{
                          ...@js($state),
                          reopen() { if (!this.closed && !this.f0 && !this.t0) { this.f0 = '09:00'; this.t0 = '17:00'; } },
                          togglePause() { this.split = !this.split; if (!this.split) { this.f1 = ''; this.t1 = ''; } },
                      }">
                <legend class="sr-only">{{ $dayName }}</legend>

                <div class="flex items-center gap-3">
                    <b class="font-semibold" :class="{ 'text-muted': closed }" aria-hidden="true">{{ mb_convert_case($dayName, MB_CASE_TITLE) }}</b>
                    <label class="check ml-auto">
                        <input type="checkbox" name="closed[{{ $weekday }}]" value="1" x-model="closed" x-on:change="reopen()" aria-label="{{ $dayName }} – zavřeno">
                        <span>Zavřeno</span>
                    </label>
                </div>

                <div x-show="!closed" @if ($state['closed']) x-cloak @endif>
                    <div class="mt-1 flex items-center gap-2">
                        <input class="input" type="time" name="hours[{{ $weekday }}][0][from]" step="300" x-model="f0" aria-label="{{ $dayName }} od">
                        <span class="text-muted">–</span>
                        <input class="input" type="time" name="hours[{{ $weekday }}][0][to]" step="300" x-model="t0" aria-label="{{ $dayName }} do">
                    </div>
                    <div class="mt-2 flex items-center gap-2" x-show="split" @unless ($state['split']) x-cloak @endunless>
                        <input class="input" type="time" name="hours[{{ $weekday }}][1][from]" step="300" x-model="f1" aria-label="{{ $dayName }}, po pauze od">
                        <span class="text-muted">–</span>
                        <input class="input" type="time" name="hours[{{ $weekday }}][1][to]" step="300" x-model="t1" aria-label="{{ $dayName }}, po pauze do">
                    </div>
                    <button type="button" class="btn btn-quiet btn-sm mt-2" x-on:click="togglePause()" x-text="split ? 'Bez polední pauzy' : 'Přidat polední pauzu'">Přidat polední pauzu</button>
                </div>

                @error("hours.$weekday") <div class="field-error">{{ $message }}</div> @enderror
            </fieldset>
        @endforeach

        <button class="btn btn-primary btn-block mt-3" type="submit">Uložit pracovní dobu</button>
    </form>

    {{-- Volno a blokace --}}
    <h2 class="section-title mt-6">Volno a blokace</h2>

    @foreach ($timeOffs as $timeOff)
        <div class="row">
            <span class="row-main">
                <b>{{ Cz::date($timeOff->starts_at) }} {{ Cz::time($timeOff->starts_at) }} – {{ Cz::date($timeOff->ends_at) }} {{ Cz::time($timeOff->ends_at) }}</b>
                @if ($timeOff->reason)<span>{{ $timeOff->reason }}</span>@endif
            </span>
            <form method="post" action="{{ route('admin.time-off.destroy', $timeOff) }}">
                @csrf
                <button class="btn btn-quiet btn-sm" type="submit" aria-label="Smazat volno {{ Cz::date($timeOff->starts_at) }}">Smazat</button>
            </form>
        </div>
    @endforeach

    <form method="post" action="{{ route('admin.time-off.store', $query) }}" class="card mt-2" novalidate>
        @csrf
        <b class="mb-2 block font-semibold">Přidat volno</b>
        <div class="grid grid-cols-2 gap-3">
            <label class="field">
                <span>Od (den)</span>
                <input class="input" type="date" name="from_date" value="{{ old('from_date') }}" required>
            </label>
            <label class="field">
                <span>Do (den)</span>
                <input class="input" type="date" name="to_date" value="{{ old('to_date') }}" required>
            </label>
            <label class="field">
                <span>Od (čas, nepovinné)</span>
                <input class="input" type="time" name="from_time" value="{{ old('from_time') }}" step="300">
            </label>
            <label class="field">
                <span>Do (čas, nepovinné)</span>
                <input class="input" type="time" name="to_time" value="{{ old('to_time') }}" step="300">
            </label>
        </div>
        @error('from_date') <div class="field-error">{{ $message }}</div> @enderror
        @error('to_date') <div class="field-error">{{ $message }}</div> @enderror
        @error('to_time') <div class="field-error">{{ $message }}</div> @enderror
        <p class="label mb-2">Bez časů se zablokují celé dny.</p>
        <label class="field">
            <span>Důvod (nepovinné)</span>
            <input class="input" type="text" name="reason" value="{{ old('reason') }}" maxlength="255" placeholder="Dovolená, lékař…">
        </label>
        <button class="btn btn-outline btn-block" type="submit">Přidat volno</button>
    </form>

    {{-- Výjimky a svátky --}}
    <h2 class="section-title mt-6">Výjimky pro jeden den</h2>
    <p class="label mb-2">
        Ve státní svátek je automaticky zavřeno. Výjimkou otevřete i ve svátek nebo jednorázově změníte pracovní dobu (třeba pracovní sobota).
        @if ($upcomingHolidays)
            Nejbližší svátky:
            @foreach ($upcomingHolidays as $holiday){{ Cz::date($holiday['date']) }}@if (! $loop->last), @endif @endforeach.
        @endif
    </p>

    @foreach ($overrides as $override)
        <div class="row">
            <span class="row-main">
                <b>{{ Cz::date($override->date) }} {{ $override->date->year }}</b>
                <span>{{ substr($override->starts_at, 0, 5) }}–{{ substr($override->ends_at, 0, 5) }}</span>
            </span>
            <form method="post" action="{{ route('admin.overrides.destroy', $override) }}">
                @csrf
                <button class="btn btn-quiet btn-sm" type="submit" aria-label="Smazat výjimku {{ Cz::date($override->date) }}">Smazat</button>
            </form>
        </div>
    @endforeach

    <form method="post" action="{{ route('admin.overrides.store', $query) }}" class="card mt-2" novalidate>
        @csrf
        <b class="mb-2 block font-semibold">Přidat výjimku</b>
        <label class="field">
            <span>Den</span>
            <input class="input" type="date" name="date" value="{{ old('date') }}" required>
            @error('date') <div class="field-error">{{ $message }}</div> @enderror
        </label>
        <div class="grid grid-cols-2 gap-3">
            <label class="field">
                <span>Od</span>
                <input class="input" type="time" name="from" value="{{ old('from') }}" step="300" required>
            </label>
            <label class="field">
                <span>Do</span>
                <input class="input" type="time" name="to" value="{{ old('to') }}" step="300" required>
            </label>
        </div>
        @error('from') <div class="field-error">{{ $message }}</div> @enderror
        @error('to') <div class="field-error">{{ $message }}</div> @enderror
        <button class="btn btn-outline btn-block" type="submit">Přidat výjimku</button>
    </form>
@endsection
