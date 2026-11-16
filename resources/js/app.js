import Alpine from 'alpinejs';
import bookingWizard from './booking-wizard';
import calendarBoard from './calendar-board';
import pushSetup from './push';
import { registerWorker } from './worker';

window.Alpine = Alpine;

Alpine.data('bookingWizard', bookingWizard);
Alpine.data('calendarBoard', calendarBoard);
Alpine.data('pushSetup', pushSetup);

// Světlá / tmavá verze: podle zařízení, dokud si ji uživatel nepřepne sám (pamatuje si to prohlížeč).
const prefersDark = window.matchMedia('(prefers-color-scheme: dark)');

Alpine.store('theme', {
    mode: null, // 'light' | 'dark' | null = podle zařízení
    dark: false,

    init() {
        const chosen = document.documentElement.dataset.theme;

        this.mode = chosen === 'light' || chosen === 'dark' ? chosen : null;
        this.apply();
        prefersDark.addEventListener('change', () => this.apply());
    },

    toggle() {
        this.set(this.dark ? 'light' : 'dark');
    },

    set(mode) {
        this.mode = mode;

        try {
            mode ? localStorage.setItem('luxio-theme', mode) : localStorage.removeItem('luxio-theme');
        } catch (error) {
            // Bez úložiště volba platí jen do zavření stránky.
        }

        this.apply();
    },

    apply() {
        const root = document.documentElement;

        this.mode ? root.setAttribute('data-theme', this.mode) : root.removeAttribute('data-theme');
        this.dark = this.mode ? this.mode === 'dark' : prefersDark.matches;

        // Barva lišty prohlížeče a systému na telefonu.
        document.querySelectorAll('meta[name="theme-color"]').forEach((meta) => {
            meta.removeAttribute('media');
            meta.setAttribute('content', this.dark ? '#1C1713' : '#EFE7DB');
        });
    },
});

Alpine.start();

// Service worker (push upozornění, offline stránka). Zákaznická část mu navíc sdělí
// svou domovskou adresu, aby klik na upozornění otevřel správnou rezervaci.
if ('serviceWorker' in navigator) {
    window.addEventListener('load', async () => {
        try {
            await registerWorker();

            const base = document.documentElement.dataset.base;

            if (base) {
                const registration = await navigator.serviceWorker.ready;

                registration.active?.postMessage({ type: 'base', base });
            }
        } catch (error) {
            // Bez service workeru aplikace funguje dál, jen bez upozornění.
        }
    });
}
