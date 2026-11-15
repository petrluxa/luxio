@use('App\Support\Cz')
@extends('layouts.customer')

@section('title', 'Rezervace odeslána')

@section('content')
    <p class="label">{{ $salon->name }}</p>
    <h1 class="screen-title">Rezervace je odeslaná</h1>

    <div class="banner">Čeká na potvrzení kadeřnicí.</div>

    <div class="card mt-3">
        <p>Na váš e-mail jsme poslali odkaz, přes který uvidíte své termíny. Jakmile kadeřnice rezervaci potvrdí, dáme vám vědět.</p>
        <p class="mt-2 text-muted">E-mail nepřišel? Podívejte se do nevyžádané pošty.</p>
    </div>

    @if ($salon->phone)
        <a class="btn btn-outline btn-block mt-4" href="tel:{{ $salon->phone }}">Zavolat do salonu · {{ Cz::phone($salon->phone) }}</a>
    @endif
@endsection
