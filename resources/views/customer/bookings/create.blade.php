@use('App\Support\Cz')
@extends('layouts.customer')

@section('title', $replaces ? 'Přesun termínu' : 'Nová rezervace')

@section('content')
    {{-- Na novou rezervaci se stálá zákaznice dostane i rovnou z odkazu salonu – „Zpět“ by tam nedávalo
         smysl, proto odkaz říká, kam vede. --}}
    @if ($replaces)
        <a class="back-link" href="{{ route('customer.booking.show', $replaces) }}">‹ Zpět na termín</a>
    @else
        <a class="back-link" href="{{ route('customer.home') }}">‹ Můj účet a termíny</a>
    @endif
    <h1 class="screen-title">{{ $replaces ? 'Přesun termínu' : 'Nová rezervace' }}</h1>

    @if ($customer->is_blocked)
        <div class="banner banner-danger">
            Online rezervace teď pro vás není dostupná.
            @if ($salon->phone) Zavolejte prosím na <a class="underline" href="tel:{{ $salon->phone }}">{{ Cz::phone($salon->phone) }}</a>. @endif
        </div>
    @else
        @if ($replaces)
            <div class="banner">Přesouváte termín: {{ Cz::dateTime($replaces->starts_at) }}</div>
        @elseif ($next)
            <div class="banner">Příští návštěva: {{ Cz::dateTime($next->starts_at) }}</div>
        @endif

        @include('partials.booking-wizard', [
            'action' => route('customer.booking.store'),
            'secondaryUrl' => route('customer.bookings'),
            'secondaryLabel' => 'Moje termíny',
            'submitLabel' => $replaces ? 'Přesunout' : 'Rezervovat',
        ])
    @endif
@endsection
