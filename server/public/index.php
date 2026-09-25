<?php
declare(strict_types=1);

// Übersicht: eine Karte pro Gerät mit Update-Ampel und dem Stand der letzten Meldung.

require __DIR__ . '/../app/bootstrap.php';
require_login();

$devices = all_devices();
$patchday = next_patchday(new DateTimeImmutable('now'));
page_header('Übersicht', 'index');
?>
<h1>Übersicht</h1>
<p class="muted small">Nächster Patchday: <?= h(weekday($patchday) . ' ' . $patchday->format('d.m.Y')) ?> ·
   Das Monatsupdate sollte eine Woche danach installiert sein.</p>

<?php if (!$devices): ?>
<section class="card narrow">
  <p>Noch keine Geräte angelegt.</p>
  <p><a class="button" href="admin.php">Gerät anlegen</a></p>
</section>
<?php endif; ?>

<div class="cards">
<?php foreach ($devices as $row): $v = device_view($row); $s = $v['status']; ?>
  <article class="card device level-<?= h($s['level']) ?>">
    <header class="device-head">
      <div>
        <h2><a href="device.php?id=<?= (int) $row['id'] ?>"><?= h($row['name']) ?></a></h2>
        <?php if ($v['hostname'] !== '' || $v['model'] !== ''): ?>
        <p class="muted small"><?= h(implode(' · ', array_filter([$v['hostname'], $v['model']]))) ?></p>
        <?php endif; ?>
      </div>
      <span class="pill <?= $v['online'] ? 'pill-ok' : 'pill-off' ?>"><?= $v['online'] ? 'online' : 'offline' ?></span>
    </header>

    <p class="status status-<?= h($s['level']) ?>">
      <strong><?= h($s['label']) ?></strong> · <?= h(implode(' · ', $s['reasons'])) ?>
    </p>

    <?php if ($v['has_report']): ?>
    <dl class="facts">
      <dt>Zuletzt gemeldet</dt>
      <dd><?= h(fmt_datetime($v['last_seen'])) ?> <span class="muted">(<?= h(fmt_ago($v['last_seen'])) ?>)</span></dd>

      <dt>Öffentliche IP</dt>
      <dd><code><?= h($row['last_ip'] ?: '–') ?></code></dd>

      <dt>Netzwerk</dt>
      <dd>
        <?php if (!$v['networks']): ?>–<?php endif; ?>
        <?php foreach ($v['networks'] as $net): ?>
        <div><?= h($net['type']) ?> „<?= h($net['name']) ?>“<?php if ($net['ipv4'] !== ''): ?> · <code><?= h($net['ipv4']) ?></code><?php endif; ?><?php if (!$net['internet']): ?> <span class="muted">(ohne Internet)</span><?php endif; ?></div>
        <?php endforeach; ?>
      </dd>

      <dt>Windows</dt>
      <dd><?= h($v['os'] ?: '–') ?><?php if ($v['build'] !== ''): ?><br><span class="muted">Build <?= h($v['build']) ?></span><?php endif; ?></dd>

      <dt>Patch-Stand</dt>
      <dd>
        <?php if ($v['patch_month'] !== '' || $v['patch_kb'] !== ''): ?>
          <?= h(implode(' · ', array_filter([$v['patch_month'], $v['patch_kb']]))) ?>
          <?php if ($v['patch_installed'] !== ''): ?><br><span class="muted">installiert am <?= h(fmt_date($v['patch_installed'])) ?></span><?php endif; ?>
        <?php else: ?>
          <span class="muted">nicht erkannt</span>
        <?php endif; ?>
      </dd>

      <dt>Ausstehend</dt>
      <dd>
        <?php if (!$v['pending_known']): ?>
          <span class="muted">unbekannt</span>
        <?php else: ?>
          <?= $v['pending_count'] === 0 ? 'keine Updates' : h($v['pending_count'] . ($v['pending_count'] === 1 ? ' Update' : ' Updates')) ?>
          <br><span class="muted">geprüft <?= h(fmt_ago($v['pending_checked'])) ?></span>
        <?php endif; ?>
      </dd>

      <dt>Virenschutz</dt>
      <dd>
        <?php if (!$v['defender_known']): ?>
          <span class="muted">unbekannt</span>
        <?php elseif ($v['defender_mode'] !== '' && $v['defender_mode'] !== 'Normal'): ?>
          Defender: <?= h($v['defender_mode']) ?>
        <?php else: ?>
          <?= $v['defender_active'] ? 'Defender aktiv' : '<strong class="bad-text">ausgeschaltet</strong>' ?>
          <?php if ($v['signature_updated'] !== ''): ?><br><span class="muted">Definitionen vom <?= h(fmt_date($v['signature_updated'])) ?></span><?php endif; ?>
        <?php endif; ?>
      </dd>

      <dt>Neustart nötig</dt>
      <dd><?= $v['reboot_required'] ? '<strong class="warn">Ja</strong>' : 'Nein' ?></dd>
    </dl>

    <?php if ($v['pending_items']): ?>
    <details class="updates">
      <summary>Ausstehende Updates</summary>
      <ul>
        <?php foreach ($v['pending_items'] as $title): ?><li><?= h($title) ?></li><?php endforeach; ?>
      </ul>
    </details>
    <?php endif; ?>

    <?php if ($v['errors']): ?>
    <p class="note warn">Der Agent meldet Probleme: <?= h(implode(' · ', $v['errors'])) ?></p>
    <?php endif; ?>
    <?php endif; ?>

    <p class="muted small stand">
      <a href="device.php?id=<?= (int) $row['id'] ?>">Details und Online-Zeiten</a>
      <?php if ($v['agent_version'] !== ''): ?> · Agent <?= h($v['agent_version']) ?><?php endif; ?>
      <?php if ($v['agent_outdated']): ?> · <a href="admin.php#agent">neuere Version verfügbar</a><?php endif; ?>
    </p>
  </article>
<?php endforeach; ?>
</div>
<?php
page_footer();
