<?php

while (ob_get_level()) {
    ob_end_clean();
}
ob_start();

require __DIR__ . '/../db.php';
require __DIR__ . '/../RequestValidator.php';

header_remove();
header('Content-Type: application/json; charset=utf-8');

function json_response($payload, $code = 200) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header_remove();
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function clean_string($value) {
    return trim((string)($value ?? ''));
}

function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode($data) {
    $padding = 4 - (strlen($data) % 4);
    if ($padding < 4) {
        $data .= str_repeat('=', $padding);
    }
    return base64_decode(strtr($data, '-_', '+/'));
}

function get_jwt_secret() {
    $secret = getenv('JWT_SECRET') ?: '';
    if ($secret === '') {
        $secret = API_KEY ?: '';
    }
    if ($secret === '' || (ENVIRONMENT !== 'development' && strlen($secret) < 32)) {
        throw new Exception('JWT_SECRET no configurado correctamente', 500);
    }
    return $secret;
}

function jwt_encode_payload($payload, $ttlSeconds = 28800) {
    $now = time();
    $payload['iat'] = $now;
    $payload['nbf'] = $now;
    $payload['exp'] = $now + $ttlSeconds;

    $header = ['typ' => 'JWT', 'alg' => 'HS256'];
    $segments = [
        base64url_encode(json_encode($header, JSON_UNESCAPED_UNICODE)),
        base64url_encode(json_encode($payload, JSON_UNESCAPED_UNICODE))
    ];
    $signature = hash_hmac('sha256', implode('.', $segments), get_jwt_secret(), true);
    $segments[] = base64url_encode($signature);
    return [implode('.', $segments), $payload['exp']];
}

function jwt_decode_payload($token) {
    $parts = explode('.', clean_string($token));
    if (count($parts) !== 3) {
        throw new Exception('Token invalido', 401);
    }

    [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;
    $header = json_decode(base64url_decode($encodedHeader), true);
    $payload = json_decode(base64url_decode($encodedPayload), true);
    if (!is_array($header) || !is_array($payload) || ($header['alg'] ?? '') !== 'HS256') {
        throw new Exception('Token invalido', 401);
    }

    $expected = base64url_encode(hash_hmac('sha256', $encodedHeader . '.' . $encodedPayload, get_jwt_secret(), true));
    if (!hash_equals($expected, $encodedSignature)) {
        throw new Exception('Token invalido', 401);
    }

    $now = time();
    if (isset($payload['nbf']) && (int)$payload['nbf'] > $now) {
        throw new Exception('Token aun no valido', 401);
    }
    if (!isset($payload['exp']) || (int)$payload['exp'] < $now) {
        throw new Exception('Sesion expirada', 401);
    }
    return $payload;
}

function get_authorization_header() {
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        return $_SERVER['HTTP_AUTHORIZATION'];
    }
    if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        return $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }
    if (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        foreach ($headers as $key => $value) {
            if (strtolower($key) === 'authorization') {
                return $value;
            }
        }
    }
    return '';
}

function get_bearer_token() {
    $header = get_authorization_header();
    if (preg_match('/Bearer\s+(.+)/i', $header, $m)) {
        return trim($m[1]);
    }
    return '';
}

function slugify_cliente($value) {
    $slug = strtolower(clean_string($value));
    $slug = preg_replace('/[^a-z0-9]+/i', '-', $slug);
    $slug = trim($slug, '-');
    return $slug !== '' ? $slug : 'cliente';
}

function to_mysql_date($value) {
    $s = clean_string($value);
    if ($s === '') {
        return date('Y-m-d');
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $s)) {
        return substr($s, 0, 10);
    }
    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})/', $s, $m)) {
        return $m[3] . '-' . $m[2] . '-' . $m[1];
    }
    try {
        return (new DateTime($s))->format('Y-m-d');
    } catch (Exception $e) {
        return date('Y-m-d');
    }
}

function to_mysql_datetime_or_null($value) {
    $s = clean_string($value);
    if ($s === '') {
        return null;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $s)) {
        return str_replace('T', ' ', strlen($s) === 16 ? $s . ':00' : substr($s, 0, 19));
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}/', $s)) {
        return strlen($s) === 16 ? $s . ':00' : substr($s, 0, 19);
    }
    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})\s+(\d{1,2}):(\d{2})(?::(\d{2}))?/', $s, $m)) {
        return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $m[3], $m[2], $m[1], $m[4], $m[5], $m[6] ?? 0);
    }
    try {
        return (new DateTime($s))->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return null;
    }
}

