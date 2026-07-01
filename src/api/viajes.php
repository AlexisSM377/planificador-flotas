<?php
while (ob_get_level()) {
    ob_end_clean();
}
ob_start();

require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../config.php";

date_default_timezone_set("America/Mexico_City");

header_remove();
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key");

if (($_SERVER["REQUEST_METHOD"] ?? "") === "OPTIONS") {
    http_response_code(204);
    exit();
}

function json_ok($data = [], $code = 200)
{
    while (ob_get_level()) {
        ob_end_clean();
    }
    header_remove();
    header("Content-Type: application/json; charset=utf-8");
    http_response_code($code);
    echo json_encode(
        array_merge(["ok" => true], $data),
        JSON_UNESCAPED_UNICODE,
    );
    exit();
}

function json_err($msg, $code = 400)
{
    while (ob_get_level()) {
        ob_end_clean();
    }
    header_remove();
    header("Content-Type: application/json; charset=utf-8");
    http_response_code($code);
    echo json_encode(["ok" => false, "error" => $msg], JSON_UNESCAPED_UNICODE);
    exit();
}

function str_val($v)
{
    return trim((string) ($v ?? ""));
}

function null_if_empty($v)
{
    $s = str_val($v);
    return $s === "" ? null : $s;
}

function utf8_lower_v($v)
{
    $s = (string) ($v ?? "");
    return function_exists("mb_strtolower")
        ? mb_strtolower($s, "UTF-8")
        : strtolower($s);
}

function db_table_exists($conn, $table)
{
    $table = (string) $table;
    $res = $conn->query(
        "SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'",
    );
    return $res && $res->num_rows > 0;
}

function db_columns_exist($conn, $table, $columns)
{
    $table = (string) $table;
    if (!db_table_exists($conn, $table)) {
        return false;
    }

    $res = $conn->query(
        "SHOW COLUMNS FROM `" . str_replace("`", "``", $table) . "`",
    );
    if (!$res) {
        return false;
    }

    $found = [];
    while ($row = $res->fetch_assoc()) {
        $found[$row["Field"]] = true;
    }

    foreach ($columns as $column) {
        if (empty($found[$column])) {
            return false;
        }
    }
    return true;
}

function base64url_encode_v($data)
{
    return rtrim(strtr(base64_encode($data), "+/", "-_"), "=");
}

function base64url_decode_v($data)
{
    $pad = 4 - (strlen($data) % 4);
    if ($pad < 4) {
        $data .= str_repeat("=", $pad);
    }
    return base64_decode(strtr($data, "-_", "+/"));
}

function get_jwt_secret_v()
{
    $s = getenv("JWT_SECRET") ?: (defined("API_KEY") ? API_KEY : "");
    if (
        $s === "" ||
        (defined("ENVIRONMENT") &&
            ENVIRONMENT !== "development" &&
            strlen($s) < 32)
    ) {
        throw new Exception("JWT_SECRET no configurado", 500);
    }
    return $s;
}

function jwt_decode_v($token)
{
    $parts = explode(".", trim((string) $token));
    if (count($parts) !== 3) {
        throw new Exception("Token invalido", 401);
    }
    [$h, $p, $sig] = $parts;
    $header = json_decode(base64url_decode_v($h), true);
    $payload = json_decode(base64url_decode_v($p), true);
    if (
        !is_array($header) ||
        !is_array($payload) ||
        ($header["alg"] ?? "") !== "HS256"
    ) {
        throw new Exception("Token invalido", 401);
    }
    $expected = base64url_encode_v(
        hash_hmac("sha256", "$h.$p", get_jwt_secret_v(), true),
    );
    if (!hash_equals($expected, $sig)) {
        throw new Exception("Token invalido", 401);
    }
    $now = time();
    if (isset($payload["nbf"]) && (int) $payload["nbf"] > $now) {
        throw new Exception("Token aun no valido", 401);
    }
    if (!isset($payload["exp"]) || (int) $payload["exp"] < $now) {
        throw new Exception("Sesion expirada", 401);
    }
    return $payload;
}

function get_bearer_token_v()
{
    $h =
        $_SERVER["HTTP_AUTHORIZATION"] ??
        ($_SERVER["REDIRECT_HTTP_AUTHORIZATION"] ?? "");
    if ($h === "" && function_exists("apache_request_headers")) {
        foreach (apache_request_headers() as $k => $v) {
            if (strtolower($k) === "authorization") {
                $h = $v;
                break;
            }
        }
    }
    return preg_match("/Bearer\s+(.+)/i", $h, $m) ? trim($m[1]) : "";
}

function get_usuario_context($conn)
{
    $token = get_bearer_token_v();
    if ($token === "") {
        throw new Exception("Sesion requerida", 401);
    }

    $payload = jwt_decode_v($token);
    $email = strtolower(str_val($payload["email"] ?? ""));
    if ($email === "") {
        throw new Exception("Token invalido", 401);
    }

    $stmt = $conn->prepare(
        "SELECT id, email, nombre, role, activo FROM usuarios WHERE LOWER(email) = ? LIMIT 1",
    );
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user || (int) $user["activo"] !== 1) {
        throw new Exception("Sesion invalida", 401);
    }

    $uid = (int) $user["id"];
    $role = strtolower($user["role"]);
    $clientes = [];

    if ($role === "admin") {
        $res = $conn->query(
            "SELECT id, nombre FROM clientes WHERE activo = 1 ORDER BY nombre ASC",
        );
        while ($r = $res->fetch_assoc()) {
            $clientes[] = ["id" => (int) $r["id"], "nombre" => $r["nombre"]];
        }
    } else {
        $stmt = $conn->prepare(
            'SELECT c.id, c.nombre
               FROM usuario_clientes uc
               JOIN clientes c ON c.id = uc.cliente_id
              WHERE uc.usuario_id = ? AND c.activo = 1
              ORDER BY c.nombre ASC',
        );
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $clientes[] = ["id" => (int) $r["id"], "nombre" => $r["nombre"]];
        }
        $stmt->close();
    }

    return [
        "id" => $uid,
        "email" => $user["email"],
        "role" => $role,
        "clientes" => $clientes,
        // IDs permitidos; vacío = admin (acceso total)
        "ids" => $role === "admin" ? [] : array_column($clientes, "id"),
    ];
}

function assert_cliente_access($ctx, $cliente_id)
{
    $cliente_id = (int) $cliente_id;
    if ($ctx["role"] !== "admin" && !in_array($cliente_id, $ctx["ids"], true)) {
        throw new Exception("Sin acceso a ese cliente", 403);
    }
}

function assert_can_write($ctx)
{
    if ($ctx["role"] === "lector") {
        throw new Exception("Sin permisos de escritura", 403);
    }
}

function cliente_where($ctx, $cliente_id_param = null)
{
    if ($cliente_id_param !== null) {
        $cid = (int) $cliente_id_param;
        assert_cliente_access($ctx, $cid);
        return [
            "sql" => "v.cliente_id = ?",
            "types" => "i",
            "params" => [$cid],
        ];
    }
    if ($ctx["role"] === "admin") {
        return ["sql" => "1=1", "types" => "", "params" => []];
    }
    $ids = $ctx["ids"];
    if (!count($ids)) {
        throw new Exception("Sin clientes asignados", 403);
    }
    $ph = implode(",", array_fill(0, count($ids), "?"));
    return [
        "sql" => "v.cliente_id IN ($ph)",
        "types" => str_repeat("i", count($ids)),
        "params" => $ids,
    ];
}

function to_date($v)
{
    $s = str_val($v);
    if ($s === "") {
        return null;
    }
    if (preg_match("/^\d{4}-\d{2}-\d{2}/", $s)) {
        return substr($s, 0, 10);
    }
    if (preg_match("/^(\d{2})\/(\d{2})\/(\d{4})/", $s, $m)) {
        return "{$m[3]}-{$m[2]}-{$m[1]}";
    }
    try {
        return new DateTime($s)->format("Y-m-d");
    } catch (Exception $e) {
        return null;
    }
}

function to_datetime($v)
{
    $s = str_val($v);
    if ($s === "") {
        return null;
    }
    if (preg_match("/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/", $s)) {
        return str_replace("T", " ", substr($s, 0, 19));
    }
    if (preg_match("/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/", $s)) {
        return substr($s, 0, 19);
    }
    try {
        return new DateTime($s)->format("Y-m-d H:i:s");
    } catch (Exception $e) {
        return null;
    }
}

function fecha_fin_desde_tramos($tramos)
{
    $max = null;
    $campos = [
        "salida_patio",
        "cita_carga",
        "salida_carga",
        "descarga_programada",
        "regreso_origen_programado",
    ];
    foreach ($tramos as $t) {
        foreach ($campos as $c) {
            $dt = to_datetime($t[$c] ?? "");
            if ($dt && ($max === null || $dt > $max)) {
                $max = $dt;
            }
        }
    }
    return $max ? substr($max, 0, 10) : null;
}

function map_tramo_row($r)
{
    return [
        "id" => (int) $r["id"],
        "viaje_id" => (int) $r["viaje_id"],
        "tramo_numero" => (int) $r["tramo_numero"],
        "origen" => $r["origen"],
        "lugar_carga" => $r["lugar_carga"],
        "destino" => $r["destino"],
        "ruta" => $r["ruta"],
        "instrucciones" => $r["instrucciones"],
        "gps_estado" => $r["gps_estado"] ?? null,
        "gps_timestamp" => $r["gps_timestamp"] ?? null,
        "salida_patio" => $r["salida_patio"],
        "salida_patio_real" => $r["salida_patio_real"] ?? null,
        "cita_carga" => $r["cita_carga"],
        "cita_carga_real" => $r["cita_carga_real"] ?? null,
        "salida_carga" => $r["salida_carga"],
        "salida_carga_real" => $r["salida_carga_real"] ?? null,
        "descarga_programada" => $r["descarga_programada"],
        "descarga_real" => $r["descarga_real"] ?? null,
        "vacio_real" => $r["vacio_real"] ?? null,
        "requiere_regreso_origen" => (int) ($r["requiere_regreso_origen"] ?? 0),
        "regreso_origen_programado" => $r["regreso_origen_programado"] ?? null,
        "regreso_origen_real" => $r["regreso_origen_real"] ?? null,
        "estado" => $r["estado"],
        "created_at" => $r["created_at"],
        "updated_at" => $r["updated_at"],
    ];
}

function map_viaje_row($r)
{
    return [
        "id" => (int) $r["id"],
        "cliente_id" => (int) $r["cliente_id"],
        "cliente_nombre" => $r["cliente_nombre"] ?? null,
        "unidad_id" => (int) $r["unidad_id"],
        "economico" => $r["economico"] ?? null,
        "operador" => $r["operador"] ?? null,
        "folio" => $r["folio"],
        "fecha_inicio" => $r["fecha_inicio"],
        "fecha_fin" => $r["fecha_fin"],
        "estado" => $r["estado"],
        "notas" => $r["notas"],
        "created_by_usuario_id" => $r["created_by_usuario_id"]
            ? (int) $r["created_by_usuario_id"]
            : null,
        "created_at" => $r["created_at"],
        "updated_at" => $r["updated_at"],
    ];
}

function insertar_tramo($conn, $viaje_id, $num, $t)
{
    $origen = null_if_empty($t["origen"] ?? "");
    $lc = null_if_empty($t["lugar_carga"] ?? "");
    $destino = null_if_empty($t["destino"] ?? "");
    $ruta = null_if_empty($t["ruta"] ?? "");
    $instr = null_if_empty($t["instrucciones"] ?? "");
    $salida_p = to_datetime($t["salida_patio"] ?? "");
    $cita = to_datetime($t["cita_carga"] ?? "");
    $salida_c = to_datetime($t["salida_carga"] ?? "");
    $descarga = to_datetime($t["descarga_programada"] ?? "");
    $has_regreso_origen = db_columns_exist($conn, "viaje_tramos", [
        "requiere_regreso_origen",
        "regreso_origen_programado",
        "regreso_origen_real",
    ]);
    $requiere_regreso = !empty($t["requiere_regreso_origen"]) ? 1 : 0;
    $regreso_programado = $requiere_regreso
        ? to_datetime($t["regreso_origen_programado"] ?? "")
        : null;
    $estado = "pendiente";

    if ($has_regreso_origen) {
        $stmt = $conn->prepare(
            'INSERT INTO viaje_tramos
               (viaje_id, tramo_numero, origen, lugar_carga, destino, ruta,
                 instrucciones, salida_patio, cita_carga, salida_carga, descarga_programada,
                 requiere_regreso_origen, regreso_origen_programado, estado)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        if (!$stmt) {
            throw new Exception("Error preparando tramo: " . $conn->error, 500);
        }
        $stmt->bind_param(
            "iisssssssssiss",
            $viaje_id,
            $num,
            $origen,
            $lc,
            $destino,
            $ruta,
            $instr,
            $salida_p,
            $cita,
            $salida_c,
            $descarga,
            $requiere_regreso,
            $regreso_programado,
            $estado,
        );
    } else {
        $stmt = $conn->prepare(
            'INSERT INTO viaje_tramos
               (viaje_id, tramo_numero, origen, lugar_carga, destino, ruta,
                 instrucciones, salida_patio, cita_carga, salida_carga, descarga_programada, estado)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        if (!$stmt) {
            throw new Exception("Error preparando tramo: " . $conn->error, 500);
        }
        $stmt->bind_param(
            "iissssssssss",
            $viaje_id,
            $num,
            $origen,
            $lc,
            $destino,
            $ruta,
            $instr,
            $salida_p,
            $cita,
            $salida_c,
            $descarga,
            $estado,
        );
    }
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new Exception("Error insertando tramo: " . $err, 500);
    }
    $id = (int) $stmt->insert_id;
    $stmt->close();
    return $id;
}

function sincronizar_despachos(
    $conn,
    $viaje_id,
    $cliente_id,
    $unidad_id,
    $folio,
    $fecha_inicio,
    $tramos,
    $cliente_nombre,
) {
    foreach ($tramos as $idx => $t) {
        $tramo_numero = (int) ($t["tramo_numero"] ?? $idx + 1);
        $legacy_key = substr(
            $cliente_nombre .
                "|" .
                $folio .
                "|" .
                $unidad_id .
                "|" .
                $fecha_inicio .
                "|" .
                $tramo_numero,
            0,
            190,
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
                legacy_key              = VALUES(legacy_key)',
        );
        if (!$stmt) {
            throw new Exception(
                "Error preparando sincronizacion despacho: " . $conn->error,
                500,
            );
        }

        $ruta = null_if_empty($t["ruta"] ?? "");
        $origen = null_if_empty($t["origen"] ?? "");
        $lugar_carga = null_if_empty($t["lugar_carga"] ?? "");
        $destino = null_if_empty($t["destino"] ?? "");
        $instruc = null_if_empty($t["instrucciones"] ?? "");
        $sal_patio = to_datetime($t["salida_patio"] ?? "") ?: null;
        $cita = to_datetime($t["cita_carga"] ?? "") ?: null;
        $sal_carga = to_datetime($t["salida_carga"] ?? "") ?: null;
        $descarga = to_datetime($t["descarga_programada"] ?? "") ?: null;

        $stmt->bind_param(
            "iississssssssss",
            $cliente_id,
            $unidad_id,
            $folio,
            $fecha_inicio,
            $tramo_numero,
            $ruta,
            $origen,
            $lugar_carga,
            $destino,
            $instruc,
            $sal_patio,
            $cita,
            $sal_carga,
            $descarga,
            $legacy_key,
        );
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new Exception("Error sincronizando despacho: " . $err, 500);
        }
        $stmt->close();
    }
}

