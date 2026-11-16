@extends('layouts.admin')

@php
    $isNew = ! $service->exists;
    $query = $for->is(auth()->user()) ? [] : ['kadernice' => $for->id];
@endphp

@section('title', $isNew ? 'Nová služba' : $service->name)

@section('content')
    <a class="back-link" href="{{ route('admin.services.index', $query) }}">Ceník</a>
    <h1 class="screen-title">{{ $isNew ? 'Nová služba' : $service->name }}</h1>

    <form method="post" action="{{ $isNew ? route('admin.services.store', $query) : route('admin.services.update', [$service] + $query) }}" novalidate>
        @csrf

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
        <p class="label mb-2">Práce – pauza na působení barvy – dokončení. Během pauzy může přijít jiná zákaznice.</p>
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
                <span>Dokončení (min)</span>
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

        {{-- Správce u nové služby rovnou vybere, kdo ji dělá. Kadeřnice ji přidává sobě. --}}
        @if ($isNew && $staffList->isNotEmpty())
            <h2 class="section-title">Kdo službu dělá</h2>
            @foreach ($staffList as $member)
                <label class="check">
                    <input type="checkbox" name="staff[]" value="{{ $member->id }}" @checked(in_array($member->id, array_map('intval', (array) old('staff', $checked)), true))>
                    <span>{{ $member->name }}</span>
                </label>
            @endforeach
            @error('staff') <div class="field-error">{{ $message }}</div> @enderror
        @elseif ($isNew)
            <p class="label mt-2">Služba se přidá do vašeho ceníku. Kolegyně si ji mohou zaškrtnout ve svém.</p>
        @else
            <p class="label mt-2">Tohle jsou výchozí hodnoty služby. Vlastní cenu a délku má každá kadeřnice ve svém ceníku.</p>
        @endif

        <button class="btn btn-primary btn-block mt-3" type="submit">{{ $isNew ? 'Přidat službu' : 'Uložit' }}</button>
    </form>
@endsection
