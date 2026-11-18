@extends('layouts.base')

@push('head')
    <link rel="manifest" href="{{ route('admin.manifest') }}">
@endpush

@section('body')
    @php
        $tab = $tab ?? '';
        $me = auth()->user();
        // Šířka obsahu: formuláře úzké, přehledy širší, rozvrh přes celé okno.
        $width = match ($width ?? null) {
            'full' => 'max-w-[1720px]',
            'wide' => 'max-w-5xl',
            default => 'max-w-2xl',
        };
        // „Můj den“ je osobní seznam dnešních zákaznic; správce bez vlastního sloupce ho nemá.
        $nav = array_filter([
            'calendar' => ['admin.calendar', 'Rozvrh'],
            'today' => $me->is_bookable ? ['admin.today', 'Můj den'] : null,
            'pending' => ['admin.pending', 'Schválit'],
            'customers' => ['admin.customers.index', 'Zákaznice'],
            'more' => ['admin.more', 'Admin'],
        ]);
    @endphp

    {{-- Horní lišta: název salonu a na počítači hlavní nabídka (na telefonu je nabídka dole). --}}
    <header class="top-bar">
        <div class="top-bar-inner">
            {{-- Vlevo název salonu; rozvrh sem dává den se šipkami (název má na ceduli pod lištou). --}}
            @hasSection('top-left')
                @yield('top-left')
            @elseif ($salon->sign_title)
                <a class="top-brand top-brand-sign" href="{{ route('admin.calendar') }}">
                    <b>{{ $salon->sign_title }}</b>
                    @if ($salon->sign_subtitle)<span>{{ $salon->sign_subtitle }}</span>@endif
                </a>
            @else
                <a class="top-brand" href="{{ route('admin.calendar') }}">
                    <b>{{ $salon->name }}</b>
                    @if ($salon->address || $salon->phone)
                        <span>{{ collect([$salon->address, \App\Support\Cz::phone($salon->phone)])->filter()->join(' · ') }}</span>
                    @endif
                </a>
            @endif

            <nav class="top-nav" aria-label="Hlavní nabídka">
                @foreach ($nav as $key => [$route, $label])
                    <a href="{{ route($route) }}" @class(['top-btn', 'is-on' => $tab === $key]) @if ($tab === $key) aria-current="page" @endif>
                        @include('partials.icon', ['name' => $key, 'size' => 18])
                        <span>{{ $label }}</span>
                        @if ($key === 'pending' && ($pendingCount ?? 0) > 0)
                            <span class="top-badge">{{ $pendingCount }}</span>
                        @endif
                    </a>
                @endforeach

                <button type="button" class="top-btn top-btn-icon" x-data x-on:click="$store.theme.toggle()"
                        :aria-label="$store.theme.dark ? 'Přepnout na světlou verzi' : 'Přepnout na tmavou verzi'" :aria-pressed="$store.theme.dark">
                    @include('partials.icon', ['name' => 'moon', 'size' => 18])
                </button>

                <details class="top-user" x-data x-on:click.outside="$el.removeAttribute('open')">
                    <summary @class(['top-btn', 'is-on' => $tab === 'account'])>
                        @include('partials.icon', ['name' => 'customers', 'size' => 18])
                        <span>{{ $me->name }}</span>
                    </summary>
                    <div class="top-menu">
                        <a href="{{ route('admin.account') }}">Můj účet a heslo</a>
                        <form method="post" action="{{ route('admin.logout') }}">
                            @csrf
                            <button type="submit">Odhlásit se</button>
                        </form>
                    </div>
                </details>
            </nav>
        </div>
    </header>

    <main class="admin-main mx-auto w-full {{ $width }} px-[18px] pt-4">
        @include('partials.flash', ['sticky' => true])
        @yield('content')
    </main>

    <nav class="admin-bottom-nav fixed inset-x-0 bottom-0 z-30 border-t border-line bg-card pb-[env(safe-area-inset-bottom)]" aria-label="Hlavní nabídka">
        <div class="mx-auto flex max-w-2xl">
            @foreach ($nav as $key => [$route, $label])
                <a href="{{ route($route) }}"
                   @class(['relative flex min-h-[56px] flex-1 flex-col items-center justify-center gap-0.5 text-[12px]', 'font-semibold text-link' => $tab === $key, 'text-muted' => $tab !== $key])
                   @if ($tab === $key) aria-current="page" @endif>
                    @include('partials.icon', ['name' => $key])
                    <span>{{ $label }}</span>
                    @if ($key === 'pending' && ($pendingCount ?? 0) > 0)
                        <span class="absolute right-[calc(50%-22px)] top-1.5 flex h-[18px] min-w-[18px] items-center justify-center rounded-full bg-primary px-1 text-[11px] font-semibold text-on-primary">{{ $pendingCount }}</span>
                    @endif
                </a>
            @endforeach
        </div>
    </nav>
@endsection
