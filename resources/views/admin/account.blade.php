@extends('layouts.admin')

@section('title', 'Můj účet')

@section('content')
    <a class="back-link" href="{{ route('admin.more') }}">Admin</a>
    <h1 class="screen-title">Můj účet</h1>

    <div class="card">
        <b class="block text-[17px] font-semibold">{{ auth()->user()->name }}</b>
        <span class="label">{{ auth()->user()->role->label() }} · přihlašovací jméno {{ auth()->user()->login }}</span>
    </div>

    {{-- Změna hesla --}}
    <h2 class="section-title mt-6">Změna hesla</h2>
    <form method="post" action="{{ route('admin.password') }}" novalidate>
        @csrf
        <label class="field">
            <span>Současné heslo</span>
            <input class="input" type="password" name="current_password" autocomplete="current-password" required>
            @error('current_password') <div class="field-error">{{ $message }}</div> @enderror
        </label>
        <label class="field">
            <span>Nové heslo (aspoň 8 znaků)</span>
            <input class="input" type="password" name="password" autocomplete="new-password" required>
            @error('password') <div class="field-error">{{ $message }}</div> @enderror
        </label>
        <label class="field">
            <span>Nové heslo znovu</span>
            <input class="input" type="password" name="password_confirmation" autocomplete="new-password" required>
        </label>
        <button class="btn btn-outline btn-block" type="submit">Změnit heslo</button>
    </form>

    @include('admin.partials.push')

    <form method="post" action="{{ route('admin.logout') }}" class="mt-6">
        @csrf
        <button class="btn btn-quiet btn-block" type="submit">Odhlásit se</button>
    </form>
@endsection
