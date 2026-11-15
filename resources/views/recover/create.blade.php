@extends('layouts.customer')

@section('title', 'Obnovit odkaz')

@section('content')
    <h1 class="screen-title">Obnovit odkaz</h1>
    <p class="mb-4 text-muted">Ztratili jste odkaz do svých rezervací? Zadejte e-mail, který jste uvedli při objednání, a pošleme vám nový.</p>

    <form method="post" action="{{ route('recover.store') }}" novalidate>
        @csrf
        <label class="field">
            <span>E-mail</span>
            <input class="input" type="email" name="email" value="{{ old('email') }}" autocomplete="email" inputmode="email" required>
            @error('email') <div class="field-error">{{ $message }}</div> @enderror
        </label>

        <button class="btn btn-primary btn-block mt-2" type="submit">Poslat nový odkaz</button>
    </form>
@endsection
