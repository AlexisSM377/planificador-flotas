<?php

/**
 * API: viajes.php
 * Gestión de viajes multi-tramo y multi-día.
 *
 * Endpoints (todos requieren Authorization: Bearer <token>):
 *
 *   POST   ?action=crear             → crea viaje completo con sus tramos (transacción)
 *   GET    ?action=listar            → viajes por cliente_id, rango de fechas, unidad
 *   GET    ?action=obtener           → un viaje con todos sus tramos
 *   PUT    ?action=actualizar        → edita cabecera del viaje
 *   POST   ?action=agregar_tramo     → agrega tramo a viaje existente
 *   PUT    ?action=actualizar_tramo  → edita un tramo
 *   DELETE ?action=eliminar_tramo    → cancela un tramo (soft: estado='cancelado')
 *   DELETE ?action=eliminar_viaje    → cancela el viaje completo
 *   GET    ?action=unidades          → lista unidades del cliente (autocomplete)
 *   POST   ?action=duplicar          → clona viaje a nueva fecha (desplaza tramos)
 */

while (ob_get_level()) { ob_end_clean(); }
ob_start();

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../config.php';

header_remove();
header('Content-Type: application/json; charset=utf-8');

// ---------------------------------------------------------------------------
// Utilidades base (mismos patrones que planificador.php)
// ---------------------------------------------------------------------------

function json_ok($data = [], $code = 200) {
    while (ob_get_level()) { ob_end_clean(); }
    header_remove();
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode(array_merge(['ok' => true], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

function json_err($msg, $code = 400) {
    while (ob_get_level()) { ob_end_clean(); }
    header_remove();
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function str_val($v) {
    return trim((string)($v ?? ''));
}

function null_if_empty($v) {
    $s = str_val($v);
    return $s === '' ? null : $s;
}

// ---------------------------------------------------------------------------
// JWT (idéntico a planificador.php)
// ---------------------------------------------------------------------------

function base64url_encode_v($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode_v($data) {
    $pad = 4 - (strlen($data) % 4);
    if ($pad < 4) $data .= str_repeat('=', $pad);
    return base64_decode(strtr($data, '-_', '+/'));
}

function get_jwt_secret_v() {
    $s = getenv('JWT_SECRET') ?: (defined('API_KEY') ? API_KEY : '');
    if ($s === '' || (defined('ENVIRONMENT') && ENVIRONMENT !== 'development' && strlen($s) < 32)) {
        throw new Exception('JWT_SECRET no configurado', 500);
    }
    return $s;
}

function jwt_decode_v($token) {
    $parts = explode('.', trim((string)$token));
    if (count($parts) !== 3) throw new Exception('Token invalido', 401);
    [$h, $p, $sig] = $parts;
    $header  = json_decode(base64url_decode_v($h), true);
    $payload = json_decode(base64url_decode_v($p), true);
    if (!is_array($header) || !is_array($payload) || ($header['alg'] ?? '') !== 'HS256') {
        throw new Exception('Token invalido', 401);
    }
    $expected = base64url_encode_v(hash_hmac('sha256', "$h.$p", get_jwt_secret_v(), true));
    if (!hash_equals($expected, $sig)) throw new Exception('Token invalido', 401);
    $now = time();
    if (isset($payload['nbf']) && (int)$payload['nbf'] > $now) throw new Exception('Token aun no valido', 401);
    if (!isset($payload['exp']) || (int)$payload['exp'] < $now) throw new Exception('Sesion expirada', 401);
    return $payload;
}

function get_bearer_token_v() {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($h === '' && function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $k => $v) {
            if (strtolower($k) === 'authorization') { $h = $v; break; }
        }
    }
    return preg_match('/Bearer\s+(.+)/i', $h, $m) ? trim($m[1]) : '';
}

// ---------------------------------------------------------------------------
// Contexto de usuario y permisos de tenant
// ---------------------------------------------------------------------------

function get_usuario_context($conn) {
    $token = get_bearer_token_v();
    if ($token === '') throw new Exception('Sesion requerida', 401);

    $payload = jwt_decode_v($token);
    $email   = strtolower(str_val($payload['email'] ?? ''));
    if ($email === '') throw new Exception('Token invalido', 401);

    $stmt = $conn->prepare(
        'SELECT id, email, nombre, role, activo FROM usuarios WHERE LOWER(email) = ? LIMIT 1'
    );
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user || (int)$user['activo'] !== 1) throw new Exception('Sesion invalida', 401);

    $uid     = (int)$user['id'];
    $role    = strtolower($user['role']);
    $clientes = [];

    if ($role === 'admin') {
        $res = $conn->query('SELECT id, nombre FROM clientes WHERE activo = 1 ORDER BY nombre ASC');
        while ($r = $res->fetch_assoc()) $clientes[] = ['id' => (int)$r['id'], 'nombre' => $r['nombre']];
    } else {
        $stmt = $conn->prepare(
            'SELECT c.id, c.nombre
               FROM usuario_clientes uc
               JOIN clientes c ON c.id = uc.cliente_id
              WHERE uc.usuario_id = ? AND c.activo = 1
              ORDER BY c.nombre ASC'
        );
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) $clientes[] = ['id' => (int)$r['id'], 'nombre' => $r['nombre']];
        $stmt->close();
    }

    return [
        'id'       => $uid,
        'email'    => $user['email'],
        'role'     => $role,
        'clientes' => $clientes,
        // IDs permitidos; vacío = admin (acceso total)
        'ids'      => $role === 'admin'
                        ? []
                        : array_column($clientes, 'id'),
    ];
}

/**
 * Verifica que el usuario tenga acceso al cliente_id dado.
 * Lanza excepción si no.
 */
function assert_cliente_access($ctx, $cliente_id) {
    $cliente_id = (int)$cliente_id;
    if ($ctx['role'] !== 'admin' && !in_array($cliente_id, $ctx['ids'], true)) {
        throw new Exception('Sin acceso a ese cliente', 403);
    }
}

/**
 * Verifica que el usuario no sea solo lector.
 */
function assert_can_write($ctx) {
    if ($ctx['role'] === 'lector') {
        throw new Exception('Sin permisos de escritura', 403);
    }
}

/**
 * Devuelve la cláusula WHERE + params para filtrar por cliente_ids del tenant.
 * Si es admin y no se pasa cliente_id, se filtra por todos sus clientes.
 */
function cliente_where($ctx, $cliente_id_param = null) {
    if ($cliente_id_param !== null) {
        $cid = (int)$cliente_id_param;
        assert_cliente_access($ctx, $cid);
        return ['sql' => 'v.cliente_id = ?', 'types' => 'i', 'params' => [$cid]];
    }
    if ($ctx['role'] === 'admin') {
        // Sin restricción de cliente
        return ['sql' => '1=1', 'types' => '', 'params' => []];
    }
    $ids = $ctx['ids'];
    if (!count($ids)) throw new Exception('Sin clientes asignados', 403);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    return [
        'sql'    => "v.cliente_id IN ($ph)",
        'types'  => str_repeat('i', count($ids)),
        'params' => $ids,
    ];
}

// ---------------------------------------------------------------------------
// Helpers de fecha / datetime
// ---------------------------------------------------------------------------

function to_date($v) {
    $s = str_val($v);
    if ($s === '') return null;
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $s)) return substr($s, 0, 10);
    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})/', $s, $m)) return "{$m[3]}-{$m[2]}-{$m[1]}";
    try { return (new DateTime($s))->format('Y-m-d'); } catch (Exception $e) { return null; }
}