function upsert_unidad(
    $conn,
    $cliente_id,
    $economico,
    $placas,
    $operador,
    $telefonos,
    $equipos,
    $operador_id = null,
) {
    $operador_id =
        $operador_id !== null && (int) $operador_id > 0
            ? (int) $operador_id
            : null;
    $stmt = $conn->prepare(
        'INSERT INTO unidades (cliente_id, economico, placas, operador, telefonos, equipos, operador_id, activo)
         VALUES (?, ?, ?, ?, ?, ?, ?, 1)
         ON DUPLICATE KEY UPDATE
           placas      = IF(VALUES(placas)    != \'\', VALUES(placas),    placas),
           operador    = IF(VALUES(operador)  != \'\', VALUES(operador),  operador),
           telefonos   = IF(VALUES(telefonos) != \'\', VALUES(telefonos), telefonos),
           equipos     = IF(VALUES(equipos)   != \'\', VALUES(equipos),   equipos),
           operador_id = IF(VALUES(operador_id) IS NOT NULL, VALUES(operador_id), operador_id),
           activo      = 1',
    );
    if (!$stmt) {
        throw new Exception("Error preparando unidad: " . $conn->error, 500);
    }
    $stmt->bind_param(
        "isssssi",
        $cliente_id,
        $economico,
        $placas,
        $operador,
        $telefonos,
        $equipos,
        $operador_id,
    );
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new Exception("Error guardando unidad: " . $err, 500);
    }
    $stmt->close();

    $stmt = $conn->prepare(
        "SELECT id FROM unidades WHERE cliente_id = ? AND economico = ? LIMIT 1",
    );
    if (!$stmt) {
        throw new Exception(
            "Error preparando consulta de unidad: " . $conn->error,
            500,
        );
    }
    $stmt->bind_param("is", $cliente_id, $economico);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        throw new Exception(
            "No se pudo resolver la unidad tras el upsert",
            500,
        );
    }
    return (int) $row["id"];
}

function handle_crear($conn, $ctx)
{
    assert_can_write($ctx);

    $body = json_decode(file_get_contents("php://input"), true);
    if (!is_array($body)) {
        throw new Exception("JSON invalido", 400);
    }

    $cliente_id = (int) ($body["cliente_id"] ?? 0);
    $folio = str_val($body["folio"] ?? "");
    $fecha_inicio = to_date($body["fecha_inicio"] ?? "");
    $fecha_fin = null;
    $notas = null_if_empty($body["notas"] ?? "");
    $tramos = $body["tramos"] ?? [];

    $economico = str_val($body["economico"] ?? "");
    $placas = str_val($body["placas"] ?? "");
    $operador = str_val($body["operador"] ?? "");
    $telefonos = str_val($body["telefonos"] ?? "");
    $equipos = str_val($body["equipos"] ?? "");
    $operador_id = (int) ($body["operador_id"] ?? 0);

    if ($cliente_id <= 0) {
        throw new Exception("cliente_id requerido", 400);
    }
    if ($folio === "") {
        throw new Exception("folio requerido", 400);
    }
    if (!$fecha_inicio) {
        throw new Exception("fecha_inicio requerida (YYYY-MM-DD)", 400);
    }
    $hoy = new DateTime("today")->format("Y-m-d");
    if ($fecha_inicio < $hoy) {
        throw new Exception(
            "No se puede registrar un viaje con fecha de inicio en el pasado",
            400,
        );
    }
    if (!is_array($tramos) || count($tramos) === 0) {
        throw new Exception("Se requiere al menos un tramo", 400);
    }

    $ahora = new DateTime("now")->format("Y-m-d H:i:s");
    $campos_tiempo = [
        "salida_patio" => "Salida de Patio",
        "cita_carga" => "Cita de Carga",
        "salida_carga" => "Salida de Carga",
        "descarga_programada" => "Cita de Descarga",
        "regreso_origen_programado" => "Fecha de Regreso",
    ];
    foreach ($tramos as $i => $t) {
        $requiere_regreso = !empty($t["requiere_regreso_origen"]);
        if (
            $requiere_regreso &&
            !to_datetime($t["regreso_origen_programado"] ?? "")
        ) {
            throw new Exception(
                "Tramo " . ($i + 1) . ": Fecha de Regreso requerida",
                400,
            );
        }
        foreach ($campos_tiempo as $campo => $etiqueta) {
            if ($campo === "regreso_origen_programado" && !$requiere_regreso) {
                continue;
            }
            $dt = to_datetime($t[$campo] ?? "");
            if ($dt && $dt < $ahora) {
                throw new Exception(
                    "Tramo " .
                        ($i + 1) .
                        ": \"$etiqueta\" no puede ser una fecha/hora pasada",
                    400,
                );
            }
        }
    }
    $fecha_fin = fecha_fin_desde_tramos($tramos);
    if ($fecha_fin && $fecha_fin < $fecha_inicio) {
        $fecha_fin = $fecha_inicio;
    }

    assert_cliente_access($ctx, $cliente_id);

    $unidad_id = (int) ($body["unidad_id"] ?? 0);

    if ($unidad_id > 0) {
        $stmt = $conn->prepare(
            "SELECT id FROM unidades WHERE id = ? AND cliente_id = ? AND activo = 1 LIMIT 1",
        );
        if (!$stmt) {
            throw new Exception(
                "Error preparando verificacion de unidad: " . $conn->error,
                500,
            );
        }
        $stmt->bind_param("ii", $unidad_id, $cliente_id);
        $stmt->execute();
        $existe = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$existe) {
            throw new Exception(
                "La unidad no pertenece al cliente indicado",
                400,
            );
        }
    } else {
        if ($economico === "") {
            throw new Exception(
                "economico requerido para registrar una unidad nueva",
                400,
            );
        }
        $unidad_id = upsert_unidad(
            $conn,
            $cliente_id,
            $economico,
            $placas,
            $operador,
            $telefonos,
            $equipos,
            $operador_id,
        );
    }

    $conflictos = detectar_conflictos(
        $conn,
        $unidad_id,
        $fecha_inicio,
        $fecha_fin,
    );
    if (count($conflictos) > 0) {
        json_err_conflicto($conflictos);
    }

    $conn->begin_transaction();
    try {
        $uid = $ctx["id"];
        $stmt = $conn->prepare(
            'INSERT INTO viajes
               (cliente_id, unidad_id, folio, fecha_inicio, fecha_fin, estado, notas, created_by_usuario_id)
             VALUES (?, ?, ?, ?, ?, \'planificado\', ?, ?)',
        );
        if (!$stmt) {
            throw new Exception("Error preparando viaje: " . $conn->error, 500);
        }
        $stmt->bind_param(
            "iissssi",
            $cliente_id,
            $unidad_id,
            $folio,
            $fecha_inicio,
            $fecha_fin,
            $notas,
            $uid,
        );
        if (!$stmt->execute()) {
            throw new Exception("Error creando viaje: " . $stmt->error, 500);
        }
        $viaje_id = (int) $stmt->insert_id;
        $stmt->close();

        foreach ($tramos as $i => $t) {
            insertar_tramo($conn, $viaje_id, $i + 1, $t);
        }

        $stmt_cn = $conn->prepare(
            "SELECT nombre FROM clientes WHERE id = ? LIMIT 1",
        );
        if (!$stmt_cn) {
            throw new Exception(
                "Error preparando cliente: " . $conn->error,
                500,
            );
        }
        $stmt_cn->bind_param("i", $cliente_id);
        $stmt_cn->execute();
        $cliente_nombre = $stmt_cn->get_result()->fetch_assoc()["nombre"] ?? "";
        $stmt_cn->close();

        $tramos_sync = array_values(
            array_map(
                function ($t, $i) {
                    return array_merge($t, ["tramo_numero" => $i + 1]);
                },
                $tramos,
                array_keys($tramos),
            ),
        );

        sincronizar_despachos(
            $conn,
            $viaje_id,
            $cliente_id,
            $unidad_id,
            $folio,
            $fecha_inicio,
            $tramos_sync,
            $cliente_nombre,
        );

        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

    json_ok(handle_obtener_data($conn, $viaje_id), 201);
}

function handle_listar($conn, $ctx)
{
    $cliente_id = isset($_GET["cliente_id"]) ? (int) $_GET["cliente_id"] : null;
    $unidad_id = isset($_GET["unidad_id"]) ? (int) $_GET["unidad_id"] : null;
    $fecha_desde = to_date($_GET["fecha_desde"] ?? "");
    $fecha_hasta = to_date($_GET["fecha_hasta"] ?? "");
    $estado_f = str_val($_GET["estado"] ?? "");
    $limit = min((int) ($_GET["limit"] ?? 50), 200);
    $offset = max((int) ($_GET["offset"] ?? 0), 0);

    $cw = cliente_where($ctx, $cliente_id);
    $where = [$cw["sql"]];
    $types = $cw["types"];
    $params = $cw["params"];

    if ($unidad_id) {
        $where[] = "v.unidad_id = ?";
        $types .= "i";
        $params[] = $unidad_id;
    }

    if ($fecha_desde) {
        $where[] =
            "(v.fecha_fin >= ? OR (v.fecha_fin IS NULL AND v.fecha_inicio >= ?))";
        $types .= "ss";
        $params[] = $fecha_desde;
        $params[] = $fecha_desde;
    }
    if ($fecha_hasta) {
        $where[] = "v.fecha_inicio <= ?";
        $types .= "s";
        $params[] = $fecha_hasta;
    }
    if (
        in_array(
            $estado_f,
            ["planificado", "en_curso", "completado", "cancelado"],
            true,
        )
    ) {
        $where[] = "v.estado = ?";
        $types .= "s";
        $params[] = $estado_f;
    }

    $where_sql = implode(" AND ", $where);

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

    $types .= "ii";
    $params[] = $limit;
    $params[] = $offset;

    $stmt = $conn->prepare($sql);
    if (count($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();

    $viajes = [];
    while ($r = $res->fetch_assoc()) {
        $v = map_viaje_row($r);
        $v["total_tramos"] = (int) $r["total_tramos"];
        $v["primera_salida"] = $r["primera_salida"];
        $v["ultima_descarga"] = $r["ultima_descarga"];
        $viajes[] = $v;
    }
    $stmt->close();

    $sql_count = "SELECT COUNT(DISTINCT v.id) AS total
                  FROM viajes v
                  WHERE $where_sql";
    $count_params = array_slice($params, 0, -2);
    $count_types = substr($types, 0, -2);
    $stmt = $conn->prepare($sql_count);
    if (count($count_params)) {
        $stmt->bind_param($count_types, ...$count_params);
    }
    $stmt->execute();
    $total = (int) $stmt->get_result()->fetch_assoc()["total"];
    $stmt->close();

    json_ok([
        "viajes" => $viajes,
        "total" => $total,
        "limit" => $limit,
        "offset" => $offset,
    ]);
}

function handle_obtener($conn, $ctx)
{
    $viaje_id = (int) ($_GET["viaje_id"] ?? 0);
    if ($viaje_id <= 0) {
        throw new Exception("viaje_id requerido", 400);
    }
    json_ok(handle_obtener_data($conn, $viaje_id, $ctx));
}

function handle_obtener_data($conn, $viaje_id, $ctx = null)
{
    $stmt = $conn->prepare(
        'SELECT v.*, c.nombre AS cliente_nombre, u.economico, u.operador
           FROM viajes v
           JOIN clientes c ON c.id = v.cliente_id
           JOIN unidades u ON u.id = v.unidad_id
          WHERE v.id = ? LIMIT 1',
    );
    if (!$stmt) {
        throw new Exception(
            "Error preparando consulta de viaje: " . $conn->error,
            500,
        );
    }
    $stmt->bind_param("i", $viaje_id);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$r) {
        throw new Exception("Viaje no encontrado", 404);
    }
    if ($ctx) {
        assert_cliente_access($ctx, $r["cliente_id"]);
    }

    $viaje = map_viaje_row($r);

    $stmt = $conn->prepare(
        "SELECT * FROM viaje_tramos WHERE viaje_id = ? ORDER BY tramo_numero ASC",
    );
    if (!$stmt) {
        throw new Exception(
            "Error preparando consulta de tramos: " . $conn->error,
            500,
        );
    }
    $stmt->bind_param("i", $viaje_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $tramos = [];
    while ($t = $res->fetch_assoc()) {
        $tramos[] = map_tramo_row($t);
    }
    $stmt->close();

    $viaje["tramos"] = $tramos;

    $incidencias_map = [];
    if (db_table_exists($conn, "viaje_incidencias")) {
        $stmt = $conn->prepare(
            'SELECT id, tramo_id, tipo, severidad, fecha, direccion, notas, creado_por, created_at
               FROM viaje_incidencias
              WHERE viaje_id = ?
              ORDER BY tramo_id ASC, fecha ASC',
        );
        if (!$stmt) {
            throw new Exception(
                "Error preparando consulta de incidencias: " . $conn->error,
                500,
            );
        }
        $stmt->bind_param("i", $viaje_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($inc = $res->fetch_assoc()) {
            $tid = (int) $inc["tramo_id"];
            $incidencias_map[$tid][] = [
                "id" => (int) $inc["id"],
                "tramo_id" => $tid,
                "tipo" => $inc["tipo"],
                "severidad" => $inc["severidad"],
                "fecha" => $inc["fecha"],
                "direccion" => $inc["direccion"],
                "notas" => $inc["notas"],
                "created_at" => $inc["created_at"],
            ];
        }
        $stmt->close();
    }

    foreach ($viaje["tramos"] as &$tramo) {
        $tramo["incidencias"] = $incidencias_map[$tramo["id"]] ?? [];
    }
    unset($tramo);

    return ["viaje" => $viaje];
}

function handle_actualizar($conn, $ctx)
{
    assert_can_write($ctx);

    $body = json_decode(file_get_contents("php://input"), true);
    if (!is_array($body)) {
        throw new Exception("JSON invalido", 400);
    }

    $viaje_id = (int) ($body["viaje_id"] ?? 0);
    if ($viaje_id <= 0) {
        throw new Exception("viaje_id requerido", 400);
    }

    $stmt = $conn->prepare(
        "SELECT cliente_id FROM viajes WHERE id = ? LIMIT 1",
    );
    $stmt->bind_param("i", $viaje_id);
    $stmt->execute();
    $v = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$v) {
        throw new Exception("Viaje no encontrado", 404);
    }
    assert_cliente_access($ctx, $v["cliente_id"]);

    $sets = [];
    $types = "";
    $params = [];

    $estados_validos = ["planificado", "en_curso", "completado", "cancelado"];

    if (array_key_exists("folio", $body)) {
        $sets[] = "folio = ?";
        $types .= "s";
        $params[] = str_val($body["folio"]);
    }
    if (array_key_exists("fecha_inicio", $body)) {
        $d = to_date($body["fecha_inicio"]);
        if (!$d) {
            throw new Exception("fecha_inicio invalida", 400);
        }
        $sets[] = "fecha_inicio = ?";
        $types .= "s";
        $params[] = $d;
    }
    if (array_key_exists("fecha_fin", $body)) {
        $sets[] = "fecha_fin = ?";
        $types .= "s";
        $params[] = null_if_empty($body["fecha_fin"])
            ? to_date($body["fecha_fin"])
            : null;
    }
    if (array_key_exists("estado", $body)) {
        if (!in_array($body["estado"], $estados_validos, true)) {
            throw new Exception(
                "estado invalido. Valores: " . implode(", ", $estados_validos),
                400,
            );
        }
        $sets[] = "estado = ?";
        $types .= "s";
        $params[] = $body["estado"];
    }
    if (array_key_exists("notas", $body)) {
        $sets[] = "notas = ?";
        $types .= "s";
        $params[] = null_if_empty($body["notas"]);
    }

    if (!count($sets)) {
        throw new Exception("No hay campos para actualizar", 400);
    }

    $revertir_tramos = !empty($body["revertir_tramos"]);

    $types .= "i";
    $params[] = $viaje_id;

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            "UPDATE viajes SET " . implode(", ", $sets) . " WHERE id = ?",
        );
        $stmt->bind_param($types, ...$params);
        if (!$stmt->execute()) {
            throw new Exception(
                "Error actualizando viaje: " . $stmt->error,
                500,
            );
        }
        $stmt->close();

        if ($revertir_tramos) {
            $stmt2 = $conn->prepare(
                "UPDATE viaje_tramos SET estado = 'pendiente'
                  WHERE viaje_id = ? AND estado = 'completado'",
            );
            $stmt2->bind_param("i", $viaje_id);
            if (!$stmt2->execute()) {
                throw new Exception(
                    "Error revirtiendo tramos: " . $stmt2->error,
                    500,
                );
            }
            $stmt2->close();
        }

        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

    json_ok(handle_obtener_data($conn, $viaje_id));
}

function handle_agregar_tramo($conn, $ctx)
{
    assert_can_write($ctx);

    $body = json_decode(file_get_contents("php://input"), true);
    if (!is_array($body)) {
        throw new Exception("JSON invalido", 400);
    }

    $viaje_id = (int) ($body["viaje_id"] ?? 0);
    if ($viaje_id <= 0) {
        throw new Exception("viaje_id requerido", 400);
    }

    $stmt = $conn->prepare(
        'SELECT v.cliente_id, v.unidad_id, v.folio, v.fecha_inicio, v.fecha_fin,
                c.nombre AS cliente_nombre
           FROM viajes v
           JOIN clientes c ON c.id = v.cliente_id
          WHERE v.id = ? LIMIT 1',
    );
    $stmt->bind_param("i", $viaje_id);
    $stmt->execute();
    $v = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$v) {
        throw new Exception("Viaje no encontrado", 404);
    }
    assert_cliente_access($ctx, $v["cliente_id"]);

    $stmt = $conn->prepare(
        "SELECT COALESCE(MAX(tramo_numero), 0) + 1 AS siguiente FROM viaje_tramos WHERE viaje_id = ?",
    );
    $stmt->bind_param("i", $viaje_id);
    $stmt->execute();
    $siguiente = (int) $stmt->get_result()->fetch_assoc()["siguiente"];
    $stmt->close();

    $tramo_data = $body["tramo"] ?? [];
    if (!is_array($tramo_data)) {
        throw new Exception("tramo debe ser un objeto", 400);
    }

    $ahora = new DateTime("now")->format("Y-m-d H:i:s");
    $campos_tiempo = [
        "salida_patio" => "Salida de Patio",
        "cita_carga" => "Cita de Carga",
        "salida_carga" => "Salida de Carga",
        "descarga_programada" => "Cita de Descarga",
        "regreso_origen_programado" => "Fecha de Regreso",
    ];
    $requiere_regreso = !empty($tramo_data["requiere_regreso_origen"]);
    if (
        $requiere_regreso &&
        !to_datetime($tramo_data["regreso_origen_programado"] ?? "")
    ) {
        throw new Exception("Fecha de Regreso requerida", 400);
    }
    foreach ($campos_tiempo as $campo => $etiqueta) {
        if ($campo === "regreso_origen_programado" && !$requiere_regreso) {
            continue;
        }
        $dt = to_datetime($tramo_data[$campo] ?? "");
        if ($dt && $dt < $ahora) {
            throw new Exception(
                "\"$etiqueta\" no puede ser una fecha/hora pasada",
                400,
            );
        }
    }

    $tramo_id = insertar_tramo($conn, $viaje_id, $siguiente, $tramo_data);

    $fin_tramo = fecha_fin_desde_tramos([$tramo_data]);
    if ($fin_tramo) {
        $fin_actual = $v["fecha_fin"] ?: $v["fecha_inicio"];
        if ($fin_tramo > $fin_actual) {
            $stmt = $conn->prepare(
                "UPDATE viajes SET fecha_fin = ? WHERE id = ?",
            );
            $stmt->bind_param("si", $fin_tramo, $viaje_id);
            $stmt->execute();
            $stmt->close();
        }
    }

    $tramo_data["tramo_numero"] = $siguiente;
    sincronizar_despachos(
        $conn,
        $viaje_id,
        (int) $v["cliente_id"],
        (int) $v["unidad_id"],
        $v["folio"],
        $v["fecha_inicio"],
        [$tramo_data],
        $v["cliente_nombre"],
    );

    $stmt = $conn->prepare("SELECT * FROM viaje_tramos WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $tramo_id);
    $stmt->execute();
    $t = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    json_ok(["tramo" => map_tramo_row($t)], 201);
}

function handle_completar_tramo($conn, $ctx)
{
    assert_can_write($ctx);

    $body = json_decode(file_get_contents("php://input"), true);
    if (!is_array($body)) {
        throw new Exception("JSON invalido", 400);
    }

    $tramo_id = (int) ($body["tramo_id"] ?? 0);
    if ($tramo_id <= 0) {
        throw new Exception("tramo_id requerido", 400);
    }

    $stmt = $conn->prepare(
        'SELECT vt.id, vt.viaje_id, vt.tramo_numero, vt.estado,
                vt.origen, vt.lugar_carga, vt.destino, vt.ruta, vt.instrucciones,
                v.cliente_id
           FROM viaje_tramos vt
           JOIN viajes v ON v.id = vt.viaje_id
          WHERE vt.id = ? LIMIT 1',
    );
    $stmt->bind_param("i", $tramo_id);
    $stmt->execute();
    $tramo = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$tramo) {
        throw new Exception("Tramo no encontrado", 404);
    }
    assert_cliente_access($ctx, $tramo["cliente_id"]);

    if ($tramo["estado"] === "completado") {
        throw new Exception("El tramo ya estaba completado", 400);
    }
    if ($tramo["estado"] === "cancelado") {
        throw new Exception("No se puede completar un tramo cancelado", 400);
    }

    $viaje_id = (int) $tramo["viaje_id"];
    $tramo_numero = (int) $tramo["tramo_numero"];

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            "UPDATE viaje_tramos SET estado = ? WHERE id = ?",
        );
        $completado = "completado";
        $stmt->bind_param("si", $completado, $tramo_id);
        if (!$stmt->execute()) {
            throw new Exception(
                "Error completando tramo: " . $stmt->error,
                500,
            );
        }
        $stmt->close();

        $stmt = $conn->prepare(
            'SELECT id FROM viaje_tramos
              WHERE viaje_id = ?
                AND tramo_numero > ?
                AND estado = ?
              ORDER BY tramo_numero ASC
              LIMIT 1',
        );
        $pendiente = "pendiente";
        $stmt->bind_param("iis", $viaje_id, $tramo_numero, $pendiente);
        $stmt->execute();
        $siguiente = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $tramo_activado_id = null;
        if ($siguiente) {
            $tramo_activado_id = (int) $siguiente["id"];
            $stmt = $conn->prepare(
                "UPDATE viaje_tramos SET estado = ? WHERE id = ?",
            );
            $en_curso = "en_curso";
            $stmt->bind_param("si", $en_curso, $tramo_activado_id);
            if (!$stmt->execute()) {
                throw new Exception(
                    "Error activando siguiente tramo: " . $stmt->error,
                    500,
                );
            }
            $stmt->close();
        }

        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

    $stmt = $conn->prepare("SELECT * FROM viaje_tramos WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $tramo_id);
    $stmt->execute();
    $tramo_completado = map_tramo_row($stmt->get_result()->fetch_assoc());
    $stmt->close();

    $tramo_activado = null;
    if ($tramo_activado_id) {
        $stmt = $conn->prepare(
            "SELECT * FROM viaje_tramos WHERE id = ? LIMIT 1",
        );
        $stmt->bind_param("i", $tramo_activado_id);
        $stmt->execute();
        $tramo_activado = map_tramo_row($stmt->get_result()->fetch_assoc());
        $stmt->close();
    }

    json_ok([
        "tramo_completado" => $tramo_completado,
        "tramo_activado" => $tramo_activado,
    ]);
}

function handle_actualizar_tramo($conn, $ctx)
{
    assert_can_write($ctx);

    $body = json_decode(file_get_contents("php://input"), true);
    if (!is_array($body)) {
        throw new Exception("JSON invalido", 400);
    }

    $tramo_id = (int) ($body["tramo_id"] ?? 0);
    if ($tramo_id <= 0) {
        throw new Exception("tramo_id requerido", 400);
    }

    $stmt = $conn->prepare(
        'SELECT vt.viaje_id, v.cliente_id
           FROM viaje_tramos vt
           JOIN viajes v ON v.id = vt.viaje_id
          WHERE vt.id = ? LIMIT 1',
    );
    $stmt->bind_param("i", $tramo_id);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$r) {
        throw new Exception("Tramo no encontrado", 404);
    }
    assert_cliente_access($ctx, $r["cliente_id"]);

    $estados_validos = ["pendiente", "en_curso", "completado", "cancelado"];
    $campos_texto = [
        "origen",
        "lugar_carga",
        "destino",
        "ruta",
        "instrucciones",
    ];
    $campos_dt = [
        "salida_patio",
        "cita_carga",
        "salida_carga",
        "descarga_programada",
        "regreso_origen_programado",
        "salida_patio_real",
        "cita_carga_real",
        "salida_carga_real",
        "descarga_real",
        "vacio_real",
        "regreso_origen_real",
    ];
    $campos_reales = [
        "salida_patio_real",
        "cita_carga_real",
        "salida_carga_real",
        "descarga_real",
        "vacio_real",
        "regreso_origen_real",
    ];

    if ($ctx["role"] !== "admin") {
        foreach ($campos_reales as $campo_real) {
            if (array_key_exists($campo_real, $body)) {
                throw new Exception(
                    "Solo admin puede actualizar campos reales de seguimiento",
                    403,
                );
            }
        }
    }

    $sets = [];
    $types = "";
    $params = [];

    foreach ($campos_texto as $campo) {
        if (array_key_exists($campo, $body)) {
            $sets[] = "$campo = ?";
            $types .= "s";
            $params[] = null_if_empty($body[$campo]);
        }
    }
    foreach ($campos_dt as $campo) {
        if (
            in_array(
                $campo,
                ["regreso_origen_programado", "regreso_origen_real"],
                true,
            ) &&
            !db_columns_exist($conn, "viaje_tramos", [$campo])
        ) {
            continue;
        }
        if (array_key_exists($campo, $body)) {
            $sets[] = "$campo = ?";
            $types .= "s";
            $params[] = null_if_empty($body[$campo])
                ? to_datetime($body[$campo])
                : null;
        }
    }
    if (
        array_key_exists("requiere_regreso_origen", $body) &&
        db_columns_exist($conn, "viaje_tramos", ["requiere_regreso_origen"])
    ) {
        $sets[] = "requiere_regreso_origen = ?";
        $types .= "i";
        $params[] = !empty($body["requiere_regreso_origen"]) ? 1 : 0;
    }
    if (array_key_exists("estado", $body)) {
        if (!in_array($body["estado"], $estados_validos, true)) {
            throw new Exception(
                "estado invalido. Valores: " . implode(", ", $estados_validos),
                400,
            );
        }
        $sets[] = "estado = ?";
        $types .= "s";
        $params[] = $body["estado"];
    }

    if (!count($sets)) {
        throw new Exception("No hay campos para actualizar", 400);
    }

    $types .= "i";
    $params[] = $tramo_id;

    $stmt = $conn->prepare(
        "UPDATE viaje_tramos SET " . implode(", ", $sets) . " WHERE id = ?",
    );
    $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) {
        throw new Exception("Error actualizando tramo: " . $stmt->error, 500);
    }
    $stmt->close();

    $stmt = $conn->prepare("SELECT * FROM viaje_tramos WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $tramo_id);
    $stmt->execute();
    $t = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    json_ok(["tramo" => map_tramo_row($t)]);
}

function handle_guardar_gps($conn, $ctx)
{
    assert_can_write($ctx);

    $body = json_decode(file_get_contents("php://input"), true);
    if (!is_array($body)) {
        throw new Exception("JSON invalido", 400);
    }

    $tramo_id = (int) ($body["tramo_id"] ?? 0);
    if ($tramo_id <= 0) {
        throw new Exception("tramo_id requerido", 400);
    }

    $gps_estado = $body["gps_estado"] ?? null;
    if ($gps_estado === null) {
        throw new Exception("gps_estado requerido", 400);
    }

    $stmt = $conn->prepare(
        'SELECT vt.id, v.cliente_id
           FROM viaje_tramos vt
           JOIN viajes v ON v.id = vt.viaje_id
          WHERE vt.id = ? LIMIT 1',
    );
    $stmt->bind_param("i", $tramo_id);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$r) {
        throw new Exception("Tramo no encontrado", 404);
    }
    assert_cliente_access($ctx, $r["cliente_id"]);

    if (is_array($gps_estado)) {
        $gps_estado = json_encode($gps_estado, JSON_UNESCAPED_UNICODE);
    } else {
        $gps_estado = trim((string) $gps_estado);
    }

    $ts =
        isset($body["gps_timestamp"]) && $body["gps_timestamp"]
            ? to_datetime($body["gps_timestamp"])
            : date("Y-m-d H:i:s");

    $stmt = $conn->prepare(
        "UPDATE viaje_tramos SET gps_estado = ?, gps_timestamp = ? WHERE id = ?",
    );
    $stmt->bind_param("ssi", $gps_estado, $ts, $tramo_id);
    if (!$stmt->execute()) {
        throw new Exception("Error guardando GPS: " . $stmt->error, 500);
    }
    $stmt->close();

    $stmt = $conn->prepare("SELECT * FROM viaje_tramos WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $tramo_id);
    $stmt->execute();
    $t = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    json_ok(["tramo" => map_tramo_row($t)]);
}

function handle_agregar_incidencia($conn, $ctx)
{
    assert_can_write($ctx);

    $body = json_decode(file_get_contents("php://input"), true);
    if (!is_array($body)) {
        throw new Exception("JSON invalido", 400);
    }

    $tramo_id = (int) ($body["tramo_id"] ?? 0);
    $tipo = trim((string) ($body["tipo"] ?? ""));
    $severidad = trim((string) ($body["severidad"] ?? "media"));
    $fecha = trim((string) ($body["fecha"] ?? ""));
    $direccion = null_if_empty($body["direccion"] ?? "");
    $notas = null_if_empty($body["notas"] ?? "");

    if ($tramo_id <= 0) {
        throw new Exception("tramo_id requerido", 400);
    }
    if ($tipo === "") {
        throw new Exception("tipo requerido", 400);
    }
    if ($fecha === "") {
        throw new Exception("fecha requerida", 400);
    }
    if (!in_array($severidad, ["alta", "media", "baja"], true)) {
        throw new Exception("severidad invalida (alta|media|baja)", 400);
    }

    $stmt = $conn->prepare(
        'SELECT vt.viaje_id, v.cliente_id
           FROM viaje_tramos vt
           JOIN viajes v ON v.id = vt.viaje_id
          WHERE vt.id = ? LIMIT 1',
    );
    $stmt->bind_param("i", $tramo_id);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$r) {
        throw new Exception("Tramo no encontrado", 404);
    }
    assert_cliente_access($ctx, $r["cliente_id"]);

    $viaje_id = (int) $r["viaje_id"];
    $creado_por = ((int) ($ctx["id"] ?? 0)) ?: null;
    $fecha_dt = to_datetime($fecha);

    $stmt = $conn->prepare(
        'INSERT INTO viaje_incidencias
             (viaje_id, tramo_id, tipo, severidad, fecha, direccion, notas)
         VALUES (?, ?, ?, ?, ?, ?, ?)',
    );
    $stmt->bind_param(
        "iisssss",
        $viaje_id,
        $tramo_id,
        $tipo,
        $severidad,
        $fecha_dt,
        $direccion,
        $notas,
    );
    if (!$stmt->execute()) {
        throw new Exception("Error guardando incidencia: " . $stmt->error, 500);
    }
    $inc_id = $conn->insert_id;
    $stmt->close();

    $stmt = $conn->prepare(
        "SELECT * FROM viaje_incidencias WHERE id = ? LIMIT 1",
    );
    $stmt->bind_param("i", $inc_id);
    $stmt->execute();
    $inc = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    json_ok(
        [
            "incidencia" => [
                "id" => (int) $inc["id"],
                "tramo_id" => (int) $inc["tramo_id"],
                "viaje_id" => (int) $inc["viaje_id"],
                "tipo" => $inc["tipo"],
                "severidad" => $inc["severidad"],
                "fecha" => $inc["fecha"],
                "direccion" => $inc["direccion"],
                "notas" => $inc["notas"],
                "created_at" => $inc["created_at"],
            ],
        ],
        201,
    );
}

function handle_eliminar_incidencia($conn, $ctx)
{
    assert_can_write($ctx);

    $body = json_decode(file_get_contents("php://input"), true);
    if (!is_array($body)) {
        throw new Exception("JSON invalido", 400);
    }

    $inc_id = (int) ($body["incidencia_id"] ?? 0);
    if ($inc_id <= 0) {
        throw new Exception("incidencia_id requerido", 400);
    }

    $stmt = $conn->prepare(
        'SELECT vi.id, v.cliente_id
           FROM viaje_incidencias vi
           JOIN viajes v ON v.id = vi.viaje_id
          WHERE vi.id = ? LIMIT 1',
    );
    $stmt->bind_param("i", $inc_id);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$r) {
        throw new Exception("Incidencia no encontrada", 404);
    }
    assert_cliente_access($ctx, $r["cliente_id"]);

    $stmt = $conn->prepare("DELETE FROM viaje_incidencias WHERE id = ?");
    $stmt->bind_param("i", $inc_id);
    if (!$stmt->execute()) {
        throw new Exception(
            "Error eliminando incidencia: " . $stmt->error,
            500,
        );
    }
    $stmt->close();

    json_ok(["eliminado" => true]);
}

