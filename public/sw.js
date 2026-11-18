/*
 * Service worker: příjem push upozornění, klik na upozornění a jednoduchá offline stránka.
 * Data aplikace se necachují – termíny musí být vždy čerstvé ze serveru.
 *
 * Aplikace může běžet i v podsložce webu (např. /luxio), proto se všechny cesty
 * počítají od adresy, na které tento soubor leží.
 */
const VERSION = 'luxio-v3';
const META = 'luxio-meta';
const ROOT = new URL('./', self.location.href);
const inApp = (path) => new URL(String(path).replace(/^\/+/, ''), ROOT).href;
const OFFLINE_URL = inApp('offline');
const BASE_KEY = inApp('__base');

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches
            .open(VERSION)
            .then((cache) => cache.add(new Request(OFFLINE_URL, { cache: 'reload' })))
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        (async () => {
            // Mazat jen vlastní staré verze – na stejné doméně mohou běžet i jiné aplikace.
            const keys = await caches.keys();
            const old = keys.filter((key) => key.startsWith('luxio-') && key !== VERSION && key !== META);

            await Promise.all(old.map((key) => caches.delete(key)));
            await self.clients.claim();
        })(),
    );
});

// Otevření stránky: vždy ze sítě, při výpadku připojení offline stránka.
// Odeslání formuláře (POST) se nechává prohlížeči beze změny – offline stránka pro něj nedává smysl
// a požadavek znovu vyslaný odsud by v Safari nesl tento soubor jako „předchozí stránku“.
self.addEventListener('fetch', (event) => {
    if (event.request.mode !== 'navigate' || event.request.method !== 'GET') {
        return;
    }

    event.respondWith(fetch(event.request).catch(async () => (await caches.match(OFFLINE_URL)) || Response.error()));
});

// Stránka zákaznice sdělí svou domovskou adresu (/k/<token>). Server token nezná
// (má jen jeho hash), proto si ji pamatuje zařízení – kvůli otevření rezervace z upozornění.
self.addEventListener('message', (event) => {
    const data = event.data || {};

    if (data.type === 'base' && typeof data.base === 'string' && data.base.startsWith('/k/')) {
        event.waitUntil(caches.open(META).then((cache) => cache.put(BASE_KEY, new Response(data.base))));
    }
});

self.addEventListener('push', (event) => {
    let payload = {};

    try {
        payload = event.data ? event.data.json() : {};
    } catch (error) {
        payload = { body: event.data ? event.data.text() : '' };
    }

    const { title = 'Rezervace', ...options } = payload;

    for (const key of ['icon', 'badge']) {
        if (typeof options[key] === 'string') {
            options[key] = inApp(options[key]);
        }
    }

    event.waitUntil(self.registration.showNotification(title, options));
});

// Klik na upozornění otevře příslušnou rezervaci v aplikaci.
self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    event.waitUntil(
        (async () => {
            const data = event.notification.data || {};
            let path = typeof data.url === 'string' ? data.url : null;

            if (!path) {
                const stored = await (await caches.open(META)).match(BASE_KEY);
                const base = stored ? await stored.text() : '';

                path = base ? base + (typeof data.path === 'string' ? data.path : '') : '';
            }

            // Cíl je vždy uvnitř aplikace – cokoliv jiného skončí na její úvodní stránce.
            let target = inApp(path);

            if (!target.startsWith(ROOT.href)) {
                target = ROOT.href;
            }

            const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });

            for (const client of windows) {
                if ('focus' in client) {
                    await client.focus();

                    if ('navigate' in client) {
                        try {
                            await client.navigate(target);
                        } catch (error) {
                            await self.clients.openWindow(target);
                        }
                    }

                    return;
                }
            }

            await self.clients.openWindow(target);
        })(),
    );
});
