<?php
header('Content-Type: application/json');

echo json_encode([
    'ok' => true,
    'message' => 'API funcionando',
    'timestamp' => date('Y-m-d H:i:s')
]);