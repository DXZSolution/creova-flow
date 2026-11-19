<?php

class FlowError extends Exception
{
    public $status;

    public function __construct($message, $status = 400)
    {
        parent::__construct($message);
        $this->status = (int) $status;
    }
}

require_once __DIR__ . '/db.php';

function flow_base_path()
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/');
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');

    if (substr($dir, -4) === '/api') {
        $dir = substr($dir, 0, -4);
    }

    $dir = rtrim(str_replace('\\', '/', $dir), '/');

    if ($dir === '' || $dir === '/' || $dir === '.') {
        return '';
    }

    return $dir;
}

function flow_base_url()
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    return $scheme . '://' . $host . flow_base_path();
}

function flow_url($path)
{
    return rtrim(flow_base_url(), '/') . '/' . ltrim($path, '/');
}

function flow_boot()
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $path = flow_base_path();
    session_name('creova_flow');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => ($path === '' ? '/' : $path),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function flow_token()
{
    flow_boot();

    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }

    return $_SESSION['csrf'];
}

function flow_check_token()
{
    $header = (string) ($_SERVER['HTTP_X_CREOVA_TOKEN'] ?? '');
    $token = flow_token();

    if ($header === '' || !hash_equals($token, $header)) {
        throw new FlowError('La sesión del formulario expiró. Recarga la página.', 419);
    }
}

function flow_json($payload, $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function flow_ok($payload = [])
{
    $payload['success'] = true;
    $payload['token'] = flow_token();
    flow_json($payload);
}

function flow_body()
{
    $raw = file_get_contents('php://input');

    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);

    if (!is_array($data)) {
        throw new FlowError('El cuerpo de la petición no es JSON válido.', 400);
    }

    return $data;
}

function flow_h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function flow_cut($text, $max)
{
    $text = trim((string) $text);

    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $max);
    }

    return substr($text, 0, $max);
}

function flow_now()
{
    return gmdate('c');
}

function flow_transaction($callback)
{
    $db = flow_pdo();

    if ($db->inTransaction()) {
        $result = $callback($db);

        return is_array($result) && array_key_exists('out', $result) ? $result['out'] : $result;
    }

    $db->beginTransaction();

    try {
        $result = $callback($db);
        $db->commit();
        flow_flush_push($db);

        return is_array($result) && array_key_exists('out', $result) ? $result['out'] : $result;
    } catch (Throwable $error) {
        $GLOBALS['flow_push_queue'] = [];

        if ($db->inTransaction()) {
            $db->rollBack();
        }

        throw $error;
    }
}

function flow_session_id()
{
    flow_boot();

    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
}

function flow_current_user()
{
    $user = flow_user_by_id(flow_pdo(), flow_session_id());

    if (!$user) {
        unset($_SESSION['user_id']);

        return null;
    }

    return $user;
}

function flow_login_session($user)
{
    flow_boot();
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
}

function flow_require($db, $role = null)
{
    $user = flow_user_by_id($db, flow_session_id());

    if (!$user) {
        throw new FlowError('Inicia sesión para continuar.', 401);
    }

    if ($role !== null && $user['rol'] !== $role) {
        throw new FlowError('No tienes permiso para esta acción.', 403);
    }

    return $user;
}

function flow_public_user($user)
{
    return [
        'id' => (int) $user['id'],
        'nombre' => $user['nombre'],
        'correo' => $user['correo'],
        'rol' => $user['rol'],
        'creado' => $user['creado'],
    ];
}

function flow_modes()
{
    return ['solicitar', 'suscribirse', 'iniciar', 'informacion'];
}

function flow_offer_states()
{
    return ['borrador', 'publicada', 'pausada', 'no_disponible', 'archivada'];
}

function flow_request_states()
{
    return ['nueva', 'en_revision', 'informacion_adicional', 'cotizacion_enviada', 'aprobada', 'rechazada', 'cancelada'];
}

function flow_project_states()
{
    return ['pendiente', 'en_proceso', 'en_espera', 'completado', 'pausado', 'cancelado'];
}

function flow_request_open($estado)
{
    return in_array($estado, ['nueva', 'en_revision', 'informacion_adicional', 'cotizacion_enviada'], true);
}

function flow_label_request($estado)
{
    $map = [
        'nueva' => 'Nueva',
        'en_revision' => 'En revisión',
        'informacion_adicional' => 'Información adicional requerida',
        'cotizacion_enviada' => 'Cotización enviada',
        'aprobada' => 'Aprobada',
        'rechazada' => 'Rechazada',
        'cancelada' => 'Cancelada',
    ];

    return $map[$estado] ?? $estado;
}

function flow_label_project($estado)
{
    $map = [
        'pendiente' => 'Pendiente de inicio',
        'en_proceso' => 'En proceso',
        'en_espera' => 'En espera',
        'completado' => 'Completado',
        'pausado' => 'Pausado',
        'cancelado' => 'Cancelado',
    ];

    return $map[$estado] ?? $estado;
}

function flow_map_legacy_status($estado)
{
    $map = [
        'Pendiente' => 'pendiente',
        'En proceso' => 'en_proceso',
        'Terminado' => 'completado',
        'pendiente' => 'pendiente',
        'en_proceso' => 'en_proceso',
        'en_espera' => 'en_espera',
        'completado' => 'completado',
        'pausado' => 'pausado',
        'cancelado' => 'cancelado',
    ];

    return $map[$estado] ?? 'pendiente';
}

function flow_offer($db, $id)
{
    $offer = flow_get_offer($db, $id);

    if (!$offer) {
        throw new FlowError('No encontramos esa oferta.', 404);
    }

    return $offer;
}

function flow_offer_accepts($offer)
{
    return ($offer['estado'] ?? '') === 'publicada';
}

function flow_http_url($url, $label)
{
    $url = trim((string) $url);

    if ($url === '') {
        return '';
    }

    if (!preg_match('#^https?://#i', $url)) {
        throw new FlowError($label . ' debe usar un enlace http o https.', 422);
    }

    return flow_cut($url, 500);
}

function flow_files($input)
{
    $files = [];
    $items = $input['archivos'] ?? [];

    if (!is_array($items)) {
        return [];
    }

    foreach ($items as $file) {
        if (!is_array($file)) {
            continue;
        }

        $nombre = flow_cut($file['nombre'] ?? '', 120);

        if ($nombre === '') {
            continue;
        }

        $files[] = [
            'nombre' => $nombre,
            'url' => flow_http_url($file['url'] ?? '', 'Cada archivo de apoyo'),
        ];

        if (count($files) >= 8) {
            break;
        }
    }

    return $files;
}

