/**
 * Rozvrh v administraci: přetažení rezervace na jiný čas nebo do sloupce jiné kadeřnice.
 *
 * Myší se táhne hned, prstem až po krátkém podržení (jinak by nešlo stránkou posouvat).
 * Po puštění se přesun potvrzuje v okně; teprve pak se pošle na server a stránka se načte znovu.
 * Kliknutí bez tažení funguje dál jako odkaz na detail rezervace.
 *
 * config = {
 *   dayStart, dayEnd:  rozsah zobrazené osy v minutách od půlnoci,
 *   hourHeight:        výška hodiny v px,
 *   snap:              krok v minutách, na který se čas zaokrouhluje,
 *   moveUrl:           adresa pro uložení, s místem __ID__ pro číslo rezervace
 * }
 */
const HOLD_MS = 350; // podržení prstem, než se rezervace „zvedne“
const MOUSE_SLOP = 6; // o kolik px se musí myš pohnout, aby šlo o tažení a ne o klik
const TOUCH_SLOP = 10; // pohyb prstu během podržení = posouvání stránky, ne tažení
const EDGE = 70; // pruh u horního a dolního okraje okna, ve kterém se při tažení stránka sama posouvá

const format = (minutes) => `${Math.floor(minutes / 60)}:${String(minutes % 60).padStart(2, '0')}`;

