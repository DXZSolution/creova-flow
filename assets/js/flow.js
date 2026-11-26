const FLOW_LABELS = {
    ofertaEstado: {
        borrador: "Borrador",
        publicada: "Publicada",
        pausada: "Pausada",
        no_disponible: "No disponible",
        archivada: "Archivada"
    },
    modalidad: {
        solicitar: "Solicitar servicio",
        suscribirse: "Suscribirse",
        iniciar: "Iniciar proyecto",
        informacion: "Solicitar información"
    },
    solicitud: {
        nueva: "Nueva",
        en_revision: "En revisión",
        informacion_adicional: "Información adicional requerida",
        cotizacion_enviada: "Cotización enviada",
        aprobada: "Aprobada",
        rechazada: "Rechazada",
        cancelada: "Cancelada"
    },
    proyecto: {
        pendiente: "Pendiente de inicio",
        en_proceso: "En proceso",
        en_espera: "En espera",
        completado: "Completado",
        pausado: "Pausado",
        cancelado: "Cancelado"
    },
    notificacion: {
        bienvenida: "Bienvenida",
        nueva_oferta: "Nueva oferta",
        solicitud_recibida: "Confirmación de solicitud",
        solicitud_admin: "Nueva solicitud",
        suscripcion_usuario: "Confirmación de suscripción",
        suscripcion_admin: "Nueva suscripción",
        nuevo_usuario: "Nuevo usuario",
        cambio_estado: "Cambio de estado",
        proyecto_iniciado: "Proyecto iniciado",
        proyecto_completado: "Proyecto completado",
        informacion_adicional: "Información adicional",
        comentario: "Comentario"
    }
};

const OFFLINE_KEY = "creovaOfflineCopy";

function readOfflineCopy() {
    try {
        return JSON.parse(localStorage.getItem(OFFLINE_KEY) || "null");
    } catch (error) {
        return null;
    }
}

function rememberOffline(action, data) {
    if (!data || data.offline || !data.user) {
        return;
    }

    const copy = readOfflineCopy() || {};

    if (action === "session") {
        copy.session = {
            success: true,
            user: data.user,
            token: data.token || ""
        };
    }

    if (action === "bootstrap") {
        copy.bootstrap = data;
        copy.session = {
            success: true,
            user: data.user,
            token: data.token || (copy.session && copy.session.token) || ""
        };
    }

    if (action === "login" || action === "register") {
        copy.session = {
            success: true,
            user: data.user,
            token: data.token || ""
        };
        delete copy.bootstrap;
    }

    copy.savedAt = new Date().toISOString();

    try {
        localStorage.setItem(OFFLINE_KEY, JSON.stringify(copy));
    } catch (error) {
        return;
    }
}

function offlineCopy(action) {
    const copy = readOfflineCopy();

    if (!copy) {
        return null;
    }

    const saved = action === "bootstrap" ? copy.bootstrap : copy.session;

    if (!saved || !saved.user) {
        return null;
    }

    return Object.assign({}, saved, { offline: true });
}

function offlineError() {
    const error = new Error("Sin conexión. Este cambio espera a que vuelva la red.");
    error.offline = true;
    return error;
}

function showOfflineNotice() {
    if (document.getElementById("offlineNotice")) {
        return;
    }

    const bar = document.createElement("div");
    bar.id = "offlineNotice";
    bar.setAttribute("role", "status");
    bar.textContent = "Sin conexión. Estás viendo la última copia guardada en este dispositivo.";
    bar.style.cssText = "position:fixed;left:0;right:0;bottom:0;z-index:2000;background:#34415c;color:#fff;text-align:center;padding:10px 16px;font:14px/1.4 Arial,sans-serif;";
    document.body.appendChild(bar);
}