function flow_save_offer($db, $admin, $input)
{
    $nombre = trim($input['nombre'] ?? '');
    $corta = trim($input['descripcionCorta'] ?? '');
    $modalidad = $input['modalidad'] ?? '';
    $estado = $input['estado'] ?? 'borrador';

    if ($nombre === '' || $corta === '') {
        throw new FlowError('La oferta necesita nombre y descripción corta.', 422);
    }

    if (!in_array($modalidad, flow_modes(), true)) {
        throw new FlowError('Selecciona una modalidad válida.', 422);
    }

    if (!in_array($estado, flow_offer_states(), true)) {
        throw new FlowError('Selecciona un estado de publicación válido.', 422);
    }

    $id = (int) ($input['id'] ?? 0);
    $previous = $id ? flow_get_offer($db, $id) : null;

    if ($id && !$previous) {
        throw new FlowError('No encontramos esa oferta.', 404);
    }

    $wasPublished = $previous && $previous['estado'] === 'publicada';
    $publicadaEn = $previous['publicadaEn'] ?? null;

    if ($estado === 'publicada' && !$wasPublished) {
        $publicadaEn = flow_now();
    }

    $payload = [
        'nombre' => flow_cut($nombre, 140),
        'portada' => flow_http_url($input['portada'] ?? '', 'La portada'),
        'descripcionCorta' => flow_cut($corta, 280),
        'problema' => flow_cut($input['problema'] ?? '', 2000),
        'beneficios' => flow_cut($input['beneficios'] ?? '', 2000),
        'alcance' => flow_cut($input['alcance'] ?? '', 2000),
        'entregables' => flow_cut($input['entregables'] ?? '', 2000),
        'precio' => flow_cut($input['precio'] ?? '', 80),
        'duracion' => flow_cut($input['duracion'] ?? '', 80),
        'modalidad' => $modalidad,
        'disponibilidad' => flow_cut($input['disponibilidad'] ?? '', 120),
        'estado' => $estado,
        'publicadaEn' => $publicadaEn,
        'creado' => $previous['creado'] ?? flow_now(),
        'creadoPor' => $previous['creadoPor'] ?? $admin['id'],
        'archivos' => flow_files($input),
    ];

    if ($previous) {
        flow_run(
            $db,
            'UPDATE ofertas SET
                nombre = ?, portada = ?, descripcion_corta = ?, problema = ?, beneficios = ?,
                alcance = ?, entregables = ?, precio = ?, duracion = ?, modalidad = ?,
                disponibilidad = ?, estado = ?, publicada_en = ?, actualizado = ?
             WHERE id = ?',
            [
                $payload['nombre'], $payload['portada'], $payload['descripcionCorta'], $payload['problema'],
                $payload['beneficios'], $payload['alcance'], $payload['entregables'], $payload['precio'],
                $payload['duracion'], $payload['modalidad'], $payload['disponibilidad'], $payload['estado'],
                $payload['publicadaEn'], flow_now(), $id,
            ]
        );
        flow_replace_files($db, 'oferta_archivos', 'oferta_id', $id, $payload['archivos']);
        $offerId = $id;
    } else {
        $offerId = flow_insert_offer($db, $payload, null);
    }

    if ($estado === 'publicada' && !$wasPublished) {
        flow_announce_offer($db, flow_offer($db, $offerId));
    }

    return $db;
}

function flow_announce_offer($db, $offer)
{
    $users = flow_all($db, 'SELECT * FROM usuarios WHERE rol = ?', ['user']);

    foreach ($users as $row) {
        $user = flow_user_from_row($row);

        if (flow_active_subscription($db, $user['id'], $offer['id'])) {
            continue;
        }

        $link = flow_url('portal.php?seccion=ofertas&id=' . $offer['id']);
        flow_notify($db, [
            'userId' => $user['id'],
            'tipo' => 'nueva_oferta',
            'titulo' => 'Nueva oferta disponible',
            'mensaje' => 'Nueva oferta disponible: ' . $offer['nombre'] . '. Ver detalles.',
            'enlace' => $link,
            'ofertaId' => $offer['id'],
            'clave' => 'nueva_oferta|' . $user['id'] . '|oferta|' . $offer['id'] . '|' . ($offer['publicadaEn'] ?? ''),
            'correo' => $user['correo'],
            'asunto' => 'Nueva oferta disponible en Creova Flow',
            'cuerpo' => "Se ha agregado una nueva oferta que puede interesarte. Consulta sus detalles y solicita el servicio desde tu cuenta.\n\nOferta: " . $offer['nombre'],
            'correoTipo' => 'nueva_oferta',
        ]);
    }
}

function flow_active_subscription($db, $userId, $offerId)
{
    $row = flow_one(
        $db,
        'SELECT * FROM suscripciones WHERE usuario_id = ? AND oferta_id = ? AND estado = ?',
        [(int) $userId, (int) $offerId, 'activa']
    );

    if (!$row) {
        return null;
    }

    return [
        'id' => (int) $row['id'],
        'userId' => (int) $row['usuario_id'],
        'ofertaId' => (int) $row['oferta_id'],
        'proyectoId' => (int) $row['proyecto_id'],
        'estado' => $row['estado'],
    ];
}

function flow_send_mail($db, $to, $subject, $body, $link, $tipo)
{
    $enabled = flow_mail_enabled($db, $tipo);
    $full = $body;

    if ($link) {
        $full .= "\n\nAbrir: " . $link;
    }

    $sent = false;
    $error = null;

    if (!$enabled) {
        $error = 'Correo desactivado en configuración.';
    } elseif (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $error = 'Correo de destino no válido.';
    } else {
        $from = '';
        $config = flow_config();

        if ($config && filter_var($config['mail_from'] ?? '', FILTER_VALIDATE_EMAIL)) {
            $from = $config['mail_from'];
        }

        $headers = "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n";

        if ($from !== '') {
            $headers .= 'From: Creova Flow <' . $from . ">\r\n";
            $headers .= 'Reply-To: ' . $from . "\r\n";
        }

        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $sent = $from !== ''
            ? @mail($to, $encodedSubject, $full, $headers, '-f' . $from)
            : @mail($to, $encodedSubject, $full, $headers);

        if (!$sent) {
            $error = 'El servidor no aceptó el envío. El mensaje quedó registrado.';
        }
    }

    flow_run(
        $db,
        'INSERT INTO correos (para, asunto, cuerpo, tipo, fecha, enviado, error) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$to, $subject, $full, $tipo, flow_now(), $sent ? 1 : 0, $error]
    );
}

function flow_notify($db, $spec)
{
    $exists = flow_one($db, 'SELECT id FROM notificaciones WHERE clave = ?', [$spec['clave']]);

    if ($exists) {
        return false;
    }

    flow_run(
        $db,
        'INSERT INTO notificaciones (
            usuario_id, tipo, titulo, mensaje, fecha, leida, enlace,
            oferta_id, proyecto_id, solicitud_id, relacion_usuario_id, clave
        ) VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?)',
        [
            (int) $spec['userId'],
            $spec['tipo'],
            $spec['titulo'],
            $spec['mensaje'],
            flow_now(),
            $spec['enlace'] ?? '',
            flow_nid($spec['ofertaId'] ?? 0),
            flow_nid($spec['proyectoId'] ?? 0),
            flow_nid($spec['solicitudId'] ?? 0),
            flow_nid($spec['relacionUserId'] ?? 0),
            $spec['clave'],
        ]
    );

    if (!empty($spec['correo'])) {
        flow_send_mail(
            $db,
            $spec['correo'],
            $spec['asunto'] ?? $spec['titulo'],
            $spec['cuerpo'] ?? $spec['mensaje'],
            $spec['enlace'] ?? '',
            $spec['correoTipo'] ?? 'cambio_estado'
        );
    }

    $GLOBALS['flow_push_queue'][] = [
        'userId' => (int) $spec['userId'],
        'title' => $spec['titulo'],
        'body' => $spec['mensaje'],
        'url' => $spec['enlace'] ?? '',
    ];

    return true;
}

function flow_save_push_device($db, $userId, $data)
{
    $endpoint = filter_var($data['endpoint'] ?? '', FILTER_VALIDATE_URL);

    if (!$endpoint || parse_url($endpoint, PHP_URL_SCHEME) !== 'https') {
        throw new FlowError('El dispositivo no envió una suscripción válida.', 422);
    }

    $p256dh = trim((string) ($data['keys']['p256dh'] ?? ''));
    $auth = trim((string) ($data['keys']['auth'] ?? ''));

    if ($p256dh === '' || $auth === '') {
        throw new FlowError('La suscripción del dispositivo está incompleta.', 422);
    }

    $existing = flow_one($db, 'SELECT id FROM dispositivo_push WHERE endpoint = ?', [$endpoint]);

    if ($existing) {
        flow_run(
            $db,
            'UPDATE dispositivo_push SET usuario_id = ?, p256dh = ?, auth = ? WHERE endpoint = ?',
            [(int) $userId, $p256dh, $auth, $endpoint]
        );

        return;
    }

    flow_run(
        $db,
        'INSERT INTO dispositivo_push (usuario_id, endpoint, p256dh, auth, creado) VALUES (?, ?, ?, ?, ?)',
        [(int) $userId, $endpoint, $p256dh, $auth, flow_now()]
    );
}

