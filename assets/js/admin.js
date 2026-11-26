const state = {
    data: null,
    section: "inicio",
    offerTab: "catalogo",
    projectId: 0,
    offerId: 0,
    requestId: 0,
    userId: 0
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
    state.projectId = 0;
    state.offerId = 0;
    state.requestId = 0;
    state.userId = 0;
    remember(name, {});
    paintSection();
    render();
}

function applyQuery() {
    const params = new URLSearchParams(window.location.search);
    const id = Number(params.get("id") || 0);
    state.section = params.get("seccion") || "inicio";
    state.requestId = Number(params.get("solicitud") || 0);
    state.projectId = 0;
    state.offerId = 0;
    state.userId = 0;

    if (state.requestId) {
        state.section = "ofertas";
        state.offerTab = "solicitudes";
        return;
    }

    if (state.section === "notificaciones") {
        state.section = "inicio";
        openNotifyPanel();
    }

    if (state.section === "biblioteca") {
        state.projectId = id;
    } else if (state.section === "ofertas") {
        state.offerId = id;
        state.offerTab = "catalogo";
    } else if (state.section === "usuarios") {
        state.userId = id;
    }
}

async function refresh() {
    state.data = await FlowApi.request("bootstrap");
    setUnread(state.data.notifications.filter(item => !item.leida).length);
    document.getElementById("currentUserName").textContent = state.data.user.nombre;
    paintSection();
    render();
}

