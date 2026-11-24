<?php
// get-counts.php

require_once __DIR__ . '/vendor/autoload.php';

// Cargar .env únicamente si existe (desarrollo local).
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

// Leer variables tanto de Railway como del .env local.
function envValue(string $key, ?string $default = null): ?string {
    $value = getenv($key);

    if ($value !== false) {
        return $value;
    }

    if (isset($_ENV[$key])) {
        return (string) $_ENV[$key];
    }

    if (isset($_SERVER[$key])) {
        return (string) $_SERVER[$key];
    }

    return $default;
}


use Google\Client;
use Google\Service\Sheets;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

$spreadsheetId = '1KpDNdgOdvxmdde1WLvNiQXbfq31gtQoJR086HOe5ENk';
$sheetName     = 'Hoja 1';

// ========== CARGA DE CREDENCIALES ==========

$credentials = null;

// 1. Railway: credenciales almacenadas como variable privada.
$credentialsJson = envValue('GOOGLE_CREDENTIALS');

if ($credentialsJson) {
    $credentials = json_decode($credentialsJson, true);
}

// 2. Local: archivo credentials.json.
if (!$credentials && file_exists(__DIR__ . '/credentials.json')) {
    $credentials = json_decode(
        file_get_contents(__DIR__ . '/credentials.json'),
        true
    );
}

// 3. Comprobar credenciales.
if (!$credentials || !is_array($credentials)) {
    error_log('No se pudieron cargar las credenciales de Google.');

    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode([
        'success' => false,
        'message' => 'No se pudieron cargar las credenciales de Google.'
    ]);

    exit;
}


try {
    $client = new Client();
    $client->setApplicationName('Contador Adhesions');
    $client->setScopes([Sheets::SPREADSHEETS_READONLY]);
    $client->setAuthConfig($credentials);
    
    $service = new Sheets($client);

    // Leemos la columna B (Tipo) completa
    $range = $sheetName . '!B:B';
    $response = $service->spreadsheets_values->get($spreadsheetId, $range);
    $values = $response->getValues() ?? [];

    $entitats = 0;
    $persones = 0;

    // Empezamos desde la fila 2 (saltamos el encabezado)
    for ($i = 1; $i < count($values); $i++) {
        $tipo = strtolower(trim($values[$i][0] ?? ''));
        
        if ($tipo === 'entitat') {
            $entitats++;
        } elseif ($tipo === 'individual') {
            $persones++;
        }
    }

    $total = $entitats + $persones;

    echo json_encode([
        'success'   => true,
        'entitats'  => $entitats,
        'persones'  => $persones,
        'total'     => $total
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success'  => false,
        'message'  => $e->getMessage(),
        'entitats' => 0,
        'persones' => 0,
        'total'    => 0
    ]);
}