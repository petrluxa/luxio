@use('App\Support\Cz')
@extends('layouts.customer')

@section('title', 'Rezervace odeslána')

@section('content')
    <p class="label">{{ $salon->name }}</p>
    <h1 class="screen-title">Rezervace je odeslaná</h1>

    <div class="banner">Čeká na potvrzení kadeřnicí.</div>

    <div class="card mt-3">
        <p>Toto telefonní číslo už u nás máme. Kadeřnice se vám proto ozve a pošle vám váš osobní odkaz – přes něj své termíny uvidíte a můžete je měnit.</p>
        <p class="mt-2 text-muted">Odkaz si pak přidejte na plochu telefonu, ať ho máte vždy po ruce.</p>
    </div>

    @if ($salon->phone)
        <a class="btn btn-outline btn-block mt-4" href="tel:{{ $salon->phone }}">Zavolat do salonu · {{ Cz::phone($salon->phone) }}</a>
    @endif
@endsection
