const state = {
    data: null,
    section: "inicio",
    offerId: 0,
    projectId: 0,
    requestId: 0
};

const openRequestStates = ["nueva", "en_revision", "informacion_adicional", "cotizacion_enviada"];

function byId(list, id) {
    return (list || []).find(item => Number(item.id) === Number(id)) || null;
}

function remember(section, params) {
    const url = new URL(window.location.href);
    url.search = "";
    url.searchParams.set("seccion", section);
    Object.entries(params || {}).forEach(([key, value]) => {
        if (value) {
            url.searchParams.set(key, String(value));
        }
    });
    history.replaceState({}, "", url);
}

function paintSection() {
    document.querySelectorAll(".content-section").forEach(section => {
        section.classList.toggle("d-none", section.id !== state.section);
    });
    document.querySelectorAll("[data-section]").forEach(link => {
        link.classList.toggle("active", link.dataset.section === state.section);
    });
}

function showSection(name) {
    state.section = name;
    state.offerId = 0;
    state.projectId = 0;
    state.requestId = 0;
    remember(name, {});
    paintSection();
    render();
}

function applyQuery() {
    const params = new URLSearchParams(window.location.search);
    const id = Number(params.get("id") || 0);
    state.section = params.get("seccion") || "inicio";
    state.offerId = 0;
    state.projectId = 0;
    state.requestId = 0;

    if (state.section === "notificaciones") {
        state.section = "inicio";
        openNotifyPanel();
    }

    if (state.section === "ofertas") {
        state.offerId = id;
    } else if (state.section === "proyectos") {
        state.projectId = id;
    } else if (state.section === "solicitudes") {
        state.requestId = id;
    }
}

async function refresh() {
    state.data = await FlowApi.request("bootstrap");
    setUnread(state.data.notifications.filter(item => !item.leida).length);
    document.getElementById("currentUserName").textContent = state.data.user.nombre;
    document.getElementById("homeTitle").textContent = "Hola, " + state.data.user.nombre + ".";
    document.getElementById("welcomeLine").textContent = "Bienvenido a Creova Flow";
    document.getElementById("profileName").value = state.data.user.nombre;
    document.getElementById("profileEmail").value = state.data.user.correo;
    paintSection();
    render();
}

async function run(action, body) {
    showAlert("");
    const result = await FlowApi.request(action, { method: "POST", body });
    showAlert(result.message || "Listo.", true);
    await refresh();
}

function statCard(title, value) {
    const col = document.createElement("div");
    col.className = "col-md-3";
    const card = document.createElement("div");
    card.className = "stat-card";
    const label = document.createElement("h5");
    label.textContent = title;
    const number = document.createElement("h2");
    number.textContent = String(value);
    card.append(label, number);
    col.appendChild(card);
    return col;
}

function activeSubscription(offerId) {
    return state.data.subscriptions.find(item => Number(item.ofertaId) === Number(offerId) && item.estado === "activa");
}

function renderInicio() {
    const box = document.getElementById("homeStats");
    box.innerHTML = "";
    [
        ["Ofertas disponibles", state.data.offers.length],
        ["Solicitudes abiertas", state.data.requests.filter(item => openRequestStates.includes(item.estado)).length],
        ["Mis proyectos", state.data.projects.length],
        ["Suscripciones activas", state.data.subscriptions.filter(item => item.estado === "activa").length]
    ].forEach(pair => box.appendChild(statCard(pair[0], pair[1])));
    renderNotificationList(
        document.getElementById("homeNotices"),
        state.data.notifications.slice(0, 3),
        openNotice
    );
}

function renderOfertas() {
    const box = document.getElementById("offerList");
    box.innerHTML = "";
    const offer = byId(state.data.offers, state.offerId);

    if (offer) {
        box.appendChild(renderOfferDetail(offer));
        return;
    }

    if (!state.data.offers.length) {
        box.appendChild(emptyState("No hay ofertas publicadas por ahora."));
        return;
    }

    const row = document.createElement("div");
    row.className = "row g-3";
    state.data.offers.forEach(item => {
        const col = document.createElement("div");
        col.className = "col-md-6 col-lg-4";
        const card = document.createElement("article");
        card.className = "project-card";
        const title = document.createElement("h5");
        title.textContent = item.nombre;
        card.append(
            title,
            pill(labelOf("modalidad", item.modalidad)),
            kv("Resumen", item.descripcionCorta),
            kv("Precio", item.precio || "Por definir"),
            kv("Duración", item.duracion || "Por definir")
        );
        const button = document.createElement("button");
        button.type = "button";
        button.className = "btn btn-primary btn-sm";
        button.textContent = "Ver oferta";
        button.addEventListener("click", () => {
            state.offerId = item.id;
            remember("ofertas", { id: item.id });
            renderOfertas();
        });
        card.appendChild(button);
        col.appendChild(card);
        row.appendChild(col);
    });
    box.appendChild(row);
}

