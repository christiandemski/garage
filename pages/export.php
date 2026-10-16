<?php
declare(strict_types=1);

$what = $_GET['what'] ?? 'costs';
$vs = vehicles(true);
$ids = array_map('intval', array_column($vs, 'id'));
$names = array_column($vs, 'name', 'id');
$in = $ids ? implode(',', $ids) : '0';

/** Zelle für CSV aufbereiten; Text, den Excel als Formel deuten würde (=, +, -, @ …), mit ' entschärfen */
function csv_cell($x)
{
    if (is_float($x)) return str_replace('.', ',', (string)$x);
    if (is_string($x) && $x !== '' && strpbrk($x[0], "=+-@\t\r") !== false && !is_numeric(str_replace(',', '.', $x))) return "'" . $x;
    return $x;
}
function csv_string(array $header, array $rows): string
{
    $h = fopen('php://temp', 'r+');
    fwrite($h, "\xEF\xBB\xBF");
    fputcsv($h, $header, ';', '"', '\\');
    foreach ($rows as $r) fputcsv($h, array_map('csv_cell', $r), ';', '"', '\\');
    rewind($h);
    return (string)stream_get_contents($h);
}
$dec = fn($x) => $x === null ? '' : str_replace('.', ',', (string)(float)$x);

if ($what === 'costs') {
    $vf = (int)($_GET['v'] ?? 0);
    $sel = $vf && in_array($vf, $ids, true) ? [$vf] : $ids;
    $y = (string)($_GET['y'] ?? '');
    [$from, $to] = preg_match('/^\d{4}$/', $y) ? ["$y-01-01", "$y-12-31"] : ($y === '12m' ? [date('Y-m-01', strtotime('-11 months')), today()] : [null, null]);
    $rows = [];
    foreach (cost_entries($sel, $from, $to) as $e) {
        $rows[] = [$e['src'] === 'fuel' ? 'Tanken' : 'Kosten', $names[$e['vehicle_id']] ?? '', fmt_date($e['date']), cat_label($e['category']), $e['title'], $dec($e['amount']), $e['odometer'], $dec($e['quantity']), $e['vendor'], $e['note']];
    }
    session_write_close();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="garage-kosten-' . ($y ?: 'alle') . '.csv"');
    echo csv_string(['Art', 'Fahrzeug', 'Datum', 'Kategorie', 'Titel', 'Betrag', 'Kilometerstand', 'Menge', 'Werkstatt/Tankstelle', 'Notiz'], $rows);
    exit;
}

// Komplett-Export als ZIP
$files = [
    'fahrzeuge.csv' => csv_string(['ID', 'Name', 'Art', 'Marke', 'Modell', 'Ausstattung', 'Baujahr', 'Kennzeichen', 'FIN', 'Antrieb', 'Kaufdatum', 'Kaufpreis', 'km bei Kauf', 'Notizen'],
        array_map(fn($v) => [$v['id'], $v['name'], VEHICLE_TYPES[$v['type']] ?? $v['type'], $v['make'], $v['model'], $v['variant'], $v['year'], $v['plate'], $v['vin'], $v['fuel_type'], $v['purchase_date'], $dec($v['purchase_price']), $v['purchase_km'], $v['notes']], $vs)),
    'tankungen.csv' => csv_string(['Fahrzeug', 'Datum', 'Kilometerstand', 'Strecke', 'Menge', 'Gesamtpreis', 'Vollgetankt', 'Sorte', 'Tankstelle', 'Notiz'],
        array_map(fn($f) => [$names[$f['vehicle_id']] ?? '', fmt_date($f['date']), $f['odometer'], $dec($f['trip']), $dec($f['quantity']), $dec($f['total']), $f['full_tank'] ? 'Voll' : 'Teil', $f['fuel_sort'], $f['station'], $f['note']], rows("SELECT * FROM fuelings WHERE vehicle_id IN ($in) ORDER BY vehicle_id, date"))),
    'kosten.csv' => csv_string(['Fahrzeug', 'Datum', 'Kategorie', 'Titel', 'Betrag', 'Kilometerstand', 'Werkstatt', 'Wiederholung (Monate)', 'Notiz'],
        array_map(fn($c) => [$names[$c['vehicle_id']] ?? '', fmt_date($c['date']), cat_label($c['category']), $c['title'], $dec($c['amount']), $c['odometer'], $c['vendor'], $c['repeat_months'], $c['note']], rows("SELECT * FROM costs WHERE vehicle_id IN ($in) ORDER BY vehicle_id, date"))),
    'kilometer.csv' => csv_string(['Fahrzeug', 'Datum', 'Kilometerstand', 'Strecke', 'Quelle', 'Notiz'],
        array_map(fn($o) => [$names[$o['vehicle_id']] ?? '', fmt_date($o['date']), $o['km'], $dec($o['trip']), $o['source'], $o['note']], rows("SELECT * FROM odometer_log WHERE vehicle_id IN ($in) ORDER BY vehicle_id, date"))),
    'fristen.csv' => csv_string(['Fahrzeug', 'Titel', 'Art', 'Fällig am', 'Fällig bei km', 'Intervall Monate', 'Intervall km', 'Erledigt', 'Notiz'],
        array_map(fn($r) => [$names[$r['vehicle_id']] ?? '', $r['title'], kind_label($r['kind']), $r['due_date'], $r['due_km'], $r['interval_months'], $r['interval_km'], $r['done_at'], $r['note']], rows("SELECT * FROM reminders WHERE vehicle_id IN ($in) ORDER BY vehicle_id, due_date"))),
    'teile.csv' => csv_string(['Fahrzeug', 'Kategorie', 'Name', 'Marke', 'Eingebaut', 'Einbau-km', 'Laufleistung', 'Ausgebaut', 'Ausbau-km', 'Preis', 'Saison', 'DOT', 'Profil mm', 'Lagerort', 'Notiz'],
        array_map(fn($p) => [$names[$p['vehicle_id']] ?? '', part_label($p['category']), $p['name'], $p['brand'], $p['installed_date'], $p['installed_km'], $p['limit_km'], $p['removed_date'], $p['removed_km'], $dec($p['price']), $p['season'], $p['dot'], $dec($p['tread_mm']), $p['storage'], $p['note']], rows("SELECT * FROM parts WHERE vehicle_id IN ($in) ORDER BY vehicle_id"))),
];

session_write_close();
if (class_exists('ZipArchive')) {
    $tmp = tempnam(sys_get_temp_dir(), 'gar');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    foreach ($files as $n => $c) $zip->addFromString($n, $c);
    $zip->close();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="garage-export-' . date('Y-m-d') . '.zip"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    unlink($tmp);
} else {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="garage-kosten.csv"');
    echo $files['kosten.csv'];
}
exit;