export default (config) => ({
    move: null, // čeká na potvrzení: { id, staff, date, time, name, from, to }
    error: '',
    saving: false,

    drag: null,
    ghost: null,
    suppressClick: false,

    init() {
        const board = this.$refs.board;

        board.addEventListener('pointerdown', (event) => this.pointerDown(event));
        window.addEventListener('pointermove', (event) => this.pointerMove(event));
        window.addEventListener('pointerup', (event) => this.pointerUp(event));
        window.addEventListener('pointercancel', () => this.abort());

        // Jakmile je rezervace zvednutá, prst už stránkou neposouvá.
        document.addEventListener(
            'touchmove',
            (event) => {
                if (this.drag?.active && event.cancelable) {
                    event.preventDefault();
                }
            },
            { passive: false },
        );

        // Po tažení nesmí následovat klik, který by otevřel detail rezervace.
        board.addEventListener(
            'click',
            (event) => {
                if (this.suppressClick) {
                    event.preventDefault();
                    event.stopPropagation();
                    this.suppressClick = false;
                }
            },
            true,
        );

        // Dlouhé podržení odkazu jinak na telefonu otevírá nabídku prohlížeče.
        board.addEventListener('contextmenu', (event) => {
            if (this.drag) {
                event.preventDefault();
            }
        });
    },

    columns() {
        return [...this.$refs.board.querySelectorAll('.cal-col')];
    },

    /** Minuty od půlnoci v místě, kde je ukazatel ve sloupci. */
    minutesAt(column, clientY) {
        return config.dayStart + ((clientY - column.getBoundingClientRect().top) / config.hourHeight) * 60;
    },

    columnAt(clientX) {
        return this.columns().find((column) => {
            const rect = column.getBoundingClientRect();

            return clientX >= rect.left && clientX < rect.right;
        });
    },

    pointerDown(event) {
        const block = event.target.closest('[data-draggable]');

        if (!block || this.move || (event.pointerType === 'mouse' && event.button !== 0)) {
            return;
        }

        const column = block.closest('.cal-col');
        const start = Number(block.dataset.start);

        this.drag = {
            id: block.dataset.booking,
            name: block.dataset.name,
            start,
            duration: Math.max(Number(block.dataset.duration), config.snap),
            column,
            // Kde uvnitř rezervace ji uživatel chytil – ten bod zůstává pod ukazatelem.
            grab: this.minutesAt(column, event.clientY) - start,
            x: event.clientX,
            y: event.clientY,
            touch: event.pointerType !== 'mouse',
            active: false,
            target: null,
            timer: null,
        };

        if (this.drag.touch) {
            this.drag.timer = setTimeout(() => this.activate(), HOLD_MS);
        }
    },

    pointerMove(event) {
        const drag = this.drag;

        if (!drag) {
            return;
        }

        if (!drag.active) {
            const distance = Math.hypot(event.clientX - drag.x, event.clientY - drag.y);

            if (drag.touch) {
                // Prst se pohnul dřív, než se rezervace zvedla: uživatel posouvá stránku.
                if (distance > TOUCH_SLOP) {
                    this.abort();
                }

                return;
            }

            if (distance < MOUSE_SLOP) {
                return;
            }

            this.activate();
        }

        this.place(event.clientX, event.clientY);
    },

    activate() {
        const drag = this.drag;

        if (!drag || drag.active) {
            return;
        }

        drag.active = true;
        this.autoScroll();

        this.$refs.board.querySelectorAll(`[data-booking="${drag.id}"]`).forEach((part) => part.classList.add('is-dragging'));
        document.documentElement.classList.add('is-dragging-booking');

        this.ghost = document.createElement('div');
        this.ghost.className = 'cal-ghost';
        drag.column.appendChild(this.ghost);
        this.place(drag.x, drag.y);

        if (drag.touch && navigator.vibrate) {
            navigator.vibrate(10);
        }
    },

    /** U okraje okna stránku při tažení posouvá, aby šlo rezervaci dotáhnout i na čas, který není vidět. */
    autoScroll() {
        const step = () => {
            const drag = this.drag;

            if (!drag?.active) {
                return;
            }

            const y = drag.pointerY ?? drag.y;
            const speed = y < EDGE + 60 ? -Math.ceil((EDGE + 60 - y) / 6) : y > window.innerHeight - EDGE ? Math.ceil((y - (window.innerHeight - EDGE)) / 6) : 0;

            if (speed !== 0) {
                const before = window.scrollY;

                window.scrollBy(0, speed);

                if (window.scrollY !== before) {
                    this.place(drag.pointerX ?? drag.x, y);
                }
            }

            requestAnimationFrame(step);
        };

        requestAnimationFrame(step);
    },

    /** Posune obrys rezervace pod ukazatel: sloupec podle vodorovné polohy, čas zaokrouhlený na krok. */
    place(clientX, clientY) {
        const drag = this.drag;

        drag.pointerX = clientX;
        drag.pointerY = clientY;
        const column = this.columnAt(clientX) ?? drag.target?.column ?? drag.column;
        const latest = Math.max(config.dayStart, config.dayEnd - drag.duration);
        const raw = this.minutesAt(column, clientY) - drag.grab;
        const minutes = Math.min(latest, Math.max(config.dayStart, Math.round(raw / config.snap) * config.snap));

        drag.target = { column, minutes };

        if (this.ghost.parentElement !== column) {
            column.appendChild(this.ghost);
        }

        this.ghost.style.top = `${((minutes - config.dayStart) / 60) * config.hourHeight}px`;
        this.ghost.style.height = `${(drag.duration / 60) * config.hourHeight}px`;
        this.ghost.textContent = `${format(minutes)} · ${drag.name}`;
    },

    pointerUp(event) {
        const drag = this.drag;

        if (!drag) {
            return;
        }

        clearTimeout(drag.timer);

        if (!drag.active) {
            this.drag = null; // obyčejný klik nebo ťuknutí

            return;
        }

        // Klik, který prohlížeč po puštění ještě pošle, nesmí otevřít detail.
        this.suppressClick = true;
        setTimeout(() => (this.suppressClick = false), 400);

        this.place(event.clientX, event.clientY);

        const target = drag.target;

        if (target.column === drag.column && target.minutes === drag.start) {
            this.abort();

            return;
        }

        this.error = '';
        this.move = {
            id: drag.id,
            staff: target.column.dataset.staff,
            date: target.column.dataset.date,
            time: format(target.minutes),
            name: drag.name,
            from: `${drag.column.dataset.label} v ${format(drag.start)}`,
            to: `${target.column.dataset.label} v ${format(target.minutes)}`,
            warning: this.warningFor(drag, target),
        };

        this.drag = null;
        document.documentElement.classList.remove('is-dragging-booking');
        this.$refs.dialog.showModal();
    },

    /**
     * Upozornění do potvrzovacího okna: nový čas je mimo pracovní dobu nebo se kryje s jinou
     * rezervací. Počítá se z toho, co je v rozvrhu nakreslené; přesunu to nebrání.
     */
    warningFor(drag, target) {
        const top = ((target.minutes - config.dayStart) / 60) * config.hourHeight;
        const bottom = top + (drag.duration / 60) * config.hourHeight;
        const span = (element) => {
            const from = parseFloat(element.style.top);

            return [from, from + parseFloat(element.style.height)];
        };
        const who = target.column.dataset.label;

        const open = [...target.column.querySelectorAll('.cal-open')].map(span);

        if (!open.some(([from, to]) => top >= from - 0.5 && bottom <= to + 0.5)) {
            return `Pozor: ${who} – v tu dobu se nepracuje.`;
        }

        const busy = [...target.column.querySelectorAll('.cal-block')]
            .filter((block) => block.dataset.booking !== drag.id)
            .map(span);

        return busy.some(([from, to]) => top < to - 0.5 && bottom > from + 0.5) ? `Pozor: ${who} – v tu dobu už je jiná zákaznice. Budou dvě najednou.` : '';
    },

    /** Uklidí rozpracované tažení (zrušení, klávesa Esc, přerušení prohlížečem). */
    abort() {
        clearTimeout(this.drag?.timer);

        this.$refs.board.querySelectorAll('.is-dragging').forEach((part) => part.classList.remove('is-dragging'));
        document.documentElement.classList.remove('is-dragging-booking');
        this.ghost?.remove();
        this.ghost = null;
        this.drag = null;
    },

    cancel() {
        if (this.saving) {
            return;
        }

        this.move = null;
        this.error = '';
        this.abort();

        if (this.$refs.dialog.open) {
            this.$refs.dialog.close();
        }
    },

    async confirm() {
        if (!this.move || this.saving) {
            return;
        }

        this.saving = true;
        this.error = '';

        try {
            const response = await fetch(config.moveUrl.replace('__ID__', this.move.id), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                },
                body: JSON.stringify({ staff: this.move.staff, date: this.move.date, time: this.move.time }),
            });

            const data = await response.json().catch(() => ({}));

            if (!response.ok) {
                throw new Error(data.message || 'Přesun se nepodařilo uložit.');
            }

            // Server si výsledek poznamenal; po načtení se ukáže nahoře a rozvrh se překreslí.
            window.location.reload();
        } catch (error) {
            this.error = error.message || 'Přesun se nepodařilo uložit.';
            this.saving = false;
        }
    },
});
