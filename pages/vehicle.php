<?php
declare(strict_types=1);

$v = vehicle_or_404($_GET['id'] ?? 0);
$vid = (int)$v['id'];
$GLOBALS['CURRENT_VEHICLE'] = $vid;
$motor = is_motor($v);
$km = current_km($v);
$tab = $_GET['tab'] ?? 'overview';
$self = fn(array $p = []) => url('vehicle', ['id' => $vid] + $p);

$counts = [
    'fuel' => (int)val('SELECT COUNT(*) FROM fuelings WHERE vehicle_id = ?', [$vid]),
    'costs' => (int)val('SELECT COUNT(*) FROM costs WHERE vehicle_id = ?', [$vid]),
    'km' => (int)val('SELECT COUNT(*) FROM odometer_log WHERE vehicle_id = ?', [$vid]),
    'reminders' => (int)val('SELECT COUNT(*) FROM reminders WHERE vehicle_id = ? AND done_at IS NULL', [$vid]),
    'parts' => (int)val('SELECT COUNT(*) FROM parts WHERE vehicle_id = ? AND removed_date IS NULL', [$vid]),
    'docs' => (int)val('SELECT COUNT(*) FROM documents WHERE vehicle_id = ?', [$vid]),
];
$tabs = ['overview' => ['Übersicht', 'grid']];
if ($motor) $tabs['fuel'] = [is_electric($v) ? 'Laden' : 'Tanken', 'fuel'];
$tabs += [
    'costs' => ['Kosten', 'receipt'],
    'reminders' => ['Fristen', 'bell'],
    'parts' => [$motor ? 'Reifen & Teile' : 'Verschleiß', 'gauge'],
    'km' => ['Kilometer', 'route'],
    'docs' => ['Dokumente', 'files'],
    'data' => ['Daten', 'info'],
];
if (!isset($tabs[$tab])) $tab = 'overview';

page_header($v['name'], ['nav' => 'vehicles', 'back' => url('vehicles'), 'heading' => $v['name'],
    'actions' => '<a class="icon-btn" href="' . e(url('vehicle_edit', ['id' => $vid])) . '" aria-label="Bearbeiten">' . icon('edit') . '</a>']);
?>
<div class="vhero" style="--vc:<?= e($v['color'] ?: '#0f766e') ?>">
  <?php if ($v['photo']): ?><img src="<?= e(file_url($v['photo'])) ?>" alt=""><?php else: ?><?= icon(vehicle_icon($v), 'vhero-bg-ic') ?><?php endif; ?>
  <div class="vhero-body">
    <div>
      <div class="sub"><?= e(trim(implode(' · ', array_filter([trim(($v['make'] ?? '') . ' ' . ($v['model'] ?? '')), $v['variant'], $v['year']])))) ?: e(VEHICLE_TYPES[$v['type']]) ?></div>
      <h2><?= e($v['name']) ?><?= $v['archived'] ? ' <span class="pill">Archiviert</span>' : '' ?></h2>
      <?php if ($v['plate']): ?><span class="vcard-plate"><?= e($v['plate']) ?></span><?php endif; ?>
    </div>
    <div class="vhero-km"><b><?= $km !== null ? fmt_num($km) : '–' ?></b><span>Kilometerstand</span></div>
  </div>
</div>

<nav class="tabs">
  <?php foreach ($tabs as $k => [$label, $ic]): $c = ['fuel' => $counts['fuel'], 'costs' => $counts['costs'], 'reminders' => $counts['reminders'], 'parts' => $counts['parts'], 'docs' => $counts['docs']][$k] ?? null; ?>
    <a class="<?= $k === $tab ? 'on' : '' ?>" href="<?= e($self(['tab' => $k])) ?>"><?= icon($ic) ?><?= e($label) ?><?= $c ? ' <span class="count">' . $c . '</span>' : '' ?></a>
  <?php endforeach; ?>
</nav>

