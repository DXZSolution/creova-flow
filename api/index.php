<?php

require dirname(__DIR__) . '/lib/flow.php';

flow_boot();

set_exception_handler(function ($error) {
    $status = $error instanceof FlowError ? $error->status : 500;
    $message = $error instanceof FlowError
        ? $error->getMessage()
        : 'No se pudo completar la operación.';

    if (!($error instanceof FlowError)) {
        error_log($error->getMessage());
    }

    flow_json([
        'success' => false,
        'message' => $message,
    ], $status);
});

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET' && $action === 'session') {
    $db = flow_load();
    $user = flow_user_by_id($db, flow_session_id());
    flow_ok([
        'user' => $user ? flow_public_user($user) : null,
    ]);
}

if ($method === 'GET' && $action === 'bootstrap') {
    $db = flow_load();
    $user = flow_require($db);
    flow_ok(flow_bootstrap($db, $user));
}

if ($method !== 'POST') {
    throw new FlowError('Método no permitido.', 405);
}

flow_check_token();
$body = flow_body();

if ($action === 'login') {
    $user = flow_transaction(function ($db) use ($body) {
        $correo = strtolower(trim($body['correo'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $found = flow_user_by_email($db, $correo);

        if (!$found || !password_verify($password, $found['password'])) {
            throw new FlowError('Correo o contraseña incorrectos.', 401);
        }

        return ['db' => $db, 'out' => $found];
    });
    flow_login_session($user);
    flow_ok([
        'user' => flow_public_user($user),
        'redirect' => $user['rol'] === 'admin' ? 'index.php' : 'portal.php?seccion=ofertas',
    ]);
}

if ($action === 'register') {
    $user = flow_transaction(function ($db) use ($body) {
        $result = flow_register($db, $body);

        return ['db' => $result['db'], 'out' => $result['user']];
    });
    flow_login_session($user);
    flow_ok([
        'user' => flow_public_user($user),
        'redirect' => 'portal.php?seccion=ofertas',
        'message' => 'Tu cuenta fue creada. Ya puedes consultar las ofertas.',
    ]);
}

if ($action === 'logout') {
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }

    session_destroy();
    flow_json([
        'success' => true,
        'redirect' => 'acceso.php',
    ]);
}

$result = flow_transaction(function ($db) use ($action, $body) {
    if ($action === 'profile') {
        $user = flow_require($db);
        $db = flow_update_profile($db, $user, $body);

        return ['db' => $db, 'out' => 'Perfil actualizado.'];
    }

    if ($action === 'offer') {
        $admin = flow_require($db, 'admin');

        return ['db' => flow_save_offer($db, $admin, $body), 'out' => 'Oferta guardada.'];
    }

    if ($action === 'request') {
        $user = flow_require($db);

        if ($user['rol'] !== 'user') {
            throw new FlowError('Las solicitudes se registran desde una cuenta de usuario.', 403);
        }

        return ['db' => flow_create_request($db, $user, $body), 'out' => 'Solicitud registrada.'];
    }

    if ($action === 'request-status') {
        $actor = flow_require($db);

        return [
            'db' => flow_set_request_status($db, $actor, (int) ($body['id'] ?? 0), $body['estado'] ?? '', $body['nota'] ?? ''),
            'out' => 'Estado de la solicitud actualizado.',
        ];
    }

    if ($action === 'request-reply') {
        $actor = flow_require($db);

        return [
            'db' => flow_reply_request($db, $actor, (int) ($body['id'] ?? 0), $body['texto'] ?? ''),
            'out' => 'Información enviada.',
        ];
    }

    if ($action === 'subscribe') {
        $user = flow_require($db);

        if ($user['rol'] !== 'user') {
            throw new FlowError('La suscripción se registra desde una cuenta de usuario.', 403);
        }

        return ['db' => flow_subscribe($db, $user, (int) ($body['ofertaId'] ?? 0)), 'out' => 'Suscripción registrada.'];
    }

    if ($action === 'project') {
        $admin = flow_require($db, 'admin');

        return ['db' => flow_save_project($db, $admin, $body), 'out' => 'Proyecto guardado.'];
    }

    if ($action === 'project-status') {
        $admin = flow_require($db, 'admin');

        return [
            'db' => flow_apply_project_status($db, $admin, (int) ($body['id'] ?? 0), $body['estado'] ?? '', $body['nota'] ?? ''),
            'out' => 'Estado del proyecto actualizado.',
        ];
    }

    if ($action === 'project-delete') {
        $admin = flow_require($db, 'admin');

        return [
            'db' => flow_delete_project($db, $admin, (int) ($body['id'] ?? 0)),
            'out' => 'Proyecto eliminado.',
        ];
    }

    if ($action === 'comment') {
        $user = flow_require($db);

        return [
            'db' => flow_add_comment($db, $user, (int) ($body['proyectoId'] ?? 0), $body['texto'] ?? ''),
            'out' => 'Comentario publicado.',
        ];
    }

    if ($action === 'notification-read') {
        $user = flow_require($db);

        return ['db' => flow_mark_read($db, $user, (int) ($body['id'] ?? 0), false), 'out' => 'Notificación leída.'];
    }

    if ($action === 'notification-read-all') {
        $user = flow_require($db);

        return ['db' => flow_mark_read($db, $user, 0, true), 'out' => 'Notificaciones marcadas como leídas.'];
    }

    if ($action === 'settings') {
        flow_require($db, 'admin');

        return ['db' => flow_save_settings($db, $body), 'out' => 'Configuración guardada.'];
    }

    if ($action === 'import-projects') {
        $admin = flow_require($db, 'admin');
        $imported = flow_import_projects($db, $admin, $body['projects'] ?? []);

        return ['db' => $imported['db'], 'out' => $imported['imported']];
    }

    throw new FlowError('Acción no encontrada.', 404);
});

if ($action === 'import-projects') {
    flow_ok(['imported' => $result, 'message' => 'Proyectos locales revisados.']);
}

flow_ok(['message' => $result]);
