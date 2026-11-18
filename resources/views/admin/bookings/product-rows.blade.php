@use('App\Support\Cz')
@use('App\Enums\ProductCategory')
{{-- Zápis použitých přípravků: co už je zapsané (s odstínem a množstvím), totéž co minule a výběr dalšího –
     ťuknutím podle druhu, nebo psaním. Patří dovnitř formuláře.
     Vstup: $booking (s načtenými products), $last (minulá návštěva s přípravky, nebo null). --}}
@php
    // Řádky: po chybě to, co bylo odesláno, jinak co je u návštěvy uložené.
    $productRows = is_array(old('products'))
        ? collect(old('products'))->map(fn ($row) => [
            'id' => ($row['id'] ?? '') !== '' ? (int) $row['id'] : null,
            'name' => (string) ($row['name'] ?? ''),
            'note' => (string) ($row['note'] ?? ''),
        ])->values()
        : $booking->products->map(fn ($item) => ['id' => $item->product_id, 'name' => $item->name, 'note' => (string) $item->note])->values();

    $previousRows = $last?->products->map(fn ($item) => ['id' => $item->product_id, 'name' => $item->name, 'note' => (string) $item->note])->values();
@endphp

<div x-data="{
        rows: @js($productRows),
        previous: @js($previousRows ?? []),
        q: '',
        kind: '',
        results: [],
        searched: false,
        async search() {
            this.searched = false;
            if (this.q.trim().length < 2 && !this.kind) { this.results = []; return; }
            const term = this.q;
            const kind = this.kind;
            const response = await fetch(@js(route('admin.products.lookup')) + '?q=' + encodeURIComponent(term) + '&druh=' + encodeURIComponent(kind), { headers: { Accept: 'application/json' } });
            if (response.ok && term === this.q && kind === this.kind) { this.results = await response.json(); this.searched = true; }
        },
        pick(kind) {
            this.kind = this.kind === kind ? '' : kind;
            this.search();
        },
        has(hit) {
            return hit.id !== null && this.rows.some((row) => row.id === hit.id);
        },
        add(hit) {
            if (this.has(hit)) { return; }
            this.rows.push({ id: hit.id, name: hit.name, note: '' });
            // Při vybírání ťuknutím zůstává seznam otevřený (dá se přidat víc věcí za sebou);
            // po hledání psaním se pole vyprázdní a kurzor skočí na odstín a množství.
            if (this.q.trim() !== '' || !this.kind) {
                this.q = '';
                this.searched = false;
                if (this.kind) { this.search(); } else { this.results = []; }
                Alpine.nextTick(() => { const notes = $root.querySelectorAll('[data-note]'); notes[notes.length - 1]?.focus(); });
            }
        },
        addCustom() {
            const name = this.q.trim();
            if (name.length >= 2) { this.add({ id: null, name }); }
        },
        usePrevious() {
            this.previous.forEach((item) => { if (!this.has(item)) { this.rows.push({ ...item }); } });
            this.previous = [];
        },
     }">
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

    <p class="label mb-2" x-show="rows.length === 0">Zatím nic zapsaného. Vyberte přípravek níže.</p>

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

    {{-- Přidat další: ťuknutím na druh se vypíše celá nabídka, psaním se hledá podle názvu, řady nebo značky. --}}
    <span class="label mt-4 block">Přidat přípravek – vyberte druh, nebo pište název</span>
    <div class="chips mt-2" role="group" aria-label="Druh přípravku">
        @foreach (ProductCategory::cases() as $case)
            <button type="button" class="chip" :class="{ 'is-on': kind === @js($case->value) }" :aria-pressed="kind === @js($case->value)"
                    x-on:click="pick(@js($case->value))">{{ $case->label() }}</button>
        @endforeach
    </div>

    <input class="input mt-2" type="search" x-model="q" x-on:input.debounce.250ms="search()" x-on:keydown.enter.prevent="results.length ? add(results[0]) : addCustom()"
           maxlength="120" placeholder="Např. majirel, absolut repair, elixir" aria-label="Hledat přípravek podle názvu, řady nebo značky">

    <div class="mt-2">
        <template x-for="hit in results" :key="hit.id">
            <button type="button" class="row mb-2 w-full text-left" :class="{ 'opacity-60': has(hit) }" x-on:click="add(hit)" :aria-disabled="has(hit)">
                <span class="row-main"><b x-text="hit.name"></b><span x-text="has(hit) ? 'přidáno' : hit.meta"></span></span>
                <span x-show="!has(hit)">@include('partials.icon', ['name' => 'plus', 'size' => 20, 'class' => 'text-muted'])</span>
                <span x-show="has(hit)" x-cloak>@include('partials.icon', ['name' => 'pending', 'size' => 20, 'class' => 'text-muted'])</span>
            </button>
        </template>
    </div>

    <p class="label" x-show="searched && results.length === 0 && q.trim().length < 2" x-cloak>V tomto druhu zatím nic není.</p>

    <button type="button" class="btn btn-quiet btn-sm btn-block" x-show="q.trim().length >= 2 && searched" x-cloak x-on:click="addCustom()">
        <span x-text="results.length ? 'Není v nabídce – zapsat „' + q.trim() + '“' : 'V přehledu není – zapsat „' + q.trim() + '“'"></span>
    </button>

    @error('products') <div class="field-error">{{ $message }}</div> @enderror
</div>