<?php
switch ($tab) {

case 'overview':
    $year = date('Y');
    $from12 = date('Y-m-01', strtotime('-11 months'));
    $e12 = cost_entries([$vid], $from12, today());
    $eAll = cost_entries([$vid]);
    $sum12 = array_sum(array_column($e12, 'amount'));
    $km12 = km_driven($vid, date('Y-m-d', strtotime('-12 months')), today());
    $sumAll = array_sum(array_column($eAll, 'amount'));
    $c = $motor ? consumption_stats($vid) : null;
    echo '<div class="kpis">';
    if ($motor) {
        echo kpi('Ø Verbrauch', $c['avg'] ? fmt_num($c['avg'], 2) . '<small>' . e(fuel_unit($v)) . '/100</small>' : '–', $c['last'] ? 'zuletzt ' . fmt_num($c['last'], 2) : 'mind. 2 Volltankungen nötig', 'fuel', 'accent');
    } else {
        echo kpi('Gefahren (12 M)', $km12 ? fmt_num($km12) . '<small>km</small>' : '–', 'letzte 12 Monate', 'route', 'accent');
    }
    echo kpi('Kosten (12 M)', fmt_money($sum12, false), fmt_money($sum12 / 12, false) . ' pro Monat', 'euro');
    echo kpi('Kosten pro km', ($km12 && $sum12) ? fmt_num($sum12 / $km12 * 100, 1) . '<small>ct</small>' : '–', $km12 ? fmt_km(round($km12)) . ' in 12 Monaten' : 'noch zu wenig km-Daten', 'trend');
    $owned = $v['purchase_date'] ? max(1, (int)round((time() - strtotime($v['purchase_date'])) / 2629800)) : null;
    echo kpi('Gesamtkosten', fmt_money($sumAll + (float)$v['purchase_price'], false), $v['purchase_price'] ? 'inkl. Kaufpreis' . ($owned ? ' · ' . $owned . ' Monate' : '') : 'alle Einträge', 'layers');
    echo '</div>';

    echo '<div class="grid grid-main mt"><div>';
    // Diagramm
    if ($motor && $c && count(array_filter(array_column($c['list'], 'consumption'))) >= 2) {
        $pts = [];
        foreach ($c['list'] as $f) if ($f['consumption'] !== null) $pts[] = [fmt_date($f['date'], true), $f['consumption']];
        echo '<div class="card"><div class="card-head"><h2>Verbrauch</h2><span class="muted small">' . e(fuel_unit($v)) . '/100 km</span></div>' . line_chart(array_slice($pts, -24), ['unit' => fuel_unit($v) . '/100 km']) . '</div>';
    }
    $months = [];
    for ($i = 11; $i >= 0; $i--) $months[date('Y-m', strtotime("first day of -$i months"))] = [];
    foreach ($e12 as $e) { $m = substr($e['date'], 0, 7); if (isset($months[$m])) $months[$m][$e['category']] = ($months[$m][$e['category']] ?? 0) + $e['amount']; }
    $series = [];
    foreach ($months as $m => $cs) $series[fmt_month_short($m)] = $cs;
    echo '<div class="card"><div class="card-head"><h2>Kosten je Monat</h2><a class="small" href="' . e(url('costs', ['v' => $vid])) . '">Auswertung</a></div>'
        . ($e12 ? bar_chart($series, ['colors' => array_map(fn($x) => $x[1], COST_CATEGORIES)]) : '<p class="muted">Noch keine Kosten in den letzten 12 Monaten.</p>') . '</div>';
    // Letzte Einträge
    echo '<div class="card flush"><div class="card-head"><h2>Letzte Einträge</h2></div>';
    echo $eAll ? '<ul class="list">' . implode('', array_map(fn($e) => entry_row($e, [$v]), array_slice($eAll, 0, 6))) . '</ul>' : '<p class="muted" style="padding:0 18px 18px">Noch keine Einträge.</p>';
    echo '</div></div><div>';
    // Fällig
    $due = due_items($v);
    echo '<div class="card flush"><div class="card-head"><h2>Fristen</h2><a class="small" href="' . e(url('reminder_edit', ['v' => $vid])) . '">+ Neu</a></div>';
    if ($due) {
        echo '<ul class="list">';
        foreach (array_slice($due, 0, 6) as $d) {
            $href = $d['type'] === 'reminder' ? url('reminder_edit', ['id' => $d['r']['id']]) : url('part_edit', ['id' => $d['r']['id']]);
            echo '<li><a class="list-item" href="' . e($href) . '"><span class="li-ic ' . ($d['status'] === 'overdue' ? 'c-red' : ($d['status'] === 'soon' ? 'c-amber' : 'c-green')) . '">' . icon($d['icon']) . '</span><span class="li-main"><span class="li-title">' . e($d['title']) . '</span><span class="li-sub">' . e($d['text']) . '</span></span></a></li>';
        }
        echo '</ul>';
    } else {
        echo '<p class="muted small" style="padding:0 18px 18px">Keine offenen Fristen.</p>';
    }
    echo '</div>';
    // Kategorien
    $byCat = sum_by($e12, fn($e) => $e['category']);
    if ($byCat) echo '<div class="card mt"><div class="card-head"><h2>Kostenverteilung</h2><span class="muted small">12 Monate</span></div>' . category_bars($byCat) . '</div>';
    if ($motor && $c && $c['avg_ppu']) {
        echo '<div class="card mt"><h2 class="mb">Tank-Statistik</h2>'
            . '<div class="stat-line"><span class="muted">Tankvorgänge</span><b>' . $c['count'] . '</b></div>'
            . '<div class="stat-line"><span class="muted">Ø Preis</span><b>' . fmt_num($c['avg_ppu'], 3) . ' €/' . e(fuel_unit($v)) . '</b></div>'
            . ($c['min'] ? '<div class="stat-line"><span class="muted">Bester Verbrauch</span><b>' . fmt_num($c['min'], 2) . '</b></div><div class="stat-line"><span class="muted">Höchster Verbrauch</span><b>' . fmt_num($c['max'], 2) . '</b></div>' : '')
            . '</div>';
    }
    echo '</div></div>';
    break;

case 'fuel':
    $c = consumption_stats($vid);
    $list = array_reverse($c['list']);
    echo '<div class="row-between mb"><div class="muted small">' . count($list) . ' Einträge' . ($c['avg'] ? ' · Ø ' . fmt_num($c['avg'], 2) . ' ' . e(fuel_unit($v)) . '/100 km' : '') . '</div><a class="btn btn-primary btn-sm" href="' . e(url('fuel_edit', ['v' => $vid])) . '">' . icon('plus') . ' Tanken</a></div>';
    if (!$list) { echo '<div class="card">' . empty_state('fuel', 'Noch nicht getankt', 'Erfasse Tankvorgänge mit Kilometerstand – Verbrauch und Kosten werden automatisch berechnet. Alte Daten kannst du aus Spritmonitor importieren.', 'Tankvorgang erfassen', url('fuel_edit', ['v' => $vid])) . '</div>'; break; }
    echo '<div class="card flush"><ul class="list">';
    foreach ($list as $f) {
        echo '<li><a class="list-item" href="' . e(url('fuel_edit', ['id' => $f['id']])) . '"><span class="li-ic c-teal">' . icon('fuel') . '</span><span class="li-main"><span class="li-title">' . fmt_date($f['date']) . ' · ' . fmt_num($f['quantity'], 2) . ' ' . e(fuel_unit($v)) . (!$f['full_tank'] ? ' <span class="pill">Teil</span>' : '') . '</span>'
            . '<span class="li-sub">' . fmt_km($f['odometer']) . ($f['ppu'] ? ' · ' . fmt_num($f['ppu'], 3) . ' €/' . e(fuel_unit($v)) : '') . ($f['station'] ? ' · ' . e($f['station']) : '') . '</span></span>'
            . '<span class="li-end"><span class="li-val">' . fmt_money($f['total']) . '</span><span class="li-sub">' . ($f['consumption'] ? fmt_num($f['consumption'], 2) . ' ' . e(fuel_unit($v)) . '/100' : '&nbsp;') . '</span></span></a></li>';
    }
    echo '</ul></div>';
    break;

case 'costs':
    $list = rows('SELECT * FROM costs WHERE vehicle_id = ? ORDER BY date DESC, id DESC', [$vid]);
    echo '<div class="row-between mb"><div class="muted small">' . count($list) . ' Einträge · ' . fmt_money(array_sum(array_column($list, 'amount'))) . '</div><a class="btn btn-primary btn-sm" href="' . e(url('cost_edit', ['v' => $vid])) . '">' . icon('plus') . ' Kosten</a></div>';
    if (!$list) { echo '<div class="card">' . empty_state('receipt', 'Keine Kosten erfasst', 'Werkstatt, Versicherung, Steuer, Reifen, Zubehör – alles, was das Fahrzeug kostet.', 'Kosten erfassen', url('cost_edit', ['v' => $vid])) . '</div>'; break; }
    echo '<div class="card flush"><ul class="list">';
    $lastYear = null;
    foreach ($list as $c) {
        $y = substr($c['date'], 0, 4);
        if ($y !== $lastYear) {
            $ySum = array_sum(array_column(array_filter($list, fn($x) => str_starts_with($x['date'], $y)), 'amount'));
            echo '<li class="list-group-label"><span>' . e($y) . '</span><span>' . fmt_money($ySum) . '</span></li>';
            $lastYear = $y;
        }
        echo entry_row(['src' => 'cost'] + $c + ['quantity' => null], [$v]);
    }
    echo '</ul></div>';
    break;

case 'km':
    $list = rows('SELECT * FROM odometer_log WHERE vehicle_id = ? ORDER BY date DESC, km DESC', [$vid]);
    echo '<div class="row-between mb"><div class="muted small">Kilometerstände & Fahrten</div><a class="btn btn-primary btn-sm" href="' . e(url('odo_edit', ['v' => $vid])) . '">' . icon('plus') . ' ' . ($motor ? 'Kilometerstand' : 'Fahrt / Stand') . '</a></div>';
    // Jahreskilometer
    $pts = km_points($vid);
    if (count($pts) >= 2) {
        $years = [];
        for ($y = (int)substr($pts[0]['d'], 0, 4); $y <= (int)date('Y'); $y++) {
            $d = km_driven($vid, "$y-01-01", min("$y-12-31", today()));
            if ($d) $years[(string)$y] = round($d);
        }
        if ($years) echo '<div class="card mb"><div class="card-head"><h2>Kilometer pro Jahr</h2></div>' . bar_chart($years, ['unit' => 'km', 'height' => 150]) . '</div>';
    }
    if (!$list) { echo '<div class="card">' . empty_state('route', 'Noch keine Kilometer', $motor ? 'Kilometerstände kommen automatisch aus Tankungen, Kosten und dem MySkoda-Abgleich. Hier kannst du zusätzlich Stände erfassen.' : 'Trage Fahrten oder den Tachostand ein – darüber wird der Verschleiß deiner Teile berechnet.', 'Eintragen', url('odo_edit', ['v' => $vid])) . '</div>'; break; }
    echo '<div class="card flush"><ul class="list">';
    foreach ($list as $o) {
        $src = ['skoda' => 'MySkoda', 'api' => 'API', 'garmin' => 'Garmin', 'manual' => ''][$o['source']] ?? $o['source'];
        echo '<li><a class="list-item" href="' . e(url('odo_edit', ['id' => $o['id']])) . '"><span class="li-ic c-sky">' . icon($o['source'] === 'skoda' ? 'sync' : 'route') . '</span><span class="li-main"><span class="li-title">' . fmt_km($o['km']) . ($o['trip'] ? ' <span class="muted small">(+' . fmt_num($o['trip'], 1) . ' km)</span>' : '') . '</span><span class="li-sub">' . fmt_date($o['date']) . ($src ? ' · ' . e($src) : '') . ($o['note'] ? ' · ' . e($o['note']) : '') . '</span></span>' . icon('chevron-right', 'li-chev') . '</a></li>';
    }
    echo '</ul></div>';
    break;

case 'reminders':
    echo '<div class="row-between mb"><div class="muted small">Termine nach Datum und/oder Kilometer</div><a class="btn btn-primary btn-sm" href="' . e(url('reminder_edit', ['v' => $vid])) . '">' . icon('plus') . ' Frist</a></div>';
    echo reminders_list(rows('SELECT r.*, ? AS vname FROM reminders r WHERE vehicle_id = ? ORDER BY done_at IS NOT NULL, done_at DESC', [$v['name'], $vid]), [$vid => $km], false);
    break;

case 'parts':
    $active = rows('SELECT * FROM parts WHERE vehicle_id = ? AND removed_date IS NULL ORDER BY category, installed_date DESC', [$vid]);
    $old = rows('SELECT * FROM parts WHERE vehicle_id = ? AND removed_date IS NOT NULL ORDER BY removed_date DESC', [$vid]);
    echo '<div class="row-between mb"><div class="muted small">' . ($motor ? 'Reifensätze, Batterie, Wischer & Co.' : 'Kette, Reifen, Bremsbeläge & Co. – mit Laufleistung') . '</div><a class="btn btn-primary btn-sm" href="' . e(url('part_edit', ['v' => $vid])) . '">' . icon('plus') . ' Teil</a></div>';
    if (!$active && !$old) { echo '<div class="card">' . empty_state('gauge', 'Noch keine Teile', $motor ? 'Erfasse z. B. Sommer- und Winterreifen mit Lagerort, Profiltiefe und DOT.' : 'Erfasse Verschleißteile mit Einbau-km und Laufleistung – die App warnt dich rechtzeitig.', 'Teil erfassen', url('part_edit', ['v' => $vid])) . '</div>'; break; }
    if ($active) echo '<div class="card flush"><div class="card-head"><h2>Montiert / aktiv</h2></div><ul class="list">' . implode('', array_map(fn($p) => part_row($p, $km), $active)) . '</ul></div>';
    if ($old) echo '<div class="card flush mt"><div class="card-head"><h2>Ausgebaut / eingelagert</h2></div><ul class="list">' . implode('', array_map(fn($p) => part_row($p, $km), $old)) . '</ul></div>';
    break;

case 'docs':
    echo '<div class="row-between mb"><div class="muted small">Fahrzeugschein, Rechnungen, Verträge …</div><a class="btn btn-primary btn-sm" href="' . e(url('doc_edit', ['v' => $vid])) . '">' . icon('upload') . ' Hochladen</a></div>';
    echo documents_list(rows('SELECT * FROM documents WHERE vehicle_id = ? ORDER BY created_at DESC', [$vid]), [$v]);
    break;

case 'data':
    $fields = [
        'Art' => VEHICLE_TYPES[$v['type']] ?? '', 'Marke' => $v['make'], 'Modell' => $v['model'], 'Ausstattung' => $v['variant'], 'Baujahr' => $v['year'],
        'Kennzeichen' => $v['plate'], 'Erstzulassung' => $v['first_registration'] ? fmt_date($v['first_registration']) : null,
        'FIN' => $v['vin'], 'HSN/TSN' => $v['hsn_tsn'], 'Rahmennummer' => $v['frame_number'],
        'Antrieb' => FUEL_TYPES[$v['fuel_type'] ?? ''] ?? null, 'Tankinhalt' => $v['tank_l'] ? fmt_num($v['tank_l'], 1) . ' ' . fuel_unit($v) : null,
        'Reifengröße' => $v['tire_size'], 'Motoröl' => $v['oil_spec'],
        'Kaufdatum' => $v['purchase_date'] ? fmt_date($v['purchase_date']) : null, 'Kaufpreis' => $v['purchase_price'] ? fmt_money($v['purchase_price']) : null,
        'km bei Kauf' => $v['purchase_km'] !== null ? fmt_km($v['purchase_km']) : null,
        'Versicherung' => $v['insurance_company'], 'Vertragsnummer' => $v['insurance_number'],
        'Spritmonitor-ID' => $v['sm_vehicle_id'],
    ];
    echo '<div class="grid grid-2"><div class="card"><div class="card-head"><h2>Fahrzeugdaten</h2><a class="btn btn-sm" href="' . e(url('vehicle_edit', ['id' => $vid])) . '">' . icon('edit') . ' Bearbeiten</a></div><dl class="kv">';
    foreach ($fields as $k => $val) if ($val !== null && $val !== '') echo '<dt>' . e($k) . '</dt><dd>' . e($val) . '</dd>';
    echo '</dl></div>';
    echo '<div class="card"><h2 class="mb">Notizen</h2>' . ($v['notes'] ? '<p style="white-space:pre-line">' . e($v['notes']) . '</p>' : '<p class="muted">Keine Notizen.</p>') . '</div></div>';
    break;
}

page_footer();
