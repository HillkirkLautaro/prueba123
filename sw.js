// Service Worker básico para PWA (Comentarios - TP Integrador N.º 1)
// Permite instalar la app como PWA desde Android (Chrome) y cachear recursos estáticos.
const CACHE_NAME = 'comentarios-v1';
const ASSETS = [
    '/index.php',
    '/styles.css',
    '/timezone.js?v=2',
    '/manifest.json',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => cache.addAll(ASSETS))
    );
});

self.addEventListener('fetch', (event) => {
    event.respondWith(
        caches.match(event.request).then((response) => response || fetch(event.request))
    );
});
