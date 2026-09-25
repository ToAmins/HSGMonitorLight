<?php
declare(strict_types=1);

/*
 * Einrichtungshilfe: prüft den Webspace und erzeugt den Passwort-Hash für config.php.
 * Solange noch kein Passwort eingerichtet ist, ist die Seite frei erreichbar, danach nur
 * noch angemeldet.
 */

require __DIR__ . '/../app/bootstrap.php';

$config = load_config();
$ready = $config !== null && (string) $config['admin_password_hash'] !== '';
if ($ready) {
    require_login();
}

$hash = null;
$hashError = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $password = (string) ($_POST['password'] ?? '');
    if (strlen($password) < 12) {
        $hashError = 'Bitte mindestens 12 Zeichen verwenden.';
    } elseif ($password !== (string) ($_POST['password2'] ?? '')) {
        $hashError = 'Die beiden Eingaben unterscheiden sich.';
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
    }
}

$docRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
$checks = [
    ['PHP ' . PHP_VERSION, version_compare(PHP_VERSION, '8.3', '>='),
        'Läuft ab PHP 8.1, aber ältere Versionen als 8.3 bekommen bald oder schon keine Sicherheitsupdates mehr. Im KAS bei der Subdomain PHP 8.3 oder neuer wählen.'],
    ['SQLite (pdo_sqlite)', extension_loaded('pdo_sqlite'),
        'Die Erweiterung fehlt. Beim Hoster nachfragen.'],
    ['HTTPS', is_https(),
        'Im KAS für die Subdomain ein SSL-Zertifikat (Let\'s Encrypt) aktivieren und „SSL erzwingen“ einschalten.'],
    ['Document Root zeigt auf den Ordner public', $docRoot !== false && $docRoot === realpath(__DIR__),
        'Im KAS als Ziel der Subdomain den Ordner …/monitor/public/ eintragen. Sonst sind config.php und die Daten womöglich aus dem Web erreichbar.'],
    ['config.php vorhanden', $config !== null,
        'config.example.php als config.php speichern (im Ordner über public) und anpassen.'],
    ['Passwort eingerichtet', $ready,
        'Unten einen Hash erzeugen und bei admin_password_hash in config.php eintragen.'],
];
if ($config !== null) {
    try {
        db();
        $checks[] = ['Datenbank', true, ''];
    } catch (Throwable $e) {
        $checks[] = ['Datenbank', false, 'Fehler: ' . $e->getMessage()];
    }
}

page_header('Einrichtung prüfen');
?>
<section class="card narrow">
  <h1>Einrichtung prüfen</h1>
  <ul class="checks">
    <?php foreach ($checks as [$label, $ok, $hint]): ?>
    <li class="<?= $ok ? 'ok' : 'bad' ?>">
      <span class="mark" aria-hidden="true"><?= $ok ? '✓' : '✗' ?></span>
      <div><strong><?= h($label) ?></strong><?php if (!$ok): ?><br><span class="small"><?= h($hint) ?></span><?php endif; ?></div>
    </li>
    <?php endforeach; ?>
  </ul>
</section>

<section class="card narrow">
  <h2>Passwort-Hash erzeugen</h2>
  <p class="small">Das Passwort wird nicht gespeichert. Die Seite zeigt nur den Hash an, den du in
     <code>config.php</code> einträgst.</p>
  <?php if ($hashError): ?><p class="note bad"><?= h($hashError) ?></p><?php endif; ?>
  <?php if ($hash): ?>
    <p>In <code>config.php</code> eintragen:</p>
    <pre class="command" id="hash-line">'admin_password_hash' => '<?= h($hash) ?>',</pre>
    <p><button type="button" class="button" data-copy="hash-line">Zeile kopieren</button></p>
  <?php endif; ?>
  <form method="post" class="stack">
    <label for="password">Neues Passwort (mindestens 12 Zeichen)</label>
    <input id="password" name="password" type="password" autocomplete="new-password" required minlength="12">
    <label for="password2">Noch einmal</label>
    <input id="password2" name="password2" type="password" autocomplete="new-password" required minlength="12">
    <button type="submit" class="button">Hash erzeugen</button>
  </form>
</section>
<?php
page_footer();
