@use('App\Support\Cz')
@use('App\Enums\BookingStatus')
@extends('layouts.customer')

@section('title', 'Moje termíny')

@section('content')
    <a class="back-link" href="{{ route('customer.home') }}">Zpět</a>
    <h1 class="screen-title">Moje termíny</h1>

    <h2 class="section-title">Nadcházející</h2>
    @forelse ($upcoming as $booking)
        <a class="row" href="{{ route('customer.booking.show', $booking) }}">
            <span class="row-main">
                <b>{{ Cz::dateTime($booking->starts_at) }}</b>
                <span>{{ $booking->servicesLabel() }} · {{ $booking->staff->name }}</span>
            </span>
            @if ($booking->status === BookingStatus::Pending)
                <span class="tag">Čeká</span>
            @endif
            @include('partials.icon', ['name' => 'chevron', 'size' => 20, 'class' => 'text-muted'])
        </a>
    @empty
        <div class="empty">Žádný naplánovaný termín.</div>
    @endforelse

    @if ($history->isNotEmpty())
        <h2 class="section-title mt-6">Historie</h2>
        @foreach ($history as $booking)
            <a class="row" href="{{ route('customer.booking.show', $booking) }}">
                <span class="row-main">
                    <b class="font-normal">{{ Cz::date($booking->starts_at) }} {{ $booking->starts_at->year }}</b>
                    <span>{{ $booking->servicesLabel() }} · {{ $booking->staff->name }}</span>
                </span>
                @if ($booking->status->isCancelled())
                    <span class="tag tag-muted">Zrušeno</span>
                @elseif ($booking->status === BookingStatus::NoShow)
                    <span class="tag tag-muted">Nepřišla</span>
                @endif
            </a>
        @endforeach
    @endif

    <div class="bottom-bar">
        <a class="btn btn-outline" href="{{ route('customer.home') }}">Domů</a>
        <a class="btn btn-primary" href="{{ route('customer.booking.create') }}">Nová rezervace</a>
    </div>
@endsection
