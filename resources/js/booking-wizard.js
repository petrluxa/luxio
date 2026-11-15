/**
 * Rezervační průvodce: kadeřnice → služby → den → čas → shrnutí.
 * Stav drží Alpine, volné termíny se načítají ze serveru po 14 dnech
 * a formulář se odešle klasicky (skrytá pole), takže ověření zůstává na serveru.
 *
 * config = {
 *   staff:    [{ id, name }],
 *   services: [{ id, name, meta, staffIds }],
 *   slotsUrl: adresa pro načtení termínů,
 *   replaces: id přesouvané rezervace | null,
 *   selected: { staffId, serviceIds, date, time }   – předvýběr / návrat po chybě
 * }
 */
export default (config) => ({
    staff: config.staff,
    services: config.services,

    staffId: null,
    serviceIds: [],
    date: null,
    time: null,

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

        if (this.serviceIds.length) {
            this.load(this.date, true);
        }
    },

    get visibleServices() {
        if (this.staffId === null) {
            return this.services;
        }

        return this.services.filter((s) => s.staffIds.includes(this.staffId));
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

    get ready() {
        return this.serviceIds.length > 0 && this.date !== null && this.time !== null && !this.submitting;
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
    },

    /** Změna kadeřnice nebo služeb: výběr termínu už neplatí, načíst znovu od dneška. */
    refresh() {
        this.date = null;
        this.time = null;

        if (this.serviceIds.length) {
            this.load(null);
        } else {
            this.requestNo++;
            this.days = [];
            this.summary = null;
            this.loaded = false;
            this.loading = false;
        }
    },

    async load(from = null, keepSelection = false) {
        const requestNo = ++this.requestNo;
        const params = new URLSearchParams();

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
            const response = await fetch(`${config.slotsUrl}?${params}`, {
                headers: { Accept: 'application/json' },
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
                const keepDay = keepSelection && this.selectedDay && this.selectedDay.times.length > 0;

                this.time = null;
                this.date = keepDay ? this.date : (this.days.find((d) => d.times.length > 0)?.date ?? null);
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
