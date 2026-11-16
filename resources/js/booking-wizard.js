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
 *   selected: { staffId, serviceIds, date, time }   – předvýběr / návrat po chybě
 * }
 */
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

    init() {
        const selected = config.selected ?? {};
        const known = (id) => this.staff.some((s) => s.id === id);

        // Jediná kadeřnice je vybraná rovnou, jinak předvýběr nebo „Kdokoliv“.
        this.staffId = this.staff.length === 1 ? this.staff[0].id : known(selected.staffId) ? selected.staffId : null;
        this.serviceIds = (selected.serviceIds ?? []).filter((id) => this.visibleServices.some((s) => s.id === id));
        this.date = selected.date ?? null;
        this.time = selected.time ?? null;
        this.wantedDate = this.date;
        this.wantedTime = this.time;

        if (this.hasSelection) {
            this.load(this.date, true);
        }
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
