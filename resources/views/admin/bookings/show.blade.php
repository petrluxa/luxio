@use('App\Support\Cz')
@use('App\Enums\BookingStatus')
@use('App\Enums\BookingSource')
@use('App\Enums\SegmentType')
@extends('layouts.admin')

@section('title', 'Rezervace')

@section('content')
    <a class="back-link" href="{{ route('admin.calendar', ['datum' => $booking->starts_at->toDateString()]) }}">Rozvrh</a>
    <h1 class="screen-title">{{ Cz::dateTime($booking->starts_at) }}</h1>

    @if ($booking->status === BookingStatus::Pending)
        <div class="banner mb-3">Čeká na schválení</div>
    @elseif ($booking->status !== BookingStatus::Confirmed)
        <div class="banner @if ($booking->status->isCancelled() || $booking->status === BookingStatus::NoShow) banner-danger @endif mb-3">
            {{ $booking->status->label() }}@if ($booking->cancel_reason): {{ $booking->cancel_reason }}@endif
        </div>
    @endif

    {{-- Zákaznice --}}
    <a class="row" href="{{ route('admin.customers.show', $booking->customer) }}">
        <span class="row-main">
            <b>{{ $booking->customer->name }}</b>
            <span>{{ Cz::phone($booking->customer->phone) ?: 'bez telefonu' }}@if ($booking->customer->is_blocked) · zablokovaná @endif</span>
        </span>
        @include('partials.icon', ['name' => 'chevron', 'size' => 20, 'class' => 'text-muted'])
    </a>

    @if ($booking->customer->phone)
        <a class="btn btn-outline btn-block mt-2" href="tel:{{ $booking->customer->phone }}">Zavolat</a>
    @endif

    {{-- Dozví se zákaznice o změně sama? Aplikace neposílá e-maily ani SMS. --}}
    @if ($booking->source === BookingSource::SalonLink && $booking->status->isActive())
        <p class="banner mt-2 font-normal">
            Rezervace přišla přes veřejný odkaz salonu a zákaznice u sebe nemá osobní odkaz.
            Ověřte si, že je to ona, a pošlete jí ho z její karty – bez něj termín v telefonu neuvidí.
        </p>
    @elseif ($booking->status->isActive())
        <p class="label mt-2">
            {{ $booking->customer->reachableByPush()
                ? 'Upozornění jí chodí na telefon.'
                : 'Nemá zapnutá upozornění – o změně termínu jí dejte vědět sama.' }}
        </p>
    @endif

    {{-- Úkony a čas. Další úkon jde přidat, když si zákaznice při návštěvě řekne o něco navíc. --}}
    <h2 class="section-title">Úkony</h2>
    <div class="card" x-data>
        @foreach ($booking->items as $item)
            <div class="flex items-center justify-between gap-3 @if (! $loop->first) mt-2 @endif">
                <b class="min-w-0 flex-1 font-semibold">{{ $item->name }}</b>
                <span class="whitespace-nowrap text-muted">{{ Cz::price($item->price, $item->price_to) }}</span>
                @if ($editable && $booking->items->count() > 1)
                    <form method="post" action="{{ route('admin.bookings.items.destroy', [$booking, $item]) }}" class="flex-none">
                        @csrf
                        <button class="btn btn-quiet btn-sm" type="submit" aria-label="Odebrat úkon {{ $item->name }}">Odebrat</button>
                    </form>
                @endif
            </div>
        @endforeach

        @if ($editable && $addable->isNotEmpty())
            <button type="button" class="btn btn-outline btn-block mt-3" x-on:click="$refs.ukony.showModal()">
                @include('partials.icon', ['name' => 'plus', 'size' => 18]) Přidat úkon
            </button>

            <dialog class="sheet" x-ref="ukony" x-on:click="if ($event.target === $el) $el.close()">
                <b class="block text-[18px] font-semibold">Přidat úkon</b>
                <p class="label mb-3">Návštěva se o něj prodlouží a cena se sečte.</p>
                <form method="post" action="{{ route('admin.bookings.items.store', $booking) }}">
                    @csrf
                    @foreach ($addable as $option)
                        <button class="row mb-2 w-full text-left" type="submit" name="service" value="{{ $option['id'] }}">
                            <span class="row-main">
                                <b>{{ $option['service']->name }}</b>
                                <span>{{ Cz::duration($option['service']->totalMinutes()) }} · {{ Cz::price($option['service']->priceFrom, $option['service']->priceTo) }}</span>
                            </span>
                            @include('partials.icon', ['name' => 'plus', 'size' => 20, 'class' => 'text-muted'])
                        </button>
                    @endforeach
                </form>
                <button type="button" class="btn btn-quiet btn-block" x-on:click="$refs.ukony.close()">Zavřít</button>
            </dialog>
        @endif

        <dl class="mt-3 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 border-t border-line pt-3">
            <dt class="text-muted">Čas</dt>
            <dd>{{ Cz::time($booking->starts_at) }}–{{ Cz::time($booking->ends_at) }} ({{ Cz::duration($booking->durationMinutes()) }})</dd>
            @foreach ($booking->segments->where('type', SegmentType::Pause) as $pause)
                <dt class="text-muted">Působí barva</dt>
                <dd>{{ Cz::time($pause->starts_at) }}–{{ Cz::time($pause->ends_at) }} (volné ruce)</dd>
            @endforeach
            <dt class="text-muted">Kadeřnice</dt><dd>{{ $booking->staff->name }}</dd>
            <dt class="text-muted">Cena</dt><dd>{{ $booking->priceLabel() }}</dd>
            <dt class="text-muted">Vznikla</dt>
            <dd>{{ $booking->source->label() }}, {{ Cz::date($booking->created_at) }}</dd>
            @if ($booking->customer_note)
                <dt class="text-muted">Od zákaznice</dt><dd>„{{ $booking->customer_note }}“</dd>
            @endif
        </dl>

        @if ($booking->customer->color_recipe)
            <p class="mt-3 border-t border-line pt-3"><span class="label block">Receptura barvy</span>{{ $booking->customer->color_recipe }}</p>
        @endif
    </div>

    {{-- Přípravky, které kadeřnice zákaznici použila – zapisují se zvlášť, platba až potom. --}}
    @unless ($booking->status->isCancelled() || $booking->status === BookingStatus::NoShow)
        <h2 class="section-title">Přípravky</h2>
        <div class="card">
            @if ($booking->products->isNotEmpty())
                <ul>
                    @foreach ($booking->products as $item)
                        <li>{{ $item->label() }}</li>
                    @endforeach
                </ul>
            @else
                <p class="text-muted">Zatím nic zapsaného.</p>
            @endif

            <a class="btn btn-outline btn-block mt-3" href="{{ route('admin.bookings.products', $booking) }}">
                @include('partials.icon', ['name' => 'bottle', 'size' => 18]) {{ $booking->products->isEmpty() ? 'Zapsat přípravky' : 'Upravit přípravky' }}
            </a>
        </div>

        {{-- Vyúčtování: platba a doklad --}}
        <h2 class="section-title">Vyúčtování</h2>
        <div class="card">
            @if ($booking->isPaid())
                @php
                    $receiptUrl = $booking->receiptUrl();
                    $receiptText = 'Dobrý den, posílám doklad o zaplacení – '.$booking->salon->name.': '.$receiptUrl;
                @endphp
                <b class="block text-[20px] font-semibold">Zaplaceno {{ Cz::money($booking->paid_amount) }}</b>
                <span class="label">{{ $booking->payment_method->label() }} · {{ Cz::date($booking->paid_at) }} · doklad č. {{ $booking->receipt_number }}</span>

                <div class="mt-3 grid gap-2" x-data="{
                        url: @js($receiptUrl),
                        text: @js($receiptText),
                        copied: false,
                        canShare: typeof navigator.share === 'function',
                        async share() { try { await navigator.share({ title: @js('Doklad o zaplacení'), text: this.text }); } catch (e) {} },
                        async copy() {
                            try { await navigator.clipboard.writeText(this.url); } catch (e) {}
                            this.copied = true; setTimeout(() => this.copied = false, 2500);
                        },
                     }">
                    <a class="btn btn-primary btn-block" target="_blank" rel="noopener noreferrer"
                       href="https://wa.me/{{ ltrim((string) $booking->customer->phone, '+') }}?text={{ rawurlencode($receiptText) }}">Poslat doklad přes WhatsApp</a>
                    <div class="flex gap-2">
                        <button type="button" class="btn btn-outline btn-sm flex-1" x-show="canShare" x-on:click="share()">Sdílet jinak</button>
                        <button type="button" class="btn btn-outline btn-sm flex-1" x-on:click="copy()" x-text="copied ? 'Zkopírováno' : 'Kopírovat odkaz'">Kopírovat odkaz</button>
                        <a class="btn btn-outline btn-sm flex-1" href="{{ $receiptUrl }}" target="_blank" rel="noopener">Zobrazit</a>
                    </div>
                </div>

                <a class="btn btn-quiet btn-sm btn-block mt-3" href="{{ route('admin.bookings.checkout', $booking) }}">Upravit vyúčtování</a>
                <form method="post" action="{{ route('admin.bookings.checkout.revoke', $booking) }}" class="mt-2" x-data="{ sure: false }"
                      x-on:submit="if (!sure) { $event.preventDefault(); sure = true; setTimeout(() => sure = false, 4000) }">
                    @csrf
                    <button class="btn btn-quiet btn-sm btn-block" type="submit" x-text="sure ? 'Opravdu zrušit zaplacení?' : 'Zrušit zaplacení'">Zrušit zaplacení</button>
                </form>
            @else
                <p class="text-muted">Zatím nezaplaceno. K zaplacení {{ $booking->priceLabel() }}.</p>
                <a class="btn btn-primary btn-block mt-3" href="{{ route('admin.bookings.checkout', $booking) }}">Vyúčtovat</a>
            @endif
        </div>
    @endunless

    {{-- Poznámka k návštěvě --}}
    <form method="post" action="{{ route('admin.bookings.note', $booking) }}" class="mt-3" id="poznamka">
        @csrf
        <label class="field">
            <span>Poznámka k návštěvě (vidíte jen vy)</span>
            <textarea class="input" name="staff_note" maxlength="2000" rows="3">{{ old('staff_note', $booking->staff_note) }}</textarea>
            @error('staff_note') <div class="field-error">{{ $message }}</div> @enderror
        </label>
        <button class="btn btn-outline btn-sm btn-block" type="submit">Uložit poznámku</button>
    </form>

    {{-- Akce --}}
    @if ($booking->status === BookingStatus::Pending)
        <h2 class="section-title">Schválení</h2>
        <form method="post" action="{{ route('admin.bookings.approve', $booking) }}">
            @csrf
            <button class="btn btn-primary btn-block" type="submit">Schválit</button>
        </form>
    @endif

    @if ($booking->status->isActive())
        <h2 class="section-title">Změna termínu</h2>
        <div class="grid gap-2" x-data="{ cancelling: false }">
            <a class="btn btn-outline btn-block" href="{{ route('admin.bookings.move', $booking) }}">Přesunout</a>

            @if ($others->isNotEmpty())
                <form method="post" action="{{ route('admin.bookings.reassign', $booking) }}" class="card">
                    @csrf
                    <span class="label">Předat jiné kadeřnici (čas zůstává)</span>
                    <div class="chips mt-2">
                        @foreach ($others as $member)
                            <button class="chip" type="submit" name="kadernice" value="{{ $member->id }}">{{ $member->name }}</button>
                        @endforeach
                    </div>
                    @error('kadernice') <div class="field-error">{{ $message }}</div> @enderror
                </form>
            @endif

            @if ($booking->status === BookingStatus::Confirmed)
                <form method="post" action="{{ route('admin.bookings.no-show', $booking) }}">
                    @csrf
                    <button class="btn btn-quiet btn-block" type="submit">Nepřišla</button>
                </form>
            @endif

            <button type="button" class="btn btn-danger btn-block" x-show="!cancelling" x-on:click="cancelling = true">
                {{ $booking->status === BookingStatus::Pending ? 'Zamítnout' : 'Zrušit rezervaci' }}
            </button>

            <form method="post" class="card" x-show="cancelling" x-cloak
                  action="{{ $booking->status === BookingStatus::Pending ? route('admin.bookings.reject', $booking) : route('admin.bookings.cancel', $booking) }}">
                @csrf
                <label class="field">
                    <span>Důvod pro zákaznici (nepovinné)</span>
                    <input class="input" type="text" name="reason" maxlength="255" placeholder="Např. nemoc – ozvu se s novým termínem">
                </label>
                <div class="flex gap-2">
                    <button type="button" class="btn btn-quiet flex-1" x-on:click="cancelling = false">Zpět</button>
                    <button type="submit" class="btn btn-danger flex-1">{{ $booking->status === BookingStatus::Pending ? 'Zamítnout' : 'Zrušit' }}</button>
                </div>
            </form>
        </div>
    @elseif ($booking->status === BookingStatus::NoShow)
        <form method="post" action="{{ route('admin.bookings.complete', $booking) }}" class="mt-4">
            @csrf
            <button class="btn btn-outline btn-block" type="submit">Přece jen přišla</button>
        </form>
    @endif
@endsection