function get_or_create_cliente($conn, $nombre) {
    $nombre = clean_string($nombre);
    if ($nombre === '') {
        throw new Exception('Cliente requerido', 400);
    }

    $stmt = $conn->prepare('SELECT id FROM clientes WHERE nombre = ? LIMIT 1');
    $stmt->bind_param('s', $nombre);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $stmt->close();
        return (int)$row['id'];
    }
    $stmt->close();

    $baseSlug = slugify_cliente($nombre);
    $slug = $baseSlug;
    $n = 2;
    while (true) {
        $stmt = $conn->prepare('SELECT id FROM clientes WHERE slug = ? LIMIT 1');
        $stmt->bind_param('s', $slug);
        $stmt->execute();
        $res = $stmt->get_result();
        $exists = $res && $res->num_rows > 0;
        $stmt->close();
        if (!$exists) {
            break;
        }
        $slug = $baseSlug . '-' . $n;
        $n++;
    }

    $stmt = $conn->prepare('INSERT INTO clientes (nombre, slug, activo) VALUES (?, ?, 1)');
    $stmt->bind_param('ss', $nombre, $slug);
    if (!$stmt->execute()) {
        throw new Exception('Error creando cliente: ' . $stmt->error, 500);
    }
    $id = (int)$stmt->insert_id;
    $stmt->close();
    return $id;
}

function get_or_upsert_unidad($conn, $clienteId, $economico, $placas, $operador, $telefonos, $equipos) {
    $stmt = $conn->prepare(
        'INSERT INTO unidades (cliente_id, economico, placas, operador, telefonos, equipos, activo)
         VALUES (?, ?, ?, ?, ?, ?, 1)
         ON DUPLICATE KEY UPDATE
           placas = VALUES(placas),
           operador = VALUES(operador),
           telefonos = VALUES(telefonos),
           equipos = VALUES(equipos),
           activo = 1'
    );
    $stmt->bind_param('isssss', $clienteId, $economico, $placas, $operador, $telefonos, $equipos);
    if (!$stmt->execute()) {
        throw new Exception('Error guardando unidad: ' . $stmt->error, 500);
    }
    $stmt->close();

    $stmt = $conn->prepare('SELECT id FROM unidades WHERE cliente_id = ? AND economico = ? LIMIT 1');
    $stmt->bind_param('is', $clienteId, $economico);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!$row) {
        throw new Exception('No se pudo resolver la unidad guardada', 500);
    }
    return (int)$row['id'];
}

function parse_tabs($raw) {
    $tabs = [];
    foreach (explode(',', clean_string($raw)) as $part) {
        $v = (int)trim($part);
        if ($v >= 0 && !in_array($v, $tabs, true)) {
            $tabs[] = $v;
        }
    }
    return count($tabs) ? $tabs : [3, 4, 5, 6, 7];
}

function default_tabs_for_role($role) {
    return strtolower(clean_string($role)) === 'lector'
        ? [3, 4, 5, 6, 7]
        : [0, 1, 2, 3, 4, 5, 6, 7];
}

