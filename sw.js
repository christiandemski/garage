/* Garage – Service Worker: statische Dateien cachen, Seiten immer frisch vom Server */
const CACHE = 'garage-v2';
const ASSETS = ['assets/app.css', 'assets/app.js', 'assets/icon.svg', 'assets/icon-192.png'];
self.addEventListener('install', e => { e.waitUntil(caches.open(CACHE).then(c => c.addAll(ASSETS)).then(() => self.skipWaiting())); });
self.addEventListener('activate', e => { e.waitUntil(caches.keys().then(k => Promise.all(k.filter(x => x !== CACHE).map(x => caches.delete(x)))).then(() => self.clients.claim())); });
self.addEventListener('fetch', e => {
  const url = new URL(e.request.url);
  if (e.request.method !== 'GET' || url.origin !== location.origin) return;
  if (url.pathname.includes('/assets/')) {
    // Dateien sind über ?v=… versioniert: exakter Treffer aus dem Cache, sonst neu laden und ältere Versionen derselben Datei entfernen
    e.respondWith(caches.match(e.request).then(r => r || fetch(e.request).then(res => {
      if (res.ok) {
        const copy = res.clone();
        caches.open(CACHE).then(async c => {
          for (const k of await c.keys()) if (new URL(k.url).pathname === url.pathname) await c.delete(k);
          await c.put(e.request, copy);
        });
      }
      return res;
    }).catch(() => caches.match(e.request, { ignoreSearch: true }))));
    return;
  }
  if (e.request.mode === 'navigate') {
    e.respondWith(fetch(e.request).catch(() => new Response('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Offline</title><body style="font-family:system-ui;display:grid;place-items:center;min-height:90vh;text-align:center;color:#555"><div><h1 style="color:#111">Keine Verbindung</h1><p>Garage braucht eine Internetverbindung.<br>Bitte versuche es gleich noch einmal.</p></div></body>', { headers: { 'Content-Type': 'text/html; charset=utf-8' } })));
  }
});
