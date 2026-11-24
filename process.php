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

header('Content-Type: application/json; charset=utf-8');

// ================= CONFIGURACIÓN =================
$spreadsheetId = envValue('SPREADSHEET_ID', '1c2-jfz1TotTV380NKDok65rn_oRxZJCI8uml2zBv-1Y');
$sheetName = 'Full 1';              // Adhesiones confirmadas
$pendingSheetName = 'Pendientes';   // Pestaña de verificaciones pendientes

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
    echo json_encode([
        'success' => false,
        'message' => 'No se pudieron cargar las credenciales de Google.'
    ]);

    exit;
}

// ========== CORREO (Brevo, API por HTTPS) ==========
// Variables necesarias en Railway: BREVO_API_KEY y MAIL_FROM (remitente verificado en Brevo).
$brevoApiKey = envValue('BREVO_API_KEY');
$mailFrom    = envValue('MAIL_FROM');

function sheetsService(array $credentials): Sheets {
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
        'textContent' => trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html))),
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

function emailExists(Sheets $service, string $spreadsheetId, string $sheetName, string $email): bool {
    $response = $service->spreadsheets_values->get($spreadsheetId, $sheetName . '!G:G');
    foreach (($response->getValues() ?? []) as $row) {
        if (isset($row[0]) && strtolower(trim((string)$row[0])) === strtolower($email)) {
            return true;
        }
    }
    return false;
}

