<?php
declare(strict_types=1);

// Übersicht: eine Karte pro Gerät mit dem Stand der letzten Meldung.

require __DIR__ . '/../app/bootstrap.php';
require_login();

$devices = all_devices();
page_header('Übersicht', 'index');
?>
<h1>Übersicht</h1>

<?php if (!$devices): ?>
<section class="card narrow">
  <p>Noch keine Geräte angelegt.</p>
  <p><a class="button" href="admin.php">Gerät anlegen</a></p>
</section>
<?php endif; ?>

<div class="cards">
<?php foreach ($devices as $row): $v = device_view($row); ?>
  <article class="card device">
    <header class="device-head">
      <div>
        <h2><?= h($row['name']) ?></h2>
        <?php if ($v['hostname'] !== '' || $v['model'] !== ''): ?>
        <p class="muted small"><?= h(implode(' · ', array_filter([$v['hostname'], $v['model']]))) ?></p>
        <?php endif; ?>
      </div>
      <span class="pill <?= $v['online'] ? 'pill-ok' : 'pill-off' ?>"><?= $v['online'] ? 'online' : 'offline' ?></span>
    </header>

    <?php if (!$v['has_report']): ?>
    <p class="muted">Noch keine Meldung. Ist der Agent auf dem Gerät installiert?</p>
    <?php else: ?>
    <dl class="facts">
      <dt>Zuletzt gemeldet</dt>
      <dd><?= h(fmt_datetime($row['last_seen_at'])) ?> <span class="muted">(<?= h(fmt_ago($row['last_seen_at'])) ?>)</span></dd>

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

      <dt>Letzte Update-Suche</dt>
      <dd><?= h(fmt_datetime($v['last_search'])) ?><?php if ($v['last_search'] !== ''): ?> <span class="muted">(<?= h(fmt_ago($v['last_search'])) ?>)</span><?php endif; ?></dd>

      <dt>Neustart nötig</dt>
      <dd><?= $v['reboot_required'] ? '<strong class="warn">Ja</strong>' : 'Nein' ?></dd>

      <?php if ($v['user'] !== ''): ?>
      <dt>Angemeldet</dt>
      <dd><?= h($v['user']) ?></dd>
      <?php endif; ?>
    </dl>

    <?php if ($v['recent']): ?>
    <details class="updates">
      <summary>Zuletzt installierte Updates</summary>
      <ul>
        <?php foreach (array_slice($v['recent'], 0, 8) as $u): ?>
        <li><span class="muted"><?= h(fmt_date($u['date'])) ?></span> <?= h($u['title']) ?></li>
        <?php endforeach; ?>
      </ul>
    </details>
    <?php endif; ?>

    <?php if ($v['errors']): ?>
    <p class="note warn">Der Agent meldet Probleme: <?= h(implode(' · ', $v['errors'])) ?></p>
    <?php endif; ?>
    <p class="muted small stand">Stand der Meldung vom <?= h(fmt_datetime($row['last_seen_at'])) ?> · Agent <?= h($v['agent_version'] ?: '?') ?></p>
    <?php endif; ?>
  </article>
<?php endforeach; ?>
</div>
<?php
page_footer();
