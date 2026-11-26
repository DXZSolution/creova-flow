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
    header('Location: inicio.php');
    exit;
}

if ($current['rol'] !== 'admin') {
    header('Location: portal.php');
    exit;
}

header('Content-Type: text/html; charset=utf-8');
readfile(__DIR__ . '/shell-admin.html');
