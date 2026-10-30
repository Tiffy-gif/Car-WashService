/**
 * Car Wash POS — Progressive Web App (PWA) Service Worker
 * Vanilla JS Service Worker for Tablet POS
 * 
 * Rules:
 * - Network-first for index.html (cache fallback)
 * - Stale-while-revalidate for static assets (css, js, fonts, icons)
 * - NEVER intercept or cache anything under /api/
 * - NEVER intercept non-GET requests
 * - Never cache live streams or SSE
 */

const POS_VERSION = 'v20261030_101';
const CACHE_NAME = 'cw-pos-' + POS_VERSION;

const APP_SHELL = [
    './',
    './index.html',
    './manifest.webmanifest',
    './css/app.css',
    './css/font-awesome.min.css',
    './js/config.js',
    './js/storage.js',
    './js/api.js',
    './js/i18n.js',
    './js/thermal-printer.js',
    './js/app.js',
    './icons/icon-192.png',
    './icons/icon-512.png',
    './icons/icon-maskable-192.png',
    './icons/icon-maskable-512.png',
    './webfonts/fa-brands-400.ttf',
    './webfonts/fa-brands-400.woff2',
    './webfonts/fa-regular-400.ttf',
    './webfonts/fa-regular-400.woff2',
    './webfonts/fa-solid-900.ttf',
    './webfonts/fa-solid-900.woff2',
    './webfonts/inter-v20-400.woff2',
    './webfonts/inter-v20-500.woff2',
    './webfonts/inter-v20-600.woff2',
    './webfonts/inter-v20-700.woff2',
    './webfonts/kantumruy-pro-v12-400.woff2',
    './webfonts/kantumruy-pro-v12-500.woff2',
    './webfonts/kantumruy-pro-v12-600.woff2',
    './webfonts/kantumruy-pro-v12-700.woff2'
];

// Install: Pre-cache App Shell
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            return cache.addAll(APP_SHELL);
        })
    );
});

// Activate: Delete Old Caches & Claim Clients
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => {
            return Promise.all(
                keys.map((key) => {
                    if (key.startsWith('cw-pos-') && key !== CACHE_NAME) {
                        return caches.delete(key);
                    }
                })
            );
        }).then(() => self.clients.claim())
    );
});

// Message: Allow page to tell waiting worker to activate immediately
self.addEventListener('message', (event) => {
    if (event.data && event.data.action === 'skipWaiting') {
        self.skipWaiting();
    }
});

// Fetch: Caching Strategies
self.addEventListener('fetch', (event) => {
    const req = event.request;
    const url = new URL(req.url);

    // 1. NEVER intercept non-GET requests (POST, PUT, DELETE, OPTIONS)
    if (req.method !== 'GET') {
        return;
    }

    // 2. NEVER intercept or cache anything under /api/ or live stream/events
    if (url.pathname.includes('/api/') || url.pathname.includes('/stream') || url.pathname.includes('/events')) {
        return;
    }

    // 3. Navigation / HTML requests: Network-First with Cache Fallback
    const isNavigation = req.mode === 'navigate' ||
                         req.destination === 'document' ||
                         url.pathname.endsWith('/pos/') ||
                         url.pathname.endsWith('/pos/index.html');

    if (isNavigation) {
        event.respondWith(
            fetch(req)
                .then((networkRes) => {
                    if (networkRes && networkRes.status === 200) {
                        const copy = networkRes.clone();
                        caches.open(CACHE_NAME).then((cache) => cache.put(req, copy));
                    }
                    return networkRes;
                })
                .catch(() => {
                    return caches.match(req).then((cached) => {
                        return cached || caches.match('./index.html') || caches.match('./');
                    });
                })
        );
        return;
    }

    // 4. Static Assets (CSS, JS, Fonts, Images, Icons): Stale-While-Revalidate
    event.respondWith(
        caches.match(req).then((cachedRes) => {
            const fetchPromise = fetch(req).then((networkRes) => {
                if (networkRes && networkRes.status === 200) {
                    const copy = networkRes.clone();
                    caches.open(CACHE_NAME).then((cache) => cache.put(req, copy));
                }
                return networkRes;
            }).catch(() => {
                // Return null if network fails; cachedRes handles it
                return null;
            });

            return cachedRes || fetchPromise;
        })
    );
});
