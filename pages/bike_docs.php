<?php
declare(strict_types=1);

$bs = bikes(true);
$ids = array_map('intval', array_column($bs, 'id'));
$names = array_column($bs, 'name', 'id');
$kinds = array_intersect_key(DOC_KINDS, array_flip(BIKE_DOC_KINDS));
$kind = isset(DOC_KINDS[$_GET['kind'] ?? '']) ? $_GET['kind'] : '';
$vf = (string)($_GET['v'] ?? '');               // '' = alle, 'allg' = ohne Rad, sonst Rad-ID
if ($vf !== '' && $vf !== 'allg' && !in_array((int)$vf, $ids, true)) $vf = '';

$where = ["(d.vehicle_id IS NULL AND d.user_id = ? AND d.area = 'fahrraeder')"];
$p = [uid()];
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
$addUrl = url('doc_edit', ['area' => 'fahrraeder'] + ($vf !== '' && $vf !== 'allg' ? ['v' => $vf] : []) + ($kind ? ['kind' => $kind] : []));

page_header('Dokumente', ['nav' => 'fahrraeder', 'heading' => 'Fahrräder',
    'actions' => '<a class="btn btn-primary btn-sm" href="' . e($addUrl) . '">' . icon('upload') . '<span>Hochladen</span></a>']);
echo bike_area_nav('bike_docs');
?>
<?php if ($warranties): ?>
  <div class="card flush mb">
    <div class="card-head"><h2>Garantien</h2><span class="muted small"><?= count(array_filter($warranties, fn($d) => $d['valid_until'] >= today())) ?> laufend</span></div>
    <ul class="list">
      <?php foreach ($warranties as $d): $left = (int)floor((strtotime($d['valid_until']) - strtotime(today())) / 86400);
          $tone = $left < 0 ? 'c-slate' : ($left <= 90 ? 'c-amber' : 'c-green'); ?>
        <li><a class="list-item" href="<?= e(url('doc_edit', ['id' => $d['id'], 'back' => url('bike_docs')])) ?>"><span class="li-ic <?= $tone ?>"><?= icon('shield') ?></span>
          <span class="li-main"><span class="li-title"><?= e($d['title']) ?></span><span class="li-sub"><?= e(($d['vehicle_id'] ? ($names[$d['vehicle_id']] ?? '') : 'Allgemein') . ' · bis ' . fmt_date($d['valid_until'])) ?></span></span>
          <span class="li-end"><?= $left < 0 ? status_pill('done', 'abgelaufen') : status_pill($left <= 90 ? 'soon' : 'ok', rel_days($d['valid_until'])) ?></span></a></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<form class="filters" method="get">
  <input type="hidden" name="p" value="bike_docs">
  <?= f_select('v', 'Fahrrad', ['allg' => 'Allgemein (ohne Rad)'] + array_column($bs, 'name', 'id'), $vf, ['empty' => 'Alle']) ?>
  <?= f_select('kind', 'Art', $kinds, $kind, ['empty' => 'Alle Arten']) ?>
  <noscript><button class="btn">Anwenden</button></noscript>
</form>

<?= documents_list(array_values($docs), $bs, true, 'Kaufverträge, Rechnungen, Garantiebelege und Anleitungen deiner Räder – auch für Teile, Helm oder Werkzeug ohne festes Rad.') ?>
<?php page_footer();