function handle_eliminar_tramo($conn, $ctx)
{
    assert_can_write($ctx);

    $body = json_decode(file_get_contents("php://input"), true);
    if (!is_array($body)) {
        throw new Exception("JSON invalido", 400);
    }

    $tramo_id = (int) ($body["tramo_id"] ?? 0);
    if ($tramo_id <= 0) {
        throw new Exception("tramo_id requerido", 400);
    }

    $stmt = $conn->prepare(
        'SELECT vt.viaje_id, vt.estado, v.cliente_id
           FROM viaje_tramos vt
           JOIN viajes v ON v.id = vt.viaje_id
          WHERE vt.id = ? LIMIT 1',
    );
    $stmt->bind_param("i", $tramo_id);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$r) {
        throw new Exception("Tramo no encontrado", 404);
    }
    assert_cliente_access($ctx, $r["cliente_id"]);

    if ($r["estado"] === "cancelado") {
        json_ok([
            "tramo_id" => $tramo_id,
            "estado" => "cancelado",
            "msg" => "Ya estaba cancelado",
        ]);
    }

    $stmt = $conn->prepare(
        "UPDATE viaje_tramos SET estado = 'cancelado' WHERE id = ?",
    );
    $stmt->bind_param("i", $tramo_id);
    if (!$stmt->execute()) {
        throw new Exception("Error cancelando tramo: " . $stmt->error, 500);
    }
    $stmt->close();

    json_ok(["tramo_id" => $tramo_id, "estado" => "cancelado"]);
}

