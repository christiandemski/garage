<?php
declare(strict_types=1);

$cats = stock_categories();
$cat = isset($cats[$_GET['cat'] ?? '']) ? $_GET['cat'] : '';
$all = rows('SELECT * FROM stock_items WHERE user_id = ? ORDER BY category, name', [uid()]);
$items = array_filter($all, fn($i) => !$cat || $i['category'] === $cat);
$inStock = array_filter($items, fn($i) => (int)$i['quantity'] > 0);
$empty = array_filter($items, fn($i) => (int)$i['quantity'] <= 0);

$bs = bikes(true);
$bikeIds = array_map('intval', array_column($bs, 'id'));
$names = array_column($bs, 'name', 'id');
$removed = $bikeIds ? rows('SELECT * FROM parts WHERE vehicle_id IN (' . implode(',', $bikeIds) . ') AND removed_date IS NOT NULL ORDER BY removed_date DESC') : [];

$count = array_sum(array_map(fn($i) => max(0, (int)$i['quantity']), $all));
$value = array_sum(array_map(fn($i) => max(0, (int)$i['quantity']) * (float)$i['price'], $all));
$usedCats = array_unique(array_column($all, 'category'));
$addUrl = url('stock_edit', $cat ? ['cat' => $cat] : []);

page_header('Lager', ['nav' => 'fahrraeder', 'heading' => 'Fahrräder',
    'actions' => '<a class="btn btn-primary btn-sm" href="' . e($addUrl) . '">' . icon('plus') . '<span>Artikel</span></a>']);
echo bike_area_nav('bike_stock');

if (!$all && !$removed) {
    echo '<div class="card">' . empty_state('box', 'Das Lager ist leer', 'Erfasse Ersatzteile, Reifen, Schläuche, Pflegemittel oder Werkzeug – mit Menge und Lagerort. Teile baust du später mit einem Tipp an ein Rad.', 'Artikel erfassen', $addUrl) . '</div>';
    page_footer();
    return;
}
?>
<div class="kpis k3 mb">
  <?= kpi('Artikel', (string)count(array_filter($all, fn($i) => (int)$i['quantity'] > 0)), 'verschiedene', 'box') ?>
  <?= kpi('Stück auf Lager', fmt_num($count), null, 'layers') ?>
  <?= kpi('Lagerwert', fmt_money($value), 'nach Stückpreis', 'euro') ?>
</div>

<?php if (count($usedCats) > 1): ?>
<div class="chips mb">
  <a class="chip<?= !$cat ? ' on' : '' ?>" href="<?= e(url('bike_stock')) ?>">Alle</a>
  <?php foreach ($cats as $k => $l): if (!in_array($k, $usedCats, true)) continue; ?>
    <a class="chip<?= $cat === $k ? ' on' : '' ?>" href="<?= e(url('bike_stock', ['cat' => $k])) ?>"><?= e($l) ?></a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($inStock):
    $groups = [];
    foreach ($inStock as $i) $groups[$i['category']][] = $i; ?>
  <div class="card flush"><ul class="list">
    <?php foreach ($groups as $g => $list): ?>
      <li class="list-group-label"><span><?= e($cats[$g] ?? 'Sonstiges') ?></span><span><?= array_sum(array_map(fn($i) => (int)$i['quantity'], $list)) ?> Stück</span></li>
      <?php foreach ($list as $i) echo stock_row($i); ?>
    <?php endforeach; ?>
  </ul></div>
<?php elseif ($all): ?>
  <div class="card card-quiet"><p class="muted small m0">In dieser Auswahl ist nichts auf Lager.</p></div>
<?php endif; ?>

<?php if ($removed && !$cat): ?>
  <h3 class="section-title">Ausgebaut an den Rädern</h3>
  <div class="card flush"><ul class="list">
    <?php foreach ($removed as $p): $w = part_wear($p, null); ?>
      <li><a class="list-item" href="<?= e(url('part_edit', ['id' => $p['id']])) ?>"><span class="li-ic c-slate"><?= icon(stock_icon($p['category'])) ?></span>
        <span class="li-main"><span class="li-title"><?= e($p['name']) ?></span><span class="li-sub"><?= e(implode(' · ', array_filter([$names[$p['vehicle_id']] ?? '', 'ausgebaut ' . fmt_date($p['removed_date']), $p['storage'] ? 'Lager: ' . $p['storage'] : null]))) ?></span></span>
        <?php if ($w['used'] !== null): ?><span class="li-end"><span class="li-sub"><?= fmt_km($w['used']) ?> gelaufen</span></span><?php endif; ?></a></li>
    <?php endforeach; ?>
  </ul></div>
<?php endif; ?>

<?php if ($empty): ?>
  <details class="more mt">
    <summary><?= icon('chevron-down') ?> Aufgebraucht (<?= count($empty) ?>)</summary>
    <div class="card flush mt"><ul class="list"><?php foreach ($empty as $i) echo stock_row($i); ?></ul></div>
  </details>
<?php endif;
page_footer();
