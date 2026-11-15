@extends('layouts.customer')

@section('title', 'Moje rezervace')

@section('content')
    <p class="label">{{ $salon->name }}</p>
    <h1 class="screen-title">Vaše rezervace</h1>
    <p class="mb-4 text-muted">Pokračujte do svých termínů. Aplikaci si potom uložte na plochu telefonu, ať ji máte příště po ruce.</p>

    <form method="post" action="{{ $action }}">
        @csrf
        <button class="btn btn-primary btn-block" type="submit">Pokračovat</button>
    </form>
@endsection