function handle_eliminar_viaje($conn, $ctx)
{
    assert_can_write($ctx);

    $body = json_decode(file_get_contents("php://input"), true);
    if (!is_array($body)) {
        throw new Exception("JSON invalido", 400);
    }

    $viaje_id = (int) ($body["viaje_id"] ?? 0);
    if ($viaje_id <= 0) {
        throw new Exception("viaje_id requerido", 400);
    }

    $stmt = $conn->prepare(
        "SELECT id, estado, cliente_id FROM viajes WHERE id = ? LIMIT 1",
    );
    $stmt->bind_param("i", $viaje_id);
    $stmt->execute();
    $v = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$v) {
        throw new Exception("Viaje no encontrado", 404);
    }
    assert_cliente_access($ctx, $v["cliente_id"]);

    if ($v["estado"] === "cancelado") {
        json_ok([
            "viaje_id" => $viaje_id,
            "estado" => "cancelado",
            "msg" => "Ya estaba cancelado",
        ]);
    }

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            "UPDATE viajes SET estado = 'cancelado' WHERE id = ?",
        );
        $stmt->bind_param("i", $viaje_id);
        if (!$stmt->execute()) {
            throw new Exception("Error cancelando viaje: " . $stmt->error, 500);
        }
        $stmt->close();

        $stmt = $conn->prepare(
            "UPDATE viaje_tramos SET estado = 'cancelado'
              WHERE viaje_id = ? AND estado != 'cancelado'",
        );
        $stmt->bind_param("i", $viaje_id);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

    json_ok(["viaje_id" => $viaje_id, "estado" => "cancelado"]);
}

function handle_unidades($conn, $ctx)
{
    $cliente_id = (int) ($_GET["cliente_id"] ?? 0);
    if ($cliente_id <= 0) {
        throw new Exception("cliente_id requerido", 400);
    }
    assert_cliente_access($ctx, $cliente_id);

    $stmt = $conn->prepare(
        'SELECT id, economico, placas, operador, telefonos, equipos
           FROM unidades
          WHERE cliente_id = ? AND activo = 1
          ORDER BY economico ASC',
    );
    $stmt->bind_param("i", $cliente_id);
    $stmt->execute();
    $res = $stmt->get_result();

    $unidades = [];
    while ($r = $res->fetch_assoc()) {
        $unidades[] = [
            "id" => (int) $r["id"],
            "economico" => $r["economico"],
            "placas" => $r["placas"],
            "operador" => $r["operador"],
            "telefonos" => $r["telefonos"],
            "equipos" => $r["equipos"],
        ];
    }
    $stmt->close();

    json_ok(["unidades" => $unidades]);
}

function handle_actualizar_unidad($conn, $ctx)
{
    assert_can_write($ctx);

    $body = json_decode(file_get_contents("php://input"), true);
    if (!is_array($body)) {
        throw new Exception("JSON invalido", 400);
    }

    $unidad_id = (int) ($body["unidad_id"] ?? 0);
    $cliente_id = (int) ($body["cliente_id"] ?? 0);
    $economico = str_val($body["economico"] ?? "");

    if ($unidad_id > 0) {
        $stmt = $conn->prepare(
            "SELECT id, cliente_id, economico FROM unidades WHERE id = ? AND activo = 1 LIMIT 1",
        );
        $stmt->bind_param("i", $unidad_id);
    } elseif ($cliente_id > 0 && $economico !== "") {
        $stmt = $conn->prepare(
            'SELECT id, cliente_id, economico FROM unidades
              WHERE cliente_id = ? AND economico = ? AND activo = 1 LIMIT 1',
        );
        $stmt->bind_param("is", $cliente_id, $economico);
    } else {
        throw new Exception(
            "Se requiere unidad_id o (cliente_id + economico)",
            400,
        );
    }
    $stmt->execute();
    $unidad = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$unidad) {
        throw new Exception("Unidad no encontrada", 404);
    }
    assert_cliente_access($ctx, (int) $unidad["cliente_id"]);
    $unidad_id = (int) $unidad["id"];

    $campos = ["placas", "operador", "telefonos", "equipos"];
    $sets = [];
    $types = "";
    $params = [];

    foreach ($campos as $campo) {
        if (array_key_exists($campo, $body)) {
            $sets[] = "$campo = ?";
            $types .= "s";
            $params[] = null_if_empty($body[$campo]);
        }
    }

    if (array_key_exists("operador_id", $body)) {
        $sets[] = "operador_id = ?";
        $types .= "i";
        $oid = (int) $body["operador_id"];
        $params[] = $oid > 0 ? $oid : null;
    }

    if (array_key_exists("economico_nuevo", $body)) {
        $eco_nuevo = str_val($body["economico_nuevo"]);
        if ($eco_nuevo === "") {
            throw new Exception("El económico no puede quedar vacío", 400);
        }
        if ($eco_nuevo !== $unidad["economico"]) {
            $chk = $conn->prepare(
                'SELECT id FROM unidades
                  WHERE cliente_id = ? AND economico = ? AND id <> ? AND activo = 1 LIMIT 1',
            );
            $chk->bind_param(
                "isi",
                $unidad["cliente_id"],
                $eco_nuevo,
                $unidad_id,
            );
            $chk->execute();
            $dup = $chk->get_result()->fetch_assoc();
            $chk->close();
            if ($dup) {
                throw new Exception(
                    "Ya existe otra unidad con ese económico",
                    409,
                );
            }

            $sets[] = "economico = ?";
            $types .= "s";
            $params[] = $eco_nuevo;
        }
    }

    if (!count($sets)) {
        throw new Exception("No hay campos para actualizar", 400);
    }

    $types .= "i";
    $params[] = $unidad_id;

    $stmt = $conn->prepare(
        "UPDATE unidades SET " . implode(", ", $sets) . " WHERE id = ?",
    );
    $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) {
        throw new Exception("Error actualizando unidad: " . $stmt->error, 500);
    }
    $stmt->close();

    $stmt = $conn->prepare(
        'SELECT id, cliente_id, economico, placas, operador, telefonos, equipos
           FROM unidades WHERE id = ? LIMIT 1',
    );
    $stmt->bind_param("i", $unidad_id);
    $stmt->execute();
    $u = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    json_ok([
        "unidad" => [
            "id" => (int) $u["id"],
            "cliente_id" => (int) $u["cliente_id"],
            "economico" => $u["economico"],
            "placas" => $u["placas"],
            "operador" => $u["operador"],
            "telefonos" => $u["telefonos"],
            "equipos" => $u["equipos"],
        ],
    ]);
}

