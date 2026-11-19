<?php

require dirname(__DIR__) . '/lib/flow.php';

flow_boot();

set_exception_handler(function ($error) {
    $status = $error instanceof FlowError ? $error->status : 500;
    $message = $error instanceof FlowError
        ? $error->getMessage()
        : 'No se pudo registrar el dispositivo.';

    if (!($error instanceof FlowError)) {
        error_log($error->getMessage());
    }

    flow_json([
        'success' => false,
        'message' => $message,
    ], $status);
});

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    throw new FlowError('Método no permitido.', 405);
}

flow_check_token();

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data) && !empty($_POST['payload'])) {
    $decoded = base64_decode((string) $_POST['payload'], true);

    if ($decoded !== false) {
        $data = json_decode($decoded, true);
    }
}

if (!is_array($data)) {
    throw new FlowError('Suscripción incompleta.', 400);
}

flow_transaction(function ($db) use ($data) {
    $user = flow_require($db);
    flow_save_push_device($db, (int) $user['id'], $data);
});

flow_ok(['message' => 'Este dispositivo recibirá avisos junto con el correo.']);
