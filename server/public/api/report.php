<?php
declare(strict_types=1);

/*
 * Nimmt die Meldungen der Agenten an: POST mit JSON-Body und Header X-Client-Token.
 * Die Antwort ist nur {"ok":true} – Befehle an die Geräte gibt es bewusst nicht.
 * Den Standard-Header "Authorization" reichen manche PHP-Setups (CGI/FPM) nicht durch,
 * deshalb der eigene Header.
 */

require __DIR__ . '/../../app/bootstrap.php';

function respond(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond(405, ['ok' => false, 'error' => 'Nur POST erlaubt']);
}

$token = (string) ($_SERVER['HTTP_X_CLIENT_TOKEN'] ?? '');
if (!preg_match('/^[A-Za-z0-9_-]{32,128}$/', $token)) {
    respond(401, ['ok' => false, 'error' => 'Token fehlt oder hat ein falsches Format']);
}

if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > MAX_REPORT_BYTES) {
    respond(413, ['ok' => false, 'error' => 'Meldung zu groß']);
}
$raw = (string) file_get_contents('php://input', false, null, 0, MAX_REPORT_BYTES + 1);
if (strlen($raw) > MAX_REPORT_BYTES) {
    respond(413, ['ok' => false, 'error' => 'Meldung zu groß']);
}

try {
    $device = find_device_by_token($token);
    if ($device === null) {
        respond(401, ['ok' => false, 'error' => 'Unbekannter Token']);
    }

    $payload = json_decode($raw, true, 16);
    if (!is_array($payload) || ($payload['schema_version'] ?? null) !== REPORT_SCHEMA) {
        respond(400, ['ok' => false, 'error' => 'Meldung hat nicht das erwartete Format']);
    }

    // Die öffentliche IP bestimmt der Server selbst – die Angabe des Geräts ließe sich fälschen.
    store_report((int) $device['id'], $payload, (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    respond(200, ['ok' => true]);
} catch (Throwable $e) {
    error_log('HSGMonitorLight report.php: ' . $e->getMessage());
    respond(500, ['ok' => false, 'error' => 'Serverfehler']);
}
