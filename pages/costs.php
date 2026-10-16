<?php
declare(strict_types=1);

$vs = vehicles(true);
$ids = array_column($vs, 'id');
$vf = (int)($_GET['v'] ?? 0);
if ($vf && !in_array($vf, array_map('intval', $ids), true)) $vf = 0;
$period = (string)($_GET['y'] ?? date('Y'));
$cat = array_key_exists($_GET['cat'] ?? '', COST_CATEGORIES) ? $_GET['cat'] : '';

if ($period === '12m') { $from = date('Y-m-01', strtotime('-11 months')); $to = today(); $label = 'Letzte 12 Monate'; }
elseif ($period === 'all') { $from = null; $to = null; $label = 'Gesamt'; }
else { $period = preg_match('/^\d{4}$/', $period) ? $period : date('Y'); $from = "$period-01-01"; $to = "$period-12-31"; $label = $period; }

$sel = $vf ? [$vf] : $ids;
$entries = cost_entries($sel, $from, $to, $cat ?: null);
$total = array_sum(array_column($entries, 'amount'));
$byCat = sum_by($entries, fn($e) => $e['category']);
$byVeh = sum_by($entries, fn($e) => $e['vehicle_id']);
$names = array_column($vs, 'name', 'id');

// Jahre für Filter
$years = [];
$minDate = val('SELECT MIN(d) FROM (SELECT MIN(date) d FROM costs c JOIN vehicles v ON v.id=c.vehicle_id WHERE v.user_id = ? UNION ALL SELECT MIN(date) FROM fuelings f JOIN vehicles v ON v.id=f.vehicle_id WHERE v.user_id = ?) x', [uid(), uid()]);
for ($y = (int)date('Y'); $y >= (int)substr((string)($minDate ?: date('Y')), 0, 4); $y--) $years[(string)$y] = (string)$y;

// Zeitreihe
$series = [];
if ($period === 'all') {
    foreach ($entries as $e) { $k = substr($e['date'], 0, 4); $series[$k][$e['category']] = ($series[$k][$e['category']] ?? 0) + $e['amount']; }
    ksort($series);
} else {
    $start = $from;
    for ($i = 0; $i < 12; $i++) { $m = date('Y-m', strtotime("$start +$i months")); $series[$m] = []; }
    foreach ($entries as $e) { $m = substr($e['date'], 0, 7); if (isset($series[$m])) $series[$m][$e['category']] = ($series[$m][$e['category']] ?? 0) + $e['amount']; }
    $tmp = [];
    foreach ($series as $m => $c) $tmp[fmt_month_short($m)] = $c;
    $series = $tmp;
}
$monthsCount = $period === 'all' ? max(1, count($series) * 12) : ($period === date('Y') ? (int)date('n') : 12);

// km im Zeitraum
$kmSum = 0;
foreach ($sel as $vid) {
    $vrow = null; foreach ($vs as $x) if ($x['id'] == $vid) $vrow = $x;
    if ($vrow && is_motor($vrow)) $kmSum += (float)km_driven((int)$vid, $from ?? '1970-01-01', min($to ?? today(), today()));
}
$motorCost = array_sum(array_map(fn($e) => (float)$e['amount'], array_filter($entries, function ($e) use ($vs) { foreach ($vs as $x) if ($x['id'] == $e['vehicle_id']) return is_motor($x); return false; })));

$q = fn(array $p) => url('costs', array_filter(['v' => $vf ?: null, 'y' => $period, 'cat' => $cat ?: null] + $p, fn($x) => $x !== null && $x !== ''));

page_header('Kosten', ['nav' => 'costs', 'actions' => '<a class="icon-btn" href="' . e(url('export', ['what' => 'costs', 'v' => $vf ?: null, 'y' => $period])) . '" aria-label="Als CSV exportieren" title="Als CSV exportieren">' . icon('download') . '</a>']);
?>
<form class="filters" method="get">
  <input type="hidden" name="p" value="costs">
  <?= f_select('v', 'Fahrzeug', array_column($vs, 'name', 'id'), $vf ?: '', ['empty' => 'Alle Fahrzeuge']) ?>
  <?= f_select('y', 'Zeitraum', ['12m' => 'Letzte 12 Monate'] + $years + ['all' => 'Gesamt'], $period) ?>
  <?= f_select('cat', 'Kategorie', array_map(fn($c) => $c[0], COST_CATEGORIES), $cat, ['empty' => 'Alle Kategorien']) ?>
  <noscript><button class="btn">Anwenden</button></noscript>
</form>
<script>document.querySelectorAll('.filters select').forEach(s=>s.addEventListener('change',()=>s.form.submit()));</script>

<div class="kpis">
  <?= kpi('Summe · ' . $label, fmt_money($total, false), count($entries) . ' Einträge', 'euro', 'accent') ?>
  <?= kpi('Ø pro Monat', fmt_money($total / $monthsCount, false), $period === date('Y') ? 'bis heute' : '', 'calendar') ?>
  <?= kpi('Kraftstoff', fmt_money($byCat['kraftstoff'] ?? 0, false), $total ? fmt_num(($byCat['kraftstoff'] ?? 0) / $total * 100) . ' % der Kosten' : '', 'fuel') ?>
  <?= kpi('Kosten pro km', $kmSum > 0 ? fmt_num($motorCost / $kmSum * 100, 1) . '<small>ct</small>' : '–', $kmSum ? fmt_km(round($kmSum)) . ' · nur Auto' : 'Auto · zu wenig km-Daten', 'route') ?>
</div>

<?php if (!$entries): ?>
  <div class="card mt"><?= empty_state('receipt', 'Keine Kosten im Zeitraum', 'Wähle einen anderen Zeitraum oder erfasse neue Kosten über das Plus.') ?></div>
<?php else: ?>
<div class="grid grid-main mt">
  <div>
    <div class="card">
      <div class="card-head"><h2>Verlauf</h2><span class="muted small"><?= e($label) ?></span></div>
      <?= bar_chart($series, ['colors' => array_map(fn($c) => $c[1], COST_CATEGORIES), 'height' => 200]) ?>
    </div>
    <div class="card flush mt">
      <div class="card-head"><h2>Einträge</h2><span class="muted small"><?= count($entries) ?></span></div>
      <ul class="list">
        <?php $lastM = null; foreach (array_slice($entries, 0, 400) as $e):
          $m = substr($e['date'], 0, 7);
          if ($m !== $lastM) { $mSum = array_sum(array_column(array_filter($entries, fn($x) => str_starts_with($x['date'], $m)), 'amount')); echo '<li class="list-group-label"><span>' . e(fmt_month($m, true)) . '</span><span>' . fmt_money($mSum) . '</span></li>'; $lastM = $m; }
          echo entry_row($e, $vs);
        endforeach; ?>
      </ul>
    </div>
  </div>
  <div>
    <div class="card">
      <div class="card-head"><h2>Nach Kategorie</h2></div>
      <?= donut($byCat, fmt_money($total, false) . '<small>' . e($label) . '</small>') ?>
      <?= category_bars($byCat) ?>
    </div>
    <?php if (!$vf && count($byVeh) > 1): ?>
    <div class="card mt">
      <div class="card-head"><h2>Nach Fahrzeug</h2></div>
      <?php arsort($byVeh); foreach ($byVeh as $vid => $s): ?>
        <a class="stat-line" href="<?= e($q(['v' => $vid])) ?>" style="color:inherit;text-decoration:none"><span><?= e($names[$vid] ?? '?') ?></span><b><?= fmt_money($s) ?></b></a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif;
page_footer();
