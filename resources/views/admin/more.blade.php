@extends('layouts.admin')

@section('title', 'Více')

@section('content')
    <p class="label">{{ $salon->name }} · {{ auth()->user()->name }}</p>
    <h1 class="screen-title">Více</h1>

    @foreach (array_filter([
        ['admin.services.index', 'Služby', 'Ceník, délky a pořadí'],
        ['admin.hours', 'Pracovní doba a volno', 'Týdenní rozvrh, dovolená, výjimky'],
        auth()->user()->isOwner() ? ['admin.settings', 'Nastavení', 'Pravidla rezervací, údaje salonu, kadeřnice'] : null,
    ]) as [$route, $title, $text])
        <a class="row" href="{{ route($route) }}">
            <span class="row-main"><b>{{ $title }}</b><span>{{ $text }}</span></span>
            @include('partials.icon', ['name' => 'chevron', 'size' => 20, 'class' => 'text-muted'])
        </a>
    @endforeach

    {{-- Veřejný odkaz salonu ke sdílení (Instagram, web, QR) --}}
    <h2 class="section-title mt-6">Odkaz pro nové zákaznice</h2>
    <div class="card" x-data="{
            url: @js(route('salon.show', $salon)),
            copied: false,
            canShare: typeof navigator.share === 'function',
            async share() { try { await navigator.share({ title: @js($salon->name), url: this.url }); } catch (e) {} },
            async copy() {
                try { await navigator.clipboard.writeText(this.url); } catch (e) { $refs.url.select(); document.execCommand('copy'); }
                this.copied = true; setTimeout(() => this.copied = false, 2500);
            },
         }">
        <p class="label mb-2">Dejte ho na Instagram, web nebo do Google profilu. Nová zákaznice se přes něj objedná poprvé.</p>
        <input class="input mb-2 text-[14px]" type="text" x-ref="url" :value="url" readonly aria-label="Veřejný odkaz salonu">
        <div class="flex gap-2">
            <button type="button" class="btn btn-primary flex-1" x-show="canShare" x-on:click="share()">Sdílet</button>
            <button type="button" class="btn btn-outline flex-1" x-on:click="copy()" x-text="copied ? 'Zkopírováno' : 'Kopírovat'">Kopírovat</button>
        </div>
    </div>

    @include('admin.partials.push')

    {{-- Změna hesla --}}
    <h2 class="section-title mt-6">Změna hesla</h2>
    <form method="post" action="{{ route('admin.password') }}" novalidate>
        @csrf
        <label class="field">
            <span>Současné heslo</span>
            <input class="input" type="password" name="current_password" autocomplete="current-password" required>
            @error('current_password') <div class="field-error">{{ $message }}</div> @enderror
        </label>
        <label class="field">
            <span>Nové heslo (aspoň 8 znaků)</span>
            <input class="input" type="password" name="password" autocomplete="new-password" required>
            @error('password') <div class="field-error">{{ $message }}</div> @enderror
        </label>
        <label class="field">
            <span>Nové heslo znovu</span>
            <input class="input" type="password" name="password_confirmation" autocomplete="new-password" required>
        </label>
        <button class="btn btn-outline btn-block" type="submit">Změnit heslo</button>
    </form>

    <form method="post" action="{{ route('admin.logout') }}" class="mt-6">
        @csrf
        <button class="btn btn-quiet btn-block" type="submit">Odhlásit se</button>
    </form>
@endsection
