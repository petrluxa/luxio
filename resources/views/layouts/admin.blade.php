@extends('layouts.base')

@push('head')
    @if (Route::has('admin.manifest'))<link rel="manifest" href="{{ route('admin.manifest') }}">@endif
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="{{ config('app.name') }}">
    <link rel="apple-touch-icon" href="/icons/icon-192.png">
@endpush

@section('body')
    @php($tab = $tab ?? '')
    <main class="mx-auto w-full max-w-2xl px-[18px] pb-[calc(84px+env(safe-area-inset-bottom))] pt-[max(20px,env(safe-area-inset-top))]"
          style="--bar-offset: calc(57px + env(safe-area-inset-bottom)); --bar-safe: 0px">
        @include('partials.flash')
        @yield('content')
    </main>

    <nav class="fixed inset-x-0 bottom-0 z-30 border-t border-line bg-card pb-[env(safe-area-inset-bottom)]" aria-label="Hlavní nabídka">
        <div class="mx-auto flex max-w-2xl">
            @foreach ([
                'today' => ['admin.today', 'Dnes'],
                'calendar' => ['admin.calendar', 'Kalendář'],
                'pending' => ['admin.pending', 'Schválit'],
                'customers' => ['admin.customers.index', 'Zákaznice'],
                'more' => ['admin.more', 'Více'],
            ] as $key => [$route, $label])
                @continue(! Route::has($route))
                <a href="{{ route($route) }}"
                   @class(['relative flex min-h-[56px] flex-1 flex-col items-center justify-center gap-0.5 text-[12px]', 'font-semibold text-primary' => $tab === $key, 'text-muted' => $tab !== $key])
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
