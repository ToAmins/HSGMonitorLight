<?php
declare(strict_types=1);

/*
 * Gemeinsamer Unterbau aller Seiten: Konfiguration, Datenbank, Anmeldung und
 * Ausgabe-Helfer. Liegt außerhalb des Document Root.
 */

const APP_VERSION = '0.1.1';
const DB_SCHEMA = 2;               // Stand der Datenbankstruktur (PRAGMA user_version)
const REPORT_SCHEMA = 1;           // Format der Meldungen vom Agenten
const MAX_REPORT_BYTES = 32768;    // eine echte Meldung hat etwa 7 KB
const REPORT_MIN_INTERVAL = 20;    // Sekunden zwischen zwei Meldungen eines Geräts
const SESSION_DAYS = 14;
const LOGIN_MAX_FAILURES = 5;      // Fehlversuche pro IP-Adresse ...
const LOGIN_WINDOW_MINUTES = 15;   // ... innerhalb dieses Zeitraums, danach ist die Anmeldung gesperrt

define('APP_ROOT', dirname(__DIR__));

// Fehler nur ins Protokoll des Webspace schreiben, nie mit Pfaden im Browser anzeigen.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
set_exception_handler(function (Throwable $e): void {
    error_log('HSGMonitorLight: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo 'Interner Fehler. Details stehen im Fehlerprotokoll des Webspace.';
});

// ------------------------------------------------------------------ Konfiguration

function default_config(): array
{
    return [
        'title' => 'HSGMonitorLight',
        'admin_user' => 'admin',
        'admin_password_hash' => '',
        'timezone' => 'Europe/Berlin',
        'db_path' => APP_ROOT . '/data/monitor.sqlite',
        'online_minutes' => 75,
        'keep_days' => 180,
        'keep_reports' => 2000,
    ];
}

/** Liefert die Einstellungen aus config.php oder null, solange es die Datei nicht gibt. */
function load_config(): ?array
{
    static $config = false;
    if ($config === false) {
        $config = null;
        $file = APP_ROOT . '/config.php';
        if (is_file($file)) {
            $values = require $file;
            $config = array_merge(default_config(), is_array($values) ? $values : []);
        }
    }
    return $config;
}

function config(string $key): mixed
{
    $config = load_config();
    if ($config === null) {
        fail_page(500, 'Noch nicht eingerichtet', 'Es fehlt die Datei config.php. Was zu tun ist, zeigt check.php.');
    }
    return $config[$key] ?? null;
}

date_default_timezone_set((load_config() ?? default_config())['timezone']);

// ------------------------------------------------------------------ Datenbank

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $path = (string) config('db_path');
        ensure_private_dir(dirname($path));
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA foreign_keys = ON');
        migrate($pdo);
    }
    return $pdo;
}

/** Legt einen Ordner an und sperrt ihn gegen Abruf aus dem Web. */
function ensure_private_dir(string $dir): void
{
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException("Ordner kann nicht angelegt werden: $dir");
    }
    if (!is_file($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', "Require all denied\n");
    }
}

function migrate(PDO $pdo): void
{
    if ((int) $pdo->query('PRAGMA user_version')->fetchColumn() >= DB_SCHEMA) {
        return;
    }
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $version = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
        if ($version < 1) {
            $pdo->exec('CREATE TABLE IF NOT EXISTS devices (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                name           TEXT NOT NULL UNIQUE,
                token_hash     TEXT NOT NULL UNIQUE,
                created_at     TEXT NOT NULL,
                last_seen_at   TEXT,
                last_ip        TEXT,
                last_report_id INTEGER
            )');
            $pdo->exec('CREATE TABLE IF NOT EXISTS reports (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                device_id   INTEGER NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
                received_at TEXT NOT NULL,
                remote_ip   TEXT NOT NULL,
                payload     TEXT NOT NULL
            )');
            $pdo->exec('CREATE INDEX IF NOT EXISTS reports_device_time ON reports (device_id, received_at)');
        }
        if ($version < 2) {
            $pdo->exec('CREATE TABLE IF NOT EXISTS login_failures (
                ip TEXT NOT NULL,
                at TEXT NOT NULL
            )');
            $pdo->exec('CREATE INDEX IF NOT EXISTS login_failures_ip ON login_failures (ip, at)');
        }
        $pdo->exec('PRAGMA user_version = ' . DB_SCHEMA);
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
}