function get_user_context($conn, $email) {
    $email = strtolower(clean_string($email));
    if ($email === '') {
        return null;
    }

    $stmt = $conn->prepare(
        'SELECT id, email, nombre, role, activo FROM usuarios WHERE LOWER(email) = ? LIMIT 1'
    );
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $res = $stmt->get_result();
    $user = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!$user || (int)$user['activo'] !== 1) {
        return null;
    }

    $userId = (int)$user['id'];
    $clientes = [];
    if (strtolower($user['role']) === 'admin') {
        $res = $conn->query('SELECT id, nombre FROM clientes WHERE activo = 1 ORDER BY nombre ASC');
        while ($row = $res->fetch_assoc()) {
            $clientes[] = ['id' => (int)$row['id'], 'nombre' => $row['nombre']];
        }
    } else {
        $stmt = $conn->prepare(
            'SELECT c.id, c.nombre
               FROM usuario_clientes uc
               INNER JOIN clientes c ON c.id = uc.cliente_id
              WHERE uc.usuario_id = ? AND c.activo = 1
              ORDER BY c.nombre ASC'
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $clientes[] = ['id' => (int)$row['id'], 'nombre' => $row['nombre']];
        }
        $stmt->close();
    }

    $tabs = [];
    $stmt = $conn->prepare('SELECT tab_index FROM usuario_tabs WHERE usuario_id = ? ORDER BY tab_index ASC');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $tabs[] = (int)$row['tab_index'];
    }
    $stmt->close();
    if (!count($tabs)) {
        $tabs = default_tabs_for_role($user['role']);
    }

    return [
        'id' => $userId,
        'email' => $user['email'],
        'nombre' => $user['nombre'],
        'role' => $user['role'],
        'clientes' => $clientes,
        'cliente' => count($clientes) === 1 ? $clientes[0]['nombre'] : '',
        'tabs' => $tabs
    ];
}

function handle_login($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        throw new Exception('Invalid JSON input', 400);
    }

    $email = strtolower(clean_string($input['username'] ?? $input['email'] ?? ''));
    $password = clean_string($input['password'] ?? '');
    if ($email === '' || $password === '') {
        throw new Exception('Email y password son requeridos', 400);
    }

    $stmt = $conn->prepare(
        'SELECT id, email, password_hash, activo FROM usuarios WHERE LOWER(email) = ? LIMIT 1'
    );
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $res = $stmt->get_result();
    $user = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!$user || !password_verify($password, (string)$user['password_hash'])) {
        throw new Exception('Email o password incorrectos', 401);
    }
    if ((int)$user['activo'] !== 1) {
        throw new Exception('Usuario desactivado', 403);
    }

    $context = get_user_context($conn, $email);
    [$token, $expiresAt] = jwt_encode_payload([
        'sub' => (int)$context['id'],
        'email' => strtolower($context['email']),
        'role' => $context['role']
    ]);

    json_response([
        'ok' => true,
        'user' => $context,
        'token' => $token,
        'expires_at' => $expiresAt
    ]);
}

function get_request_context($conn, $input = null) {
    $token = get_bearer_token();
    if ($token === '') {
        throw new Exception('Sesion requerida', 401);
    }
    $payload = jwt_decode_payload($token);
    $email = clean_string($payload['email'] ?? '');
    if ($email === '') {
        throw new Exception('Token invalido', 401);
    }
    return get_user_context($conn, $email);
}

function allowed_cliente_ids($context) {
    if (!$context || strtolower($context['role']) === 'admin') {
        return [];
    }
    return array_values(array_filter(array_map(fn($c) => (int)($c['id'] ?? 0), $context['clientes'] ?? [])));
}

