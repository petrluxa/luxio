<!DOCTYPE html>
{{-- data-root: podsložka webu, ve které aplikace běží (prázdné = kořen domény) --}}
<html lang="cs" data-root="{{ request()->getBaseUrl() }}" @isset($token) data-base="/k/{{ $token }}" @endisset>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#EFE7DB" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#1C1713" media="(prefers-color-scheme: dark)">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <meta name="format-detection" content="telephone=no">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="{{ $appTitle ?? config('app.name') }}">
    <link rel="icon" href="{{ asset('icons/favicon.svg') }}" type="image/svg+xml">
    <link rel="apple-touch-icon" href="{{ asset('icons/apple-touch-icon.png') }}">
    @isset($token)
        <link rel="manifest" href="{{ route('customer.manifest') }}">
    @endisset
    <title>@hasSection('title')@yield('title') · @endif{{ $appTitle ?? config('app.name') }}</title>
    {{-- Ručně zvolená světlá nebo tmavá verze se nastaví dřív, než se stránka vykreslí. --}}
    <script>try{var t=localStorage.getItem('luxio-theme');if(t==='dark'||t==='light'){document.documentElement.setAttribute('data-theme',t)}}catch(e){}</script>
    @stack('head')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    @yield('body')
    @stack('scripts')
</body>
</html>
