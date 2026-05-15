<?php

require_once __DIR__ . '/config.php';

mysqli_report(MYSQLI_REPORT_OFF);

function getConfigValue($key, $default = null) {
    $value = getenv($key);
    return $value !== false ? $value : $default;
}

function getDbConnection() {
    $host = getConfigValue('DB_HOST', 'localhost');
    $port = (int)getConfigValue('DB_PORT', 3306);
    $user = getConfigValue('DB_USER', '');
    $pass = getConfigValue('DB_PASS', '');
    $db = getConfigValue('DB_NAME', '');

    if ($user === '' || $db === '') {
        throw new Exception('Credenciales de base de datos no configuradas');
    }

    $conn = @new mysqli($host, $user, $pass, $db, $port);
    if ($conn->connect_error) {
        throw new Exception('No se pudo conectar a la base de datos');
    }

    $conn->set_charset('utf8mb4');
    return $conn;
}

?>
