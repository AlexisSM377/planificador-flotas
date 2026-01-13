<?php
/**
 * Google Sheets API endpoint with security
 */

// Load configuration
require __DIR__ . '/../config.php';
require __DIR__ . '/../RequestValidator.php';

header('Content-Type: application/json');

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
    
    $service = new Google_Service_Sheets($client);
    
    $sheets = [
        'logistica' => 'BITACORA',
        'contactos' => 'Contactos'
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
    // Log error securely
    error_log('API Error: ' . $e->getMessage());
    
    // Return error response
    $code = is_numeric($e->getCode()) ? $e->getCode() : 500;
    http_response_code($code);
    
    // Don't expose detailed error in production
    if (ENVIRONMENT === 'development') {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    } else {
        echo json_encode(['ok' => false, 'error' => 'An error occurred']);
    }
}

/**
 * Handle read request
 */
function handleRead($service, $sheets) {
    $tipo = RequestValidator::sanitizeInput($_GET['tipo'] ?? '', 'string');
    $tipo = RequestValidator::validateTipo($tipo);
    
    $range = $sheets[$tipo] . '!A2:N1000';
    $response = $service->spreadsheets_values->get(SPREADSHEET_ID, $range);
    $values = $response->getValues();
    
    echo json_encode([
        'ok' => true,
        'data' => $values ?? []
    ]);
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
    
    // Write to Google Sheets
    $body = new Google_Service_Sheets_ValueRange([
        'values' => $sanitizedRows
    ]);
    
    $service->spreadsheets_values->append(
        SPREADSHEET_ID,
        $sheets[$tipo] . '!A2',
        $body,
        [
            'valueInputOption' => 'RAW',
            'insertDataOption' => 'INSERT_ROWS'
        ]
    );
    
    echo json_encode(['ok' => true]);
}