function handle_actualizar_despacho($conn, $ctx)
{
    assert_can_write($ctx);

    $body = json_decode(file_get_contents("php://input"), true);
    if (!is_array($body)) {
        throw new Exception("JSON invalido", 400);
    }

    $despacho_id = (int) ($body["despacho_id"] ?? 0);
    if ($despacho_id <= 0) {
        throw new Exception("despacho_id requerido", 400);
    }

    $stmt = $conn->prepare(
        'SELECT id, cliente_id, unidad_id, folio, tramo_numero
           FROM despachos WHERE id = ? AND eliminado_at IS NULL LIMIT 1',
    );
    $stmt->bind_param("i", $despacho_id);
    $stmt->execute();
    $desp = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$desp) {
        throw new Exception("Despacho no encontrado", 404);
    }
    assert_cliente_access($ctx, (int) $desp["cliente_id"]);

    $folio_viejo = (string) $desp["folio"];
    $cliente_id = (int) $desp["cliente_id"];
    $unidad_id = (int) $desp["unidad_id"];

    $folio_nuevo = null;
    if (array_key_exists("folio", $body)) {
        $folio_nuevo = trim((string) $body["folio"]);
        if ($folio_nuevo === "") {
            throw new Exception("El folio no puede quedar vacío", 400);
        }
        if ($folio_nuevo === $folio_viejo) {
            $folio_nuevo = null; // sin cambios reales
        }
    }

    if ($folio_nuevo !== null) {
        $stmt = $conn->prepare(
            "SELECT id FROM despachos
              WHERE cliente_id = ? AND unidad_id = ? AND folio = ? AND eliminado_at IS NULL
              LIMIT 1",
        );
        $stmt->bind_param("iis", $cliente_id, $unidad_id, $folio_nuevo);
        $stmt->execute();
        $existe = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($existe) {
            throw new Exception(
                "El folio '$folio_nuevo' ya existe para esta unidad",
                409,
            );
        }
    }

    $campos = ["ruta", "origen", "lugar_carga", "destino", "instrucciones"];
    $sets = [];
    $types = "";
    $params = [];

    foreach ($campos as $campo) {
        if (array_key_exists($campo, $body)) {
            $sets[] = "$campo = ?";
            $types .= "s";
            $params[] = null_if_empty($body[$campo]);
        }
    }

    if (!count($sets) && $folio_nuevo === null) {
        throw new Exception("No hay campos para actualizar", 400);
    }

    $tramos_sincronizados = 0;
    $folio_actualizado = false;

    $conn->begin_transaction();
    try {
        if (count($sets)) {
            $types_d = $types . "i";
            $params_d = $params;
            $params_d[] = $despacho_id;
            $stmt = $conn->prepare(
                "UPDATE despachos SET " .
                    implode(", ", $sets) .
                    " WHERE id = ?",
            );
            $stmt->bind_param($types_d, ...$params_d);
            if (!$stmt->execute()) {
                throw new Exception(
                    "Error actualizando despacho: " . $stmt->error,
                    500,
                );
            }
            $stmt->close();
        }

        $stmt = $conn->prepare(
            "SELECT vt.id
               FROM viaje_tramos vt
               JOIN viajes v ON v.id = vt.viaje_id
              WHERE v.cliente_id = ? AND v.unidad_id = ? AND v.folio = ?
                AND vt.tramo_numero = ? AND v.estado <> 'cancelado'",
        );
        $stmt->bind_param(
            "iisi",
            $cliente_id,
            $unidad_id,
            $folio_viejo,
            $desp["tramo_numero"],
        );
        $stmt->execute();
        $res_t = $stmt->get_result();
        $tramo_ids = [];
        while ($r = $res_t->fetch_assoc()) {
            $tramo_ids[] = (int) $r["id"];
        }
        $stmt->close();

        if (count($sets) && count($tramo_ids)) {
            $types_t = $types . "i";
            foreach ($tramo_ids as $tid) {
                $params_t = $params;
                $params_t[] = $tid;
                $stmt = $conn->prepare(
                    "UPDATE viaje_tramos SET " .
                        implode(", ", $sets) .
                        " WHERE id = ?",
                );
                $stmt->bind_param($types_t, ...$params_t);
                if ($stmt->execute()) {
                    $tramos_sincronizados++;
                }
                $stmt->close();
            }
        }
        if ($folio_nuevo !== null) {
            $stmt = $conn->prepare(
                "UPDATE despachos SET folio = ?
                  WHERE cliente_id = ? AND unidad_id = ? AND folio = ? AND eliminado_at IS NULL",
            );
            $stmt->bind_param(
                "siis",
                $folio_nuevo,
                $cliente_id,
                $unidad_id,
                $folio_viejo,
            );
            if (!$stmt->execute()) {
                throw new Exception(
                    "Error actualizando folio en despachos: " . $stmt->error,
                    500,
                );
            }
            $stmt->close();

            $stmt = $conn->prepare(
                "UPDATE viajes SET folio = ?
                  WHERE cliente_id = ? AND unidad_id = ? AND folio = ? AND estado <> 'cancelado'",
            );
            $stmt->bind_param(
                "siis",
                $folio_nuevo,
                $cliente_id,
                $unidad_id,
                $folio_viejo,
            );
            if (!$stmt->execute()) {
                throw new Exception(
                    "Error actualizando folio en viajes: " . $stmt->error,
                    500,
                );
            }
            $stmt->close();
            $folio_actualizado = true;
        }

        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

    $stmt = $conn->prepare(
        'SELECT id, folio, tramo_numero, ruta, origen, lugar_carga, destino, instrucciones
           FROM despachos WHERE id = ? LIMIT 1',
    );
    $stmt->bind_param("i", $despacho_id);
    $stmt->execute();
    $d = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    json_ok([
        "despacho" => [
            "id" => (int) $d["id"],
            "folio" => $d["folio"],
            "tramo_numero" => (int) $d["tramo_numero"],
            "ruta" => $d["ruta"],
            "origen" => $d["origen"],
            "lugar_carga" => $d["lugar_carga"],
            "destino" => $d["destino"],
            "instrucciones" => $d["instrucciones"],
        ],
        "tramos_sincronizados" => $tramos_sincronizados,
        "folio_actualizado" => $folio_actualizado,
    ]);
}

function handle_eliminar_despacho($conn, $ctx)
{
    assert_can_write($ctx);

    $body = json_decode(file_get_contents("php://input"), true);
    if (!is_array($body)) {
        throw new Exception("JSON invalido", 400);
    }

    $despacho_id = (int) ($body["despacho_id"] ?? 0);
    if ($despacho_id <= 0) {
        throw new Exception("despacho_id requerido", 400);
    }
    $motivo = null_if_empty($body["motivo"] ?? "");

    $stmt = $conn->prepare(
        'SELECT id, cliente_id, unidad_id, folio, tramo_numero, eliminado_at
           FROM despachos WHERE id = ? LIMIT 1',
    );
    $stmt->bind_param("i", $despacho_id);
    $stmt->execute();
    $desp = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$desp) {
        throw new Exception("Despacho no encontrado", 404);
    }
    assert_cliente_access($ctx, (int) $desp["cliente_id"]);

    if ($desp["eliminado_at"] !== null) {
        json_ok([
            "despacho_id" => $despacho_id,
            "eliminado" => true,
            "msg" => "Ya estaba eliminado",
        ]);
    }

    $uid = (int) $ctx["id"];
    $tramos_cancelados = 0;

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            'UPDATE despachos
                SET eliminado_at = NOW(), eliminado_por_usuario_id = ?, eliminado_motivo = ?
              WHERE id = ?',
        );
        $stmt->bind_param("isi", $uid, $motivo, $despacho_id);
        if (!$stmt->execute()) {
            throw new Exception(
                "Error eliminando despacho: " . $stmt->error,
                500,
            );
        }
        $stmt->close();

        $stmt = $conn->prepare(
            "SELECT vt.id
               FROM viaje_tramos vt
               JOIN viajes v ON v.id = vt.viaje_id
              WHERE v.cliente_id = ? AND v.unidad_id = ? AND v.folio = ?
                AND vt.tramo_numero = ? AND v.estado <> 'cancelado' AND vt.estado <> 'cancelado'",
        );
        $stmt->bind_param(
            "iisi",
            $desp["cliente_id"],
            $desp["unidad_id"],
            $desp["folio"],
            $desp["tramo_numero"],
        );
        $stmt->execute();
        $res_t = $stmt->get_result();
        $tramo_ids = [];
        while ($r = $res_t->fetch_assoc()) {
            $tramo_ids[] = (int) $r["id"];
        }
        $stmt->close();

        foreach ($tramo_ids as $tid) {
            $stmt = $conn->prepare(
                "UPDATE viaje_tramos
                    SET estado = 'cancelado',
                        eliminado_at = NOW(),
                        eliminado_por_usuario_id = ?,
                        eliminado_motivo = ?
                  WHERE id = ?",
            );
            $stmt->bind_param("isi", $uid, $motivo, $tid);
            if ($stmt->execute()) {
                $tramos_cancelados++;
            }
            $stmt->close();
        }

        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

    json_ok([
        "despacho_id" => $despacho_id,
        "eliminado" => true,
        "tramos_cancelados" => $tramos_cancelados,
    ]);
}

function handle_eliminar_registro($conn, $ctx)
{
    assert_can_write($ctx);

    $body = json_decode(file_get_contents("php://input"), true);
    if (!is_array($body)) {
        throw new Exception("JSON invalido", 400);
    }

    $cliente_id = (int) ($body["cliente_id"] ?? 0);
    $unidad_id = (int) ($body["unidad_id"] ?? 0);
    $folio = str_val($body["folio"] ?? "");
    $motivo = null_if_empty($body["motivo"] ?? "");

    if ($cliente_id <= 0 || $unidad_id <= 0 || $folio === "") {
        throw new Exception("cliente_id, unidad_id y folio requeridos", 400);
    }
    assert_cliente_access($ctx, $cliente_id);

    $uid = (int) $ctx["id"];
    $despachos_eliminados = 0;
    $viajes_cancelados = 0;
    $tramos_cancelados = 0;

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            'UPDATE despachos
                SET eliminado_at = NOW(), eliminado_por_usuario_id = ?, eliminado_motivo = ?
              WHERE cliente_id = ? AND unidad_id = ? AND folio = ? AND eliminado_at IS NULL',
        );
        $stmt->bind_param(
            "isiis",
            $uid,
            $motivo,
            $cliente_id,
            $unidad_id,
            $folio,
        );
        if (!$stmt->execute()) {
            throw new Exception(
                "Error eliminando despachos: " . $stmt->error,
                500,
            );
        }
        $despachos_eliminados = $stmt->affected_rows;
        $stmt->close();

        $stmt = $conn->prepare(
            "SELECT id FROM viajes
              WHERE cliente_id = ? AND unidad_id = ? AND folio = ? AND estado <> 'cancelado'",
        );
        $stmt->bind_param("iis", $cliente_id, $unidad_id, $folio);
        $stmt->execute();
        $res_v = $stmt->get_result();
        $viaje_ids = [];
        while ($r = $res_v->fetch_assoc()) {
            $viaje_ids[] = (int) $r["id"];
        }
        $stmt->close();

        foreach ($viaje_ids as $vid) {
            $stmt = $conn->prepare(
                "UPDATE viajes
                    SET estado = 'cancelado',
                        eliminado_at = NOW(),
                        eliminado_por_usuario_id = ?,
                        eliminado_motivo = ?
                  WHERE id = ?",
            );
            $stmt->bind_param("isi", $uid, $motivo, $vid);
            if ($stmt->execute()) {
                $viajes_cancelados++;
            }
            $stmt->close();

            $stmt = $conn->prepare(
                "UPDATE viaje_tramos
                    SET estado = 'cancelado',
                        eliminado_at = NOW(),
                        eliminado_por_usuario_id = ?,
                        eliminado_motivo = ?
                  WHERE viaje_id = ? AND estado <> 'cancelado'",
            );
            $stmt->bind_param("isi", $uid, $motivo, $vid);
            $stmt->execute();
            $tramos_cancelados += $stmt->affected_rows;
            $stmt->close();
        }

        if ($despachos_eliminados === 0 && $viajes_cancelados === 0) {
            $conn->rollback();
            throw new Exception(
                "No se encontró un registro activo para eliminar",
                404,
            );
        }

        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

    json_ok([
        "folio" => $folio,
        "despachos_eliminados" => $despachos_eliminados,
        "viajes_cancelados" => $viajes_cancelados,
        "tramos_cancelados" => $tramos_cancelados,
    ]);
}

