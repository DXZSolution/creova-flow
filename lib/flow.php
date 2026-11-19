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

function flow_data_file()
{
    return dirname(__DIR__) . '/data/flow.json';
}

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

function flow_empty_db()
{
    return [
        'seq' => [
            'users' => 0,
            'offers' => 0,
            'requests' => 0,
            'subscriptions' => 0,
            'projects' => 0,
            'notifications' => 0,
            'mails' => 0,
            'comments' => 0,
        ],
        'users' => [],
        'offers' => [],
        'requests' => [],
        'subscriptions' => [],
        'projects' => [],
        'notifications' => [],
        'mails' => [],
        'settings' => [
            'correos' => [
                'bienvenida' => true,
                'nueva_oferta' => true,
                'solicitud' => true,
                'suscripcion' => true,
                'cambio_estado' => true,
                'nuevo_usuario' => true,
            ],
        ],
        'meta' => [
            'seeded' => false,
        ],
    ];
}

function flow_normalize($db)
{
    $empty = flow_empty_db();

    foreach (['users', 'offers', 'requests', 'subscriptions', 'projects', 'notifications', 'mails'] as $key) {
        if (!isset($db[$key]) || !is_array($db[$key])) {
            $db[$key] = [];
        }
    }

    if (!isset($db['seq']) || !is_array($db['seq'])) {
        $db['seq'] = $empty['seq'];
    }

    foreach ($empty['seq'] as $key => $value) {
        if (!isset($db['seq'][$key])) {
            $db['seq'][$key] = $value;
        }
    }

    if (!isset($db['settings']) || !is_array($db['settings'])) {
        $db['settings'] = $empty['settings'];
    }

    if (!isset($db['settings']['correos']) || !is_array($db['settings']['correos'])) {
        $db['settings']['correos'] = $empty['settings']['correos'];
    }

    foreach ($empty['settings']['correos'] as $key => $value) {
        if (!array_key_exists($key, $db['settings']['correos'])) {
            $db['settings']['correos'][$key] = $value;
        }
    }

    if (!isset($db['meta']) || !is_array($db['meta'])) {
        $db['meta'] = ['seeded' => false];
    }

    if (!isset($db['meta']['seeded'])) {
        $db['meta']['seeded'] = false;
    }

    return $db;
}

function flow_next_id(&$db, $bucket)
{
    $db['seq'][$bucket] = (int) ($db['seq'][$bucket] ?? 0) + 1;

    return $db['seq'][$bucket];
}

function flow_find_index($list, $id)
{
    foreach ($list as $index => $item) {
        if ((int) ($item['id'] ?? 0) === (int) $id) {
            return $index;
        }
    }

    return -1;
}

function flow_prepare_store()
{
    $dir = dirname(flow_data_file());

    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('No se pudo crear el almacén de datos.');
    }
}

function flow_write_db($fp, $db)
{
    $encoded = json_encode($db, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    if ($encoded === false) {
        throw new RuntimeException('No se pudo guardar el almacén.');
    }

    rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, $encoded);
    fflush($fp);
}

