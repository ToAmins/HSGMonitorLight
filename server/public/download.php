<?php
declare(strict_types=1);

/*
 * Liefert den Agenten zum Herunterladen – nur angemeldet. Der Geräte-Token steckt bewusst
 * nicht darin; er kommt über den Befehl aus der Geräteverwaltung dazu.
 * Ohne ?file= gibt es alles als ZIP, mit ?file=<Name> eine einzelne Datei (falls ZIP fehlt).
 */

require __DIR__ . '/../app/bootstrap.php';
require_login();

function send_file(string $path, string $name, string $type): never
{
    header('Content-Type: ' . $type);
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    readfile($path);
    exit;
}

$dir = agent_dir();
if ($dir === null) {
    fail_page(404, 'Agent nicht gefunden', 'Auf dem Webspace fehlt der Ordner agent. Er gehört nach /monitor/agent/ (siehe server/README.md).');
}

$file = (string) ($_GET['file'] ?? '');
if ($file !== '') {
    if (!in_array($file, AGENT_FILES, true) || !is_file($dir . '/' . $file)) {
        fail_page(404, 'Datei nicht gefunden', 'Diese Datei gehört nicht zum Agenten.');
    }
    send_file($dir . '/' . $file, $file, 'application/octet-stream');
}

if (!class_exists(ZipArchive::class)) {
    fail_page(500, 'ZIP nicht verfügbar', 'Auf dem Webspace fehlt die PHP-Erweiterung zip. Die Dateien lassen sich in der Geräteverwaltung einzeln herunterladen.');
}

$tmpDir = dirname((string) config('db_path')) . '/tmp';
ensure_private_dir($tmpDir);
$zipPath = $tmpDir . '/agent-' . bin2hex(random_bytes(8)) . '.zip';
try {
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('ZIP-Datei konnte nicht angelegt werden');
    }
    foreach (AGENT_FILES as $name) {
        if (is_file($dir . '/' . $name)) {
            $zip->addFile($dir . '/' . $name, 'HSGMonitorLight-agent/' . $name);
        }
    }
    $zip->close();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="HSGMonitorLight-agent-' . (agent_version() ?? 'x') . '.zip"');
    header('Content-Length: ' . filesize($zipPath));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    readfile($zipPath);
} finally {
    @unlink($zipPath);
}