function handle_duplicar($conn, $ctx)
{
    assert_can_write($ctx);

    $body = json_decode(file_get_contents("php://input"), true);
    if (!is_array($body)) {
        throw new Exception("JSON invalido", 400);
    }

    $viaje_id = (int) ($body["viaje_id"] ?? 0);
    $nueva_fecha_inicio = to_date($body["nueva_fecha_inicio"] ?? "");
    $ignorar_conflictos = !empty($body["ignorar_conflictos"]);

    if ($viaje_id <= 0) {
        throw new Exception("viaje_id requerido", 400);
    }
    if (!$nueva_fecha_inicio) {
        throw new Exception("nueva_fecha_inicio requerida (YYYY-MM-DD)", 400);
    }

    $stmt = $conn->prepare(
        'SELECT v.*, c.nombre AS cliente_nombre, u.economico
           FROM viajes v
           JOIN clientes c ON c.id = v.cliente_id
           JOIN unidades u ON u.id = v.unidad_id
          WHERE v.id = ? LIMIT 1',
    );
    $stmt->bind_param("i", $viaje_id);
    $stmt->execute();
    $orig = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$orig) {
        throw new Exception("Viaje original no encontrado", 404);
    }
    assert_cliente_access($ctx, $orig["cliente_id"]);

    $dt_orig_inicio = new DateTime($orig["fecha_inicio"]);
    $dt_nueva = new DateTime($nueva_fecha_inicio);
    $delta_dias = (int) $dt_orig_inicio->diff($dt_nueva)->format("%r%a"); // positivo = hacia adelante

    $nueva_fecha_fin = null;
    if ($orig["fecha_fin"]) {
        $dt_fin = new DateTime($orig["fecha_fin"]);
        $dt_fin->modify("{$delta_dias} days");
        $nueva_fecha_fin = $dt_fin->format("Y-m-d");
    }

    if (!$ignorar_conflictos) {
        $conflictos = detectar_conflictos(
            $conn,
            (int) $orig["unidad_id"],
            $nueva_fecha_inicio,
            $nueva_fecha_fin,
        );
        if (count($conflictos) > 0) {
            json_err_conflicto($conflictos);
        }
    }

    $stmt = $conn->prepare(
        "SELECT * FROM viaje_tramos WHERE viaje_id = ? ORDER BY tramo_numero ASC",
    );
    $stmt->bind_param("i", $viaje_id);
    $stmt->execute();
    $tramos_orig = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $desplazar_dt = function ($dt_str) use ($delta_dias) {
        if (!$dt_str) {
            return null;
        }
        try {
            $dt = new DateTime($dt_str);
            $dt->modify("{$delta_dias} days");
            return $dt->format("Y-m-d H:i:s");
        } catch (Exception $e) {
            return null;
        }
    };

    $conn->begin_transaction();
    try {
        $uid = $ctx["id"];
        $nuevo_folio = $orig["folio"] . "-COPIA";

        $stmt = $conn->prepare(
            'INSERT INTO viajes
               (cliente_id, unidad_id, folio, fecha_inicio, fecha_fin, estado, notas, created_by_usuario_id)
             VALUES (?, ?, ?, ?, ?, \'planificado\', ?, ?)',
        );
        $stmt->bind_param(
            "iisssis",
            $orig["cliente_id"],
            $orig["unidad_id"],
            $nuevo_folio,
            $nueva_fecha_inicio,
            $nueva_fecha_fin,
            $orig["notas"],
            $uid,
        );
        if (!$stmt->execute()) {
            throw new Exception("Error clonando viaje: " . $stmt->error, 500);
        }
        $nuevo_viaje_id = (int) $stmt->insert_id;
        $stmt->close();

        foreach ($tramos_orig as $t) {
            $tramo_data = [
                "origen" => $t["origen"],
                "lugar_carga" => $t["lugar_carga"],
                "destino" => $t["destino"],
                "ruta" => $t["ruta"],
                "instrucciones" => $t["instrucciones"],
                "salida_patio" => $desplazar_dt($t["salida_patio"]),
                "cita_carga" => $desplazar_dt($t["cita_carga"]),
                "salida_carga" => $desplazar_dt($t["salida_carga"]),
                "descarga_programada" => $desplazar_dt(
                    $t["descarga_programada"],
                ),
            ];
            insertar_tramo(
                $conn,
                $nuevo_viaje_id,
                (int) $t["tramo_numero"],
                $tramo_data,
            );
        }

        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

    json_ok(handle_obtener_data($conn, $nuevo_viaje_id), 201);
}

function detectar_conflictos(
    $conn,
    $unidad_id,
    $fecha_inicio,
    $fecha_fin,
    $excluir_viaje_id = null,
) {
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
    $types = "iss";

    if ($excluir_viaje_id) {
        $sql .= " AND v.id != ?";
        $types .= "i";
        $params[] = $excluir_viaje_id;
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception(
            "Error preparando deteccion de conflictos: " . $conn->error,
            500,
        );
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    $conflictos = [];
    while ($r = $res->fetch_assoc()) {
        $conflictos[] = [
            "viaje_id" => (int) $r["viaje_id"],
            "folio" => $r["folio"],
            "fecha_inicio" => $r["fecha_inicio"],
            "fecha_fin" => $r["fecha_fin"],
            "estado" => $r["estado"],
            "economico" => $r["economico"],
        ];
    }
    $stmt->close();
    return $conflictos;
}

function json_err_conflicto($conflictos)
{
    while (ob_get_level()) {
        ob_end_clean();
    }
    header_remove();
    header("Content-Type: application/json; charset=utf-8");
    http_response_code(409);
    echo json_encode(
        [
            "ok" => false,
            "conflicto" => true,
            "error" =>
                "La unidad ya tiene viajes activos en ese rango de fechas",
            "conflictos" => $conflictos,
        ],
        JSON_UNESCAPED_UNICODE,
    );
    exit();
}

function handle_verificar_conflictos($conn, $ctx)
{
    $unidad_id = (int) ($_GET["unidad_id"] ?? 0);
    $fecha_inicio = to_date($_GET["fecha_inicio"] ?? "");
    $fecha_fin = to_date($_GET["fecha_fin"] ?? "");
    $excluir_viaje_id = isset($_GET["excluir_viaje_id"])
        ? (int) $_GET["excluir_viaje_id"]
        : null;

    if ($unidad_id <= 0) {
        throw new Exception("unidad_id requerido", 400);
    }
    if (!$fecha_inicio) {
        throw new Exception("fecha_inicio requerida", 400);
    }

    $stmt = $conn->prepare(
        "SELECT u.id, u.economico, u.cliente_id FROM unidades u WHERE u.id = ? LIMIT 1",
    );
    $stmt->bind_param("i", $unidad_id);
    $stmt->execute();
    $u = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$u) {
        throw new Exception("Unidad no encontrada", 404);
    }
    assert_cliente_access($ctx, $u["cliente_id"]);

    $conflictos = detectar_conflictos(
        $conn,
        $unidad_id,
        $fecha_inicio,
        $fecha_fin,
        $excluir_viaje_id,
    );

    json_ok([
        "tiene_conflicto" => count($conflictos) > 0,
        "conflictos" => $conflictos,
        "unidad_id" => $unidad_id,
        "economico" => $u["economico"],
        "fecha_inicio" => $fecha_inicio,
        "fecha_fin" => $fecha_fin,
    ]);
}

function firma_ruta_normalizada($origen, $lugar_carga, $destino)
{
    $norm = fn($v) => utf8_lower_v(trim((string) ($v ?? "")));
    $firma = $norm($origen) . "|" . $norm($lugar_carga) . "|" . $norm($destino);
    return $firma === "||" ? null : $firma;
}

function upsert_historial_ruta($conn, $cliente_id, $tramo)
{
    $origen = null_if_empty($tramo["origen"] ?? "");
    $lugar_carga = null_if_empty($tramo["lugar_carga"] ?? "");
    $destino = null_if_empty($tramo["destino"] ?? "");
    $ruta = null_if_empty($tramo["ruta"] ?? "");
    $instrucciones = null_if_empty($tramo["instrucciones"] ?? "");

    $firma = firma_ruta_normalizada($origen, $lugar_carga, $destino);
    if ($firma === null) {
        return;
    }

    $etiqueta = mb_substr(
        ($origen ?? "Origen") . " → " . ($destino ?? "Destino"),
        0,
        100,
        "UTF-8",
    );

    $stmt = $conn->prepare(
        "INSERT INTO tramos_catalogo
            (cliente_id, etiqueta, ruta, origen, lugar_carga, destino, instrucciones,
             origen_tipo, firma_ruta, veces_usada, ultima_vez_usada, es_favorito, activo)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'historial', ?, 1, NOW(), 0, 1)
         ON DUPLICATE KEY UPDATE
            veces_usada      = veces_usada + 1,
            ultima_vez_usada = NOW(),
            -- refresca instrucciones/ruta solo si llegan no vacías
            instrucciones    = COALESCE(VALUES(instrucciones), instrucciones),
            ruta             = COALESCE(VALUES(ruta), ruta),
            -- si estaba oculta (soft-deleted) y se vuelve a usar, reactivar
            activo           = 1",
    );
    $stmt->bind_param(
        "isssssss",
        $cliente_id,
        $etiqueta,
        $ruta,
        $origen,
        $lugar_carga,
        $destino,
        $instrucciones,
        $firma,
    );
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new Exception(
            "Error registrando historial de ruta: " . $err,
            500,
        );
    }
    $stmt->close();
}

function handle_buscar_rutas_historial($conn, $ctx)
{
    $cliente_id = (int) ($_GET["cliente_id"] ?? 0);
    if ($cliente_id <= 0) {
        throw new Exception("cliente_id requerido", 400);
    }
    assert_cliente_access($ctx, $cliente_id);

    $q = trim((string) ($_GET["q"] ?? ""));
    $limit = min(max((int) ($_GET["limit"] ?? 10), 1), 10);
    $like = "%" . utf8_lower_v($q) . "%";
    $rutas_por_firma = [];

    $agregar_ruta = function ($row, $source_rank) use (&$rutas_por_firma) {
        $ruta = trim((string) ($row["ruta"] ?? ""));
        $origen = trim((string) ($row["origen"] ?? ""));
        $lugar_carga = trim((string) ($row["lugar_carga"] ?? ""));
        $destino = trim((string) ($row["destino"] ?? ""));
        $firma = utf8_lower_v(
            $ruta . "|" . $origen . "|" . $lugar_carga . "|" . $destino,
        );
        if ($firma === "|||") {
            return;
        }
        $recorrido = trim(
            ($origen ?: "Origen") . " -> " . ($destino ?: "Destino"),
        );
        $item = [
            "ruta" => $ruta,
            "origen" => $origen,
            "lugar_carga" => $lugar_carga,
            "destino" => $destino,
            "veces_usada" => (int) ($row["veces_usada"] ?? 0),
            "ultima_vez_usada" => $row["ultima_vez_usada"] ?? null,
            "etiqueta" =>
                $ruta !== "" ? $ruta . " · " . $recorrido : $recorrido,
            "_source_rank" => $source_rank,
            "_prioridad" => (int) ($row["prioridad"] ?? 9),
        ];

        if (!isset($rutas_por_firma[$firma])) {
            $rutas_por_firma[$firma] = $item;
            return;
        }

        $actual = $rutas_por_firma[$firma];
        $mejor = [
            $item["_prioridad"],
            $item["_source_rank"],
            -$item["veces_usada"],
        ];
        $prev = [
            $actual["_prioridad"],
            $actual["_source_rank"],
            -$actual["veces_usada"],
        ];
        if ($mejor < $prev) {
            $rutas_por_firma[$firma] = $item;
        }
    };

    if (db_table_exists($conn, "tramos_catalogo")) {
        $has_historial_cols = db_columns_exist($conn, "tramos_catalogo", [
            "veces_usada",
            "ultima_vez_usada",
            "es_favorito",
        ]);
        $catalogWhere = ["cliente_id = ?", "activo = 1"];
        $catalogWhereTypes = "i";
        $catalogWhereParams = [$cliente_id];
        $catalogPriority = "9";
        $catalogPriorityTypes = "";
        $catalogPriorityParams = [];
        if ($q !== "") {
            $catalogPriority = "CASE
                WHEN LOWER(COALESCE(ruta, '')) LIKE ? THEN 0
                WHEN LOWER(COALESCE(etiqueta, '')) LIKE ? THEN 0
                WHEN LOWER(COALESCE(origen, '')) LIKE ? THEN 1
                WHEN LOWER(COALESCE(lugar_carga, '')) LIKE ? THEN 2
                WHEN LOWER(COALESCE(destino, '')) LIKE ? THEN 3
                ELSE 4
            END";
            $catalogPriorityTypes = "sssss";
            $catalogPriorityParams = [$like, $like, $like, $like, $like];
            $catalogWhere[] = "(
                LOWER(COALESCE(ruta, '')) LIKE ?
                OR LOWER(COALESCE(etiqueta, '')) LIKE ?
                OR LOWER(COALESCE(origen, '')) LIKE ?
                OR LOWER(COALESCE(lugar_carga, '')) LIKE ?
                OR LOWER(COALESCE(destino, '')) LIKE ?
            )";
            $catalogWhereTypes .= "sssss";
            array_push($catalogWhereParams, $like, $like, $like, $like, $like);
        }

        $vecesExpr = $has_historial_cols ? "COALESCE(veces_usada, 0)" : "0";
        $ultimaExpr = $has_historial_cols
            ? "COALESCE(ultima_vez_usada, updated_at, created_at)"
            : "COALESCE(updated_at, created_at)";
        $favoritoExpr = $has_historial_cols ? "es_favorito" : "1";
        $catalogSql =
            "
            SELECT ruta, origen, lugar_carga, destino,
                   $vecesExpr AS veces_usada,
                   $ultimaExpr AS ultima_vez_usada,
                   $catalogPriority AS prioridad,
                   $favoritoExpr AS es_favorito
              FROM tramos_catalogo
             WHERE " .
            implode(" AND ", $catalogWhere) .
            "
             ORDER BY prioridad ASC, es_favorito DESC, veces_usada DESC, ultima_vez_usada DESC
             LIMIT ?";
        $catalogTypes = $catalogPriorityTypes . $catalogWhereTypes . "i";
        $catalogParams = array_merge(
            $catalogPriorityParams,
            $catalogWhereParams,
            [$limit],
        );

        $stmt = $conn->prepare($catalogSql);
        if (!$stmt) {
            throw new Exception(
                "Error preparando busqueda de catalogo: " . $conn->error,
                500,
            );
        }
        $refs = [$catalogTypes];
        foreach ($catalogParams as $k => $v) {
            $refs[] = &$catalogParams[$k];
        }
        call_user_func_array([$stmt, "bind_param"], $refs);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new Exception(
                "Error buscando catalogo de rutas: " . $err,
                500,
            );
        }
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $agregar_ruta(
                $row,
                ((int) ($row["es_favorito"] ?? 0)) === 1 ? 0 : 1,
            );
        }
        $stmt->close();
    }

    $where = [
        "v.cliente_id = ?",
        "(vt.estado IS NULL OR vt.estado <> 'cancelado')",
        "(NULLIF(TRIM(COALESCE(vt.ruta, '')), '') IS NOT NULL
          OR NULLIF(TRIM(COALESCE(vt.origen, '')), '') IS NOT NULL
          OR NULLIF(TRIM(COALESCE(vt.lugar_carga, '')), '') IS NOT NULL
          OR NULLIF(TRIM(COALESCE(vt.destino, '')), '') IS NOT NULL)",
    ];
    $whereTypes = "i";
    $whereParams = [$cliente_id];
    $prioritySql = "9";
    $priorityTypes = "";
    $priorityParams = [];
    if ($q !== "") {
        $prioritySql = "CASE
            WHEN LOWER(COALESCE(vt.ruta, '')) LIKE ? THEN 0
            WHEN LOWER(COALESCE(vt.origen, '')) LIKE ? THEN 1
            WHEN LOWER(COALESCE(vt.lugar_carga, '')) LIKE ? THEN 2
            WHEN LOWER(COALESCE(vt.destino, '')) LIKE ? THEN 3
            ELSE 4
        END";
        $priorityTypes = "ssss";
        $priorityParams = [$like, $like, $like, $like];
        $where[] = "(
            LOWER(COALESCE(vt.ruta, '')) LIKE ?
            OR LOWER(COALESCE(vt.origen, '')) LIKE ?
            OR LOWER(COALESCE(vt.lugar_carga, '')) LIKE ?
            OR LOWER(COALESCE(vt.destino, '')) LIKE ?
        )";
        $whereTypes .= "ssss";
        array_push($whereParams, $like, $like, $like, $like);
    }

    $sql =
        "
        SELECT COALESCE(vt.ruta, '') AS ruta,
               COALESCE(vt.origen, '') AS origen,
               COALESCE(vt.lugar_carga, '') AS lugar_carga,
               COALESCE(vt.destino, '') AS destino,
               COUNT(*) AS veces_usada,
               MAX(COALESCE(vt.updated_at, vt.created_at, v.updated_at, v.created_at)) AS ultima_vez_usada,
               MIN($prioritySql) AS prioridad
          FROM viaje_tramos vt
          INNER JOIN viajes v ON v.id = vt.viaje_id
         WHERE " .
        implode(" AND ", $where) .
        "
         GROUP BY COALESCE(vt.ruta, ''), COALESCE(vt.origen, ''), COALESCE(vt.lugar_carga, ''), COALESCE(vt.destino, '')
         ORDER BY prioridad ASC, veces_usada DESC, ultima_vez_usada DESC
         LIMIT ?";
    $types = $priorityTypes . $whereTypes . "i";
    $params = array_merge($priorityParams, $whereParams, [$limit]);

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception(
            "Error preparando busqueda de rutas: " . $conn->error,
            500,
        );
    }
    $refs = [$types];
    foreach ($params as $k => $v) {
        $refs[] = &$params[$k];
    }
    call_user_func_array([$stmt, "bind_param"], $refs);
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new Exception("Error buscando rutas: " . $err, 500);
    }
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $agregar_ruta($row, 2);
    }
    $stmt->close();

    $rutas = array_values($rutas_por_firma);
    usort($rutas, function ($a, $b) {
        return [
            $a["_prioridad"],
            $a["_source_rank"],
            -$a["veces_usada"],
            (string) ($b["ultima_vez_usada"] ?? ""),
        ] <=> [
            $b["_prioridad"],
            $b["_source_rank"],
            -$b["veces_usada"],
            (string) ($a["ultima_vez_usada"] ?? ""),
        ];
    });
    $rutas = array_slice($rutas, 0, $limit);
    foreach ($rutas as &$ruta) {
        unset($ruta["_source_rank"], $ruta["_prioridad"]);
    }
    unset($ruta);

    json_ok(["rutas" => $rutas, "total" => count($rutas)]);
}

