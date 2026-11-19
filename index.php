
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Creova Flow</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link href="https://fonts.googleapis.com/css2?family=Google+Sans:wght@400;500;600;700&display=swap"
        rel="stylesheet">

    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/style.css">

    <link rel="manifest" href="manifest.json">
    <link rel="icon" href="icons/icon-192.png">
    <link rel="apple-touch-icon" href="icons/icon-192.png">

    <meta name="theme-color" content="#0b0b0b">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Creova Flow">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">

</head>

<body>

<div id="splash" class="splash" role="status" aria-live="polite">

    <img src="icons/icon-192.png" alt="">

    <h1>Creova Flow</h1>

    <p>Tu espacio de producción creativa</p>

</div>


<div class="app-container">

    <aside class="sidebar">

        <h3>
            Creova <span>Flow.</span>
        </h3>

        <p class="sidebar-label">
            Espacio de trabajo
        </p>

        <nav>

            <a href="#" class="active" data-section="inicio">
                Inicio
            </a>

            <a href="#" data-section="proyectos">
                Proyectos
            </a>

            <a href="#" data-section="biblioteca">
                Biblioteca
            </a>

            <a href="#" data-section="prompts">
                Prompts IA
            </a>

        </nav>

        <div class="sidebar-bottom">

            <button id="notificationBtn"
                class="btn btn-outline-light">

                Activar notificaciones

            </button>

        </div>

    </aside>

    <main class="main-content">

        <header class="topbar">

            <h4>Panel de producción</h4>

            <span>Bienvenido a tu espacio creativo</span>

        </header>


        <section id="inicio" class="content-section">

            <span class="panel-label">
                Resumen general
            </span>

            <h2>
                Tu espacio creativo.
            </h2>

            <p>
                Consulta el estado de tus proyectos
                y organiza tu trabajo desde un solo lugar.
            </p>

            <div class="row g-3 mt-4">

                <div class="col-md-4">

                    <div class="stat-card">

                        <h5>Proyectos totales</h5>

                        <h2 id="totalProjects">0</h2>

                    </div>

                </div>


                <div class="col-md-4">

                    <div class="stat-card">

                        <h5>En proceso</h5>

                        <h2 id="activeProjects">0</h2>

                    </div>

                </div>


                <div class="col-md-4">

                    <div class="stat-card">

                        <h5>Terminados</h5>

                        <h2 id="finishedProjects">0</h2>

                    </div>

                </div>

            </div>

            <div class="mt-4">

                <button class="btn btn-primary"
                    id="newProjectBtn">

                    + Nuevo proyecto

                </button>

            </div>

            <div class="mt-5">

                <span class="panel-label">
                    Actividad
                </span>

                <h4 class="mb-4">
                    Proyectos recientes
                </h4>

                <div id="projectList" class="row g-3"></div>

            </div>

        </section>

        <section id="proyectos" class="content-section d-none">

            <span class="panel-label">
                Administración
            </span>

            <h2>Mis proyectos</h2>

            <p>
                Consulta, edita y administra tus proyectos creativos.
            </p>

            <div class="mt-4" id="allProjects"></div>

        </section>

        <section id="biblioteca" class="content-section d-none">

            <span class="panel-label">
                Recursos creativos
            </span>

            <h2>Biblioteca multimedia</h2>

            <p>
                Organiza tus archivos, imágenes, videos
                y referencias creativas.
            </p>

            <div class="empty-state mt-4">

                <h5>Tu espacio multimedia</h5>

                <p>
                    Aquí podrás organizar tus recursos creativos.
                </p>

            </div>

        </section>


        <section id="prompts" class="content-section d-none">

            <span class="panel-label">
                Herramientas creativas
            </span>

            <h2>Prompts IA</h2>

            <p>
                Guarda y organiza instrucciones para tus
                herramientas de inteligencia artificial.
            </p>

            <div class="empty-state mt-4">

                <h5>Tu colección de prompts</h5>

                <p>
                    Aquí podrás organizar tus instrucciones creativas.
                </p>

            </div>

        </section>


    </main>

</div>


<div class="modal fade" id="projectModal" tabindex="-1">

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content">

            <div class="modal-header">

                <h5 class="modal-title">
                    Registrar proyecto
                </h5>

                <button type="button"
                    class="btn-close btn-close-white"
                    data-bs-dismiss="modal"
                    aria-label="Cerrar">
                </button>

            </div>


            <form id="projectForm">

                <div class="modal-body">

                    <label for="projectName">
                        Nombre del proyecto
                    </label>

                    <input type="text"
                        id="projectName"
                        class="form-control mb-3"
                        placeholder="Ej. Video publicitario"
                        required>


                    <label for="projectCategory">
                        Categoría
                    </label>

                    <select id="projectCategory"
                        class="form-select mb-3">

                        <option value="Video">Video</option>
                        <option value="Audio">Audio</option>
                        <option value="Imagen">Imagen</option>
                        <option value="Animación">Animación</option>
                        <option value="3D">Modelado 3D</option>

                    </select>


                    <label for="projectDescription">
                        Descripción
                    </label>

                    <textarea id="projectDescription"
                        class="form-control mb-3"
                        rows="3"
                        placeholder="Describe el proyecto..."
                        required></textarea>


                    <label for="projectResponsible">
                        Responsable
                    </label>

                    <input type="text"
                        id="projectResponsible"
                        class="form-control mb-3"
                        placeholder="Nombre del responsable"
                        required>


                    <label for="projectStatus">
                        Estado
                    </label>

                    <select id="projectStatus"
                        class="form-select">

                        <option value="Pendiente">
                            Pendiente
                        </option>

                        <option value="En proceso">
                            En proceso
                        </option>

                        <option value="Terminado">
                            Terminado
                        </option>

                    </select>

                </div>


                <div class="modal-footer">

                    <button type="button"
                        class="btn btn-outline-light"
                        data-bs-dismiss="modal">

                        Cancelar

                    </button>

                    <button type="submit"
                        class="btn btn-primary">

                        Guardar proyecto

                    </button>

                </div>

            </form>

        </div>

    </div>

</div>


<script src="assets/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/db.js"></script>
<script src="assets/js/app.js"></script>

</body>
</html>
