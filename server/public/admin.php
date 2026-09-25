<?php
declare(strict_types=1);

// Geräteverwaltung: Gerät anlegen, Token erneuern, Gerät löschen.

require __DIR__ . '/../app/bootstrap.php';
require_login();

$pdo = db();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    check_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $device = find_device((int) ($_POST['id'] ?? 0));

    if ($action === 'create') {
        $name = trim((string) ($_POST['name'] ?? ''));
        if (!preg_match('/^.{1,40}$/u', $name)) {
            flash('error', 'Bitte einen Namen mit höchstens 40 Zeichen angeben.');
        } else {
            $token = new_token();
            try {
                $pdo->prepare('INSERT INTO devices (name, token_hash, created_at) VALUES (?, ?, ?)')
                    ->execute([$name, token_hash($token), now_utc()]);
                $_SESSION['new_token'] = ['name' => $name, 'token' => $token];
            } catch (PDOException) {
                flash('error', "Ein Gerät namens „{$name}“ gibt es schon.");
            }
        }
    } elseif ($action === 'renew' && $device) {
        $token = new_token();
        $pdo->prepare('UPDATE devices SET token_hash = ? WHERE id = ?')->execute([token_hash($token), $device['id']]);
        $_SESSION['new_token'] = ['name' => $device['name'], 'token' => $token];
    } elseif ($action === 'delete' && $device) {
        $pdo->prepare('DELETE FROM devices WHERE id = ?')->execute([$device['id']]);
        flash('ok', "„{$device['name']}“ wurde mit allen Meldungen gelöscht.");
    }

    header('Location: admin.php', true, 303);
    exit;
}

$newToken = $_SESSION['new_token'] ?? null;
unset($_SESSION['new_token']);
$messages = take_flash();
$devices = all_devices();

$base = (is_https() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$reportUrl = $base . '/api/report.php';
$agentVersion = agent_version();
$zipAvailable = class_exists(ZipArchive::class);

page_header('Geräte', 'admin');
?>
<h1>Geräte</h1>

<?php foreach ($messages as $m): ?>
<p class="note <?= $m['type'] === 'error' ? 'bad' : 'ok' ?>"><?= h($m['message']) ?></p>
<?php endforeach; ?>

<?php if ($newToken): ?>
<section class="card highlight">
  <h2>Installation auf „<?= h($newToken['name']) ?>“</h2>
  <p>Den Token zeigt diese Seite <strong>nur jetzt</strong> an. Am einfachsten direkt auf dem Notebook:</p>
  <ol class="steps">
    <?php if ($agentVersion !== null): ?>
    <li><a href="download.php">Agent herunterladen</a> und die ZIP-Datei nach <code>C:\</code> entpacken
        (ergibt <code>C:\HSGMonitorLight-agent</code>).</li>
    <?php else: ?>
    <li>Den Ordner <code>agent</code> aus dem Repo nach <code>C:\HSGMonitorLight-agent</code> kopieren.</li>
    <?php endif; ?>
    <li>PowerShell <strong>als Administrator</strong> öffnen (Startmenü → „PowerShell“ → „Als Administrator ausführen“).</li>
    <li>Diesen Befehl einfügen und ausführen:</li>
  </ol>
  <pre class="command" id="install-cmd">powershell -ExecutionPolicy Bypass -File C:\HSGMonitorLight-agent\install.ps1 -Url "<?= h($reportUrl) ?>" -Token "<?= h($newToken['token']) ?>"</pre>
  <p><button type="button" class="button" data-copy="install-cmd">Befehl kopieren</button></p>
  <p class="muted small">Liegt der Agent woanders, den Pfad hinter <code>-File</code> anpassen. Nach der Installation
     kann <code>C:\HSGMonitorLight-agent</code> gelöscht werden. Danach hier abmelden und das Passwort nicht im
     Browser des Notebooks speichern.</p>
</section>
<?php endif; ?>

<section class="card" id="agent">
  <h2>Agent herunterladen</h2>
  <?php if ($agentVersion === null): ?>
    <p class="muted">Auf dem Webspace liegt noch kein Agent. Dafür den Ordner <code>agent</code> aus dem Repo nach
       <code>/monitor/agent/</code> hochladen (siehe server/README.md).</p>
  <?php else: ?>
    <p>Version <?= h($agentVersion) ?> – enthält keinen Token, der kommt über den Befehl beim Anlegen dazu.
       Ein schon installierter Agent wird aktualisiert, indem man <code>install.ps1</code> ohne Parameter als
       Administrator ausführt.</p>
    <?php if ($zipAvailable): ?>
      <p><a class="button" href="download.php">Agent als ZIP herunterladen</a></p>
    <?php else: ?>
      <p>Einzeln herunterladen und in einen gemeinsamen Ordner legen:
      <?php foreach (AGENT_FILES as $i => $name): ?><?= $i ? ' · ' : '' ?><a href="download.php?file=<?= h(rawurlencode($name)) ?>"><?= h($name) ?></a><?php endforeach; ?></p>
    <?php endif; ?>
  <?php endif; ?>
</section>

<section class="card">
  <h2>Neues Gerät</h2>
  <form method="post" class="inline-form">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="create">
    <label for="name">Name</label>
    <input id="name" name="name" maxlength="40" required placeholder="z. B. Zeitnehmer 1">
    <button type="submit" class="button">Anlegen</button>
  </form>
</section>

<?php if ($devices): ?>
<section class="card">
  <h2>Angelegte Geräte</h2>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Name</th><th>Angelegt</th><th>Zuletzt gemeldet</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($devices as $d): ?>
        <tr>
          <td><?= h($d['name']) ?></td>
          <td><?= h(fmt_date($d['created_at'])) ?></td>
          <td><?= $d['last_seen_at'] ? h(fmt_datetime($d['last_seen_at'])) : '<span class="muted">noch nie</span>' ?></td>
          <td class="actions">
            <form method="post" data-confirm="Neuen Token für „<?= h($d['name']) ?>“ erzeugen? Der alte funktioniert dann sofort nicht mehr – der Agent muss mit dem neuen Token neu installiert werden.">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="action" value="renew">
              <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
              <button type="submit" class="button secondary">Token erneuern</button>
            </form>
            <form method="post" data-confirm="„<?= h($d['name']) ?>“ mit allen Meldungen löschen?">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
              <button type="submit" class="button danger">Löschen</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>
<?php
page_footer();
