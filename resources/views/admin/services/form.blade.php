@extends('layouts.admin')

@php
    $isNew = ! $service->exists;
    $owner = auth()->user()->isOwner();
@endphp

@section('title', $isNew ? 'Nová služba' : $service->name)

@section('content')
    <a class="back-link" href="{{ route('admin.services.index') }}">Služby</a>
    <h1 class="screen-title">{{ $isNew ? 'Nová služba' : $service->name }}</h1>

    <form method="post" action="{{ $isNew ? route('admin.services.store') : route('admin.services.update', $service) }}" novalidate
          x-data="{ offers: @js((bool) old('offers', $offers)) }">
        @csrf

        @if ($owner)
            <label class="field">
                <span>Název</span>
                <input class="input" type="text" name="name" value="{{ old('name', $service->name) }}" maxlength="100" required>
                @error('name') <div class="field-error">{{ $message }}</div> @enderror
            </label>

            <label class="field">
                <span>Popis (nepovinné)</span>
                <textarea class="input" name="description" maxlength="1000" rows="2">{{ old('description', $service->description) }}</textarea>
            </label>

            <div class="grid grid-cols-2 gap-3">
                <label class="field">
                    <span>Cena (Kč)</span>
                    <input class="input" type="number" name="price_from" value="{{ old('price_from', $service->price_from) }}" inputmode="numeric" min="0" step="10" required>
                    @error('price_from') <div class="field-error">{{ $message }}</div> @enderror
                </label>
                <label class="field">
                    <span>Cena do (zobrazí „od“)</span>
                    <input class="input" type="number" name="price_to" value="{{ old('price_to', $service->price_to) }}" inputmode="numeric" min="0" step="10">
                    @error('price_to') <div class="field-error">{{ $message }}</div> @enderror
                </label>
            </div>

            <h2 class="section-title">Délka</h2>
            <p class="label mb-2">Práce – pauza na působení barvy – práce. Během pauzy můžete mít jinou zákaznici.</p>
            <div class="grid grid-cols-3 gap-3">
                <label class="field">
                    <span>Práce (min)</span>
                    <input class="input" type="number" name="work_1" value="{{ old('work_1', $service->work_1) }}" inputmode="numeric" min="5" step="5" required>
                </label>
                <label class="field">
                    <span>Pauza (min)</span>
                    <input class="input" type="number" name="pause" value="{{ old('pause', $service->pause ?? 0) }}" inputmode="numeric" min="0" step="5">
                </label>
                <label class="field">
                    <span>Práce 2 (min)</span>
                    <input class="input" type="number" name="work_2" value="{{ old('work_2', $service->work_2 ?? 0) }}" inputmode="numeric" min="0" step="5">
                </label>
            </div>
            @error('work_1') <div class="field-error">{{ $message }}</div> @enderror
            @error('pause') <div class="field-error">{{ $message }}</div> @enderror
            @error('work_2') <div class="field-error">{{ $message }}</div> @enderror

            <label class="field">
                <span>Úklid po službě (min)</span>
                <input class="input" type="number" name="buffer_after" value="{{ old('buffer_after', $service->buffer_after ?? 0) }}" inputmode="numeric" min="0" step="5">
                @error('buffer_after') <div class="field-error">{{ $message }}</div> @enderror
            </label>

            <label class="check">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $service->is_active))>
                <span>Nabízet zákaznicím k rezervaci</span>
            </label>
        @else
            <div class="card mb-3">
                <b class="font-semibold">{{ $service->durationLabel() }} · {{ $service->priceLabel() }}</b>
                <span class="label block">Ceník upravuje majitelka salonu. Níže si nastavíte vlastní cenu a délky.</span>
            </div>
        @endif

        @unless ($isNew)
            <h2 class="section-title">Moje nastavení</h2>
            <label class="check">
                <input type="checkbox" name="offers" value="1" x-model="offers">
                <span>Tuto službu dělám</span>
            </label>

            <div x-show="offers" x-cloak>
                <p class="label mb-2">Vyplňte jen to, co máte jinak než v ceníku. Prázdné pole = podle ceníku.</p>
                <div class="grid grid-cols-2 gap-3">
                    <label class="field">
                        <span>Moje cena (Kč)</span>
                        <input class="input" type="number" name="own_price" value="{{ old('own_price', $pivot?->price_from) }}" inputmode="numeric" min="0" step="10" placeholder="{{ $service->price_from }}">
                    </label>
                    <label class="field">
                        <span>Moje práce (min)</span>
                        <input class="input" type="number" name="own_work_1" value="{{ old('own_work_1', $pivot?->work_1) }}" inputmode="numeric" min="5" step="5" placeholder="{{ $service->work_1 }}">
                    </label>
                    <label class="field">
                        <span>Moje pauza (min)</span>
                        <input class="input" type="number" name="own_pause" value="{{ old('own_pause', $pivot?->pause) }}" inputmode="numeric" min="0" step="5" placeholder="{{ $service->pause }}">
                    </label>
                    <label class="field">
                        <span>Moje práce 2 (min)</span>
                        <input class="input" type="number" name="own_work_2" value="{{ old('own_work_2', $pivot?->work_2) }}" inputmode="numeric" min="0" step="5" placeholder="{{ $service->work_2 }}">
                    </label>
                </div>
            </div>
        @endunless

        <button class="btn btn-primary btn-block mt-3" type="submit">{{ $isNew ? 'Přidat službu' : 'Uložit' }}</button>
    </form>
@endsection
