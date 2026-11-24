/**
 * Rezervační průvodce: kadeřnice → služby → den → čas → shrnutí.
 * Stav drží Alpine, volné termíny se načítají ze serveru po 14 dnech
 * a formulář se odešle klasicky (skrytá pole), takže ověření zůstává na serveru.
 *
 * config = {
 *   staff:    [{ id, name }],
 *   services: [{ id, name, meta, metaByStaff, staffIds }],   – meta = délka · cena; každá kadeřnice má svůj ceník,
 *   slotsUrl: adresa pro načtení termínů,
 *   replaces: id přesouvané rezervace | null,
 *   fixed:    true = služby jsou dané (přesun termínu v administraci), vybírá se jen den a čas,
 *   anyTime:  true = administrace: jde zadat i čas mimo volné termíny (kadeřnice dělá dvě
 *             zákaznice najednou nebo pracuje mimo svou dobu); formulář pak pošle force=1,
 *   selected: { staffId, serviceIds, date, time },  – předvýběr / návrat po chybě
 *   boardUrl: adresa rozvrhu dne celého salonu | null
 * }
 *
 * S `boardUrl` (zákaznice u salonu s víc lidmi) začíná průvodce rozvrhem: sloupec pro každou
 * kadeřnici, volné a obsazené časy. Ťuknutím na volný čas vybere zákaznice kadeřnici, den i čas
 * najednou a potom teprve službu; chipy s kadeřnicemi a dny se neukazují.
 */

/** Minuty od půlnoci → 9:30 (stejný zápis, jaký vrací nabídka termínů). */
const clock = (minute) => `${Math.floor(minute / 60)}:${String(minute % 60).padStart(2, '0')}`;