function to_datetime($v) {
    $s = str_val($v);
    if ($s === '') return null;
    if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $s)) {
        return str_replace('T', ' ', substr($s, 0, 19));
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $s)) return substr($s, 0, 19);
    try { return (new DateTime($s))->format('Y-m-d H:i:s'); } catch (Exception $e) { return null; }
}

// ---------------------------------------------------------------------------
// Helpers de tramos
// ---------------------------------------------------------------------------

function map_tramo_row($r) {
    return [
        'id'                => (int)$r['id'],
        'viaje_id'          => (int)$r['viaje_id'],
        'tramo_numero'      => (int)$r['tramo_numero'],
        'origen'            => $r['origen'],
        'lugar_carga'       => $r['lugar_carga'],
        'destino'           => $r['destino'],
        'ruta'              => $r['ruta'],
        'instrucciones'     => $r['instrucciones'],
        'salida_patio'      => $r['salida_patio'],
        'cita_carga'        => $r['cita_carga'],
        'salida_carga'      => $r['salida_carga'],
        'descarga_programada' => $r['descarga_programada'],
        'estado'            => $r['estado'],
        'created_at'        => $r['created_at'],
        'updated_at'        => $r['updated_at'],
    ];
}

function map_viaje_row($r) {
    return [
        'id'                    => (int)$r['id'],
        'cliente_id'            => (int)$r['cliente_id'],
        'cliente_nombre'        => $r['cliente_nombre'] ?? null,
        'unidad_id'             => (int)$r['unidad_id'],
        'economico'             => $r['economico'] ?? null,
        'operador'              => $r['operador'] ?? null,
        'folio'                 => $r['folio'],
        'fecha_inicio'          => $r['fecha_inicio'],
        'fecha_fin'             => $r['fecha_fin'],
        'estado'                => $r['estado'],
        'notas'                 => $r['notas'],
        'created_by_usuario_id' => $r['created_by_usuario_id'] ? (int)$r['created_by_usuario_id'] : null,
        'created_at'            => $r['created_at'],
        'updated_at'            => $r['updated_at'],
    ];
}

function insertar_tramo($conn, $viaje_id, $num, $t) {
    $stmt = $conn->prepare(
        'INSERT INTO viaje_tramos
           (viaje_id, tramo_numero, origen, lugar_carga, destino, ruta,
            instrucciones, salida_patio, cita_carga, salida_carga, descarga_programada, estado)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $origen      = null_if_empty($t['origen'] ?? '');
    $lc          = null_if_empty($t['lugar_carga'] ?? '');
    $destino     = null_if_empty($t['destino'] ?? '');
    $ruta        = null_if_empty($t['ruta'] ?? '');
    $instr       = null_if_empty($t['instrucciones'] ?? '');
    $salida_p    = to_datetime($t['salida_patio'] ?? '');
    $cita        = to_datetime($t['cita_carga'] ?? '');
    $salida_c    = to_datetime($t['salida_carga'] ?? '');
    $descarga    = to_datetime($t['descarga_programada'] ?? '');
    $estado      = 'pendiente';

    $stmt->bind_param(
        'iissssssssss',
        $viaje_id, $num,
        $origen, $lc, $destino, $ruta, $instr,
        $salida_p, $cita, $salida_c, $descarga,
        $estado
    );
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new Exception('Error insertando tramo: ' . $err, 500);
    }
    $id = (int)$stmt->insert_id;
    $stmt->close();
    return $id;
}

// ---------------------------------------------------------------------------
// Compatibilidad: sincronizar tramos a tabla despachos (tab "Unidades en Sesión")
// ---------------------------------------------------------------------------

/**
 * Inserta (o actualiza) cada tramo del viaje en la tabla `despachos`
 * para que sigan apareciendo en la tab "Unidades en Sesión" del planificador.
 * Usa el mismo patrón de legacy_key que planificador.php.
 */
