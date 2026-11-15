{{--
    Rezervační průvodce (vzor: docs/design-mockup.html).
    Vstup: $wizard (data pro Alpine), $action, $replaces (Booking|null),
           $secondaryUrl + $secondaryLabel (levé tlačítko), $submitLabel,
           $withContact (jméno, telefon, e-mail a souhlas pro novou zákaznici).
--}}
<form method="post" action="{{ $action }}" x-data="bookingWizard(@js($wizard))" x-on:submit="submitting = true" novalidate>
    @csrf
    <input type="hidden" name="staff" :value="staffId ?? ''">
    <template x-for="id in serviceIds" :key="id">
        <input type="hidden" name="services[]" :value="id">
    </template>
    <input type="hidden" name="date" :value="date ?? ''">
    <input type="hidden" name="time" :value="time ?? ''">
    @if ($replaces ?? null)
        <input type="hidden" name="replaces" value="{{ $replaces->id }}">
    @endif

    @includeWhen($customerPicker ?? false, 'admin.partials.customer-picker')

    {{-- Kadeřnice --}}
    <template x-if="staff.length > 1">
        <section>
            <h2 class="section-title" id="wizard-staff">Kadeřnice</h2>
            <div class="chips" role="radiogroup" aria-labelledby="wizard-staff">
                <template x-for="member in staff" :key="member.id">
                    <button type="button" class="chip" role="radio" :aria-checked="staffId === member.id"
                            x-on:click="pickStaff(member.id)" x-text="member.name"></button>
                </template>
                <button type="button" class="chip" role="radio" :aria-checked="staffId === null"
                        x-on:click="pickStaff(null)">Kdokoliv</button>
            </div>
        </section>
    </template>

    {{-- Služba --}}
    <section x-show="!fixed">
        <h2 class="section-title" id="wizard-services">Služba</h2>
        <div role="group" aria-labelledby="wizard-services">
            <template x-for="service in visibleServices" :key="service.id">
                <button type="button" class="option-card" role="checkbox" :aria-checked="serviceIds.includes(service.id)"
                        x-on:click="toggleService(service.id)">
                    <div class="dot"></div>
                    <div><b x-text="service.name"></b><span x-text="service.meta"></span></div>
                </button>
            </template>
        </div>
        <p class="empty" x-show="visibleServices.length === 0" x-cloak>Tato kadeřnice teď nemá v nabídce žádnou službu.</p>
        @error('services') <div class="field-error">{{ $message }}</div> @enderror
    </section>

    {{-- Termín --}}
    <section x-show="hasSelection" x-cloak>
        <div class="flex items-baseline justify-between gap-3">
            <h2 class="section-title" id="wizard-days">Termín</h2>
            <span class="label" x-text="monthLabel"></span>
        </div>

        <div class="chips" role="group" aria-labelledby="wizard-days">
            <button type="button" class="chip" x-show="prev" x-on:click="shift(prev)" aria-label="Dřívější dny">‹</button>
            <template x-for="day in days" :key="day.date">
                <button type="button" class="chip" :class="{ 'is-off': day.times.length === 0 }" :disabled="day.times.length === 0"
                        :aria-pressed="date === day.date" :aria-label="day.label" x-on:click="pickDay(day.date)" x-text="day.chip"></button>
            </template>
            <button type="button" class="chip" x-show="next" x-on:click="shift(next)" aria-label="Další dny">›</button>
        </div>

        <p class="label mt-3" x-show="loading && !loaded" role="status">Hledám volné termíny…</p>

        <div class="empty mt-3" x-show="failed" role="alert">
            Termíny se nepodařilo načíst.
            <button type="button" class="btn btn-outline btn-sm mt-3" x-on:click="load(date)">Zkusit znovu</button>
        </div>

        <div class="empty mt-3" x-show="loaded && !failed && !hasAnySlot">
            V těchto dnech není žádný volný termín.
            <template x-if="next">
                <button type="button" class="btn btn-outline btn-sm mt-3" x-on:click="shift(next)">Zkusit další dny</button>
            </template>
        </div>

        <div class="times mt-2" x-show="selectedDay && selectedDay.times.length > 0" role="group" aria-label="Čas">
            <template x-for="slot in (selectedDay ? selectedDay.times : [])" :key="slot">
                <button type="button" class="chip" :aria-pressed="time === slot" x-on:click="time = slot" x-text="slot"></button>
            </template>
        </div>
        @error('date') <div class="field-error">{{ $message }}</div> @enderror
        @error('time') <div class="field-error">{{ $message }}</div> @enderror
    </section>

    {{-- Shrnutí --}}
    <section x-show="time" x-cloak>
        <h2 class="section-title">Shrnutí</h2>
        <div class="card">
            <b class="block text-[16px] font-semibold" x-text="whenLabel"></b>
            <span class="mt-1 block" x-show="serviceNames" x-text="serviceNames"></span>
            <span class="label mt-1 block">
                <span x-show="staff.length > 1" x-text="staffName + ' · '"></span><span x-text="summary ? summary.duration + ' · ' + summary.price : ''"></span>
            </span>
        </div>

        <label class="field mt-3" x-show="!fixed">
            <span>{{ $noteLabel ?? 'Poznámka pro kadeřnici (nepovinné)' }}</span>
            <textarea class="input" name="note" maxlength="500" rows="2">{{ old('note') }}</textarea>
        </label>
    </section>

    @includeWhen($withContact ?? false, 'partials.contact-fields')

    <div class="bottom-bar">
        <a class="btn btn-outline" href="{{ $secondaryUrl }}">{{ $secondaryLabel }}</a>
        <button class="btn btn-primary" type="submit" :disabled="!ready">{{ $submitLabel ?? 'Rezervovat' }}</button>
    </div>
</form>