const FlowApi = {
    token: "",

    async request(action, options = {}) {
        const method = options.method || "GET";
        const headers = { Accept: "application/json" };
        const opts = {
            method,
            headers,
            credentials: "same-origin"
        };

        if (options.body) {
            headers["Content-Type"] = "application/json";
            headers["X-Creova-Token"] = this.token;
            opts.body = JSON.stringify(options.body);
        }

        let response;

        try {
            response = await fetch("api/index.php?action=" + encodeURIComponent(action), opts);
        } catch (error) {
            const cached = method === "GET" ? offlineCopy(action) : null;

            if (cached) {
                if (cached.token) {
                    this.token = cached.token;
                }

                showOfflineNotice();
                return cached;
            }

            throw offlineError();
        }

        const raw = await response.text();
        let data;

        try {
            data = JSON.parse(raw);
        } catch (error) {
            throw new Error("La respuesta del servidor no es válida.");
        }

        if (data.token) {
            this.token = data.token;
        }

        if (action === "logout" && response.ok) {
            localStorage.removeItem(OFFLINE_KEY);
        }

        if (response.status === 401) {
            window.location.href = "acceso.php";
            throw new Error(data.message || "Inicia sesión para continuar.");
        }

        if (!response.ok || data.success === false) {
            throw new Error(data.message || "No se pudo completar la operación.");
        }

        rememberOffline(action, data);
        return data;
    }
};

function formatWhen(iso) {
    if (!iso) {
        return "—";
    }

    const date = new Date(iso);

    if (Number.isNaN(date.getTime())) {
        return iso;
    }

    return date.toLocaleString("es-MX", {
        dateStyle: "medium",
        timeStyle: "short"
    });
}

function labelOf(group, key) {
    return (FLOW_LABELS[group] && FLOW_LABELS[group][key]) || key || "—";
}

function isHttp(url) {
    return /^https?:\/\//i.test(url || "");
}

function parseFiles(text) {
    return String(text || "")
        .split(/\n/)
        .map(line => line.trim())
        .filter(Boolean)
        .map(line => {
            const parts = line.split("|");
            return {
                nombre: (parts[0] || "").trim(),
                url: parts.slice(1).join("|").trim()
            };
        })
        .filter(file => file.nombre);
}

function formatFiles(files) {
    return (files || [])
        .map(file => file.url ? file.nombre + " | " + file.url : file.nombre)
        .join("\n");
}

function pill(text) {
    const span = document.createElement("span");
    span.className = "status-pill";
    span.textContent = text;
    return span;
}

function kv(label, value, preserve) {
    const p = document.createElement("p");
    p.className = preserve ? "kv pre" : "kv";
    const strong = document.createElement("strong");
    strong.textContent = label + ": ";
    p.append(strong, document.createTextNode(value || "—"));
    return p;
}

function emptyState(text) {
    const box = document.createElement("div");
    box.className = "empty-state";
    box.textContent = text;
    return box;
}

function showAlert(message, ok) {
    const box = document.getElementById("appAlert");

    if (!box) {
        return;
    }

    box.textContent = message || "";
    box.classList.toggle("d-none", !message);
    box.classList.toggle("is-ok", Boolean(ok));
}

function setUnread(count) {
    const badge = document.getElementById("unreadBadge");

    if (!badge) {
        return;
    }

    badge.textContent = String(count);
    badge.classList.toggle("d-none", count < 1);
}

function renderNotificationList(container, items, onOpen) {
    container.innerHTML = "";

    if (!items.length) {
        container.appendChild(emptyState("No hay notificaciones por ahora."));
        return;
    }

    items.forEach(item => {
        const button = document.createElement("button");
        button.type = "button";
        button.className = "notice" + (item.leida ? "" : " is-unread");

        const title = document.createElement("strong");
        title.textContent = item.titulo;

        const meta = document.createElement("span");
        meta.className = "notice-meta";
        meta.textContent = labelOf("notificacion", item.tipo) + " · " + formatWhen(item.fecha);

        const message = document.createElement("p");
        message.textContent = item.mensaje;

        button.append(title, meta, message);
        button.addEventListener("click", () => onOpen(item));
        container.appendChild(button);
    });
}

