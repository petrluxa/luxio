@extends('layouts.base')

@section('title', 'Přihlášení')

@section('body')
    <main class="mx-auto flex min-h-dvh w-full max-w-md flex-col justify-center px-[18px] py-8">
        <h1 class="screen-title">Přihlášení</h1>
        <p class="mb-5 text-muted">Administrace salonu – správce a kadeřnice.</p>

        @include('partials.flash')

        <form method="post" action="{{ route('admin.login.store') }}" novalidate>
            @csrf

            <label class="field">
                <span>Přihlašovací jméno</span>
                <input class="input" type="text" name="login" value="{{ old('login', $login ?? '') }}" autocomplete="username"
                       autocapitalize="none" autocorrect="off" spellcheck="false" maxlength="190" required @if (! old('login', $login ?? '')) autofocus @endif>
                @error('login') <div class="field-error">{{ $message }}</div> @enderror
            </label>

            <label class="field">
                <span>Heslo</span>
                <input class="input" type="password" name="password" autocomplete="current-password" required @if (old('login', $login ?? '')) autofocus @endif>
                @error('password') <div class="field-error">{{ $message }}</div> @enderror
            </label>

            <label class="check">
                <input type="checkbox" name="remember" value="1" @checked(old('remember', true))>
                <span>Zapamatovat přihlášení na tomto zařízení</span>
            </label>

            <button class="btn btn-primary btn-block mt-3" type="submit">Přihlásit se</button>
        </form>
    </main>
@endsection