function sincronizar_despachos($conn, $viaje_id, $cliente_id, $unidad_id, $folio, $fecha_inicio, $tramos, $cliente_nombre) {
    foreach ($tramos as $idx => $t) {
        $tramo_numero = (int)($t['tramo_numero'] ?? ($idx + 1));
        $legacy_key   = substr(
            $cliente_nombre . '|' . $folio . '|' . $unidad_id . '|' . $fecha_inicio . '|' . $tramo_numero,
            0, 190
        );

        $stmt = $conn->prepare(
            'INSERT INTO despachos (
                cliente_id, unidad_id, folio, fecha_programada, tramo_numero,
                ruta, origen, lugar_carga, destino, instrucciones,
                salida_patio_programada, cita_carga, salida_carga_programada,
                descarga_programada, source_system, legacy_key
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "planificador", ?)
             ON DUPLICATE KEY UPDATE
                ruta                    = VALUES(ruta),
                origen                  = VALUES(origen),
                lugar_carga             = VALUES(lugar_carga),
                destino                 = VALUES(destino),
                instrucciones           = VALUES(instrucciones),
                salida_patio_programada = VALUES(salida_patio_programada),
                cita_carga              = VALUES(cita_carga),
                salida_carga_programada = VALUES(salida_carga_programada),
                descarga_programada     = VALUES(descarga_programada),
                legacy_key              = VALUES(legacy_key)'
        );

        $ruta        = null_if_empty($t['ruta']              ?? '');
        $origen      = null_if_empty($t['origen']            ?? '');
        $lugar_carga = null_if_empty($t['lugar_carga']       ?? '');
        $destino     = null_if_empty($t['destino']           ?? '');
        $instruc     = null_if_empty($t['instrucciones']     ?? '');
        $sal_patio   = to_datetime($t['salida_patio']        ?? '') ?: null;
        $cita        = to_datetime($t['cita_carga']          ?? '') ?: null;
        $sal_carga   = to_datetime($t['salida_carga']        ?? '') ?: null;
        $descarga    = to_datetime($t['descarga_programada'] ?? '') ?: null;

        $stmt->bind_param(
            'iississssssssss',
            $cliente_id, $unidad_id, $folio, $fecha_inicio, $tramo_numero,
            $ruta, $origen, $lugar_carga, $destino, $instruc,
            $sal_patio, $cita, $sal_carga, $descarga,
            $legacy_key
        );
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new Exception('Error sincronizando despacho: ' . $err, 500);
        }
        $stmt->close();
    }
}

// ---------------------------------------------------------------------------
// Upsert de unidad (crea si no existe, actualiza si ya existe)
// ---------------------------------------------------------------------------

/**
 * Inserta o actualiza una unidad para el cliente dado.
 * Usa UNIQUE KEY (cliente_id, economico) para el ON DUPLICATE KEY UPDATE.
 * Devuelve el id de la unidad.
 */