function hideSplash() {
    const splash = document.getElementById("splash");

    if (!splash) {
        return;
    }

    window.setTimeout(() => {
        splash.classList.add("splash-hide");
        window.setTimeout(() => splash.remove(), 450);
    }, 700);
}

function sameOrigin(enlace) {
    if (!enlace) {
        return "";
    }

    const url = new URL(enlace, window.location.href);

    if (url.origin !== window.location.origin) {
        return "";
    }

    return url.pathname + url.search + url.hash;
}

const VAPID_PUBLIC_KEY = "BOpxmyj8Iu3z1kjB1h0FUj32jvOwAjrSeCurj8FCTzjZ-t2tQ7XZoilHSvmf0B8_ZJPp0EUg4AR4FUmkXq6K9WA";

function urlBase64ToUint8Array(base64String) {
    const padding = "=".repeat((4 - base64String.length % 4) % 4);
    const base64 = (base64String + padding).replace(/-/g, "+").replace(/_/g, "/");
    const rawData = window.atob(base64);

    return Uint8Array.from([...rawData].map(char => char.charCodeAt(0)));
}

async function activatePush() {
    if (!("serviceWorker" in navigator) || !("PushManager" in window)) {
        throw new Error("Este navegador no soporta notificaciones push.");
    }

    const permission = await Notification.requestPermission();

    if (permission !== "granted") {
        throw new Error("No se concedió el permiso para notificaciones.");
    }

    const registration = await navigator.serviceWorker.ready;
    const applicationServerKey = urlBase64ToUint8Array(VAPID_PUBLIC_KEY);
    let subscription = await registration.pushManager.getSubscription();

    if (subscription) {
        await subscription.unsubscribe();
    }

    try {
        subscription = await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey
        });
    } catch (error) {
        const message = String(error && error.message || "");

        if (message.indexOf("push service") === -1) {
            throw error;
        }

        const fresh = await navigator.serviceWorker.register("service-worker.js");
        await navigator.serviceWorker.ready;
        subscription = await fresh.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey
        });
    }

    if (!FlowApi.token) {
        await FlowApi.request("session");
    }

    const response = await fetch("push/subscribe.php?v=12", {
        method: "POST",
        credentials: "same-origin",
        headers: {
            "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
            "X-Creova-Token": FlowApi.token
        },
        body: new URLSearchParams({
            payload: btoa(unescape(encodeURIComponent(JSON.stringify(subscription.toJSON()))))
        })
    });
    const raw = await response.text();
    let result;

    try {
        result = JSON.parse(raw);
    } catch (error) {
        throw new Error("No se pudo registrar el dispositivo.");
    }

    if (!response.ok) {
        throw new Error(result.message || "No se pudo registrar el dispositivo.");
    }
}

function openNotifyPanel() {
    const panel = document.getElementById("notifyPanel");
    const toggle = document.getElementById("notifyToggle");

    if (!panel || !toggle) {
        return;
    }

    panel.classList.remove("d-none");
    toggle.setAttribute("aria-expanded", "true");
}

document.addEventListener("DOMContentLoaded", () => {
    const toggle = document.getElementById("notifyToggle");
    const panel = document.getElementById("notifyPanel");

    if (!toggle || !panel) {
        return;
    }

    toggle.addEventListener("click", event => {
        event.stopPropagation();
        const willOpen = panel.classList.contains("d-none");
        panel.classList.toggle("d-none", !willOpen);
        toggle.setAttribute("aria-expanded", willOpen ? "true" : "false");
    });
    panel.addEventListener("click", event => event.stopPropagation());
    document.addEventListener("click", () => {
        panel.classList.add("d-none");
        toggle.setAttribute("aria-expanded", "false");
    });
});

window.addEventListener("load", hideSplash);

if ("serviceWorker" in navigator) {
    window.addEventListener("load", () => {
        navigator.serviceWorker.register("service-worker.js").catch(() => {});
    });
}