function flow_transaction($callback)
{
    flow_prepare_store();
    $fp = fopen(flow_data_file(), 'c+');

    if (!$fp) {
        throw new RuntimeException('No se pudo abrir el almacén de datos.');
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            throw new RuntimeException('No se pudo bloquear el almacén de datos.');
        }

        $raw = stream_get_contents($fp);

        if ($raw === false || trim($raw) === '') {
            $db = flow_empty_db();
        } else {
            $db = json_decode($raw, true);

            if (!is_array($db)) {
                throw new RuntimeException('El almacén de datos está dañado.');
            }
        }

        $db = flow_seed(flow_normalize($db));
        $result = $callback($db);
        flow_write_db($fp, $result['db']);

        return $result['out'];
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

function flow_load()
{
    $file = flow_data_file();

    if (!is_file($file) || filesize($file) === 0) {
        return flow_transaction(function ($db) {
            return ['db' => $db, 'out' => $db];
        });
    }

    $fp = fopen($file, 'r');

    if (!$fp) {
        throw new RuntimeException('No se pudo leer el almacén de datos.');
    }

    try {
        if (!flock($fp, LOCK_SH)) {
            throw new RuntimeException('No se pudo leer el almacén de datos.');
        }

        $raw = stream_get_contents($fp);
        $db = json_decode($raw, true);

        if (!is_array($db)) {
            throw new RuntimeException('El almacén de datos está dañado.');
        }

        $db = flow_normalize($db);
        $needsSeed = empty($db['meta']['seeded']);
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    if ($needsSeed) {
        return flow_transaction(function ($db) {
            return ['db' => $db, 'out' => $db];
        });
    }

    return $db;
}

function flow_seed($db)
{
    if (!empty($db['meta']['seeded'])) {
        return $db;
    }

    $now = flow_now();

    if (count($db['users']) === 0) {
        $db['users'][] = [
            'id' => flow_next_id($db, 'users'),
            'nombre' => 'Administrador Creova',
            'correo' => 'admin@creovaflow.local',
            'password' => password_hash('CreovaAdmin2026', PASSWORD_DEFAULT),
            'rol' => 'admin',
            'terminosAt' => $now,
            'creado' => $now,
        ];
    }

    $adminId = (int) $db['users'][0]['id'];

    if (count($db['offers']) === 0) {
        $db['offers'][] = flow_offer_record($db, [
            'nombre' => 'Producción audiovisual',
            'descripcionCorta' => 'Videos promocionales y contenido visual para marcas.',
            'problema' => 'Necesitas explicar un producto o una idea con una pieza visual clara.',
            'beneficios' => "Dirección creativa\nProducción y edición\nEntrega digital",
            'alcance' => 'Una pieza audiovisual según el brief aprobado.',
            'entregables' => 'Video final en formato digital y archivos de revisión.',
            'precio' => 'Desde $4,500',
            'duracion' => '2 a 4 semanas',
            'modalidad' => 'solicitar',
            'disponibilidad' => 'Cupo abierto',
            'estado' => 'publicada',
            'creadoPor' => $adminId,
        ], null);

        $db['offers'][] = flow_offer_record($db, [
            'nombre' => 'Acompañamiento creativo mensual',
            'descripcionCorta' => 'Producción recurrente para mantener activa la comunicación de tu marca.',
            'problema' => 'Publicar una sola vez no sostiene la presencia de la marca.',
            'beneficios' => "Piezas cada mes\nSeguimiento del avance\nUn proyecto compartido",
            'alcance' => 'Un bloque mensual de piezas creativas acordadas al inicio.',
            'entregables' => 'Entregas digitales del periodo y registro del avance.',
            'precio' => 'Desde $8,900 / mes',
            'duracion' => 'Recurrente',
            'modalidad' => 'suscribirse',
            'disponibilidad' => 'Cupo abierto',
            'estado' => 'publicada',
            'creadoPor' => $adminId,
        ], null);
    }

    $db['meta']['seeded'] = true;

    return $db;
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

function flow_user_by_id($db, $id)
{
    $index = flow_find_index($db['users'], $id);

    return $index < 0 ? null : $db['users'][$index];
}

function flow_session_id()
{
    flow_boot();

    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
}

function flow_current_user()
{
    $db = flow_load();
    $user = flow_user_by_id($db, flow_session_id());

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

function flow_admins($db)
{
    return array_values(array_filter($db['users'], function ($user) {
        return ($user['rol'] ?? '') === 'admin';
    }));
}

function flow_offer($db, $id)
{
    $index = flow_find_index($db['offers'], $id);

    if ($index < 0) {
        throw new FlowError('No encontramos esa oferta.', 404);
    }

    return $db['offers'][$index];
}

function flow_offer_or_null($db, $id)
{
    $index = flow_find_index($db['offers'], $id);

    return $index < 0 ? null : $db['offers'][$index];
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

function flow_offer_record(&$db, $input, $previous)
{
    $now = flow_now();
    $estado = $input['estado'] ?? 'borrador';
    $publicadaEn = $previous['publicadaEn'] ?? null;

    if ($estado === 'publicada' && (!$previous || ($previous['estado'] ?? '') !== 'publicada')) {
        $publicadaEn = $now;
    }

    return [
        'id' => $previous['id'] ?? flow_next_id($db, 'offers'),
        'nombre' => flow_cut($input['nombre'] ?? '', 140),
        'portada' => flow_http_url($input['portada'] ?? '', 'La portada'),
        'descripcionCorta' => flow_cut($input['descripcionCorta'] ?? '', 280),
        'problema' => flow_cut($input['problema'] ?? '', 2000),
        'beneficios' => flow_cut($input['beneficios'] ?? '', 2000),
        'alcance' => flow_cut($input['alcance'] ?? '', 2000),
        'entregables' => flow_cut($input['entregables'] ?? '', 2000),
        'precio' => flow_cut($input['precio'] ?? '', 80),
        'duracion' => flow_cut($input['duracion'] ?? '', 80),
        'modalidad' => $input['modalidad'],
        'disponibilidad' => flow_cut($input['disponibilidad'] ?? '', 120),
        'archivos' => $input['archivos'] ?? [],
        'estado' => $estado,
        'publicadaEn' => $publicadaEn,
        'creado' => $previous['creado'] ?? $now,
        'actualizado' => $now,
        'creadoPor' => (int) ($previous['creadoPor'] ?? ($input['creadoPor'] ?? 0)),
    ];
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
    $index = $id ? flow_find_index($db['offers'], $id) : -1;

    if ($id && $index < 0) {
        throw new FlowError('No encontramos esa oferta.', 404);
    }

    $previous = $index >= 0 ? $db['offers'][$index] : null;
    $wasPublished = $previous && ($previous['estado'] ?? '') === 'publicada';
    $input['nombre'] = $nombre;
    $input['descripcionCorta'] = $corta;
    $input['modalidad'] = $modalidad;
    $input['estado'] = $estado;
    $input['archivos'] = flow_files($input);
    $input['creadoPor'] = $admin['id'];
    $offer = flow_offer_record($db, $input, $previous);

    if ($index >= 0) {
        $db['offers'][$index] = $offer;
    } else {
        $db['offers'][] = $offer;
    }

    if ($estado === 'publicada' && !$wasPublished) {
        flow_announce_offer($db, $offer);
    }

    return $db;
}

function flow_announce_offer(&$db, $offer)
{
    foreach ($db['users'] as $user) {
        if (($user['rol'] ?? '') !== 'user') {
            continue;
        }

        if (flow_active_subscription($db, $user['id'], $offer['id'])) {
            continue;
        }

        $link = flow_url('portal.php?seccion=ofertas&id=' . $offer['id']);
        flow_notify($db, [
            'userId' => (int) $user['id'],
            'tipo' => 'nueva_oferta',
            'titulo' => 'Nueva oferta disponible',
            'mensaje' => 'Nueva oferta disponible: ' . $offer['nombre'] . '. Ver detalles.',
            'enlace' => $link,
            'ofertaId' => (int) $offer['id'],
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
    foreach ($db['subscriptions'] as $subscription) {
        if ((int) $subscription['userId'] === (int) $userId
            && (int) $subscription['ofertaId'] === (int) $offerId
            && ($subscription['estado'] ?? '') === 'activa') {
            return $subscription;
        }
    }

    return null;
}

function flow_send_mail(&$db, $to, $subject, $body, $link, $tipo)
{
    $enabled = !empty($db['settings']['correos'][$tipo]);
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
        $headers = "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n";
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $sent = @mail($to, $encodedSubject, $full, $headers);

        if (!$sent) {
            $error = 'El servidor no aceptó el envío. El mensaje quedó registrado.';
        }
    }

    $db['mails'][] = [
        'id' => flow_next_id($db, 'mails'),
        'para' => $to,
        'asunto' => $subject,
        'cuerpo' => $full,
        'tipo' => $tipo,
        'fecha' => flow_now(),
        'enviado' => (bool) $sent,
        'error' => $error,
    ];

    if (count($db['mails']) > 200) {
        $db['mails'] = array_slice($db['mails'], -200);
    }
}

function flow_notify(&$db, $spec)
{
    foreach ($db['notifications'] as $notification) {
        if (($notification['clave'] ?? '') === $spec['clave']) {
            return false;
        }
    }

    $db['notifications'][] = [
        'id' => flow_next_id($db, 'notifications'),
        'userId' => (int) $spec['userId'],
        'tipo' => $spec['tipo'],
        'titulo' => $spec['titulo'],
        'mensaje' => $spec['mensaje'],
        'fecha' => flow_now(),
        'leida' => false,
        'enlace' => $spec['enlace'] ?? '',
        'ofertaId' => $spec['ofertaId'] ?? null,
        'proyectoId' => $spec['proyectoId'] ?? null,
        'solicitudId' => $spec['solicitudId'] ?? null,
        'relacionUserId' => $spec['relacionUserId'] ?? null,
        'clave' => $spec['clave'],
    ];

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

    return true;
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

    foreach ($db['users'] as $user) {
        if (strtolower($user['correo']) === $correo) {
            throw new FlowError('Ese correo ya está registrado.', 409);
        }
    }

    $now = flow_now();
    $user = [
        'id' => flow_next_id($db, 'users'),
        'nombre' => flow_cut($nombre, 120),
        'correo' => $correo,
        'password' => password_hash($password, PASSWORD_DEFAULT),
        'rol' => 'user',
        'terminosAt' => $now,
        'creado' => $now,
    ];
    $db['users'][] = $user;
    $link = flow_url('portal.php?seccion=ofertas');

    flow_notify($db, [
        'userId' => (int) $user['id'],
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
            'userId' => (int) $admin['id'],
            'tipo' => 'nuevo_usuario',
            'titulo' => 'Nuevo usuario registrado',
            'mensaje' => $user['nombre'] . ' creó una cuenta.',
            'enlace' => flow_url('index.php?seccion=usuarios&id=' . $user['id']),
            'relacionUserId' => (int) $user['id'],
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

    foreach ($db['users'] as $existing) {
        if ((int) $existing['id'] !== (int) $user['id'] && strtolower($existing['correo']) === $correo) {
            throw new FlowError('Ese correo ya está registrado.', 409);
        }
    }

    if ($password !== '' || $confirm !== '') {
        if (strlen($password) < 8) {
            throw new FlowError('La contraseña debe tener al menos 8 caracteres.', 422);
        }

        if ($password !== $confirm) {
            throw new FlowError('Las contraseñas no coinciden.', 422);
        }
    }

    $index = flow_find_index($db['users'], $user['id']);
    $db['users'][$index]['nombre'] = flow_cut($nombre, 120);
    $db['users'][$index]['correo'] = $correo;

    if ($password !== '') {
        $db['users'][$index]['password'] = password_hash($password, PASSWORD_DEFAULT);
    }

    return $db;
}

function flow_new_project(&$db, $fields)
{
    $now = flow_now();
    $project = [
        'id' => flow_next_id($db, 'projects'),
        'nombre' => flow_cut($fields['nombre'] ?? '', 140),
        'descripcion' => flow_cut($fields['descripcion'] ?? '', 4000),
        'categoria' => flow_cut($fields['categoria'] ?? '', 40),
        'responsable' => flow_cut($fields['responsable'] ?? '', 120),
        'ofertaId' => !empty($fields['ofertaId']) ? (int) $fields['ofertaId'] : null,
        'usuarioPrincipalId' => !empty($fields['usuarioPrincipalId']) ? (int) $fields['usuarioPrincipalId'] : null,
        'suscriptores' => array_values(array_map('intval', $fields['suscriptores'] ?? [])),
        'estado' => $fields['estado'] ?? 'pendiente',
        'creado' => $now,
        'actualizado' => $now,
        'ultimaActividad' => $now,
        'historial' => [[
            'fecha' => $now,
            'actorId' => (int) ($fields['actorId'] ?? 0),
            'actorNombre' => $fields['actorNombre'] ?? 'Sistema',
            'texto' => flow_cut($fields['historial'] ?? 'Proyecto creado.', 2000),
        ]],
        'comentarios' => [],
        'archivos' => $fields['archivos'] ?? [],
        'origen' => $fields['origen'] ?? 'manual',
    ];
    $db['projects'][] = $project;

    return $project;
}

function flow_service_project_index($db, $offerId)
{
    foreach ($db['projects'] as $index => $project) {
        if ((int) ($project['ofertaId'] ?? 0) === (int) $offerId && ($project['origen'] ?? '') === 'suscripcion') {
            return $index;
        }
    }

    return -1;
}

function flow_user_sees_project($user, $project)
{
    if (($user['rol'] ?? '') === 'admin') {
        return true;
    }

    if ((int) ($project['usuarioPrincipalId'] ?? 0) === (int) $user['id']) {
        return true;
    }

    foreach ($project['suscriptores'] ?? [] as $subscriberId) {
        if ((int) $subscriberId === (int) $user['id']) {
            return true;
        }
    }

    return false;
}

function flow_project_recipients($db, $project)
{
    $ids = [];

    if (!empty($project['usuarioPrincipalId'])) {
        $ids[] = (int) $project['usuarioPrincipalId'];
    }

    foreach ($project['suscriptores'] ?? [] as $subscriberId) {
        $ids[] = (int) $subscriberId;
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

    if (($offer['modalidad'] ?? '') !== 'suscribirse') {
        throw new FlowError('Esta oferta no es de suscripción.', 409);
    }

    if (flow_active_subscription($db, $user['id'], $offer['id'])) {
        throw new FlowError('Ya tienes una suscripción activa a esta oferta.', 409);
    }

    $now = flow_now();
    $index = flow_service_project_index($db, $offer['id']);

    if ($index < 0) {
        $project = flow_new_project($db, [
            'nombre' => $offer['nombre'],
            'descripcion' => $offer['descripcionCorta'],
            'ofertaId' => $offer['id'],
            'usuarioPrincipalId' => $user['id'],
            'suscriptores' => [(int) $user['id']],
            'estado' => 'en_proceso',
            'actorId' => $user['id'],
            'actorNombre' => $user['nombre'],
            'historial' => 'Proyecto de servicio creado cuando ' . $user['nombre'] . ' se suscribió.',
            'origen' => 'suscripcion',
        ]);
        $projectId = (int) $project['id'];
    } else {
        $projectId = (int) $db['projects'][$index]['id'];
        $already = false;

        foreach ($db['projects'][$index]['suscriptores'] as $subscriberId) {
            if ((int) $subscriberId === (int) $user['id']) {
                $already = true;
            }
        }

        if (!$already) {
            $db['projects'][$index]['suscriptores'][] = (int) $user['id'];
        }

        $db['projects'][$index]['actualizado'] = $now;
        $db['projects'][$index]['ultimaActividad'] = $now;
        $db['projects'][$index]['historial'][] = [
            'fecha' => $now,
            'actorId' => (int) $user['id'],
            'actorNombre' => $user['nombre'],
            'texto' => $user['nombre'] . ' se suscribió a la oferta.',
        ];
    }

    $subscription = [
        'id' => flow_next_id($db, 'subscriptions'),
        'userId' => (int) $user['id'],
        'ofertaId' => (int) $offer['id'],
        'proyectoId' => $projectId,
        'estado' => 'activa',
        'creado' => $now,
    ];
    $db['subscriptions'][] = $subscription;
    $userLink = flow_url('portal.php?seccion=proyectos&id=' . $projectId);
    $adminLink = flow_url('index.php?seccion=biblioteca&id=' . $projectId);

    flow_notify($db, [
        'userId' => (int) $user['id'],
        'tipo' => 'suscripcion_usuario',
        'titulo' => 'Suscripción registrada',
        'mensaje' => 'Tu suscripción a ' . $offer['nombre'] . ' fue registrada.',
        'enlace' => $userLink,
        'ofertaId' => (int) $offer['id'],
        'proyectoId' => $projectId,
        'clave' => 'suscripcion|user|' . $user['id'] . '|sub|' . $subscription['id'],
        'correo' => $user['correo'],
        'asunto' => 'Tu suscripción fue registrada',
        'cuerpo' => 'Te suscribiste a ' . $offer['nombre'] . '. Puedes revisar el proyecto desde tu panel de Creova Flow.',
        'correoTipo' => 'suscripcion',
    ]);

    foreach (flow_admins($db) as $admin) {
        flow_notify($db, [
            'userId' => (int) $admin['id'],
            'tipo' => 'suscripcion_admin',
            'titulo' => 'Nueva suscripción',
            'mensaje' => $user['nombre'] . ' se suscribió a ' . $offer['nombre'] . '.',
            'enlace' => $adminLink,
            'ofertaId' => (int) $offer['id'],
            'proyectoId' => $projectId,
            'relacionUserId' => (int) $user['id'],
            'clave' => 'suscripcion|admin|' . $admin['id'] . '|sub|' . $subscription['id'],
            'correo' => $admin['correo'],
            'asunto' => 'Nueva solicitud de servicio',
            'cuerpo' => $user['nombre'] . ' se suscribió a ' . $offer['nombre'] . '. Revisa la solicitud en el panel administrativo.',
            'correoTipo' => 'suscripcion',
        ]);
    }

    return $db;
}

function flow_create_request($db, $user, $input)
{
    $offer = flow_offer($db, (int) ($input['ofertaId'] ?? 0));

    if (!flow_offer_accepts($offer)) {
        throw new FlowError('Esta oferta no acepta nuevas solicitudes.', 409);
    }

    if (($offer['modalidad'] ?? '') === 'suscribirse') {
        throw new FlowError('Esta oferta se contrata por suscripción.', 409);
    }

    $nota = trim($input['nota'] ?? '');

    if (strlen($nota) < 8) {
        throw new FlowError('Cuéntanos qué necesitas, con un poco más de detalle.', 422);
    }

    foreach ($db['requests'] as $existing) {
        if ((int) $existing['userId'] === (int) $user['id']
            && (int) $existing['ofertaId'] === (int) $offer['id']
            && flow_request_open($existing['estado'])) {
            throw new FlowError('Ya tienes una solicitud abierta para esta oferta.', 409);
        }
    }

    $now = flow_now();
    $tipo = $offer['modalidad'];
    $request = [
        'id' => flow_next_id($db, 'requests'),
        'userId' => (int) $user['id'],
        'ofertaId' => (int) $offer['id'],
        'proyectoId' => null,
        'tipo' => $tipo,
        'nota' => flow_cut($nota, 4000),
        'respuesta' => '',
        'estado' => 'nueva',
        'creado' => $now,
        'actualizado' => $now,
        'historial' => [[
            'fecha' => $now,
            'actorId' => (int) $user['id'],
            'actorNombre' => $user['nombre'],
            'texto' => 'Solicitud registrada.',
        ]],
    ];

    if ($tipo === 'iniciar') {
        $project = flow_new_project($db, [
            'nombre' => $offer['nombre'] . ' — ' . $user['nombre'],
            'descripcion' => trim($offer['descripcionCorta'] . "\n\n" . $nota),
            'ofertaId' => $offer['id'],
            'usuarioPrincipalId' => $user['id'],
            'suscriptores' => [],
            'estado' => 'pendiente',
            'actorId' => $user['id'],
            'actorNombre' => $user['nombre'],
            'historial' => 'Proyecto creado al iniciar la oferta con condiciones definidas.',
            'origen' => 'solicitud',
        ]);
        $request['proyectoId'] = (int) $project['id'];
        $request['historial'][] = [
            'fecha' => $now,
            'actorId' => (int) $user['id'],
            'actorNombre' => $user['nombre'],
            'texto' => 'El proyecto relacionado quedó pendiente de inicio.',
        ];
    }

    $db['requests'][] = $request;
    $projectId = $request['proyectoId'];
    $userLink = $projectId
        ? flow_url('portal.php?seccion=proyectos&id=' . $projectId)
        : flow_url('portal.php?seccion=solicitudes&id=' . $request['id']);
    $adminLink = $projectId
        ? flow_url('index.php?seccion=biblioteca&id=' . $projectId)
        : flow_url('index.php?seccion=ofertas&solicitud=' . $request['id']);
    $verbo = $tipo === 'informacion' ? 'solicitó información de' : 'solicitó';

    flow_notify($db, [
        'userId' => (int) $user['id'],
        'tipo' => 'solicitud_recibida',
        'titulo' => 'Solicitud recibida',
        'mensaje' => 'Tu solicitud para ' . $offer['nombre'] . ' fue recibida.',
        'enlace' => $userLink,
        'ofertaId' => (int) $offer['id'],
        'proyectoId' => $projectId,
        'solicitudId' => (int) $request['id'],
        'clave' => 'solicitud|user|' . $request['id'] . '|recibida',
        'correo' => $user['correo'],
        'asunto' => 'Recibimos tu solicitud de servicio',
        'cuerpo' => 'Hemos recibido tu solicitud para ' . $offer['nombre'] . '. Puedes revisar el estado desde tu panel de Creova Flow.',
        'correoTipo' => 'solicitud',
    ]);

    if ($projectId) {
        flow_notify($db, [
            'userId' => (int) $user['id'],
            'tipo' => 'proyecto_iniciado',
            'titulo' => 'Proyecto iniciado',
            'mensaje' => 'Tu proyecto ' . $offer['nombre'] . ' — ' . $user['nombre'] . ' ya está en Creova Flow.',
            'enlace' => $userLink,
            'ofertaId' => (int) $offer['id'],
            'proyectoId' => $projectId,
            'solicitudId' => (int) $request['id'],
            'clave' => 'proyecto_iniciado|user|' . $user['id'] . '|proyecto|' . $projectId,
            'correo' => $user['correo'],
            'asunto' => 'Tu proyecto fue iniciado',
            'cuerpo' => 'El proyecto relacionado con ' . $offer['nombre'] . ' ya está disponible en tu panel.',
            'correoTipo' => 'cambio_estado',
        ]);
    }

    foreach (flow_admins($db) as $admin) {
        flow_notify($db, [
            'userId' => (int) $admin['id'],
            'tipo' => 'solicitud_admin',
            'titulo' => 'Nueva solicitud',
            'mensaje' => 'Nueva solicitud de ' . $user['nombre'] . ' para ' . $offer['nombre'] . '.',
            'enlace' => $adminLink,
            'ofertaId' => (int) $offer['id'],
            'proyectoId' => $projectId,
            'solicitudId' => (int) $request['id'],
            'relacionUserId' => (int) $user['id'],
            'clave' => 'solicitud|admin|' . $admin['id'] . '|req|' . $request['id'],
            'correo' => $admin['correo'],
            'asunto' => 'Nueva solicitud de servicio',
            'cuerpo' => $user['nombre'] . ' ' . $verbo . ' ' . $offer['nombre'] . '. Revisa la solicitud en el panel administrativo.',
            'correoTipo' => 'solicitud',
        ]);
    }

    return $db;
}

function flow_set_request_status($db, $actor, $requestId, $estado, $nota)
{
    if (!in_array($estado, flow_request_states(), true)) {
        throw new FlowError('Selecciona un estado válido.', 422);
    }

    $index = flow_find_index($db['requests'], $requestId);

    if ($index < 0) {
        throw new FlowError('No encontramos esa solicitud.', 404);
    }

    $request = $db['requests'][$index];
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
    $db['requests'][$index]['estado'] = $estado;
    $db['requests'][$index]['actualizado'] = $now;
    $text = 'Estado de la solicitud: ' . $label . '.';

    if ($nota !== '') {
        $text .= ' ' . $nota;
    }

    $db['requests'][$index]['historial'][] = [
        'fecha' => $now,
        'actorId' => (int) $actor['id'],
        'actorNombre' => $actor['nombre'],
        'texto' => flow_cut($text, 2000),
    ];

    if ($estado === 'aprobada'
        && empty($db['requests'][$index]['proyectoId'])
        && in_array($request['tipo'], ['solicitar', 'iniciar'], true)) {
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
        $db['requests'][$index]['proyectoId'] = (int) $project['id'];
        flow_notify($db, [
            'userId' => (int) $owner['id'],
            'tipo' => 'proyecto_iniciado',
            'titulo' => 'Proyecto iniciado',
            'mensaje' => 'Tu proyecto ' . $project['nombre'] . ' ya está en Creova Flow.',
            'enlace' => flow_url('portal.php?seccion=proyectos&id=' . $project['id']),
            'ofertaId' => (int) $offer['id'],
            'proyectoId' => (int) $project['id'],
            'solicitudId' => (int) $request['id'],
            'clave' => 'proyecto_iniciado|user|' . $owner['id'] . '|proyecto|' . $project['id'],
            'correo' => $owner['correo'],
            'asunto' => 'Tu proyecto fue iniciado',
            'cuerpo' => 'Tu proyecto ' . $project['nombre'] . ' ya está en Creova Flow.',
            'correoTipo' => 'cambio_estado',
        ]);
    }

    $owner = flow_user_by_id($db, $request['userId']);
    $offer = flow_offer($db, $request['ofertaId']);
    $projectId = $db['requests'][$index]['proyectoId'];
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
            'userId' => (int) $owner['id'],
            'tipo' => $tipo,
            'titulo' => $titulo,
            'mensaje' => flow_cut($mensaje, 500),
            'enlace' => $link,
            'ofertaId' => (int) $offer['id'],
            'proyectoId' => $projectId,
            'solicitudId' => (int) $request['id'],
            'clave' => 'estado|solicitud|' . $request['id'] . '|' . $estado . '|' . $now,
            'correo' => $owner['correo'],
            'asunto' => $titulo,
            'cuerpo' => $mensaje . "\n\nPuedes revisar el estado desde tu panel de Creova Flow.",
            'correoTipo' => 'cambio_estado',
        ]);
    } elseif ($estado === 'cancelada') {
        foreach (flow_admins($db) as $admin) {
            flow_notify($db, [
                'userId' => (int) $admin['id'],
                'tipo' => 'cambio_estado',
                'titulo' => 'Solicitud cancelada',
                'mensaje' => $owner['nombre'] . ' canceló su solicitud para ' . $offer['nombre'] . '.',
                'enlace' => flow_url('index.php?seccion=ofertas&solicitud=' . $request['id']),
                'ofertaId' => (int) $offer['id'],
                'proyectoId' => $projectId,
                'solicitudId' => (int) $request['id'],
                'relacionUserId' => (int) $owner['id'],
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

    $index = flow_find_index($db['requests'], $requestId);

    if ($index < 0) {
        throw new FlowError('No encontramos esa solicitud.', 404);
    }

    $request = $db['requests'][$index];

    if ((int) $request['userId'] !== (int) $actor['id']) {
        throw new FlowError('Solo quien hizo la solicitud puede responder.', 403);
    }

    if ($request['estado'] !== 'informacion_adicional') {
        throw new FlowError('Esta solicitud no está esperando información.', 409);
    }

    $now = flow_now();
    $previous = trim($request['respuesta'] ?? '');
    $db['requests'][$index]['respuesta'] = trim($previous . ($previous !== '' ? "\n\n" : '') . flow_cut($texto, 4000));
    $db['requests'][$index]['estado'] = 'en_revision';
    $db['requests'][$index]['actualizado'] = $now;
    $db['requests'][$index]['historial'][] = [
        'fecha' => $now,
        'actorId' => (int) $actor['id'],
        'actorNombre' => $actor['nombre'],
        'texto' => 'El usuario envió información adicional.',
    ];
    $offer = flow_offer($db, $request['ofertaId']);
    $step = count($db['requests'][$index]['historial']);

    foreach (flow_admins($db) as $admin) {
        flow_notify($db, [
            'userId' => (int) $admin['id'],
            'tipo' => 'informacion_adicional',
            'titulo' => 'Información adicional recibida',
            'mensaje' => $actor['nombre'] . ' envió información adicional sobre ' . $offer['nombre'] . '.',
            'enlace' => flow_url('index.php?seccion=ofertas&solicitud=' . $request['id']),
            'ofertaId' => (int) $offer['id'],
            'proyectoId' => $request['proyectoId'],
            'solicitudId' => (int) $request['id'],
            'relacionUserId' => (int) $actor['id'],
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

    $index = flow_find_index($db['projects'], $projectId);

    if ($index < 0) {
        throw new FlowError('No encontramos ese proyecto.', 404);
    }

    if ($db['projects'][$index]['estado'] === $estado) {
        throw new FlowError('El proyecto ya está en ese estado.', 409);
    }

    $now = flow_now();
    $label = flow_label_project($estado);
    $nota = trim((string) $nota);
    $db['projects'][$index]['estado'] = $estado;
    $db['projects'][$index]['actualizado'] = $now;
    $db['projects'][$index]['ultimaActividad'] = $now;
    $text = 'Estado del proyecto: ' . $label . '.';

    if ($nota !== '') {
        $text .= ' ' . $nota;
    }

    $db['projects'][$index]['historial'][] = [
        'fecha' => $now,
        'actorId' => (int) $actor['id'],
        'actorNombre' => $actor['nombre'],
        'texto' => flow_cut($text, 2000),
    ];

    foreach (flow_project_recipients($db, $db['projects'][$index]) as $person) {
        if ((int) $person['id'] === (int) $actor['id']) {
            continue;
        }

        $completed = $estado === 'completado';
        $tipo = $completed ? 'proyecto_completado' : 'cambio_estado';
        $titulo = $completed ? 'Proyecto completado' : 'Actualización de tu proyecto';
        $mensaje = $completed
            ? 'El proyecto ' . $db['projects'][$index]['nombre'] . ' fue marcado como completado.'
            : 'El proyecto ' . $db['projects'][$index]['nombre'] . ' ahora está: ' . $label . '.';

        if ($nota !== '') {
            $mensaje .= ' ' . $nota;
        }

        $clave = $completed
            ? 'proyecto_completado|user|' . $person['id'] . '|proyecto|' . $projectId
            : 'cambio_estado|proyecto|' . $projectId . '|' . $estado . '|' . $now . '|user|' . $person['id'];

        flow_notify($db, [
            'userId' => (int) $person['id'],
            'tipo' => $tipo,
            'titulo' => $titulo,
            'mensaje' => flow_cut($mensaje, 500),
            'enlace' => flow_url('portal.php?seccion=proyectos&id=' . $projectId),
            'ofertaId' => $db['projects'][$index]['ofertaId'],
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

    if ($ofertaId && !flow_offer_or_null($db, $ofertaId)) {
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
            $person = flow_user_by_id($db, $userId);
            $completed = $estado === 'completado';
            flow_notify($db, [
                'userId' => (int) $person['id'],
                'tipo' => $completed ? 'proyecto_completado' : 'proyecto_iniciado',
                'titulo' => $completed ? 'Proyecto completado' : 'Proyecto iniciado',
                'mensaje' => 'El proyecto ' . $project['nombre'] . ' ya está relacionado con tu cuenta.',
                'enlace' => flow_url('portal.php?seccion=proyectos&id=' . $project['id']),
                'ofertaId' => $ofertaId ?: null,
                'proyectoId' => (int) $project['id'],
                'clave' => ($completed ? 'proyecto_completado' : 'proyecto_iniciado') . '|user|' . $person['id'] . '|proyecto|' . $project['id'],
                'correo' => $person['correo'],
                'asunto' => $completed ? 'Proyecto completado' : 'Tu proyecto fue iniciado',
                'cuerpo' => 'El proyecto ' . $project['nombre'] . ' ya está en Creova Flow.',
                'correoTipo' => 'cambio_estado',
            ]);
        }

        return $db;
    }

    $index = flow_find_index($db['projects'], $id);

    if ($index < 0) {
        throw new FlowError('No encontramos ese proyecto.', 404);
    }

    $previousStatus = $db['projects'][$index]['estado'];
    $previousPrincipal = (int) ($db['projects'][$index]['usuarioPrincipalId'] ?? 0);
    $now = flow_now();
    $db['projects'][$index]['nombre'] = flow_cut($nombre, 140);
    $db['projects'][$index]['descripcion'] = flow_cut($input['descripcion'] ?? '', 4000);
    $db['projects'][$index]['categoria'] = flow_cut($input['categoria'] ?? '', 40);
    $db['projects'][$index]['ofertaId'] = $ofertaId ?: null;
    $db['projects'][$index]['usuarioPrincipalId'] = $userId ?: null;
    $db['projects'][$index]['actualizado'] = $now;
    $db['projects'][$index]['ultimaActividad'] = $now;

    if ($previousStatus !== $estado) {
        return flow_apply_project_status($db, $admin, $id, $estado, $input['nota'] ?? '');
    }

    $db['projects'][$index]['historial'][] = [
        'fecha' => $now,
        'actorId' => (int) $admin['id'],
        'actorNombre' => $admin['nombre'],
        'texto' => 'Datos del proyecto actualizados.',
    ];

    if ($userId && $userId !== $previousPrincipal) {
        $person = flow_user_by_id($db, $userId);
        flow_notify($db, [
            'userId' => (int) $person['id'],
            'tipo' => 'proyecto_iniciado',
            'titulo' => 'Proyecto iniciado',
            'mensaje' => 'El proyecto ' . $db['projects'][$index]['nombre'] . ' ya está relacionado con tu cuenta.',
            'enlace' => flow_url('portal.php?seccion=proyectos&id=' . $id),
            'ofertaId' => $ofertaId ?: null,
            'proyectoId' => $id,
            'clave' => 'proyecto_iniciado|user|' . $person['id'] . '|proyecto|' . $id,
            'correo' => $person['correo'],
            'asunto' => 'Tu proyecto fue iniciado',
            'cuerpo' => 'El proyecto ' . $db['projects'][$index]['nombre'] . ' ya está en Creova Flow.',
            'correoTipo' => 'cambio_estado',
        ]);
    }

    return $db;
}

function flow_add_comment($db, $user, $projectId, $texto)
{
    $texto = trim((string) $texto);

    if ($texto === '') {
        throw new FlowError('Escribe un comentario.', 422);
    }

    $index = flow_find_index($db['projects'], $projectId);

    if ($index < 0) {
        throw new FlowError('No encontramos ese proyecto.', 404);
    }

    if (!flow_user_sees_project($user, $db['projects'][$index])) {
        throw new FlowError('No tienes permiso para comentar este proyecto.', 403);
    }

    $now = flow_now();
    $comment = [
        'id' => flow_next_id($db, 'comments'),
        'userId' => (int) $user['id'],
        'nombre' => $user['nombre'],
        'texto' => flow_cut($texto, 2000),
        'fecha' => $now,
    ];
    $db['projects'][$index]['comentarios'][] = $comment;
    $db['projects'][$index]['ultimaActividad'] = $now;
    $db['projects'][$index]['actualizado'] = $now;
    $db['projects'][$index]['historial'][] = [
        'fecha' => $now,
        'actorId' => (int) $user['id'],
        'actorNombre' => $user['nombre'],
        'texto' => $user['nombre'] . ' comentó el proyecto.',
    ];

    $recipients = ($user['rol'] ?? '') === 'admin'
        ? flow_project_recipients($db, $db['projects'][$index])
        : flow_admins($db);

    foreach ($recipients as $person) {
        if ((int) $person['id'] === (int) $user['id']) {
            continue;
        }

        $link = ($person['rol'] ?? '') === 'admin'
            ? flow_url('index.php?seccion=biblioteca&id=' . $projectId)
            : flow_url('portal.php?seccion=proyectos&id=' . $projectId);

        flow_notify($db, [
            'userId' => (int) $person['id'],
            'tipo' => 'comentario',
            'titulo' => 'Nuevo comentario',
            'mensaje' => $user['nombre'] . ' comentó ' . $db['projects'][$index]['nombre'] . '.',
            'enlace' => $link,
            'ofertaId' => $db['projects'][$index]['ofertaId'],
            'proyectoId' => (int) $projectId,
            'clave' => 'comentario|' . $comment['id'] . '|user|' . $person['id'],
            'correo' => $person['correo'],
            'asunto' => 'Nuevo comentario en tu proyecto',
            'cuerpo' => $user['nombre'] . ' comentó ' . $db['projects'][$index]['nombre'] . ".\n\n" . $comment['texto'],
            'correoTipo' => 'cambio_estado',
        ]);
    }

    return $db;
}

function flow_mark_read($db, $user, $id, $all)
{
    foreach ($db['notifications'] as $index => $notification) {
        if ((int) $notification['userId'] !== (int) $user['id']) {
            continue;
        }

        if ($all || (int) $notification['id'] === (int) $id) {
            $db['notifications'][$index]['leida'] = true;
        }
    }

    return $db;
}

function flow_save_settings($db, $input)
{
    $incoming = $input['correos'] ?? [];

    if (!is_array($incoming)) {
        throw new FlowError('La configuración de correos no es válida.', 422);
    }

    foreach (array_keys($db['settings']['correos']) as $key) {
        if (array_key_exists($key, $incoming)) {
            $db['settings']['correos'][$key] = !empty($incoming[$key]);
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

        $duplicate = false;

        foreach ($db['projects'] as $project) {
            if (($project['origen'] ?? '') === 'local' && $project['nombre'] === $nombre) {
                $duplicate = true;
            }
        }

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

function flow_user_brief($db, $id, $withEmail)
{
    $user = flow_user_by_id($db, $id);

    if (!$user) {
        return null;
    }

    $brief = [
        'id' => (int) $user['id'],
        'nombre' => $user['nombre'],
    ];

    if ($withEmail) {
        $brief['correo'] = $user['correo'];
    }

    return $brief;
}

function flow_present_request($db, $request)
{
    $offer = flow_offer_or_null($db, $request['ofertaId'] ?? 0);
    $user = flow_user_by_id($db, $request['userId'] ?? 0);
    $projectName = null;

    if (!empty($request['proyectoId'])) {
        $index = flow_find_index($db['projects'], $request['proyectoId']);
        $projectName = $index >= 0 ? $db['projects'][$index]['nombre'] : null;
    }

    $request['ofertaNombre'] = $offer['nombre'] ?? 'Oferta no disponible';
    $request['usuarioNombre'] = $user['nombre'] ?? 'Usuario';
    $request['usuarioCorreo'] = $user['correo'] ?? '';
    $request['proyectoNombre'] = $projectName;

    return $request;
}

function flow_present_subscription($db, $subscription)
{
    $offer = flow_offer_or_null($db, $subscription['ofertaId'] ?? 0);
    $user = flow_user_by_id($db, $subscription['userId'] ?? 0);
    $subscription['ofertaNombre'] = $offer['nombre'] ?? 'Oferta';
    $subscription['usuarioNombre'] = $user['nombre'] ?? 'Usuario';

    return $subscription;
}

function flow_present_project($db, $project, $viewer)
{
    $offer = !empty($project['ofertaId']) ? flow_offer_or_null($db, $project['ofertaId']) : null;
    $withEmail = ($viewer['rol'] ?? '') === 'admin';
    $subscribers = [];

    foreach ($project['suscriptores'] ?? [] as $subscriberId) {
        $brief = flow_user_brief($db, $subscriberId, $withEmail);

        if ($brief) {
            $subscribers[] = $brief;
        }
    }

    $requests = [];

    foreach ($db['requests'] as $request) {
        if ((int) ($request['proyectoId'] ?? 0) !== (int) $project['id']) {
            continue;
        }

        if (!$withEmail && (int) $request['userId'] !== (int) $viewer['id']) {
            continue;
        }

        $requests[] = flow_present_request($db, $request);
    }

    $pending = 0;

    foreach ($requests as $request) {
        if (flow_request_open($request['estado'])) {
            $pending++;
        }
    }

    $notifications = [];

    foreach ($db['notifications'] as $notification) {
        if ((int) ($notification['proyectoId'] ?? 0) !== (int) $project['id']) {
            continue;
        }

        if (!$withEmail && (int) $notification['userId'] !== (int) $viewer['id']) {
            continue;
        }

        $notifications[] = $notification;
    }

    $project['oferta'] = $offer ? ['id' => (int) $offer['id'], 'nombre' => $offer['nombre']] : null;
    $project['usuarioPrincipal'] = !empty($project['usuarioPrincipalId'])
        ? flow_user_brief($db, $project['usuarioPrincipalId'], $withEmail)
        : null;
    $project['suscriptoresDetalle'] = $subscribers;
    $project['suscritos'] = count($project['suscriptores'] ?? []);
    $project['solicitudes'] = $requests;
    $project['solicitudesPendientes'] = $pending;
    $project['notificaciones'] = $notifications;

    return $project;
}

function flow_visible_notifications($db, $userId)
{
    $items = array_values(array_filter($db['notifications'], function ($notification) use ($userId) {
        return (int) $notification['userId'] === (int) $userId;
    }));

    usort($items, function ($a, $b) {
        return (int) $b['id'] <=> (int) $a['id'];
    });

    return $items;
}

function flow_bootstrap($db, $user)
{
    if ($user['rol'] === 'admin') {
        $projects = [];

        foreach ($db['projects'] as $project) {
            $projects[] = flow_present_project($db, $project, $user);
        }

        $requests = [];

        foreach ($db['requests'] as $request) {
            $requests[] = flow_present_request($db, $request);
        }

        $subscriptions = [];

        foreach ($db['subscriptions'] as $subscription) {
            $subscriptions[] = flow_present_subscription($db, $subscription);
        }

        $users = [];

        foreach ($db['users'] as $account) {
            $users[] = flow_public_user($account);
        }

        return [
            'user' => flow_public_user($user),
            'offers' => $db['offers'],
            'projects' => $projects,
            'requests' => $requests,
            'subscriptions' => $subscriptions,
            'users' => $users,
            'notifications' => flow_visible_notifications($db, $user['id']),
            'settings' => $db['settings'],
            'mails' => array_slice(array_reverse($db['mails']), 0, 30),
        ];
    }

    $offers = array_values(array_filter($db['offers'], function ($offer) {
        return ($offer['estado'] ?? '') === 'publicada';
    }));
    $projects = [];

    foreach ($db['projects'] as $project) {
        if (flow_user_sees_project($user, $project)) {
            $projects[] = flow_present_project($db, $project, $user);
        }
    }

    $requests = [];

    foreach ($db['requests'] as $request) {
        if ((int) $request['userId'] === (int) $user['id']) {
            $requests[] = flow_present_request($db, $request);
        }
    }

    $subscriptions = [];

    foreach ($db['subscriptions'] as $subscription) {
        if ((int) $subscription['userId'] === (int) $user['id']) {
            $subscriptions[] = flow_present_subscription($db, $subscription);
        }
    }

    return [
        'user' => flow_public_user($user),
        'offers' => $offers,
        'projects' => $projects,
        'requests' => $requests,
        'subscriptions' => $subscriptions,
        'notifications' => flow_visible_notifications($db, $user['id']),
    ];
}