function flow_flush_push($db)
{
    $queue = $GLOBALS['flow_push_queue'] ?? [];
    $GLOBALS['flow_push_queue'] = [];

    foreach ($queue as $item) {
        try {
            flow_send_push($db, $item);
        } catch (Throwable $error) {
            error_log('Push Creova Flow: ' . $error->getMessage());
        }
    }
}

function flow_send_push($db, $item)
{
    $autoload = dirname(__DIR__) . '/push/vendor/autoload.php';

    if (!is_file($autoload) || PHP_VERSION_ID < 80200) {
        return;
    }

    $devices = flow_all(
        $db,
        'SELECT endpoint, p256dh, auth FROM dispositivo_push WHERE usuario_id = ?',
        [(int) $item['userId']]
    );

    if (!$devices) {
        return;
    }

    require_once $autoload;
    $config = require dirname(__DIR__) . '/push/config.php';
    $webPush = new Minishlink\WebPush\WebPush([
        'VAPID' => [
            'subject' => 'mailto:creovaflow@dxzsolution.com',
            'publicKey' => $config['vapid']['publicKey'],
            'privateKey' => $config['vapid']['privateKey'],
        ],
    ]);
    $payload = json_encode([
        'title' => $item['title'],
        'body' => $item['body'],
        'url' => $item['url'] !== '' ? $item['url'] : flow_url('inicio.php'),
    ]);

    foreach ($devices as $device) {
        $webPush->queueNotification(
            Minishlink\WebPush\Subscription::create([
                'endpoint' => $device['endpoint'],
                'keys' => [
                    'p256dh' => $device['p256dh'],
                    'auth' => $device['auth'],
                ],
            ]),
            $payload
        );
    }

    foreach ($webPush->flush() as $report) {
        if (!$report->isSuccess() && $report->isSubscriptionExpired()) {
            flow_run($db, 'DELETE FROM dispositivo_push WHERE endpoint = ?', [$report->getEndpoint()]);
        }
    }
}

function flow_register($db, $input)
{
    $nombre = trim($input['nombre'] ?? '');
    $correo = strtolower(trim($input['correo'] ?? ''));
    $password = (string) ($input['password'] ?? '');
    $confirm = array_key_exists('passwordConfirmacion', $input)
        ? (string) $input['passwordConfirmacion']
        : $password;

    if (strlen($nombre) < 2) {
        throw new FlowError('Escribe tu nombre.', 422);
    }

    if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        throw new FlowError('Escribe un correo válido.', 422);
    }

    if (strlen($password) < 8) {
        throw new FlowError('La contraseña debe tener al menos 8 caracteres.', 422);
    }

    if ($password !== $confirm) {
        throw new FlowError('Las contraseñas no coinciden.', 422);
    }

    if (empty($input['terminos'])) {
        throw new FlowError('Debes aceptar los términos para crear la cuenta.', 422);
    }

    if (flow_user_by_email($db, $correo)) {
        throw new FlowError('Ese correo ya está registrado.', 409);
    }

    $now = flow_now();
    flow_run(
        $db,
        'INSERT INTO usuarios (nombre, correo, password, rol, terminos_at, creado) VALUES (?, ?, ?, ?, ?, ?)',
        [flow_cut($nombre, 120), $correo, password_hash($password, PASSWORD_DEFAULT), 'user', $now, $now]
    );
    $user = flow_user_by_id($db, flow_id($db));
    $link = flow_url('portal.php?seccion=ofertas');

    flow_notify($db, [
        'userId' => $user['id'],
        'tipo' => 'bienvenida',
        'titulo' => 'Bienvenido a Creova Flow',
        'mensaje' => 'Bienvenido a Creova Flow. Explora las ofertas disponibles para comenzar.',
        'enlace' => $link,
        'clave' => 'bienvenida|user|' . $user['id'],
        'correo' => $user['correo'],
        'asunto' => 'Bienvenido a Creova Flow',
        'cuerpo' => 'Tu cuenta ha sido creada correctamente. Desde tu panel podrás consultar ofertas, solicitar servicios y revisar el estado de tus proyectos.',
        'correoTipo' => 'bienvenida',
    ]);

    foreach (flow_admins($db) as $admin) {
        flow_notify($db, [
            'userId' => $admin['id'],
            'tipo' => 'nuevo_usuario',
            'titulo' => 'Nuevo usuario registrado',
            'mensaje' => $user['nombre'] . ' creó una cuenta.',
            'enlace' => flow_url('index.php?seccion=usuarios&id=' . $user['id']),
            'relacionUserId' => $user['id'],
            'clave' => 'nuevo_usuario|' . $admin['id'] . '|user|' . $user['id'],
            'correo' => $admin['correo'],
            'asunto' => 'Nuevo usuario en Creova Flow',
            'cuerpo' => $user['nombre'] . ' (' . $user['correo'] . ') se registró en la plataforma.',
            'correoTipo' => 'nuevo_usuario',
        ]);
    }

    return ['db' => $db, 'user' => $user];
}

function flow_update_profile($db, $user, $input)
{
    $nombre = trim($input['nombre'] ?? '');
    $correo = strtolower(trim($input['correo'] ?? ''));
    $password = (string) ($input['password'] ?? '');
    $confirm = array_key_exists('passwordConfirmacion', $input)
        ? (string) $input['passwordConfirmacion']
        : $password;

    if (strlen($nombre) < 2) {
        throw new FlowError('Escribe tu nombre.', 422);
    }

    if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        throw new FlowError('Escribe un correo válido.', 422);
    }

    $existing = flow_user_by_email($db, $correo);

    if ($existing && (int) $existing['id'] !== (int) $user['id']) {
        throw new FlowError('Ese correo ya está registrado.', 409);
    }

    if ($password !== '' || $confirm !== '') {
        if (strlen($password) < 8) {
            throw new FlowError('La contraseña debe tener al menos 8 caracteres.', 422);
        }

        if ($password !== $confirm) {
            throw new FlowError('Las contraseñas no coinciden.', 422);
        }
    }

    flow_run($db, 'UPDATE usuarios SET nombre = ?, correo = ? WHERE id = ?', [flow_cut($nombre, 120), $correo, (int) $user['id']]);

    if ($password !== '') {
        flow_run($db, 'UPDATE usuarios SET password = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), (int) $user['id']]);
    }

    return $db;
}

function flow_new_project($db, $fields)
{
    $now = flow_now();
    $estado = $fields['estado'] ?? 'pendiente';
    flow_run(
        $db,
        'INSERT INTO proyectos (
            nombre, descripcion, categoria, responsable, oferta_id, usuario_principal_id,
            estado, origen, creado, actualizado, ultima_actividad
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            flow_cut($fields['nombre'] ?? '', 140),
            flow_cut($fields['descripcion'] ?? '', 4000),
            flow_cut($fields['categoria'] ?? '', 40),
            flow_cut($fields['responsable'] ?? '', 120),
            flow_nid($fields['ofertaId'] ?? 0),
            flow_nid($fields['usuarioPrincipalId'] ?? 0),
            $estado,
            $fields['origen'] ?? 'manual',
            $now,
            $now,
            $now,
        ]
    );
    $id = flow_id($db);

    foreach ($fields['suscriptores'] ?? [] as $userId) {
        if ((int) $userId > 0) {
            flow_add_member($db, $id, $userId);
        }
    }

    flow_add_history(
        $db,
        $id,
        null,
        $fields['actorId'] ?? 0,
        $fields['actorNombre'] ?? 'Sistema',
        $fields['historial'] ?? 'Proyecto creado.'
    );

    return [
        'id' => $id,
        'nombre' => flow_cut($fields['nombre'] ?? '', 140),
        'estado' => $estado,
        'ofertaId' => flow_nid($fields['ofertaId'] ?? 0),
    ];
}

