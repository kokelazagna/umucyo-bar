/* ============================================================================
   Umucyo Bar (BSSBMS) service worker - offline mode.
   Served at the project root (whatever the folder is named - /umc/sw.js,
   /mybar/sw.js... - via the .htaccess rewrite) so its scope covers the WHOLE app.

   Strategy:
   - Static app shell (css/js/img/fonts/manifest): cache-first, updated in the
     background ("stale-while-revalidate").
   - Page navigations (GET): network-first; when the network is down the last
     saved copy of the page is served from the cache, and if the page was never
     cached a small "you are offline" notice is served instead.
   - POST requests (forms, sync/push): NEVER served from the cache. Going
     offline is detected by the page (offline.js) which queues the action.
============================================================================ */

var VERSION = 'v2.2-offline-1';
var SHELL_CACHE = 'bssbms-shell-' + VERSION;
var PAGES_CACHE = 'bssbms-pages-' + VERSION;

// The app shell: files that never change per request. Base path is taken from
// the registration scope, so this works whatever the project folder is called.
var SHELL_FILES = [
  'public/assets/css/design.css',
  'public/assets/css/reports.css',
  'public/assets/js/app.js',
  'public/assets/js/pos.js',
  'public/assets/js/offline.js',
  'public/assets/js/other_option.js',
  'public/assets/js/draft_round.js',
  'public/assets/js/products.js',
  'public/assets/js/purchases.js',
  'public/assets/js/adjust.js',
  'public/assets/js/login.js',
  'public/assets/img/icon-192.png',
  'public/assets/img/icon-512.png',
  'manifest.webmanifest'
];

var GOOGLE_FONTS = [
  'https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap'
];

self.addEventListener('install', function (event) {
  var base = new URL(self.registration.scope).pathname;   // e.g. /umc/
  var shell = SHELL_FILES.map(function (f) { return base + f; }).concat(GOOGLE_FONTS);
  event.waitUntil(
    caches.open(SHELL_CACHE).then(function (cache) {
      // credentials 'same-origin' so the CSS/JS of protected pages can be cached
      return Promise.all(shell.map(function (url) {
        return cache.add(new Request(url, { credentials: 'same-origin', cache: 'reload' }))
          .catch(function () { /* a missing optional file must not break the install */ });
      }));
    }).then(function () { return self.skipWaiting(); })
  );
});

self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(keys.map(function (k) {
        if (k !== SHELL_CACHE && k !== PAGES_CACHE) { return caches.delete(k); }
      }));
    }).then(function () { return self.clients.claim(); })
  );
});

self.addEventListener('message', function (event) {
  if (event.data === 'SKIP_WAITING') { self.skipWaiting(); }
});

self.addEventListener('fetch', function (event) {
  var request = event.request;
  if (request.method !== 'GET') { return; }               // forms + sync/push: network only

  var url = new URL(request.url);

  // ---- page navigations: network first, then the cached copy ----
  if (request.mode === 'navigate' && url.origin === location.origin) {
    event.respondWith(
      fetch(request).then(function (response) {
        var copy = response.clone();
        caches.open(PAGES_CACHE).then(function (cache) { cache.put(request, copy); });
        return response;
      }).catch(function () {
        return caches.match(request, { ignoreSearch: false }).then(function (cached) {
          if (cached) { return cached; }
          // the page was never cached: a clear "offline" notice instead of an error
          var base = new URL(self.registration.scope).pathname;
          return new Response(
            '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">' +
            '<meta name="viewport" content="width=device-width, initial-scale=1">' +
            '<title>Offline - Umucyo Bar</title></head>' +
            '<body style="font-family:Roboto,Arial,sans-serif;background:#f4f2fb;color:#2d2350;' +
            'display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0">' +
            '<div style="text-align:center;padding:32px">' +
            '<h1 style="margin:0 0 8px">You are offline</h1>' +
            '<p style="margin:0 0 20px;color:#6b5fa8">This page was never opened while online, so there is no saved copy.</p>' +
            '<p><a href="' + base + 'pos" style="color:#7c3aed;font-weight:bold">Go to the Point of Sale</a> ' +
            '(it keeps working offline and syncs when you are back).</p></div></body></html>',
            { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } }
          );
        });
      })
    );
    return;
  }

  // ---- same-origin static files: cache first, refresh in the background ----
  if (url.origin === location.origin) {
    event.respondWith(
      caches.open(SHELL_CACHE).then(function (cache) {
        return cache.match(request).then(function (cached) {
          var fetchAndUpdate = fetch(request).then(function (response) {
            if (response && response.ok) { cache.put(request, response.clone()); }
            return response;
          }).catch(function () { return null; });
          if (cached) {
            event.waitUntil(fetchAndUpdate);
            return cached;
          }
          return fetch(request).then(function (response) {
            if (response && response.ok) { cache.put(request, response.clone()); }
            return response;
          });
        });
      })
    );
    return;
  }

  // ---- Google fonts (cross-origin): cache first ----
  if (/fonts\.(googleapis|gstatic)\.com$/.test(url.hostname)) {
    event.respondWith(
      caches.open(SHELL_CACHE).then(function (cache) {
        return cache.match(request).then(function (cached) {
          if (cached) { return cached; }
          return fetch(request).then(function (response) {
            cache.put(request, response.clone());
            return response;
          });
        });
      })
    );
  }
});
