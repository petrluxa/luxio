import Alpine from 'alpinejs';
import bookingWizard from './booking-wizard';
import pushSetup from './push';

window.Alpine = Alpine;

Alpine.data('bookingWizard', bookingWizard);
Alpine.data('pushSetup', pushSetup);

Alpine.start();

// Service worker (push upozornění, offline stránka). Zákaznická část mu navíc sdělí
// svou domovskou adresu, aby klik na upozornění otevřel správnou rezervaci.
if ('serviceWorker' in navigator) {
    window.addEventListener('load', async () => {
        try {
            await navigator.serviceWorker.register('/sw.js');

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