function handle_listar_catalogo($conn, $ctx)
{
    $cliente_id = (int) ($_GET["cliente_id"] ?? 0);
    if ($cliente_id <= 0) {
        throw new Exception("cliente_id requerido", 400);
    }
    assert_cliente_access($ctx, $cliente_id);

    $stmt = $conn->prepare(
        'SELECT id, etiqueta, ruta, origen, lugar_carga, destino, instrucciones,
                duracion_estimada_horas, created_at, updated_at
           FROM tramos_catalogo
          WHERE cliente_id = ? AND activo = 1
          ORDER BY etiqueta ASC',
    );
    $stmt->bind_param("i", $cliente_id);
    $stmt->execute();
    $res = $stmt->get_result();

    $plantillas = [];
    while ($r = $res->fetch_assoc()) {
        $plantillas[] = [
            "id" => (int) $r["id"],
            "etiqueta" => $r["etiqueta"],
            "ruta" => $r["ruta"],
            "origen" => $r["origen"],
            "lugar_carga" => $r["lugar_carga"],
            "destino" => $r["destino"],
            "instrucciones" => $r["instrucciones"],
            "duracion_estimada_horas" => $r["duracion_estimada_horas"]
                ? (float) $r["duracion_estimada_horas"]
                : null,
            "created_at" => $r["created_at"],
            "updated_at" => $r["updated_at"],
        ];
    }
    $stmt->close();

    json_ok(["plantillas" => $plantillas, "total" => count($plantillas)]);
}

function handle_listar_rutas($conn, $ctx)
{
    $cliente_id = (int) ($_GET["cliente_id"] ?? 0);
    if ($cliente_id <= 0) {
        throw new Exception("cliente_id requerido", 400);
    }
    assert_cliente_access($ctx, $cliente_id);

    if (!db_table_exists($conn, "tramos_catalogo")) {
        json_ok([
            "favoritos" => [],
            "frecuentes" => [],
            "recientes" => [],
            "total" => 0,
        ]);
    }

    $has_historial = db_columns_exist($conn, "tramos_catalogo", [
        "origen_tipo",
        "firma_ruta",
        "veces_usada",
        "ultima_vez_usada",
        "es_favorito",
    ]);

    $sql = $has_historial
        ? 'SELECT id, etiqueta, ruta, origen, lugar_carga, destino, instrucciones,
                  duracion_estimada_horas, origen_tipo, firma_ruta, veces_usada,
                  ultima_vez_usada, es_favorito, created_at, updated_at
             FROM tramos_catalogo
            WHERE cliente_id = ? AND activo = 1
            ORDER BY es_favorito DESC,
                     veces_usada DESC,
                     COALESCE(ultima_vez_usada, updated_at, created_at) DESC,
                     etiqueta ASC'
        : 'SELECT id, etiqueta, ruta, origen, lugar_carga, destino, instrucciones,
                  duracion_estimada_horas, created_at, updated_at
             FROM tramos_catalogo
            WHERE cliente_id = ? AND activo = 1
            ORDER BY etiqueta ASC';

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception(
            "Error preparando listado de rutas: " . $conn->error,
            500,
        );
    }
    $stmt->bind_param("i", $cliente_id);
    $stmt->execute();
    $res = $stmt->get_result();

    $favoritos = [];
    $frecuentes = [];
    $recientes = [];

    while ($r = $res->fetch_assoc()) {
        $plantilla = [
            "id" => (int) $r["id"],
            "etiqueta" => $r["etiqueta"],
            "ruta" => $r["ruta"],
            "origen" => $r["origen"],
            "lugar_carga" => $r["lugar_carga"],
            "destino" => $r["destino"],
            "instrucciones" => $r["instrucciones"],
            "duracion_estimada_horas" => $r["duracion_estimada_horas"]
                ? (float) $r["duracion_estimada_horas"]
                : null,
            "origen_tipo" => $has_historial ? $r["origen_tipo"] : "manual",
            "firma_ruta" => $has_historial ? $r["firma_ruta"] : null,
            "veces_usada" => $has_historial ? (int) $r["veces_usada"] : 0,
            "ultima_vez_usada" => $has_historial
                ? $r["ultima_vez_usada"]
                : null,
            "es_favorito" => $has_historial
                ? (int) $r["es_favorito"] === 1
                : false,
            "created_at" => $r["created_at"],
            "updated_at" => $r["updated_at"],
        ];

        if ($plantilla["es_favorito"]) {
            $favoritos[] = $plantilla;
        } elseif ($plantilla["veces_usada"] > 0) {
            $frecuentes[] = $plantilla;
        } else {
            $recientes[] = $plantilla;
        }
    }
    $stmt->close();

    json_ok([
        "favoritos" => $favoritos,
        "frecuentes" => $frecuentes,
        "recientes" => $recientes,
        "total" => count($favoritos) + count($frecuentes) + count($recientes),
    ]);
}

function handle_crear_plantilla($conn, $ctx)
{
    assert_can_write($ctx);

    $body = json_decode(file_get_contents("php://input"), true);
    if (!is_array($body)) {
        throw new Exception("JSON invalido", 400);
    }

    $cliente_id = (int) ($body["cliente_id"] ?? 0);
    $etiqueta = trim((string) ($body["etiqueta"] ?? ""));
    $ruta = null_if_empty($body["ruta"] ?? "");
    $origen = null_if_empty($body["origen"] ?? "");
    $lugar_carga = null_if_empty($body["lugar_carga"] ?? "");
    $destino = null_if_empty($body["destino"] ?? "");
    $instrucciones = null_if_empty($body["instrucciones"] ?? "");
    $duracion =
        isset($body["duracion_estimada_horas"]) &&
        $body["duracion_estimada_horas"] !== ""
            ? (float) $body["duracion_estimada_horas"]
            : null;

    if ($cliente_id <= 0) {
        throw new Exception("cliente_id requerido", 400);
    }
    if ($etiqueta === "") {
        throw new Exception("etiqueta requerida", 400);
    }

    assert_cliente_access($ctx, $cliente_id);

    $stmt = $conn->prepare(
        "SELECT id FROM tramos_catalogo WHERE cliente_id = ? AND etiqueta = ? AND activo = 1 LIMIT 1",
    );
    $stmt->bind_param("is", $cliente_id, $etiqueta);
    $stmt->execute();
    $existe = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existe) {
        throw new Exception(
            "Ya existe una plantilla con la etiqueta '$etiqueta' para este cliente",
            400,
        );
    }

    $stmt = $conn->prepare(
        'INSERT INTO tramos_catalogo
           (cliente_id, etiqueta, ruta, origen, lugar_carga, destino, instrucciones, duracion_estimada_horas)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
    );
    $stmt->bind_param(
        "issssssd",
        $cliente_id,
        $etiqueta,
        $ruta,
        $origen,
        $lugar_carga,
        $destino,
        $instrucciones,
        $duracion,
    );
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new Exception("Error creando plantilla: " . $err, 500);
    }
    $plantilla_id = (int) $stmt->insert_id;
    $stmt->close();

    $stmt = $conn->prepare(
        "SELECT * FROM tramos_catalogo WHERE id = ? LIMIT 1",
    );
    $stmt->bind_param("i", $plantilla_id);
    $stmt->execute();
    $p = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    json_ok(
        [
            "plantilla" => [
                "id" => (int) $p["id"],
                "etiqueta" => $p["etiqueta"],
                "ruta" => $p["ruta"],
                "origen" => $p["origen"],
                "lugar_carga" => $p["lugar_carga"],
                "destino" => $p["destino"],
                "instrucciones" => $p["instrucciones"],
                "duracion_estimada_horas" => $p["duracion_estimada_horas"]
                    ? (float) $p["duracion_estimada_horas"]
                    : null,
                "created_at" => $p["created_at"],
                "updated_at" => $p["updated_at"],
            ],
        ],
        201,
    );
}

function handle_actualizar_plantilla($conn, $ctx)
{
    assert_can_write($ctx);

    $body = json_decode(file_get_contents("php://input"), true);
    if (!is_array($body)) {
        throw new Exception("JSON invalido", 400);
    }

    $plantilla_id = (int) ($body["plantilla_id"] ?? 0);
    if ($plantilla_id <= 0) {
        throw new Exception("plantilla_id requerido", 400);
    }

    $stmt = $conn->prepare(
        "SELECT cliente_id FROM tramos_catalogo WHERE id = ? AND activo = 1 LIMIT 1",
    );
    $stmt->bind_param("i", $plantilla_id);
    $stmt->execute();
    $p = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$p) {
        throw new Exception("Plantilla no encontrada", 404);
    }
    assert_cliente_access($ctx, $p["cliente_id"]);

    $sets = [];
    $types = "";
    $params = [];

    $campos = [
        "etiqueta",
        "ruta",
        "origen",
        "lugar_carga",
        "destino",
        "instrucciones",
    ];
    foreach ($campos as $campo) {
        if (array_key_exists($campo, $body)) {
            $sets[] = "$campo = ?";
            $types .= "s";
            $params[] =
                $campo === "etiqueta"
                    ? trim((string) $body[$campo])
                    : null_if_empty($body[$campo]);
        }
    }

    if (array_key_exists("duracion_estimada_horas", $body)) {
        $sets[] = "duracion_estimada_horas = ?";
        $types .= "d";
        $params[] =
            $body["duracion_estimada_horas"] !== ""
                ? (float) $body["duracion_estimada_horas"]
                : null;
    }

    if (!count($sets)) {
        throw new Exception("No hay campos para actualizar", 400);
    }

    $types .= "i";
    $params[] = $plantilla_id;

    $stmt = $conn->prepare(
        "UPDATE tramos_catalogo SET " . implode(", ", $sets) . " WHERE id = ?",
    );
    $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) {
        throw new Exception(
            "Error actualizando plantilla: " . $stmt->error,
            500,
        );
    }
    $stmt->close();

    $stmt = $conn->prepare(
        "SELECT * FROM tramos_catalogo WHERE id = ? LIMIT 1",
    );
    $stmt->bind_param("i", $plantilla_id);
    $stmt->execute();
    $p = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    json_ok([
        "plantilla" => [
            "id" => (int) $p["id"],
            "etiqueta" => $p["etiqueta"],
            "ruta" => $p["ruta"],
            "origen" => $p["origen"],
            "lugar_carga" => $p["lugar_carga"],
            "destino" => $p["destino"],
            "instrucciones" => $p["instrucciones"],
            "duracion_estimada_horas" => $p["duracion_estimada_horas"]
                ? (float) $p["duracion_estimada_horas"]
                : null,
            "created_at" => $p["created_at"],
            "updated_at" => $p["updated_at"],
        ],
    ]);
}

function handle_eliminar_plantilla($conn, $ctx)
{
    assert_can_write($ctx);

    $body = json_decode(file_get_contents("php://input"), true);
    if (!is_array($body)) {
        throw new Exception("JSON invalido", 400);
    }

    $plantilla_id = (int) ($body["plantilla_id"] ?? 0);
    if ($plantilla_id <= 0) {
        throw new Exception("plantilla_id requerido", 400);
    }

    $stmt = $conn->prepare(
        "SELECT cliente_id, activo FROM tramos_catalogo WHERE id = ? LIMIT 1",
    );
    $stmt->bind_param("i", $plantilla_id);
    $stmt->execute();
    $p = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$p) {
        throw new Exception("Plantilla no encontrada", 404);
    }
    assert_cliente_access($ctx, $p["cliente_id"]);

    if ((int) $p["activo"] === 0) {
        json_ok([
            "plantilla_id" => $plantilla_id,
            "activo" => 0,
            "msg" => "Ya estaba eliminada",
        ]);
    }

    $stmt = $conn->prepare(
        "UPDATE tramos_catalogo SET activo = 0 WHERE id = ?",
    );
    $stmt->bind_param("i", $plantilla_id);
    if (!$stmt->execute()) {
        throw new Exception("Error eliminando plantilla: " . $stmt->error, 500);
    }
    $stmt->close();

    json_ok(["plantilla_id" => $plantilla_id, "activo" => 0]);
}

