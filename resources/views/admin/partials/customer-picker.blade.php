@use('App\Support\Cz')
{{-- Výběr zákaznice pro ruční rezervaci: hledání v databázi, nebo rychlé založení nové. --}}
<section x-data="{
            picked: @js(($preselected ?? null) ? ['id' => $preselected->id, 'name' => $preselected->name, 'phone' => Cz::phone($preselected->phone)] : null),
            q: @js(old('customer_name', '')),
            results: [],
            searched: false,
            async search() {
                this.searched = false;
                if (this.q.trim().length < 2) { this.results = []; return; }
                const term = this.q;
                const response = await fetch(@js(route('admin.customers.lookup')) + '?q=' + encodeURIComponent(term), { headers: { Accept: 'application/json' } });
                if (response.ok && term === this.q) { this.results = await response.json(); this.searched = true; }
            },
         }">
    <h2 class="section-title">Zákaznice</h2>

    <input type="hidden" name="customer_id" :value="picked ? picked.id : ''">

    {{-- Vybraná zákaznice --}}
    <div class="row" x-show="picked" x-cloak>
        <span class="row-main"><b x-text="picked?.name"></b><span x-text="picked?.phone || 'bez telefonu'"></span></span>
        <button type="button" class="btn btn-quiet btn-sm" x-on:click="picked = null; $nextTick(() => $refs.search.focus())">Změnit</button>
    </div>

    {{-- Hledání / nová --}}
    <div x-show="!picked">
        <label class="field">
            <span>Jméno – začněte psát a vyberte, nebo zadejte novou zákaznici</span>
            <input class="input" type="text" name="customer_name" x-ref="search" x-model="q" x-on:input.debounce.250ms="search()"
                   autocomplete="off" maxlength="100" placeholder="Jana Nováková">
            @error('customer_name') <div class="field-error">{{ $message }}</div> @enderror
        </label>

        <template x-for="hit in results" :key="hit.id">
            <button type="button" class="row mb-2 w-full text-left" x-on:click="picked = hit; results = []">
                <span class="row-main"><b x-text="hit.name"></b><span x-text="hit.phone || 'bez telefonu'"></span></span>
                <span class="tag tag-danger" x-show="hit.blocked">blokovaná</span>
            </button>
        </template>

        <div x-show="q.trim().length >= 2" x-cloak>
            <p class="label mb-2" x-show="searched && results.length === 0">V databázi není – založí se nová zákaznice.</p>
            <label class="field">
                <span>Telefon nové zákaznice (nepovinné)</span>
                <input class="input" type="tel" name="customer_phone" value="{{ old('customer_phone') }}" inputmode="tel" autocomplete="off" placeholder="603 123 456">
            </label>
            <label class="field">
                <span>E-mail nové zákaznice (nepovinné)</span>
                <input class="input" type="email" name="customer_email" value="{{ old('customer_email') }}" inputmode="email" autocomplete="off">
                @error('customer_email') <div class="field-error">{{ $message }}</div> @enderror
            </label>
        </div>
    </div>
</section>