function upsert_unidad($conn, $cliente_id, $economico, $placas, $operador, $telefonos, $equipos) {
    $stmt = $conn->prepare(
        'INSERT INTO unidades (cliente_id, economico, placas, operador, telefonos, equipos, activo)
         VALUES (?, ?, ?, ?, ?, ?, 1)
         ON DUPLICATE KEY UPDATE
           placas    = IF(VALUES(placas)    != \'\', VALUES(placas),    placas),
           operador  = IF(VALUES(operador)  != \'\', VALUES(operador),  operador),
           telefonos = IF(VALUES(telefonos) != \'\', VALUES(telefonos), telefonos),
           equipos   = IF(VALUES(equipos)   != \'\', VALUES(equipos),   equipos),
           activo    = 1'
    );
    $stmt->bind_param('isssss', $cliente_id, $economico, $placas, $operador, $telefonos, $equipos);
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new Exception('Error guardando unidad: ' . $err, 500);
    }
    $stmt->close();

    $stmt = $conn->prepare('SELECT id FROM unidades WHERE cliente_id = ? AND economico = ? LIMIT 1');
    $stmt->bind_param('is', $cliente_id, $economico);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) throw new Exception('No se pudo resolver la unidad tras el upsert', 500);
    return (int)$row['id'];
}

// ---------------------------------------------------------------------------
// HANDLERS
// ---------------------------------------------------------------------------

/**
 * POST ?action=crear
 * Body JSON:
 * {
 *   "cliente_id": 1,
 *   "unidad_id": 5,
 *   "folio": "FOL-001",
 *   "fecha_inicio": "2026-05-25",
 *   "fecha_fin": "2026-05-26",     // opcional
 *   "notas": "...",                // opcional
 *   "tramos": [
 *     {
 *       "origen": "CDMX",
 *       "lugar_carga": "Bodega Norte",
 *       "destino": "Puebla",
 *       "ruta": "MEX-150D",
 *       "instrucciones": "...",
 *       "salida_patio": "2026-05-25T08:00",
 *       "cita_carga": "2026-05-25T09:00",
 *       "salida_carga": "2026-05-25T10:00",
 *       "descarga_programada": "2026-05-25T14:00"
 *     }
 *   ]
 * }
 */
function handle_crear($conn, $ctx) {
    assert_can_write($ctx);

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) throw new Exception('JSON invalido', 400);

    $cliente_id   = (int)($body['cliente_id']  ?? 0);
    $folio        = str_val($body['folio']      ?? '');
    $fecha_inicio = to_date($body['fecha_inicio'] ?? '');
    $fecha_fin    = to_date($body['fecha_fin']  ?? '');
    $notas        = null_if_empty($body['notas'] ?? '');
    $tramos       = $body['tramos'] ?? [];

    // Datos de la unidad (pueden venir del formulario si es unidad nueva)
    $economico    = str_val($body['economico']  ?? '');
    $placas       = str_val($body['placas']     ?? '');
    $operador     = str_val($body['operador']   ?? '');
    $telefonos    = str_val($body['telefonos']  ?? '');
    $equipos      = str_val($body['equipos']    ?? '');

    if ($cliente_id <= 0)  throw new Exception('cliente_id requerido', 400);
    if ($folio === '')     throw new Exception('folio requerido', 400);
    if (!$fecha_inicio)    throw new Exception('fecha_inicio requerida (YYYY-MM-DD)', 400);
    if (!is_array($tramos) || count($tramos) === 0) {
        throw new Exception('Se requiere al menos un tramo', 400);
    }

    assert_cliente_access($ctx, $cliente_id);

    // ── Resolver unidad_id ────────────────────────────────────────────────────
    // Si el frontend ya resolvió el ID (unidad existente), usarlo directamente.
    // Si no (unidad nueva), hacer upsert con los datos del formulario.
    $unidad_id = (int)($body['unidad_id'] ?? 0);

    if ($unidad_id > 0) {
        // Verificar que la unidad pertenece al cliente
        $stmt = $conn->prepare('SELECT id FROM unidades WHERE id = ? AND cliente_id = ? AND activo = 1 LIMIT 1');
        $stmt->bind_param('ii', $unidad_id, $cliente_id);
        $stmt->execute();
        $existe = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$existe) throw new Exception('La unidad no pertenece al cliente indicado', 400);
    } else {
        // Unidad nueva o no resuelta por el frontend: upsert por económico
        if ($economico === '') throw new Exception('economico requerido para registrar una unidad nueva', 400);
        $unidad_id = upsert_unidad($conn, $cliente_id, $economico, $placas, $operador, $telefonos, $equipos);
    }

    // Detectar conflictos de solapamiento con viajes activos de la misma unidad
    $conflictos = detectar_conflictos($conn, $unidad_id, $fecha_inicio, $fecha_fin);
    if (count($conflictos) > 0) {
        // Retornar 409 Conflict con detalle de los viajes que se solapan
        json_err_conflicto($conflictos);
    }

    // Transacción: viaje + todos sus tramos
    $conn->begin_transaction();
    try {
        $uid = $ctx['id'];
        $stmt = $conn->prepare(
            'INSERT INTO viajes
               (cliente_id, unidad_id, folio, fecha_inicio, fecha_fin, estado, notas, created_by_usuario_id)
             VALUES (?, ?, ?, ?, ?, \'planificado\', ?, ?)'
        );
        $stmt->bind_param('iisssis', $cliente_id, $unidad_id, $folio, $fecha_inicio, $fecha_fin, $notas, $uid);
        if (!$stmt->execute()) throw new Exception('Error creando viaje: ' . $stmt->error, 500);
        $viaje_id = (int)$stmt->insert_id;
        $stmt->close();

        foreach ($tramos as $i => $t) {
            insertar_tramo($conn, $viaje_id, $i + 1, $t);
        }

        // Sincronizar a tabla despachos para compatibilidad con "Unidades en Sesión"
        $stmt_cn = $conn->prepare('SELECT nombre FROM clientes WHERE id = ? LIMIT 1');
        $stmt_cn->bind_param('i', $cliente_id);
        $stmt_cn->execute();
        $cliente_nombre = $stmt_cn->get_result()->fetch_assoc()['nombre'] ?? '';
        $stmt_cn->close();

        // Preparar tramos con tramo_numero para sincronizar
        $tramos_sync = array_values(array_map(function($t, $i) {
            return array_merge($t, ['tramo_numero' => $i + 1]);
        }, $tramos, array_keys($tramos)));

        sincronizar_despachos($conn, $viaje_id, $cliente_id, $unidad_id, $folio, $fecha_inicio, $tramos_sync, $cliente_nombre);

        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

    // Devolver viaje recién creado con sus tramos
    json_ok(handle_obtener_data($conn, $viaje_id), 201);
}

/**
 * GET ?action=listar
 * Params opcionales:
 *   cliente_id, unidad_id, fecha_desde, fecha_hasta, estado, limit, offset
 */
function handle_listar($conn, $ctx) {
    $cliente_id  = isset($_GET['cliente_id']) ? (int)$_GET['cliente_id'] : null;
    $unidad_id   = isset($_GET['unidad_id'])  ? (int)$_GET['unidad_id']  : null;
    $fecha_desde = to_date($_GET['fecha_desde'] ?? '');
    $fecha_hasta = to_date($_GET['fecha_hasta'] ?? '');
    $estado_f    = str_val($_GET['estado'] ?? '');
    $limit       = min((int)($_GET['limit'] ?? 50), 200);
    $offset      = max((int)($_GET['offset'] ?? 0), 0);

    $cw    = cliente_where($ctx, $cliente_id);
    $where = [$cw['sql']];
    $types = $cw['types'];
    $params = $cw['params'];

    if ($unidad_id) {
        $where[] = 'v.unidad_id = ?';
        $types   .= 'i';
        $params[] = $unidad_id;
    }

    // Rango de fechas: captura viajes multi-día que se solapen con el rango
    if ($fecha_desde) {
        $where[] = '(v.fecha_fin >= ? OR (v.fecha_fin IS NULL AND v.fecha_inicio >= ?))';
        $types   .= 'ss';
        $params[] = $fecha_desde;
        $params[] = $fecha_desde;
    }
    if ($fecha_hasta) {
        $where[] = 'v.fecha_inicio <= ?';
        $types   .= 's';
        $params[] = $fecha_hasta;
    }
    if (in_array($estado_f, ['planificado','en_curso','completado','cancelado'], true)) {
        $where[] = 'v.estado = ?';
        $types   .= 's';
        $params[] = $estado_f;
    }

    $where_sql = implode(' AND ', $where);

    $sql = "SELECT
                v.id, v.cliente_id, c.nombre AS cliente_nombre,
                v.unidad_id, u.economico, u.operador,
                v.folio, v.fecha_inicio, v.fecha_fin,
                v.estado, v.notas,
                v.created_by_usuario_id, v.created_at, v.updated_at,
                COUNT(vt.id) AS total_tramos,
                MIN(vt.salida_patio) AS primera_salida,
                MAX(vt.descarga_programada) AS ultima_descarga
            FROM viajes v
            JOIN clientes c ON c.id = v.cliente_id
            JOIN unidades u ON u.id = v.unidad_id
            LEFT JOIN viaje_tramos vt ON vt.viaje_id = v.id AND vt.estado != 'cancelado'
            WHERE $where_sql
            GROUP BY v.id
            ORDER BY v.fecha_inicio DESC, v.created_at DESC
            LIMIT ? OFFSET ?";

    $types   .= 'ii';
    $params[] = $limit;
    $params[] = $offset;

    $stmt = $conn->prepare($sql);
    if (count($params)) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    $viajes = [];
    while ($r = $res->fetch_assoc()) {
        $v = map_viaje_row($r);
        $v['total_tramos']    = (int)$r['total_tramos'];
        $v['primera_salida']  = $r['primera_salida'];
        $v['ultima_descarga'] = $r['ultima_descarga'];
        $viajes[] = $v;
    }
    $stmt->close();

    // Total sin paginación
    $sql_count = "SELECT COUNT(DISTINCT v.id) AS total
                  FROM viajes v
                  WHERE $where_sql";
    $count_params = array_slice($params, 0, -2);
    $count_types  = substr($types, 0, -2);
    $stmt = $conn->prepare($sql_count);
    if (count($count_params)) $stmt->bind_param($count_types, ...$count_params);
    $stmt->execute();
    $total = (int)$stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();

    json_ok(['viajes' => $viajes, 'total' => $total, 'limit' => $limit, 'offset' => $offset]);
}

/**
 * GET ?action=obtener&viaje_id=X
 */
function handle_obtener($conn, $ctx) {
    $viaje_id = (int)($_GET['viaje_id'] ?? 0);
    if ($viaje_id <= 0) throw new Exception('viaje_id requerido', 400);
    json_ok(handle_obtener_data($conn, $viaje_id, $ctx));
}

function handle_obtener_data($conn, $viaje_id, $ctx = null) {
    $stmt = $conn->prepare(
        'SELECT v.*, c.nombre AS cliente_nombre, u.economico, u.operador
           FROM viajes v
           JOIN clientes c ON c.id = v.cliente_id
           JOIN unidades u ON u.id = v.unidad_id
          WHERE v.id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $viaje_id);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$r) throw new Exception('Viaje no encontrado', 404);
    if ($ctx) assert_cliente_access($ctx, $r['cliente_id']);

    $viaje = map_viaje_row($r);

    // Tramos
    $stmt = $conn->prepare(
        'SELECT * FROM viaje_tramos WHERE viaje_id = ? ORDER BY tramo_numero ASC'
    );
    $stmt->bind_param('i', $viaje_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $tramos = [];
    while ($t = $res->fetch_assoc()) $tramos[] = map_tramo_row($t);
    $stmt->close();

    $viaje['tramos'] = $tramos;
    return ['viaje' => $viaje];
}

/**
 * PUT ?action=actualizar
 * Body JSON: { viaje_id, folio?, fecha_inicio?, fecha_fin?, estado?, notas? }
 */
function handle_actualizar($conn, $ctx) {
    assert_can_write($ctx);

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) throw new Exception('JSON invalido', 400);

    $viaje_id = (int)($body['viaje_id'] ?? 0);
    if ($viaje_id <= 0) throw new Exception('viaje_id requerido', 400);

    // Verificar existencia y acceso
    $stmt = $conn->prepare('SELECT cliente_id FROM viajes WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $viaje_id);
    $stmt->execute();
    $v = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$v) throw new Exception('Viaje no encontrado', 404);
    assert_cliente_access($ctx, $v['cliente_id']);

    // Construir SET dinámico solo con campos enviados
    $sets   = [];
    $types  = '';
    $params = [];

    $estados_validos = ['planificado','en_curso','completado','cancelado'];

    if (array_key_exists('folio', $body)) {
        $sets[] = 'folio = ?'; $types .= 's';
        $params[] = str_val($body['folio']);
    }
    if (array_key_exists('fecha_inicio', $body)) {
        $d = to_date($body['fecha_inicio']);
        if (!$d) throw new Exception('fecha_inicio invalida', 400);
        $sets[] = 'fecha_inicio = ?'; $types .= 's'; $params[] = $d;
    }
    if (array_key_exists('fecha_fin', $body)) {
        $sets[] = 'fecha_fin = ?'; $types .= 's';
        $params[] = null_if_empty($body['fecha_fin']) ? to_date($body['fecha_fin']) : null;
    }
    if (array_key_exists('estado', $body)) {
        if (!in_array($body['estado'], $estados_validos, true)) {
            throw new Exception('estado invalido. Valores: ' . implode(', ', $estados_validos), 400);
        }
        $sets[] = 'estado = ?'; $types .= 's'; $params[] = $body['estado'];
    }
    if (array_key_exists('notas', $body)) {
        $sets[] = 'notas = ?'; $types .= 's';
        $params[] = null_if_empty($body['notas']);
    }

    if (!count($sets)) throw new Exception('No hay campos para actualizar', 400);

    $types   .= 'i';
    $params[] = $viaje_id;

    $stmt = $conn->prepare('UPDATE viajes SET ' . implode(', ', $sets) . ' WHERE id = ?');
    $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) throw new Exception('Error actualizando viaje: ' . $stmt->error, 500);
    $stmt->close();

    json_ok(handle_obtener_data($conn, $viaje_id));
}

