<?php

function flow_config()
{
    static $loaded = false;
    static $config = null;

    if ($loaded) {
        return $config;
    }

    $loaded = true;
    $file = dirname(__DIR__) . '/data/database.php';

    if (!is_file($file)) {
        return null;
    }

    $data = require $file;
    $config = is_array($data) ? $data : null;

    return $config;
}

function flow_sqlite_file()
{
    return dirname(__DIR__) . '/data/creova.sqlite';
}

function flow_json_file()
{
    return dirname(__DIR__) . '/data/flow.json';
}

function flow_pdo()
{
    static $pdo = null;
    static $ready = false;

    if ($pdo instanceof PDO && $ready) {
        return $pdo;
    }

    if (!$pdo instanceof PDO) {
        $config = flow_config();

        if ($config && ($config['driver'] ?? '') === 'mysql') {
            $pdo = new PDO(
                'mysql:host=127.0.0.1;port=3306;dbname=' . $config['database'] . ';charset=utf8mb4',
                (string) $config['username'],
                (string) $config['password'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
        } else {
            $dir = dirname(flow_sqlite_file());

            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException('No se pudo crear la carpeta de la base de datos.');
            }

            $pdo = new PDO('sqlite:' . flow_sqlite_file());
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA busy_timeout = 5000');
        }

        flow_schema($pdo);
    }

    if (!$ready) {
        $pdo->beginTransaction();

        try {
            $count = (int) $pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();

            if ($count === 0 && !flow_import_json($pdo)) {
                flow_seed_database($pdo);
            }

            flow_ensure_mail_settings($pdo);
            $pdo->commit();
            $ready = true;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $error;
        }
    }

    return $pdo;
}

function flow_load()
{
    return flow_pdo();
}

function flow_schema($db)
{
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        flow_schema_mysql($db);

        return;
    }

    $sql =
        'CREATE TABLE IF NOT EXISTS usuarios (
            id INTEGER PRIMARY KEY,
            nombre TEXT NOT NULL,
            correo TEXT NOT NULL UNIQUE,
            password TEXT NOT NULL,
            rol TEXT NOT NULL,
            terminos_at TEXT,
            creado TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS ofertas (
            id INTEGER PRIMARY KEY,
            nombre TEXT NOT NULL,
            portada TEXT NOT NULL DEFAULT "",
            descripcion_corta TEXT NOT NULL,
            problema TEXT NOT NULL DEFAULT "",
            beneficios TEXT NOT NULL DEFAULT "",
            alcance TEXT NOT NULL DEFAULT "",
            entregables TEXT NOT NULL DEFAULT "",
            precio TEXT NOT NULL DEFAULT "",
            duracion TEXT NOT NULL DEFAULT "",
            modalidad TEXT NOT NULL,
            disponibilidad TEXT NOT NULL DEFAULT "",
            estado TEXT NOT NULL,
            publicada_en TEXT,
            creado TEXT NOT NULL,
            actualizado TEXT NOT NULL,
            creado_por INTEGER REFERENCES usuarios(id)
        );

        CREATE TABLE IF NOT EXISTS oferta_archivos (
            id INTEGER PRIMARY KEY,
            oferta_id INTEGER NOT NULL REFERENCES ofertas(id) ON DELETE CASCADE,
            nombre TEXT NOT NULL,
            url TEXT NOT NULL DEFAULT "",
            orden INTEGER NOT NULL DEFAULT 0
        );

        CREATE TABLE IF NOT EXISTS proyectos (
            id INTEGER PRIMARY KEY,
            nombre TEXT NOT NULL,
            descripcion TEXT NOT NULL DEFAULT "",
            categoria TEXT NOT NULL DEFAULT "",
            responsable TEXT NOT NULL DEFAULT "",
            oferta_id INTEGER REFERENCES ofertas(id),
            usuario_principal_id INTEGER REFERENCES usuarios(id),
            estado TEXT NOT NULL,
            origen TEXT NOT NULL DEFAULT "manual",
            creado TEXT NOT NULL,
            actualizado TEXT NOT NULL,
            ultima_actividad TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS proyecto_usuarios (
            proyecto_id INTEGER NOT NULL REFERENCES proyectos(id) ON DELETE CASCADE,
            usuario_id INTEGER NOT NULL REFERENCES usuarios(id),
            PRIMARY KEY (proyecto_id, usuario_id)
        );

        CREATE TABLE IF NOT EXISTS proyecto_archivos (
            id INTEGER PRIMARY KEY,
            proyecto_id INTEGER NOT NULL REFERENCES proyectos(id) ON DELETE CASCADE,
            nombre TEXT NOT NULL,
            url TEXT NOT NULL DEFAULT "",
            orden INTEGER NOT NULL DEFAULT 0
        );

        CREATE TABLE IF NOT EXISTS solicitudes (
            id INTEGER PRIMARY KEY,
            usuario_id INTEGER NOT NULL REFERENCES usuarios(id),
            oferta_id INTEGER NOT NULL REFERENCES ofertas(id),
            proyecto_id INTEGER REFERENCES proyectos(id),
            tipo TEXT NOT NULL,
            nota TEXT NOT NULL,
            respuesta TEXT NOT NULL DEFAULT "",
            estado TEXT NOT NULL,
            creado TEXT NOT NULL,
            actualizado TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS suscripciones (
            id INTEGER PRIMARY KEY,
            usuario_id INTEGER NOT NULL REFERENCES usuarios(id),
            oferta_id INTEGER NOT NULL REFERENCES ofertas(id),
            proyecto_id INTEGER NOT NULL REFERENCES proyectos(id),
            estado TEXT NOT NULL,
            creado TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS historial (
            id INTEGER PRIMARY KEY,
            proyecto_id INTEGER REFERENCES proyectos(id) ON DELETE CASCADE,
            solicitud_id INTEGER REFERENCES solicitudes(id) ON DELETE CASCADE,
            fecha TEXT NOT NULL,
            actor_id INTEGER REFERENCES usuarios(id),
            actor_nombre TEXT NOT NULL,
            texto TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS comentarios (
            id INTEGER PRIMARY KEY,
            proyecto_id INTEGER NOT NULL REFERENCES proyectos(id) ON DELETE CASCADE,
            usuario_id INTEGER NOT NULL REFERENCES usuarios(id),
            nombre TEXT NOT NULL,
            texto TEXT NOT NULL,
            fecha TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS notificaciones (
            id INTEGER PRIMARY KEY,
            usuario_id INTEGER NOT NULL REFERENCES usuarios(id),
            tipo TEXT NOT NULL,
            titulo TEXT NOT NULL,
            mensaje TEXT NOT NULL,
            fecha TEXT NOT NULL,
            leida INTEGER NOT NULL DEFAULT 0,
            enlace TEXT NOT NULL DEFAULT "",
            oferta_id INTEGER REFERENCES ofertas(id),
            proyecto_id INTEGER REFERENCES proyectos(id),
            solicitud_id INTEGER REFERENCES solicitudes(id),
            relacion_usuario_id INTEGER REFERENCES usuarios(id),
            clave TEXT NOT NULL UNIQUE
        );

        CREATE TABLE IF NOT EXISTS dispositivo_push (
            id INTEGER PRIMARY KEY,
            usuario_id INTEGER NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
            endpoint TEXT NOT NULL UNIQUE,
            p256dh TEXT NOT NULL,
            auth TEXT NOT NULL,
            creado TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS correos (
            id INTEGER PRIMARY KEY,
            para TEXT NOT NULL,
            asunto TEXT NOT NULL,
            cuerpo TEXT NOT NULL,
            tipo TEXT NOT NULL,
            fecha TEXT NOT NULL,
            enviado INTEGER NOT NULL DEFAULT 0,
            error TEXT
        );

        CREATE TABLE IF NOT EXISTS configuracion (
            clave TEXT PRIMARY KEY,
            valor TEXT NOT NULL
        );

        CREATE INDEX IF NOT EXISTS idx_solicitudes_usuario ON solicitudes(usuario_id);
        CREATE INDEX IF NOT EXISTS idx_solicitudes_oferta ON solicitudes(oferta_id);
        CREATE INDEX IF NOT EXISTS idx_solicitudes_proyecto ON solicitudes(proyecto_id);
        CREATE INDEX IF NOT EXISTS idx_suscripciones_usuario ON suscripciones(usuario_id);
        CREATE INDEX IF NOT EXISTS idx_suscripciones_oferta ON suscripciones(oferta_id);
        CREATE INDEX IF NOT EXISTS idx_proyectos_oferta ON proyectos(oferta_id);
        CREATE INDEX IF NOT EXISTS idx_notificaciones_usuario ON notificaciones(usuario_id);
        CREATE INDEX IF NOT EXISTS idx_historial_proyecto ON historial(proyecto_id);
        CREATE INDEX IF NOT EXISTS idx_historial_solicitud ON historial(solicitud_id)';

    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        $db->exec($statement);
    }

    $db->exec(
        "CREATE UNIQUE INDEX IF NOT EXISTS suscripcion_activa
            ON suscripciones(usuario_id, oferta_id)
            WHERE estado = 'activa'"
    );
}

function flow_schema_mysql($db)
{
    $statements = [
        'CREATE TABLE IF NOT EXISTS usuarios (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            nombre VARCHAR(120) NOT NULL,
            correo VARCHAR(190) NOT NULL,
            password VARCHAR(255) NOT NULL,
            rol VARCHAR(20) NOT NULL,
            terminos_at VARCHAR(40) NULL,
            creado VARCHAR(40) NOT NULL,
            UNIQUE KEY usuarios_correo (correo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS ofertas (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            nombre VARCHAR(140) NOT NULL,
            portada VARCHAR(500) NOT NULL DEFAULT "",
            descripcion_corta VARCHAR(280) NOT NULL,
            problema TEXT NOT NULL,
            beneficios TEXT NOT NULL,
            alcance TEXT NOT NULL,
            entregables TEXT NOT NULL,
            precio VARCHAR(80) NOT NULL DEFAULT "",
            duracion VARCHAR(80) NOT NULL DEFAULT "",
            modalidad VARCHAR(20) NOT NULL,
            disponibilidad VARCHAR(120) NOT NULL DEFAULT "",
            estado VARCHAR(30) NOT NULL,
            publicada_en VARCHAR(40) NULL,
            creado VARCHAR(40) NOT NULL,
            actualizado VARCHAR(40) NOT NULL,
            creado_por INT UNSIGNED NULL,
            CONSTRAINT fk_ofertas_usuario FOREIGN KEY (creado_por) REFERENCES usuarios(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS oferta_archivos (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            oferta_id INT UNSIGNED NOT NULL,
            nombre VARCHAR(120) NOT NULL,
            url VARCHAR(500) NOT NULL DEFAULT "",
            orden INT NOT NULL DEFAULT 0,
            CONSTRAINT fk_oferta_archivos FOREIGN KEY (oferta_id) REFERENCES ofertas(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS proyectos (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            nombre VARCHAR(140) NOT NULL,
            descripcion TEXT NOT NULL,
            categoria VARCHAR(40) NOT NULL DEFAULT "",
            responsable VARCHAR(120) NOT NULL DEFAULT "",
            oferta_id INT UNSIGNED NULL,
            usuario_principal_id INT UNSIGNED NULL,
            estado VARCHAR(30) NOT NULL,
            origen VARCHAR(20) NOT NULL DEFAULT "manual",
            creado VARCHAR(40) NOT NULL,
            actualizado VARCHAR(40) NOT NULL,
            ultima_actividad VARCHAR(40) NOT NULL,
            CONSTRAINT fk_proyectos_oferta FOREIGN KEY (oferta_id) REFERENCES ofertas(id),
            CONSTRAINT fk_proyectos_usuario FOREIGN KEY (usuario_principal_id) REFERENCES usuarios(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS proyecto_usuarios (
            proyecto_id INT UNSIGNED NOT NULL,
            usuario_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (proyecto_id, usuario_id),
            CONSTRAINT fk_miembros_proyecto FOREIGN KEY (proyecto_id) REFERENCES proyectos(id) ON DELETE CASCADE,
            CONSTRAINT fk_miembros_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS proyecto_archivos (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            proyecto_id INT UNSIGNED NOT NULL,
            nombre VARCHAR(120) NOT NULL,
            url VARCHAR(500) NOT NULL DEFAULT "",
            orden INT NOT NULL DEFAULT 0,
            CONSTRAINT fk_proyecto_archivos FOREIGN KEY (proyecto_id) REFERENCES proyectos(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS solicitudes (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT UNSIGNED NOT NULL,
            oferta_id INT UNSIGNED NOT NULL,
            proyecto_id INT UNSIGNED NULL,
            tipo VARCHAR(20) NOT NULL,
            nota TEXT NOT NULL,
            respuesta TEXT NOT NULL,
            estado VARCHAR(40) NOT NULL,
            creado VARCHAR(40) NOT NULL,
            actualizado VARCHAR(40) NOT NULL,
            CONSTRAINT fk_solicitudes_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
            CONSTRAINT fk_solicitudes_oferta FOREIGN KEY (oferta_id) REFERENCES ofertas(id),
            CONSTRAINT fk_solicitudes_proyecto FOREIGN KEY (proyecto_id) REFERENCES proyectos(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS suscripciones (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT UNSIGNED NOT NULL,
            oferta_id INT UNSIGNED NOT NULL,
            proyecto_id INT UNSIGNED NOT NULL,
            estado VARCHAR(20) NOT NULL,
            creado VARCHAR(40) NOT NULL,
            activa_clave VARCHAR(40) GENERATED ALWAYS AS (CASE WHEN estado = \'activa\' THEN CONCAT(usuario_id, \'-\', oferta_id) ELSE NULL END) STORED,
            UNIQUE KEY suscripcion_activa (activa_clave),
            CONSTRAINT fk_suscripciones_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
            CONSTRAINT fk_suscripciones_oferta FOREIGN KEY (oferta_id) REFERENCES ofertas(id),
            CONSTRAINT fk_suscripciones_proyecto FOREIGN KEY (proyecto_id) REFERENCES proyectos(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS historial (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            proyecto_id INT UNSIGNED NULL,
            solicitud_id INT UNSIGNED NULL,
            fecha VARCHAR(40) NOT NULL,
            actor_id INT UNSIGNED NULL,
            actor_nombre VARCHAR(120) NOT NULL,
            texto TEXT NOT NULL,
            CONSTRAINT fk_historial_proyecto FOREIGN KEY (proyecto_id) REFERENCES proyectos(id) ON DELETE CASCADE,
            CONSTRAINT fk_historial_solicitud FOREIGN KEY (solicitud_id) REFERENCES solicitudes(id) ON DELETE CASCADE,
            CONSTRAINT fk_historial_actor FOREIGN KEY (actor_id) REFERENCES usuarios(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS comentarios (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            proyecto_id INT UNSIGNED NOT NULL,
            usuario_id INT UNSIGNED NOT NULL,
            nombre VARCHAR(120) NOT NULL,
            texto TEXT NOT NULL,
            fecha VARCHAR(40) NOT NULL,
            CONSTRAINT fk_comentarios_proyecto FOREIGN KEY (proyecto_id) REFERENCES proyectos(id) ON DELETE CASCADE,
            CONSTRAINT fk_comentarios_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS notificaciones (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT UNSIGNED NOT NULL,
            tipo VARCHAR(40) NOT NULL,
            titulo VARCHAR(160) NOT NULL,
            mensaje TEXT NOT NULL,
            fecha VARCHAR(40) NOT NULL,
            leida TINYINT NOT NULL DEFAULT 0,
            enlace VARCHAR(500) NOT NULL DEFAULT "",
            oferta_id INT UNSIGNED NULL,
            proyecto_id INT UNSIGNED NULL,
            solicitud_id INT UNSIGNED NULL,
            relacion_usuario_id INT UNSIGNED NULL,
            clave VARCHAR(190) NOT NULL,
            UNIQUE KEY notificaciones_clave (clave),
            CONSTRAINT fk_notificaciones_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
            CONSTRAINT fk_notificaciones_oferta FOREIGN KEY (oferta_id) REFERENCES ofertas(id),
            CONSTRAINT fk_notificaciones_proyecto FOREIGN KEY (proyecto_id) REFERENCES proyectos(id),
            CONSTRAINT fk_notificaciones_solicitud FOREIGN KEY (solicitud_id) REFERENCES solicitudes(id),
            CONSTRAINT fk_notificaciones_relacion FOREIGN KEY (relacion_usuario_id) REFERENCES usuarios(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS correos (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            para VARCHAR(190) NOT NULL,
            asunto VARCHAR(190) NOT NULL,
            cuerpo TEXT NOT NULL,
            tipo VARCHAR(40) NOT NULL,
            fecha VARCHAR(40) NOT NULL,
            enviado TINYINT NOT NULL DEFAULT 0,
            error VARCHAR(255) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS dispositivo_push (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT UNSIGNED NOT NULL,
            endpoint VARCHAR(768) NOT NULL,
            p256dh VARCHAR(255) NOT NULL,
            auth VARCHAR(255) NOT NULL,
            creado VARCHAR(40) NOT NULL,
            UNIQUE KEY dispositivo_endpoint (endpoint),
            CONSTRAINT fk_push_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS configuracion (
            clave VARCHAR(80) NOT NULL PRIMARY KEY,
            valor VARCHAR(20) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
    ];

    foreach ($statements as $statement) {
        $db->exec($statement);
    }
}

function flow_run($db, $sql, $params = [])
{
    $statement = $db->prepare($sql);
    $statement->execute(array_values($params));

    return $statement;
}

function flow_one($db, $sql, $params = [])
{
    $row = flow_run($db, $sql, $params)->fetch();

    return $row ?: null;
}

function flow_all($db, $sql, $params = [])
{
    return flow_run($db, $sql, $params)->fetchAll();
}

function flow_id($db)
{
    return (int) $db->lastInsertId();
}

function flow_nid($id)
{
    $id = (int) $id;

    return $id > 0 ? $id : null;
}

function flow_mail_types()
{
    return ['bienvenida', 'nueva_oferta', 'solicitud', 'suscripcion', 'cambio_estado', 'nuevo_usuario'];
}

function flow_ensure_mail_settings($db)
{
    foreach (flow_mail_types() as $type) {
        $row = flow_one($db, 'SELECT clave FROM configuracion WHERE clave = ?', ['correo_' . $type]);

        if (!$row) {
            flow_run($db, 'INSERT INTO configuracion (clave, valor) VALUES (?, ?)', ['correo_' . $type, '1']);
        }
    }
}

function flow_mail_enabled($db, $type)
{
    $row = flow_one($db, 'SELECT valor FROM configuracion WHERE clave = ?', ['correo_' . $type]);

    if (!$row) {
        return true;
    }

    return $row['valor'] === '1';
}

function flow_user_from_row($row)
{
    if (!$row) {
        return null;
    }

    return [
        'id' => (int) $row['id'],
        'nombre' => $row['nombre'],
        'correo' => $row['correo'],
        'password' => $row['password'],
        'rol' => $row['rol'],
        'terminosAt' => $row['terminos_at'],
        'creado' => $row['creado'],
    ];
}

function flow_user_by_id($db, $id)
{
    return flow_user_from_row(flow_one($db, 'SELECT * FROM usuarios WHERE id = ?', [(int) $id]));
}

function flow_user_by_email($db, $correo)
{
    return flow_user_from_row(flow_one($db, 'SELECT * FROM usuarios WHERE correo = ?', [strtolower(trim($correo))]));
}

function flow_admins($db)
{
    $rows = flow_all($db, 'SELECT * FROM usuarios WHERE rol = ? ORDER BY id', ['admin']);
    $users = [];

    foreach ($rows as $row) {
        $users[] = flow_user_from_row($row);
    }

    return $users;
}

function flow_offer_files($db, $offerId)
{
    return flow_all(
        $db,
        'SELECT nombre, url FROM oferta_archivos WHERE oferta_id = ? ORDER BY orden, id',
        [(int) $offerId]
    );
}

function flow_offer_from_row($db, $row)
{
    if (!$row) {
        return null;
    }

    return [
        'id' => (int) $row['id'],
        'nombre' => $row['nombre'],
        'portada' => $row['portada'],
        'descripcionCorta' => $row['descripcion_corta'],
        'problema' => $row['problema'],
        'beneficios' => $row['beneficios'],
        'alcance' => $row['alcance'],
        'entregables' => $row['entregables'],
        'precio' => $row['precio'],
        'duracion' => $row['duracion'],
        'modalidad' => $row['modalidad'],
        'disponibilidad' => $row['disponibilidad'],
        'estado' => $row['estado'],
        'publicadaEn' => $row['publicada_en'],
        'creado' => $row['creado'],
        'actualizado' => $row['actualizado'],
        'creadoPor' => $row['creado_por'] !== null ? (int) $row['creado_por'] : null,
        'archivos' => flow_offer_files($db, $row['id']),
    ];
}

function flow_get_offer($db, $id)
{
    return flow_offer_from_row($db, flow_one($db, 'SELECT * FROM ofertas WHERE id = ?', [(int) $id]));
}

function flow_history_rows($db, $column, $id)
{
    if ($column !== 'proyecto_id' && $column !== 'solicitud_id') {
        return [];
    }

    $rows = flow_all(
        $db,
        'SELECT fecha, actor_id, actor_nombre, texto FROM historial WHERE ' . $column . ' = ? ORDER BY id',
        [(int) $id]
    );
    $items = [];

    foreach ($rows as $row) {
        $items[] = [
            'fecha' => $row['fecha'],
            'actorId' => (int) $row['actor_id'],
            'actorNombre' => $row['actor_nombre'],
            'texto' => $row['texto'],
        ];
    }

    return $items;
}

function flow_add_history($db, $proyectoId, $solicitudId, $actorId, $actorNombre, $texto)
{
    flow_run(
        $db,
        'INSERT INTO historial (proyecto_id, solicitud_id, fecha, actor_id, actor_nombre, texto)
            VALUES (?, ?, ?, ?, ?, ?)',
        [
            flow_nid($proyectoId),
            flow_nid($solicitudId),
            flow_now(),
            flow_nid($actorId),
            $actorNombre,
            flow_cut($texto, 2000),
        ]
    );
}

function flow_replace_files($db, $table, $column, $parentId, $files)
{
    $idColumn = $table === 'oferta_archivos' ? 'oferta_id' : 'proyecto_id';
    flow_run($db, 'DELETE FROM ' . $table . ' WHERE ' . $idColumn . ' = ?', [(int) $parentId]);
    $order = 0;

    foreach ($files as $file) {
        flow_run(
            $db,
            'INSERT INTO ' . $table . ' (' . $idColumn . ', nombre, url, orden) VALUES (?, ?, ?, ?)',
            [(int) $parentId, $file['nombre'], $file['url'], $order]
        );
        $order++;
    }
}

function flow_save_setting($db, $clave, $valor)
{
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        flow_run(
            $db,
            'INSERT INTO configuracion (clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)',
            [$clave, $valor]
        );

        return;
    }

    flow_run(
        $db,
        'INSERT INTO configuracion (clave, valor) VALUES (?, ?)
            ON CONFLICT(clave) DO UPDATE SET valor = excluded.valor',
        [$clave, $valor]
    );
}

function flow_add_member($db, $proyectoId, $usuarioId)
{
    $sql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
        ? 'INSERT IGNORE INTO proyecto_usuarios (proyecto_id, usuario_id) VALUES (?, ?)'
        : 'INSERT OR IGNORE INTO proyecto_usuarios (proyecto_id, usuario_id) VALUES (?, ?)';
    flow_run($db, $sql, [(int) $proyectoId, (int) $usuarioId]);
}

function flow_member_ids($db, $proyectoId)
{
    $rows = flow_all($db, 'SELECT usuario_id FROM proyecto_usuarios WHERE proyecto_id = ? ORDER BY usuario_id', [(int) $proyectoId]);
    $ids = [];

    foreach ($rows as $row) {
        $ids[] = (int) $row['usuario_id'];
    }

    return $ids;
}

function flow_touch_project($db, $proyectoId)
{
    $now = flow_now();
    flow_run(
        $db,
        'UPDATE proyectos SET actualizado = ?, ultima_actividad = ? WHERE id = ?',
        [$now, $now, (int) $proyectoId]
    );
}

function flow_seed_database($db)
{
    $now = flow_now();
    $config = flow_config();
    $adminCorreo = strtolower(trim((string) ($config['admin_correo'] ?? 'admin@creovaflow.local')));
    $adminNombre = trim((string) ($config['admin_nombre'] ?? 'Administrador Creova'));

    if (!filter_var($adminCorreo, FILTER_VALIDATE_EMAIL)) {
        $adminCorreo = 'admin@creovaflow.local';
    }

    if ($adminNombre === '') {
        $adminNombre = 'Administrador Creova';
    }

    flow_run(
        $db,
        'INSERT INTO usuarios (nombre, correo, password, rol, terminos_at, creado) VALUES (?, ?, ?, ?, ?, ?)',
        [
            flow_cut($adminNombre, 120),
            $adminCorreo,
            password_hash('CreovaAdmin2026', PASSWORD_DEFAULT),
            'admin',
            $now,
            $now,
        ]
    );
    $adminId = flow_id($db);
    flow_insert_offer($db, [
        'nombre' => 'Producción audiovisual',
        'portada' => '',
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
        'publicadaEn' => $now,
        'creadoPor' => $adminId,
        'archivos' => [],
    ], null);
    flow_insert_offer($db, [
        'nombre' => 'Acompañamiento creativo mensual',
        'portada' => '',
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
        'publicadaEn' => $now,
        'creadoPor' => $adminId,
        'archivos' => [],
    ], null);
}

function flow_insert_offer($db, $offer, $explicitId)
{
    $now = flow_now();
    $columns = 'nombre, portada, descripcion_corta, problema, beneficios, alcance, entregables, precio, duracion, modalidad, disponibilidad, estado, publicada_en, creado, actualizado, creado_por';
    $values = [
        $offer['nombre'],
        $offer['portada'],
        $offer['descripcionCorta'],
        $offer['problema'],
        $offer['beneficios'],
        $offer['alcance'],
        $offer['entregables'],
        $offer['precio'],
        $offer['duracion'],
        $offer['modalidad'],
        $offer['disponibilidad'],
        $offer['estado'],
        $offer['publicadaEn'],
        $offer['creado'] ?? $now,
        $now,
        flow_nid($offer['creadoPor'] ?? 0),
    ];

    if ($explicitId) {
        flow_run(
            $db,
            'INSERT INTO ofertas (id, ' . $columns . ') VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            array_merge([(int) $explicitId], $values)
        );
        $id = (int) $explicitId;
    } else {
        flow_run(
            $db,
            'INSERT INTO ofertas (' . $columns . ') VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            $values
        );
        $id = flow_id($db);
    }

    flow_replace_files($db, 'oferta_archivos', 'oferta_id', $id, $offer['archivos'] ?? []);

    return $id;
}

function flow_import_json($db)
{
    $file = flow_json_file();

    if (!is_file($file)) {
        return false;
    }

    $data = json_decode(file_get_contents($file), true);

    if (!is_array($data) || empty($data['users'])) {
        return false;
    }

    foreach ($data['users'] as $user) {
        flow_run(
            $db,
            'INSERT INTO usuarios (id, nombre, correo, password, rol, terminos_at, creado) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                (int) $user['id'],
                $user['nombre'],
                strtolower($user['correo']),
                $user['password'],
                $user['rol'],
                $user['terminosAt'] ?? null,
                $user['creado'],
            ]
        );
    }

    foreach ($data['offers'] ?? [] as $offer) {
        flow_insert_offer($db, [
            'nombre' => $offer['nombre'],
            'portada' => $offer['portada'] ?? '',
            'descripcionCorta' => $offer['descripcionCorta'] ?? '',
            'problema' => $offer['problema'] ?? '',
            'beneficios' => $offer['beneficios'] ?? '',
            'alcance' => $offer['alcance'] ?? '',
            'entregables' => $offer['entregables'] ?? '',
            'precio' => $offer['precio'] ?? '',
            'duracion' => $offer['duracion'] ?? '',
            'modalidad' => $offer['modalidad'],
            'disponibilidad' => $offer['disponibilidad'] ?? '',
            'estado' => $offer['estado'],
            'publicadaEn' => $offer['publicadaEn'] ?? null,
            'creado' => $offer['creado'] ?? flow_now(),
            'creadoPor' => $offer['creadoPor'] ?? null,
            'archivos' => $offer['archivos'] ?? [],
        ], (int) $offer['id']);
        flow_run($db, 'UPDATE ofertas SET actualizado = ? WHERE id = ?', [$offer['actualizado'] ?? flow_now(), (int) $offer['id']]);
    }

    foreach ($data['projects'] ?? [] as $project) {
        flow_run(
            $db,
            'INSERT INTO proyectos (
                id, nombre, descripcion, categoria, responsable, oferta_id, usuario_principal_id,
                estado, origen, creado, actualizado, ultima_actividad
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (int) $project['id'],
                $project['nombre'],
                $project['descripcion'] ?? '',
                $project['categoria'] ?? '',
                $project['responsable'] ?? '',
                flow_nid($project['ofertaId'] ?? 0),
                flow_nid($project['usuarioPrincipalId'] ?? 0),
                $project['estado'],
                $project['origen'] ?? 'manual',
                $project['creado'],
                $project['actualizado'] ?? $project['creado'],
                $project['ultimaActividad'] ?? $project['actualizado'] ?? $project['creado'],
            ]
        );

        foreach ($project['suscriptores'] ?? [] as $userId) {
            flow_add_member($db, $project['id'], $userId);
        }

        foreach ($project['historial'] ?? [] as $item) {
            flow_run(
                $db,
                'INSERT INTO historial (proyecto_id, fecha, actor_id, actor_nombre, texto) VALUES (?, ?, ?, ?, ?)',
                [
                    (int) $project['id'],
                    $item['fecha'],
                    flow_nid($item['actorId'] ?? 0),
                    $item['actorNombre'] ?? 'Sistema',
                    $item['texto'] ?? '',
                ]
            );
        }

        foreach ($project['comentarios'] ?? [] as $comment) {
            flow_run(
                $db,
                'INSERT INTO comentarios (id, proyecto_id, usuario_id, nombre, texto, fecha) VALUES (?, ?, ?, ?, ?, ?)',
                [
                    (int) $comment['id'],
                    (int) $project['id'],
                    (int) $comment['userId'],
                    $comment['nombre'],
                    $comment['texto'],
                    $comment['fecha'],
                ]
            );
        }
    }

    foreach ($data['requests'] ?? [] as $request) {
        flow_run(
            $db,
            'INSERT INTO solicitudes (
                id, usuario_id, oferta_id, proyecto_id, tipo, nota, respuesta, estado, creado, actualizado
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (int) $request['id'],
                (int) $request['userId'],
                (int) $request['ofertaId'],
                flow_nid($request['proyectoId'] ?? 0),
                $request['tipo'],
                $request['nota'] ?? '',
                $request['respuesta'] ?? '',
                $request['estado'],
                $request['creado'],
                $request['actualizado'] ?? $request['creado'],
            ]
        );

        foreach ($request['historial'] ?? [] as $item) {
            flow_run(
                $db,
                'INSERT INTO historial (solicitud_id, fecha, actor_id, actor_nombre, texto) VALUES (?, ?, ?, ?, ?)',
                [
                    (int) $request['id'],
                    $item['fecha'],
                    flow_nid($item['actorId'] ?? 0),
                    $item['actorNombre'] ?? 'Sistema',
                    $item['texto'] ?? '',
                ]
            );
        }
    }

    foreach ($data['subscriptions'] ?? [] as $subscription) {
        flow_run(
            $db,
            'INSERT INTO suscripciones (id, usuario_id, oferta_id, proyecto_id, estado, creado) VALUES (?, ?, ?, ?, ?, ?)',
            [
                (int) $subscription['id'],
                (int) $subscription['userId'],
                (int) $subscription['ofertaId'],
                (int) $subscription['proyectoId'],
                $subscription['estado'],
                $subscription['creado'],
            ]
        );
    }

    foreach ($data['notifications'] ?? [] as $note) {
        flow_run(
            $db,
            'INSERT INTO notificaciones (
                id, usuario_id, tipo, titulo, mensaje, fecha, leida, enlace,
                oferta_id, proyecto_id, solicitud_id, relacion_usuario_id, clave
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (int) $note['id'],
                (int) $note['userId'],
                $note['tipo'],
                $note['titulo'],
                $note['mensaje'],
                $note['fecha'],
                !empty($note['leida']) ? 1 : 0,
                $note['enlace'] ?? '',
                flow_nid($note['ofertaId'] ?? 0),
                flow_nid($note['proyectoId'] ?? 0),
                flow_nid($note['solicitudId'] ?? 0),
                flow_nid($note['relacionUserId'] ?? 0),
                $note['clave'],
            ]
        );
    }

    foreach ($data['mails'] ?? [] as $mail) {
        flow_run(
            $db,
            'INSERT INTO correos (id, para, asunto, cuerpo, tipo, fecha, enviado, error) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (int) $mail['id'],
                $mail['para'],
                $mail['asunto'],
                $mail['cuerpo'],
                $mail['tipo'],
                $mail['fecha'],
                !empty($mail['enviado']) ? 1 : 0,
                $mail['error'] ?? null,
            ]
        );
    }

    foreach (($data['settings']['correos'] ?? []) as $type => $enabled) {
        flow_save_setting($db, 'correo_' . $type, !empty($enabled) ? '1' : '0');
    }

    return true;
}
