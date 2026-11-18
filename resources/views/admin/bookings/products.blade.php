@use('App\Support\Cz')
@extends('layouts.admin')

@section('title', 'Přípravky')

@section('content')
    <a class="back-link" href="{{ route('admin.bookings.show', $booking) }}">Rezervace</a>
    <h1 class="screen-title">Přípravky</h1>

    <div class="banner">
        {{ $booking->customer->name }} · {{ $booking->servicesLabel() }}
        <span class="mt-1 block font-normal">{{ Cz::dateTime($booking->starts_at) }} · {{ $booking->staff->name }}</span>
    </div>

    @if ($booking->customer->color_recipe)
        <p class="card mt-3"><span class="label block">Receptura barvy</span>{{ $booking->customer->color_recipe }}</p>
    @endif

    {{-- Enter v poli formulář neodesílá – ukládá až tlačítko dole. --}}
    <form method="post" action="{{ route('admin.bookings.products.store', $booking) }}" novalidate autocomplete="off"
          x-data x-on:keydown.enter="if ($event.target.tagName === 'INPUT') $event.preventDefault()">
        @csrf

        <h2 class="section-title">Co jste zákaznici použila</h2>

        @include('admin.bookings.product-rows')

        <div class="bottom-bar">
            <a class="btn btn-outline" href="{{ route('admin.bookings.show', $booking) }}">Zpět</a>
            <button class="btn btn-primary" type="submit">Uložit přípravky</button>
        </div>
    </form>

    <p class="label mt-3">Přípravky se ukládají k návštěvě a uvidíte je příště v kartě zákaznice. Platba se zapisuje zvlášť ve Vyúčtování.</p>
@endsection
