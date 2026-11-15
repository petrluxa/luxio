@use('App\Support\Cz')
@use('App\Enums\BookingStatus')
@extends('layouts.customer')

@section('title', 'Aplikace v telefonu')

@section('content')
    @if ($booking)
        <p class="label">{{ $salon->name }}</p>
        <h1 class="screen-title">{{ $booking->status === BookingStatus::Pending ? 'Rezervace je odeslaná' : 'Termín je rezervovaný' }}</h1>

        <div class="banner">
            {{ Cz::dateTime($booking->starts_at) }} · {{ $booking->servicesLabel() }}
            @if ($booking->status === BookingStatus::Pending)
                <span class="mt-1 block font-normal">Čeká na potvrzení kadeřnicí – dáme vám vědět.</span>
            @endif
        </div>

        <h2 class="section-title mt-6">Uložte si aplikaci na plochu</h2>
    @else
        <a class="back-link" href="{{ route('customer.home') }}">Zpět</a>
        <h1 class="screen-title">Aplikace v telefonu</h1>
    @endif

    <p class="mb-3 text-muted">Příště se objednáte na pár ťuknutí a připomínka termínu vám přijde rovnou na telefon. Bez hesla, bez SMS.</p>

    @include('partials.install-steps', ['pushConfig' => ['vapidKey' => $vapidPublicKey, 'subscribeUrl' => route('customer.push.store')]])

    <div class="mt-6">
        <a class="btn {{ $booking ? 'btn-primary' : 'btn-outline' }} btn-block" href="{{ route('customer.home') }}">{{ $booking ? 'Pokračovat' : 'Zpět domů' }}</a>
    </div>
@endsection
