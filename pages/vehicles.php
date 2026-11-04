<?php
declare(strict_types=1);

// Aufruf als ?p=autos oder ?p=fahrraeder; alte Links auf ?p=vehicles landen bei den Autos
if (!isset(VEHICLE_GROUPS[$page])) redirect(url('autos'));
$group = $page;
if ($group === 'autos' && ($car = single_car()) && empty($_GET['list'])) redirect(url('vehicle', ['id' => $car['id']]));
[$title, $ic, $newType] = VEHICLE_GROUPS[$group];
$single = VEHICLE_TYPES[$newType];

$all = array_filter(vehicles(true), fn($v) => vehicle_group($v) === $group);
$active = array_filter($all, fn($v) => !$v['archived']);
$archived = array_filter($all, fn($v) => $v['archived']);
$addUrl = url('vehicle_edit', ['type' => $newType]);

page_header($title, ['nav' => $group, 'actions' => '<a class="btn btn-primary btn-sm" href="' . e($addUrl) . '">' . icon('plus') . '<span>' . e($single) . '</span></a>']);
echo area_nav($group, $group);
?>
<?php if (!$all): ?>
  <div class="card"><?= $group === 'fahrraeder'
      ? empty_state('bike', 'Noch keine Fahrräder', 'Lege dein Fahrrad oder E-Bike an – mit Kilometern, Verschleißteilen und Kosten.', 'Fahrrad anlegen', $addUrl)
      : empty_state('car', 'Noch kein Auto', 'Lege dein Auto, Motorrad oder deinen Anhänger an – mit Tanken, Fristen und Kosten.', 'Auto anlegen', $addUrl) ?></div>
<?php else: ?>
<div class="vcards">
  <?php foreach ($active as $v) echo vehicle_card($v); ?>
  <a class="vcard add" href="<?= e($addUrl) ?>"><span><?= icon('plus') ?><?= e($single) ?> hinzufügen</span></a>
</div>
<?php endif; ?>

<?php if ($archived): ?>
  <h3 class="section-title">Archiviert</h3>
  <div class="card flush"><ul class="list">
    <?php foreach ($archived as $v): ?>
      <li><a class="list-item" href="<?= e(url('vehicle', ['id' => $v['id']])) ?>"><?= vehicle_avatar($v, 'sm') ?><span class="li-main"><span class="li-title"><?= e($v['name']) ?></span><span class="li-sub"><?= e(VEHICLE_TYPES[$v['type']] ?? '') ?></span></span><?= icon('chevron-right', 'li-chev') ?></a></li>
    <?php endforeach; ?>
  </ul></div>
<?php endif;
page_footer();