function flow_service_project($db, $offerId)
{
    return flow_one(
        $db,
        'SELECT * FROM proyectos WHERE oferta_id = ? AND origen = ? ORDER BY id LIMIT 1',
        [(int) $offerId, 'suscripcion']
    );
}

function flow_user_sees_project($db, $user, $projectId)
{
    if (($user['rol'] ?? '') === 'admin') {
        return true;
    }

    $project = flow_one($db, 'SELECT usuario_principal_id FROM proyectos WHERE id = ?', [(int) $projectId]);

    if (!$project) {
        return false;
    }

    if ((int) $project['usuario_principal_id'] === (int) $user['id']) {
        return true;
    }

    return in_array((int) $user['id'], flow_member_ids($db, $projectId), true);
}

function flow_project_recipients($db, $projectId)
{
    $project = flow_one($db, 'SELECT usuario_principal_id FROM proyectos WHERE id = ?', [(int) $projectId]);
    $ids = flow_member_ids($db, $projectId);

    if ($project && !empty($project['usuario_principal_id'])) {
        $ids[] = (int) $project['usuario_principal_id'];
    }

    $people = [];

    foreach (array_unique($ids) as $id) {
        $person = flow_user_by_id($db, $id);

        if ($person) {
            $people[] = $person;
        }
    }

    return $people;
}

function flow_subscribe($db, $user, $offerId)
{
    $offer = flow_offer($db, $offerId);

    if (!flow_offer_accepts($offer)) {
        throw new FlowError('Esta oferta no acepta nuevas suscripciones.', 409);
    }

    if ($offer['modalidad'] !== 'suscribirse') {
        throw new FlowError('Esta oferta no es de suscripción.', 409);
    }

    if (flow_active_subscription($db, $user['id'], $offer['id'])) {
        throw new FlowError('Ya tienes una suscripción activa a esta oferta.', 409);
    }

    $service = flow_service_project($db, $offer['id']);

    if (!$service) {
        $project = flow_new_project($db, [
            'nombre' => $offer['nombre'],
            'descripcion' => $offer['descripcionCorta'],
            'ofertaId' => $offer['id'],
            'usuarioPrincipalId' => $user['id'],
            'suscriptores' => [$user['id']],
            'estado' => 'en_proceso',
            'actorId' => $user['id'],
            'actorNombre' => $user['nombre'],
            'historial' => 'Proyecto de servicio creado cuando ' . $user['nombre'] . ' se suscribió.',
            'origen' => 'suscripcion',
        ]);
        $projectId = $project['id'];
    } else {
        $projectId = (int) $service['id'];
        flow_add_member($db, $projectId, $user['id']);
        flow_touch_project($db, $projectId);
        flow_add_history($db, $projectId, null, $user['id'], $user['nombre'], $user['nombre'] . ' se suscribió a la oferta.');
    }

    flow_run(
        $db,
        'INSERT INTO suscripciones (usuario_id, oferta_id, proyecto_id, estado, creado) VALUES (?, ?, ?, ?, ?)',
        [(int) $user['id'], (int) $offer['id'], $projectId, 'activa', flow_now()]
    );
    $subscriptionId = flow_id($db);
    $userLink = flow_url('portal.php?seccion=proyectos&id=' . $projectId);
    $adminLink = flow_url('index.php?seccion=biblioteca&id=' . $projectId);

    flow_notify($db, [
        'userId' => $user['id'],
        'tipo' => 'suscripcion_usuario',
        'titulo' => 'Suscripción registrada',
        'mensaje' => 'Tu suscripción a ' . $offer['nombre'] . ' fue registrada.',
        'enlace' => $userLink,
        'ofertaId' => $offer['id'],
        'proyectoId' => $projectId,
        'clave' => 'suscripcion|user|' . $user['id'] . '|sub|' . $subscriptionId,
    ]);

    foreach (flow_admins($db) as $admin) {
        flow_notify($db, [
            'userId' => $admin['id'],
            'tipo' => 'suscripcion_admin',
            'titulo' => 'Nueva suscripción',
            'mensaje' => $user['nombre'] . ' se suscribió a ' . $offer['nombre'] . '.',
            'enlace' => $adminLink,
            'ofertaId' => $offer['id'],
            'proyectoId' => $projectId,
            'relacionUserId' => $user['id'],
            'clave' => 'suscripcion|admin|' . $admin['id'] . '|sub|' . $subscriptionId,
            'correo' => $admin['correo'],
            'asunto' => 'Nueva suscripción',
            'cuerpo' => $user['nombre'] . ' se suscribió a ' . $offer['nombre'] . '. Revisa la suscripción en el panel administrativo.',
            'correoTipo' => 'suscripcion',
        ]);
    }

    return $db;
}

function flow_open_request($db, $userId, $offerId)
{
    return flow_one(
        $db,
        "SELECT id FROM solicitudes
            WHERE usuario_id = ? AND oferta_id = ?
            AND estado IN ('nueva', 'en_revision', 'informacion_adicional', 'cotizacion_enviada')",
        [(int) $userId, (int) $offerId]
    );
}

function flow_create_request($db, $user, $input)
{
    $offer = flow_offer($db, (int) ($input['ofertaId'] ?? 0));

    if (!flow_offer_accepts($offer)) {
        throw new FlowError('Esta oferta no acepta nuevas solicitudes.', 409);
    }

    if ($offer['modalidad'] === 'suscribirse') {
        throw new FlowError('Esta oferta se contrata por suscripción.', 409);
    }

    $nota = trim($input['nota'] ?? '');

    if (strlen($nota) < 8) {
        throw new FlowError('Cuéntanos qué necesitas, con un poco más de detalle.', 422);
    }

    if (flow_open_request($db, $user['id'], $offer['id'])) {
        throw new FlowError('Ya tienes una solicitud abierta para esta oferta.', 409);
    }

    $now = flow_now();
    $tipo = $offer['modalidad'];
    $projectId = null;

    if ($tipo === 'iniciar') {
        $project = flow_new_project($db, [
            'nombre' => $offer['nombre'] . ' — ' . $user['nombre'],
            'descripcion' => trim($offer['descripcionCorta'] . "\n\n" . $nota),
            'ofertaId' => $offer['id'],
            'usuarioPrincipalId' => $user['id'],
            'estado' => 'pendiente',
            'actorId' => $user['id'],
            'actorNombre' => $user['nombre'],
            'historial' => 'Proyecto creado al iniciar la oferta con condiciones definidas.',
            'origen' => 'solicitud',
        ]);
        $projectId = $project['id'];
    }

    flow_run(
        $db,
        'INSERT INTO solicitudes (usuario_id, oferta_id, proyecto_id, tipo, nota, respuesta, estado, creado, actualizado)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [(int) $user['id'], (int) $offer['id'], $projectId, $tipo, flow_cut($nota, 4000), '', 'nueva', $now, $now]
    );
    $requestId = flow_id($db);
    flow_add_history($db, null, $requestId, $user['id'], $user['nombre'], 'Solicitud registrada.');

    if ($projectId) {
        flow_add_history($db, null, $requestId, $user['id'], $user['nombre'], 'El proyecto relacionado quedó pendiente de inicio.');
    }

    $userLink = $projectId
        ? flow_url('portal.php?seccion=proyectos&id=' . $projectId)
        : flow_url('portal.php?seccion=solicitudes&id=' . $requestId);
    $adminLink = $projectId
        ? flow_url('index.php?seccion=biblioteca&id=' . $projectId)
        : flow_url('index.php?seccion=ofertas&solicitud=' . $requestId);
    $verbo = $tipo === 'informacion' ? 'solicitó información de' : 'solicitó';

    flow_notify($db, [
        'userId' => $user['id'],
        'tipo' => 'solicitud_recibida',
        'titulo' => 'Solicitud recibida',
        'mensaje' => 'Tu solicitud para ' . $offer['nombre'] . ' fue recibida.',
        'enlace' => $userLink,
        'ofertaId' => $offer['id'],
        'proyectoId' => $projectId,
        'solicitudId' => $requestId,
        'clave' => 'solicitud|user|' . $requestId . '|recibida',
    ]);

    if ($projectId) {
        flow_notify($db, [
            'userId' => $user['id'],
            'tipo' => 'proyecto_iniciado',
            'titulo' => 'Proyecto iniciado',
            'mensaje' => 'Tu proyecto ' . $offer['nombre'] . ' — ' . $user['nombre'] . ' ya está en Creova Flow.',
            'enlace' => $userLink,
            'ofertaId' => $offer['id'],
            'proyectoId' => $projectId,
            'solicitudId' => $requestId,
            'clave' => 'proyecto_iniciado|user|' . $user['id'] . '|proyecto|' . $projectId,
            'correo' => $user['correo'],
            'asunto' => 'Tu proyecto fue iniciado',
            'cuerpo' => 'El proyecto relacionado con ' . $offer['nombre'] . ' ya está disponible en tu panel.',
            'correoTipo' => 'cambio_estado',
        ]);
    }

    foreach (flow_admins($db) as $admin) {
        flow_notify($db, [
            'userId' => $admin['id'],
            'tipo' => 'solicitud_admin',
            'titulo' => 'Nueva solicitud',
            'mensaje' => 'Nueva solicitud de ' . $user['nombre'] . ' para ' . $offer['nombre'] . '.',
            'enlace' => $adminLink,
            'ofertaId' => $offer['id'],
            'proyectoId' => $projectId,
            'solicitudId' => $requestId,
            'relacionUserId' => $user['id'],
            'clave' => 'solicitud|admin|' . $admin['id'] . '|req|' . $requestId,
            'correo' => $admin['correo'],
            'asunto' => 'Nueva solicitud de servicio',
            'cuerpo' => $user['nombre'] . ' ' . $verbo . ' ' . $offer['nombre'] . '. Revisa la solicitud en el panel administrativo.',
            'correoTipo' => 'solicitud',
        ]);
    }

    return $db;
}

