@use('App\Support\Cz')
@use('App\Enums\BookingStatus')
@extends('layouts.customer')

@section('title', 'Aplikace v telefonu')

@section('content')
    @if ($booking)
        <p class="label">{{ $salon->name }}</p>
        <h1 class="screen-title">{{ $booking->status === BookingStatus::Pending ? 'Rezervace je odeslaná' : 'Termín je rezervovaný' }}</h1>

        <div class="banner">
            {{ Cz::dateTime($booking->starts_at) }} · {{ $booking->servicesLabel() }}
            @if ($booking->status === BookingStatus::Pending)
                <span class="mt-1 block font-normal">Čeká na potvrzení kadeřnicí – dáme vám vědět.</span>
            @endif
        </div>

        <h2 class="section-title mt-6">Uložte si aplikaci na plochu</h2>
    @else
        <a class="back-link" href="{{ route('customer.home') }}">Zpět</a>
        <h1 class="screen-title">Aplikace v telefonu</h1>
    @endif

    <p class="mb-3 text-muted">Příště se objednáte na pár ťuknutí a připomínka termínu vám přijde rovnou na telefon. Bez hesla, bez SMS.</p>

    @include('partials.install-steps', ['pushConfig' => ['vapidKey' => $vapidPublicKey, 'subscribeUrl' => route('customer.push.store')]])

    {{-- Osobní odkaz k uložení: e-mailem ho neposíláme, zákaznice si ho schová sama. --}}
    <section class="card mt-2" x-data="{
            url: @js(route('customer.home')),
            copied: false,
            canShare: typeof navigator.share === 'function',
            async share() {
                try { await navigator.share({ title: @js($salon->name), text: @js('Moje rezervace – '.$salon->name), url: this.url }); } catch (e) {}
            },
            async copy() {
                try { await navigator.clipboard.writeText(this.url); } catch (e) { $refs.url.select(); document.execCommand('copy'); }
                this.copied = true; setTimeout(() => this.copied = false, 2500);
            },
         }">
        <b class="text-[16px] font-semibold">Váš osobní odkaz</b>
        <p class="mt-2 text-muted">Tímto odkazem se dostanete ke svým termínům bez hesla. Schovejte si ho – třeba si ho pošlete do zpráv. Nikomu cizímu ho nedávejte.</p>
        <input class="input mt-3 text-[13px]" type="text" x-ref="url" :value="url" readonly aria-label="Váš osobní odkaz">
        <div class="mt-2 flex gap-2">
            <button type="button" class="btn btn-outline flex-1" x-show="canShare" x-on:click="share()">Sdílet</button>
            <button type="button" class="btn btn-outline flex-1" x-on:click="copy()" x-text="copied ? 'Zkopírováno' : 'Kopírovat'">Kopírovat</button>
        </div>
    </section>

    <div class="mt-6">
        <a class="btn {{ $booking ? 'btn-primary' : 'btn-outline' }} btn-block" href="{{ route('customer.home') }}">{{ $booking ? 'Pokračovat' : 'Zpět domů' }}</a>
    </div>
@endsection
