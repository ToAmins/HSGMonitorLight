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

page_header('Geräte', 'admin');
?>
<h1>Geräte</h1>

<?php foreach ($messages as $m): ?>
<p class="note <?= $m['type'] === 'error' ? 'bad' : 'ok' ?>"><?= h($m['message']) ?></p>
<?php endforeach; ?>

<?php if ($newToken): ?>
<section class="card highlight">
  <h2>Installation auf „<?= h($newToken['name']) ?>“</h2>
  <p>Den Token zeigt diese Seite <strong>nur jetzt</strong> an. Auf dem Notebook eine PowerShell
     <strong>als Administrator</strong> öffnen, in den Ordner <code>agent</code> wechseln und diesen Befehl ausführen:</p>
  <pre class="command" id="install-cmd">powershell -ExecutionPolicy Bypass -File .\install.ps1 -Url "<?= h($reportUrl) ?>" -Token "<?= h($newToken['token']) ?>"</pre>
  <p><button type="button" class="button" data-copy="install-cmd">Befehl kopieren</button></p>
</section>
<?php endif; ?>

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
