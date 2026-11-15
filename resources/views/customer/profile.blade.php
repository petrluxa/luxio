@use('App\Support\Cz')
@extends('layouts.customer')

@section('title', 'Profil')

@section('content')
    <a class="back-link" href="{{ route('customer.home') }}">Zpět</a>
    <h1 class="screen-title">Profil</h1>

    <form method="post" action="{{ route('customer.profile.update') }}" novalidate>
        @csrf

        <label class="field">
            <span>Jméno a příjmení</span>
            <input class="input" type="text" name="name" value="{{ old('name', $customer->name) }}" autocomplete="name" maxlength="100" required>
            @error('name') <div class="field-error">{{ $message }}</div> @enderror
        </label>

        <label class="field">
            <span>Telefon</span>
            <input class="input" type="tel" name="phone" value="{{ old('phone', Cz::phone($customer->phone)) }}" autocomplete="tel" inputmode="tel" required>
            @error('phone') <div class="field-error">{{ $message }}</div> @enderror
        </label>

        <label class="field">
            <span>E-mail</span>
            <input class="input" type="email" name="email" value="{{ old('email', $customer->email) }}" autocomplete="email" inputmode="email" maxlength="190" required>
            @error('email') <div class="field-error">{{ $message }}</div> @enderror
        </label>

        <h2 class="section-title">Notifikace</h2>
        <label class="check">
            <input type="checkbox" name="notifications_enabled" value="1" @checked(old('notifications_enabled', $customer->notifications_enabled))>
            <span>Posílat připomínky a změny termínů</span>
        </label>

        <button class="btn btn-primary btn-block mt-4" type="submit">Uložit</button>
    </form>

    <h2 class="section-title mt-6">Aplikace v telefonu</h2>
    <a class="row" href="{{ route('customer.onboarding') }}">
        <span class="row-main"><b>Přidat na plochu a zapnout upozornění</b><span>Připomínky pak chodí rovnou na telefon</span></span>
        @include('partials.icon', ['name' => 'chevron', 'size' => 20, 'class' => 'text-muted'])
    </a>
@endsection