function renderOfferDetail(offer) {
    const panel = document.createElement("article");
    panel.className = "detail-panel";
    const back = document.createElement("button");
    back.type = "button";
    back.className = "btn btn-outline-light btn-sm mb-3";
    back.textContent = "Volver a ofertas";
    back.addEventListener("click", () => {
        state.offerId = 0;
        remember("ofertas", {});
        renderOfertas();
    });
    const title = document.createElement("h3");
    title.textContent = offer.nombre;
    panel.append(back, title, pill(labelOf("modalidad", offer.modalidad)));
    if (isHttp(offer.portada)) {
        const image = document.createElement("img");
        image.className = "cover";
        image.alt = "";
        image.src = offer.portada;
        panel.appendChild(image);
    }
    panel.append(
        kv("Descripción", offer.descripcionCorta),
        kv("Problema que resuelve", offer.problema, true),
        kv("Beneficios", offer.beneficios, true),
        kv("Alcance", offer.alcance, true),
        kv("Entregables", offer.entregables, true),
        kv("Precio", offer.precio),
        kv("Duración", offer.duracion),
        kv("Disponibilidad", offer.disponibilidad)
    );
    (offer.archivos || []).forEach(file => {
        if (!isHttp(file.url)) {
            return;
        }
        const link = document.createElement("a");
        link.href = file.url;
        link.target = "_blank";
        link.rel = "noopener noreferrer";
        link.textContent = file.nombre;
        const p = document.createElement("p");
        p.appendChild(link);
        panel.appendChild(p);
    });

    const subscription = activeSubscription(offer.id);
    if (offer.modalidad === "suscribirse") {
        if (subscription) {
            panel.appendChild(kv("Suscripción", "Ya tienes una suscripción activa a esta oferta."));
            const open = document.createElement("button");
            open.type = "button";
            open.className = "btn btn-primary";
            open.textContent = "Ver mi proyecto";
            open.addEventListener("click", () => {
                state.section = "proyectos";
                state.projectId = subscription.proyectoId;
                remember("proyectos", { id: subscription.proyectoId });
                paintSection();
                render();
            });
            panel.appendChild(open);
        } else {
            const button = document.createElement("button");
            button.type = "button";
            button.className = "btn btn-primary";
            button.textContent = "Suscribirme";
            button.addEventListener("click", async () => {
                if (!window.confirm("¿Confirmas la suscripción a " + offer.nombre + "?")) {
                    return;
                }
                try {
                    await run("subscribe", { ofertaId: offer.id });
                    state.section = "proyectos";
                    paintSection();
                } catch (error) {
                    showAlert(error.message);
                }
            });
            panel.appendChild(button);
        }
        return panel;
    }

    const form = document.createElement("form");
    form.className = "mt-3";
    const note = document.createElement("textarea");
    note.className = "form-control mb-3";
    note.rows = 4;
    note.required = true;
    note.placeholder = "Cuéntanos qué necesitas";
    const button = document.createElement("button");
    button.className = "btn btn-primary";
    button.type = "submit";
    button.textContent = labelOf("modalidad", offer.modalidad);
    form.append(note, button);
    form.addEventListener("submit", async event => {
        event.preventDefault();
        if (!window.confirm("¿Confirmas esta " + labelOf("modalidad", offer.modalidad).toLowerCase() + "?")) {
            return;
        }
        try {
            await run("request", { ofertaId: offer.id, nota: note.value });
            state.section = offer.modalidad === "iniciar" ? "proyectos" : "solicitudes";
            paintSection();
        } catch (error) {
            showAlert(error.message);
        }
    });
    panel.appendChild(form);
    return panel;
}