function handle_read($conn) {
    $tipo = RequestValidator::sanitizeInput($_GET['tipo'] ?? 'logistica', 'string');
    $context = get_request_context($conn);
    if (!$context) {
        throw new Exception('Sesion requerida', 401);
    }
    $allowedClienteIds = allowed_cliente_ids($context);

    if ($tipo === 'clientes') {
        if (count($allowedClienteIds)) {
            $placeholders = implode(',', array_fill(0, count($allowedClienteIds), '?'));
            $stmt = $conn->prepare("SELECT id, nombre FROM clientes WHERE activo = 1 AND id IN ($placeholders) ORDER BY nombre ASC");
            $types = str_repeat('i', count($allowedClienteIds));
            $stmt->bind_param($types, ...$allowedClienteIds);
            $stmt->execute();
            $res = $stmt->get_result();
        } else {
            $res = $conn->query('SELECT id, nombre FROM clientes WHERE activo = 1 ORDER BY nombre ASC');
        }
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = ['id' => (int)$row['id'], 'nombre' => $row['nombre']];
        }
        json_response(['ok' => true, 'data' => $rows]);
    }

    if ($tipo === 'usuarios') {
        $clienteFilterSql = '';
        if (count($allowedClienteIds)) {
            $clienteFilterSql = ' AND c.id IN (' . implode(',', array_fill(0, count($allowedClienteIds), '?')) . ')';
        }
        $sql = "SELECT u.email, u.nombre, u.role, c.nombre AS cliente, u.activo,
                       GROUP_CONCAT(ut.tab_index ORDER BY ut.tab_index SEPARATOR ',') AS tabs
                  FROM usuarios u
                  LEFT JOIN usuario_clientes uc ON uc.usuario_id = u.id
                  LEFT JOIN clientes c ON c.id = uc.cliente_id
                  LEFT JOIN usuario_tabs ut ON ut.usuario_id = u.id
                 WHERE u.role = 'lector' $clienteFilterSql
                 GROUP BY u.id, c.id
                 ORDER BY c.nombre ASC, u.email ASC";
        if (count($allowedClienteIds)) {
            $stmt = $conn->prepare($sql);
            $types = str_repeat('i', count($allowedClienteIds));
            $stmt->bind_param($types, ...$allowedClienteIds);
            $stmt->execute();
            $res = $stmt->get_result();
        } else {
            $res = $conn->query($sql);
            if (!$res) {
                throw new Exception('Error consultando usuarios: ' . $conn->error, 500);
            }
        }
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = [
                $row['email'] ?? '',
                '',
                $row['role'] ?? 'lector',
                $row['cliente'] ?? '',
                $row['tabs'] ?: '3,4,5,6,7',
                ((int)$row['activo'] === 1 ? 'TRUE' : 'FALSE'),
                $row['nombre'] ?? ''
            ];
        }
        json_response(['ok' => true, 'data' => $rows]);
    }

    if ($tipo === 'directorio_monitoreo') {
        $clienteFilterSql = '';
        if (count($allowedClienteIds)) {
            $clienteFilterSql = ' WHERE dm.cliente_id IN (' . implode(',', array_map('intval', $allowedClienteIds)) . ')';
        }

        $sql = "SELECT c.nombre AS cliente, dm.nombre, dm.cargo, dm.area,
                       dm.prioridad, dm.telefonos, dm.correos, dm.acciones,
                       dm.observaciones, dm.activo
                  FROM directorio_monitoreo dm
                  INNER JOIN clientes c ON c.id = dm.cliente_id
                 $clienteFilterSql
                 ORDER BY c.nombre ASC, dm.nombre ASC";
        $res = $conn->query($sql);
        if (!$res) {
            throw new Exception('Error consultando directorio de monitoreo: ' . $conn->error, 500);
        }

        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = [
                $row['cliente'] ?? '',
                $row['nombre'] ?? '',
                $row['cargo'] ?? '',
                $row['area'] ?? '',
                $row['prioridad'] ?? '',
                $row['telefonos'] ?? '',
                $row['correos'] ?? '',
                $row['acciones'] ?? '',
                $row['observaciones'] ?? '',
                ((int)$row['activo'] === 1 ? 'TRUE' : 'FALSE')
            ];
        }
        json_response(['ok' => true, 'data' => $rows]);
    }

    if ($tipo !== 'logistica') {
        throw new Exception('Tipo no soportado', 400);
    }

    // Filtros: siempre excluir despachos eliminados (soft-delete)
    $whereConds = ['d.eliminado_at IS NULL'];
    if (count($allowedClienteIds)) {
        $whereConds[] = 'd.cliente_id IN (' . implode(',', array_map('intval', $allowedClienteIds)) . ')';
    }
    $clienteFilterSql = ' WHERE ' . implode(' AND ', $whereConds);

    $sql = "SELECT d.fecha_programada, d.folio, u.economico, u.placas, u.equipos,
                   u.operador, u.telefonos, d.ruta, d.origen, d.lugar_carga,
                   d.destino, d.salida_patio_programada, d.cita_carga,
                   d.salida_carga_programada, d.descarga_programada,
                   d.instrucciones, c.nombre AS cliente,
                   GROUP_CONCAT(DISTINCT lu.email ORDER BY lu.email SEPARATOR ' / ') AS lectores,
                   u.id AS unidad_id, d.cliente_id, d.id AS despacho_id, d.tramo_numero
              FROM despachos d
              INNER JOIN clientes c ON c.id = d.cliente_id
              INNER JOIN unidades u ON u.id = d.unidad_id
              LEFT JOIN despacho_lectores dl ON dl.despacho_id = d.id
              LEFT JOIN usuarios lu ON lu.id = dl.usuario_id
             $clienteFilterSql
             GROUP BY d.id
             ORDER BY d.fecha_programada DESC, u.economico ASC, d.tramo_numero ASC";
    $res = $conn->query($sql);
    if (!$res) {
        throw new Exception('Error consultando logística: ' . $conn->error, 500);
    }

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = [
            $row['fecha_programada'] ?? '',
            $row['folio'] ?? '',
            $row['economico'] ?? '',
            $row['placas'] ?? '',
            $row['equipos'] ?? '',
            $row['operador'] ?? '',
            $row['telefonos'] ?? '',
            $row['ruta'] ?? '',
            $row['origen'] ?? '',
            $row['lugar_carga'] ?? '',
            $row['destino'] ?? '',
            $row['salida_patio_programada'] ?? '',
            $row['cita_carga'] ?? '',
            $row['salida_carga_programada'] ?? '',
            $row['descarga_programada'] ?? '',
            $row['instrucciones'] ?? '',
            $row['cliente'] ?? '',
            $row['lectores'] ?? '',
            isset($row['unidad_id']) ? (int)$row['unidad_id'] : 0,
            isset($row['cliente_id']) ? (int)$row['cliente_id'] : 0,
            isset($row['despacho_id']) ? (int)$row['despacho_id'] : 0,
            isset($row['tramo_numero']) ? (int)$row['tramo_numero'] : 0
        ];
    }
    json_response(['ok' => true, 'data' => $rows]);
}