async function run(action, body) {
    showAlert("");
    const result = await FlowApi.request(action, { method: "POST", body });
    showAlert(result.message || "Cambio guardado.", true);
    await refresh();
    return result;
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

function fillSelect(select, items, placeholder, selected) {
    select.innerHTML = "";
    const empty = document.createElement("option");
    empty.value = "";
    empty.textContent = placeholder;
    select.appendChild(empty);
    items.forEach(item => {
        const option = document.createElement("option");
        option.value = String(item.id);
        option.textContent = item.nombre;
        select.appendChild(option);
    });
    select.value = selected ? String(selected) : "";
}

function clientName(project) {
    if (project.usuarioPrincipal) {
        return project.usuarioPrincipal.nombre;
    }

    if (project.responsable) {
        return project.responsable;
    }

    return "Proyecto interno";
}

function projectCard(project, onOpen) {
    const col = document.createElement("div");
    col.className = "col-md-6 col-lg-4";
    const card = document.createElement("article");
    card.className = "project-card";
    const title = document.createElement("h5");
    title.textContent = project.nombre;
    card.append(
        title,
        pill(labelOf("proyecto", project.estado)),
        kv("Oferta", project.oferta ? project.oferta.nombre : "Sin oferta"),
        kv("Cliente", clientName(project)),
        kv("Suscritos", String(project.suscritos || 0)),
        kv("Pendientes", String(project.solicitudesPendientes || 0)),
        kv("Última actividad", formatWhen(project.ultimaActividad))
    );
    const button = document.createElement("button");
    button.type = "button";
    button.className = "btn btn-outline-light btn-sm";
    button.textContent = "Ver relación";
    button.addEventListener("click", () => onOpen(project));
    const edit = document.createElement("button");
    edit.type = "button";
    edit.className = "btn btn-warning btn-sm";
    edit.textContent = "Editar";
    edit.addEventListener("click", () => openProjectForm(project));
    card.append(button, edit, deleteProjectButton(project, "btn btn-outline-danger btn-sm"));
    col.appendChild(card);
    return col;
}

function renderInicio() {
    const projects = state.data.projects;
    const requests = state.data.requests;
    const primary = document.getElementById("homeStats");
    const secondary = document.getElementById("homeStatsSecondary");
    primary.innerHTML = "";
    secondary.innerHTML = "";
    [
        ["Proyectos totales", projects.length],
        ["Proyectos en proceso", projects.filter(item => item.estado === "en_proceso").length],
        ["Ofertas activas", state.data.offers.filter(item => item.estado === "publicada").length],
        ["Solicitudes pendientes", requests.filter(item => openRequestStates.includes(item.estado)).length]
    ].forEach(pair => primary.appendChild(statCard(pair[0], pair[1])));
    [
        ["Usuarios registrados", state.data.users.filter(item => item.rol === "user").length],
        ["Suscripciones activas", state.data.subscriptions.filter(item => item.estado === "activa").length],
        ["Solicitudes nuevas", requests.filter(item => item.estado === "nueva").length],
        ["Proyectos terminados", projects.filter(item => item.estado === "completado").length]
    ].forEach(pair => secondary.appendChild(statCard(pair[0], pair[1])));

    const recent = document.getElementById("projectList");
    recent.innerHTML = "";
    const items = projects.slice().sort((a, b) => new Date(b.ultimaActividad) - new Date(a.ultimaActividad)).slice(0, 6);

    if (!items.length) {
        recent.appendChild(emptyState("Todavía no hay proyectos. Crea uno o espera una solicitud."));
        return;
    }

    items.forEach(project => recent.appendChild(projectCard(project, openProject)));
}

function renderProyectos() {
    const box = document.getElementById("allProjects");
    box.innerHTML = "";
    const row = document.createElement("div");
    row.className = "row g-3";

    if (!state.data.projects.length) {
        box.appendChild(emptyState("No hay proyectos registrados."));
        return;
    }

    state.data.projects.forEach(project => row.appendChild(projectCard(project, openProject)));
    box.appendChild(row);
}

function offerPayloadFrom(offer, estado) {
    return {
        id: offer.id,
        nombre: offer.nombre,
        portada: offer.portada || "",
        descripcionCorta: offer.descripcionCorta,
        problema: offer.problema || "",
        beneficios: offer.beneficios || "",
        alcance: offer.alcance || "",
        entregables: offer.entregables || "",
        precio: offer.precio || "",
        duracion: offer.duracion || "",
        modalidad: offer.modalidad,
        disponibilidad: offer.disponibilidad || "",
        archivos: offer.archivos || [],
        estado: estado || offer.estado
    };
}

function renderOfferCard(offer) {
    const col = document.createElement("div");
    col.className = "col-md-6 col-lg-4";
    const card = document.createElement("article");
    card.className = "project-card";
    const title = document.createElement("h5");
    title.textContent = offer.nombre;
    card.append(
        title,
        pill(labelOf("ofertaEstado", offer.estado)),
        kv("Modalidad", labelOf("modalidad", offer.modalidad)),
        kv("Precio", offer.precio || "Por definir"),
        kv("Publicación", formatWhen(offer.publicadaEn))
    );
    const open = document.createElement("button");
    open.type = "button";
    open.className = "btn btn-outline-light btn-sm";
    open.textContent = "Ver";
    open.addEventListener("click", () => {
        state.offerId = offer.id;
        state.offerTab = "catalogo";
        remember("ofertas", { id: offer.id });
        renderOfertas();
    });
    const edit = document.createElement("button");
    edit.type = "button";
    edit.className = "btn btn-warning btn-sm";
    edit.textContent = "Editar";
    edit.addEventListener("click", () => openOfferForm(offer));
    card.append(open, edit);

    if (offer.estado === "publicada") {
        const pause = document.createElement("button");
        pause.type = "button";
        pause.className = "btn btn-outline-light btn-sm";
        pause.textContent = "Pausar";
        pause.addEventListener("click", () => run("offer", offerPayloadFrom(offer, "pausada")).catch(error => showAlert(error.message)));
        card.appendChild(pause);
    }

    col.appendChild(card);
    return col;
}

function renderOfferDetail(offer) {
    const panel = document.createElement("article");
    panel.className = "detail-panel mb-4";
    const back = document.createElement("button");
    back.type = "button";
    back.className = "btn btn-outline-light btn-sm mb-3";
    back.textContent = "Volver al catálogo";
    back.addEventListener("click", () => {
        state.offerId = 0;
        remember("ofertas", {});
        renderOfertas();
    });
    const title = document.createElement("h3");
    title.textContent = offer.nombre;
    panel.append(back, title, pill(labelOf("ofertaEstado", offer.estado)));
    if (isHttp(offer.portada)) {
        const image = document.createElement("img");
        image.className = "cover";
        image.alt = "";
        image.src = offer.portada;
        panel.appendChild(image);
    }
    panel.append(
        kv("Descripción", offer.descripcionCorta),
        kv("Problema", offer.problema, true),
        kv("Beneficios", offer.beneficios, true),
        kv("Alcance", offer.alcance, true),
        kv("Entregables", offer.entregables, true),
        kv("Precio", offer.precio),
        kv("Duración", offer.duracion),
        kv("Modalidad", labelOf("modalidad", offer.modalidad)),
        kv("Disponibilidad", offer.disponibilidad),
        kv("Publicada", formatWhen(offer.publicadaEn))
    );
    appendFiles(panel, offer.archivos);
    const edit = document.createElement("button");
    edit.type = "button";
    edit.className = "btn btn-primary";
    edit.textContent = "Editar oferta";
    edit.addEventListener("click", () => openOfferForm(offer));
    panel.appendChild(edit);
    const related = state.data.requests.filter(item => Number(item.ofertaId) === Number(offer.id));
    const heading = document.createElement("h4");
    heading.className = "mt-4";
    heading.textContent = "Solicitudes de esta oferta";
    panel.appendChild(heading);
    if (!related.length) {
        panel.appendChild(emptyState("Esta oferta todavía no tiene solicitudes."));
    } else {
        related.forEach(request => panel.appendChild(requestRow(request)));
    }
    return panel;
}

function appendFiles(panel, files) {
    if (!files || !files.length) {
        return;
    }

    const heading = document.createElement("h4");
    heading.className = "mt-3";
    heading.textContent = "Archivos de apoyo";
    panel.appendChild(heading);
    files.forEach(file => {
        const p = document.createElement("p");
        p.className = "kv";
        if (isHttp(file.url)) {
            const link = document.createElement("a");
            link.href = file.url;
            link.target = "_blank";
            link.rel = "noopener noreferrer";
            link.textContent = file.nombre;
            p.appendChild(link);
        } else {
            p.textContent = file.nombre;
        }
        panel.appendChild(p);
    });
}

function requestRow(request) {
    const button = document.createElement("button");
    button.type = "button";
    button.className = "notice";
    const title = document.createElement("strong");
    title.textContent = request.usuarioNombre + " · " + request.ofertaNombre;
    const meta = document.createElement("span");
    meta.className = "notice-meta";
    meta.textContent = labelOf("modalidad", request.tipo) + " · " + labelOf("solicitud", request.estado) + " · " + formatWhen(request.creado);
    const message = document.createElement("p");
    message.textContent = request.nota;
    button.append(title, meta, message);
    button.addEventListener("click", () => {
        state.requestId = request.id;
        state.section = "ofertas";
        state.offerTab = "solicitudes";
        remember("ofertas", { solicitud: request.id });
        paintSection();
        renderOfertas();
    });
    return button;
}

function renderRequestDetail(request) {
    const panel = document.createElement("article");
    panel.className = "detail-panel mb-4";
    const back = document.createElement("button");
    back.type = "button";
    back.className = "btn btn-outline-light btn-sm mb-3";
    back.textContent = "Volver a solicitudes";
    back.addEventListener("click", () => {
        state.requestId = 0;
        remember("ofertas", {});
        renderOfertas();
    });
    const title = document.createElement("h3");
    title.textContent = request.ofertaNombre;
    panel.append(
        back,
        title,
        pill(labelOf("solicitud", request.estado)),
        kv("Persona", request.usuarioNombre + " · " + request.usuarioCorreo),
        kv("Tipo", labelOf("modalidad", request.tipo)),
        kv("Proyecto", request.proyectoNombre || "Todavía sin proyecto"),
        kv("Nota", request.nota, true),
        kv("Respuesta", request.respuesta, true)
    );
    if (request.proyectoId) {
        const open = document.createElement("button");
        open.type = "button";
        open.className = "btn btn-outline-light mb-3";
        open.textContent = "Abrir proyecto";
        open.addEventListener("click", () => openProject(byId(state.data.projects, request.proyectoId)));
        panel.appendChild(open);
    }
    const form = document.createElement("form");
    form.className = "mt-3";
    const select = document.createElement("select");
    select.className = "form-select mb-3";
    Object.entries(FLOW_LABELS.solicitud).forEach(([value, text]) => {
        const option = document.createElement("option");
        option.value = value;
        option.textContent = text;
        select.appendChild(option);
    });
    select.value = request.estado;
    const note = document.createElement("textarea");
    note.className = "form-control mb-3";
    note.rows = 2;
    note.placeholder = "Nota para la persona, si quieres acompañar el cambio";
    const submit = document.createElement("button");
    submit.className = "btn btn-primary";
    submit.type = "submit";
    submit.textContent = "Actualizar solicitud";
    form.append(select, note, submit);
    form.addEventListener("submit", async event => {
        event.preventDefault();
        try {
            await run("request-status", { id: request.id, estado: select.value, nota: note.value });
            state.requestId = request.id;
            state.offerTab = "solicitudes";
            state.section = "ofertas";
        } catch (error) {
            showAlert(error.message);
        }
    });
    panel.appendChild(form);
    appendHistory(panel, request.historial);
    return panel;
}

function appendHistory(panel, items) {
    const heading = document.createElement("h4");
    heading.className = "mt-4";
    heading.textContent = "Historial";
    panel.appendChild(heading);
    (items || []).slice().reverse().forEach(item => {
        const p = document.createElement("p");
        p.className = "kv";
        p.textContent = formatWhen(item.fecha) + " · " + item.actorNombre + " · " + item.texto;
        panel.appendChild(p);
    });
}

function renderOfertas() {
    document.querySelectorAll("[data-offer-tab]").forEach(button => {
        const active = button.dataset.offerTab === state.offerTab;
        button.classList.toggle("btn-primary", active);
        button.classList.toggle("btn-outline-light", !active);
    });
    const catalog = document.getElementById("offerCatalog");
    const requests = document.getElementById("offerRequests");
    catalog.classList.toggle("d-none", state.offerTab !== "catalogo");
    requests.classList.toggle("d-none", state.offerTab !== "solicitudes");
    catalog.innerHTML = "";
    requests.innerHTML = "";

    if (state.offerTab === "catalogo") {
        const offer = byId(state.data.offers, state.offerId);
        if (offer) {
            catalog.appendChild(renderOfferDetail(offer));
        }
        const row = document.createElement("div");
        row.className = "row g-3";
        if (!state.data.offers.length) {
            catalog.appendChild(emptyState("Crea la primera oferta."));
            return;
        }
        state.data.offers.forEach(item => row.appendChild(renderOfferCard(item)));
        catalog.appendChild(row);
        return;
    }

    const current = byId(state.data.requests, state.requestId);
    if (current) {
        requests.appendChild(renderRequestDetail(current));
    }
    if (!state.data.requests.length) {
        requests.appendChild(emptyState("No hay solicitudes."));
        return;
    }
    state.data.requests
        .slice()
        .sort((a, b) => b.id - a.id)
        .forEach(request => requests.appendChild(requestRow(request)));
}

function openProject(project) {
    if (!project) {
        return;
    }
    state.section = "biblioteca";
    state.projectId = project.id;
    remember("biblioteca", { id: project.id });
    paintSection();
    renderBiblioteca();
}

function renderBiblioteca() {
    const box = document.getElementById("libraryList");
    box.innerHTML = "";
    const project = byId(state.data.projects, state.projectId);

    if (project) {
        box.appendChild(renderProjectDetail(project));
        return;
    }

    if (!state.data.projects.length) {
        box.appendChild(emptyState("La biblioteca mostrará aquí la relación entre proyecto, oferta y usuario."));
        return;
    }

    const row = document.createElement("div");
    row.className = "row g-3";
    state.data.projects.forEach(item => row.appendChild(projectCard(item, openProject)));
    box.appendChild(row);
}

function renderProjectDetail(project) {
    const panel = document.createElement("article");
    panel.className = "detail-panel";
    const back = document.createElement("button");
    back.type = "button";
    back.className = "btn btn-outline-light btn-sm mb-3";
    back.textContent = "Volver a la biblioteca";
    back.addEventListener("click", () => {
        state.projectId = 0;
        remember("biblioteca", {});
        renderBiblioteca();
    });
    const title = document.createElement("h3");
    title.textContent = project.nombre;
    panel.append(
        back,
        title,
        pill(labelOf("proyecto", project.estado)),
        kv("Oferta de origen", project.oferta ? project.oferta.nombre : "Sin oferta"),
        kv("Usuario principal", clientName(project)),
        kv("Creado", formatWhen(project.creado)),
        kv("Última actividad", formatWhen(project.ultimaActividad)),
        kv("Descripción", project.descripcion, true),
        kv("Solicitudes pendientes", String(project.solicitudesPendientes || 0))
    );
    const people = document.createElement("h4");
    people.className = "mt-4";
    people.textContent = "Usuarios suscritos";
    panel.appendChild(people);
    if (!project.suscriptoresDetalle.length) {
        panel.appendChild(kv("Suscritos", "Nadie suscrito todavía"));
    } else {
        project.suscriptoresDetalle.forEach(person => {
            panel.appendChild(kv(person.nombre, person.correo || "Suscripción activa"));
        });
    }
    const requestsTitle = document.createElement("h4");
    requestsTitle.className = "mt-4";
    requestsTitle.textContent = "Solicitantes";
    panel.appendChild(requestsTitle);
    if (!project.solicitudes.length) {
        panel.appendChild(emptyState("No hay solicitudes ligadas a este proyecto."));
    } else {
        project.solicitudes.forEach(request => panel.appendChild(requestRow(request)));
    }
    appendHistory(panel, project.historial);
    const commentsTitle = document.createElement("h4");
    commentsTitle.className = "mt-4";
    commentsTitle.textContent = "Comentarios";
    panel.appendChild(commentsTitle);
    (project.comentarios || []).forEach(comment => {
        panel.appendChild(kv(comment.nombre + " · " + formatWhen(comment.fecha), comment.texto, true));
    });
    panel.appendChild(commentForm(project.id));
    const statusTitle = document.createElement("h4");
    statusTitle.className = "mt-4";
    statusTitle.textContent = "Estado actual";
    panel.appendChild(statusTitle);
    panel.appendChild(projectStatusForm(project));
    const sent = document.createElement("h4");
    sent.className = "mt-4";
    sent.textContent = "Notificaciones enviadas";
    panel.appendChild(sent);
    if (!project.notificaciones.length) {
        panel.appendChild(emptyState("Todavía no hay avisos de este proyecto."));
    } else {
        project.notificaciones.forEach(item => {
            panel.appendChild(kv(item.titulo + " · " + formatWhen(item.fecha), item.mensaje));
        });
    }
    const edit = document.createElement("button");
    edit.type = "button";
    edit.className = "btn btn-warning";
    edit.textContent = "Editar datos";
    edit.addEventListener("click", () => openProjectForm(project));
    const actions = document.createElement("div");
    actions.className = "d-flex flex-wrap gap-2 mt-3";
    actions.append(edit, deleteProjectButton(project, "btn btn-outline-danger"));
    panel.appendChild(actions);
    return panel;
}

function deleteProjectButton(project, className) {
    const button = document.createElement("button");
    button.type = "button";
    button.className = className;
    button.textContent = "Eliminar";
    button.addEventListener("click", async () => {
        const name = project.nombre || "este proyecto";
        if (!window.confirm("¿Eliminar el proyecto \"" + name + "\"? No se puede deshacer.")) {
            return;
        }
        try {
            state.projectId = 0;
            await run("project-delete", { id: project.id });
        } catch (error) {
            showAlert(error.message);
        }
    });
    return button;
}

function commentForm(projectId) {
    const form = document.createElement("form");
    form.className = "mt-3";
    const input = document.createElement("textarea");
    input.className = "form-control mb-3";
    input.rows = 2;
    input.placeholder = "Escribe una actualización para las personas del proyecto";
    input.required = true;
    const button = document.createElement("button");
    button.className = "btn btn-primary";
    button.type = "submit";
    button.textContent = "Publicar comentario";
    form.append(input, button);
    form.addEventListener("submit", async event => {
        event.preventDefault();
        try {
            await run("comment", { proyectoId: projectId, texto: input.value });
            state.section = "biblioteca";
            state.projectId = projectId;
        } catch (error) {
            showAlert(error.message);
        }
    });
    return form;
}

function projectStatusForm(project) {
    const form = document.createElement("form");
    const select = document.createElement("select");
    select.className = "form-select mb-3";
    Object.entries(FLOW_LABELS.proyecto).forEach(([value, text]) => {
        const option = document.createElement("option");
        option.value = value;
        option.textContent = text;
        select.appendChild(option);
    });
    select.value = project.estado;
    const note = document.createElement("textarea");
    note.className = "form-control mb-3";
    note.rows = 2;
    note.placeholder = "Nota del cambio de estado";
    const button = document.createElement("button");
    button.className = "btn btn-primary";
    button.type = "submit";
    button.textContent = "Guardar estado";
    form.append(select, note, button);
    form.addEventListener("submit", async event => {
        event.preventDefault();
        try {
            await run("project-status", { id: project.id, estado: select.value, nota: note.value });
            state.section = "biblioteca";
            state.projectId = project.id;
        } catch (error) {
            showAlert(error.message);
        }
    });
    return form;
}

function renderUsuarios() {
    const box = document.getElementById("userList");
    box.innerHTML = "";
    const user = byId(state.data.users, state.userId);

    if (user) {
        box.appendChild(renderUserDetail(user));
    }

    const people = state.data.users.filter(item => item.rol === "user");
    if (!people.length) {
        box.appendChild(emptyState("Cuando alguien se registre, aparecerá aquí."));
        return;
    }
    people.forEach(person => {
        const button = document.createElement("button");
        button.type = "button";
        button.className = "notice";
        const title = document.createElement("strong");
        title.textContent = person.nombre;
        const meta = document.createElement("span");
        meta.className = "notice-meta";
        meta.textContent = person.correo + " · alta " + formatWhen(person.creado);
        button.append(title, meta);
        button.addEventListener("click", () => {
            state.userId = person.id;
            remember("usuarios", { id: person.id });
            renderUsuarios();
        });
        box.appendChild(button);
    });
}

function renderUserDetail(user) {
    const panel = document.createElement("article");
    panel.className = "detail-panel mb-4";
    const subs = state.data.subscriptions.filter(item => Number(item.userId) === Number(user.id));
    const requests = state.data.requests.filter(item => Number(item.userId) === Number(user.id));
    const projects = state.data.projects.filter(project => {
        if (project.usuarioPrincipal && Number(project.usuarioPrincipal.id) === Number(user.id)) {
            return true;
        }
        return (project.suscriptoresDetalle || []).some(person => Number(person.id) === Number(user.id));
    });
    const title = document.createElement("h3");
    title.textContent = user.nombre;
    panel.append(title, kv("Correo", user.correo), kv("Alta", formatWhen(user.creado)));
    const subTitle = document.createElement("h4");
    subTitle.className = "mt-3";
    subTitle.textContent = "Suscripciones";
    panel.appendChild(subTitle);
    if (!subs.length) {
        panel.appendChild(kv("Suscripciones", "Ninguna"));
    } else {
        subs.forEach(item => {
            panel.appendChild(kv(item.ofertaNombre, item.estado + " · " + formatWhen(item.creado)));
        });
    }
    const reqTitle = document.createElement("h4");
    reqTitle.className = "mt-3";
    reqTitle.textContent = "Solicitudes";
    panel.appendChild(reqTitle);
    if (!requests.length) {
        panel.appendChild(emptyState("Sin solicitudes."));
    } else {
        requests.forEach(request => panel.appendChild(requestRow(request)));
    }
    const projTitle = document.createElement("h4");
    projTitle.className = "mt-3";
    projTitle.textContent = "Proyectos";
    panel.appendChild(projTitle);
    if (!projects.length) {
        panel.appendChild(emptyState("Sin proyectos relacionados."));
    } else {
        projects.forEach(project => {
            const button = document.createElement("button");
            button.type = "button";
            button.className = "btn btn-outline-light me-2 mb-2";
            button.textContent = project.nombre;
            button.addEventListener("click", () => openProject(project));
            panel.appendChild(button);
        });
    }
    return panel;
}

function renderNotificaciones() {
    renderNotificationList(document.getElementById("notificationList"), state.data.notifications, async item => {
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
    });
}

function renderConfig() {
    const correos = state.data.settings.correos || {};
    document.querySelectorAll("#settingsForm input[name]").forEach(input => {
        input.checked = Boolean(correos[input.name]);
    });
    const log = document.getElementById("mailLog");
    log.innerHTML = "";
    if (!state.data.mails.length) {
        log.appendChild(emptyState("Todavía no se registra ningún correo."));
        return;
    }
    state.data.mails.forEach(mail => {
        const box = document.createElement("article");
        box.className = "notice";
        const title = document.createElement("strong");
        title.textContent = mail.asunto;
        const meta = document.createElement("span");
        meta.className = "notice-meta";
        meta.textContent = mail.para + " · " + formatWhen(mail.fecha) + " · " + (mail.enviado ? "enviado" : (mail.error || "no enviado"));
        const body = document.createElement("p");
        body.className = "pre";
        body.textContent = mail.cuerpo;
        box.append(title, meta, body);
        log.appendChild(box);
    });
}

function render() {
    if (!state.data) {
        return;
    }
    renderInicio();
    renderProyectos();
    renderOfertas();
    renderBiblioteca();
    renderUsuarios();
    renderNotificaciones();
    renderConfig();
}

function openOfferForm(offer) {
    const form = document.getElementById("offerForm");
    form.reset();
    document.getElementById("offerId").value = offer ? offer.id : "";
    if (offer) {
        document.getElementById("offerName").value = offer.nombre || "";
        document.getElementById("offerCover").value = offer.portada || "";
        document.getElementById("offerShort").value = offer.descripcionCorta || "";
        document.getElementById("offerProblem").value = offer.problema || "";
        document.getElementById("offerBenefits").value = offer.beneficios || "";
        document.getElementById("offerScope").value = offer.alcance || "";
        document.getElementById("offerDeliverables").value = offer.entregables || "";
        document.getElementById("offerPrice").value = offer.precio || "";
        document.getElementById("offerDuration").value = offer.duracion || "";
        document.getElementById("offerMode").value = offer.modalidad || "solicitar";
        document.getElementById("offerStatus").value = offer.estado || "borrador";
        document.getElementById("offerAvailability").value = offer.disponibilidad || "";
        document.getElementById("offerFiles").value = formatFiles(offer.archivos);
    } else {
        document.getElementById("offerMode").value = "solicitar";
        document.getElementById("offerStatus").value = "borrador";
        document.getElementById("offerAvailability").value = "Cupo abierto";
    }
    bootstrap.Modal.getOrCreateInstance(document.getElementById("offerModal")).show();
}

function openProjectForm(project) {
    fillSelect(
        document.getElementById("projectOffer"),
        state.data.offers,
        "Sin oferta relacionada",
        project && project.ofertaId
    );
    fillSelect(
        document.getElementById("projectUser"),
        state.data.users.filter(user => user.rol === "user"),
        "Proyecto interno",
        project && project.usuarioPrincipalId
    );
    document.getElementById("projectId").value = project ? project.id : "";
    document.getElementById("projectName").value = project ? project.nombre : "";
    document.getElementById("projectCategory").value = project && project.categoria ? project.categoria : "";
    document.getElementById("projectDescription").value = project ? (project.descripcion || "") : "";
    document.getElementById("projectStatus").value = project ? project.estado : "pendiente";
    bootstrap.Modal.getOrCreateInstance(document.getElementById("projectModal")).show();
}

async function importLocalProjectsOnce() {
    if (localStorage.getItem("creovaLocalImported") === "1" || typeof openDatabase !== "function") {
        return 0;
    }

    try {
        await openDatabase();
        const projects = await getProjects();
        if (!projects.length) {
            localStorage.setItem("creovaLocalImported", "1");
            return 0;
        }
        const result = await FlowApi.request("import-projects", {
            method: "POST",
            body: { projects }
        });
        localStorage.setItem("creovaLocalImported", "1");
        return result.imported || 0;
    } catch (error) {
        console.warn(error);
        return 0;
    }
}

document.addEventListener("DOMContentLoaded", async () => {
    document.querySelectorAll("[data-section]").forEach(link => {
        link.addEventListener("click", event => {
            event.preventDefault();
            showSection(link.dataset.section);
        });
    });
    document.querySelectorAll("[data-offer-tab]").forEach(button => {
        button.addEventListener("click", () => {
            state.offerTab = button.dataset.offerTab;
            state.offerId = 0;
            state.requestId = 0;
            renderOfertas();
        });
    });
    document.getElementById("newOfferBtn").addEventListener("click", () => openOfferForm(null));
    document.getElementById("newProjectBtn").addEventListener("click", () => openProjectForm(null));
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
    document.getElementById("offerForm").addEventListener("submit", async event => {
        event.preventDefault();
        const id = document.getElementById("offerId").value;
        try {
            await run("offer", {
                id: id ? Number(id) : 0,
                nombre: document.getElementById("offerName").value,
                portada: document.getElementById("offerCover").value,
                descripcionCorta: document.getElementById("offerShort").value,
                problema: document.getElementById("offerProblem").value,
                beneficios: document.getElementById("offerBenefits").value,
                alcance: document.getElementById("offerScope").value,
                entregables: document.getElementById("offerDeliverables").value,
                precio: document.getElementById("offerPrice").value,
                duracion: document.getElementById("offerDuration").value,
                modalidad: document.getElementById("offerMode").value,
                disponibilidad: document.getElementById("offerAvailability").value,
                estado: document.getElementById("offerStatus").value,
                archivos: parseFiles(document.getElementById("offerFiles").value)
            });
            bootstrap.Modal.getOrCreateInstance(document.getElementById("offerModal")).hide();
            state.section = "ofertas";
            paintSection();
        } catch (error) {
            showAlert(error.message);
        }
    });
    document.getElementById("projectForm").addEventListener("submit", async event => {
        event.preventDefault();
        const id = document.getElementById("projectId").value;
        try {
            await run("project", {
                id: id ? Number(id) : 0,
                nombre: document.getElementById("projectName").value,
                categoria: document.getElementById("projectCategory").value,
                descripcion: document.getElementById("projectDescription").value,
                ofertaId: Number(document.getElementById("projectOffer").value || 0),
                usuarioPrincipalId: Number(document.getElementById("projectUser").value || 0),
                estado: document.getElementById("projectStatus").value
            });
            bootstrap.Modal.getOrCreateInstance(document.getElementById("projectModal")).hide();
            state.section = "proyectos";
            paintSection();
        } catch (error) {
            showAlert(error.message);
        }
    });
    document.getElementById("settingsForm").addEventListener("submit", async event => {
        event.preventDefault();
        const correos = {};
        document.querySelectorAll("#settingsForm input[name]").forEach(input => {
            correos[input.name] = input.checked;
        });
        try {
            await run("settings", { correos });
            state.section = "configuracion";
            paintSection();
        } catch (error) {
            showAlert(error.message);
        }
    });

    try {
        const session = await FlowApi.request("session");

        if (!session.user) {
            window.location.href = "inicio.php";
            return;
        }

        if (session.user.rol !== "admin") {
            window.location.href = "portal.php";
            return;
        }

        applyQuery();
        await refresh();
        const imported = await importLocalProjectsOnce();
        if (imported > 0) {
            showAlert("Se importaron " + imported + " proyectos de este navegador.", true);
            await refresh();
        }
    } catch (error) {
        showAlert(error.message);
    }
});