function renderProyectos() {
    const box = document.getElementById("projectList");
    box.innerHTML = "";
    const subs = state.data.subscriptions.filter(item => item.estado === "activa");
    if (subs.length && !state.projectId) {
        const intro = document.createElement("div");
        intro.className = "detail-panel mb-4";
        const heading = document.createElement("h3");
        heading.textContent = "Suscripciones activas";
        intro.appendChild(heading);
        subs.forEach(item => intro.appendChild(kv(item.ofertaNombre, "Suscripción · " + formatWhen(item.creado))));
        box.appendChild(intro);
    }
    const project = byId(state.data.projects, state.projectId);
    if (project) {
        box.appendChild(renderProjectDetail(project));
        return;
    }
    if (!state.data.projects.length) {
        box.appendChild(emptyState("Cuando solicites o te suscribas, el proyecto aparecerá aquí."));
        return;
    }
    const row = document.createElement("div");
    row.className = "row g-3";
    state.data.projects.forEach(item => {
        const col = document.createElement("div");
        col.className = "col-md-6";
        const card = document.createElement("article");
        card.className = "project-card";
        const title = document.createElement("h5");
        title.textContent = item.nombre;
        card.append(
            title,
            pill(labelOf("proyecto", item.estado)),
            kv("Oferta", item.oferta ? item.oferta.nombre : "Sin oferta"),
            kv("Actividad", formatWhen(item.ultimaActividad))
        );
        const button = document.createElement("button");
        button.type = "button";
        button.className = "btn btn-outline-light btn-sm";
        button.textContent = "Ver proyecto";
        button.addEventListener("click", () => {
            state.projectId = item.id;
            remember("proyectos", { id: item.id });
            renderProyectos();
        });
        card.appendChild(button);
        col.appendChild(card);
        row.appendChild(col);
    });
    box.appendChild(row);
}

function renderProjectDetail(project) {
    const panel = document.createElement("article");
    panel.className = "detail-panel";
    const back = document.createElement("button");
    back.type = "button";
    back.className = "btn btn-outline-light btn-sm mb-3";
    back.textContent = "Volver a mis proyectos";
    back.addEventListener("click", () => {
        state.projectId = 0;
        remember("proyectos", {});
        renderProyectos();
    });
    const title = document.createElement("h3");
    title.textContent = project.nombre;
    panel.append(
        back,
        title,
        pill(labelOf("proyecto", project.estado)),
        kv("Oferta", project.oferta ? project.oferta.nombre : "Sin oferta"),
        kv("Estado", labelOf("proyecto", project.estado)),
        kv("Descripción", project.descripcion, true)
    );
    const history = document.createElement("h4");
    history.className = "mt-4";
    history.textContent = "Historial";
    panel.appendChild(history);
    (project.historial || []).slice().reverse().forEach(item => {
        panel.appendChild(kv(formatWhen(item.fecha) + " · " + item.actorNombre, item.texto, true));
    });
    const comments = document.createElement("h4");
    comments.className = "mt-4";
    comments.textContent = "Comentarios";
    panel.appendChild(comments);
    (project.comentarios || []).forEach(comment => {
        panel.appendChild(kv(comment.nombre + " · " + formatWhen(comment.fecha), comment.texto, true));
    });
    const form = document.createElement("form");
    form.className = "mt-3";
    const input = document.createElement("textarea");
    input.className = "form-control mb-3";
    input.required = true;
    input.placeholder = "Escribe un mensaje para el equipo";
    const button = document.createElement("button");
    button.className = "btn btn-primary";
    button.type = "submit";
    button.textContent = "Enviar comentario";
    form.append(input, button);
    form.addEventListener("submit", async event => {
        event.preventDefault();
        try {
            await run("comment", { proyectoId: project.id, texto: input.value });
            state.section = "proyectos";
            state.projectId = project.id;
            paintSection();
        } catch (error) {
            showAlert(error.message);
        }
    });
    panel.appendChild(form);
    return panel;
}

function renderSolicitudes() {
    const box = document.getElementById("requestList");
    box.innerHTML = "";
    const request = byId(state.data.requests, state.requestId);
    if (request) {
        box.appendChild(renderRequestDetail(request));
    }
    if (!state.data.requests.length) {
        box.appendChild(emptyState("Todavía no envías solicitudes. Las suscripciones se consultan en Mis proyectos."));
        return;
    }
    state.data.requests.slice().sort((a, b) => b.id - a.id).forEach(item => {
        const button = document.createElement("button");
        button.type = "button";
        button.className = "notice";
        const title = document.createElement("strong");
        title.textContent = item.ofertaNombre;
        const meta = document.createElement("span");
        meta.className = "notice-meta";
        meta.textContent = "Solicitud · " + labelOf("modalidad", item.tipo) + " · " + labelOf("solicitud", item.estado);
        const message = document.createElement("p");
        message.textContent = item.nota;
        button.append(title, meta, message);
        button.addEventListener("click", () => {
            state.requestId = item.id;
            remember("solicitudes", { id: item.id });
            renderSolicitudes();
        });
        box.appendChild(button);
    });
}

