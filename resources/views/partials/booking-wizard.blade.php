{{--
    Rezervační průvodce (vzor: docs/design-mockup.html).
    Vstup: $wizard (data pro Alpine), $action, $replaces (Booking|null),
           $secondaryUrl + $secondaryLabel (levé tlačítko), $submitLabel,
           $withContact (jméno, telefon a souhlas pro novou zákaznici).
--}}
<form method="post" action="{{ $action }}" x-data="bookingWizard(@js($wizard))" x-on:submit="submitting = true" novalidate>
    @csrf
    <input type="hidden" name="staff" :value="staffId ?? ''">
    <template x-for="id in serviceIds" :key="id">
        <input type="hidden" name="services[]" :value="id">
    </template>
    <input type="hidden" name="date" :value="date ?? ''">
    <input type="hidden" name="time" :value="time ?? ''">
    <template x-if="anyTime">
        <input type="hidden" name="force" :value="forced ? 1 : ''">
    </template>
    @if ($replaces ?? null)
        <input type="hidden" name="replaces" value="{{ $replaces->id }}">
    @endif

    @includeWhen($customerPicker ?? false, 'admin.partials.customer-picker')

    {{-- Rozvrh pro zákaznice. Den celého salonu (sloupec = člověk) nebo – po ťuknutí na kadeřnici –
         jejích sedm dní (sloupec = den); řádek = půl hodiny. Vidět je jen volno a obsazeno, žádná jména.
         Ťuknutím na volný čas se vybere kadeřnice, den i čas. --}}
    @if ($wizard['boardUrl'] ?? null)
        <section class="cal-frame pub-board" x-show="boardOn" x-cloak aria-labelledby="board-title">
            <div class="pub-top">
                <div class="pub-bar">
                    <div class="pub-when">
                        <button type="button" class="chip pub-step" :disabled="!board || !board.prev" x-on:click="loadBoard(board.prev)"
                                :aria-label="board && board.mode === 'week' ? 'Předchozí týden' : 'Předchozí den'">‹</button>
                        <div class="pub-title">
                            <h2 id="board-title" x-text="boardTitle">Rozvrh</h2>
                            <span x-show="board && board.mode === 'week'" x-text="board ? board.label : ''"></span>
                        </div>
                        <button type="button" class="chip pub-step" :disabled="!board || !board.next" x-on:click="loadBoard(board.next)"
                                :aria-label="board && board.mode === 'week' ? 'Další týden' : 'Další den'">›</button>
                    </div>

                    <div class="pub-nav">
                        <button type="button" class="chip" :aria-pressed="board && board.isToday" :disabled="!board" x-on:click="loadBoard(board.today)">Dnes</button>
                        <button type="button" class="chip" x-show="board && board.tomorrow" :aria-pressed="board && board.isTomorrow" x-on:click="loadBoard(board.tomorrow)">Zítra</button>
                        {{-- Skok na jiný den: vlastní měsíční kalendář (ne kalendář prohlížeče), ve stejném
                             stylu jako ten v administraci. Dny, kdy se nepracuje, jsou ztlumené. --}}
                        <div class="pub-datewrap" x-on:click.outside="cal.open = false" x-on:keydown.escape.window="cal.open = false">
                            <button type="button" class="chip pub-date" :aria-expanded="cal.open" aria-controls="board-calendar" x-on:click="toggleCal()">
                                @include('partials.icon', ['name' => 'calendar', 'size' => 18])
                                <span>Jiný den</span>
                            </button>

                            <div class="pub-cal" id="board-calendar" x-show="cal.open" x-cloak x-transition.opacity.duration.150ms role="dialog" aria-label="Vybrat jiný den">
                                <div class="pub-cal-head">
                                    <button type="button" class="pub-cal-arrow" :disabled="!cal.data || !cal.data.prev" x-on:click="loadMonth(cal.data.prev)" aria-label="Předchozí měsíc">‹</button>
                                    <b x-text="cal.data ? cal.data.label : 'Načítám…'"></b>
                                    <button type="button" class="pub-cal-arrow" :disabled="!cal.data || !cal.data.next" x-on:click="loadMonth(cal.data.next)" aria-label="Další měsíc">›</button>
                                </div>

                                <div class="pub-cal-grid" :class="{ 'is-loading': cal.loading }">
                                    @foreach (['po', 'út', 'st', 'čt', 'pá', 'so', 'ne'] as $dow)
                                        <span class="pub-cal-dow">{{ $dow }}</span>
                                    @endforeach

                                    <template x-for="day in (cal.data ? cal.data.days : [])" :key="day.date">
                                        <button type="button" class="pub-cal-day" :disabled="!day.enabled" x-on:click="jumpTo(day.date)" x-text="day.day"
                                                :class="{ 'is-out': !day.inMonth, 'is-closed': !day.open, 'is-today': day.today, 'is-on': isShown(day.date), 'is-first': board && day.date === board.date }"
                                                :aria-label="day.label + (day.open ? '' : ', zavřeno')" :aria-current="board && day.date === board.date ? 'date' : false"></button>
                                    </template>
                                </div>

                                <p class="pub-cal-note"><i></i><span x-text="boardStaffId === null ? 'Dny bez podkladu má salon zavřeno.' : 'Dny bez podkladu nepracuje.'"></span></p>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Čí rozvrh: celý salon v jednom dni, nebo jeden člověk a jeho týden. --}}
                <div class="pub-staff" role="group" aria-label="Čí rozvrh chcete vidět">
                    <button type="button" class="chip" :aria-pressed="boardStaffId === null" x-on:click="showStaff(null)">Celý salon</button>
                    <template x-for="member in staff" :key="member.id">
                        <button type="button" class="chip" :aria-pressed="boardStaffId === member.id" x-on:click="showStaff(member.id)" x-text="member.label ?? member.name"></button>
                    </template>
                </div>
            </div>

            <div class="pub-info">
                <p class="pub-hint">
                    <span x-show="!board || board.mode === 'day'">Ťukněte na volný čas u toho, ke komu chcete jít. Ťuknutím na jméno uvidíte jeho celý týden.</span>
                    <span x-show="board && board.mode === 'week'" x-cloak>Ťukněte na volný čas, který se vám hodí. Službu vyberete hned potom.</span>
                </p>

                <div class="pub-legend" aria-hidden="true">
                    <span><i class="is-free"></i>volno</span>
                    <span><i class="is-busy"></i>obsazeno</span>
                    <span><i class="is-closed"></i>zavřeno</span>
                    <span x-show="picked"><i class="is-mine"></i>váš termín</span>
                </div>
            </div>

            <p class="label" x-show="boardLoading && !board" role="status">Načítám rozvrh…</p>

            <div class="empty" x-show="boardFailed" x-cloak role="alert">
                Rozvrh se nepodařilo načíst.
                <button type="button" class="btn btn-outline btn-sm mt-3" x-on:click="loadBoard(board ? board.date : null)">Zkusit znovu</button>
            </div>

            <div class="empty" x-show="board && board.closed" x-cloak>
                <span x-text="board && board.mode === 'week' ? 'V těchto dnech nepracuje.' : 'Tento den má salon zavřeno.'"></span>
                <button type="button" class="btn btn-outline btn-sm mt-3" x-show="board && board.next" x-on:click="loadBoard(board.next)"
                        x-text="board && board.mode === 'week' ? 'Další týden' : 'Další den'"></button>
            </div>

            <div class="pub-grid" x-show="board && !board.closed" x-cloak :class="{ 'is-loading': boardLoading }">
                {{-- Záhlaví se drží nahoře při sjíždění stránky a do strany jede s rozvrhem. --}}
                <div class="cal-headbar">
                    <div class="cal-headbar-scroll" x-ref="boardHead">
                        <div class="cal" :style="`--cal-cols: ${boardColumns.length}`">
                            <div class="cal-corner">Čas</div>
                            <template x-for="column in boardColumns" :key="column.key">
                                {{-- Den celého salonu: jméno je tlačítko, které ukáže týden toho člověka. --}}
                                <button type="button" class="cal-staff" :disabled="board.mode === 'week'" x-on:click="showStaff(column.staffId)"
                                        :class="{ 'is-today': board.mode === 'week' && column.today }"
                                        :aria-label="board.mode === 'week' ? `${column.title}, ${column.subtitle}` : `${column.title} – ukázat celý týden`">
                                    <span class="cal-staff-name">
                                        <b x-text="column.title"></b>
                                        <span x-text="column.subtitle"></span>
                                    </span>
                                </button>
                            </template>
                        </div>
                    </div>
                    <button type="button" class="cal-pan cal-pan-prev" style="display: none" x-show="pan.left" x-on:click="panBy(-1)" aria-label="Posunout rozvrh doleva">‹</button>
                    <button type="button" class="cal-pan cal-pan-next" style="display: none" x-show="pan.right" x-on:click="panBy(1)" aria-label="Posunout rozvrh doprava">›</button>
                </div>

                <div class="cal-scroll" x-ref="boardScroll" x-on:scroll.passive="updatePan()">
                    <div class="cal" :style="`--cal-cols: ${boardColumns.length}`">
                        <div class="cal-hours" :style="`height: calc(var(--cal-half) * ${boardRows.length})`">
                            <template x-for="row in boardRows" :key="row.minute">
                                <span class="cal-hour" :class="{ 'is-half': row.half }" :style="boardBox(row.minute, row.minute + 30)" x-text="row.label"></span>
                            </template>
                        </div>

                        <template x-for="column in boardColumns" :key="column.key">
                            <div class="cal-col pub-col" :style="`height: calc(var(--cal-half) * ${boardRows.length})`">
                                <template x-for="minute in column.starts" :key="minute">
                                    <button type="button" class="pub-slot" :style="boardBox(minute, minute + 30)" x-on:click="pickCell(column, minute)"
                                            :aria-label="`${column.title}, ${column.short} v ${clock(minute)} – volno`" x-text="clock(minute)"></button>
                                </template>

                                {{-- Obsazené půlhodiny jsou spojené do plných bloků jako v rozvrhu salonu – jen bez jmen. --}}
                                <template x-for="block in blocks(column)" :key="block.from">
                                    <div class="pub-busy" :class="{ 'is-short': block.to - block.from <= 30 }" :style="boardBox(block.from, block.to)">
                                        <b>Obsazeno</b>
                                        <span x-text="`${clock(block.from)} – ${clock(block.to)}`"></span>
                                    </div>
                                </template>

                                <template x-if="mine && mine.key === column.key">
                                    <div class="pub-mine" :class="{ 'is-short': mine.to - mine.from <= 30 }" :style="boardBox(mine.from, mine.to)">
                                        <b>Váš termín</b>
                                        <span x-text="mine.label"></span>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            {{-- Jen pro novou zákaznici („Už mám účet“). Známá zákaznice má odkaz na účet nahoře na stránce. --}}
            @if (($withContact ?? false) && ($secondaryUrl ?? null))
                <p class="pub-other" x-show="!picked"><a href="{{ $secondaryUrl }}">{{ $secondaryLabel }}</a></p>
            @endif
        </section>
    @endif

    {{-- Zbytek průvodce je úzký sloupec i tam, kde je rozvrh přes celé okno. --}}
    <div @class(['pub-rest' => $wizard['boardUrl'] ?? null])>

    {{-- Ťuknutý čas z rozvrhu --}}
    @if ($wizard['boardUrl'] ?? null)
        <div class="pub-choice" x-show="boardOn && picked" x-cloak x-ref="choice">
            <h2 class="section-title">Vaše rezervace</h2>
            <div class="card">
                <b class="block text-[16px] font-semibold" x-text="staffName"></b>
                <span class="mt-1 block" x-text="pickedWhen"></span>
            </div>
        </div>
    @endif

    {{-- Kadeřnice --}}
    <template x-if="staff.length > 1 && !boardOn">
        <section>
            <h2 class="section-title" id="wizard-staff">{{ $wizard['staffHeading'] ?? 'Kadeřnice' }}</h2>
            <div class="chips" role="radiogroup" aria-labelledby="wizard-staff">
                <template x-for="member in staff" :key="member.id">
                    <button type="button" class="chip" role="radio" :aria-checked="staffId === member.id"
                            x-on:click="pickStaff(member.id)" x-text="member.label ?? member.name"></button>
                </template>
                <button type="button" class="chip" role="radio" :aria-checked="staffId === null"
                        x-on:click="pickStaff(null)">Kdokoliv</button>
            </div>
        </section>
    </template>

    {{-- Služba --}}
    <section x-show="!fixed && (!boardOn || picked)">
        <h2 class="section-title" id="wizard-services">Služba</h2>
        <div role="group" aria-labelledby="wizard-services">
            <template x-for="service in visibleServices" :key="service.id">
                <button type="button" class="option-card" role="checkbox" :aria-checked="serviceIds.includes(service.id)"
                        x-on:click="toggleService(service.id)">
                    <div class="dot"></div>
                    <div><b x-text="service.name"></b><span x-text="metaFor(service)"></span></div>
                </button>
            </template>
        </div>
        <p class="empty" x-show="visibleServices.length === 0" x-cloak>Tato kadeřnice teď nemá v nabídce žádnou službu.</p>
        @error('services') <div class="field-error">{{ $message }}</div> @enderror
    </section>

    {{-- Termín --}}
    <section x-show="hasSelection && (!boardOn || picked)" x-cloak>
        <div class="flex items-baseline justify-between gap-3">
            <h2 class="section-title" id="wizard-days" x-text="boardOn ? 'Začátek' : 'Termín'">Termín</h2>
            <span class="label" x-show="!boardOn" x-text="monthLabel"></span>
        </div>

        {{-- Rozvrh: den je daný ťuknutím; když se služba od toho času nevejde, nabídnou se jiné začátky téhož dne. --}}
        <p class="banner banner-danger mb-2 font-normal" x-show="boardOn && loaded && !loading && selectedDay && selectedDay.times.length > 0 && time === null" x-cloak role="status">
            Od <span x-text="wantedTime"></span> se vybraná služba nevejde. Vyberte prosím jiný začátek:
        </p>
        <p class="banner banner-danger mb-2 font-normal" x-show="boardOn && loaded && !loading && (!selectedDay || selectedDay.times.length === 0)" x-cloak role="status">
            S touto službou se ten den k&nbsp;<span x-text="staffName"></span> už nevejdete. Zkuste v rozvrhu jiný den, nebo někoho jiného.
        </p>

        <div class="chips" role="group" aria-labelledby="wizard-days" x-show="!boardOn">
            <button type="button" class="chip" x-show="prev" x-on:click="shift(prev)" aria-label="Dřívější dny">‹</button>
            <template x-for="day in days" :key="day.date">
                <button type="button" class="chip" :class="{ 'is-off': day.times.length === 0 }" :disabled="!anyTime && day.times.length === 0"
                        :aria-pressed="date === day.date" :aria-label="day.label" x-on:click="pickDay(day.date)" x-text="day.chip"></button>
            </template>
            <button type="button" class="chip" x-show="next" x-on:click="shift(next)" aria-label="Další dny">›</button>
        </div>

        <p class="label mt-3" x-show="loading && !loaded" role="status">Hledám volné termíny…</p>

        <div class="empty mt-3" x-show="failed" role="alert">
            Termíny se nepodařilo načíst.
            <button type="button" class="btn btn-outline btn-sm mt-3" x-on:click="load(date)">Zkusit znovu</button>
        </div>

        <div class="empty mt-3" x-show="loaded && !failed && !hasAnySlot && !anyTime && !boardOn">
            V těchto dnech není žádný volný termín.
            <template x-if="next">
                <button type="button" class="btn btn-outline btn-sm mt-3" x-on:click="shift(next)">Zkusit další dny</button>
            </template>
        </div>

        <div class="times mt-2" x-show="selectedDay && selectedDay.times.length > 0" role="group" aria-label="Čas">
            <template x-for="slot in (selectedDay ? selectedDay.times : [])" :key="slot">
                <button type="button" class="chip" :aria-pressed="time === slot" x-on:click="pickTime(slot)" x-text="slot"></button>
            </template>
        </div>
        <p class="label mt-2" x-show="anyTime && loaded && selectedDay && selectedDay.times.length === 0" x-cloak>Tento den nemá žádný volný termín.</p>

        {{-- Administrace: kadeřnice si zákaznici zapíše i na obsazený čas nebo mimo pracovní dobu. --}}
        <template x-if="anyTime">
            <label class="field mt-3" x-show="loaded && date">
                <span>Jiný čas – i když je obsazeno nebo mimo pracovní dobu</span>
                <input class="input" type="time" step="300" :value="customTime" x-on:change="setCustomTime($event.target.value)" aria-describedby="wizard-any-time">
            </label>
        </template>
        <p class="banner mt-1 font-normal" id="wizard-any-time" x-show="forced && !needsStaff" x-cloak>
            Tento čas není mezi volnými termíny – kadeřnice v tu dobu má jinou zákaznici nebo nepracuje. Rezervace se přesto zapíše.
        </p>
        <p class="banner banner-danger mt-1 font-normal" x-show="needsStaff" x-cloak>
            Pro čas mimo volné termíny vyberte nahoře konkrétní kadeřnici.
        </p>
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

    {{-- S rozvrhem se kontakt ukáže až po výběru času – dřív by stránka vypadala jako formulář bez termínu. --}}
    @if ($withContact ?? false)
        <div x-show="!boardOn || picked">
            @include('partials.contact-fields')
        </div>
    @endif

    {{-- S rozvrhem se spodní lišta ukáže až po výběru času – dřív nemá co odeslat. --}}
    <div class="bottom-bar" x-show="!boardOn || picked">
        <a class="btn btn-outline" href="{{ $secondaryUrl }}">{{ $secondaryLabel }}</a>
        <button class="btn btn-primary" type="submit" :disabled="!ready">{{ $submitLabel ?? 'Rezervovat' }}</button>
    </div>

    </div>
</form>