function handle_write_usuarios($conn, $rows, $context = null) {
    $created = 0;
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $email = strtolower(clean_string($row[0] ?? ''));
        $password = clean_string($row[1] ?? '');
        $role = clean_string($row[2] ?? 'lector') ?: 'lector';
        $cliente = clean_string($row[3] ?? '');
        $allowedIds = allowed_cliente_ids($context);
        if (count($allowedIds) && count($context['clientes'] ?? []) === 1) {
            $cliente = $context['clientes'][0]['nombre'];
        }
        $tabs = parse_tabs($row[4] ?? '3,4,5,6,7');
        $activo = strtoupper(clean_string($row[5] ?? 'TRUE')) !== 'FALSE' ? 1 : 0;
        $nombre = clean_string($row[6] ?? '');
        if ($nombre === '' && strpos($email, '@') !== false) {
            $nombre = explode('@', $email)[0];
        }
        if ($email === '' || $password === '' || $cliente === '') {
            continue;
        }

        $clienteId = get_or_create_cliente($conn, $cliente);
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $conn->prepare(
            'INSERT INTO usuarios (email, password_hash, nombre, role, activo)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               nombre = VALUES(nombre),
               role = VALUES(role),
               activo = VALUES(activo)'
        );
        $stmt->bind_param('ssssi', $email, $hash, $nombre, $role, $activo);
        if (!$stmt->execute()) {
            throw new Exception('Error guardando lector: ' . $stmt->error, 500);
        }
        $stmt->close();

        $stmt = $conn->prepare('SELECT id FROM usuarios WHERE email = ? LIMIT 1');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $res = $stmt->get_result();
        $user = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if (!$user) {
            continue;
        }
        $userId = (int)$user['id'];

        $stmt = $conn->prepare('INSERT IGNORE INTO usuario_clientes (usuario_id, cliente_id) VALUES (?, ?)');
        $stmt->bind_param('ii', $userId, $clienteId);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare('DELETE FROM usuario_tabs WHERE usuario_id = ?');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare('INSERT INTO usuario_tabs (usuario_id, tab_index) VALUES (?, ?)');
        foreach ($tabs as $tab) {
            $stmt->bind_param('ii', $userId, $tab);
            $stmt->execute();
        }
        $stmt->close();
        $created++;
    }
    return $created;
}