/**
 * POST ?action=agregar_tramo
 * Body JSON: { viaje_id, tramo: { origen, destino, ... } }
 */
function handle_agregar_tramo($conn, $ctx) {
    assert_can_write($ctx);

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) throw new Exception('JSON invalido', 400);

    $viaje_id = (int)($body['viaje_id'] ?? 0);
    if ($viaje_id <= 0) throw new Exception('viaje_id requerido', 400);

    $stmt = $conn->prepare('SELECT cliente_id FROM viajes WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $viaje_id);
    $stmt->execute();
    $v = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$v) throw new Exception('Viaje no encontrado', 404);
    assert_cliente_access($ctx, $v['cliente_id']);

    // Calcular siguiente número de tramo
    $stmt = $conn->prepare('SELECT COALESCE(MAX(tramo_numero), 0) + 1 AS siguiente FROM viaje_tramos WHERE viaje_id = ?');
    $stmt->bind_param('i', $viaje_id);
    $stmt->execute();
    $siguiente = (int)$stmt->get_result()->fetch_assoc()['siguiente'];
    $stmt->close();

    $tramo_data = $body['tramo'] ?? [];
    if (!is_array($tramo_data)) throw new Exception('tramo debe ser un objeto', 400);

    $tramo_id = insertar_tramo($conn, $viaje_id, $siguiente, $tramo_data);

    // Devolver el tramo creado
    $stmt = $conn->prepare('SELECT * FROM viaje_tramos WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $tramo_id);
    $stmt->execute();
    $t = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    json_ok(['tramo' => map_tramo_row($t)], 201);
}