// ------------------------------------------------------------------ Geräte und Meldungen

/** Zufälliger Geräte-Token (43 Zeichen, URL-sicher). Auf dem Server liegt nur sein Hash. */
function new_token(): string
{
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

function token_hash(string $token): string
{
    return hash('sha256', $token);
}

function find_device(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM devices WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function find_device_by_token(string $token): ?array
{
    $stmt = db()->prepare('SELECT * FROM devices WHERE token_hash = ?');
    $stmt->execute([token_hash($token)]);
    return $stmt->fetch() ?: null;
}

/** Alle Geräte, jeweils mit den Rohdaten ihrer letzten Meldung. */
function all_devices(): array
{
    return db()->query(
        'SELECT d.*, r.payload FROM devices d
         LEFT JOIN reports r ON r.id = d.last_report_id
         ORDER BY d.name COLLATE NOCASE'
    )->fetchAll();
}

/** Sekunden seit der letzten angenommenen Meldung des Geräts (null = noch nie). */
function seconds_since_last_report(array $device): ?int
{
    $last = to_local($device['last_seen_at'] ?? null);
    return $last ? time() - $last->getTimestamp() : null;
}

/**
 * Speichert eine Meldung und räumt dabei die alten dieses Geräts ab: älter als keep_days
 * oder jenseits der keep_reports neuesten. So bleibt die Datenbank auch dann begrenzt,
 * wenn ein Token in falsche Hände gerät.
 */
function store_report(int $deviceId, array $payload, string $ip): void
{
    $pdo = db();
    $now = now_utc();
    $cutoff = gmdate('Y-m-d\TH:i:s\Z', time() - 86400 * max(1, (int) config('keep_days')));
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $pdo->prepare('INSERT INTO reports (device_id, received_at, remote_ip, payload) VALUES (?, ?, ?, ?)')
            ->execute([$deviceId, $now, $ip, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
        $reportId = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE devices SET last_seen_at = ?, last_ip = ?, last_report_id = ? WHERE id = ?')
            ->execute([$now, $ip, $reportId, $deviceId]);
        $pdo->prepare(
            'DELETE FROM reports WHERE device_id = ? AND id <> ? AND (received_at < ? OR id <= (
                 SELECT id FROM reports WHERE device_id = ? ORDER BY id DESC LIMIT 1 OFFSET ?
             ))'
        )->execute([$deviceId, $reportId, $cutoff, $deviceId, max(1, (int) config('keep_reports'))]);
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
}

/**
 * Bereitet ein Gerät samt letzter Meldung für die Anzeige auf. Die Meldung kommt vom Agenten
 * und wird deshalb nur gelesen, nie vorausgesetzt: Fehlende Felder bleiben leer.
 */
function device_view(array $row): array
{
    $payload = json_decode((string) ($row['payload'] ?? ''), true);
    $payload = is_array($payload) ? $payload : [];
    $device = sub($payload, 'device');
    $windows = sub($payload, 'windows');
    $updates = sub($payload, 'updates');
    $patch = sub($updates, 'current_patch');

    $networks = [];
    foreach ((array) ($payload['network'] ?? []) as $net) {
        if (is_array($net)) {
            $networks[] = [
                'type' => match (text($net, 'type')) { 'wlan' => 'WLAN', 'lan' => 'LAN', 'mobil' => 'Mobilfunk', default => 'Netz' },
                'name' => text($net, 'name'),
                'ipv4' => implode(', ', array_filter((array) ($net['ipv4'] ?? []), 'is_string')),
                'internet' => ($net['internet'] ?? false) === true,
            ];
        }
    }

    $recent = [];
    foreach ((array) ($updates['recent'] ?? []) as $item) {
        if (is_array($item)) {
            $recent[] = ['date' => text($item, 'date'), 'title' => text($item, 'title')];
        }
    }

    $lastSeen = to_local($row['last_seen_at'] ?? null);
    $onlineSeconds = 60 * (int) (load_config() ?? default_config())['online_minutes'];

    return [
        'has_report' => $payload !== [],
        'online' => $lastSeen !== null && time() - $lastSeen->getTimestamp() <= $onlineSeconds,
        'hostname' => text($device, 'hostname'),
        'model' => trim(text($device, 'manufacturer') . ' ' . text($device, 'model')),
        'user' => text($device, 'user'),
        'last_boot' => text($device, 'last_boot'),
        'os' => trim(text($windows, 'product') . ' ' . text($windows, 'display_version')),
        'build' => text($windows, 'version'),
        'patch_month' => patch_month(text($patch, 'title')),
        'patch_kb' => text($patch, 'kb'),
        'patch_installed' => text($patch, 'date'),
        'reboot_required' => ($updates['reboot_required'] ?? false) === true,
        'last_search' => text($updates, 'last_search_success'),
        'recent' => $recent,
        'networks' => $networks,
        'agent_version' => text($payload, 'agent_version'),
        'errors' => array_values(array_filter((array) ($payload['errors'] ?? []), 'is_string')),
    ];
}

function sub(array $data, string $key): array
{
    return is_array($data[$key] ?? null) ? $data[$key] : [];
}

function text(array $data, string $key): string
{
    $value = $data[$key] ?? '';
    return is_scalar($value) ? (string) $value : '';
}

/** "2026-09 Sicherheitsupdate (…)" → "September 2026". Der Monat steht in jeder Sprache vorn. */
function patch_month(string $title): string
{
    if (!preg_match('/^(\d{4})-(\d{2})\b/', $title, $m) || (int) $m[2] < 1 || (int) $m[2] > 12) {
        return '';
    }
    $months = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August',
        'September', 'Oktober', 'November', 'Dezember'];
    return $months[(int) $m[2] - 1] . ' ' . $m[1];
}

// ------------------------------------------------------------------ Zeit

function now_utc(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

function to_local(?string $iso): ?DateTimeImmutable
{
    if ($iso === null || $iso === '') {
        return null;
    }
    try {
        return (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone(date_default_timezone_get()));
    } catch (Exception) {
        return null;
    }
}

function fmt_datetime(?string $iso): string
{
    $d = to_local($iso);
    return $d ? weekday($d) . ' ' . $d->format('d.m.Y, H:i') : '–';
}

function fmt_date(?string $iso): string
{
    $d = to_local($iso);
    return $d ? $d->format('d.m.Y') : '–';
}

function weekday(DateTimeImmutable $d): string
{
    return ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'][(int) $d->format('w')];
}

function fmt_ago(?string $iso): string
{
    $d = to_local($iso);
    if (!$d) {
        return '';
    }
    $s = time() - $d->getTimestamp();
    if ($s < 90) {
        return 'gerade eben';
    }
    if ($s < 3600) {
        return 'vor ' . round($s / 60) . ' Min.';
    }
    if ($s < 86400) {
        $h = (int) round($s / 3600);
        return $h === 1 ? 'vor 1 Stunde' : "vor $h Stunden";
    }
    $days = intdiv($s, 86400);
    return $days === 1 ? 'vor 1 Tag' : "vor $days Tagen";
}

// ------------------------------------------------------------------ Sitzung und Anmeldung

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['SERVER_PORT'] ?? '') === '443'
        || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    // Eigener Ordner, damit die Aufräumroutine anderer Skripte auf dem Webspace
    // die Anmeldung nicht nach 24 Minuten beendet.
    $config = load_config();
    if ($config !== null) {
        $dir = dirname((string) $config['db_path']) . '/sessions';
        try {
            ensure_private_dir($dir);
            session_save_path($dir);
            ini_set('session.gc_probability', '1');
        } catch (RuntimeException) {
            // Dann eben der Standardordner des Webspace.
        }
    }
    ini_set('session.gc_maxlifetime', (string) (SESSION_DAYS * 86400));
    ini_set('session.use_strict_mode', '1');
    session_name('hml');
    session_set_cookie_params([
        'lifetime' => SESSION_DAYS * 86400,
        'path' => '/',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function is_logged_in(): bool
{
    start_session();
    return !empty($_SESSION['user']);
}

function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: login.php?next=' . rawurlencode(basename($_SERVER['SCRIPT_NAME'] ?? 'index.php')), true, 303);
        exit;
    }
}

function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
}

