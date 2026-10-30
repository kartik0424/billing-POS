/**
 * QuickPOS Progressive Web App Service Worker
 * Version: 1.0.0
 * 
 * Caching Strategies:
 * - API requests (/api/*): Network-only (never cached, mutating POS transactions).
 * - Static Assets (CSS, JS, Fonts, Icons, CDNs): Cache-first with network fallback.
 * - Navigation / HTML Pages: Network-first with cache fallback, failing to offline.html.
 */

const CACHE_NAME = 'quickpos-cache-v1.0.3';

// Essential App Shell resources to precache
const PRECACHE_ASSETS = [
    './',
    'index.php',
    'login.php',
    'billing.php',
    'history.php',
    'products.php',
    'audit.php',
    'settings.php',
    'offline.html',
    'manifest.json',

    // Local Stylesheets
    'assets/css/style.css',
    'assets/css/print.css',

    // Local Scripts
    'assets/js/app.js',
    'assets/js/billing.js',
    'assets/js/receipt.js',
    'assets/js/printer.js',
    'assets/js/history.js',
    'assets/js/products.js',
    'assets/js/audit.js',
    'assets/js/settings.js',
    'assets/js/pwa.js',

    // PWA Icons
    'assets/icons/icon-192.png',
    'assets/icons/icon-512.png',
    'assets/icons/icon.svg',

    // CDN Dependencies
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css',
    'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css',
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js',
    'https://cdn.jsdelivr.net/npm/chart.js',
    'https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js'
];

// ============================================================================
// 1. Install Event: Precache Application Shell
// ============================================================================
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            // Attempt caching assets; ignore individual network hiccups during install
            return Promise.allSettled(
                PRECACHE_ASSETS.map((url) => {
                    return fetch(url, { mode: url.startsWith('http') ? 'cors' : 'no-cors' })
                        .then((response) => {
                            if (response.ok || response.type === 'opaque') {
                                return cache.put(url, response);
                            }
                        })
                        .catch((err) => {
                            console.warn('[SW Precache Warning] Could not precache:', url, err);
                        });
                })
            );
        })
    );
});

// ============================================================================
// 2. Activate Event: Clean up outdated cache versions
// ============================================================================
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
        }).then(() => self.clients.claim())
    );
});

// ============================================================================
// 3. Message Event: Skip waiting on user confirmation
// ============================================================================
self.addEventListener('message', (event) => {
    if (event.data && event.data.action === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});

// ============================================================================
// 4. Fetch Event: Routing & Caching Strategies
// ============================================================================
self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);

    // Strategy A: Network-only for API endpoints and non-GET requests (Never cache API calls)
    if (request.method !== 'GET' || url.pathname.includes('/api/')) {
        event.respondWith(
            fetch(request).catch(() => {
                return new Response(
                    JSON.stringify({
                        success: false,
                        offline: true,
                        message: 'You are currently offline. Transaction queued locally.'
                    }),
                    {
                        status: 503,
                        headers: { 'Content-Type': 'application/json' }
                    }
                );
            })
        );
        return;
    }

    // Strategy B: Navigation Requests (HTML Pages) -> Network-first with cache fallback & offline.html
    if (request.mode === 'navigate' || request.headers.get('accept')?.includes('text/html')) {
        event.respondWith(
            fetch(request)
                .then((networkResponse) => {
                    // Update cache with latest page version
                    if (networkResponse && networkResponse.status === 200) {
                        const responseClone = networkResponse.clone();
                        caches.open(CACHE_NAME).then((cache) => cache.put(request, responseClone));
                    }
                    return networkResponse;
                })
                .catch(async () => {
                    // Check cache for this page
                    const cachedResponse = await caches.match(request);
                    if (cachedResponse) {
                        return cachedResponse;
                    }
                    // Fallback to offline page
                    const offlineFallback = await caches.match('offline.html');
                    return offlineFallback || new Response('Offline - No connection available.', { status: 503 });
                })
        );
        return;
    }

    // Strategy C: Static Assets (CSS, JS, Fonts, Images, CDNs) -> Cache-first with network fallback
    event.respondWith(
        caches.match(request).then((cachedResponse) => {
            if (cachedResponse) {
                // Return from cache, and fetch in background to revalidate (Stale-While-Revalidate)
                fetch(request)
                    .then((networkResponse) => {
                        if (networkResponse && networkResponse.status === 200) {
                            caches.open(CACHE_NAME).then((cache) => cache.put(request, networkResponse));
                        }
                    })
                    .catch(() => { /* Silent failure if offline */ });

                return cachedResponse;
            }

            // Not in cache, fetch from network
            return fetch(request).then((networkResponse) => {
                if (networkResponse && (networkResponse.status === 200 || networkResponse.type === 'opaque')) {
                    const responseClone = networkResponse.clone();
                    caches.open(CACHE_NAME).then((cache) => cache.put(request, responseClone));
                }
                return networkResponse;
            });
        })
    );
});