function flow_request_data($db, $id)
{
    $row = flow_one($db, 'SELECT * FROM solicitudes WHERE id = ?', [(int) $id]);

    if (!$row) {
        return null;
    }

    return [
        'id' => (int) $row['id'],
        'userId' => (int) $row['usuario_id'],
        'ofertaId' => (int) $row['oferta_id'],
        'proyectoId' => $row['proyecto_id'] !== null ? (int) $row['proyecto_id'] : null,
        'tipo' => $row['tipo'],
        'nota' => $row['nota'],
        'respuesta' => $row['respuesta'],
        'estado' => $row['estado'],
    ];
}

function flow_set_request_status($db, $actor, $requestId, $estado, $nota)
{
    if (!in_array($estado, flow_request_states(), true)) {
        throw new FlowError('Selecciona un estado válido.', 422);
    }

    $request = flow_request_data($db, $requestId);

    if (!$request) {
        throw new FlowError('No encontramos esa solicitud.', 404);
    }

    $isOwner = (int) $request['userId'] === (int) $actor['id'];

    if ($actor['rol'] !== 'admin') {
        if (!$isOwner || $estado !== 'cancelada' || !flow_request_open($request['estado'])) {
            throw new FlowError('No puedes cambiar este estado.', 403);
        }
    }

    if ($request['estado'] === $estado) {
        throw new FlowError('La solicitud ya está en ese estado.', 409);
    }

    $now = flow_now();
    $label = flow_label_request($estado);
    $nota = trim((string) $nota);
    flow_run($db, 'UPDATE solicitudes SET estado = ?, actualizado = ? WHERE id = ?', [$estado, $now, $request['id']]);
    $text = 'Estado de la solicitud: ' . $label . '.';

    if ($nota !== '') {
        $text .= ' ' . $nota;
    }

    flow_add_history($db, null, $request['id'], $actor['id'], $actor['nombre'], $text);

    if ($estado === 'aprobada' && empty($request['proyectoId']) && in_array($request['tipo'], ['solicitar', 'iniciar'], true)) {
        $offer = flow_offer($db, $request['ofertaId']);
        $owner = flow_user_by_id($db, $request['userId']);
        $project = flow_new_project($db, [
            'nombre' => $offer['nombre'] . ' — ' . $owner['nombre'],
            'descripcion' => trim($offer['descripcionCorta'] . "\n\n" . $request['nota']),
            'ofertaId' => $offer['id'],
            'usuarioPrincipalId' => $owner['id'],
            'estado' => 'pendiente',
            'actorId' => $actor['id'],
            'actorNombre' => $actor['nombre'],
            'historial' => 'Proyecto creado al aprobar la solicitud.',
            'origen' => 'solicitud',
        ]);
        flow_run($db, 'UPDATE solicitudes SET proyecto_id = ? WHERE id = ?', [$project['id'], $request['id']]);
        $request['proyectoId'] = $project['id'];
        flow_notify($db, [
            'userId' => $owner['id'],
            'tipo' => 'proyecto_iniciado',
            'titulo' => 'Proyecto iniciado',
            'mensaje' => 'Tu proyecto ' . $project['nombre'] . ' ya está en Creova Flow.',
            'enlace' => flow_url('portal.php?seccion=proyectos&id=' . $project['id']),
            'ofertaId' => $offer['id'],
            'proyectoId' => $project['id'],
            'solicitudId' => $request['id'],
            'clave' => 'proyecto_iniciado|user|' . $owner['id'] . '|proyecto|' . $project['id'],
            'correo' => $owner['correo'],
            'asunto' => 'Tu proyecto fue iniciado',
            'cuerpo' => 'Tu proyecto ' . $project['nombre'] . ' ya está en Creova Flow.',
            'correoTipo' => 'cambio_estado',
        ]);
    }

    $owner = flow_user_by_id($db, $request['userId']);
    $offer = flow_offer($db, $request['ofertaId']);
    $projectId = flow_request_data($db, $request['id'])['proyectoId'];
    $link = $projectId
        ? flow_url('portal.php?seccion=proyectos&id=' . $projectId)
        : flow_url('portal.php?seccion=solicitudes&id=' . $request['id']);
    $tipo = $estado === 'informacion_adicional' ? 'informacion_adicional' : 'cambio_estado';
    $titulo = $estado === 'informacion_adicional' ? 'Información adicional requerida' : 'Actualización de tu solicitud';
    $mensaje = $estado === 'informacion_adicional'
        ? 'Necesitamos información adicional sobre tu solicitud para ' . $offer['nombre'] . '.'
        : 'Tu solicitud para ' . $offer['nombre'] . ' ahora está: ' . $label . '.';

    if ($nota !== '') {
        $mensaje .= ' ' . $nota;
    }

    if ((int) $actor['id'] !== (int) $owner['id']) {
        flow_notify($db, [
            'userId' => $owner['id'],
            'tipo' => $tipo,
            'titulo' => $titulo,
            'mensaje' => flow_cut($mensaje, 500),
            'enlace' => $link,
            'ofertaId' => $offer['id'],
            'proyectoId' => $projectId,
            'solicitudId' => $request['id'],
            'clave' => 'estado|solicitud|' . $request['id'] . '|' . $estado . '|' . $now,
            'correo' => $owner['correo'],
            'asunto' => $titulo,
            'cuerpo' => $mensaje . "\n\nPuedes revisar el estado desde tu panel de Creova Flow.",
            'correoTipo' => 'cambio_estado',
        ]);
    } elseif ($estado === 'cancelada') {
        foreach (flow_admins($db) as $admin) {
            flow_notify($db, [
                'userId' => $admin['id'],
                'tipo' => 'cambio_estado',
                'titulo' => 'Solicitud cancelada',
                'mensaje' => $owner['nombre'] . ' canceló su solicitud para ' . $offer['nombre'] . '.',
                'enlace' => flow_url('index.php?seccion=ofertas&solicitud=' . $request['id']),
                'ofertaId' => $offer['id'],
                'proyectoId' => $projectId,
                'solicitudId' => $request['id'],
                'relacionUserId' => $owner['id'],
                'clave' => 'cancelada|admin|' . $admin['id'] . '|req|' . $request['id'] . '|' . $now,
                'correo' => $admin['correo'],
                'asunto' => 'Solicitud cancelada',
                'cuerpo' => $owner['nombre'] . ' canceló su solicitud para ' . $offer['nombre'] . '.',
                'correoTipo' => 'cambio_estado',
            ]);
        }
    }

    return $db;
}

