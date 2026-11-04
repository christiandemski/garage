<?php
declare(strict_types=1);

// Dokumente eines Bereichs: /autos/dokumente bzw. /fahrraeder/dokumente
$group = $page === 'bike_docs' ? 'fahrraeder' : 'autos';
$bike = $group === 'fahrraeder';
if (!$bike && ($car = single_car())) redirect(url('vehicle', ['id' => $car['id'], 'tab' => 'docs']));
$vs = array_values(array_filter(vehicles(true), fn($v) => vehicle_group($v) === $group));
$ids = array_map('intval', array_column($vs, 'id'));
$names = array_column($vs, 'name', 'id');
$kinds = $bike ? array_intersect_key(DOC_KINDS, array_flip(BIKE_DOC_KINDS)) : DOC_KINDS;
$kind = isset(DOC_KINDS[$_GET['kind'] ?? '']) ? $_GET['kind'] : '';
$vf = (string)($_GET['v'] ?? '');               // '' = alle, 'allg' = ohne Fahrzeug, sonst Fahrzeug-ID
if ($vf !== '' && $vf !== 'allg' && !in_array((int)$vf, $ids, true)) $vf = '';

$where = ['(d.vehicle_id IS NULL AND d.user_id = ? AND d.area = ?)'];
$p = [uid(), $group];
if ($ids) $where[] = 'd.vehicle_id IN (' . implode(',', $ids) . ')';
$sql = 'SELECT d.* FROM documents d WHERE (' . implode(' OR ', $where) . ')';
if ($vf === 'allg') $sql .= ' AND d.vehicle_id IS NULL';
elseif ($vf !== '') { $sql .= ' AND d.vehicle_id = ?'; $p[] = (int)$vf; }
$all = rows($sql . ' ORDER BY COALESCE(d.doc_date, d.created_at) DESC', $p);
$docs = $kind ? array_filter($all, fn($d) => $d['kind'] === $kind) : $all;

// Garantien: laufend oder in den letzten 30 Tagen abgelaufen
$soon = date('Y-m-d', strtotime('-30 days'));
$warranties = array_filter($all, fn($d) => $d['valid_until'] && $d['valid_until'] >= $soon);
usort($warranties, fn($a, $b) => strcmp($a['valid_until'], $b['valid_until']));
$addUrl = url('doc_edit', ['area' => $group] + ($vf !== '' && $vf !== 'allg' ? ['v' => $vf] : []) + ($kind ? ['kind' => $kind] : []));
$general = $bike ? 'Allgemein (ohne Rad)' : 'Allgemein (ohne Fahrzeug)';

page_header('Dokumente', ['nav' => $group, 'heading' => VEHICLE_GROUPS[$group][0],
    'actions' => '<a class="btn btn-primary btn-sm" href="' . e($addUrl) . '">' . icon('upload') . '<span>Hochladen</span></a>']);
echo area_nav($group, $page);
?>
<?php if ($warranties): ?>
  <div class="card flush mb">
    <div class="card-head"><h2>Garantien</h2><span class="muted small"><?= count(array_filter($warranties, fn($d) => $d['valid_until'] >= today())) ?> laufend</span></div>
    <ul class="list">
      <?php foreach ($warranties as $d): $left = (int)floor((strtotime($d['valid_until']) - strtotime(today())) / 86400);
          $tone = $left < 0 ? 'c-slate' : ($left <= 90 ? 'c-amber' : 'c-green'); ?>
        <li><a class="list-item" href="<?= e(url('doc_edit', ['id' => $d['id'], 'back' => url($page)])) ?>"><span class="li-ic <?= $tone ?>"><?= icon('shield') ?></span>
          <span class="li-main"><span class="li-title"><?= e($d['title']) ?></span><span class="li-sub"><?= e(($d['vehicle_id'] ? ($names[$d['vehicle_id']] ?? '') : 'Allgemein') . ' · bis ' . fmt_date($d['valid_until'])) ?></span></span>
          <span class="li-end"><?= $left < 0 ? status_pill('done', 'abgelaufen') : status_pill($left <= 90 ? 'soon' : 'ok', rel_days($d['valid_until'])) ?></span></a></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<form class="filters" method="get">
  <?= f_select('v', $bike ? 'Fahrrad' : 'Fahrzeug', ['allg' => $general] + $names, $vf, ['empty' => 'Alle']) ?>
  <?= f_select('kind', 'Art', $kinds, $kind, ['empty' => 'Alle Arten']) ?>
  <noscript><button class="btn">Anwenden</button></noscript>
</form>

<?= documents_list(array_values($docs), $vs, true, $bike
    ? 'Kaufverträge, Rechnungen, Garantiebelege und Anleitungen deiner Räder – auch für Teile, Helm oder Werkzeug ohne festes Rad.'
    : 'Fahrzeugschein, Rechnungen, HU-Berichte und Verträge – als PDF oder Foto.') ?>
<?php page_footer();
