@extends('layouts.admin')

@section('title', 'Kadeřnice')

@php
    $bookable = $users->where('is_bookable', true)->values();
@endphp

@section('content')
    <a class="back-link" href="{{ route('admin.more') }}">Admin</a>
    <h1 class="screen-title">Kadeřnice</h1>

    <p class="label mb-3">
        Ťuknutím na jméno otevřete její nastavení: přihlášení, pracovní dobu, ceník a rozvrh.
        Pořadí odpovídá sloupcům v rozvrhu – šipkami ho změníte.
    </p>

    @foreach ($users as $member)
        <div class="row">
            <span class="h-3 w-3 flex-none rounded-full" style="background: {{ $member->color }}" aria-hidden="true"></span>
            <a class="row-main" href="{{ route('admin.staff.edit', $member) }}">
                <b>{{ $member->name }}@if ($member->position) <span class="inline font-normal text-muted">· {{ $member->position }}</span>@endif</b>
                <span>
                    {{ $member->canLogIn() ? 'přihlašuje se jako '.$member->login : 'bez vlastního přihlášení' }} ·
                    {{ $member->isOwner() ? 'správce – vidí celý salon' : 'vidí jen svůj rozvrh' }}@if (! $member->is_bookable) · nemá sloupec v rozvrhu @endif
                </span>
            </a>
            @if ($member->is_bookable && $bookable->count() > 1)
                <form method="post" action="{{ route('admin.staff.reorder', $member) }}" class="flex flex-none gap-1">
                    @csrf
                    <button class="btn btn-quiet btn-sm w-11 px-0" type="submit" name="direction" value="up" @disabled($bookable->first()->is($member)) aria-label="Posunout {{ $member->name }} v rozvrhu dopředu">↑</button>
                    <button class="btn btn-quiet btn-sm w-11 px-0" type="submit" name="direction" value="down" @disabled($bookable->last()->is($member)) aria-label="Posunout {{ $member->name }} v rozvrhu dozadu">↓</button>
                </form>
            @endif
        </div>
    @endforeach

    <a class="btn btn-primary btn-block mt-3" href="{{ route('admin.staff.create') }}">Přidat kadeřnici nebo recepci</a>

    <p class="label mt-3">
        Kadeřnice se svým jménem a heslem vidí jen svůj rozvrh a své zákaznice a sama si nastavuje pracovní dobu a ceník.
        Celý salon vidí jen správce – majitelka a recepce. Jako správce můžete nastavení upravit komukoliv.
    </p>
@endsection