function flow_reply_request($db, $actor, $requestId, $texto)
{
    $texto = trim((string) $texto);

    if (strlen($texto) < 8) {
        throw new FlowError('Escribe la información que quieres enviar.', 422);
    }

    $request = flow_request_data($db, $requestId);

    if (!$request) {
        throw new FlowError('No encontramos esa solicitud.', 404);
    }

    if ((int) $request['userId'] !== (int) $actor['id']) {
        throw new FlowError('Solo quien hizo la solicitud puede responder.', 403);
    }

    if ($request['estado'] !== 'informacion_adicional') {
        throw new FlowError('Esta solicitud no está esperando información.', 409);
    }

    $now = flow_now();
    $previous = trim($request['respuesta'] ?? '');
    $respuesta = trim($previous . ($previous !== '' ? "\n\n" : '') . flow_cut($texto, 4000));
    flow_run(
        $db,
        'UPDATE solicitudes SET respuesta = ?, estado = ?, actualizado = ? WHERE id = ?',
        [$respuesta, 'en_revision', $now, $request['id']]
    );
    flow_add_history($db, null, $request['id'], $actor['id'], $actor['nombre'], 'El usuario envió información adicional.');
    $offer = flow_offer($db, $request['ofertaId']);
    $step = (int) flow_one($db, 'SELECT COUNT(*) AS total FROM historial WHERE solicitud_id = ?', [$request['id']])['total'];

    foreach (flow_admins($db) as $admin) {
        flow_notify($db, [
            'userId' => $admin['id'],
            'tipo' => 'informacion_adicional',
            'titulo' => 'Información adicional recibida',
            'mensaje' => $actor['nombre'] . ' envió información adicional sobre ' . $offer['nombre'] . '.',
            'enlace' => flow_url('index.php?seccion=ofertas&solicitud=' . $request['id']),
            'ofertaId' => $offer['id'],
            'proyectoId' => $request['proyectoId'],
            'solicitudId' => $request['id'],
            'relacionUserId' => $actor['id'],
            'clave' => 'info|req|' . $request['id'] . '|' . $step . '|admin|' . $admin['id'],
            'correo' => $admin['correo'],
            'asunto' => 'Información adicional recibida',
            'cuerpo' => $actor['nombre'] . ' envió información adicional sobre ' . $offer['nombre'] . ".\n\n" . flow_cut($texto, 1000),
            'correoTipo' => 'solicitud',
        ]);
    }

    return $db;
}

function flow_apply_project_status($db, $actor, $projectId, $estado, $nota)
{
    if ($actor['rol'] !== 'admin') {
        throw new FlowError('No tienes permiso para cambiar el proyecto.', 403);
    }

    if (!in_array($estado, flow_project_states(), true)) {
        throw new FlowError('Selecciona un estado válido.', 422);
    }

    $project = flow_one($db, 'SELECT * FROM proyectos WHERE id = ?', [(int) $projectId]);

    if (!$project) {
        throw new FlowError('No encontramos ese proyecto.', 404);
    }

    if ($project['estado'] === $estado) {
        throw new FlowError('El proyecto ya está en ese estado.', 409);
    }

    $now = flow_now();
    $label = flow_label_project($estado);
    $nota = trim((string) $nota);
    flow_run(
        $db,
        'UPDATE proyectos SET estado = ?, actualizado = ?, ultima_actividad = ? WHERE id = ?',
        [$estado, $now, $now, (int) $projectId]
    );
    $text = 'Estado del proyecto: ' . $label . '.';

    if ($nota !== '') {
        $text .= ' ' . $nota;
    }

    flow_add_history($db, $projectId, null, $actor['id'], $actor['nombre'], $text);

    foreach (flow_project_recipients($db, $projectId) as $person) {
        if ((int) $person['id'] === (int) $actor['id']) {
            continue;
        }

        $completed = $estado === 'completado';
        $tipo = $completed ? 'proyecto_completado' : 'cambio_estado';
        $titulo = $completed ? 'Proyecto completado' : 'Actualización de tu proyecto';
        $mensaje = $completed
            ? 'El proyecto ' . $project['nombre'] . ' fue marcado como completado.'
            : 'El proyecto ' . $project['nombre'] . ' ahora está: ' . $label . '.';

        if ($nota !== '') {
            $mensaje .= ' ' . $nota;
        }

        $clave = $completed
            ? 'proyecto_completado|user|' . $person['id'] . '|proyecto|' . $projectId
            : 'cambio_estado|proyecto|' . $projectId . '|' . $estado . '|' . $now . '|user|' . $person['id'];

        flow_notify($db, [
            'userId' => $person['id'],
            'tipo' => $tipo,
            'titulo' => $titulo,
            'mensaje' => flow_cut($mensaje, 500),
            'enlace' => flow_url('portal.php?seccion=proyectos&id=' . $projectId),
            'ofertaId' => $project['oferta_id'],
            'proyectoId' => (int) $projectId,
            'clave' => $clave,
            'correo' => $person['correo'],
            'asunto' => $titulo,
            'cuerpo' => $mensaje,
            'correoTipo' => 'cambio_estado',
        ]);
    }

    return $db;
}

function flow_notify_project_link($db, $person, $projectId, $nombre, $ofertaId, $completed)
{
    flow_notify($db, [
        'userId' => $person['id'],
        'tipo' => $completed ? 'proyecto_completado' : 'proyecto_iniciado',
        'titulo' => $completed ? 'Proyecto completado' : 'Proyecto iniciado',
        'mensaje' => 'El proyecto ' . $nombre . ' ya está relacionado con tu cuenta.',
        'enlace' => flow_url('portal.php?seccion=proyectos&id=' . $projectId),
        'ofertaId' => $ofertaId,
        'proyectoId' => $projectId,
        'clave' => ($completed ? 'proyecto_completado' : 'proyecto_iniciado') . '|user|' . $person['id'] . '|proyecto|' . $projectId,
        'correo' => $person['correo'],
        'asunto' => $completed ? 'Proyecto completado' : 'Tu proyecto fue iniciado',
        'cuerpo' => 'El proyecto ' . $nombre . ' ya está en Creova Flow.',
        'correoTipo' => 'cambio_estado',
    ]);
}

