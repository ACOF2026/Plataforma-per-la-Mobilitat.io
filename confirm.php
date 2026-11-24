<?php
declare(strict_types=1);

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
use Google\Service\Sheets\ValueRange;
use Google\Service\Sheets\ClearValuesRequest;

$spreadsheetId = envValue('SPREADSHEET_ID', '1KpDNdgOdvxmdde1WLvNiQXbfq31gtQoJR086HOe5ENk');
$sheetName = 'Hoja 1';
$pendingSheetName = 'Pendientes';

function page(string $title, string $message, bool $ok): void {
    $color = $ok ? '#18794e' : '#b42318';
    echo '<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>'
       . '<body style="margin:0;background:#f4f6f8;font-family:Arial,sans-serif;color:#263445;display:grid;min-height:100vh;place-items:center">'
       . '<main style="background:#fff;max-width:560px;margin:24px;padding:36px;border-radius:14px;box-shadow:0 8px 30px #0001;text-align:center">'
       . '<h1 style="color:' . $color . '">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>'
       . '<p style="line-height:1.7">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
       . '<a href="index.html" style="display:inline-block;margin-top:16px;background:#263b50;color:#fff;padding:12px 20px;border-radius:6px;text-decoration:none">Volver a la página</a>'
       . '</main></body></html>';
}

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
    page('Error del servidor', 'No se pudieron cargar las credenciales de Google.', false);
    exit;
}

// ========== CORREO (Brevo, API por HTTPS) ==========
$brevoApiKey = envValue('BREVO_API_KEY');
$mailFrom    = envValue('MAIL_FROM');
$adminEmail  = envValue('ADMIN_EMAIL') ?: $mailFrom;

function sheetsServiceConfirm(array $credentials): Sheets {
    $client = new Client();
    $client->setApplicationName('Formulario Adhesions');
    $client->setScopes([Sheets::SPREADSHEETS]);
    $client->setAuthConfig($credentials);
    return new Sheets($client);
}

function sendMail(string $to, string $subject, string $html, ?string $apiKey, ?string $from): void {
    if (!$apiKey || !$from) {
        throw new RuntimeException('Falta configurar BREVO_API_KEY o MAIL_FROM en el servidor.');
    }

    $payload = [
        'sender'      => ['name' => 'Plataforma pel Dret a la Mobilitat', 'email' => $from],
        'to'          => [['email' => $to]],
        'subject'     => $subject,
        'htmlContent' => $html,
    ];

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'accept: application/json',
            'api-key: ' . $apiKey,
            'content-type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('Error de conexión con Brevo: ' . $curlErr);
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException('Brevo respondió HTTP ' . $httpCode . ': ' . $response);
    }
}

function sendAdminNotice(array $data, ?string $adminEmail, ?string $apiKey, ?string $from): void {
    if (!$adminEmail) {
        throw new RuntimeException('No hay ADMIN_EMAIL ni MAIL_FROM configurado.');
    }

    $subjectName = $data['tipo'] === 'entity'
        ? ($data['fila'][2] ?? 'Entitat')
        : ($data['fila'][5] ?? 'Persona');
    $subject = 'Nova adhesió confirmada: ' . $subjectName;

    $labels = ['Fecha', 'Tipo', 'Entidad', 'Representante', 'Cargo', 'Nombre', 'Correo', 'Municipio', 'Ámbito', 'Visibilidad'];
    $body = '<h2>Nova adhesió confirmada</h2>';
    foreach (($data['fila'] ?? []) as $i => $value) {
        $label = $labels[$i] ?? ('Campo ' . ($i + 1));
        $body .= '<p><strong>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . ':</strong> '
              . htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') . '</p>';
    }

    sendMail($adminEmail, $subject, $body, $apiKey, $from);
}

try {
    $token = trim((string)($_GET['token'] ?? ''));
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        throw new RuntimeException('El enlace de verificación no es válido.');
    }

    $service = sheetsServiceConfirm($credentials);
    $response = $service->spreadsheets_values->get($spreadsheetId, $pendingSheetName . '!A:C');
    $rows = $response->getValues() ?? [];
    $foundIndex = null;
    $pendingData = null;

    foreach ($rows as $index => $row) {
        if (isset($row[0]) && hash_equals((string)$row[0], $token)) {
            $foundIndex = $index;
            $expiresAt = (int)($row[1] ?? 0);
            $pendingData = json_decode((string)($row[2] ?? ''), true);
            if ($expiresAt < time()) {
                $service->spreadsheets_values->clear(
                    $spreadsheetId,
                    $pendingSheetName . '!A' . ($index + 1) . ':C' . ($index + 1),
                    new ClearValuesRequest()
                );
                throw new RuntimeException('El enlace ha caducado. Vuelve a enviar el formulario para recibir uno nuevo.');
            }
            break;
        }
    }

    if ($foundIndex === null || !is_array($pendingData) || empty($pendingData['fila'])) {
        throw new RuntimeException('Este enlace ya se ha utilizado o no existe. Si necesitas adherirte, vuelve a enviar el formulario.');
    }

    // Evita duplicados si la dirección ya se confirmó por otra solicitud.
    $email = strtolower((string)($pendingData['email'] ?? ''));
    $existing = $service->spreadsheets_values->get($spreadsheetId, $sheetName . '!G:G')->getValues() ?? [];
    foreach ($existing as $row) {
        if (isset($row[0]) && strtolower(trim((string)$row[0])) === $email) {
            $service->spreadsheets_values->clear(
                $spreadsheetId,
                $pendingSheetName . '!A' . ($foundIndex + 1) . ':C' . ($foundIndex + 1),
                new ClearValuesRequest()
            );
            throw new RuntimeException('Este correo ya figura como adhesión confirmada.');
        }
    }

    $body = new ValueRange(['values' => [$pendingData['fila']]]);
    $service->spreadsheets_values->append(
        $spreadsheetId,
        $sheetName . '!A1',
        $body,
        ['valueInputOption' => 'USER_ENTERED']
    );

    // El token se invalida después de registrar la adhesión.
    $service->spreadsheets_values->clear(
        $spreadsheetId,
        $pendingSheetName . '!A' . ($foundIndex + 1) . ':C' . ($foundIndex + 1),
        new ClearValuesRequest()
    );

    try {
        sendAdminNotice($pendingData, $adminEmail, $brevoApiKey, $mailFrom);
    } catch (Throwable $mailError) {
        error_log('Adhesión confirmada, pero no se pudo enviar el aviso al administrador: ' . $mailError->getMessage());
    }

    page('¡Correo verificado!', 'Tu dirección se ha confirmado y la adhesión se ha registrado correctamente. ¡Gracias por sumarte!', true);

} catch (Throwable $e) {
    error_log('Error en confirm.php: ' . $e->getMessage());
    page('No se ha podido verificar', $e->getMessage(), false);
}
