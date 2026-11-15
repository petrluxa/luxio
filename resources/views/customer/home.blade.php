@use('App\Support\Cz')
@use('App\Enums\BookingStatus')
@extends('layouts.customer')

@section('title', 'Domů')

@section('content')
    <p class="label">{{ $salon->name }}</p>
    <h1 class="screen-title">Dobrý den</h1>

    @if ($next)
        <a href="{{ route('customer.booking.show', $next) }}" class="card block">
            <span class="label">Příští návštěva</span>
            <b class="mt-1 block text-[22px] font-semibold leading-tight">{{ Cz::dateTime($next->starts_at) }}</b>
            <span class="mt-1 block text-muted">{{ $next->servicesLabel() }} · {{ $next->staff->name }}</span>
            @if ($next->status === BookingStatus::Pending)
                <span class="tag mt-3">Čeká na potvrzení</span>
            @endif
        </a>
    @else
        <div class="empty">Zatím nemáte žádný naplánovaný termín.</div>
    @endif

    <div class="mt-4 grid gap-2">
        <a class="btn btn-primary btn-block" href="{{ route('customer.booking.create') }}">Nová rezervace</a>
        <a class="btn btn-outline btn-block" href="{{ route('customer.bookings') }}">Moje termíny</a>
    </div>

    <div class="mt-6 grid gap-2">
        <a class="row" href="{{ route('customer.profile') }}">
            <span class="row-main"><b>Profil a notifikace</b><span>{{ $customer->name }}</span></span>
            @include('partials.icon', ['name' => 'chevron', 'size' => 20, 'class' => 'text-muted'])
        </a>
        @if ($salon->phone)
            <a class="row" href="tel:{{ $salon->phone }}">
                <span class="row-main"><b>Zavolat do salonu</b><span>{{ Cz::phone($salon->phone) }}</span></span>
                @include('partials.icon', ['name' => 'phone', 'size' => 20, 'class' => 'text-muted'])
            </a>
        @endif
    </div>
@endsection
