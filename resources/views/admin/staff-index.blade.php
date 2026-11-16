@extends('layouts.admin')

@section('title', 'Kadeřnice')

@section('content')
    <a class="back-link" href="{{ route('admin.more') }}">Admin</a>
    <h1 class="screen-title">Kadeřnice</h1>

    @foreach ($users as $member)
        <a class="row" href="{{ route('admin.staff.edit', $member) }}">
            <span class="h-3 w-3 flex-none rounded-full" style="background: {{ $member->color }}" aria-hidden="true"></span>
            <span class="row-main">
                <b>{{ $member->name }}</b>
                <span>
                    {{ $member->role->label() }} ·
                    {{ $member->canLogIn() ? 'přihlašuje se jako '.$member->login : 'bez vlastního přihlášení' }}@if (! $member->is_bookable) · není v rozvrhu @endif
                </span>
            </span>
            @include('partials.icon', ['name' => 'chevron', 'size' => 20, 'class' => 'text-muted'])
        </a>
    @endforeach

    <a class="btn btn-primary btn-block mt-3" href="{{ route('admin.staff.create') }}">Přidat kadeřnici</a>

    <p class="label mt-3">
        Každá kadeřnice s vlastním přihlášením si pracovní dobu, volno i soboty a neděle nastavuje sama (Více → Pracovní doba a volno).
        Jako správce ji můžete upravit komukoliv.
    </p>
@endsection