/**
 * PUT ?action=actualizar_tramo
 * Body JSON: { tramo_id, origen?, lugar_carga?, destino?, ruta?, instrucciones?,
 *              salida_patio?, cita_carga?, salida_carga?, descarga_programada?, estado? }
 */
function handle_actualizar_tramo($conn, $ctx) {
    assert_can_write($ctx);

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) throw new Exception('JSON invalido', 400);

    $tramo_id = (int)($body['tramo_id'] ?? 0);
    if ($tramo_id <= 0) throw new Exception('tramo_id requerido', 400);

    // Verificar existencia y acceso vía viaje
    $stmt = $conn->prepare(
        'SELECT vt.viaje_id, v.cliente_id
           FROM viaje_tramos vt
           JOIN viajes v ON v.id = vt.viaje_id
          WHERE vt.id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $tramo_id);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$r) throw new Exception('Tramo no encontrado', 404);
    assert_cliente_access($ctx, $r['cliente_id']);

    $estados_validos = ['pendiente','en_curso','completado','cancelado'];
    $campos_texto    = ['origen','lugar_carga','destino','ruta','instrucciones'];
    $campos_dt       = ['salida_patio','cita_carga','salida_carga','descarga_programada'];

    $sets   = [];
    $types  = '';
    $params = [];

    foreach ($campos_texto as $campo) {
        if (array_key_exists($campo, $body)) {
            $sets[] = "$campo = ?"; $types .= 's';
            $params[] = null_if_empty($body[$campo]);
        }
    }
    foreach ($campos_dt as $campo) {
        if (array_key_exists($campo, $body)) {
            $sets[] = "$campo = ?"; $types .= 's';
            $params[] = null_if_empty($body[$campo]) ? to_datetime($body[$campo]) : null;
        }
    }
    if (array_key_exists('estado', $body)) {
        if (!in_array($body['estado'], $estados_validos, true)) {
            throw new Exception('estado invalido. Valores: ' . implode(', ', $estados_validos), 400);
        }
        $sets[] = 'estado = ?'; $types .= 's'; $params[] = $body['estado'];
    }

    if (!count($sets)) throw new Exception('No hay campos para actualizar', 400);

    $types   .= 'i';
    $params[] = $tramo_id;

    $stmt = $conn->prepare('UPDATE viaje_tramos SET ' . implode(', ', $sets) . ' WHERE id = ?');
    $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) throw new Exception('Error actualizando tramo: ' . $stmt->error, 500);
    $stmt->close();

    $stmt = $conn->prepare('SELECT * FROM viaje_tramos WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $tramo_id);
    $stmt->execute();
    $t = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    json_ok(['tramo' => map_tramo_row($t)]);
}

/**
 * DELETE ?action=eliminar_tramo
 * Body JSON: { tramo_id, motivo? }
 * Soft delete: cambia estado a 'cancelado'
 */
function handle_eliminar_tramo($conn, $ctx) {
    assert_can_write($ctx);

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) throw new Exception('JSON invalido', 400);

    $tramo_id = (int)($body['tramo_id'] ?? 0);
    if ($tramo_id <= 0) throw new Exception('tramo_id requerido', 400);

    $stmt = $conn->prepare(
        'SELECT vt.viaje_id, vt.estado, v.cliente_id
           FROM viaje_tramos vt
           JOIN viajes v ON v.id = vt.viaje_id
          WHERE vt.id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $tramo_id);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$r) throw new Exception('Tramo no encontrado', 404);
    assert_cliente_access($ctx, $r['cliente_id']);

    // Idempotente
    if ($r['estado'] === 'cancelado') {
        json_ok(['tramo_id' => $tramo_id, 'estado' => 'cancelado', 'msg' => 'Ya estaba cancelado']);
    }

    $stmt = $conn->prepare("UPDATE viaje_tramos SET estado = 'cancelado' WHERE id = ?");
    $stmt->bind_param('i', $tramo_id);
    if (!$stmt->execute()) throw new Exception('Error cancelando tramo: ' . $stmt->error, 500);
    $stmt->close();

    json_ok(['tramo_id' => $tramo_id, 'estado' => 'cancelado']);
}

/**
 * DELETE ?action=eliminar_viaje
 * Body JSON: { viaje_id, motivo? }
 * Soft delete: cambia estado a 'cancelado'
 */
function handle_eliminar_viaje($conn, $ctx) {
    assert_can_write($ctx);

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) throw new Exception('JSON invalido', 400);

    $viaje_id = (int)($body['viaje_id'] ?? 0);
    if ($viaje_id <= 0) throw new Exception('viaje_id requerido', 400);

    $stmt = $conn->prepare('SELECT id, estado, cliente_id FROM viajes WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $viaje_id);
    $stmt->execute();
    $v = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$v) throw new Exception('Viaje no encontrado', 404);
    assert_cliente_access($ctx, $v['cliente_id']);

    if ($v['estado'] === 'cancelado') {
        json_ok(['viaje_id' => $viaje_id, 'estado' => 'cancelado', 'msg' => 'Ya estaba cancelado']);
    }

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("UPDATE viajes SET estado = 'cancelado' WHERE id = ?");
        $stmt->bind_param('i', $viaje_id);
        if (!$stmt->execute()) throw new Exception('Error cancelando viaje: ' . $stmt->error, 500);
        $stmt->close();

        // Cancelar también todos los tramos activos
        $stmt = $conn->prepare(
            "UPDATE viaje_tramos SET estado = 'cancelado'
              WHERE viaje_id = ? AND estado != 'cancelado'"
        );
        $stmt->bind_param('i', $viaje_id);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

    json_ok(['viaje_id' => $viaje_id, 'estado' => 'cancelado']);
}

