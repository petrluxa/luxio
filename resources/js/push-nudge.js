/**
 * Výzva v administraci: „na tomto zařízení nemáte zapnutá upozornění“.
 *
 * Upozornění se zapínají pro každé zařízení zvlášť a nastavení je schované v účtu – bez výzvy by
 * kadeřnice na telefonu o nové rezervaci nevěděla a nikdo by netušil proč. Výzva jde odložit
 * („teď ne“) a po týdnu se připomene.
 */
import { registerWorker } from './worker';

const KEY = 'luxio-push-nudge';
const WEEK = 7 * 24 * 3600 * 1000;

export default () => ({
    show: false,
    needsInstall: false, // iPhone: upozornění jdou zapnout až v aplikaci přidané na plochu

    async init() {
        try {
            if (Date.now() - Number(localStorage.getItem(KEY) || 0) < WEEK) {
                return;
            }
        } catch (error) {
            // Bez úložiště se výzva ukáže při každém načtení – pořád lepší než mlčet.
        }

        const ios = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
        const standalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;

        if (ios && !standalone) {
            this.needsInstall = true;
            this.show = true;

            return;
        }

        // Prohlížeč, který upozornění neumí, není čím otravovat.
        if (!('serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window)) {
            return;
        }

        try {
            await registerWorker();

            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.getSubscription();

            this.show = !(subscription && Notification.permission === 'granted');
        } catch (error) {
            this.show = false;
        }
    },

    later() {
        this.show = false;

        try {
            localStorage.setItem(KEY, String(Date.now()));
        } catch (error) {
            // Bez úložiště platí odložení jen do dalšího načtení stránky.
        }
    },
});
