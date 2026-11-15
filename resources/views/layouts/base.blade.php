<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#F6EFE8" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#1E1918" media="(prefers-color-scheme: dark)">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <meta name="format-detection" content="telephone=no">
    <title>@hasSection('title')@yield('title') · @endif{{ $appTitle ?? config('app.name') }}</title>
    @stack('head')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    @yield('body')
    @stack('scripts')
</body>
</html>
