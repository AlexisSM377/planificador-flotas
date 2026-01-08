<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');

require __DIR__ . '/../../vendor/autoload.php';

$SPREADSHEET_ID = '1bx2zR637XcmkNVR7osxllsz7MD9SZHTtAHvNSseuuKc';

$SHEETS = [
    'logistica' => 'Logistica',
    'contactos' => 'Contactos'
];

// Configurar cliente de Google
$client = new Google_Client();
$client->setApplicationName('Tracker Sheets');
$client->setScopes([
    'https://www.googleapis.com/auth/spreadsheets'
]);
$client->setAuthConfig(__DIR__ . '/../../credentials/google.json');
$client->setAccessType('offline');

$service = new Google_Service_Sheets($client);

// Determinar si es lectura o escritura
$action = $_GET['action'] ?? 'write';

if ($action === 'read') {
    // LECTURA: Obtener datos desde Sheets
    $tipo = $_GET['tipo'] ?? '';
    
    if (empty($tipo) || !isset($SHEETS[$tipo])) {
        echo json_encode(['ok' => false, 'error' => 'Tipo inválido']);
        exit;
    }
    
    try {
        $range = $SHEETS[$tipo] . '!A2:M1000'; // Leer hasta 1000 filas
        $response = $service->spreadsheets_values->get($SPREADSHEET_ID, $range);
        $values = $response->getValues();
        
        if (empty($values)) {
            echo json_encode(['ok' => true, 'data' => []]);
        } else {
            echo json_encode(['ok' => true, 'data' => $values]);
        }
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
} else {
    // ESCRITURA: Agregar datos a Sheets
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        echo json_encode(['ok' => false, 'error' => 'JSON inválido']);
        exit;
    }

    if (empty($input['tipo']) || empty($input['rows'])) {
        echo json_encode(['ok' => false, 'error' => 'Faltan datos']);
        exit;
    }

    if (!isset($SHEETS[$input['tipo']])) {
        echo json_encode(['ok' => false, 'error' => 'Tipo inválido']);
        exit;
    }

    try {
        $body = new Google_Service_Sheets_ValueRange([
            'values' => $input['rows']
        ]);

        $service->spreadsheets_values->append(
            $SPREADSHEET_ID,
            $SHEETS[$input['tipo']] . '!A2',
            $body,
            [
                'valueInputOption' => 'RAW',
                'insertDataOption' => 'INSERT_ROWS'
            ]
        );

        echo json_encode(['ok' => true]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}
