{{-- Instalace přes prohlížeč. Samostatná stránka bez session (viz SetupController). --}}
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>Instalace · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css'])
</head>
<body>
    <main class="mx-auto w-full max-w-md px-[18px] pb-10 pt-[max(20px,env(safe-area-inset-top))]">
        <p class="label">{{ config('app.name') }}</p>
        <h1 class="screen-title">Instalace</h1>

        @if ($error)
            <div class="banner banner-danger" role="alert">Databázi se nepodařilo připravit.</div>
            <div class="card mt-3">
                <p class="label">Hlášení serveru</p>
                <p class="mt-1 break-words text-[14px]">{{ $error }}</p>
                <p class="mt-3 text-muted">Zkontrolujte přístupy k databázi v souboru <b>.env</b> a stránku načtěte znovu.</p>
            </div>
        @else
            <div class="banner">Databáze je připravená.</div>

            @if ($salon)
                <div class="card mt-3">
                    <b class="text-[16px] font-semibold">{{ $created ? 'Salon je založený' : 'Salon už existuje' }}</b>
                    <p class="mt-1 text-muted">{{ $salon->name }}</p>
                    <div class="mt-3 grid gap-2">
                        <a class="btn btn-primary btn-block" href="{{ url('/admin') }}">Přihlásit se do administrace</a>
                        <a class="btn btn-outline btn-block" href="{{ url('/s/'.$salon->slug) }}">Veřejný odkaz pro zákaznice</a>
                    </div>
                    <p class="label mt-3">V administraci nastavte služby a pracovní dobu. Další kadeřnici přidáte v nabídce Více → Nastavení.</p>
                </div>
            @else
                <h2 class="section-title">Založit salon</h2>

                @if ($problems)
                    <div class="banner banner-danger mb-3" role="alert">
                        @foreach ($problems as $message)
                            <span class="block font-normal">{{ $message }}</span>
                        @endforeach
                    </div>
                @endif

                <form method="post" action="{{ $action }}" novalidate autocomplete="off">
                    <label class="field">
                        <span>Název salonu</span>
                        <input class="input" type="text" name="name" value="{{ $old['name'] ?? '' }}" maxlength="100" placeholder="Kadeřnictví Petra" required>
                    </label>
                    <label class="field">
                        <span>Adresa veřejného odkazu (malá písmena bez háčků, např. petra)</span>
                        <input class="input" type="text" name="slug" value="{{ $old['slug'] ?? '' }}" maxlength="60" autocapitalize="none" required>
                    </label>
                    <label class="field">
                        <span>Jméno majitelky (uvidí ho zákaznice)</span>
                        <input class="input" type="text" name="owner" value="{{ $old['owner'] ?? '' }}" maxlength="60" placeholder="Petra" required>
                    </label>
                    <label class="field">
                        <span>Přihlašovací e-mail (slouží jen jako jméno pro přihlášení)</span>
                        <input class="input" type="email" name="email" value="{{ $old['email'] ?? '' }}" maxlength="190" inputmode="email" autocapitalize="none" required>
                    </label>
                    <label class="field">
                        <span>Heslo (aspoň 10 znaků)</span>
                        <input class="input" type="password" name="password" minlength="10" autocomplete="new-password" required>
                    </label>
                    <button class="btn btn-primary btn-block mt-2" type="submit">Založit salon</button>
                </form>
            @endif
        @endif

        <h2 class="section-title mt-6">Server</h2>
        <dl class="card grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-[14px]">
            <dt class="text-muted">PHP</dt><dd>{{ $php }}</dd>
            <dt class="text-muted">Databáze</dt><dd>{{ $database ?: 'nepřipojeno' }}</dd>
            <dt class="text-muted">Adresa aplikace</dt><dd class="break-all">{{ url('/') }}</dd>
            <dt class="text-muted">Vaše IP adresa</dt><dd>{{ request()->ip() }}</dd>
        </dl>
        @if ($migrations)
            <pre class="mt-2 overflow-x-auto rounded-[14px] border border-line bg-card p-3 text-[12px] leading-snug">{{ $migrations }}</pre>
        @endif
    </main>
</body>
</html>
