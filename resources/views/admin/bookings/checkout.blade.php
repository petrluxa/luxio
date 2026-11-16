@use('App\Support\Cz')
@use('App\Enums\PaymentMethod')
@extends('layouts.admin')

@section('title', 'Vyúčtování')

@php
    // Řádky formuláře: po chybě to, co bylo odesláno, jinak co je u návštěvy uložené.
    $rows = is_array(old('products'))
        ? collect(old('products'))->map(fn ($row) => [
            'id' => ($row['id'] ?? '') !== '' ? (int) $row['id'] : null,
            'name' => (string) ($row['name'] ?? ''),
            'note' => (string) ($row['note'] ?? ''),
        ])->values()
        : $booking->products->map(fn ($item) => ['id' => $item->product_id, 'name' => $item->name, 'note' => (string) $item->note])->values();

    $previous = $last?->products->map(fn ($item) => ['id' => $item->product_id, 'name' => $item->name, 'note' => (string) $item->note])->values();
    $method = old('method', $booking->payment_method?->value ?? PaymentMethod::Cash->value);
@endphp

@section('content')
    <a class="back-link" href="{{ route('admin.bookings.show', $booking) }}">Rezervace</a>
    <h1 class="screen-title">Vyúčtování</h1>

    <div class="banner">
        {{ $booking->customer->name }} · {{ $booking->servicesLabel() }}
        <span class="mt-1 block font-normal">{{ Cz::dateTime($booking->starts_at) }} · {{ $booking->staff->name }}</span>
    </div>

    {{-- Enter v poli formulář neodesílá – o platbě rozhoduje až tlačítko dole. --}}
    <form method="post" action="{{ route('admin.bookings.checkout.store', $booking) }}" novalidate autocomplete="off"
          x-on:keydown.enter="if ($event.target.tagName === 'INPUT') $event.preventDefault()"
          x-data="{
              rows: @js($rows),
              previous: @js($previous ?? []),
              q: '',
              results: [],
              searched: false,
              method: @js($method),
              async search() {
                  this.searched = false;
                  if (this.q.trim().length < 2) { this.results = []; return; }
                  const term = this.q;
                  const response = await fetch(@js(route('admin.products.lookup')) + '?q=' + encodeURIComponent(term), { headers: { Accept: 'application/json' } });
                  if (response.ok && term === this.q) { this.results = await response.json(); this.searched = true; }
              },
              add(hit) {
                  this.rows.push({ id: hit.id, name: hit.name, note: '' });
                  this.q = ''; this.results = []; this.searched = false;
                  Alpine.nextTick(() => { const notes = document.querySelectorAll('[data-note]'); notes[notes.length - 1]?.focus(); });
              },
              addCustom() {
                  const name = this.q.trim();
                  if (name.length >= 2) { this.add({ id: null, name }); }
              },
              usePrevious() {
                  this.previous.forEach((item) => this.rows.push({ ...item }));
                  this.previous = [];
              },
          }">
        @csrf

        <h2 class="section-title">Použité přípravky</h2>

        <template x-for="(row, i) in rows" :key="i">
            <div class="card mb-2">
                <div class="flex items-center gap-3">
                    <b class="min-w-0 flex-1 font-semibold" x-text="row.name"></b>
                    <button type="button" class="btn btn-quiet btn-sm flex-none" x-on:click="rows.splice(i, 1)" :aria-label="'Odebrat ' + row.name">Odebrat</button>
                </div>
                <input type="hidden" :name="'products[' + i + '][id]'" :value="row.id ?? ''">
                <input type="hidden" :name="'products[' + i + '][name]'" :value="row.name">
                <input class="input mt-2" type="text" data-note :name="'products[' + i + '][note]'" x-model="row.note" maxlength="160"
                       placeholder="Odstín, množství – např. 7.1, 30 g" :aria-label="'Odstín a množství – ' + row.name">
            </div>
        </template>

        <p class="label mb-2" x-show="rows.length === 0">Zatím nic. Vyhledejte přípravek níže.</p>

        @if ($last)
            <div class="card mb-2" x-show="previous.length > 0" x-cloak>
                <span class="label">Minule ({{ Cz::date($last->starts_at) }} {{ $last->starts_at->year }})</span>
                <ul class="mt-1">
                    @foreach ($last->products as $item)
                        <li>{{ $item->label() }}</li>
                    @endforeach
                </ul>
                <button type="button" class="btn btn-outline btn-sm btn-block mt-2" x-on:click="usePrevious()">Použít totéž co minule</button>
            </div>
        @endif

        <label class="field mt-3">
            <span>Přidat přípravek – začněte psát název, řadu nebo značku</span>
            <input class="input" type="search" x-model="q" x-on:input.debounce.250ms="search()" x-on:keydown.enter.prevent="results.length ? add(results[0]) : addCustom()"
                   maxlength="120" placeholder="Např. majirel, absolut repair, elixir">
        </label>

        <template x-for="hit in results" :key="hit.id">
            <button type="button" class="row mb-2 w-full text-left" x-on:click="add(hit)">
                <span class="row-main"><b x-text="hit.name"></b><span x-text="hit.meta"></span></span>
                @include('partials.icon', ['name' => 'plus', 'size' => 20, 'class' => 'text-muted'])
            </button>
        </template>

        <button type="button" class="btn btn-quiet btn-sm btn-block" x-show="q.trim().length >= 2 && searched" x-cloak x-on:click="addCustom()">
            <span x-text="results.length ? 'Není v nabídce – zapsat „' + q.trim() + '“' : 'V přehledu není – zapsat „' + q.trim() + '“'"></span>
        </button>

        @error('products') <div class="field-error">{{ $message }}</div> @enderror

        <h2 class="section-title mt-6">Platba</h2>

        <label class="field">
            <span>Zákaznice zaplatí (Kč) – podle ceníku {{ $booking->priceLabel() }}</span>
            <input class="input text-[20px] font-semibold" type="number" name="amount" inputmode="numeric" min="0" max="1000000" step="1"
                   value="{{ old('amount', $booking->paid_amount ?? $booking->total_price) }}">
            @error('amount') <div class="field-error">{{ $message }}</div> @enderror
        </label>

        <input type="hidden" name="method" :value="method">
        <div class="chips" role="radiogroup" aria-label="Způsob platby">
            @foreach (PaymentMethod::cases() as $case)
                <button type="button" class="chip" role="radio" :aria-checked="method === @js($case->value)" x-on:click="method = @js($case->value)">{{ mb_convert_case($case->label(), MB_CASE_TITLE) }}</button>
            @endforeach
        </div>

        <div class="bottom-bar">
            @if ($booking->isPaid())
                <a class="btn btn-outline" href="{{ route('admin.bookings.show', $booking) }}">Zpět</a>
                <button class="btn btn-primary" type="submit">Uložit</button>
            @else
                <button class="btn btn-outline" type="submit" name="only_products" value="1">Jen přípravky</button>
                <button class="btn btn-primary" type="submit">Zaplaceno</button>
            @endif
        </div>
    </form>

    <p class="label mt-3">
        „Zaplaceno“ vystaví doklad, který pak zákaznici pošlete přes WhatsApp. „Jen přípravky“ uloží záznam bez platby.
    </p>
@endsection
