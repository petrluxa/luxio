@extends('layouts.admin')

@section('title', 'Admin')

@php
    $me = auth()->user();
    $owner = $me->isOwner();

    // Boxíky: [adresa, ikona, název, popis, počet]. Co přibude, přidá se sem.
    // Správce salonu (majitelka, recepce) má celý salon; kadeřnice jen svůj rozvrh a své nastavení.
    $groups = $owner ? [
        'Provoz' => [
            [route('admin.calendar'), 'calendar', 'Rozvrh', 'Den celého salonu nebo skupiny, týden jedné kadeřnice, přetahování rezervací', null],
            [route('admin.pending'), 'pending', 'Ke schválení', 'Rezervace nových zákaznic', ($pendingCount ?? 0) ?: null],
            [route('admin.customers.index'), 'customers', 'Zákaznice', 'Karty, historie návštěv, osobní odkazy', $counts['customers']],
        ],
        'Salon' => [
            [route('admin.staff.index'), 'team', 'Kadeřnice', 'Každá má své přihlášení, pracovní dobu, ceník a sloupec v rozvrhu', $counts['staff']],
            [route('admin.settings'), 'salon', 'Údaje salonu a doklad', 'Název, adresa, telefon, provozovatel a IČO', null],
            [route('admin.settings').'#pravidla', 'rules', 'Pravidla rezervací', 'Lhůty, krok nabídky časů, schvalování, připomínky', null],
        ],
        'Nabídka a ceník' => [
            [route('admin.services.index'), 'price', 'Ceník služeb', 'Společné služby a ceník každé kadeřnice', $counts['services']],
            [route('admin.hours'), 'clock', 'Pracovní doba a volno', 'Týdenní rozvrh každé kadeřnice včetně soboty a neděle, dovolená', null],
            [route('admin.products.index'), 'bottle', 'Přípravky', 'Barvy, šampony, péče, styling, ampule', $counts['products']],
        ],
        'Můj účet' => [
            [route('admin.account'), 'bell', 'Heslo a upozornění', 'Změna hesla, upozornění na tomto zařízení, odhlášení', null],
        ],
    ] : [
        'Provoz' => [
            [route('admin.calendar'), 'calendar', 'Můj rozvrh', 'Sedm dní od dneška, přetahování rezervací', null],
            [route('admin.pending'), 'pending', 'Ke schválení', 'Rezervace nových zákaznic u mě', ($pendingCount ?? 0) ?: null],
            [route('admin.customers.index'), 'customers', 'Moje zákaznice', 'Karty, historie návštěv, osobní odkazy', $counts['customers']],
        ],
        'Moje nastavení' => array_values(array_filter([
            [route('admin.services.index'), 'price', 'Můj ceník', 'Co dělám, za kolik a jak dlouho', null],
            $me->is_bookable ? [route('admin.hours'), 'clock', 'Moje pracovní doba a volno', 'Týdenní rozvrh včetně soboty a neděle, dovolená', null] : null,
            [route('admin.products.index'), 'bottle', 'Přípravky', 'Barvy, šampony, péče, styling, ampule', $counts['products']],
            [route('admin.account'), 'bell', 'Heslo a upozornění', 'Změna hesla, upozornění na tomto zařízení, odhlášení', null],
        ])),
    ];
@endphp

@section('content')
    <p class="label">{{ $me->name }} · {{ $me->role->label() }}</p>
    <h1 class="screen-title">Admin</h1>

    @foreach ($groups as $heading => $tiles)
        @continue($tiles === [])
        <h2 class="section-title">{{ $heading }}</h2>
        <div class="tiles">
            @foreach ($tiles as [$url, $icon, $title, $text, $count])
                <a class="tile" href="{{ $url }}">
                    <span class="tile-icon">@include('partials.icon', ['name' => $icon, 'size' => 24])</span>
                    <span class="tile-main"><b>{{ $title }}</b><span>{{ $text }}</span></span>
                    @if ($count)<span class="tile-count">{{ $count }}</span>@endif
                </a>
            @endforeach
        </div>
    @endforeach

    {{-- Veřejný odkaz salonu ke sdílení (Instagram, web, QR) --}}
    <h2 class="section-title mt-6">Stránka pro nové zákaznice</h2>
    <div class="card max-w-2xl" x-data="{
            url: @js(route('salon.show', $salon)),
            copied: false,
            canShare: typeof navigator.share === 'function',
            async share() { try { await navigator.share({ title: @js($salon->name), url: this.url }); } catch (e) {} },
            async copy() {
                try { await navigator.clipboard.writeText(this.url); } catch (e) { $refs.url.select(); document.execCommand('copy'); }
                this.copied = true; setTimeout(() => this.copied = false, 2500);
            },
         }">
        <p class="label mb-2">Dejte ji na Instagram, web nebo do Google profilu. Nová zákaznice se přes ni objedná poprvé.</p>
        <input class="input mb-2 text-[14px]" type="text" x-ref="url" :value="url" readonly aria-label="Veřejný odkaz salonu">
        <div class="flex gap-2">
            <a class="btn btn-primary flex-1" href="{{ route('salon.show', $salon) }}" target="_blank" rel="noopener">Otevřít</a>
            <button type="button" class="btn btn-outline flex-1" x-show="canShare" x-on:click="share()">Sdílet</button>
            <button type="button" class="btn btn-outline flex-1" x-on:click="copy()" x-text="copied ? 'Zkopírováno' : 'Kopírovat'">Kopírovat</button>
        </div>
    </div>
@endsection
