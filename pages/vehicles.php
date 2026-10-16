<?php
declare(strict_types=1);

$all = vehicles(true);
$active = array_filter($all, fn($v) => !$v['archived']);
$archived = array_filter($all, fn($v) => $v['archived']);

page_header('Fahrzeuge', ['nav' => 'vehicles', 'actions' => '<a class="btn btn-primary btn-sm" href="' . e(url('vehicle_edit')) . '">' . icon('plus') . '<span>Fahrzeug</span></a>']);
?>
<div class="vcards">
  <?php foreach ($active as $v) echo vehicle_card($v); ?>
  <a class="vcard add" href="<?= e(url('vehicle_edit')) ?>"><span><?= icon('plus') ?>Fahrzeug hinzufügen</span></a>
</div>

<?php if ($archived): ?>
  <h3 class="section-title">Archiviert</h3>
  <div class="card flush"><ul class="list">
    <?php foreach ($archived as $v): ?>
      <li><a class="list-item" href="<?= e(url('vehicle', ['id' => $v['id']])) ?>"><?= vehicle_avatar($v, 'sm') ?><span class="li-main"><span class="li-title"><?= e($v['name']) ?></span><span class="li-sub"><?= e(VEHICLE_TYPES[$v['type']] ?? '') ?></span></span><?= icon('chevron-right', 'li-chev') ?></a></li>
    <?php endforeach; ?>
  </ul></div>
<?php endif;
page_footer();