function renderRequestDetail(request) {
    const panel = document.createElement("article");
    panel.className = "detail-panel mb-4";
    const title = document.createElement("h3");
    title.textContent = request.ofertaNombre;
    panel.append(
        title,
        pill("Solicitud"),
        pill(labelOf("solicitud", request.estado)),
        kv("Tipo", labelOf("modalidad", request.tipo)),
        kv("Proyecto", request.proyectoNombre || "Todavía sin proyecto"),
        kv("Tu nota", request.nota, true),
        kv("Respuesta", request.respuesta, true)
    );
    if (request.estado === "informacion_adicional") {
        const form = document.createElement("form");
        const input = document.createElement("textarea");
        input.className = "form-control mb-3";
        input.required = true;
        input.placeholder = "Escribe la información que te pidieron";
        const button = document.createElement("button");
        button.className = "btn btn-primary";
        button.type = "submit";
        button.textContent = "Enviar información";
        form.append(input, button);
        form.addEventListener("submit", async event => {
            event.preventDefault();
            try {
                await run("request-reply", { id: request.id, texto: input.value });
                state.section = "solicitudes";
                state.requestId = request.id;
                paintSection();
            } catch (error) {
                showAlert(error.message);
            }
        });
        panel.appendChild(form);
    }
    if (openRequestStates.includes(request.estado)) {
        const cancel = document.createElement("button");
        cancel.type = "button";
        cancel.className = "btn btn-outline-light mt-3";
        cancel.textContent = "Cancelar solicitud";
        cancel.addEventListener("click", async () => {
            if (!window.confirm("¿Cancelas esta solicitud?")) {
                return;
            }
            try {
                await run("request-status", { id: request.id, estado: "cancelada", nota: "" });
                state.section = "solicitudes";
                paintSection();
            } catch (error) {
                showAlert(error.message);
            }
        });
        panel.appendChild(cancel);
    }
    return panel;
}

async function openNotice(item) {
    try {
        await FlowApi.request("notification-read", { method: "POST", body: { id: item.id } });
        const path = sameOrigin(item.enlace);
        if (path) {
            window.location.href = path;
            return;
        }
        await refresh();
    } catch (error) {
        showAlert(error.message);
    }
}

function render() {
    if (!state.data) {
        return;
    }
    renderInicio();
    renderOfertas();
    renderProyectos();
    renderSolicitudes();
    renderNotificationList(document.getElementById("notificationList"), state.data.notifications, openNotice);
}

document.addEventListener("DOMContentLoaded", async () => {
    document.querySelectorAll("[data-section]").forEach(link => {
        link.addEventListener("click", event => {
            event.preventDefault();
            showSection(link.dataset.section);
        });
    });
    document.getElementById("logoutBtn").addEventListener("click", async () => {
        await FlowApi.request("logout", { method: "POST", body: {} });
        window.location.href = "acceso.php";
    });
    document.getElementById("notificationBtn").addEventListener("click", async () => {
        try {
            await activatePush();
            showAlert("Notificaciones del dispositivo activadas.", true);
        } catch (error) {
            showAlert(error.message);
        }
    });
    document.getElementById("markReadBtn").addEventListener("click", async () => {
        try {
            await run("notification-read-all", {});
            openNotifyPanel();
        } catch (error) {
            showAlert(error.message);
        }
    });
    document.getElementById("profileForm").addEventListener("submit", async event => {
        event.preventDefault();
        try {
            await run("profile", {
                nombre: document.getElementById("profileName").value,
                correo: document.getElementById("profileEmail").value,
                password: document.getElementById("profilePassword").value,
                passwordConfirmacion: document.getElementById("profilePassword2").value
            });
            document.getElementById("profilePassword").value = "";
            document.getElementById("profilePassword2").value = "";
            state.section = "perfil";
            paintSection();
        } catch (error) {
            showAlert(error.message);
        }
    });

    try {
        applyQuery();
        await refresh();
    } catch (error) {
        showAlert(error.message);
    }
});
