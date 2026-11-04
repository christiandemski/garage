<?php
declare(strict_types=1);

// Fristen im Autobereich; Fahrräder haben ihre Fristen im Serviceheft
$vs = array_values(array_filter(vehicles(), fn($v) => vehicle_group($v) === 'autos'));
$kmBy = [];
foreach ($vs as $v) $kmBy[$v['id']] = current_km($v);
$ids = array_map('intval', array_column($vs, 'id'));
$list = $ids ? rows('SELECT r.*, v.name AS vname FROM reminders r JOIN vehicles v ON v.id = r.vehicle_id WHERE r.vehicle_id IN (' . implode(',', $ids) . ') ORDER BY r.done_at IS NOT NULL, r.done_at DESC') : [];
$parts = array_filter(due_items(), fn($d) => $d['type'] === 'part' && vehicle_group($d['v']) === 'autos');

page_header('Fristen', ['nav' => 'autos', 'heading' => 'Autos',
    'actions' => $vs ? '<button class="btn btn-primary btn-sm" data-sheet="newrem">' . icon('plus') . '<span>Frist</span></button>' : '']);
echo area_nav('autos', 'reminders');
if (!$vs) { echo '<div class="card">' . empty_state('car', 'Noch keine Fahrzeuge', 'Lege zuerst ein Auto an – danach siehst du hier HU, Inspektion und weitere Fristen.', 'Auto anlegen', url('vehicle_edit', ['type' => 'auto'])) . '</div>'; page_footer(); return; }
?>
<p class="muted small mb">HU, Inspektion, Ölwechsel & Co. – sortiert nach Dringlichkeit</p>
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