export default (config) => ({
    staff: config.staff,
    services: config.services,
    fixed: config.fixed === true,
    anyTime: config.anyTime === true,

    staffId: null,
    serviceIds: [],
    date: null,
    time: null,

    // Den a čas, se kterým průvodce začal (ťuknutí do kalendáře, návrat po chybě).
    // Platí, dokud si uživatel sám nevybere jiný den.
    wantedDate: null,
    wantedTime: null,

    days: [],
    prev: null,
    next: null,
    summary: null,

    loading: false,
    loaded: false,
    failed: false,
    submitting: false,
    requestNo: 0,

    // Rozvrh dne celého salonu (jen s config.boardUrl).
    boardOn: Boolean(config.boardUrl) && config.staff.length > 1,
    board: null,
    boardLoading: false,
    boardFailed: false,
    boardRequestNo: 0,
    boardStaffId: null, // čí týden se ukazuje; null = den celého salonu
    cal: { open: false, loading: false, data: null }, // měsíční kalendář pro skok na jiný den
    picked: false, // zákaznice už ťukla na volný čas
    pickedWhen: '', // „út 24. 11. v 10:00“
    pan: { left: false, right: false },

    init() {
        const selected = config.selected ?? {};
        const known = (id) => this.staff.some((s) => s.id === id);

        // Jediná kadeřnice je vybraná rovnou, jinak předvýběr nebo „Kdokoliv“.
        this.staffId = this.staff.length === 1 ? this.staff[0].id : known(selected.staffId) ? selected.staffId : null;
        this.date = selected.date ?? null;
        this.time = selected.time ?? null;

        if (this.boardOn) {
            // V rozvrhu platí výběr jen celý (kadeřnice, den i čas) – typicky návrat po chybě ve formuláři.
            this.picked = this.staffId !== null && this.date !== null && this.time !== null;

            if (!this.picked) {
                this.staffId = null;
                this.date = null;
                this.time = null;
            }
        }

        this.serviceIds = (selected.serviceIds ?? []).filter((id) => this.visibleServices.some((s) => s.id === id));
        this.wantedDate = this.date;
        this.wantedTime = this.time;

        if (this.boardOn) {
            // Rozvrh začíná vždy celým salonem; jen po návratu z chyby týdnem vybrané kadeřnice.
            this.boardStaffId = this.picked ? this.staffId : null;
            this.loadBoard(this.date);
            window.addEventListener('resize', () => this.updatePan());
        }

        if (this.hasSelection && (!this.boardOn || this.picked)) {
            this.load(this.date, true);
        }
    },

    /** Řádky rozvrhu po půl hodině. */
    get boardRows() {
        const rows = [];

        for (let minute = this.board?.from ?? 0; minute < (this.board?.to ?? 0); minute += 30) {
            rows.push({ minute, label: clock(minute).padStart(5, '0'), half: minute % 60 !== 0 });
        }

        return rows;
    },

    /** Sloupce rozvrhu: den celého salonu = lidé, týden jedné kadeřnice = dny. */
    get boardColumns() {
        return this.board?.columns ?? [];
    },

    /** Nadpis nad rozvrhem: den, nebo jméno kadeřnice, jejíž týden je vidět. */
    get boardTitle() {
        if (!this.board) {
            return 'Rozvrh';
        }

        return this.board.mode === 'week' ? (this.staff.find((s) => s.id === this.board.staffId)?.name ?? '') : this.board.label;
    },

    /** Obsazené půlhodiny sloupce spojené do souvislých bloků. */
    blocks(column) {
        const blocks = [];

        for (const minute of column.taken) {
            const last = blocks[blocks.length - 1];

            if (last && last.to === minute) {
                last.to = minute + 30;
            } else {
                blocks.push({ from: minute, to: minute + 30 });
            }
        }

        return blocks;
    },

    /** Vybraný čas zakreslený do rozvrhu (dokud není služba, jen půlhodina od ťuknutého času). */
    get mine() {
        if (!this.boardOn || !this.picked || !this.board || this.staffId === null) {
            return null;
        }

        const column = this.boardColumns.find((c) => c.staffId === this.staffId && c.date === this.wantedDate);
        const time = this.time ?? (this.serviceIds.length ? null : this.wantedTime);

        if (!column || !time) {
            return null;
        }

        const [hours, minutes] = time.split(':').map(Number);
        const from = hours * 60 + minutes;
        const length = this.summary?.minutes ?? 30;

        return { key: column.key, from, to: from + length, label: `${time} – ${clock(from + length)}` };
    },

    clock,

    /** Svislá poloha a výška v rozvrhu (řádek = půl hodiny = --cal-half). */
    boardBox(from, to) {
        const top = Math.max(from, this.board.from);
        const bottom = Math.min(to, this.board.to);

        return `top: calc(var(--cal-half) * ${(top - this.board.from) / 30}); height: calc(var(--cal-half) * ${Math.max(0, bottom - top) / 30})`;
    },

    /** Ťuknutí na kadeřnici: její týden od dne, který je právě vidět. `null` = zpět na celý salon. */
    showStaff(id) {
        this.boardStaffId = id;
        this.loadBoard(this.board?.date ?? null);
    },

    async loadBoard(date = null) {
        const requestNo = ++this.boardRequestNo;
        const url = new URL(config.boardUrl, window.location.href);

        if (date) {
            url.searchParams.set('den', date);
        }

        if (this.boardStaffId !== null) {
            url.searchParams.set('kadernice', this.boardStaffId);
        }

        this.boardLoading = true;
        this.boardFailed = false;

        try {
            const response = await fetch(url, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();

            if (requestNo === this.boardRequestNo) {
                const reshaped = this.board?.mode !== data.mode;

                this.board = data;
                this.$nextTick(() => {
                    // Jiný pohled (salon ↔ týden) začíná od prvního sloupce.
                    if (reshaped && this.$refs.boardScroll) {
                        this.$refs.boardScroll.scrollLeft = 0;
                    }

                    this.updatePan();
                });
            }
        } catch (error) {
            if (requestNo === this.boardRequestNo) {
                this.boardFailed = true;
            }
        } finally {
            if (requestNo === this.boardRequestNo) {
                this.boardLoading = false;
            }
        }
    },

    /** Ťuknutí na volný čas v rozvrhu: kadeřnice, den i čas najednou; služba se vybírá potom. */
    pickCell(column, minute) {
        this.picked = true;
        this.staffId = column.staffId;
        this.serviceIds = this.serviceIds.filter((id) => this.visibleServices.some((s) => s.id === id));
        this.wantedDate = column.date;
        this.wantedTime = clock(minute);
        this.pickedWhen = `${column.short} v ${this.wantedTime}`;
        this.date = column.date;
        this.time = null;

        if (this.hasSelection) {
            this.load(this.wantedDate);
        } else {
            this.requestNo++;
            this.days = [];
            this.summary = null;
            this.loaded = false;
            this.loading = false;
        }

        this.$nextTick(() => this.$refs.choice?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
    },

    /** Výběr času z nabídky pod službami. V rozvrhu si ho průvodce pamatuje i po změně služeb. */
    pickTime(slot) {
        this.time = slot;

        if (this.boardOn) {
            this.wantedTime = slot;
            this.pickedWhen = this.selectedDay ? `${this.selectedDay.label} v ${slot}` : this.pickedWhen;
        }
    },

    /** „Jiný den“: rozbalí měsíční kalendář s měsícem, který je právě v rozvrhu vidět. */
    toggleCal() {
        this.cal.open = !this.cal.open;

        if (this.cal.open) {
            this.loadMonth(this.board?.date.slice(0, 7) ?? null);
        }
    },

    async loadMonth(month = null) {
        const url = new URL(config.monthUrl, window.location.href);

        if (month) {
            url.searchParams.set('mesic', month);
        }

        if (this.boardStaffId !== null) {
            url.searchParams.set('kadernice', this.boardStaffId);
        }

        this.cal.loading = true;

        try {
            const response = await fetch(url, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });

            if (response.ok) {
                this.cal.data = await response.json();
            }
        } catch (error) {
            // Kalendář zůstane, jak byl; den jde pořád změnit šipkami.
        } finally {
            this.cal.loading = false;
        }
    },

    /** Den, který je v rozvrhu právě vidět (v týdnu všech sedm) – v kalendáři je zvýrazněný. */
    isShown(date) {
        return this.boardColumns.some((column) => column.date === date);
    },

    jumpTo(date) {
        this.cal.open = false;
        this.loadBoard(date);
    },

    /** Posun rozvrhu do strany šipkami (na telefonu stačí prst); záhlaví se jmény jede s ním. */
    updatePan() {
        const scroll = this.$refs.boardScroll;

        if (!scroll) {
            return;
        }

        if (this.$refs.boardHead) {
            this.$refs.boardHead.scrollLeft = scroll.scrollLeft;
        }

        this.pan = {
            left: scroll.scrollLeft > 2,
            right: scroll.scrollLeft + scroll.clientWidth < scroll.scrollWidth - 2,
        };
    },

    panBy(direction) {
        const scroll = this.$refs.boardScroll;

        scroll?.scrollBy({ left: direction * Math.max(200, scroll.clientWidth * 0.6), behavior: 'smooth' });
    },

    /** Je z čeho počítat termíny? */
    get hasSelection() {
        return this.fixed || this.serviceIds.length > 0;
    },

    get visibleServices() {
        if (this.staffId === null) {
            return this.services;
        }

        return this.services.filter((s) => s.staffIds.includes(this.staffId));
    },

    /** Délka a cena služby u vybrané kadeřnice (u volby „Kdokoliv“ souhrnně). */
    metaFor(service) {
        return (this.staffId !== null && service.metaByStaff?.[this.staffId]) || service.meta;
    },

    get selectedDay() {
        return this.days.find((d) => d.date === this.date) ?? null;
    },

    get hasAnySlot() {
        return this.days.some((d) => d.times.length > 0);
    },

    get monthLabel() {
        if (!this.days.length) {
            return '';
        }

        const first = this.days[0].month;
        const last = this.days[this.days.length - 1].month;

        return first === last ? first : `${first.split(' ')[0]} – ${last}`;
    },

    get staffName() {
        return this.staffId === null ? 'Kdokoliv' : (this.staff.find((s) => s.id === this.staffId)?.name ?? '');
    },

    get serviceNames() {
        return this.services
            .filter((s) => this.serviceIds.includes(s.id))
            .map((s) => s.name)
            .join(' + ');
    },

    get whenLabel() {
        return this.selectedDay && this.time ? `${this.selectedDay.label} v ${this.time}` : '';
    },

    /** Vybraný čas není mezi volnými termíny (zadaný ručně nebo ťuknutím do obsazeného místa). */
    get forced() {
        return this.anyTime && this.time !== null && !(this.selectedDay?.times ?? []).includes(this.time);
    },

    /** Hodnota pole „Jiný čas“ (HH:MM). */
    get customTime() {
        return this.forced ? this.time.padStart(5, '0') : '';
    },

    /** Čas mimo volné termíny jde zapsat jen konkrétní kadeřnici, ne volbě „Kdokoliv“. */
    get needsStaff() {
        return this.forced && this.staffId === null;
    },

    get ready() {
        return this.hasSelection && this.date !== null && this.time !== null && !this.needsStaff && !this.submitting;
    },

    pickStaff(id) {
        if (this.staffId === id) {
            return;
        }

        this.staffId = id;
        this.serviceIds = this.serviceIds.filter((sid) => this.visibleServices.some((s) => s.id === sid));
        this.refresh();
    },

    toggleService(id) {
        this.serviceIds = this.serviceIds.includes(id)
            ? this.serviceIds.filter((sid) => sid !== id)
            : [...this.serviceIds, id];
        this.refresh();
    },

    pickDay(date) {
        this.date = date;
        this.time = null;
        this.wantedDate = null;
        this.wantedTime = null;
    },

    /** Ručně zadaný čas z pole „Jiný čas“: 09:05 → 9:05, prázdné pole výběr zruší. */
    setCustomTime(value) {
        const match = /^(\d{1,2}):(\d{2})$/.exec(value ?? '');

        this.time = match ? `${parseInt(match[1], 10)}:${match[2]}` : null;
    },

    /** Změna kadeřnice nebo služeb: výběr termínu už neplatí, načíst znovu od dneška. */
    refresh() {
        this.date = null;
        this.time = null;

        if (this.hasSelection) {
            this.load(this.wantedDate);
        } else {
            this.requestNo++;
            this.days = [];
            this.summary = null;
            this.loaded = false;
            this.loading = false;
        }
    },

    /** Posun okna dnů šipkou. */
    shift(from) {
        this.wantedDate = null;
        this.wantedTime = null;
        this.load(from);
    },

    async load(from = null, keepSelection = false) {
        const requestNo = ++this.requestNo;
        // Adresa už může mít vlastní parametry (při přesunu číslo přesouvané rezervace) –
        // další se k nim přidávají, nesmí se lepit za druhý otazník.
        const url = new URL(config.slotsUrl, window.location.href);
        const params = url.searchParams;

        this.serviceIds.forEach((id) => params.append('services[]', id));

        if (this.staffId !== null) {
            params.set('staff', this.staffId);
        }

        if (from) {
            params.set('from', from);
        }

        if (config.replaces) {
            params.set('replaces', config.replaces);
        }

        this.loading = true;
        this.failed = false;

        try {
            const response = await fetch(url, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();

            // Mezitím se výběr změnil – tahle odpověď už neplatí.
            if (requestNo !== this.requestNo) {
                return;
            }

            this.days = data.days;
            this.prev = data.prev;
            this.next = data.next;
            this.summary = data.summary;
            this.loaded = true;

            // Rozvrh: den i čas určilo ťuknutí. Když se vybraná služba od toho času nevejde,
            // zůstává den a zákaznice si vybere jiný začátek – průvodce sám jinam neskáče.
            if (this.boardOn) {
                const day = this.days.find((d) => d.date === this.wantedDate) ?? null;

                this.date = day ? day.date : null;
                this.time = day && day.times.includes(this.wantedTime) ? this.wantedTime : null;

                if (day && this.wantedTime) {
                    this.pickedWhen = `${day.label} v ${this.wantedTime}`;
                }

                return;
            }

            const stillValid = keepSelection && this.selectedDay && this.selectedDay.times.includes(this.time);

            if (!stillValid) {
                // V administraci platí i den bez volného termínu a čas mimo nabídku.
                const usable = (d) => this.anyTime || d.times.length > 0;
                const keepDay = keepSelection && this.selectedDay && usable(this.selectedDay);
                const wanted = this.days.find((d) => d.date === this.wantedDate && usable(d));
                const first = this.days.find((d) => d.times.length > 0) ?? (this.anyTime ? this.days[0] : null);

                this.time = null;
                this.date = keepDay ? this.date : (wanted ?? first)?.date ?? null;

                if (wanted && this.date === wanted.date && this.wantedTime && (this.anyTime || wanted.times.includes(this.wantedTime))) {
                    this.time = this.wantedTime;
                }
            }
        } catch (error) {
            if (requestNo === this.requestNo) {
                this.failed = true;
            }
        } finally {
            if (requestNo === this.requestNo) {
                this.loading = false;
            }
        }
    },
});
