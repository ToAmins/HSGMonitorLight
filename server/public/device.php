<?php
declare(strict_types=1);

// Detailseite eines Geräts: Ampel, Online-Zeiten, Updates, IP-Verlauf und Rohdaten.

require __DIR__ . '/../app/bootstrap.php';
require_login();

$device = find_device((int) ($_GET['id'] ?? 0));
if ($device === null) {
    fail_page(404, 'Gerät nicht gefunden', 'Dieses Gerät gibt es nicht (mehr).');
}
$row = null;
foreach (all_devices() as $candidate) {
    if ((int) $candidate['id'] === (int) $device['id']) {
        $row = $candidate;
    }
}
$v = device_view($row);
$s = $v['status'];
$sessions = array_slice(device_sessions((int) $device['id']), 0, 40);
$ips = device_ips((int) $device['id']);
$raw = json_decode((string) ($row['payload'] ?? ''), true);

/** Uhrzeit (lokal) aus einem Unix-Zeitstempel. */
function hm(int $t): string
{
    return (new DateTimeImmutable('@' . $t))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('H:i');
}

function day(int $t): string
{
    $d = (new DateTimeImmutable('@' . $t))->setTimezone(new DateTimeZone(date_default_timezone_get()));
    return weekday($d) . ' ' . $d->format('d.m.Y');
}

page_header($device['name'], 'index');
?>
<p class="small"><a href="index.php">← Übersicht</a></p>
<h1><?= h($device['name']) ?></h1>
<?php if ($v['hostname'] !== '' || $v['model'] !== ''): ?>
<p class="muted"><?= h(implode(' · ', array_filter([$v['hostname'], $v['model'], $v['os'], $v['build'] !== '' ? 'Build ' . $v['build'] : '']))) ?></p>
<?php endif; ?>

<section class="card level-<?= h($s['level']) ?>">
  <p class="status status-<?= h($s['level']) ?>"><strong><?= h($s['label']) ?></strong> · <?= h(implode(' · ', $s['reasons'])) ?></p>
  <p class="muted small">Stand der letzten Meldung: <?= h(fmt_datetime($v['last_seen'])) ?> (<?= h(fmt_ago($v['last_seen'])) ?>)</p>
</section>

<section class="card">
  <h2>Online-Zeiten</h2>
  <p class="muted small">Aus den Meldungen der letzten 60 Tage. Der Agent meldet sich beim Start, bei jeder neuen
     Netzwerkverbindung und stündlich. Die tatsächliche Einschaltzeit kann deshalb etwas früher beginnen.</p>
  <?php if (!$sessions): ?>
    <p class="muted">Noch keine Meldungen.</p>
  <?php else: ?>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Tag</th><th>Zeit</th><th>Netzwerk</th><th>Öffentliche IP</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($sessions as $x): ?>
        <tr>
          <td><?= h(day($x['start'])) ?></td>
          <td><?= h($x['start'] === $x['end'] ? hm($x['start']) : hm($x['start']) . '–' . hm($x['end'])) ?></td>
          <td><?= h(implode(', ', array_keys($x['nets'])) ?: '–') ?></td>
          <td><code><?= h(implode(', ', array_keys($x['ips'])) ?: '–') ?></code></td>
          <td class="muted small">
            <?php if ($x['online'] === 0): ?>ohne Verbindung zum Server<?php elseif ($x['offline'] > 0): ?>zeitweise ohne Verbindung<?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<div class="cards">
  <section class="card">
    <h2>Ausstehende Updates</h2>
    <?php if (!$v['pending_known']): ?>
      <p class="muted">Unbekannt – der Agent auf dem Gerät ist älter als 0.2.0.</p>
    <?php elseif (!$v['pending_items']): ?>
      <p>Keine. <span class="muted small">Geprüft am <?= h(fmt_datetime($v['pending_checked'])) ?></span></p>
    <?php else: ?>
      <ul class="plain"><?php foreach ($v['pending_items'] as $title): ?><li><?= h($title) ?></li><?php endforeach; ?></ul>
      <p class="muted small">Geprüft am <?= h(fmt_datetime($v['pending_checked'])) ?></p>
    <?php endif; ?>
  </section>

  <section class="card">
    <h2>Zuletzt installiert</h2>
    <?php if (!$v['recent']): ?>
      <p class="muted">Keine Angaben.</p>
    <?php else: ?>
      <ul class="plain">
        <?php foreach ($v['recent'] as $u): ?><li><span class="muted"><?= h(fmt_date($u['date'])) ?></span> <?= h($u['title']) ?></li><?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</div>

<section class="card">
  <h2>Öffentliche IP-Adressen</h2>
  <?php if (!$ips): ?>
    <p class="muted">Noch keine Meldungen.</p>
  <?php else: ?>
  <div class="table-wrap">
    <table>
      <thead><tr><th>IP-Adresse</th><th>Zuerst</th><th>Zuletzt</th><th>Meldungen</th></tr></thead>
      <tbody>
      <?php foreach ($ips as $ip): ?>
        <tr>
          <td><code><?= h($ip['remote_ip']) ?></code></td>
          <td><?= h(fmt_datetime($ip['first_seen'])) ?></td>
          <td><?= h(fmt_datetime($ip['last_seen'])) ?></td>
          <td><?= (int) $ip['reports'] ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<?php if (is_array($raw)): ?>
<section class="card">
  <details>
    <summary>Rohdaten der letzten Meldung</summary>
    <pre class="command"><?= h(json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
  </details>
</section>
<?php endif; ?>
<?php
page_footer();
