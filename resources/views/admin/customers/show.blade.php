@use('App\Support\Cz')
@use('App\Enums\BookingStatus')
@extends('layouts.admin')

@section('title', $customer->name)

@section('content')
    <a class="back-link" href="{{ route('admin.customers.index') }}">Zákaznice</a>
    <h1 class="screen-title">{{ $customer->name }}</h1>

    @if ($customer->is_blocked)
        <div class="banner banner-danger mb-3">Zablokovaná – online si rezervovat nemůže.</div>
    @elseif (! $customer->is_verified)
        <div class="banner mb-3">Nová zákaznice – její rezervace čekají na vaše schválení.</div>
    @endif

    <div class="flex gap-2">
        @if ($customer->phone)
            <a class="btn btn-outline flex-1" href="tel:{{ $customer->phone }}">Zavolat</a>
        @endif
        <a class="btn btn-primary flex-1" href="{{ route('admin.bookings.create', ['zakaznice' => $customer->id]) }}">Nová rezervace</a>
    </div>

    {{-- Osobní odkaz --}}
    <h2 class="section-title">Osobní odkaz</h2>

    @if ($link)
        <div class="card" x-data="{
                url: @js($link),
                copied: false,
                canShare: typeof navigator.share === 'function',
                async share() {
                    try { await navigator.share({ title: @js($customer->salon->name), text: @js('Váš odkaz pro rezervace – '.$customer->salon->name), url: this.url }); } catch (e) {}
                },
                async copy() {
                    try { await navigator.clipboard.writeText(this.url); } catch (e) { $refs.url.select(); document.execCommand('copy'); }
                    this.copied = true; setTimeout(() => this.copied = false, 2500);
                },
             }">
            <p class="mb-2 font-semibold">Nový odkaz je připravený</p>
            <p class="label mb-2">Pošlete ho zákaznici přes WhatsApp nebo Messenger. Z bezpečnostních důvodů se ukáže jen teď.</p>
            <input class="input mb-2 text-[13px]" type="text" x-ref="url" :value="url" readonly aria-label="Osobní odkaz">
            <div class="flex gap-2">
                <button type="button" class="btn btn-primary flex-1" x-show="canShare" x-on:click="share()">Sdílet</button>
                <button type="button" class="btn btn-outline flex-1" x-on:click="copy()" x-text="copied ? 'Zkopírováno' : 'Kopírovat'">Kopírovat</button>
            </div>
        </div>
    @else
        <div class="card">
            <p class="label mb-3">
                {{ $activeTokens > 0 ? 'Zákaznice má '.$activeTokens.' platn'.($activeTokens === 1 ? 'ý odkaz' : ($activeTokens < 5 ? 'é odkazy' : 'ých odkazů')).'.' : 'Zákaznice zatím nemá žádný platný odkaz.' }}
                Odkazem se objedná bez hesla.
            </p>
            @unless ($customer->is_blocked)
                <form method="post" action="{{ route('admin.customers.link', $customer) }}">
                    @csrf
                    @if ($staffList->count() > 1)
                        <label class="check">
                            <input type="checkbox" name="only_me" value="1">
                            <span>Odkaz jen pro rezervace u mě</span>
                        </label>
                    @endif
                    @if ($activeTokens > 0)
                        <label class="check">
                            <input type="checkbox" name="replace" value="1">
                            <span>Zrušit dosavadní odkazy (výměna)</span>
                        </label>
                    @endif
                    <button class="btn btn-outline btn-block mt-1" type="submit">Poslat odkaz</button>
                </form>
            @endunless
            @if ($activeTokens > 0)
                <form method="post" action="{{ route('admin.customers.links.revoke', $customer) }}" class="mt-2">
                    @csrf
                    <button class="btn btn-quiet btn-sm btn-block" type="submit">Zrušit všechny odkazy</button>
                </form>
            @endif
        </div>
    @endif

    {{-- Kontakty, receptura, poznámky --}}
    <h2 class="section-title">Karta</h2>
    <form method="post" action="{{ route('admin.customers.update', $customer) }}" novalidate>
        @csrf
        @include('admin.customers.fields')
        <button class="btn btn-primary btn-block" type="submit">Uložit</button>
    </form>

    {{-- Historie návštěv --}}
    <h2 class="section-title mt-6">Historie návštěv</h2>
    @forelse ($bookings as $booking)
        <a class="row" href="{{ route('admin.bookings.show', $booking) }}">
            <span class="row-main">
                <b class="@if ($booking->isPast()) font-normal @endif">{{ Cz::dateTime($booking->starts_at) }} {{ $booking->starts_at->year }}</b>
                <span>{{ $booking->servicesLabel() }} · {{ $booking->staff->name }} · {{ $booking->priceLabel() }}</span>
                @if ($booking->staff_note)<span>{{ $booking->staff_note }}</span>@endif
            </span>
            @if ($booking->status === BookingStatus::Pending)
                <span class="tag">Čeká</span>
            @elseif ($booking->status === BookingStatus::NoShow)
                <span class="tag tag-danger">Nepřišla</span>
            @elseif ($booking->status->isCancelled())
                <span class="tag tag-muted">Zrušeno</span>
            @endif
        </a>
    @empty
        <div class="empty">Zatím žádná návštěva.</div>
    @endforelse

    {{-- Blokace --}}
    <h2 class="section-title mt-6">Blokace</h2>
    <form method="post" action="{{ route('admin.customers.block', $customer) }}">
        @csrf
        <button class="btn {{ $customer->is_blocked ? 'btn-outline' : 'btn-danger' }} btn-block" type="submit">
            {{ $customer->is_blocked ? 'Zrušit blokaci' : 'Zablokovat zákaznici' }}
        </button>
    </form>
    <p class="label mt-2">Zablokovaná zákaznice si online nerezervuje a její odkazy přestanou platit. Ručně ji objednat můžete dál.</p>
@endsection
