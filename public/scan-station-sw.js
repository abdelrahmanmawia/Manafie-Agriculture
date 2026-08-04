// Minimal offline shell cache for the scan station page. Vite's built asset filenames are
// content-hashed, so we can't list them upfront — instead this caches whatever the page
// actually requests as it's fetched (network-first, falling back to cache when offline).
const CACHE_NAME = 'scan-station-shell-v1';

self.addEventListener('install', (event) => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key)))
        ).then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    if (event.request.method !== 'GET') {
        return;
    }

    // Never cache/serve API calls offline-from-cache — sync must always hit the real network
    // (or fail loudly), not silently replay a stale cached response.
    const url = new URL(event.request.url);
    if (url.pathname.startsWith('/api/')) {
        return;
    }

    event.respondWith(
        fetch(event.request)
            .then((response) => {
                const copy = response.clone();
                caches.open(CACHE_NAME).then((cache) => cache.put(event.request, copy));
                return response;
            })
            .catch(() => caches.match(event.request))
    );
});