function flow_save_project($db, $admin, $input)
{
    $nombre = trim($input['nombre'] ?? '');
    $estado = $input['estado'] ?? 'pendiente';

    if ($nombre === '') {
        throw new FlowError('Escribe el nombre del proyecto.', 422);
    }

    if (!in_array($estado, flow_project_states(), true)) {
        throw new FlowError('Selecciona un estado válido.', 422);
    }

    $ofertaId = (int) ($input['ofertaId'] ?? 0);

    if ($ofertaId && !flow_get_offer($db, $ofertaId)) {
        throw new FlowError('La oferta relacionada no existe.', 422);
    }

    $userId = (int) ($input['usuarioPrincipalId'] ?? 0);

    if ($userId && !flow_user_by_id($db, $userId)) {
        throw new FlowError('El usuario principal no existe.', 422);
    }

    $id = (int) ($input['id'] ?? 0);

    if (!$id) {
        $project = flow_new_project($db, [
            'nombre' => $nombre,
            'descripcion' => $input['descripcion'] ?? '',
            'categoria' => $input['categoria'] ?? '',
            'ofertaId' => $ofertaId,
            'usuarioPrincipalId' => $userId,
            'estado' => $estado,
            'actorId' => $admin['id'],
            'actorNombre' => $admin['nombre'],
            'historial' => 'Proyecto creado por ' . $admin['nombre'] . '.',
            'origen' => 'manual',
        ]);

        if ($userId) {
            flow_notify_project_link($db, flow_user_by_id($db, $userId), $project['id'], $project['nombre'], $ofertaId ?: null, $estado === 'completado');
        }

        return $db;
    }

    $current = flow_one($db, 'SELECT * FROM proyectos WHERE id = ?', [$id]);

    if (!$current) {
        throw new FlowError('No encontramos ese proyecto.', 404);
    }

    $previousStatus = $current['estado'];
    $previousPrincipal = (int) ($current['usuario_principal_id'] ?? 0);
    $now = flow_now();
    flow_run(
        $db,
        'UPDATE proyectos SET
            nombre = ?, descripcion = ?, categoria = ?, oferta_id = ?, usuario_principal_id = ?,
            actualizado = ?, ultima_actividad = ?
         WHERE id = ?',
        [
            flow_cut($nombre, 140),
            flow_cut($input['descripcion'] ?? '', 4000),
            flow_cut($input['categoria'] ?? '', 40),
            flow_nid($ofertaId),
            flow_nid($userId),
            $now,
            $now,
            $id,
        ]
    );

    if ($previousStatus !== $estado) {
        return flow_apply_project_status($db, $admin, $id, $estado, $input['nota'] ?? '');
    }

    flow_add_history($db, $id, null, $admin['id'], $admin['nombre'], 'Datos del proyecto actualizados.');

    if ($userId && $userId !== $previousPrincipal) {
        flow_notify_project_link($db, flow_user_by_id($db, $userId), $id, flow_cut($nombre, 140), $ofertaId ?: null, false);
    }

    return $db;
}

function flow_add_comment($db, $user, $projectId, $texto)
{
    $texto = trim((string) $texto);

    if ($texto === '') {
        throw new FlowError('Escribe un comentario.', 422);
    }

    $project = flow_one($db, 'SELECT * FROM proyectos WHERE id = ?', [(int) $projectId]);

    if (!$project) {
        throw new FlowError('No encontramos ese proyecto.', 404);
    }

    if (!flow_user_sees_project($db, $user, $projectId)) {
        throw new FlowError('No tienes permiso para comentar este proyecto.', 403);
    }

    $now = flow_now();
    flow_run(
        $db,
        'INSERT INTO comentarios (proyecto_id, usuario_id, nombre, texto, fecha) VALUES (?, ?, ?, ?, ?)',
        [(int) $projectId, (int) $user['id'], $user['nombre'], flow_cut($texto, 2000), $now]
    );
    $commentId = flow_id($db);
    flow_touch_project($db, $projectId);
    flow_add_history($db, $projectId, null, $user['id'], $user['nombre'], $user['nombre'] . ' comentó el proyecto.');
    $recipients = ($user['rol'] ?? '') === 'admin'
        ? flow_project_recipients($db, $projectId)
        : flow_admins($db);

    foreach ($recipients as $person) {
        if ((int) $person['id'] === (int) $user['id']) {
            continue;
        }

        $link = ($person['rol'] ?? '') === 'admin'
            ? flow_url('index.php?seccion=biblioteca&id=' . $projectId)
            : flow_url('portal.php?seccion=proyectos&id=' . $projectId);

        flow_notify($db, [
            'userId' => $person['id'],
            'tipo' => 'comentario',
            'titulo' => 'Nuevo comentario',
            'mensaje' => $user['nombre'] . ' comentó ' . $project['nombre'] . '.',
            'enlace' => $link,
            'ofertaId' => $project['oferta_id'],
            'proyectoId' => (int) $projectId,
            'clave' => 'comentario|' . $commentId . '|user|' . $person['id'],
            'correo' => $person['correo'],
            'asunto' => 'Nuevo comentario en tu proyecto',
            'cuerpo' => $user['nombre'] . ' comentó ' . $project['nombre'] . ".\n\n" . flow_cut($texto, 2000),
            'correoTipo' => 'cambio_estado',
        ]);
    }

    return $db;
}

function flow_mark_read($db, $user, $id, $all)
{
    if ($all) {
        flow_run($db, 'UPDATE notificaciones SET leida = 1 WHERE usuario_id = ?', [(int) $user['id']]);
    } else {
        flow_run($db, 'UPDATE notificaciones SET leida = 1 WHERE usuario_id = ? AND id = ?', [(int) $user['id'], (int) $id]);
    }

    return $db;
}

function flow_save_settings($db, $input)
{
    $incoming = $input['correos'] ?? [];

    if (!is_array($incoming)) {
        throw new FlowError('La configuración de correos no es válida.', 422);
    }

    foreach (flow_mail_types() as $type) {
        if (array_key_exists($type, $incoming)) {
            flow_save_setting($db, 'correo_' . $type, !empty($incoming[$type]) ? '1' : '0');
        }
    }

    return $db;
}

function flow_import_projects($db, $admin, $items)
{
    if (!is_array($items)) {
        throw new FlowError('No hay proyectos para importar.', 422);
    }

    $imported = 0;

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $nombre = trim($item['nombre'] ?? '');

        if ($nombre === '') {
            continue;
        }

        $duplicate = flow_one(
            $db,
            'SELECT id FROM proyectos WHERE origen = ? AND nombre = ?',
            ['local', $nombre]
        );

        if ($duplicate) {
            continue;
        }

        flow_new_project($db, [
            'nombre' => $nombre,
            'descripcion' => $item['descripcion'] ?? '',
            'categoria' => $item['categoria'] ?? '',
            'responsable' => $item['responsable'] ?? '',
            'estado' => flow_map_legacy_status($item['estado'] ?? ''),
            'actorId' => $admin['id'],
            'actorNombre' => $admin['nombre'],
            'historial' => 'Proyecto importado desde este navegador.',
            'origen' => 'local',
        ]);
        $imported++;
    }

    return ['db' => $db, 'imported' => $imported];
}

function flow_note_from_row($row)
{
    return [
        'id' => (int) $row['id'],
        'userId' => (int) $row['usuario_id'],
        'tipo' => $row['tipo'],
        'titulo' => $row['titulo'],
        'mensaje' => $row['mensaje'],
        'fecha' => $row['fecha'],
        'leida' => (int) $row['leida'] === 1,
        'enlace' => $row['enlace'],
        'ofertaId' => $row['oferta_id'] !== null ? (int) $row['oferta_id'] : null,
        'proyectoId' => $row['proyecto_id'] !== null ? (int) $row['proyecto_id'] : null,
        'solicitudId' => $row['solicitud_id'] !== null ? (int) $row['solicitud_id'] : null,
        'relacionUserId' => $row['relacion_usuario_id'] !== null ? (int) $row['relacion_usuario_id'] : null,
        'clave' => $row['clave'],
    ];
}

function flow_present_request($db, $row)
{
    $historial = flow_history_rows($db, 'solicitud_id', $row['id']);

    return [
        'id' => (int) $row['id'],
        'userId' => (int) $row['usuario_id'],
        'ofertaId' => (int) $row['oferta_id'],
        'proyectoId' => $row['proyecto_id'] !== null ? (int) $row['proyecto_id'] : null,
        'tipo' => $row['tipo'],
        'nota' => $row['nota'],
        'respuesta' => $row['respuesta'],
        'estado' => $row['estado'],
        'creado' => $row['creado'],
        'actualizado' => $row['actualizado'],
        'historial' => $historial,
        'ofertaNombre' => $row['oferta_nombre'] ?? 'Oferta no disponible',
        'usuarioNombre' => $row['usuario_nombre'] ?? 'Usuario',
        'usuarioCorreo' => $row['usuario_correo'] ?? '',
        'proyectoNombre' => $row['proyecto_nombre'] ?? null,
    ];
}

