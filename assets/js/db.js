const DB_NAME = "CreativeAI_DB";
const DB_VERSION = 1;

let db;


function openDatabase() {
    return new Promise((resolve, reject) => {

        const request = indexedDB.open(DB_NAME, DB_VERSION);

        request.onupgradeneeded = (event) => {
            const database = event.target.result;

            if (!database.objectStoreNames.contains("proyectos")) {
                database.createObjectStore("proyectos", {
                    keyPath: "id",
                    autoIncrement: true
                });
            }

            if (!database.objectStoreNames.contains("archivos")) {
                database.createObjectStore("archivos", {
                    keyPath: "id",
                    autoIncrement: true
                });
            }

            if (!database.objectStoreNames.contains("prompts")) {
                database.createObjectStore("prompts", {
                    keyPath: "id",
                    autoIncrement: true
                });
            }

            if (!database.objectStoreNames.contains("pendientes")) {
                database.createObjectStore("pendientes", {
                    keyPath: "id",
                    autoIncrement: true
                });
            }
        };

        request.onsuccess = () => {
            db = request.result;
            resolve(db);
        };

        request.onerror = () => reject(request.error);
    });
}

// Registrar proyecto
function saveProject(project) {
    return new Promise((resolve, reject) => {
        const transaction = db.transaction("proyectos", "readwrite");

        const store = transaction.objectStore("proyectos");

        const request = store.add(project);

        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

// Consultar proyectos
function getProjects() {
    return new Promise((resolve, reject) => {
        const transaction = db.transaction("proyectos", "readonly");

        const request = transaction.objectStore("proyectos").getAll();

        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

// Actualizar proyecto
function updateProject(project) {
    return new Promise((resolve, reject) => {
        const transaction = db.transaction("proyectos", "readwrite");

        const request = transaction.objectStore("proyectos").put(project);

        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

// Eliminar proyecto
function deleteProject(id) {
    return new Promise((resolve, reject) => {
        const transaction = db.transaction("proyectos", "readwrite");

        const request = transaction.objectStore("proyectos").delete(id);

        request.onsuccess = () => resolve();
        request.onerror = () => reject(request.error);
    });
}