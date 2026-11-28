const CACHE_NAME = "creova-flow-v16";

const PRECACHE = [
    ["./inicio.php", "./inicio.php?v=12"],
    ["./shell-admin.html", "./shell-admin.html?v=15"],
    ["./shell-portal.html", "./shell-portal.html?v=13"],
    ["./shell-acceso.html", "./shell-acceso.html?v=12"],
    ["./offline.html", "./offline.html?v=12"],
    ["./manifest.json", "./manifest.json?v=12"],
    ["./assets/css/style.css", "./assets/css/style.css?v=12"],
    ["./assets/css/bootstrap.min.css", "./assets/css/bootstrap.min.css?v=12"],
    ["./assets/css/landing.css", "./assets/css/landing.css?v=12"],
    ["./assets/js/bootstrap.bundle.min.js", "./assets/js/bootstrap.bundle.min.js?v=12"],
    ["./assets/js/flow.js?v=14", "./assets/js/flow.js?v=14"],
    ["./assets/js/admin.js?v=16", "./assets/js/admin.js?v=16"],
    ["./assets/js/portal.js?v=13", "./assets/js/portal.js?v=13"],
    ["./assets/js/acceso.js?v=2", "./assets/js/acceso.js?v=2"],
    ["./assets/js/db.js?v=14", "./assets/js/db.js?v=14"],
    ["./assets/images/hero-creova.jpg", "./assets/images/hero-creova.jpg?v=12"],
    ["./assets/images/portfolio-video.jpg", "./assets/images/portfolio-video.jpg?v=12"],
    ["./assets/images/portfolio-image.jpg", "./assets/images/portfolio-image.jpg?v=12"],
    ["./assets/images/portfolio-3d.jpg", "./assets/images/portfolio-3d.jpg?v=12"],
    ["./icons/icon-192.png", "./icons/icon-192.png?v=12"],
    ["./icons/icon-512.png", "./icons/icon-512.png?v=12"]
];

function pageFor(url) {
    const path = url.pathname.replace(/\/+$/, "") || "/";

    if (path.endsWith("/index.php")) {
        return "./shell-admin.html";
    }

    if (path.endsWith("/portal.php")) {
        return "./shell-portal.html";
    }

    if (path.endsWith("/acceso.php")) {
        return "./shell-acceso.html";
    }

    if (path === "/" || path.endsWith("/inicio.php")) {
        return "./inicio.php";
    }

    return "";
}

self.addEventListener("install", event => {
    event.waitUntil((async () => {
        const cache = await caches.open(CACHE_NAME);

        await Promise.all(PRECACHE.map(async pair => {
            const stored = pair[0];
            const fresh = pair[1];
            let response = await fetch(fresh, { cache: "reload" });

            if (!response.ok && fresh !== stored) {
                response = await fetch(stored, { cache: "reload" });
            }

            if (!response.ok) {
                throw new Error(stored);
            }

            await cache.put(stored, response);
        }));

        await self.skipWaiting();
    })());
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

    if (url.pathname.indexOf("/api/") !== -1) {
        const action = url.searchParams.get("action");

        if (action === "session" || action === "bootstrap") {
            event.respondWith(dataFirst(request));
        }

        return;
    }

    if (url.pathname.indexOf("/push/") !== -1) {
        return;
    }

    if (request.mode === "navigate") {
        const page = pageFor(url);

        if (page) {
            event.respondWith(serveAppPage(event, page));
            return;
        }

        event.respondWith(networkFirst(request));
        return;
    }

    event.respondWith(fromCacheFirst(request));
});

async function dataFirst(request) {
    const cache = await caches.open(CACHE_NAME);

    try {
        const response = await fetch(request);

        if (response && response.ok) {
            const data = await response.clone().json().catch(() => null);

            if (data && data.user) {
                await cache.put(request, response.clone());
            } else if (data && data.user === null) {
                const keys = await cache.keys();
                await Promise.all(keys
                    .filter(key => key.url.indexOf("/api/") !== -1)
                    .map(key => cache.delete(key)));
            }
        }

        return response;
    } catch (error) {
        const cached = await cache.match(request);

        if (cached) {
            return cached;
        }

        throw error;
    }
}

self.addEventListener("message", event => {
    if (event.data !== "clear-data") {
        return;
    }

    event.waitUntil((async () => {
        const cache = await caches.open(CACHE_NAME);
        const keys = await cache.keys();
        await Promise.all(keys
            .filter(key => key.url.indexOf("/api/") !== -1)
            .map(key => cache.delete(key)));
    })());
});

async function serveAppPage(event, page) {
    const cache = await caches.open(CACHE_NAME);
    const cached = await cache.match(page);
    const update = refreshPage(cache, page);

    if (cached) {
        event.waitUntil(update);
        return cached;
    }

    const fresh = await update;

    if (fresh) {
        return fresh;
    }

    return offlineResponse();
}

async function refreshPage(cache, page) {
    try {
        const response = await fetch(page, { cache: "reload" });

        if (response && response.ok && response.type === "basic") {
            await cache.put(page, response.clone());
            return response;
        }
    } catch (error) {
        return null;
    }

    return null;
}

async function networkFirst(request) {
    try {
        const response = await fetch(request);

        if (response && response.ok && response.type === "basic") {
            const cache = await caches.open(CACHE_NAME);
            await cache.put(request, response.clone());
        }

        return response;
    } catch (error) {
        const cached = await caches.match(request, { ignoreSearch: true });

        if (cached) {
            return cached;
        }

        if (request.mode === "navigate") {
            return offlineResponse();
        }

        throw error;
    }
}

async function fromCacheFirst(request) {
    const cached = await caches.match(request) || await caches.match(request, { ignoreSearch: true });

    if (cached) {
        return cached;
    }

    try {
        const response = await fetch(request);

        if (response && response.ok && response.type === "basic") {
            const copy = response.clone();
            const cache = await caches.open(CACHE_NAME);
            await cache.put(request, copy);
        }

        return response;
    } catch (error) {
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

async function offlineResponse() {
    const offlinePage = await caches.match("./offline.html");

    if (offlinePage) {
        return offlinePage;
    }

    return new Response("Sin conexión.", {
        status: 503,
        headers: {
            "Content-Type": "text/plain; charset=utf-8"
        }
    });
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
