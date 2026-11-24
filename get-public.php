<?php
// get-public.php

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

// ========== CONFIGURACIÓN ==========
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
    $client->setApplicationName('Llista Pública Adhesions');
    $client->setScopes([Sheets::SPREADSHEETS_READONLY]);
    $client->setAuthConfig($credentials);

    $service = new Sheets($client);

    // Columnas:
    // B = Tipo | C = Nombre entidad | F = Nombre completo | J = Visibilidad
    $range = $sheetName . '!B:J';
    $response = $service->spreadsheets_values->get($spreadsheetId, $range);
    $values = $response->getValues() ?? [];

    $entitats = [];
    $persones = [];

    for ($i = 1; $i < count($values); $i++) {
        $row = $values[$i];

        $tipo           = strtolower(trim($row[0] ?? ''));
        $nombreEntitat  = trim($row[1] ?? '');
        $nombreCompleto = trim($row[4] ?? '');
        $visibilitat    = strtolower(trim($row[8] ?? ''));

        if ($visibilitat !== 'pública') continue;

        if ($tipo === 'entitat' && !empty($nombreEntitat)) {
            $entitats[] = $nombreEntitat;
        }

        if ($tipo === 'individual' && !empty($nombreCompleto)) {
            $persones[] = $nombreCompleto;
        }
    }

    // Ordenar alfabéticamente
    sort($entitats, SORT_STRING | SORT_FLAG_CASE);
    sort($persones, SORT_STRING | SORT_FLAG_CASE);

    echo json_encode([
        'success'   => true,
        'entitats'  => $entitats,
        'persones'  => $persones
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success'   => false,
        'message'   => $e->getMessage(),
        'entitats'  => [],
        'persones'  => []
    ]);
}