<?php
/**
 * Prueba simple de la API sin dependencias de Google
 */

header('Content-Type: application/json');

// Simular request HTTP
$_GET['action'] = $_GET['action'] ?? 'read';
$_GET['tipo'] = $_GET['tipo'] ?? 'logistica';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$_SERVER['HTTP_ORIGIN'] = $_SERVER['HTTP_ORIGIN'] ?? 'http://localhost';

require __DIR__ . '/../config.php';
require __DIR__ . '/../RequestValidator.php';

try {
    // Validate request
    RequestValidator::validateRequest();
    
    // Handle request
    $action = RequestValidator::sanitizeInput($_GET['action'] ?? 'read', 'string');
    
    if ($action === 'read') {
        $tipo = RequestValidator::sanitizeInput($_GET['tipo'] ?? '', 'string');
        $tipo = RequestValidator::validateTipo($tipo);
        
        echo json_encode([
            'ok' => true,
            'message' => '✅ API is working correctly!',
            'action' => 'read',
            'tipo' => $tipo,
            'environment' => ENVIRONMENT,
            'api_key_configured' => !empty(API_KEY),
            'config' => [
                'spreadsheet_id_length' => strlen(SPREADSHEET_ID),
                'credentials_file_exists' => file_exists(GOOGLE_CREDENTIALS_PATH),
                'allowed_origins' => ALLOWED_ORIGINS,
            ]
        ]);
    } elseif ($action === 'write') {
        echo json_encode([
            'ok' => true,
            'message' => '✅ API write endpoint is working!',
            'action' => 'write',
            'environment' => ENVIRONMENT,
        ]);
    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid action']);
    }
    
} catch (Exception $e) {
    error_log('Test API Error: ' . $e->getMessage());
    
    $code = is_numeric($e->getCode()) ? $e->getCode() : 500;
    http_response_code($code);
    
    if (ENVIRONMENT === 'development') {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    } else {
        echo json_encode(['ok' => false, 'error' => 'An error occurred']);
    }
}