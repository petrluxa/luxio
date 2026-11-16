@extends('layouts.admin')

@section('title', 'Nová rezervace')

@section('content')
    <a class="back-link" href="{{ route('admin.calendar') }}">Rozvrh</a>
    <h1 class="screen-title">Nová rezervace</h1>

    @if (empty($wizard['services']))
        <div class="empty">
            Nejdřív si v ceníku nastavte služby a pracovní dobu.
            <a class="btn btn-outline btn-sm mt-3" href="{{ route('admin.services.index') }}">Služby</a>
        </div>
    @else
        @include('partials.booking-wizard', [
            'action' => route('admin.bookings.store'),
            'replaces' => null,
            'customerPicker' => true,
            'noteLabel' => 'Poznámka k návštěvě (vidíte jen vy)',
            'secondaryUrl' => route('admin.calendar'),
            'secondaryLabel' => 'Zrušit',
            'submitLabel' => 'Vytvořit',
        ])
    @endif
@endsection