function handle_write_logistica($conn, $rows, $context = null) {
    $saved = 0;
    $tramoByUnidad = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $fecha = to_mysql_date($row[0] ?? '');
        $folio = clean_string($row[1] ?? '');
        $economico = clean_string($row[2] ?? '');
        $placas = clean_string($row[3] ?? '');
        $equipos = clean_string($row[4] ?? '');
        $operador = clean_string($row[5] ?? '');
        $telefonos = clean_string($row[6] ?? '');
        $ruta = clean_string($row[7] ?? '');
        $origen = clean_string($row[8] ?? '');
        $lugarCarga = clean_string($row[9] ?? '');
        $destino = clean_string($row[10] ?? '');
        $salidaPatio = to_mysql_datetime_or_null($row[11] ?? '');
        $citaCarga = to_mysql_datetime_or_null($row[12] ?? '');
        $salidaCarga = to_mysql_datetime_or_null($row[13] ?? '');
        $descarga = to_mysql_datetime_or_null($row[14] ?? '');
        $instrucciones = clean_string($row[15] ?? '');
        $cliente = clean_string($row[16] ?? '');
        $allowedIds = allowed_cliente_ids($context);
        if (count($allowedIds) && count($context['clientes'] ?? []) === 1) {
            $cliente = $context['clientes'][0]['nombre'];
        }
        $lectorEmail = strtolower(clean_string($row[17] ?? ''));

        if ($economico === '' || $operador === '' || $cliente === '') {
            continue;
        }

        $clienteId = get_or_create_cliente($conn, $cliente);
        $unidadId = get_or_upsert_unidad($conn, $clienteId, $economico, $placas, $operador, $telefonos, $equipos);

        $key = $clienteId . '|' . $folio . '|' . $unidadId . '|' . $fecha;
        $tramoByUnidad[$key] = ($tramoByUnidad[$key] ?? 0) + 1;
        $tramoNumero = $tramoByUnidad[$key];
        $legacyKey = substr($cliente . '|' . $folio . '|' . $economico . '|' . $fecha . '|' . $tramoNumero, 0, 190);

        $stmt = $conn->prepare(
            'INSERT INTO despachos (
                cliente_id, unidad_id, folio, fecha_programada, tramo_numero,
                ruta, origen, lugar_carga, destino, instrucciones,
                salida_patio_programada, cita_carga, salida_carga_programada,
                descarga_programada, source_system, legacy_key
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "planificador", ?)
             ON DUPLICATE KEY UPDATE
                ruta = VALUES(ruta),
                origen = VALUES(origen),
                lugar_carga = VALUES(lugar_carga),
                destino = VALUES(destino),
                instrucciones = VALUES(instrucciones),
                salida_patio_programada = VALUES(salida_patio_programada),
                cita_carga = VALUES(cita_carga),
                salida_carga_programada = VALUES(salida_carga_programada),
                descarga_programada = VALUES(descarga_programada),
                legacy_key = VALUES(legacy_key)'
        );
        $stmt->bind_param(
            'iississssssssss',
            $clienteId,
            $unidadId,
            $folio,
            $fecha,
            $tramoNumero,
            $ruta,
            $origen,
            $lugarCarga,
            $destino,
            $instrucciones,
            $salidaPatio,
            $citaCarga,
            $salidaCarga,
            $descarga,
            $legacyKey
        );
        if (!$stmt->execute()) {
            throw new Exception('Error guardando despacho: ' . $stmt->error, 500);
        }
        $stmt->close();

        $stmt = $conn->prepare(
            'SELECT id FROM despachos
              WHERE cliente_id = ? AND folio = ? AND unidad_id = ? AND fecha_programada = ? AND tramo_numero = ?
              LIMIT 1'
        );
        $stmt->bind_param('isisi', $clienteId, $folio, $unidadId, $fecha, $tramoNumero);
        $stmt->execute();
        $res = $stmt->get_result();
        $despacho = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if ($despacho && $lectorEmail !== '') {
            $stmt = $conn->prepare('SELECT id FROM usuarios WHERE email = ? AND activo = 1 LIMIT 1');
            $stmt->bind_param('s', $lectorEmail);
            $stmt->execute();
            $res = $stmt->get_result();
            $lector = $res ? $res->fetch_assoc() : null;
            $stmt->close();

            if ($lector) {
                $despachoId = (int)$despacho['id'];
                $lectorId = (int)$lector['id'];
                $stmt = $conn->prepare('INSERT IGNORE INTO despacho_lectores (despacho_id, usuario_id) VALUES (?, ?)');
                $stmt->bind_param('ii', $despachoId, $lectorId);
                $stmt->execute();
                $stmt->close();
            }
        }

        $saved++;
    }
    return $saved;
}

