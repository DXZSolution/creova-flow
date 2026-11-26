function encodePayload(value) {
    const json = JSON.stringify(value);

    return btoa(unescape(encodeURIComponent(json)));
}

async function readJsonResponse(response) {
    const raw = await response.text();

    try {
        return JSON.parse(raw);
    } catch (error) {
        throw new Error("El servidor contestó con una página HTML en lugar de la notificación. Sube de nuevo aviso.php y subscribe.php.");
    }
}

function hideSplash() {
    const splash = document.getElementById("splash");

    if (!splash) {
        return;
    }

    window.setTimeout(() => {
        splash.classList.add("splash-hide");
        window.setTimeout(() => splash.remove(), 450);
    }, 900);
}

window.addEventListener("load", hideSplash);

if ("serviceWorker" in navigator) {

    window.addEventListener("load", () => {

        navigator.serviceWorker.register("./service-worker.js")
            .then(registration => {
                console.log("Service Worker registrado:", registration);
            })
            .catch(error => {
                console.error("Error:", error);
            });

    });

}

document.addEventListener("DOMContentLoaded", async () => {

    await openDatabase();


async function sendPushNotification(title, body) {

    try {

        const response = await fetch("./push/aviso.php", {

            method: "POST",

            headers: {
                "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8"
            },

            body: new URLSearchParams({
                title: title,
                body: body,
                url: "./"
            })

        });

        const result = await readJsonResponse(response);

        if (!response.ok || !result.success) {
            console.error("Error al enviar notificación:", result);
            return;
        }

        console.log("Notificación enviada correctamente:", result);

    } catch (error) {

        console.error("No se pudo enviar la notificación:", error);

    }
}




const VAPID_PUBLIC_KEY = "BOpxmyj8Iu3z1kjB1h0FUj32jvOwAjrSeCurj8FCTzjZ-t2tQ7XZoilHSvmf0B8_ZJPp0EUg4AR4FUmkXq6K9WA";

function urlBase64ToUint8Array(base64String) {

    const padding = "=".repeat(
        (4 - base64String.length % 4) % 4
    );

    const base64 = (base64String + padding)
        .replace(/-/g, "+")
        .replace(/_/g, "/");

    const rawData = window.atob(base64);

    return Uint8Array.from(
        [...rawData].map(char => char.charCodeAt(0))
    );
}



const pushButton = document.getElementById("notificationBtn");

console.log("Botón de notificaciones encontrado:", pushButton);
if (pushButton) {

    pushButton.addEventListener("click", async () => {
        console.log("Se hizo clic en activar notificaciones");
        try {

            if (!("PushManager" in window)) {
                alert("Tu navegador no soporta notificaciones Push.");
                return;
            }


            const permission = await Notification.requestPermission();

            if (permission !== "granted") {
                alert("No se concedió el permiso para notificaciones.");
                return;
            }


            const registration = await navigator.serviceWorker.ready;


            let subscription =
                await registration.pushManager.getSubscription();


            if (!subscription) {

                subscription =
                    await registration.pushManager.subscribe({

                        userVisibleOnly: true,

                        applicationServerKey:
                            urlBase64ToUint8Array(VAPID_PUBLIC_KEY)

                    });
            }


            const response = await fetch("./push/subscribe.php", {

                method: "POST",

                headers: {
                    "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8"
                },

                body: new URLSearchParams({
                    payload: encodePayload(subscription)
                })

            });

            const result = await readJsonResponse(response);

            if (!response.ok) {
                throw new Error(result.message);
            }

            alert("¡Notificaciones activadas correctamente!");

            console.log("Suscripción registrada:", result);

        } catch (error) {

            console.error("Error al activar notificaciones:", error);

            alert("No se pudieron activar las notificaciones: " + error.message);

        }

    });

}


    const modalElement = document.getElementById("projectModal");
    const modal = new bootstrap.Modal(modalElement);

    const form = document.getElementById("projectForm");


    document.querySelectorAll("[data-section]").forEach(link => {

        link.addEventListener("click", (event) => {
            event.preventDefault();

            const section = link.dataset.section;

            document.querySelectorAll(".content-section")
                .forEach(element => element.classList.add("d-none"));

            document.getElementById(section)
                .classList.remove("d-none");

            document.querySelectorAll("[data-section]")
                .forEach(element => element.classList.remove("active"));

            link.classList.add("active");
        });
    });

    document.getElementById("newProjectBtn")
        .addEventListener("click", () => {

            form.reset();
            delete form.dataset.editingId;
            delete form.dataset.originalStatus;

            modal.show();
        });


    form.addEventListener("submit", async (event) => {

        event.preventDefault();

        const project = {
            nombre: document.getElementById("projectName").value,
            categoria: document.getElementById("projectCategory").value,
            descripcion: document.getElementById("projectDescription").value,
            responsable: document.getElementById("projectResponsible").value,
            estado: document.getElementById("projectStatus").value,
            fecha: new Date().toLocaleDateString()
        };


try {

    const isEditing = Boolean(form.dataset.editingId);
    const previousStatus = form.dataset.originalStatus;

    if (isEditing) {

        project.id = Number(form.dataset.editingId);

        await updateProject(project);


        if (
            previousStatus !== "Terminado" &&
            project.estado === "Terminado"
        ) {

            await sendPushNotification(
                "¡Proyecto terminado!",
                "El proyecto " + project.nombre + " ha sido marcado como terminado."
            );

        }

    } else {

        await saveProject(project);


        await sendPushNotification(
            "¡Nuevo proyecto!",
            "Se ha creado el proyecto " + project.nombre + " correctamente."
        );

    }

    form.reset();

    delete form.dataset.editingId;
    delete form.dataset.originalStatus;

    modal.hide();

    await renderProjects();

} catch (error) {

    console.error(error);

    alert("No se pudo guardar el proyecto.");

}

    });


async function renderProjects() {

    const projects = await getProjects();

    document.getElementById("totalProjects").textContent =
        projects.length;

    document.getElementById("activeProjects").textContent =
        projects.filter(p => p.estado === "En proceso").length;

    document.getElementById("finishedProjects").textContent =
        projects.filter(p => p.estado === "Terminado").length;


    const recent = document.getElementById("projectList");
    const all = document.getElementById("allProjects");

    recent.innerHTML = "";
    all.innerHTML = "";


    projects.forEach(project => {

        function createProjectCard() {

            const card = document.createElement("div");
            card.className = "col-md-6 col-lg-4 mb-3";

            const content = document.createElement("div");
            content.className = "project-card";

            const title = document.createElement("h5");
            title.textContent = project.nombre;

            const category = document.createElement("p");
            category.textContent =
                "Categoría: " + project.categoria;

            const status = document.createElement("p");
            status.textContent =
                "Estado: " + project.estado;


            const edit = document.createElement("button");
            edit.className = "btn btn-warning btn-sm me-2";
            edit.textContent = "Editar";

            edit.addEventListener("click", () => {
                editProject(project);
            });


            const remove = document.createElement("button");
            remove.className = "btn btn-danger btn-sm";
            remove.textContent = "Eliminar";

            remove.addEventListener("click", async () => {

                if (confirm("¿Deseas eliminar este proyecto?")) {

                    await deleteProject(project.id);
                    await renderProjects();

                }
            });

            content.append(
                title,
                category,
                status,
                edit,
                remove
            );

            card.appendChild(content);

            return card;
        }

        recent.appendChild(createProjectCard());
        all.appendChild(createProjectCard());

    });
}



    function editProject(project) {

        document.getElementById("projectName").value = project.nombre;
        document.getElementById("projectCategory").value = project.categoria;
        document.getElementById("projectDescription").value = project.descripcion;
        document.getElementById("projectResponsible").value = project.responsable;
        document.getElementById("projectStatus").value = project.estado;

        form.dataset.editingId = project.id;
        form.dataset.originalStatus = project.estado;

        modal.show();
    }

    await renderProjects();

});