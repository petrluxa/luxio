@use('App\Support\Cz')
@use('App\Enums\BookingStatus')
@use('App\Enums\BookingSource')
@use('App\Enums\SegmentType')
@extends('layouts.admin')

@section('title', 'Rezervace')

@section('content')
    <a class="back-link" href="{{ route('admin.calendar', ['datum' => $booking->starts_at->toDateString(), 'kadernice' => $booking->staff_id]) }}">Kalendář</a>
    <h1 class="screen-title">{{ Cz::dateTime($booking->starts_at) }}</h1>

    @if ($booking->status === BookingStatus::Pending)
        <div class="banner mb-3">Čeká na schválení</div>
    @elseif ($booking->status !== BookingStatus::Confirmed)
        <div class="banner @if ($booking->status->isCancelled() || $booking->status === BookingStatus::NoShow) banner-danger @endif mb-3">
            {{ $booking->status->label() }}@if ($booking->cancel_reason): {{ $booking->cancel_reason }}@endif
        </div>
    @endif

    {{-- Zákaznice --}}
    <a class="row" href="{{ route('admin.customers.show', $booking->customer) }}">
        <span class="row-main">
            <b>{{ $booking->customer->name }}</b>
            <span>{{ Cz::phone($booking->customer->phone) ?: 'bez telefonu' }}@if ($booking->customer->is_blocked) · zablokovaná @endif</span>
        </span>
        @include('partials.icon', ['name' => 'chevron', 'size' => 20, 'class' => 'text-muted'])
    </a>

    @if ($booking->customer->phone)
        <a class="btn btn-outline btn-block mt-2" href="tel:{{ $booking->customer->phone }}">Zavolat</a>
    @endif

    {{-- Služby a čas --}}
    <div class="card mt-3">
        @foreach ($booking->items as $item)
            <div class="flex items-baseline justify-between gap-3 @if (! $loop->first) mt-2 @endif">
                <b class="font-semibold">{{ $item->name }}</b>
                <span class="whitespace-nowrap text-muted">{{ Cz::price($item->price, $item->price_to) }}</span>
            </div>
        @endforeach

        <dl class="mt-3 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 border-t border-line pt-3">
            <dt class="text-muted">Čas</dt>
            <dd>{{ Cz::time($booking->starts_at) }}–{{ Cz::time($booking->ends_at) }} ({{ Cz::duration($booking->durationMinutes()) }})</dd>
            @foreach ($booking->segments->where('type', SegmentType::Pause) as $pause)
                <dt class="text-muted">Působí barva</dt>
                <dd>{{ Cz::time($pause->starts_at) }}–{{ Cz::time($pause->ends_at) }} (volné ruce)</dd>
            @endforeach
            <dt class="text-muted">Kadeřnice</dt><dd>{{ $booking->staff->name }}</dd>
            <dt class="text-muted">Cena</dt><dd>{{ $booking->priceLabel() }}</dd>
            <dt class="text-muted">Vznikla</dt>
            <dd>{{ $booking->source === BookingSource::Manual ? 'ručně v administraci' : 'online' }}, {{ Cz::date($booking->created_at) }}</dd>
            @if ($booking->customer_note)
                <dt class="text-muted">Od zákaznice</dt><dd>„{{ $booking->customer_note }}“</dd>
            @endif
        </dl>

        @if ($booking->customer->color_recipe)
            <p class="mt-3 border-t border-line pt-3"><span class="label block">Receptura barvy</span>{{ $booking->customer->color_recipe }}</p>
        @endif
    </div>

    {{-- Poznámka k návštěvě --}}
    <form method="post" action="{{ route('admin.bookings.note', $booking) }}" class="mt-3" id="poznamka">
        @csrf
        <label class="field">
            <span>Poznámka k návštěvě (vidíte jen vy)</span>
            <textarea class="input" name="staff_note" maxlength="2000" rows="3">{{ old('staff_note', $booking->staff_note) }}</textarea>
            @error('staff_note') <div class="field-error">{{ $message }}</div> @enderror
        </label>
        <button class="btn btn-outline btn-sm btn-block" type="submit">Uložit poznámku</button>
    </form>

    {{-- Akce --}}
    @if ($booking->status === BookingStatus::Pending)
        <h2 class="section-title">Schválení</h2>
        <form method="post" action="{{ route('admin.bookings.approve', $booking) }}">
            @csrf
            <button class="btn btn-primary btn-block" type="submit">Schválit</button>
        </form>
    @endif

    @if ($booking->status->isActive())
        <h2 class="section-title">Změna termínu</h2>
        <div class="grid gap-2" x-data="{ cancelling: false }">
            <a class="btn btn-outline btn-block" href="{{ route('admin.bookings.move', $booking) }}">Přesunout</a>

            @if ($booking->status === BookingStatus::Confirmed)
                <form method="post" action="{{ route('admin.bookings.no-show', $booking) }}">
                    @csrf
                    <button class="btn btn-quiet btn-block" type="submit">Nepřišla</button>
                </form>
            @endif

            <button type="button" class="btn btn-danger btn-block" x-show="!cancelling" x-on:click="cancelling = true">
                {{ $booking->status === BookingStatus::Pending ? 'Zamítnout' : 'Zrušit rezervaci' }}
            </button>

            <form method="post" class="card" x-show="cancelling" x-cloak
                  action="{{ $booking->status === BookingStatus::Pending ? route('admin.bookings.reject', $booking) : route('admin.bookings.cancel', $booking) }}">
                @csrf
                <label class="field">
                    <span>Důvod pro zákaznici (nepovinné)</span>
                    <input class="input" type="text" name="reason" maxlength="255" placeholder="Např. nemoc – ozvu se s novým termínem">
                </label>
                <div class="flex gap-2">
                    <button type="button" class="btn btn-quiet flex-1" x-on:click="cancelling = false">Zpět</button>
                    <button type="submit" class="btn btn-danger flex-1">{{ $booking->status === BookingStatus::Pending ? 'Zamítnout' : 'Zrušit' }}</button>
                </div>
            </form>
        </div>
    @elseif ($booking->status === BookingStatus::NoShow)
        <form method="post" action="{{ route('admin.bookings.complete', $booking) }}" class="mt-4">
            @csrf
            <button class="btn btn-outline btn-block" type="submit">Přece jen přišla</button>
        </form>
    @endif
@endsection
