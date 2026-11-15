@use('App\Support\Cz')
@extends('layouts.customer')

@section('title', 'Rezervace')

@section('content')
    <p class="label">{{ $salon->name }}</p>
    <h1 class="screen-title">Nová rezervace</h1>

    @if (empty($wizard['services']))
        <div class="empty">
            Online rezervace teď nejsou otevřené.
            @if ($salon->phone) Zavolejte prosím na {{ Cz::phone($salon->phone) }}. @endif
        </div>
    @else
        @include('partials.booking-wizard', [
            'action' => route('salon.store', $salon),
            'replaces' => null,
            'withContact' => true,
            'secondaryUrl' => route('recover'),
            'secondaryLabel' => 'Už mám účet',
            'submitLabel' => 'Rezervovat',
        ])
    @endif
@endsection
