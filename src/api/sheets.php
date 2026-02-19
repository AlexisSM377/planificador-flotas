<?php
/**
 * Google Sheets API endpoint with security
 */

// Clean output buffer and disable output buffering
while (ob_get_level()) {
    ob_end_clean();
}

// Start output buffering to catch any unwanted output
ob_start();

// Load configuration
require __DIR__ . '/../config.php';
require __DIR__ . '/../RequestValidator.php';

// Clear all existing headers and set only JSON header
header_remove();
header('Content-Type: application/json; charset=utf-8');

try {
    // Validate incoming request
    RequestValidator::validateRequest();
    
    // Load Google API
    require __DIR__ . '/../../vendor/autoload.php';
    
    // Initialize Google Sheets service
    $client = new Google_Client();
    $client->setApplicationName('Tracker Sheets');
    $client->setScopes(['https://www.googleapis.com/auth/spreadsheets']);
    $client->setAuthConfig(GOOGLE_CREDENTIALS_PATH);
    $client->setAccessType('offline');

    // Configure HTTP client - disable SSL verification in development
    if (ENVIRONMENT === 'development') {
        $client->setHttpClient(new \GuzzleHttp\Client(['verify' => false]));
    }
    
    $service = new Google_Service_Sheets($client);
    
    $sheets = [
        'logistica' => 'Datos',  // Alias for datos
        'contactos' => 'Contactos',
        'usuarios' => 'Usuarios'
    ];
    
    // Handle request
    $action = RequestValidator::sanitizeInput($_GET['action'] ?? 'write', 'string');
    
    if ($action === 'read') {
        handleRead($service, $sheets);
    } elseif ($action === 'write') {
        handleWrite($service, $sheets);
    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid action']);
    }
    
} catch (Exception $e) {
    // Clean any captured output
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Log error securely
    error_log('API Error: ' . $e->getMessage());
    
    // Clear headers and set JSON
    header_remove();
    header('Content-Type: application/json; charset=utf-8');
    
    // Return error response
    $code = is_numeric($e->getCode()) && $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
    http_response_code($code);
    
    // Don't expose detailed error in production
    if (ENVIRONMENT === 'development') {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    } else {
        echo json_encode(['ok' => false, 'error' => 'An error occurred']);
    }
    exit;
}

/**
 * Handle read request
 */
function handleRead($service, $sheets) {
    $tipo = RequestValidator::sanitizeInput($_GET['tipo'] ?? '', 'string');
    $tipo = RequestValidator::validateTipo($tipo);
    
    // Different ranges for different sheets
    $range = match($tipo) {
        'usuarios' => $sheets[$tipo] . '!A2:F100',  // Start from row 2 (skip header), columns A-F only
        default => $sheets[$tipo] . '!A7:O1000'  // Extended to column O for Lector Responsable
    };
    
    $response = $service->spreadsheets_values->get(SPREADSHEET_ID, $range);
    $values = $response->getValues();
    
    // Clean output buffer before sending JSON
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Ensure clean headers
    header_remove();
    header('Content-Type: application/json; charset=utf-8');
    
    echo json_encode([
        'ok' => true,
        'data' => $values ?? []
    ]);
    exit;
}

/**
 * Handle write request
 */
function handleWrite($service, $sheets) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!is_array($input)) {
        throw new Exception('Invalid JSON input', 400);
    }
    
    $tipo = RequestValidator::sanitizeInput($input['tipo'] ?? '', 'string');
    $rows = $input['rows'] ?? [];
    $action = RequestValidator::sanitizeInput($input['action'] ?? 'append', 'string');
    
    // Validate inputs
    $tipo = RequestValidator::validateTipo($tipo);
    $rows = RequestValidator::validateRows($rows);
    
    // Sanitize each row
    $sanitizedRows = [];
    foreach ($rows as $row) {
        $sanitizedRow = [];
        foreach ($row as $cell) {
            $sanitizedRow[] = RequestValidator::sanitizeInput($cell, 'string');
        }
        $sanitizedRows[] = $sanitizedRow;
    }
    
    // Write to Google Sheets (append)
    $body = new Google_Service_Sheets_ValueRange([
        'values' => $sanitizedRows
    ]);
    
    $startRow = $tipo === 'usuarios' ? 'A2' : 'A7';
    
    $service->spreadsheets_values->append(
        SPREADSHEET_ID,
        $sheets[$tipo] . '!' . $startRow,
        $body,
        [
            // USER_ENTERED permite que Sheets interprete fechas numéricas sin prefijar "'"
            'valueInputOption' => 'USER_ENTERED',
            'insertDataOption' => 'INSERT_ROWS'
        ]
    );
    
    // Clean output buffer before sending JSON
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Ensure clean headers
    header_remove();
    header('Content-Type: application/json; charset=utf-8');
    
    echo json_encode(['ok' => true]);
    exit;
}
