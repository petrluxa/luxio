@use('App\Enums\UserRole')
@extends('layouts.admin')

@php($isNew = ! $member->exists)

@section('title', $isNew ? 'Nová kadeřnice' : $member->name)

@section('content')
    <a class="back-link" href="{{ route('admin.settings') }}">Nastavení</a>
    <h1 class="screen-title">{{ $isNew ? 'Nová kadeřnice' : $member->name }}</h1>

    <form method="post" action="{{ $isNew ? route('admin.staff.store') : route('admin.staff.update', $member) }}" novalidate>
        @csrf

        <label class="field">
            <span>Jméno (uvidí ho zákaznice)</span>
            <input class="input" type="text" name="name" value="{{ old('name', $member->name) }}" maxlength="60" required>
            @error('name') <div class="field-error">{{ $message }}</div> @enderror
        </label>

        <label class="field">
            <span>E-mail (přihlašovací)</span>
            <input class="input" type="email" name="email" value="{{ old('email', $member->email) }}" inputmode="email" autocomplete="off" maxlength="190" required>
            @error('email') <div class="field-error">{{ $message }}</div> @enderror
        </label>

        <label class="field">
            <span>{{ $isNew ? 'Heslo (aspoň 8 znaků)' : 'Nové heslo (nechte prázdné, pokud se nemění)' }}</span>
            <input class="input" type="password" name="password" autocomplete="new-password" @if ($isNew) required @endif>
            @error('password') <div class="field-error">{{ $message }}</div> @enderror
        </label>

        <label class="field">
            <span>Role</span>
            <select class="input" name="role">
                @foreach (UserRole::cases() as $role)
                    <option value="{{ $role->value }}" @selected(old('role', $member->role?->value) === $role->value)>{{ $role->label() }}</option>
                @endforeach
            </select>
            @error('role') <div class="field-error">{{ $message }}</div> @enderror
        </label>

        <label class="field">
            <span>Barva v kalendáři</span>
            <input class="input h-12 p-1" type="color" name="color" value="{{ old('color', $member->color) }}">
            @error('color') <div class="field-error">{{ $message }}</div> @enderror
        </label>

        <label class="check">
            <input type="checkbox" name="is_bookable" value="1" @checked(old('is_bookable', $member->is_bookable))>
            <span>Přijímá rezervace (zákaznice si ji mohou vybrat)</span>
        </label>

        <button class="btn btn-primary btn-block mt-3" type="submit">{{ $isNew ? 'Přidat kadeřnici' : 'Uložit' }}</button>
    </form>

    @unless ($isNew)
        <a class="btn btn-outline btn-block mt-3" href="{{ route('admin.hours', ['kadernice' => $member->id]) }}">Pracovní doba a volno</a>
    @endunless
@endsection
