@use('App\Support\Cz')
@extends('layouts.admin')

@section('title', 'Ceník')

@php
    $query = $staff->is(auth()->user()) ? [] : ['kadernice' => $staff->id];
    $own = $staff->is(auth()->user());
@endphp

@section('content')
    <a class="back-link" href="{{ route('admin.more') }}">Admin</a>
    <h1 class="screen-title">Ceník{{ $own ? '' : ' – '.$staff->name }}</h1>

    @include('admin.partials.staff-switch', ['route' => 'admin.services.index'])

    <p class="label mb-3">
        {{ $own ? 'Váš ceník' : 'Ceník kadeřnice '.$staff->name }}: zaškrtněte, které služby {{ $own ? 'děláte' : 'dělá' }}, a upravte cenu a délku.
        Délka se dělí na práci, pauzu na působení barvy (v ní může přijít jiná zákaznice) a dokončení.
        Zákaznice u {{ $own ? 'vás' : 'ní' }} uvidí právě tyto ceny.
    </p>

    @if ($services->isEmpty())
        <div class="empty">Ceník je zatím prázdný. Přidejte první službu.</div>
    @else
        <form method="post" action="{{ route('admin.services.prices', $query) }}" novalidate autocomplete="off">
            @csrf

            @foreach ($services as $service)
                @php
                    $pivot = $mine->get($service->id)?->pivot;
                    $offers = (bool) old("rows.{$service->id}.offers", $pivot !== null);
                    $value = fn (string $key, ?int $ownValue, int $base) => old("rows.{$service->id}.{$key}", $ownValue ?? $base);
                @endphp
                <fieldset class="price-row @if (! $service->is_active) opacity-60 @endif" x-data="{ offers: @js($offers) }" :class="{ 'is-off': !offers }">
                    <legend class="sr-only">{{ $service->name }}</legend>

                    <label class="price-row-name">
                        <input type="checkbox" name="rows[{{ $service->id }}][offers]" value="1" x-model="offers" aria-label="{{ $service->name }} – dělám">
                        <span>
                            <b>{{ $service->name }}</b>
                            <span>
                                @if (! $service->is_active) skrytá v nabídce ·
                                @endif
                                výchozí {{ $service->priceLabel() }}, {{ $service->durationLabel() }}@if ($service->staff_count === 0) · zatím ji nikdo nedělá @endif
                            </span>
                        </span>
                    </label>

                    <div class="price-row-fields" x-show="offers" x-cloak>
                        <label class="field">
                            <span>Cena{{ $service->price_to ? ' od' : '' }} (Kč)</span>
                            <input class="input" type="number" name="rows[{{ $service->id }}][price]" value="{{ $value('price', $pivot?->price_from, $service->price_from) }}" inputmode="numeric" min="0" step="10">
                        </label>
                        <label class="field">
                            <span>Práce (min)</span>
                            <input class="input" type="number" name="rows[{{ $service->id }}][work_1]" value="{{ $value('work_1', $pivot?->work_1, $service->work_1) }}" inputmode="numeric" min="5" step="5">
                        </label>
                        <label class="field">
                            <span>Pauza (min)</span>
                            <input class="input" type="number" name="rows[{{ $service->id }}][pause]" value="{{ $value('pause', $pivot?->pause, $service->pause) }}" inputmode="numeric" min="0" step="5">
                        </label>
                        <label class="field">
                            <span>Dokončení (min)</span>
                            <input class="input" type="number" name="rows[{{ $service->id }}][work_2]" value="{{ $value('work_2', $pivot?->work_2, $service->work_2) }}" inputmode="numeric" min="0" step="5">
                        </label>
                    </div>

                    @foreach (['price', 'work_1', 'pause', 'work_2'] as $key)
                        @error("rows.{$service->id}.{$key}") <div class="field-error">{{ $message }}</div> @enderror
                    @endforeach

                    <div class="price-row-tools">
                        @if ($editable($service))
                            <a class="btn btn-quiet btn-sm" href="{{ route('admin.services.edit', [$service] + $query) }}">Upravit službu</a>
                        @endif
                        @if ($isOwner)
                            <button class="btn btn-quiet btn-sm w-11 px-0" type="submit" form="poradi-{{ $service->id }}" name="direction" value="up" @disabled($loop->first) aria-label="Posunout {{ $service->name }} nahoru">↑</button>
                            <button class="btn btn-quiet btn-sm w-11 px-0" type="submit" form="poradi-{{ $service->id }}" name="direction" value="down" @disabled($loop->last) aria-label="Posunout {{ $service->name }} dolů">↓</button>
                        @endif
                    </div>
                </fieldset>
            @endforeach

            <div class="bottom-bar">
                <a class="btn btn-outline" href="{{ route('admin.services.create', $query) }}">Přidat službu</a>
                <button class="btn btn-primary" type="submit">Uložit ceník</button>
            </div>
        </form>

        {{-- Pořadí služeb v nabídce (samostatné formuláře, ať Enter v ceníku vždy ukládá ceník) --}}
        @if ($isOwner)
            @foreach ($services as $service)
                <form method="post" action="{{ route('admin.services.reorder', $service) }}" id="poradi-{{ $service->id }}" class="hidden">@csrf</form>
            @endforeach
        @endif
    @endif

    @if ($services->isEmpty())
        <a class="btn btn-primary btn-block mt-4" href="{{ route('admin.services.create', $query) }}">Přidat službu</a>
    @endif
@endsection
