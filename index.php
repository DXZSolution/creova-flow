<?php

require __DIR__ . '/lib/flow.php';
flow_boot();

try {
    $current = flow_current_user();
} catch (Throwable $error) {
    http_response_code(500);
    echo 'No se pudo abrir el almacén de Creova Flow.';
    exit;
}

if (!$current) {
    header('Location: acceso.php');
    exit;
}

if ($current['rol'] !== 'admin') {
    header('Location: portal.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Creova Flow</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Google+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="manifest" href="manifest.json">
    <link rel="icon" href="icons/icon-192.png">
    <link rel="apple-touch-icon" href="icons/icon-192.png">
    <meta name="theme-color" content="#0b0b0b">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Creova Flow">
</head>
<body>
<div id="splash" class="splash" role="status" aria-live="polite">
    <img src="icons/icon-192.png" alt="">
    <h1>Creova Flow</h1>
    <p>Ofertas, proyectos y seguimiento</p>
</div>

<div class="app-container">
    <aside class="sidebar">
        <h3>Creova <span>Flow.</span></h3>
        <a class="sidebar-site" href="inicio.php">Ver sitio público</a>
        <p class="sidebar-label">Administración</p>
        <nav>
            <a href="#" class="active" data-section="inicio">Inicio</a>
            <a href="#" data-section="proyectos">Proyectos</a>
            <a href="#" data-section="ofertas">Ofertas</a>
            <a href="#" data-section="biblioteca">Biblioteca</a>
            <a href="#" data-section="usuarios">Usuarios</a>
            <a href="#" data-section="notificaciones">Notificaciones <span id="unreadBadge" class="nav-badge d-none">0</span></a>
            <a href="#" data-section="configuracion">Configuración</a>
        </nav>
        <div class="sidebar-bottom">
            <button id="notificationBtn" class="btn btn-outline-light" type="button">Activar notificaciones</button>
        </div>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <div>
                <h4>Panel administrativo</h4>
                <span>Ofertas, solicitudes y proyectos</span>
            </div>
            <div class="topbar-user">
                <strong id="currentUserName"><?= flow_h($current['nombre']) ?></strong>
                <button id="logoutBtn" class="btn btn-outline-light" type="button">Salir</button>
            </div>
        </header>

        <div id="appAlert" class="app-alert d-none" role="status"></div>

        <section id="inicio" class="content-section">
            <span class="panel-label">Resumen general</span>
            <h2>Operación de Creova Flow.</h2>
            <p>Publica ofertas, atiende solicitudes y sigue cada proyecto con la persona que lo pidió.</p>
            <div class="row g-3 mt-4" id="homeStats"></div>
            <div class="row g-3 mt-1" id="homeStatsSecondary"></div>
            <div class="mt-4 d-flex flex-wrap gap-2">
                <button class="btn btn-primary" id="newProjectBtn" type="button">+ Nuevo proyecto</button>
                <button class="btn btn-outline-light" id="newOfferBtn" type="button">+ Nueva oferta</button>
            </div>
            <div class="mt-5">
                <span class="panel-label">Actividad</span>
                <h4 class="mb-4">Proyectos recientes</h4>
                <div id="projectList" class="row g-3"></div>
            </div>
        </section>

        <section id="proyectos" class="content-section d-none">
            <span class="panel-label">Administración</span>
            <h2>Proyectos</h2>
            <p>Crea proyectos internos o revisa los que nacen de una solicitud o una suscripción.</p>
            <div class="mt-4" id="allProjects"></div>
        </section>

        <section id="ofertas" class="content-section d-none">
            <span class="panel-label">Catálogo</span>
            <h2>Ofertas</h2>
            <p>La oferta publicada es lo que la persona ve, solicita o suscribe.</p>
            <div class="section-tabs">
                <button type="button" class="btn btn-primary" data-offer-tab="catalogo">Catálogo</button>
                <button type="button" class="btn btn-outline-light" data-offer-tab="solicitudes">Solicitudes</button>
            </div>
            <div id="offerCatalog"></div>
            <div id="offerRequests" class="d-none"></div>
        </section>

        <section id="biblioteca" class="content-section d-none">
            <span class="panel-label">Relaciones</span>
            <h2>Biblioteca</h2>
            <p>Cada proyecto muestra su oferta, la persona principal y quién está suscrito.</p>
            <div class="mt-4" id="libraryList"></div>
        </section>

        <section id="usuarios" class="content-section d-none">
            <span class="panel-label">Cuentas</span>
            <h2>Usuarios</h2>
            <p>Consulta quién se registró y qué ofertas pidió o suscribió.</p>
            <div class="mt-4" id="userList"></div>
        </section>

        <section id="notificaciones" class="content-section d-none">
            <span class="panel-label">Actividad</span>
            <h2>Notificaciones</h2>
            <p>El aviso interno abre la solicitud o el proyecto relacionado.</p>
            <button id="markReadBtn" class="btn btn-outline-light mb-4" type="button">Marcar todas como leídas</button>
            <div id="notificationList"></div>
        </section>

        <section id="configuracion" class="content-section d-none">
            <span class="panel-label">Correo</span>
            <h2>Configuración</h2>
            <p>Puedes apagar un tipo de correo sin borrar el aviso dentro de la plataforma. Si el servidor no tiene salida de correo, el intento queda en el registro.</p>
            <form id="settingsForm" class="detail-panel mb-4">
                <label class="form-check mb-2"><input class="form-check-input" type="checkbox" name="bienvenida"> Correo de bienvenida</label>
                <label class="form-check mb-2"><input class="form-check-input" type="checkbox" name="nueva_oferta"> Aviso de nueva oferta</label>
                <label class="form-check mb-2"><input class="form-check-input" type="checkbox" name="solicitud"> Solicitudes de servicio</label>
                <label class="form-check mb-2"><input class="form-check-input" type="checkbox" name="suscripcion"> Suscripciones</label>
                <label class="form-check mb-2"><input class="form-check-input" type="checkbox" name="cambio_estado"> Cambios de estado y comentarios</label>
                <label class="form-check mb-3"><input class="form-check-input" type="checkbox" name="nuevo_usuario"> Nuevo usuario registrado</label>
                <button class="btn btn-primary" type="submit">Guardar correos</button>
            </form>
            <h4 class="mb-3">Correos registrados</h4>
            <div id="mailLog"></div>
        </section>
    </main>
</div>

<div class="modal fade" id="offerModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Oferta</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <form id="offerForm">
                <div class="modal-body">
                    <input type="hidden" id="offerId">
                    <label for="offerName">Nombre de la oferta</label>
                    <input id="offerName" class="form-control mb-3" required>
                    <label for="offerCover">Imagen o portada (enlace)</label>
                    <input id="offerCover" class="form-control mb-3" placeholder="https://">
                    <label for="offerShort">Descripción corta</label>
                    <textarea id="offerShort" class="form-control mb-3" rows="2" required></textarea>
                    <label for="offerProblem">Problema que resuelve</label>
                    <textarea id="offerProblem" class="form-control mb-3" rows="2"></textarea>
                    <label for="offerBenefits">Beneficios</label>
                    <textarea id="offerBenefits" class="form-control mb-3" rows="3"></textarea>
                    <label for="offerScope">Alcance</label>
                    <textarea id="offerScope" class="form-control mb-3" rows="2"></textarea>
                    <label for="offerDeliverables">Entregables</label>
                    <textarea id="offerDeliverables" class="form-control mb-3" rows="2"></textarea>
                    <div class="row">
                        <div class="col-md-6">
                            <label for="offerPrice">Precio o precio desde</label>
                            <input id="offerPrice" class="form-control mb-3">
                        </div>
                        <div class="col-md-6">
                            <label for="offerDuration">Duración estimada</label>
                            <input id="offerDuration" class="form-control mb-3">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <label for="offerMode">Modalidad</label>
                            <select id="offerMode" class="form-select mb-3">
                                <option value="solicitar">Solicitar servicio</option>
                                <option value="suscribirse">Suscribirse</option>
                                <option value="iniciar">Iniciar proyecto</option>
                                <option value="informacion">Solicitar información</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="offerStatus">Estado de publicación</label>
                            <select id="offerStatus" class="form-select mb-3">
                                <option value="borrador">Borrador</option>
                                <option value="publicada">Publicada</option>
                                <option value="pausada">Pausada</option>
                                <option value="no_disponible">No disponible</option>
                                <option value="archivada">Archivada</option>
                            </select>
                        </div>
                    </div>
                    <label for="offerAvailability">Disponibilidad</label>
                    <input id="offerAvailability" class="form-control mb-3" placeholder="Cupo abierto">
                    <label for="offerFiles">Archivos de apoyo (nombre | https://enlace)</label>
                    <textarea id="offerFiles" class="form-control" rows="3" placeholder="Brief | https://ejemplo.com/brief.pdf"></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar oferta</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="projectModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Proyecto</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <form id="projectForm">
                <div class="modal-body">
                    <input type="hidden" id="projectId">
                    <label for="projectName">Nombre del proyecto</label>
                    <input id="projectName" class="form-control mb-3" required>
                    <label for="projectCategory">Categoría</label>
                    <select id="projectCategory" class="form-select mb-3">
                        <option value="">Sin categoría</option>
                        <option value="Video">Video</option>
                        <option value="Audio">Audio</option>
                        <option value="Imagen">Imagen</option>
                        <option value="Animación">Animación</option>
                        <option value="3D">Modelado 3D</option>
                    </select>
                    <label for="projectOffer">Oferta relacionada</label>
                    <select id="projectOffer" class="form-select mb-3"></select>
                    <label for="projectUser">Usuario principal</label>
                    <select id="projectUser" class="form-select mb-3"></select>
                    <label for="projectDescription">Descripción</label>
                    <textarea id="projectDescription" class="form-control mb-3" rows="3"></textarea>
                    <label for="projectStatus">Estado</label>
                    <select id="projectStatus" class="form-select">
                        <option value="pendiente">Pendiente de inicio</option>
                        <option value="en_proceso">En proceso</option>
                        <option value="en_espera">En espera</option>
                        <option value="completado">Completado</option>
                        <option value="pausado">Pausado</option>
                        <option value="cancelado">Cancelado</option>
                    </select>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar proyecto</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="assets/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/db.js"></script>
<script src="assets/js/flow.js"></script>
<script src="assets/js/admin.js"></script>
</body>
</html>
