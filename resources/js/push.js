/**
 * „Přidat na plochu“ a zapnutí upozornění (Web Push).
 *
 * - iPhone: upozornění fungují jen v aplikaci přidané na plochu (iOS 16.4+)
 *   a povolení se smí vyžádat pouze po ťuknutí na tlačítko.
 * - Android / Chrome: instalaci nabídne událost `beforeinstallprompt`.
 *
 * config = { vapidKey, subscribeUrl }
 */
import { registerWorker } from './worker';

const toUint8Array = (base64) => {
    const padded = (base64 + '='.repeat((4 - (base64.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');

    return Uint8Array.from(atob(padded), (char) => char.charCodeAt(0));
};

export default (config) => ({
    platform: 'desktop', // ios | android | desktop
    inAppBrowser: false, // Instagram, Facebook… – odtud na plochu přidat nejde
    standalone: false,
    supported: false,
    permission: 'default',
    subscribed: false,
    canPrompt: false,
    busy: false,
    error: '',
    installEvent: null,

    async init() {
        const ua = navigator.userAgent;
        const ios = /iPad|iPhone|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

        this.platform = ios ? 'ios' : /Android/.test(ua) ? 'android' : 'desktop';
        this.inAppBrowser = /Instagram|FBAN|FBAV|FB_IAB|Messenger|Line\//.test(ua);
        this.standalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
        this.supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window && !!config.vapidKey;
        this.permission = 'Notification' in window ? Notification.permission : 'denied';

        window.addEventListener('beforeinstallprompt', (event) => {
            event.preventDefault();
            this.installEvent = event;
            this.canPrompt = true;
        });

        window.addEventListener('appinstalled', () => {
            this.canPrompt = false;
            this.installEvent = null;
            this.standalone = true;
        });

        if (!this.supported) {
            return;
        }

        try {
            const registration = await this.registration();
            const subscription = await registration.pushManager.getSubscription();

            this.subscribed = subscription !== null && this.permission === 'granted';

            // Odběr se může časem změnit – při každé návštěvě ho serveru připomeneme.
            if (this.subscribed) {
                await this.save(subscription);
            }
        } catch (error) {
            // Bez service workeru zůstane nabídka zapnutí, chyba se ukáže až při pokusu.
        }
    },

    /** Na iPhonu se dá upozornění zapnout až v aplikaci otevřené z plochy. */
    get needsInstallFirst() {
        return this.platform === 'ios' && !this.standalone;
    },

    async registration() {
        await registerWorker();

        return navigator.serviceWorker.ready;
    },

    async install() {
        if (!this.installEvent) {
            return;
        }

        this.installEvent.prompt();
        await this.installEvent.userChoice;
        this.installEvent = null;
        this.canPrompt = false;
    },

    /** Musí se volat přímo z ťuknutí na tlačítko (požadavek iOS). */
    async enable() {
        this.error = '';
        this.busy = true;

        try {
            this.permission = await Notification.requestPermission();

            if (this.permission !== 'granted') {
                return;
            }

            const registration = await this.registration();
            const subscription =
                (await registration.pushManager.getSubscription()) ??
                (await registration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: toUint8Array(config.vapidKey),
                }));

            await this.save(subscription);
            this.subscribed = true;
        } catch (error) {
            this.error = 'Upozornění se nepodařilo zapnout. Zkuste to prosím znovu.';
        } finally {
            this.busy = false;
        }
    },

    async disable() {
        this.error = '';
        this.busy = true;

        try {
            const registration = await this.registration();
            const subscription = await registration.pushManager.getSubscription();

            if (subscription) {
                await this.request('DELETE', { endpoint: subscription.endpoint });
                await subscription.unsubscribe();
            }

            this.subscribed = false;
        } catch (error) {
            this.error = 'Upozornění se nepodařilo vypnout. Zkuste to prosím znovu.';
        } finally {
            this.busy = false;
        }
    },

    save(subscription) {
        const encoding = (PushManager.supportedContentEncodings || ['aesgcm'])[0];

        return this.request('POST', { ...subscription.toJSON(), contentEncoding: encoding });
    },

    async request(method, body) {
        const response = await fetch(config.subscribeUrl, {
            method,
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            body: JSON.stringify(body),
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
    },
});