/** Zu viele Fehlversuche von dieser IP-Adresse in den letzten Minuten? */
function login_blocked(string $ip): bool
{
    $since = gmdate('Y-m-d\TH:i:s\Z', time() - 60 * LOGIN_WINDOW_MINUTES);
    db()->prepare('DELETE FROM login_failures WHERE at < ?')->execute([$since]);
    $stmt = db()->prepare('SELECT COUNT(*) FROM login_failures WHERE ip = ?');
    $stmt->execute([$ip]);
    return (int) $stmt->fetchColumn() >= LOGIN_MAX_FAILURES;
}

function record_login_failure(string $ip): void
{
    db()->prepare('INSERT INTO login_failures (ip, at) VALUES (?, ?)')->execute([$ip, now_utc()]);
}

function clear_login_failures(string $ip): void
{
    db()->prepare('DELETE FROM login_failures WHERE ip = ?')->execute([$ip]);
}

function csrf_token(): string
{
    start_session();
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function check_csrf(): void
{
    if (!hash_equals(csrf_token(), (string) ($_POST['csrf'] ?? ''))) {
        fail_page(400, 'Sitzung abgelaufen', 'Bitte die Seite neu laden und es noch einmal versuchen.');
    }
}

function flash(string $type, string $message): void
{
    start_session();
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flash(): array
{
    start_session();
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

// ------------------------------------------------------------------ Ausgabe

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function send_security_headers(): void
{
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('X-Frame-Options: DENY');
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store');
    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

function page_header(string $title, string $active = ''): void
{
    send_security_headers();
    header('Content-Type: text/html; charset=utf-8');
    $appTitle = (load_config() ?? default_config())['title'];
    $loggedIn = !empty($_SESSION['user']);
    $current = fn(string $page) => $page === $active ? ' aria-current="page"' : '';
    ?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($title) ?> – <?= h($appTitle) ?></title>
<link rel="stylesheet" href="assets/style.css?v=<?= APP_VERSION ?>">
<script src="assets/app.js?v=<?= APP_VERSION ?>" defer></script>
</head>
<body>
<header class="topbar">
  <div class="topbar-inner">
    <a class="brand" href="index.php"><?= h($appTitle) ?></a>
    <?php if ($loggedIn): ?>
    <nav>
      <a href="index.php"<?= $current('index') ?>>Übersicht</a>
      <a href="admin.php"<?= $current('admin') ?>>Geräte</a>
      <form method="post" action="login.php">
        <input type="hidden" name="action" value="logout">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <button type="submit" class="linklike">Abmelden</button>
      </form>
    </nav>
    <?php endif; ?>
  </div>
</header>
<main>
<?php
}

function page_footer(): void
{
    ?>
</main>
<footer class="footer">HSGMonitorLight <?= APP_VERSION ?></footer>
</body>
</html>
<?php
}

function fail_page(int $status, string $title, string $message): never
{
    if (!headers_sent()) {
        http_response_code($status);
    }
    page_header($title);
    echo '<section class="card narrow"><h1>' . h($title) . '</h1><p>' . h($message) . '</p></section>';
    page_footer();
    exit;
}
