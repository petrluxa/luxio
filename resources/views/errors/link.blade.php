@extends('layouts.customer')

@section('title', 'Odkaz neplatí')

@section('content')
    <h1 class="screen-title">Odkaz neplatí</h1>
    <p class="mb-4 text-muted">Tento odkaz se nepodařilo otevřít. Zkuste to prosím za chvíli znovu, nebo si nechte poslat nový.</p>

    <a class="btn btn-primary btn-block" href="{{ route('recover') }}">Poslat nový odkaz</a>
@endsection
