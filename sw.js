/* CulTour offline shell — caches app chrome for repeat visits */
const CACHE = 'cultour-shell-v4';
const SHELL = [
  './index.php',
  './login.php',
  './explore.php',
  './assets/css/style.css',
  './manifest.webmanifest'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE).then((cache) => cache.addAll(SHELL).catch(() => undefined)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;

  // Always network-first for JS so admin actions are never stuck on a stale app.js
  const isJs = /\.js$/i.test(url.pathname);
  if (isJs) {
    event.respondWith(
      fetch(req)
        .then((res) => res)
        .catch(() => caches.match(req))
    );
    return;
  }

  // Network-first for PHP / API; cache-first for other static assets
  const isStatic = /\.(css|png|jpg|jpeg|webp|svg|woff2?|webmanifest)$/i.test(url.pathname);
  if (isStatic) {
    event.respondWith(
      caches.match(req).then((hit) =>
        hit ||
        fetch(req).then((res) => {
          const copy = res.clone();
          caches.open(CACHE).then((c) => c.put(req, copy));
          return res;
        }).catch(() => caches.match('./index.php'))
      )
    );
    return;
  }

  event.respondWith(
    fetch(req)
      .then((res) => {
        const length = Number(res.headers.get('content-length') || 0);
        if (res && res.ok && length > 1000 && (url.pathname.endsWith('.php') || url.pathname.endsWith('/'))) {
          const copy = res.clone();
          caches.open(CACHE).then((c) => c.put(req, copy));
        }
        return res;
      })
      .catch(() => caches.match(req).then((hit) => hit || caches.match('./index.php')))
  );
});
