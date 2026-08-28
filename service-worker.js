const CACHE_VERSION = 'iqpigeon-v4';
const SHELL_ASSETS = [
  '/assets/css/theme.css',
  '/assets/css/fonts.css',
  '/assets/css/app.css',
  '/assets/css/design-polish.css',
  '/assets/css/design-v2.css',
  '/assets/css/auth-v2.css',
  '/assets/css/landing.css',
  '/assets/js/app.js',
  '/assets/js/theme.js',
  '/assets/img/Fav-Icon-on-white-bg.png',
  '/manifest.json',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_VERSION).then((cache) => cache.addAll(SHELL_ASSETS)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE_VERSION).map((k) => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const { request } = event;
  if (request.method !== 'GET') return;

  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;

  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request).catch(() => caches.match('/client/dashboard') || caches.match('/login'))
    );
    return;
  }

  if (url.pathname.startsWith('/assets/')) {
    event.respondWith(
      caches.match(request).then((cached) => cached || fetch(request).then((res) => {
        const copy = res.clone();
        caches.open(CACHE_VERSION).then((cache) => cache.put(request, copy));
        return res;
      }))
    );
  }
});