/**
 * GET ?action=unidades&cliente_id=X
 * Retorna unidades activas del cliente para autocomplete en el formulario
 */
function handle_unidades($conn, $ctx) {
    $cliente_id = (int)($_GET['cliente_id'] ?? 0);
    if ($cliente_id <= 0) throw new Exception('cliente_id requerido', 400);
    assert_cliente_access($ctx, $cliente_id);

    $stmt = $conn->prepare(
        'SELECT id, economico, placas, operador, telefonos, equipos
           FROM unidades
          WHERE cliente_id = ? AND activo = 1
          ORDER BY economico ASC'
    );
    $stmt->bind_param('i', $cliente_id);
    $stmt->execute();
    $res = $stmt->get_result();

    $unidades = [];
    while ($r = $res->fetch_assoc()) {
        $unidades[] = [
            'id'        => (int)$r['id'],
            'economico' => $r['economico'],
            'placas'    => $r['placas'],
            'operador'  => $r['operador'],
            'telefonos' => $r['telefonos'],
            'equipos'   => $r['equipos'],
        ];
    }
    $stmt->close();

    json_ok(['unidades' => $unidades]);
}

// ---------------------------------------------------------------------------
// Duplicar viaje
// ---------------------------------------------------------------------------

/**
 * POST ?action=duplicar
 * Body JSON: { viaje_id, nueva_fecha_inicio, ignorar_conflictos? }
 *
 * Clona el viaje y todos sus tramos a un nuevo rango de fechas.
 * La duración del viaje original se preserva (fecha_fin - fecha_inicio).
 * Los horarios de cada tramo se desplazan el mismo número de días.
 */
