@use('App\Support\Cz')
@extends('layouts.admin')

@section('title', 'Přesun termínu')

@section('content')
    <a class="back-link" href="{{ route('admin.bookings.show', $booking) }}">Zpět</a>
    <h1 class="screen-title">Přesun termínu</h1>

    <div class="banner">
        {{ $booking->customer->name }} · {{ $booking->servicesLabel() }}
        <span class="mt-1 block font-normal">teď {{ Cz::dateTime($booking->starts_at) }} ({{ Cz::duration($booking->durationMinutes()) }})</span>
    </div>

    @include('partials.booking-wizard', [
        'action' => route('admin.bookings.move.store', $booking),
        'replaces' => null,
        'secondaryUrl' => route('admin.bookings.show', $booking),
        'secondaryLabel' => 'Zrušit',
        'submitLabel' => 'Přesunout',
    ])

    <p class="label mt-3">Zákaznice dostane upozornění o novém termínu.</p>
@endsection
