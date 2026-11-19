const CACHE_NAME = "creative-ai-studio-v6";

const APP_SHELL = [
    "./",
    "./index.php",
    "./offline.html",
    "./manifest.json",
    "./assets/css/style.css",
    "./assets/css/bootstrap.min.css",
    "./assets/js/bootstrap.bundle.min.js",
    "./assets/js/app.js",
    "./assets/js/db.js",
    "./icons/icon-192.png",
    "./icons/icon-512.png"
];

self.addEventListener("install", event => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(cache => cache.addAll(APP_SHELL))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener("activate", event => {
    event.waitUntil(
        caches.keys()
            .then(keys => Promise.all(
                keys
                    .filter(key => key !== CACHE_NAME)
                    .map(key => caches.delete(key))
            ))
            .then(() => self.clients.claim())
    );
});

self.addEventListener("fetch", event => {
    const request = event.request;

    if (request.method !== "GET") {
        return;
    }

    event.respondWith(fromCacheFirst(request));
});

async function fromCacheFirst(request) {
    const cached = await caches.match(request);

    if (cached) {
        return cached;
    }

    try {
        const response = await fetch(request);

        if (response && response.ok) {
            const copy = response.clone();
            const cache = await caches.open(CACHE_NAME);
            await cache.put(request, copy);
        }

        return response;
    } catch (error) {
        if (request.mode === "navigate") {
            const offlinePage = await caches.match("./offline.html");

            if (offlinePage) {
                return offlinePage;
            }
        }

        return new Response(
            "Este recurso no está disponible sin conexión.",
            {
                status: 503,
                headers: {
                    "Content-Type": "text/plain; charset=utf-8"
                }
            }
        );
    }
}

self.addEventListener("push", event => {
    let data = {};

    if (event.data) {
        try {
            data = event.data.json();
        } catch (error) {
            data = { title: "Creova Flow", body: event.data.text() };
        }
    }

    event.waitUntil(
        self.registration.showNotification(
            data.title || "Creova Flow",
            {
                body: data.body || "Tienes una actualización.",
                icon: "./icons/icon-192.png",
                badge: "./icons/icon-192.png",
                data: {
                    url: data.url || "./"
                }
            }
        )
    );
});

self.addEventListener("notificationclick", event => {
    event.notification.close();

    const url = (event.notification.data && event.notification.data.url)
        ? event.notification.data.url
        : "./";

    event.waitUntil(clients.openWindow(url));
});
