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

if ($current) {
    header('Location: ' . ($current['rol'] === 'admin' ? 'index.php' : 'portal.php?seccion=ofertas'));
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Entrar · Creova Flow</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Google+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="manifest" href="manifest.json">
    <link rel="icon" href="icons/icon-192.png">
    <meta name="theme-color" content="#0b0b0b">
</head>
<body>
<main class="auth-screen">
    <section class="auth-card">
        <p class="panel-label">Creova Flow</p>
        <h1>Entra o crea tu cuenta.</h1>
        <p class="auth-copy">El administrador publica ofertas. Tú consultas servicios, solicitas un proyecto o te suscribes.</p>

        <div class="section-tabs">
            <button type="button" class="btn btn-primary" data-auth="entrar">Entrar</button>
            <button type="button" class="btn btn-outline-light" data-auth="registro">Crear cuenta</button>
        </div>

        <div id="authAlert" class="app-alert d-none" role="status"></div>

        <form id="loginPanel" class="auth-form" method="post" action="acceso.php">
            <label for="loginEmail">Correo</label>
            <input id="loginEmail" class="form-control mb-3" type="email" autocomplete="username" required>

            <label for="loginPassword">Contraseña</label>
            <input id="loginPassword" class="form-control mb-3" type="password" autocomplete="current-password" required>

            <button class="btn btn-primary w-100" type="submit">Entrar</button>
        </form>

        <form id="registerPanel" class="auth-form d-none" method="post" action="acceso.php">
            <label for="registerName">Nombre</label>
            <input id="registerName" class="form-control mb-3" type="text" autocomplete="name" required>

            <label for="registerEmail">Correo</label>
            <input id="registerEmail" class="form-control mb-3" type="email" autocomplete="email" required>

            <label for="registerPassword">Contraseña</label>
            <input id="registerPassword" class="form-control mb-3" type="password" autocomplete="new-password" minlength="8" required>

            <label for="registerPassword2">Confirmar contraseña</label>
            <input id="registerPassword2" class="form-control mb-3" type="password" autocomplete="new-password" minlength="8" required>

            <label class="form-check mb-3">
                <input id="registerTerms" class="form-check-input" type="checkbox" required>
                <span>Acepto los términos de uso de Creova Flow y el aviso de privacidad.</span>
            </label>

            <button class="btn btn-primary w-100" type="submit">Crear cuenta</button>
        </form>

        <p class="demo-note">Cuenta de demostración del administrador: admin@creovaflow.local · CreovaAdmin2026. Cámbiala antes de usar el sistema con clientes reales.</p>
        <a class="auth-back" href="inicio.php">Volver al sitio</a>
    </section>
</main>

<script src="assets/js/flow.js"></script>
<script src="assets/js/acceso.js"></script>
</body>
</html>
