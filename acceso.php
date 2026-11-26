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

header('Content-Type: text/html; charset=utf-8');
readfile(__DIR__ . '/shell-acceso.html');
