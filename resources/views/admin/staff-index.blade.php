@extends('layouts.admin')

@section('title', 'Tým')

@php
    $bookable = $users->where('is_bookable', true)->values();
@endphp

@section('content')
    <a class="back-link" href="{{ route('admin.more') }}">Admin</a>
    <h1 class="screen-title">Tým</h1>

    <p class="label mb-3">Pořadí odpovídá sloupcům v rozvrhu – šipkami ho změníte. Zaměření dělí rozvrh na skupiny.</p>

    @foreach ($users as $member)
        <div class="row">
            <span class="h-3 w-3 flex-none rounded-full" style="background: {{ $member->color }}" aria-hidden="true"></span>
            <a class="row-main" href="{{ route('admin.staff.edit', $member) }}">
                <b>{{ $member->name }}@if ($member->position) <span class="inline font-normal text-muted">· {{ $member->position }}</span>@endif</b>
                <span>
                    {{ $member->role->label() }} ·
                    {{ $member->canLogIn() ? 'přihlašuje se jako '.$member->login : 'bez vlastního přihlášení' }}@if (! $member->is_bookable) · není v rozvrhu @endif
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

    <a class="btn btn-primary btn-block mt-3" href="{{ route('admin.staff.create') }}">Přidat do týmu</a>

    <p class="label mt-3">
        Kdo má vlastní přihlášení, nastavuje si pracovní dobu, volno i soboty a neděle sám (Admin → Pracovní doba a volno)
        a svůj ceník (Admin → Ceník služeb). Jako správce je můžete upravit komukoliv.
    </p>
@endsection
