<?php
/**
 * sw.php - the service worker that makes the app installable (PWA, see includes/pwa.php).
 *
 * It is a PHP file at the app root, not a .js file under assets/, because a service worker only
 * controls pages at or below its own URL: this way it covers the whole app both on Vercel
 * (BASE_URL "/") and locally ("/attendance-system/") without extra server headers.
 * No login, session or database here: it is plain JavaScript.
 *
 * What it does, deliberately little:
 *   - pages always come from the network (attendance must be live, and pages carry CSRF tokens),
 *     with a cached "You're offline" page when the network is down;
 *   - files under assets/ are served from cache and refreshed in the background.
 * CACHE changes whenever one of the cached files changes, which installs a new worker and clears
 * the old cache.
 */
$files = ['assets/offline.html', 'assets/css/style.css', 'assets/js/app.js', 'assets/js/theme.js', 'assets/js/pwa.js',
          'assets/img/app-icon-192.png', 'assets/img/school-emblem-96.png', 'sw.php'];
$stamp = '';
foreach ($files as $f) $stamp .= $f . @filemtime(__DIR__ . '/' . $f);
$version = substr(md5($stamp), 0, 10);

header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-cache');   // the browser re-checks the worker on every visit
?>
'use strict';
const CACHE = 'attendance-<?php echo $version; ?>';
const OFFLINE_URL = new URL('assets/offline.html', self.location).href;
const ASSETS_PATH = new URL('assets/', self.location).pathname;
const PRECACHE = [OFFLINE_URL, new URL('assets/img/app-icon-192.png', self.location).href];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE)
            .then((cache) => cache.addAll(PRECACHE.map((url) => new Request(url, { cache: 'reload' }))))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((k) => k.startsWith('attendance-') && k !== CACHE).map((k) => caches.delete(k))))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.method !== 'GET') return;
    const url = new URL(req.url);
    if (url.origin !== self.location.origin) return;   // fonts/CDNs: leave to the browser cache

    if (req.mode === 'navigate') {
        // Never cache pages: show the offline page only when the network itself fails.
        event.respondWith(fetch(req).catch(() => caches.match(OFFLINE_URL)));
        return;
    }

    if (url.pathname.startsWith(ASSETS_PATH)) {
        // Stale-while-revalidate: instant from cache, refreshed for next time.
        event.respondWith(caches.open(CACHE).then((cache) => cache.match(req).then((cached) => {
            const network = fetch(req).then((res) => {
                if (res.ok && res.type === 'basic') cache.put(req, res.clone());
                return res;
            });
            if (cached) {
                event.waitUntil(network.catch(() => {}));
                return cached;
            }
            return network;
        })));
    }
});