function handle_duplicar($conn, $ctx) {
    assert_can_write($ctx);

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) throw new Exception('JSON invalido', 400);

    $viaje_id          = (int)($body['viaje_id']          ?? 0);
    $nueva_fecha_inicio = to_date($body['nueva_fecha_inicio'] ?? '');
    $ignorar_conflictos = !empty($body['ignorar_conflictos']);

    if ($viaje_id <= 0)       throw new Exception('viaje_id requerido', 400);
    if (!$nueva_fecha_inicio) throw new Exception('nueva_fecha_inicio requerida (YYYY-MM-DD)', 400);

    // Cargar viaje original con sus tramos
    $stmt = $conn->prepare(
        'SELECT v.*, c.nombre AS cliente_nombre, u.economico
           FROM viajes v
           JOIN clientes c ON c.id = v.cliente_id
           JOIN unidades u ON u.id = v.unidad_id
          WHERE v.id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $viaje_id);
    $stmt->execute();
    $orig = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$orig) throw new Exception('Viaje original no encontrado', 404);
    assert_cliente_access($ctx, $orig['cliente_id']);

    // Calcular desplazamiento de días
    $dt_orig_inicio = new DateTime($orig['fecha_inicio']);
    $dt_nueva       = new DateTime($nueva_fecha_inicio);
    $delta_dias     = (int)$dt_orig_inicio->diff($dt_nueva)->format('%r%a'); // positivo = hacia adelante

    // Calcular nueva fecha_fin conservando la duración
    $nueva_fecha_fin = null;
    if ($orig['fecha_fin']) {
        $dt_fin = new DateTime($orig['fecha_fin']);
        $dt_fin->modify("{$delta_dias} days");
        $nueva_fecha_fin = $dt_fin->format('Y-m-d');
    }

    // Verificar conflictos en el nuevo rango (a menos que se ignore)
    if (!$ignorar_conflictos) {
        $conflictos = detectar_conflictos(
            $conn,
            (int)$orig['unidad_id'],
            $nueva_fecha_inicio,
            $nueva_fecha_fin
        );
        if (count($conflictos) > 0) {
            json_err_conflicto($conflictos);
        }
    }

    // Cargar tramos originales
    $stmt = $conn->prepare(
        'SELECT * FROM viaje_tramos WHERE viaje_id = ? ORDER BY tramo_numero ASC'
    );
    $stmt->bind_param('i', $viaje_id);
    $stmt->execute();
    $tramos_orig = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Helper para desplazar datetime
    $desplazar_dt = function($dt_str) use ($delta_dias) {
        if (!$dt_str) return null;
        try {
            $dt = new DateTime($dt_str);
            $dt->modify("{$delta_dias} days");
            return $dt->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            return null;
        }
    };

    // Transacción: clonar viaje + tramos
    $conn->begin_transaction();
    try {
        $uid = $ctx['id'];
        $nuevo_folio = $orig['folio'] . '-COPIA';

        $stmt = $conn->prepare(
            'INSERT INTO viajes
               (cliente_id, unidad_id, folio, fecha_inicio, fecha_fin, estado, notas, created_by_usuario_id)
             VALUES (?, ?, ?, ?, ?, \'planificado\', ?, ?)'
        );
        $stmt->bind_param(
            'iisssis',
            $orig['cliente_id'], $orig['unidad_id'],
            $nuevo_folio, $nueva_fecha_inicio, $nueva_fecha_fin,
            $orig['notas'], $uid
        );
        if (!$stmt->execute()) throw new Exception('Error clonando viaje: ' . $stmt->error, 500);
        $nuevo_viaje_id = (int)$stmt->insert_id;
        $stmt->close();

        foreach ($tramos_orig as $t) {
            $tramo_data = [
                'origen'              => $t['origen'],
                'lugar_carga'         => $t['lugar_carga'],
                'destino'             => $t['destino'],
                'ruta'                => $t['ruta'],
                'instrucciones'       => $t['instrucciones'],
                'salida_patio'        => $desplazar_dt($t['salida_patio']),
                'cita_carga'          => $desplazar_dt($t['cita_carga']),
                'salida_carga'        => $desplazar_dt($t['salida_carga']),
                'descarga_programada' => $desplazar_dt($t['descarga_programada']),
            ];
            insertar_tramo($conn, $nuevo_viaje_id, (int)$t['tramo_numero'], $tramo_data);
        }

        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

    json_ok(handle_obtener_data($conn, $nuevo_viaje_id), 201);
}

// ---------------------------------------------------------------------------
// Detección de conflictos
// ---------------------------------------------------------------------------

/**
 * Retorna los viajes activos de una unidad que se solapan con el rango dado.
 * Se excluyen viajes en estado 'cancelado'.
 * Un viaje con fecha_fin = NULL se trata como si terminara en fecha_inicio.
 *
 * Solapamiento: inicio_A <= fin_B  AND  fin_A >= inicio_B
 *   donde fin = fecha_fin ?? fecha_inicio
 */
function detectar_conflictos($conn, $unidad_id, $fecha_inicio, $fecha_fin, $excluir_viaje_id = null) {
    // Si no hay fecha_fin el viaje dura solo el día de inicio
    $fin_nuevo = $fecha_fin ?: $fecha_inicio;

    $sql = "
        SELECT
            v.id AS viaje_id, v.folio, v.fecha_inicio, v.fecha_fin,
            v.estado, u.economico
        FROM viajes v
        JOIN unidades u ON u.id = v.unidad_id
        WHERE v.unidad_id = ?
          AND v.estado NOT IN ('cancelado', 'completado')
          AND v.fecha_inicio <= ?
          AND COALESCE(v.fecha_fin, v.fecha_inicio) >= ?
    ";
    $params = [$unidad_id, $fin_nuevo, $fecha_inicio];
    $types  = 'iss';

    if ($excluir_viaje_id) {
        $sql    .= ' AND v.id != ?';
        $types  .= 'i';
        $params[] = $excluir_viaje_id;
    }

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res  = $stmt->get_result();

    $conflictos = [];
    while ($r = $res->fetch_assoc()) {
        $conflictos[] = [
            'viaje_id'    => (int)$r['viaje_id'],
            'folio'       => $r['folio'],
            'fecha_inicio'=> $r['fecha_inicio'],
            'fecha_fin'   => $r['fecha_fin'],
            'estado'      => $r['estado'],
            'economico'   => $r['economico'],
        ];
    }
    $stmt->close();
    return $conflictos;
}

function json_err_conflicto($conflictos) {
    while (ob_get_level()) { ob_end_clean(); }
    header_remove();
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(409);
    echo json_encode([
        'ok'         => false,
        'conflicto'  => true,
        'error'      => 'La unidad ya tiene viajes activos en ese rango de fechas',
        'conflictos' => $conflictos,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * GET ?action=verificar_conflictos&unidad_id=X&fecha_inicio=YYYY-MM-DD&fecha_fin=YYYY-MM-DD
 * Permite al frontend consultar conflictos sin crear el viaje.
 * Param opcional: excluir_viaje_id (para ediciones).
 */
function handle_verificar_conflictos($conn, $ctx) {
    $unidad_id        = (int)($_GET['unidad_id']        ?? 0);
    $fecha_inicio     = to_date($_GET['fecha_inicio']    ?? '');
    $fecha_fin        = to_date($_GET['fecha_fin']       ?? '');
    $excluir_viaje_id = isset($_GET['excluir_viaje_id'])
        ? (int)$_GET['excluir_viaje_id']
        : null;

    if ($unidad_id <= 0) throw new Exception('unidad_id requerido', 400);
    if (!$fecha_inicio)  throw new Exception('fecha_inicio requerida', 400);

    // Verificar acceso a la unidad vía cliente
    $stmt = $conn->prepare(
        'SELECT u.id, u.economico, u.cliente_id FROM unidades u WHERE u.id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $unidad_id);
    $stmt->execute();
    $u = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$u) throw new Exception('Unidad no encontrada', 404);
    assert_cliente_access($ctx, $u['cliente_id']);

    $conflictos = detectar_conflictos(
        $conn, $unidad_id, $fecha_inicio, $fecha_fin, $excluir_viaje_id
    );

    json_ok([
        'tiene_conflicto' => count($conflictos) > 0,
        'conflictos'      => $conflictos,
        'unidad_id'       => $unidad_id,
        'economico'       => $u['economico'],
        'fecha_inicio'    => $fecha_inicio,
        'fecha_fin'       => $fecha_fin,
    ]);
}

// ---------------------------------------------------------------------------
// Router principal
// ---------------------------------------------------------------------------

try {
    $conn   = getDbConnection();
    $action = str_val($_GET['action'] ?? '');
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

    // Endpoints que requieren contexto de usuario
    $ctx = get_usuario_context($conn);

    match (true) {
        $method === 'GET'    && $action === 'listar'                 => handle_listar($conn, $ctx),
        $method === 'GET'    && $action === 'obtener'                => handle_obtener($conn, $ctx),
        $method === 'GET'    && $action === 'unidades'               => handle_unidades($conn, $ctx),
        $method === 'GET'    && $action === 'verificar_conflictos'   => handle_verificar_conflictos($conn, $ctx),
        $method === 'POST'   && $action === 'crear'                  => handle_crear($conn, $ctx),
        $method === 'POST'   && $action === 'duplicar'               => handle_duplicar($conn, $ctx),
        $method === 'POST'   && $action === 'agregar_tramo'   => handle_agregar_tramo($conn, $ctx),
        $method === 'PUT'    && $action === 'actualizar'      => handle_actualizar($conn, $ctx),
        $method === 'PUT'    && $action === 'actualizar_tramo'=> handle_actualizar_tramo($conn, $ctx),
        $method === 'DELETE' && $action === 'eliminar_tramo'  => handle_eliminar_tramo($conn, $ctx),
        $method === 'DELETE' && $action === 'eliminar_viaje'  => handle_eliminar_viaje($conn, $ctx),
        default => json_err("Accion '$action' no reconocida o metodo '$method' incorrecto", 404),
    };

} catch (Exception $e) {
    $code = (int)$e->getCode();
    json_err($e->getMessage(), in_array($code, [400,401,403,404,409,500], true) ? $code : 500);
}
