<?php
declare(strict_types=1);

$vs = vehicles();
$kmBy = [];
foreach ($vs as $v) $kmBy[$v['id']] = current_km($v);
$ids = array_map('intval', array_column($vs, 'id'));
$list = $ids ? rows('SELECT r.*, v.name AS vname FROM reminders r JOIN vehicles v ON v.id = r.vehicle_id WHERE r.vehicle_id IN (' . implode(',', $ids) . ') ORDER BY r.done_at IS NOT NULL, r.done_at DESC') : [];
$parts = array_filter(due_items(), fn($d) => $d['type'] === 'part');

page_header('Fristen', ['nav' => 'reminders', 'back' => url('more')]);
if (!$vs) { echo '<div class="card">' . empty_state('car', 'Noch keine Fahrzeuge', 'Lege zuerst ein Fahrzeug an.', 'Fahrzeug anlegen', url('vehicle_edit')) . '</div>'; page_footer(); return; }
?>
<div class="row-between mb">
  <div class="muted small">Alle Termine deiner Fahrzeuge – sortiert nach Dringlichkeit</div>
  <button class="btn btn-primary btn-sm" data-sheet="newrem"><?= icon('plus') ?> Frist</button>
</div>
<?= reminders_list($list, $kmBy, true) ?>

<?php if ($parts): ?>
  <h3 class="section-title">Verschleißteile</h3>
  <div class="card flush"><ul class="list">
    <?php foreach ($parts as $d): ?>
      <li><a class="list-item" href="<?= e(url('part_edit', ['id' => $d['r']['id']])) ?>"><span class="li-ic <?= $d['status'] === 'overdue' ? 'c-red' : 'c-amber' ?>"><?= icon('gauge') ?></span><span class="li-main"><span class="li-title"><?= e($d['title']) ?></span><span class="li-sub"><?= e($d['v']['name'] . ' · ' . $d['text']) ?></span></span><?= status_pill($d['status']) ?></a></li>
    <?php endforeach; ?>
  </ul></div>
<?php endif; ?>

<div class="sheet" id="sheet-newrem" hidden><div class="sheet-backdrop" data-close></div><div class="sheet-panel">
  <div class="sheet-grip"></div>
  <div class="sheet-head"><h2>Frist für …</h2><button class="icon-btn" data-close><?= icon('x') ?></button></div>
  <div class="card flush" style="box-shadow:none;background:var(--surface-2)"><ul class="list">
  <?php foreach ($vs as $v): ?>
    <li><a class="list-item" href="<?= e(url('reminder_edit', ['v' => $v['id']])) ?>"><?= vehicle_avatar($v, 'sm') ?><span class="li-main li-title"><?= e($v['name']) ?></span><?= icon('chevron-right', 'li-chev') ?></a></li>
  <?php endforeach; ?>
  </ul></div>
</div></div>
<?php page_footer();
