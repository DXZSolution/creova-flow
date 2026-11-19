
<?php

header('Content-Type: application/json; charset=utf-8');

function response($status, $message) {
    http_response_code($status);
    echo json_encode(['message' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(405, 'Metodo no permitido');
}


$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data) && !empty($_POST['payload'])) {
    $decoded = base64_decode($_POST['payload'], true);

    if ($decoded !== false) {
        $data = json_decode($decoded, true);
    }
}

if (
    !is_array($data) ||
    empty($data['endpoint']) ||
    empty($data['keys']['p256dh']) ||
    empty($data['keys']['auth'])
) {
    response(400, 'Suscripcion incompleta');
}


$endpoint = filter_var($data['endpoint'], FILTER_VALIDATE_URL);

if (!$endpoint || parse_url($endpoint, PHP_URL_SCHEME) !== 'https') {
    response(400, 'Endpoint no valido');
}


$config = require __DIR__ . '/config.php';

$file = $config['subscriptions_file'];


$subscriptions = [];

if (file_exists($file)) {
    $content = file_get_contents($file);

    if ($content !== '') {
        $subscriptions = json_decode($content, true);

        if (!is_array($subscriptions)) {
            response(500, 'Error al leer las suscripciones');
        }
    }
}


$subscriptions = array_values(array_filter(
    $subscriptions,
    function ($item) use ($endpoint) {
        return ($item['endpoint'] ?? '') !== $endpoint;
    }
));


$subscriptions[] = $data;

$result = file_put_contents(
    $file,
    json_encode($subscriptions, JSON_PRETTY_PRINT),
    LOCK_EX
);

if ($result === false) {
    response(500, 'No se pudo guardar la suscripcion');
}

response(201, 'Suscripcion registrada correctamente');