function flow_request_rows($db, $where, $params)
{
    return flow_all(
        $db,
        'SELECT s.*, u.nombre AS usuario_nombre, u.correo AS usuario_correo,
                o.nombre AS oferta_nombre, p.nombre AS proyecto_nombre
         FROM solicitudes s
         JOIN usuarios u ON u.id = s.usuario_id
         JOIN ofertas o ON o.id = s.oferta_id
         LEFT JOIN proyectos p ON p.id = s.proyecto_id
         ' . $where . '
         ORDER BY s.id',
        $params
    );
}

function flow_present_project($db, $row, $viewer)
{
    $withEmail = ($viewer['rol'] ?? '') === 'admin';
    $offer = !empty($row['oferta_id']) ? flow_get_offer($db, $row['oferta_id']) : null;
    $principal = !empty($row['usuario_principal_id'])
        ? flow_user_by_id($db, $row['usuario_principal_id'])
        : null;
    $subscribers = [];

    foreach (flow_member_ids($db, $row['id']) as $memberId) {
        $member = flow_user_by_id($db, $memberId);

        if (!$member) {
            continue;
        }

        $brief = ['id' => $member['id'], 'nombre' => $member['nombre']];

        if ($withEmail) {
            $brief['correo'] = $member['correo'];
        }

        $subscribers[] = $brief;
    }

    $requestSql = 'WHERE s.proyecto_id = ?';
    $requestParams = [(int) $row['id']];

    if (!$withEmail) {
        $requestSql .= ' AND s.usuario_id = ?';
        $requestParams[] = (int) $viewer['id'];
    }

    $requests = [];

    foreach (flow_request_rows($db, $requestSql, $requestParams) as $request) {
        $requests[] = flow_present_request($db, $request);
    }

    $pending = 0;

    foreach ($requests as $request) {
        if (flow_request_open($request['estado'])) {
            $pending++;
        }
    }

    $noteSql = 'SELECT * FROM notificaciones WHERE proyecto_id = ?';
    $noteParams = [(int) $row['id']];

    if (!$withEmail) {
        $noteSql .= ' AND usuario_id = ?';
        $noteParams[] = (int) $viewer['id'];
    }

    $notes = [];

    foreach (flow_all($db, $noteSql . ' ORDER BY id', $noteParams) as $note) {
        $notes[] = flow_note_from_row($note);
    }

    $comments = [];

    foreach (flow_all($db, 'SELECT * FROM comentarios WHERE proyecto_id = ? ORDER BY id', [(int) $row['id']]) as $comment) {
        $comments[] = [
            'id' => (int) $comment['id'],
            'userId' => (int) $comment['usuario_id'],
            'nombre' => $comment['nombre'],
            'texto' => $comment['texto'],
            'fecha' => $comment['fecha'],
        ];
    }

    return [
        'id' => (int) $row['id'],
        'nombre' => $row['nombre'],
        'descripcion' => $row['descripcion'],
        'categoria' => $row['categoria'],
        'responsable' => $row['responsable'],
        'ofertaId' => $row['oferta_id'] !== null ? (int) $row['oferta_id'] : null,
        'usuarioPrincipalId' => $row['usuario_principal_id'] !== null ? (int) $row['usuario_principal_id'] : null,
        'suscriptores' => flow_member_ids($db, $row['id']),
        'estado' => $row['estado'],
        'origen' => $row['origen'],
        'creado' => $row['creado'],
        'actualizado' => $row['actualizado'],
        'ultimaActividad' => $row['ultima_actividad'],
        'historial' => flow_history_rows($db, 'proyecto_id', $row['id']),
        'comentarios' => $comments,
        'oferta' => $offer ? ['id' => $offer['id'], 'nombre' => $offer['nombre']] : null,
        'usuarioPrincipal' => $principal
            ? ($withEmail
                ? ['id' => $principal['id'], 'nombre' => $principal['nombre'], 'correo' => $principal['correo']]
                : ['id' => $principal['id'], 'nombre' => $principal['nombre']])
            : null,
        'suscriptoresDetalle' => $subscribers,
        'suscritos' => count(flow_member_ids($db, $row['id'])),
        'solicitudes' => $requests,
        'solicitudesPendientes' => $pending,
        'notificaciones' => $notes,
    ];
}

function flow_bootstrap($db, $user)
{
    $admin = $user['rol'] === 'admin';
    $offers = [];

    foreach (flow_all($db, $admin ? 'SELECT * FROM ofertas ORDER BY id' : 'SELECT * FROM ofertas WHERE estado = ? ORDER BY id', $admin ? [] : ['publicada']) as $row) {
        $offers[] = flow_offer_from_row($db, $row);
    }

    $projects = [];
    $projectSql = $admin
        ? 'SELECT * FROM proyectos ORDER BY id'
        : 'SELECT DISTINCT p.* FROM proyectos p
            LEFT JOIN proyecto_usuarios pu ON pu.proyecto_id = p.id
            WHERE p.usuario_principal_id = ? OR pu.usuario_id = ?
            ORDER BY p.id';
    $projectParams = $admin ? [] : [(int) $user['id'], (int) $user['id']];

    foreach (flow_all($db, $projectSql, $projectParams) as $row) {
        $projects[] = flow_present_project($db, $row, $user);
    }

    $requests = [];
    $requestWhere = $admin ? '' : 'WHERE s.usuario_id = ?';
    $requestParams = $admin ? [] : [(int) $user['id']];

    foreach (flow_request_rows($db, $requestWhere, $requestParams) as $row) {
        $requests[] = flow_present_request($db, $row);
    }

    $subscriptions = [];
    $subSql = 'SELECT s.*, o.nombre AS oferta_nombre, u.nombre AS usuario_nombre
        FROM suscripciones s
        JOIN ofertas o ON o.id = s.oferta_id
        JOIN usuarios u ON u.id = s.usuario_id';

    if (!$admin) {
        $subSql .= ' WHERE s.usuario_id = ?';
    }

    foreach (flow_all($db, $subSql . ' ORDER BY s.id', $admin ? [] : [(int) $user['id']]) as $row) {
        $subscriptions[] = [
            'id' => (int) $row['id'],
            'userId' => (int) $row['usuario_id'],
            'ofertaId' => (int) $row['oferta_id'],
            'proyectoId' => (int) $row['proyecto_id'],
            'estado' => $row['estado'],
            'creado' => $row['creado'],
            'ofertaNombre' => $row['oferta_nombre'],
            'usuarioNombre' => $row['usuario_nombre'],
        ];
    }

    $notifications = [];

    foreach (flow_all($db, 'SELECT * FROM notificaciones WHERE usuario_id = ? ORDER BY id DESC', [(int) $user['id']]) as $row) {
        $notifications[] = flow_note_from_row($row);
    }

    $payload = [
        'user' => flow_public_user($user),
        'offers' => $offers,
        'projects' => $projects,
        'requests' => $requests,
        'subscriptions' => $subscriptions,
        'notifications' => $notifications,
    ];

    if (!$admin) {
        return $payload;
    }

    $users = [];

    foreach (flow_all($db, 'SELECT * FROM usuarios ORDER BY id') as $row) {
        $users[] = flow_public_user(flow_user_from_row($row));
    }

    $correos = [];

    foreach (flow_mail_types() as $type) {
        $correos[$type] = flow_mail_enabled($db, $type);
    }

    $mails = [];

    foreach (flow_all($db, 'SELECT * FROM correos ORDER BY id DESC LIMIT 30') as $row) {
        $mails[] = [
            'id' => (int) $row['id'],
            'para' => $row['para'],
            'asunto' => $row['asunto'],
            'cuerpo' => $row['cuerpo'],
            'tipo' => $row['tipo'],
            'fecha' => $row['fecha'],
            'enviado' => (int) $row['enviado'] === 1,
            'error' => $row['error'],
        ];
    }

    $payload['users'] = $users;
    $payload['settings'] = ['correos' => $correos];
    $payload['mails'] = $mails;

    return $payload;
}
