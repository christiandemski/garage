/* Garage – Service Worker: statische Dateien cachen, Seiten immer frisch vom Server */
const CACHE = 'garage-v1';
const ASSETS = ['assets/app.css', 'assets/app.js', 'assets/icon.svg', 'assets/icon-192.png'];
self.addEventListener('install', e => { e.waitUntil(caches.open(CACHE).then(c => c.addAll(ASSETS)).then(() => self.skipWaiting())); });
self.addEventListener('activate', e => { e.waitUntil(caches.keys().then(k => Promise.all(k.filter(x => x !== CACHE).map(x => caches.delete(x)))).then(() => self.clients.claim())); });
self.addEventListener('fetch', e => {
  const url = new URL(e.request.url);
  if (e.request.method !== 'GET' || url.origin !== location.origin) return;
  if (url.pathname.includes('/assets/')) {
    e.respondWith(caches.match(e.request, { ignoreSearch: true }).then(r => r || fetch(e.request).then(res => { const copy = res.clone(); caches.open(CACHE).then(c => c.put(e.request, copy)); return res; })));
    return;
  }
  if (e.request.mode === 'navigate') {
    e.respondWith(fetch(e.request).catch(() => new Response('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Offline</title><body style="font-family:system-ui;display:grid;place-items:center;min-height:90vh;text-align:center;color:#555"><div><h1 style="color:#111">Keine Verbindung</h1><p>Garage braucht eine Internetverbindung.<br>Bitte versuche es gleich noch einmal.</p></div></body>', { headers: { 'Content-Type': 'text/html; charset=utf-8' } })));
  }
});
