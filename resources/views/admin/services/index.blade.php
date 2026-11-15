@extends('layouts.admin')

@section('title', 'Služby')

@section('content')
    <a class="back-link" href="{{ route('admin.more') }}">Více</a>
    <h1 class="screen-title">Služby</h1>

    @forelse ($services as $service)
        <div class="row @if (! $service->is_active) opacity-60 @endif">
            <a class="row-main" href="{{ route('admin.services.edit', $service) }}">
                <b>{{ $service->name }}</b>
                <span>
                    {{ $service->durationLabel() }} · {{ $service->priceLabel() }}
                    @if ($service->pause > 0) · pauza {{ $service->pause }} min @endif
                </span>
                <span>
                    @if (! $service->is_active) skrytá v nabídce
                    @elseif (! in_array($service->id, $mine, true)) nedělám
                    @endif
                </span>
            </a>
            @if ($canEdit)
                <form method="post" action="{{ route('admin.services.reorder', $service) }}" class="flex flex-none gap-1">
                    @csrf
                    <button class="btn btn-quiet btn-sm w-11 px-0" type="submit" name="direction" value="up" @disabled($loop->first) aria-label="Posunout {{ $service->name }} nahoru">↑</button>
                    <button class="btn btn-quiet btn-sm w-11 px-0" type="submit" name="direction" value="down" @disabled($loop->last) aria-label="Posunout {{ $service->name }} dolů">↓</button>
                </form>
            @endif
        </div>
    @empty
        <div class="empty">Ceník je zatím prázdný.</div>
    @endforelse

    @if ($canEdit)
        <a class="btn btn-primary btn-block mt-4" href="{{ route('admin.services.create') }}">Přidat službu</a>
    @endif
@endsection
