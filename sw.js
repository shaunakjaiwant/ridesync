const CACHE_NAME = 'ridesync-cache-v1';
const OFFLINE_URL = '/ridesync/pages/offline.html'; // We should create this if it doesn't exist

const STATIC_ASSETS = [
    '/ridesync/css/theme.css',
    '/ridesync/css/style.css',
    '/ridesync/js/script.js',
    '/ridesync/logo-mark.png',
    '/ridesync/logo.png',
    OFFLINE_URL
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            return cache.addAll(STATIC_ASSETS).catch(err => console.log('SW Cache Add Error:', err));
        })
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames.map((cache) => {
                    if (cache !== CACHE_NAME) {
                        return caches.delete(cache);
                    }
                })
            );
        })
    );
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);

    // Network-first for HTML pages and APIs
    if (request.headers.get('accept').includes('text/html') || url.pathname.startsWith('/ridesync/api/')) {
        event.respondWith(
            fetch(request).catch(() => {
                return caches.match(request).then((response) => {
                    if (response) return response;
                    // If offline and requesting HTML, return offline page
                    if (request.headers.get('accept').includes('text/html')) {
                        return caches.match(OFFLINE_URL);
                    }
                });
            })
        );
        return;
    }

    // Cache-first for static assets
    event.respondWith(
        caches.match(request).then((response) => {
            return response || fetch(request).then((fetchResponse) => {
                return caches.open(CACHE_NAME).then((cache) => {
                    cache.put(request, fetchResponse.clone());
                    return fetchResponse;
                });
            });
        })
    );
});