function pendingEmailExists(Sheets $service, string $spreadsheetId, string $pendingSheetName, string $email): bool {
    $response = $service->spreadsheets_values->get($spreadsheetId, $pendingSheetName . '!A:C');
    foreach (($response->getValues() ?? []) as $row) {
        if (!empty($row[2])) {
            $data = json_decode((string)$row[2], true);
            if (is_array($data) && strtolower((string)($data['email'] ?? '')) === $email) {
                // Si la solicitud pendiente ya caducó, no bloquea un nuevo intento.
                $expiresAt = (int)($row[1] ?? 0);
                if ($expiresAt >= time()) {
                    return true;
                }
            }
        }
    }
    return false;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Mètode no permès']);
        exit;
    }

    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('El correo electrónico no es válido.');
    }

    $tipo = isset($_POST['entity_name']) ? 'entity' : 'individual';

    $fecha = date('Y-m-d H:i:s');
    if ($tipo === 'entity') {
        $visibilidad = (string)($_POST['visibilitat_entitat'] ?? '');
        if (!in_array($visibilidad, ['publica', 'privada'], true)) {
            throw new RuntimeException('Selecciona la visibilidad de la adhesión.');
        }
        $fila = [
            $fecha, 'Entitat',
            trim((string)($_POST['entity_name'] ?? '')),
            trim((string)($_POST['representative'] ?? '')),
            trim((string)($_POST['role'] ?? '')),
            '', $email,
            trim((string)($_POST['municipality'] ?? '')),
            trim((string)($_POST['ambit'] ?? '')),
            $visibilidad === 'publica' ? 'Pública' : 'Privada'
        ];
        $nombre = $fila[2];
    } else {
        $visibilidad = (string)($_POST['visibilitat_individual'] ?? '');
        if (!in_array($visibilidad, ['public', 'private'], true)) {
            throw new RuntimeException('Selecciona la visibilidad de la adhesión.');
        }
        $fila = [
            $fecha, 'Individual', '', '', '',
            trim((string)($_POST['full_name'] ?? '')),
            $email,
            trim((string)($_POST['municipality'] ?? '')),
            '',
            $visibilidad === 'public' ? 'Pública' : 'Privada'
        ];
        $nombre = $fila[5];
    }

    // Comprobaciones básicas en el servidor.
    if ($tipo === 'entity' && ($fila[2] === '' || $fila[3] === '' || $fila[4] === '')) {
        throw new RuntimeException('Completa los campos obligatorios de la entidad.');
    }
    if ($tipo === 'individual' && $fila[5] === '') {
        throw new RuntimeException('Indica tu nombre y apellidos.');
    }

    $service = sheetsService($credentials);

    if (emailExists($service, $spreadsheetId, $sheetName, $email) ||
        pendingEmailExists($service, $spreadsheetId, $pendingSheetName, $email)) {
        echo json_encode([
            'success' => false,
            'message' => 'Este correo ya está registrado o tiene una verificación pendiente. Revisa tu bandeja de entrada.'
        ]);
        exit;
    }

    // Guardamos temporalmente la adhesión, NO en la hoja principal.
    $token = bin2hex(random_bytes(32));
    $expiresAt = time() + 86400; // 24 horas
    $pendingData = [
        'tipo' => $tipo,
        'fila' => $fila,
        'email' => $email,
        'nombre' => $nombre
    ];

    $pendingRow = [$token, (string)$expiresAt, json_encode($pendingData, JSON_UNESCAPED_UNICODE)];
    $body = new ValueRange(['values' => [$pendingRow]]);
    $service->spreadsheets_values->append(
        $spreadsheetId,
        $pendingSheetName . '!A:C',
        $body,
        ['valueInputOption' => 'RAW']
    );

    // URL del sitio: define SITE_BASE_URL en Railway (p. ej. https://tuapp.up.railway.app).
    $siteBaseUrl = envValue('SITE_BASE_URL');
    if (!$siteBaseUrl) {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $scheme = $https ? 'https' : 'http';
        $siteBaseUrl = $scheme . '://' . $_SERVER['HTTP_HOST'];
    }
    $confirmUrl = rtrim($siteBaseUrl, '/') . '/confirm.php?token=' . urlencode($token);

    $safeName = htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8');
    $html = '<div style="font-family:Arial,sans-serif;line-height:1.6;color:#263445;max-width:600px;margin:auto">'
          . '<h2>Confirma tu correo electrónico</h2>'
          . '<p>Hola ' . $safeName . ',</p>'
          . '<p>Hemos recibido tu solicitud de adhesión al Manifest pel Dret a la Mobilitat.</p>'
          . '<p>Para confirmar que esta dirección de correo es tuya y completar la adhesión, pulsa el botón:</p>'
          . '<p style="margin:28px 0"><a href="' . htmlspecialchars($confirmUrl, ENT_QUOTES, 'UTF-8') . '" '
          . 'style="background:#263b50;color:#fff;padding:14px 22px;text-decoration:none;border-radius:6px;display:inline-block">'
          . 'Verificar mi correo</a></p>'
          . '<p>El enlace caduca en 24 horas. Si no has solicitado esta adhesión, puedes ignorar este mensaje.</p>'
          . '<p>Plataforma pel Dret a la Mobilitat</p></div>';

    try {
        sendMail($email, 'Confirma tu adhesión al Manifest pel Dret a la Mobilitat', $html, $brevoApiKey, $mailFrom);
    } catch (Throwable $mailError) {
        // Si no se puede enviar el correo, borramos la solicitud pendiente para que pueda volver a intentarlo.
        try {
            $values = $service->spreadsheets_values->get($spreadsheetId, $pendingSheetName . '!A:A')->getValues() ?? [];
            foreach ($values as $index => $row) {
                if (($row[0] ?? '') === $token) {
                    $service->spreadsheets_values->clear(
                        $spreadsheetId,
                        $pendingSheetName . '!A' . ($index + 1) . ':C' . ($index + 1),
                        new ClearValuesRequest()
                    );
                    break;
                }
            }
        } catch (Throwable $cleanupError) {
            error_log('No se pudo limpiar la fila pendiente: ' . $cleanupError->getMessage());
        }
        error_log('No se pudo enviar el correo de verificación: ' . $mailError->getMessage());
        throw new RuntimeException('No se ha podido enviar el correo de verificación. Inténtalo de nuevo más tarde.');
    }

    echo json_encode([
        'success' => true,
        'message' => 'Solicitud recibida. Te hemos enviado un correo para verificar tu dirección. Revisa también la carpeta de spam. La adhesión se registrará cuando pulses el enlace.'
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    error_log('Error en process.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
