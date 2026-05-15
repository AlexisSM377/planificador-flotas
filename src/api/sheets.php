<?php

while (ob_get_level()) {
    ob_end_clean();
}

header_remove();
header('Content-Type: application/json; charset=utf-8');
http_response_code(410);

echo json_encode([
    'ok' => false,
    'error' => 'Google Sheets ya no esta disponible. Usa api/planificador.php con base de datos.'
], JSON_UNESCAPED_UNICODE);
exit;
