@use('App\Support\Cz')
@extends('layouts.admin')

@section('title', 'Ke schválení')

@section('content')
    <h1 class="screen-title">Ke schválení</h1>

    @forelse ($bookings as $booking)
        <article class="card @if (! $loop->first) mt-2 @endif">
            <a href="{{ route('admin.bookings.show', $booking) }}" class="block">
                <b class="block text-[17px] font-semibold">{{ $booking->customer->name }}</b>
                <span class="mt-0.5 block">{{ Cz::dateTime($booking->starts_at) }} · {{ $booking->servicesLabel() }}</span>
                <span class="label mt-0.5 block">
                    {{ Cz::duration($booking->durationMinutes()) }} · {{ $booking->priceLabel() }} · {{ $booking->staff->name }}
                    @if (! $booking->customer->is_verified) · nová zákaznice @endif
                </span>
                @if ($booking->customer_note)
                    <span class="mt-2 block border-t border-line pt-2 text-[14px]">„{{ $booking->customer_note }}“</span>
                @endif
            </a>

            <div class="mt-3 flex gap-2" x-data="{ rejecting: false }">
                <form method="post" action="{{ route('admin.bookings.approve', $booking) }}" class="flex flex-1" x-show="!rejecting">
                    @csrf
                    <button class="btn btn-primary btn-sm flex-1" type="submit">Schválit</button>
                </form>
                <button type="button" class="btn btn-danger btn-sm flex-1" x-show="!rejecting" x-on:click="rejecting = true">Zamítnout</button>

                <form method="post" action="{{ route('admin.bookings.reject', $booking) }}" class="w-full" x-show="rejecting" x-cloak>
                    @csrf
                    <label class="field">
                        <span>Důvod pro zákaznici (nepovinné)</span>
                        <input class="input" type="text" name="reason" maxlength="255" placeholder="Např. v tomto termínu už mám plno">
                    </label>
                    <div class="flex gap-2">
                        <button type="button" class="btn btn-quiet btn-sm flex-1" x-on:click="rejecting = false">Zpět</button>
                        <button type="submit" class="btn btn-danger btn-sm flex-1">Zamítnout</button>
                    </div>
                </form>
            </div>
        </article>
    @empty
        <div class="empty">Nic nečeká na schválení.</div>
    @endforelse
@endsection
