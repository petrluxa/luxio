@use('App\Support\Cz')
@use('App\Enums\PaymentMethod')
@extends('layouts.admin')

@section('title', 'Vyúčtování')

@php
    $method = old('method', $booking->payment_method?->value ?? PaymentMethod::Cash->value);
@endphp

@section('content')
    <a class="back-link" href="{{ route('admin.bookings.show', $booking) }}">Rezervace</a>
    <h1 class="screen-title">Vyúčtování</h1>

    <div class="banner">
        {{ $booking->customer->name }} · {{ $booking->servicesLabel() }}
        <span class="mt-1 block font-normal">{{ Cz::dateTime($booking->starts_at) }} · {{ $booking->staff->name }}</span>
    </div>

    {{-- Enter v poli formulář neodesílá – o platbě rozhoduje až tlačítko dole. --}}
    <form method="post" action="{{ route('admin.bookings.checkout.store', $booking) }}" novalidate autocomplete="off"
          x-data="{ method: @js($method) }"
          x-on:keydown.enter="if ($event.target.tagName === 'INPUT') $event.preventDefault()">
        @csrf

        <h2 class="section-title">Použité přípravky</h2>

        @include('admin.bookings.product-rows')

        <h2 class="section-title mt-6">Platba</h2>

        <label class="field">
            <span>Zákaznice zaplatí (Kč) – podle ceníku {{ $booking->priceLabel() }}</span>
            <input class="input text-[20px] font-semibold" type="number" name="amount" inputmode="numeric" min="0" max="1000000" step="1"
                   value="{{ old('amount', $booking->paid_amount ?? $booking->total_price) }}">
            @error('amount') <div class="field-error">{{ $message }}</div> @enderror
        </label>

        <input type="hidden" name="method" :value="method">
        <div class="chips" role="radiogroup" aria-label="Způsob platby">
            @foreach (PaymentMethod::cases() as $case)
                <button type="button" class="chip" role="radio" :aria-checked="method === @js($case->value)" x-on:click="method = @js($case->value)">{{ mb_convert_case($case->label(), MB_CASE_TITLE) }}</button>
            @endforeach
        </div>

        <div class="bottom-bar">
            @if ($booking->isPaid())
                <a class="btn btn-outline" href="{{ route('admin.bookings.show', $booking) }}">Zpět</a>
                <button class="btn btn-primary" type="submit">Uložit</button>
            @else
                <button class="btn btn-outline" type="submit" name="only_products" value="1">Jen přípravky</button>
                <button class="btn btn-primary" type="submit">Zaplaceno</button>
            @endif
        </div>
    </form>

    <p class="label mt-3">
        „Zaplaceno“ vystaví doklad, který pak zákaznici pošlete přes WhatsApp. „Jen přípravky“ uloží záznam bez platby.
    </p>
@endsection
