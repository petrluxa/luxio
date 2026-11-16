@use('App\Enums\UserRole')
@extends('layouts.admin')

@php($isNew = ! $member->exists)

@section('title', $isNew ? 'Nová kadeřnice' : $member->name)

@section('content')
    <a class="back-link" href="{{ route('admin.staff.index') }}">Kadeřnice</a>
    <h1 class="screen-title">{{ $isNew ? 'Nová kadeřnice' : $member->name }}</h1>

    <form method="post" action="{{ $isNew ? route('admin.staff.store') : route('admin.staff.update', $member) }}" novalidate autocomplete="off">
        @csrf

        <label class="field">
            <span>Jméno (uvidí ho zákaznice)</span>
            <input class="input" type="text" name="name" value="{{ old('name', $member->name) }}" maxlength="60" required>
            @error('name') <div class="field-error">{{ $message }}</div> @enderror
        </label>

        <label class="check">
            <input type="checkbox" name="is_bookable" value="1" @checked(old('is_bookable', $member->is_bookable))>
            <span>Má vlastní sloupec v rozvrhu a zákaznice se k ní mohou objednat</span>
        </label>

        <label class="field mt-2">
            <span>Barva v rozvrhu</span>
            <input class="input h-12 p-1" type="color" name="color" value="{{ old('color', $member->color) }}">
            @error('color') <div class="field-error">{{ $message }}</div> @enderror
        </label>

        <h2 class="section-title">Vlastní přihlášení</h2>
        <p class="label mb-2">
            Se jménem a heslem se přihlásí do administrace sama – vidí rozvrh, spravuje si pracovní dobu a zapisuje návštěvy.
            Bez nich je jen v rozvrhu a rezervace jí zapisuje správce.
        </p>

        <label class="field">
            <span>Přihlašovací jméno (malá písmena bez háčků, např. sabina)</span>
            <input class="input" type="text" name="login" value="{{ old('login', $member->login) }}" maxlength="60" autocapitalize="none" autocorrect="off" spellcheck="false" autocomplete="off">
            @error('login') <div class="field-error">{{ $message }}</div> @enderror
        </label>

        <label class="field">
            <span>{{ $member->password ? 'Nové heslo (nechte prázdné, pokud se nemění)' : 'Heslo (aspoň 8 znaků)' }}</span>
            <input class="input" type="password" name="password" autocomplete="new-password">
            @error('password') <div class="field-error">{{ $message }}</div> @enderror
        </label>

        <label class="field">
            <span>Oprávnění</span>
            <select class="input" name="role">
                <option value="{{ UserRole::Staff->value }}" @selected(old('role', $member->role?->value) === UserRole::Staff->value)>Kadeřnice – rozvrh, zákaznice a své nastavení</option>
                <option value="{{ UserRole::Owner->value }}" @selected(old('role', $member->role?->value) === UserRole::Owner->value)>Správce salonu – navíc ceník, kadeřnice a nastavení</option>
            </select>
            @error('role') <div class="field-error">{{ $message }}</div> @enderror
        </label>

        <button class="btn btn-primary btn-block mt-3" type="submit">{{ $isNew ? 'Přidat kadeřnici' : 'Uložit' }}</button>
    </form>

    @unless ($isNew)
        <a class="btn btn-outline btn-block mt-3" href="{{ route('admin.hours', ['kadernice' => $member->id]) }}">Pracovní doba a volno</a>
    @endunless
@endsection
