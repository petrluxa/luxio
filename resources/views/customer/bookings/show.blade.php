@use('App\Support\Cz')
@use('App\Enums\BookingStatus')
@extends('layouts.customer')

@section('title', 'Termín')

@section('content')
    <a class="back-link" href="{{ route('customer.bookings') }}">Zpět</a>
    <h1 class="screen-title">{{ Cz::dateTime($booking->starts_at) }}</h1>

    @if ($booking->status === BookingStatus::Pending)
        <div class="banner">Čeká na potvrzení kadeřnicí – dáme vám vědět.</div>
    @elseif ($booking->status->isCancelled())
        <div class="banner banner-danger">
            {{ $booking->status->label() }}@if ($booking->cancel_reason): {{ $booking->cancel_reason }}@endif
        </div>
    @endif

    <div class="card mt-3">
        @foreach ($booking->items as $item)
            <div class="flex items-baseline justify-between gap-3 @if (! $loop->first) mt-2 @endif">
                <b class="font-semibold">{{ $item->name }}</b>
                <span class="whitespace-nowrap text-muted">{{ Cz::price($item->price, $item->price_to) }}</span>
            </div>
        @endforeach

        <dl class="mt-3 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 border-t border-line pt-3 text-[15px]">
            <dt class="text-muted">Kadeřnice</dt><dd>{{ $booking->staff->name }}</dd>
            <dt class="text-muted">Čas</dt><dd>{{ Cz::time($booking->starts_at) }}–{{ Cz::time($booking->ends_at) }} ({{ Cz::duration($booking->durationMinutes()) }})</dd>
            <dt class="text-muted">Cena</dt><dd>{{ $booking->priceLabel() }}</dd>
            @if ($booking->salon->address)
                <dt class="text-muted">Kde</dt><dd>{{ $booking->salon->name }}, {{ $booking->salon->address }}</dd>
            @endif
            @if ($booking->customer_note)
                <dt class="text-muted">Poznámka</dt><dd>{{ $booking->customer_note }}</dd>
            @endif
        </dl>
    </div>

    @if ($booking->status->isActive() && ! $booking->isPast())
        @if ($booking->canBeCancelledByCustomer())
            <div class="mt-4 grid gap-2" x-data="{ asking: false }">
                <a class="btn btn-outline btn-block" href="{{ route('customer.booking.create', ['presun' => $booking->id]) }}">Přesunout na jiný termín</a>

                <button type="button" class="btn btn-danger btn-block" x-show="!asking" x-on:click="asking = true">Zrušit termín</button>

                <form method="post" action="{{ route('customer.booking.cancel', $booking) }}" class="card" x-show="asking" x-cloak>
                    @csrf
                    <p class="mb-3 text-center font-semibold">Opravdu chcete termín zrušit?</p>
                    <div class="flex gap-2">
                        <button type="button" class="btn btn-quiet flex-1" x-on:click="asking = false">Ne, nechat</button>
                        <button type="submit" class="btn btn-danger flex-1">Ano, zrušit</button>
                    </div>
                </form>
            </div>
        @else
            <div class="card mt-4 text-center">
                <p class="text-muted">Do termínu zbývá méně než {{ $booking->salon->rules()->cancelHours }} hodin.</p>
                <p class="mt-1 font-semibold">Pro zrušení zavolejte</p>
                @if ($booking->salon->phone)
                    <a class="btn btn-outline btn-block mt-3" href="tel:{{ $booking->salon->phone }}">{{ Cz::phone($booking->salon->phone) }}</a>
                @endif
            </div>
        @endif
    @endif
@endsection
