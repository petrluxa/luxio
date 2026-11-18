@use('App\Support\Cz')
@use('App\Enums\BookingStatus')
@extends('layouts.admin')

@section('title', 'Můj den')

@section('content')
    <p class="label">{{ Cz::dateLong($today) }}</p>
    <h1 class="screen-title">Můj den</h1>

    @include('admin.partials.staff-switch', ['route' => 'admin.today'])

    @if ($holiday)
        <div class="banner mb-3">Státní svátek: {{ $holiday }}</div>
    @endif

    @if ($upNext)
        <section class="card" aria-labelledby="up-next">
            <span class="label" id="up-next">{{ $upNext->starts_at->isPast() ? 'Právě teď' : 'Další zákaznice' }}</span>
            <a href="{{ route('admin.bookings.show', $upNext) }}" class="mt-1 block">
                <b class="block text-[22px] font-semibold leading-tight">{{ Cz::time($upNext->starts_at) }} · {{ $upNext->customer->name }}</b>
                <span class="mt-1 block text-muted">{{ $upNext->servicesLabel() }} · do {{ Cz::time($upNext->ends_at) }}</span>
            </a>
            @if ($upNext->customer->color_recipe)
                <p class="mt-2 border-t border-line pt-2 text-[14px]"><span class="label">Receptura:</span> {{ $upNext->customer->color_recipe }}</p>
            @endif
            @if ($upNext->staff_note)
                <p class="mt-2 border-t border-line pt-2 text-[14px]"><span class="label">Poznámka:</span> {{ $upNext->staff_note }}</p>
            @endif

            {{-- Rychlé akce --}}
            <div class="mt-3 flex gap-2">
                @if ($upNext->customer->phone)
                    <a class="btn btn-outline btn-sm flex-1" href="tel:{{ $upNext->customer->phone }}">Zavolat</a>
                @endif
                <a class="btn btn-outline btn-sm flex-1" href="{{ route('admin.bookings.show', $upNext) }}#poznamka">Poznámka</a>
                <form method="post" action="{{ route('admin.bookings.no-show', $upNext) }}" class="flex flex-1" x-data="{ sure: false }"
                      x-on:submit="if (!sure) { $event.preventDefault(); sure = true; setTimeout(() => sure = false, 4000) }">
                    @csrf
                    <button class="btn btn-quiet btn-sm flex-1" type="submit" x-text="sure ? 'Opravdu?' : 'Nepřišla'">Nepřišla</button>
                </form>
            </div>
        </section>
    @endif

    <div class="flex items-baseline justify-between">
        <h2 class="section-title">Časová osa</h2>
        <span class="label">{{ $bookings->count() }} {{ match (true) { $bookings->count() === 1 => 'rezervace', $bookings->count() >= 2 && $bookings->count() <= 4 => 'rezervace', default => 'rezervací' } }}</span>
    </div>

    @forelse ($bookings as $booking)
        @include('admin.partials.booking-row', ['dim' => $booking->ends_at->isPast()])
    @empty
        <div class="empty">Dnes nemáte žádnou rezervaci.</div>
    @endforelse

    <a class="btn btn-primary btn-block mt-4" href="{{ route('admin.bookings.create', ['datum' => $today->toDateString(), 'kadernice' => $staff->id]) }}">Nová rezervace</a>
@endsection
