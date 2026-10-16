<?php
declare(strict_types=1);

$u = current_user();
$vs = vehicles();
$h = (int)date('G');
$greet = $h < 11 ? 'Guten Morgen' : ($h < 18 ? 'Hallo' : 'Guten Abend');
$name = $u['name'] ? explode(' ', $u['name'])[0] : '';

page_header('Übersicht', ['nav' => 'dashboard', 'heading' => $greet . ($name ? ', ' . $name : '')]);

if (!$vs) {
    echo '<div class="card">' . empty_state('car', 'Deine Garage ist noch leer', 'Lege dein Auto und deine Fahrräder an – danach siehst du hier Kosten, Verbrauch und anstehende Termine.', 'Fahrzeug anlegen', url('vehicle_edit')) . '</div>';
    page_footer();
    return;
}

$ids = array_column($vs, 'id');
$year = date('Y');
$from12 = date('Y-m-01', strtotime('-11 months'));
$entries12 = cost_entries($ids, $from12, today());
$entriesYear = cost_entries($ids, "$year-01-01", "$year-12-31");
$sumYear = array_sum(array_column($entriesYear, 'amount'));
$sum12 = array_sum(array_column($entries12, 'amount'));
$lastYearSameTime = array_sum(array_column(cost_entries($ids, ($year - 1) . '-01-01', date('Y-m-d', strtotime('-1 year'))), 'amount'));

$kmCars = 0; $costCars = 0;
foreach ($vs as $v) {
    if (!is_motor($v)) continue;
    $km = km_driven((int)$v['id'], $from12, today());
    if ($km) {
        $kmCars += $km;
        $costCars += array_sum(array_column(array_filter($entries12, fn($e) => $e['vehicle_id'] == $v['id']), 'amount'));
    }
}
$due = due_items();
$urgent = array_filter($due, fn($d) => $d['status'] !== 'ok');

// Monatsreihe, gestapelt nach Kategorie
$months = [];
for ($i = 11; $i >= 0; $i--) $months[date('Y-m', strtotime("first day of -$i months"))] = [];
foreach ($entries12 as $e) {
    $m = substr($e['date'], 0, 7);
    if (isset($months[$m])) $months[$m][$e['category']] = ($months[$m][$e['category']] ?? 0) + (float)$e['amount'];
}
$series = [];
foreach ($months as $m => $c) $series[fmt_month_short($m)] = $c;
$colors = array_map(fn($c) => $c[1], COST_CATEGORIES);
$usedCats = array_unique(array_column($entries12, 'category'));

$diff = $lastYearSameTime > 0 ? ($sumYear - $lastYearSameTime) / $lastYearSameTime * 100 : null;
?>
<div class="kpis">
  <?= kpi('Kosten ' . $year, fmt_money($sumYear, false), $diff !== null ? ($diff >= 0 ? '+' : '−') . fmt_num(abs($diff)) . ' % ggü. Vorjahr' : 'seit 1. Januar', 'euro', 'accent') ?>
  <?= kpi('Ø pro Monat', fmt_money($sum12 / 12, false), 'letzte 12 Monate', 'calendar') ?>
  <?= kpi('Kosten pro km', $kmCars ? fmt_num($costCars / $kmCars * 100, 1) . '<small>ct</small>' : '–', $kmCars ? fmt_km($kmCars) . ' in 12 Monaten' : 'Auto · noch zu wenig Daten', 'route') ?>
  <?= kpi('Anstehend', (string)count($urgent), count($urgent) ? 'Fristen & Verschleiß' : 'alles im grünen Bereich', 'bell') ?>
</div>

<div class="grid grid-main mt">
  <div>
    <div class="card">
      <div class="card-head"><h2>Kosten je Monat</h2><a class="small" href="<?= e(url('costs')) ?>">Details</a></div>
      <?= $entries12 ? bar_chart($series, ['colors' => $colors, 'height' => 190]) : '<p class="muted">Noch keine Kosten erfasst.</p>' ?>
      <?php if ($usedCats): ?>
        <div class="legend"><?php foreach ($usedCats as $c): ?><span><i class="dot-c" style="background:<?= e(cat_color($c)) ?>"></i><?= e(cat_label($c)) ?></span><?php endforeach; ?></div>
      <?php endif; ?>
    </div>

    <h3 class="section-title">Fahrzeuge</h3>
    <div class="vcards"><?php foreach ($vs as $v) echo vehicle_card($v); ?></div>
  </div>

  <div>
    <div class="card flush">
      <div class="card-head"><h2>Als Nächstes</h2><a class="small" href="<?= e(url('reminders')) ?>">Alle</a></div>
      <?php if (!$due): ?>
        <div style="padding:0 18px 18px" class="muted small">Keine offenen Fristen. Lege z. B. HU, Inspektion oder Reifenwechsel an.</div>
      <?php else: ?>
      <ul class="list">
        <?php foreach (array_slice($due, 0, 7) as $d): ?>
          <li><a class="list-item" href="<?= e($d['type'] === 'reminder' ? url('reminder_edit', ['id' => $d['r']['id']]) : url('part_edit', ['id' => $d['r']['id']])) ?>">
            <span class="li-ic <?= $d['status'] === 'overdue' ? 'c-red' : ($d['status'] === 'soon' ? 'c-amber' : '') ?>"><?= icon($d['icon']) ?></span>
            <span class="li-main"><span class="li-title"><?= e($d['title']) ?></span><span class="li-sub"><?= e($d['v']['name']) ?> · <?= e($d['text']) ?></span></span>
            <?= $d['status'] !== 'ok' ? status_pill($d['status']) : '' ?>
          </a></li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>

    <div class="card flush mt">
      <div class="card-head"><h2>Zuletzt erfasst</h2></div>
      <?php $recent = array_slice(cost_entries($ids), 0, 6); ?>
      <?php if (!$recent): ?><div style="padding:0 18px 18px" class="muted small">Noch keine Einträge.</div><?php else: ?>
      <ul class="list"><?php foreach ($recent as $e) echo entry_row($e, $vs); ?></ul>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php
page_footer();

