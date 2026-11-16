@use('App\Support\Cz')
@extends('layouts.customer')

@section('title', 'Ztracený odkaz')

@section('content')
    @if ($salon)
        <p class="label">{{ $salon->name }}</p>
    @endif
    <h1 class="screen-title">Ztracený odkaz</h1>

    <div class="card">
        <p>Nový odkaz do rezervací vám pošle vaše kadeřnice – stačí jí napsat nebo zavolat. Zabere jí to chvilku.</p>
        <p class="mt-2 text-muted">Odkaz si pak přidejte na plochu telefonu, ať ho máte vždy po ruce.</p>
    </div>

    @if ($salon?->phone)
        <a class="btn btn-primary btn-block mt-4" href="tel:{{ $salon->phone }}">Zavolat do salonu · {{ Cz::phone($salon->phone) }}</a>
    @endif

    @if ($salon)
        <a class="btn btn-outline btn-block mt-2" href="{{ route('salon.show', $salon) }}">Zpět na rezervaci</a>
    @endif
@endsection
