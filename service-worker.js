const CACHE_NAME = "creova-flow-v7";

const APP_SHELL = [
    "./offline.html",
    "./manifest.json",
    "./assets/css/style.css",
    "./assets/css/bootstrap.min.css",
    "./assets/js/bootstrap.bundle.min.js",
    "./assets/js/flow.js",
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

    const url = new URL(request.url);

    if (url.origin !== self.location.origin) {
        return;
    }

    if (url.pathname.indexOf("/api/") !== -1 || url.pathname.endsWith(".php") || url.pathname.endsWith(".js")) {
        event.respondWith(networkFirst(request));
        return;
    }

    event.respondWith(fromCacheFirst(request));
});

async function networkFirst(request) {
    try {
        return await fetch(request);
    } catch (error) {
        const cached = await caches.match(request);

        if (cached) {
            return cached;
        }

        if (request.mode === "navigate") {
            const offlinePage = await caches.match("./offline.html");

            if (offlinePage) {
                return offlinePage;
            }
        }

        throw error;
    }
}

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
