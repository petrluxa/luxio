@extends('layouts.customer')

@section('title', $title)

@section('content')
    <h1 class="screen-title">{{ $title }}</h1>
    <p class="mb-4 text-muted">{{ $text }}</p>

    <a class="btn btn-outline btn-block" href="{{ url('/') }}" onclick="if (history.length > 1) { history.back(); return false; }">Zpět</a>
@endsection
