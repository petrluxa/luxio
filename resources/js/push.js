/**
 * „Přidat na plochu“ a zapnutí upozornění (Web Push).
 *
 * - iPhone: upozornění fungují jen v aplikaci přidané na plochu (iOS 16.4+)
 *   a povolení se smí vyžádat pouze po ťuknutí na tlačítko.
 * - Android / Chrome: instalaci nabídne událost `beforeinstallprompt`.
 *
 * config = { vapidKey, subscribeUrl, testUrl }
 */
import { registerWorker } from './worker';

const toUint8Array = (base64) => {
    const padded = (base64 + '='.repeat((4 - (base64.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');

    return Uint8Array.from(atob(padded), (char) => char.charCodeAt(0));
};

/** Patří odběr zařízení ke klíči tohoto serveru? (Když prohlížeč klíč neprozradí, bereme, že ano.) */
const sameKey = (subscription, key) => {
    const current = subscription.options?.applicationServerKey;

    if (!current) {
        return true;
    }

    const bytes = new Uint8Array(current);

    return bytes.length === key.length && bytes.every((byte, index) => byte === key[index]);
};

/** Chyba telefonu nebo serveru jednou větou – pro člověka, který ji přepošle dál. */
const describe = (error) => [error?.name, error?.message].filter(Boolean).join(': ');

const EXPIRED_PAGE = 'Stránka je otevřená příliš dlouho. Zavřete ji, otevřete znovu a zkuste to ještě jednou.';

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
    stillDenied: false, // uživatel říká, že upozornění v telefonu povolil, ale telefon je dál hlásí jako zakázaná
    detail: '', // doslovná chyba od telefonu nebo serveru (malým písmem pod hláškou)
    testing: false,
    testResult: '', // '' | 'sent' | 'failed' – výsledek zkušebního upozornění
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

        // Kdo si upozornění povolí v nastavení telefonu a vrátí se do aplikace, má to vidět hned –
        // aplikace z plochy se sama znovu nenačte.
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') {
                this.refresh();
            }
        });
        window.addEventListener('pageshow', () => this.refresh());

        await this.refresh();
    },

    /** Načte aktuální stav: povolení od telefonu a jestli má zařízení platný odběr. */
    async refresh() {
        this.standalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
        this.permission = 'Notification' in window ? Notification.permission : 'denied';

        if (this.permission !== 'denied') {
            this.stillDenied = false;
        }

        if (!this.supported) {
            return;
        }

        try {
            const registration = await this.registration();
            const subscription = await registration.pushManager.getSubscription();

            this.subscribed = subscription !== null && this.permission === 'granted';

            // Odběr se může časem změnit – při každé návštěvě ho serveru připomeneme.
            // Odběr založený pro jiný klíč serveru by nikdy nic nedoručil, proto se založí znovu.
            if (this.subscribed) {
                await (sameKey(subscription, toUint8Array(config.vapidKey)) ? this.save(subscription) : this.subscribe(true));
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

        // Bez časového limitu by tlačítko při potíži zůstalo navždy šedé.
        return Promise.race([
            navigator.serviceWorker.ready,
            new Promise((_, reject) => setTimeout(() => reject(new Error('Část aplikace pro upozornění se nespustila.')), 15000)),
        ]);
    },

    /**
     * Založí odběr zařízení a uloží ho na server. `fresh` zahodí ten dosavadní
     * (přestal platit nebo patří k jinému klíči serveru).
     */
    async subscribe(fresh = false) {
        const registration = await this.registration();
        const key = toUint8Array(config.vapidKey);
        let subscription = await registration.pushManager.getSubscription();

        if (subscription && (fresh || !sameKey(subscription, key))) {
            await this.request('DELETE', { endpoint: subscription.endpoint }).catch(() => {});
            await subscription.unsubscribe();
            subscription = null;
        }

        subscription ??= await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key });

        await this.save(subscription);

        return subscription;
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
        this.detail = '';
        this.testResult = '';
        this.busy = true;

        try {
            const before = Notification.permission;

            this.permission = await Notification.requestPermission();

            if (this.permission !== 'granted') {
                // Telefon se už neptá (dřív odmítnuto) – ukázat, jak dál, místo tichého nic.
                this.stillDenied = this.permission === 'denied' && before === 'denied';

                return;
            }

            this.stillDenied = false;

            await this.subscribe();
            this.subscribed = true;
        } catch (error) {
            this.error = error.expiredPage ? EXPIRED_PAGE : 'Upozornění se nepodařilo zapnout. Zkuste to prosím znovu.';
            this.detail = error.expiredPage ? '' : describe(error);
        } finally {
            this.busy = false;
        }
    },

    /**
     * Pošle na toto zařízení zkušební upozornění. Když server hlásí, že odběr přestal platit
     * (nebo ho služba upozornění odmítla), založí se znovu a zkusí se to ještě jednou.
     */
    async sendTest(retry = true) {
        this.error = '';
        this.detail = '';
        this.testResult = '';
        this.testing = true;

        try {
            const registration = await this.registration();
            const subscription = (await registration.pushManager.getSubscription()) ?? (await this.subscribe());
            const result = await this.request('POST', { endpoint: subscription.endpoint }, config.testUrl);

            if (result.ok) {
                this.testResult = 'sent';

                return;
            }

            if (retry && (result.expired || [400, 401, 403].includes(result.status))) {
                await this.subscribe(true);
                await this.sendTest(false);

                return;
            }

            this.testResult = 'failed';
            this.detail = [result.status ? `kód ${result.status}` : '', result.reason || ''].filter(Boolean).join(' – ');
        } catch (error) {
            this.testResult = 'failed';
            this.error = error.expiredPage ? EXPIRED_PAGE : '';
            this.detail = error.expiredPage ? '' : describe(error);
        } finally {
            this.testing = false;
        }
    },

    async disable() {
        this.error = '';
        this.detail = '';
        this.testResult = '';
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
        // Starší prohlížeč, který kódování neuvádí, umí to běžné (a Apple jiné nepřijímá).
        const supported = PushManager.supportedContentEncodings || [];
        const encoding = supported.includes('aes128gcm') || !supported.length ? 'aes128gcm' : supported[0];

        return this.request('POST', { ...subscription.toJSON(), contentEncoding: encoding });
    },

    async request(method, body, url = config.subscribeUrl) {
        const response = await fetch(url, {
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
            const error = new Error(`Server odpověděl kódem ${response.status}.`);

            // Vypršelo přihlášení nebo ochranný klíč stránky – pomůže ji otevřít znovu.
            error.expiredPage = [401, 419].includes(response.status);

            throw error;
        }

        return response.json().catch(() => ({}));
    },
});