function handle_marcar_favorito($conn, $ctx)
{
    assert_can_write($ctx);

    $body = json_decode(file_get_contents("php://input"), true);
    if (!is_array($body)) {
        throw new Exception("JSON invalido", 400);
    }

    $plantilla_id = (int) ($body["plantilla_id"] ?? 0);
    if ($plantilla_id <= 0) {
        throw new Exception("plantilla_id requerido", 400);
    }

    $stmt = $conn->prepare(
        "SELECT cliente_id FROM tramos_catalogo WHERE id = ? AND activo = 1 LIMIT 1",
    );
    $stmt->bind_param("i", $plantilla_id);
    $stmt->execute();
    $p = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$p) {
        throw new Exception("Ruta no encontrada", 404);
    }
    assert_cliente_access($ctx, $p["cliente_id"]);

    $es_favorito = !empty($body["es_favorito"]) ? 1 : 0;
    $etiqueta = array_key_exists("etiqueta", $body)
        ? trim((string) $body["etiqueta"])
        : null;

    if ($etiqueta !== null && $etiqueta !== "") {
        $stmt = $conn->prepare(
            "UPDATE tramos_catalogo SET es_favorito = ?, etiqueta = ? WHERE id = ?",
        );
        $stmt->bind_param("isi", $es_favorito, $etiqueta, $plantilla_id);
    } else {
        $stmt = $conn->prepare(
            "UPDATE tramos_catalogo SET es_favorito = ? WHERE id = ?",
        );
        $stmt->bind_param("ii", $es_favorito, $plantilla_id);
    }

    if (!$stmt->execute()) {
        throw new Exception(
            "Error actualizando favorito: " . $stmt->error,
            500,
        );
    }
    $stmt->close();

    json_ok([
        "plantilla_id" => $plantilla_id,
        "es_favorito" => $es_favorito === 1,
        "etiqueta" => $etiqueta,
    ]);
}

function handle_ocultar_ruta($conn, $ctx)
{
    handle_eliminar_plantilla($conn, $ctx);
}

function handle_obtener_plantilla($conn, $ctx)
{
    $plantilla_id = (int) ($_GET["plantilla_id"] ?? 0);
    if ($plantilla_id <= 0) {
        throw new Exception("plantilla_id requerido", 400);
    }

    $stmt = $conn->prepare(
        "SELECT * FROM tramos_catalogo WHERE id = ? AND activo = 1 LIMIT 1",
    );
    $stmt->bind_param("i", $plantilla_id);
    $stmt->execute();
    $p = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$p) {
        throw new Exception("Plantilla no encontrada", 404);
    }
    assert_cliente_access($ctx, $p["cliente_id"]);

    json_ok([
        "plantilla" => [
            "id" => (int) $p["id"],
            "cliente_id" => (int) $p["cliente_id"],
            "etiqueta" => $p["etiqueta"],
            "ruta" => $p["ruta"],
            "origen" => $p["origen"],
            "lugar_carga" => $p["lugar_carga"],
            "destino" => $p["destino"],
            "instrucciones" => $p["instrucciones"],
            "duracion_estimada_horas" => $p["duracion_estimada_horas"]
                ? (float) $p["duracion_estimada_horas"]
                : null,
            "created_at" => $p["created_at"],
            "updated_at" => $p["updated_at"],
        ],
    ]);
}

function handle_listar_operadores($conn, $ctx)
{
    $cliente_id = (int) ($_GET["cliente_id"] ?? 0);
    if ($cliente_id <= 0) {
        throw new Exception("cliente_id requerido", 400);
    }
    assert_cliente_access($ctx, $cliente_id);

    if (!db_table_exists($conn, "operadores")) {
        json_ok(["operadores" => [], "total" => 0]);
    }

    $stmt = $conn->prepare(
        'SELECT id, nombre, telefonos, created_at, updated_at
           FROM operadores
          WHERE cliente_id = ? AND activo = 1
          ORDER BY nombre ASC',
    );
    if (!$stmt) {
        throw new Exception(
            "Error preparando listado de operadores: " . $conn->error,
            500,
        );
    }
    $stmt->bind_param("i", $cliente_id);
    $stmt->execute();
    $res = $stmt->get_result();

    $operadores = [];
    while ($r = $res->fetch_assoc()) {
        $operadores[] = [
            "id" => (int) $r["id"],
            "nombre" => $r["nombre"],
            "telefonos" => $r["telefonos"],
            "created_at" => $r["created_at"],
            "updated_at" => $r["updated_at"],
        ];
    }
    $stmt->close();

    json_ok(["operadores" => $operadores, "total" => count($operadores)]);
}

function handle_crear_operador($conn, $ctx)
{
    assert_can_write($ctx);

    $body = json_decode(file_get_contents("php://input"), true);
    if (!is_array($body)) {
        throw new Exception("JSON invalido", 400);
    }

    $cliente_id = (int) ($body["cliente_id"] ?? 0);
    $nombre = trim((string) ($body["nombre"] ?? ""));
    $telefonos = null_if_empty($body["telefonos"] ?? "");

    if ($cliente_id <= 0) {
        throw new Exception("cliente_id requerido", 400);
    }
    if ($nombre === "") {
        throw new Exception("El nombre del operador es requerido", 400);
    }

    assert_cliente_access($ctx, $cliente_id);

    $stmt = $conn->prepare(
        "SELECT id FROM operadores WHERE cliente_id = ? AND nombre = ? AND activo = 1 LIMIT 1",
    );
    $stmt->bind_param("is", $cliente_id, $nombre);
    $stmt->execute();
    $existe = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($existe) {
        throw new Exception(
            "Ya existe un operador llamado '$nombre' para este cliente",
            409,
        );
    }

    $stmt = $conn->prepare(
        "INSERT INTO operadores (cliente_id, nombre, telefonos) VALUES (?, ?, ?)",
    );
    $stmt->bind_param("iss", $cliente_id, $nombre, $telefonos);
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new Exception("Error creando operador: " . $err, 500);
    }
    $operador_id = (int) $stmt->insert_id;
    $stmt->close();

    json_ok(
        [
            "operador" => [
                "id" => $operador_id,
                "nombre" => $nombre,
                "telefonos" => $telefonos,
            ],
        ],
        201,
    );
}

function handle_actualizar_operador($conn, $ctx)
{
    assert_can_write($ctx);

    $body = json_decode(file_get_contents("php://input"), true);
    if (!is_array($body)) {
        throw new Exception("JSON invalido", 400);
    }

    $operador_id = (int) ($body["operador_id"] ?? 0);
    if ($operador_id <= 0) {
        throw new Exception("operador_id requerido", 400);
    }

    $stmt = $conn->prepare(
        "SELECT id, cliente_id FROM operadores WHERE id = ? AND activo = 1 LIMIT 1",
    );
    $stmt->bind_param("i", $operador_id);
    $stmt->execute();
    $op = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$op) {
        throw new Exception("Operador no encontrado", 404);
    }
    assert_cliente_access($ctx, (int) $op["cliente_id"]);

    $sets = [];
    $types = "";
    $params = [];

    if (array_key_exists("nombre", $body)) {
        $nombre = trim((string) $body["nombre"]);
        if ($nombre === "") {
            throw new Exception("El nombre no puede quedar vacío", 400);
        }
        $stmt = $conn->prepare(
            "SELECT id FROM operadores WHERE cliente_id = ? AND nombre = ? AND activo = 1 AND id <> ? LIMIT 1",
        );
        $stmt->bind_param("isi", $op["cliente_id"], $nombre, $operador_id);
        $stmt->execute();
        $dup = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($dup) {
            throw new Exception(
                "Ya existe otro operador llamado '$nombre' para este cliente",
                409,
            );
        }
        $sets[] = "nombre = ?";
        $types .= "s";
        $params[] = $nombre;
    }

    if (array_key_exists("telefonos", $body)) {
        $sets[] = "telefonos = ?";
        $types .= "s";
        $params[] = null_if_empty($body["telefonos"]);
    }

    if (!count($sets)) {
        throw new Exception("No hay campos para actualizar", 400);
    }

    $types .= "i";
    $params[] = $operador_id;
    $stmt = $conn->prepare(
        "UPDATE operadores SET " . implode(", ", $sets) . " WHERE id = ?",
    );
    $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) {
        throw new Exception(
            "Error actualizando operador: " . $stmt->error,
            500,
        );
    }
    $stmt->close();

    $stmt = $conn->prepare(
        "SELECT id, nombre, telefonos FROM operadores WHERE id = ? LIMIT 1",
    );
    $stmt->bind_param("i", $operador_id);
    $stmt->execute();
    $o = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    json_ok([
        "operador" => [
            "id" => (int) $o["id"],
            "nombre" => $o["nombre"],
            "telefonos" => $o["telefonos"],
        ],
    ]);
}

function handle_eliminar_operador($conn, $ctx)
{
    assert_can_write($ctx);

    $body = json_decode(file_get_contents("php://input"), true);
    if (!is_array($body)) {
        throw new Exception("JSON invalido", 400);
    }

    $operador_id = (int) ($body["operador_id"] ?? 0);
    if ($operador_id <= 0) {
        throw new Exception("operador_id requerido", 400);
    }

    $stmt = $conn->prepare(
        "SELECT cliente_id, activo FROM operadores WHERE id = ? LIMIT 1",
    );
    $stmt->bind_param("i", $operador_id);
    $stmt->execute();
    $op = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$op) {
        throw new Exception("Operador no encontrado", 404);
    }
    assert_cliente_access($ctx, (int) $op["cliente_id"]);

    if ((int) $op["activo"] === 0) {
        json_ok([
            "operador_id" => $operador_id,
            "activo" => 0,
            "msg" => "Ya estaba eliminado",
        ]);
    }

    $stmt = $conn->prepare("UPDATE operadores SET activo = 0 WHERE id = ?");
    $stmt->bind_param("i", $operador_id);
    if (!$stmt->execute()) {
        throw new Exception("Error eliminando operador: " . $stmt->error, 500);
    }
    $stmt->close();

    json_ok(["operador_id" => $operador_id, "activo" => 0]);
}

try {
    $conn = getDbConnection();
    $action = str_val($_GET["action"] ?? "");
    $method = strtoupper($_SERVER["REQUEST_METHOD"] ?? "GET");
    if ($method === "POST") {
        $method_override = strtoupper(str_val($_GET["_method"] ?? ""));
        if (in_array($method_override, ["PUT", "DELETE"], true)) {
            $method = $method_override;
        }
    }

    $ctx = get_usuario_context($conn);

    match (true) {
        $method === "GET" && $action === "listar" => handle_listar($conn, $ctx),
        $method === "GET" && $action === "obtener" => handle_obtener(
            $conn,
            $ctx,
        ),
        $method === "GET" && $action === "unidades" => handle_unidades(
            $conn,
            $ctx,
        ),
        $method === "PUT" && $action === "actualizar_unidad"
            => handle_actualizar_unidad($conn, $ctx),
        $method === "PUT" && $action === "actualizar_despacho"
            => handle_actualizar_despacho($conn, $ctx),
        $method === "DELETE" && $action === "eliminar_despacho"
            => handle_eliminar_despacho($conn, $ctx),
        $method === "DELETE" && $action === "eliminar_registro"
            => handle_eliminar_registro($conn, $ctx),
        $method === "GET" && $action === "verificar_conflictos"
            => handle_verificar_conflictos($conn, $ctx),
        $method === "POST" && $action === "crear" => handle_crear($conn, $ctx),
        $method === "POST" && $action === "duplicar" => handle_duplicar(
            $conn,
            $ctx,
        ),
        $method === "POST" && $action === "agregar_tramo"
            => handle_agregar_tramo($conn, $ctx),
        $method === "PUT" && $action === "actualizar" => handle_actualizar(
            $conn,
            $ctx,
        ),
        $method === "PUT" && $action === "actualizar_tramo"
            => handle_actualizar_tramo($conn, $ctx),
        $method === "PUT" && $action === "completar_tramo"
            => handle_completar_tramo($conn, $ctx),
        $method === "PUT" && $action === "guardar_gps" => handle_guardar_gps(
            $conn,
            $ctx,
        ),
        $method === "POST" && $action === "agregar_incidencia"
            => handle_agregar_incidencia($conn, $ctx),
        $method === "DELETE" && $action === "eliminar_incidencia"
            => handle_eliminar_incidencia($conn, $ctx),
        $method === "DELETE" && $action === "eliminar_tramo"
            => handle_eliminar_tramo($conn, $ctx),
        $method === "DELETE" && $action === "eliminar_viaje"
            => handle_eliminar_viaje($conn, $ctx),
        // Catálogo de Plantillas de Ruta
        $method === "GET" && $action === "listar_rutas" => handle_listar_rutas(
            $conn,
            $ctx,
        ),
        $method === "GET" && $action === "buscar_rutas"
            => handle_buscar_rutas_historial($conn, $ctx),
        $method === "GET" && $action === "listar_catalogo"
            => handle_listar_catalogo($conn, $ctx),
        $method === "GET" && $action === "obtener_plantilla"
            => handle_obtener_plantilla($conn, $ctx),
        $method === "POST" && $action === "crear_plantilla"
            => handle_crear_plantilla($conn, $ctx),
        $method === "PUT" && $action === "actualizar_plantilla"
            => handle_actualizar_plantilla($conn, $ctx),
        $method === "DELETE" && $action === "eliminar_plantilla"
            => handle_eliminar_plantilla($conn, $ctx),
        $method === "PUT" && $action === "marcar_favorito"
            => handle_marcar_favorito($conn, $ctx),
        $method === "DELETE" && $action === "ocultar_ruta"
            => handle_ocultar_ruta($conn, $ctx),
        $method === "GET" && $action === "listar_operadores"
            => handle_listar_operadores($conn, $ctx),
        $method === "POST" && $action === "crear_operador"
            => handle_crear_operador($conn, $ctx),
        $method === "PUT" && $action === "actualizar_operador"
            => handle_actualizar_operador($conn, $ctx),
        $method === "DELETE" && $action === "eliminar_operador"
            => handle_eliminar_operador($conn, $ctx),
        default => json_err(
            "Accion '$action' no reconocida o metodo '$method' incorrecto",
            404,
        ),
    };
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    json_err(
        $e->getMessage(),
        in_array($code, [400, 401, 403, 404, 409, 500], true) ? $code : 500,
    );
}