function handle_write_directorio_monitoreo($conn, $rows, $context = null) {
    $saved = 0;

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $cliente = clean_string($row[0] ?? '');
        $allowedIds = allowed_cliente_ids($context);
        if (count($allowedIds) && count($context['clientes'] ?? []) === 1) {
            $cliente = $context['clientes'][0]['nombre'];
        }

        $nombre = clean_string($row[1] ?? '');
        $cargo = clean_string($row[2] ?? '');
        $area = clean_string($row[3] ?? '');
        $prioridad = clean_string($row[4] ?? '');
        $telefonos = clean_string($row[5] ?? '');
        $correos = strtolower(clean_string($row[6] ?? ''));
        $acciones = clean_string($row[7] ?? '');
        $observaciones = clean_string($row[8] ?? '');
        $activo = strtoupper(clean_string($row[9] ?? 'TRUE')) !== 'FALSE' ? 1 : 0;

        if ($cliente === '' || $nombre === '') {
            continue;
        }

        $clienteId = get_or_create_cliente($conn, $cliente);
        if (count($allowedIds) && !in_array($clienteId, $allowedIds, true)) {
            throw new Exception('No tienes permiso para modificar este cliente', 403);
        }

        $dedupeValue = $correos !== '' ? $correos : ($telefonos !== '' ? $telefonos : $nombre);
        $legacyKey = substr($clienteId . '|' . strtolower($dedupeValue), 0, 190);

        $stmt = $conn->prepare(
            'INSERT INTO directorio_monitoreo (
                cliente_id, nombre, cargo, area, prioridad,
                telefonos, correos, acciones, observaciones, activo, legacy_key
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                nombre = VALUES(nombre),
                cargo = VALUES(cargo),
                area = VALUES(area),
                prioridad = VALUES(prioridad),
                telefonos = VALUES(telefonos),
                correos = VALUES(correos),
                acciones = VALUES(acciones),
                observaciones = VALUES(observaciones),
                activo = VALUES(activo)'
        );
        $stmt->bind_param(
            'issssssssis',
            $clienteId,
            $nombre,
            $cargo,
            $area,
            $prioridad,
            $telefonos,
            $correos,
            $acciones,
            $observaciones,
            $activo,
            $legacyKey
        );
        if (!$stmt->execute()) {
            throw new Exception('Error guardando directorio de monitoreo: ' . $stmt->error, 500);
        }
        $stmt->close();
        $saved++;
    }

    return $saved;
}

function handle_write($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        throw new Exception('Invalid JSON input', 400);
    }
    $tipo = RequestValidator::sanitizeInput($input['tipo'] ?? '', 'string');
    $rows = $input['rows'] ?? [];
    $rows = RequestValidator::validateRows($rows);
    $context = get_request_context($conn, $input);
    if (!$context) {
        throw new Exception('Sesion requerida', 401);
    }

    $conn->begin_transaction();
    try {
        if ($tipo === 'usuarios') {
            $count = handle_write_usuarios($conn, $rows, $context);
        } elseif ($tipo === 'logistica') {
            $count = handle_write_logistica($conn, $rows, $context);
        } elseif ($tipo === 'directorio_monitoreo') {
            $count = handle_write_directorio_monitoreo($conn, $rows, $context);
        } else {
            throw new Exception('Tipo no soportado', 400);
        }
        $conn->commit();
        json_response(['ok' => true, 'count' => $count]);
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }
}

try {
    RequestValidator::validateRequest();
    $conn = getDbConnection();
    $action = RequestValidator::sanitizeInput($_GET['action'] ?? 'write', 'string');

    if ($action === 'login') {
        handle_login($conn);
    } elseif ($action === 'read') {
        handle_read($conn);
    } elseif ($action === 'write') {
        handle_write($conn);
    } else {
        throw new Exception('Invalid action', 400);
    }
} catch (Exception $e) {
    $code = is_numeric($e->getCode()) && $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
    json_response(['ok' => false, 'error' => $e->getMessage()], $code);
} finally {
    if (isset($conn) && $conn) {
        $conn->close();
    }
}
