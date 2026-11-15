@extends('layouts.customer')

@section('content')
    <h1 class="screen-title">{{ config('app.name') }}</h1>
    <p class="mb-4 text-muted">Rezervace u vaší kadeřnice. Otevřete prosím odkaz, který jste dostali od svého salonu.</p>

    <div class="grid gap-2">
        <a class="btn btn-primary btn-block" href="{{ route('recover') }}">Ztratila jsem odkaz</a>
        <a class="btn btn-outline btn-block" href="{{ route('admin.login') }}">Přihlášení pro kadeřnice</a>
    </div>
@endsection
