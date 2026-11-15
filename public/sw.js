/*
 * Service worker: příjem push upozornění, klik na upozornění a jednoduchá offline stránka.
 * Data aplikace se necachují – termíny musí být vždy čerstvé ze serveru.
 */
const VERSION = 'luxio-v1';
const META = 'luxio-meta';
const OFFLINE_URL = '/offline';

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
            const keys = await caches.keys();
            await Promise.all(keys.filter((key) => key !== VERSION && key !== META).map((key) => caches.delete(key)));
            await self.clients.claim();
        })(),
    );
});

// Otevření stránky: vždy ze sítě, při výpadku připojení offline stránka.
self.addEventListener('fetch', (event) => {
    if (event.request.mode !== 'navigate') {
        return;
    }

    event.respondWith(fetch(event.request).catch(async () => (await caches.match(OFFLINE_URL)) || Response.error()));
});

// Stránka zákaznice sdělí svou domovskou adresu (/k/<token>). Server token nezná
// (má jen jeho hash), proto si ji pamatuje zařízení – kvůli otevření rezervace z upozornění.
self.addEventListener('message', (event) => {
    const data = event.data || {};

    if (data.type === 'base' && typeof data.base === 'string' && data.base.startsWith('/k/')) {
        event.waitUntil(caches.open(META).then((cache) => cache.put('/__base', new Response(data.base))));
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

    event.waitUntil(self.registration.showNotification(title, options));
});

// Klik na upozornění otevře příslušnou rezervaci v aplikaci.
self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    event.waitUntil(
        (async () => {
            const data = event.notification.data || {};
            let url = typeof data.url === 'string' ? data.url : null;

            if (!url) {
                const stored = await (await caches.open(META)).match('/__base');
                const base = stored ? await stored.text() : '';

                url = base ? base + (typeof data.path === 'string' ? data.path : '') : '/';
            }

            const target = new URL(url, self.location.origin).href;
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
