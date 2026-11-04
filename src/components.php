<?php
declare(strict_types=1);

function vehicle_card(array $v): string
{
    $km = current_km($v);
    $color = $v['color'] ?: '#0f766e';
    $media = $v['photo'] ? '<img src="' . e(file_url($v['photo'])) . '" alt="" loading="lazy">' : icon(vehicle_icon($v));
    $plate = $v['plate'] ? '<span class="vcard-plate">' . e($v['plate']) . '</span>' : '';
    $stats = '<div><b>' . e($km !== null ? fmt_num($km) : '–') . '</b>km</div>';
    if (is_motor($v)) {
        $c = consumption_stats((int)$v['id']);
        $stats .= '<div><b>' . ($c['avg'] ? fmt_num($c['avg'], 1) : '–') . '</b>' . e(fuel_unit($v)) . '/100 km</div>';
    } else {
        $parts = (int)val('SELECT COUNT(*) FROM parts WHERE vehicle_id = ? AND removed_date IS NULL', [$v['id']]);
        $stats .= '<div><b>' . $parts . '</b>Teile aktiv</div>';
    }
    $yearCost = array_sum(array_column(cost_entries([(int)$v['id']], date('Y') . '-01-01'), 'amount'));
    $stats .= '<div><b>' . fmt_money($yearCost, false) . '</b>' . date('Y') . '</div>';
    $dues = array_filter(due_items($v), fn($d) => $d['status'] !== 'ok');
    $pills = '';
    foreach (array_slice($dues, 0, 2) as $d) $pills .= status_pill($d['status'], $d['title']);
    if (!$dues) {
        $next = due_items($v)[0] ?? null;
        if ($next) $pills = '<span class="pill">' . e($next['title'] . ' · ' . ($next['r']['due_date'] ?? null ? rel_days($next['r']['due_date']) : $next['text'])) . '</span>';
    }
    $sub = trim(implode(' ', array_filter([$v['make'], $v['model']])));
    return '<a class="vcard" style="--vc:' . e($color) . '" href="' . e(url('vehicle', ['id' => $v['id']])) . '"><div class="vcard-media">' . $media . $plate . '</div>'
        . '<div class="vcard-body"><div class="vcard-title">' . e($v['name']) . '</div><div class="muted small">' . e($sub ?: VEHICLE_TYPES[$v['type']] ?? '') . '</div>'
        . '<div class="vcard-stats">' . $stats . '</div>' . ($pills ? '<div class="vcard-due">' . $pills . '</div>' : '') . '</div></a>';
}

function entry_row(array $e, array $vs): string
{
    $vname = '';
    foreach ($vs as $v) if ($v['id'] == $e['vehicle_id']) $vname = $v['name'];
    $isFuel = $e['src'] === 'fuel';
    $href = $isFuel ? url('fuel_edit', ['id' => $e['id']]) : url('cost_edit', ['id' => $e['id']]);
    $ic = $isFuel ? 'fuel' : (['wartung' => 'wrench', 'reparatur' => 'wrench', 'reifen' => 'tire', 'versicherung' => 'umbrella', 'steuer' => 'receipt', 'pruefung' => 'shield', 'teile' => 'gauge', 'pflege' => 'droplet', 'parken' => 'tag', 'garage' => 'home'][$e['category']] ?? 'receipt');
    $sub = $vname . ' · ' . fmt_date($e['date']) . ($isFuel && $e['quantity'] ? ' · ' . fmt_num($e['quantity'], 2) . ' l' : '') . (($e['items'] ?? 0) > 1 ? ' · ' . $e['items'] . ' Positionen' : '');
    return '<li><a class="list-item" href="' . e($href) . '"><span class="li-ic" style="background:color-mix(in srgb,' . e(cat_color($e['category'])) . ' 14%,transparent);color:' . e(cat_color($e['category'])) . '">' . icon($ic) . '</span>'
        . '<span class="li-main"><span class="li-title">' . e($e['title']) . '</span><span class="li-sub">' . e($sub) . '</span></span>'
        . '<span class="li-end"><span class="li-val">' . fmt_money($e['amount']) . '</span></span></a></li>';
}

/** Liste von Fristen (offen + erledigt) */
function reminders_list(array $list, array $kmByVehicle, bool $showVehicle = true): string
{
    if (!$list) return '<div class="card">' . empty_state('bell', 'Keine Fristen', 'Lege HU/AU, Inspektion, Ölwechsel, Reifenwechsel oder eigene Termine an – nach Datum und/oder Kilometerstand.') . '</div>';
    $open = []; $done = [];
    foreach ($list as $r) {
        if ($r['done_at']) { $done[] = $r; continue; }
        $r['_st'] = reminder_status($r, $kmByVehicle[$r['vehicle_id']] ?? null);
        $open[] = $r;
    }
    usort($open, fn($a, $b) => $a['_st']['sort'] <=> $b['_st']['sort']);
    $h = '';
    if ($open) {
        $h .= '<div class="card flush"><ul class="list">';
        foreach ($open as $r) {
            $st = $r['_st'];
            $tone = $st['status'] === 'overdue' ? 'c-red' : ($st['status'] === 'soon' ? 'c-amber' : 'c-green');
            $int = [];
            if ($r['interval_months']) $int[] = 'alle ' . ($r['interval_months'] % 12 === 0 ? ($r['interval_months'] / 12) . ' J.' : $r['interval_months'] . ' Mon.');
            if ($r['interval_km']) $int[] = fmt_km($r['interval_km']);
            $h .= '<li class="list-item"><span class="li-ic ' . $tone . '">' . icon(REMINDER_KINDS[$r['kind']][1] ?? 'bell') . '</span>'
                . '<a class="li-main" style="color:inherit;text-decoration:none" href="' . e(url('reminder_edit', ['id' => $r['id']])) . '"><span class="li-title">' . e($r['title']) . ($r['source'] !== 'manual' ? ' <span class="pill pill-accent">MySkoda</span>' : '') . '</span>'
                . '<span class="li-sub">' . ($showVehicle ? e($r['vname']) . ' · ' : '') . e($st['text'] ?: 'ohne Termin') . ($int ? ' · ' . e(implode(' / ', $int)) : '') . '</span></a>'
                . ($st['status'] !== 'ok' ? status_pill($st['status']) : '')
                . '<a class="btn btn-sm" href="' . e(url('reminder_done', ['id' => $r['id']])) . '">' . icon('check') . '<span class="hide-xs">Erledigt</span></a></li>';
        }
        $h .= '</ul></div>';
    }
    if ($done) {
        $h .= '<details class="more mt"><summary>Erledigt (' . count($done) . ') ' . icon('chevron-down') . '</summary><div class="card flush mt"><ul class="list">';
        foreach (array_slice($done, 0, 50) as $r) {
            $h .= '<li><a class="list-item" href="' . e(url('reminder_edit', ['id' => $r['id']])) . '"><span class="li-ic">' . icon('check') . '</span><span class="li-main"><span class="li-title">' . e($r['title']) . '</span><span class="li-sub">' . ($showVehicle ? e($r['vname']) . ' · ' : '') . 'erledigt am ' . fmt_date($r['done_at']) . '</span></span></a></li>';
        }
        $h .= '</ul></div></details>';
    }
    return $h;
}

function part_row(array $p, ?int $km): string
{
    $w = part_wear($p, $km);
    $tone = $w['pct'] === null ? '' : ($w['pct'] >= 100 ? 'overdue' : ($w['pct'] >= 80 ? 'soon' : ''));
    $meta = [];
    if ($p['brand']) $meta[] = $p['brand'];
    if ($p['season']) $meta[] = ['sommer' => 'Sommer', 'winter' => 'Winter', 'ganzjahr' => 'Ganzjahr'][$p['season']] ?? $p['season'];
    if ($p['dot']) $meta[] = 'DOT ' . $p['dot'];
    if ($p['tread_mm'] !== null) $meta[] = fmt_num($p['tread_mm'], 1) . ' mm';
    if ($p['installed_date']) $meta[] = ($p['removed_date'] ? 'bis ' . fmt_date($p['removed_date']) : 'seit ' . fmt_date($p['installed_date']));
    if ($p['removed_date'] && $p['storage']) $meta[] = 'Lager: ' . $p['storage'];
    $used = $w['used'] !== null ? fmt_km($w['used']) : '';
    $bar = $w['pct'] !== null ? '<div class="wear ' . $tone . '"><span style="width:' . min(100, round($w['pct'])) . '%"></span></div>' : '';
    return '<li><a class="list-item" href="' . e(url('part_edit', ['id' => $p['id']])) . '"><span class="li-ic c-orange">' . icon($p['category'] === 'reifen' ? 'tire' : 'gauge') . '</span>'
        . '<span class="li-main"><span class="li-title">' . e($p['name']) . ' <span class="muted small">· ' . e(part_label($p['category'])) . '</span></span><span class="li-sub">' . e(implode(' · ', $meta)) . '</span>' . $bar . '</span>'
        . '<span class="li-end"><span class="li-val">' . e($used) . '</span><span class="li-sub">' . ($p['limit_km'] ? 'von ' . fmt_km($p['limit_km']) : '') . '</span></span></a></li>';
}

function documents_list(array $docs, array $vs, bool $showVehicle = false, string $empty = ''): string
{
    if (!$docs) return '<div class="card">' . empty_state('files', 'Keine Dokumente', $empty ?: 'Lade Fahrzeugschein, Rechnungen, HU-Berichte oder Versicherungsunterlagen hoch (PDF oder Foto).') . '</div>';
    $names = array_column($vs, 'name', 'id');
    $h = '<div class="card flush"><ul class="list">';
    foreach ($docs as $d) {
        $isImg = str_starts_with((string)$d['mime'], 'image/');
        $sub = [DOC_KINDS[$d['kind']] ?? 'Dokument'];
        if ($showVehicle) $sub[] = $d['vehicle_id'] ? ($names[$d['vehicle_id']] ?? '') : 'Allgemein';
        $sub[] = fmt_date($d['doc_date'] ?? null ?: $d['created_at']);
        $pill = '';
        if (!empty($d['valid_until'])) {
            $left = (int)floor((strtotime($d['valid_until']) - strtotime(today())) / 86400);
            $pill = $left < 0 ? status_pill('done', 'Garantie abgelaufen') : status_pill($left <= 90 ? 'soon' : 'ok', 'Garantie bis ' . fmt_date($d['valid_until'], true));
        }
        $h .= '<li class="list-item"><span class="li-ic c-slate">' . icon($isImg ? 'image' : 'file') . '</span>'
            . '<a class="li-main" style="color:inherit;text-decoration:none" href="' . e(url('file', ['f' => $d['stored_name'], 'name' => $d['filename']])) . '" target="_blank" rel="noopener"><span class="li-title">' . e($d['title']) . '</span>'
            . '<span class="li-sub">' . e(implode(' · ', array_filter($sub))) . '</span></a>'
            . ($pill ? '<span class="li-end hide-xs">' . $pill . '</span>' : '')
            . '<a class="icon-btn" href="' . e(url('doc_edit', ['id' => $d['id'], 'back' => $_SERVER['REQUEST_URI'] ?? null])) . '" aria-label="Bearbeiten">' . icon('edit') . '</a></li>';
    }
    return $h . '</ul></div>';
}

/* ---------- Fahrradbereich ---------- */

/** Unternavigation des Fahrradbereichs */
function bike_area_nav(string $cur): string
{
    $tabs = ['fahrraeder' => ['Räder', 'bike'], 'bike_service' => ['Service', 'wrench'], 'bike_stock' => ['Lager', 'box'], 'bike_docs' => ['Dokumente', 'files']];
    $h = '<nav class="tabs area-tabs" aria-label="Fahrradbereich">';
    foreach ($tabs as $p => [$l, $ic]) {
        $h .= '<a class="' . ($p === $cur ? 'on' : '') . '" href="' . e(url($p)) . '"' . ($p === $cur ? ' aria-current="page"' : '') . '>' . icon($ic) . e($l) . '</a>';
    }
    return $h . '</nav>';
}

/** Alle (aktiven) Fahrräder des Nutzers */
function bikes(bool $withArchived = false): array
{
    return array_values(array_filter(vehicles($withArchived), fn($v) => vehicle_group($v) === 'fahrraeder'));
}

function service_row(array $s, array $names, bool $showVehicle = true, ?string $back = null): string
{
    $planned = $s['status'] === 'geplant';
    $st = service_status($s);
    $work = $s['work'] ? count(array_filter(array_map('trim', explode("
", $s['work'])))) : 0;
    $sub = [];
    if ($showVehicle) $sub[] = $names[$s['vehicle_id']] ?? '';
    $sub[] = $planned ? $st['text'] : fmt_date($s['date']);
    if (!$planned) {
        if ($s['odometer'] !== null) $sub[] = fmt_km($s['odometer']);
        $sub[] = $s['done_by'] === 'selbst' ? 'Selbst gemacht' : ($s['workshop'] ?: 'Werkstatt');
        if ($work) $sub[] = $work . ($work === 1 ? ' Arbeit' : ' Arbeiten');
    }
    $tone = $planned ? ['overdue' => 'c-red', 'soon' => 'c-amber', 'ok' => 'c-sky'][$st['status']] : 'c-green';
    $end = $planned ? ($st['status'] !== 'ok' ? status_pill($st['status']) : status_pill('accent', 'Geplant'))
        : ($s['amount'] !== null ? '<span class="li-val">' . fmt_money($s['amount']) . '</span>' : '');
    return '<li><a class="list-item" href="' . e(url('service_edit', ['id' => $s['id']] + ($back ? ['back' => $back] : []))) . '"><span class="li-ic ' . $tone . '">' . icon(SERVICE_KINDS[$s['kind']][1] ?? 'wrench') . '</span>'
        . '<span class="li-main"><span class="li-title">' . e($s['title']) . '</span><span class="li-sub">' . e(implode(' · ', array_filter($sub))) . '</span></span>'
        . ($end ? '<span class="li-end">' . $end . '</span>' : '') . '</a></li>';
}

function stock_row(array $i): string
{
    $sub = array_filter([$i['brand'], $i['location'] ? 'Lager: ' . $i['location'] : null, $i['fits'] ? 'passt zu ' . $i['fits'] : null]);
    $qty = (int)$i['quantity'];
    return '<li><a class="list-item' . ($qty <= 0 ? ' is-empty' : '') . '" href="' . e(url('stock_edit', ['id' => $i['id']])) . '"><span class="li-ic c-orange">' . icon(stock_icon($i['category'])) . '</span>'
        . '<span class="li-main"><span class="li-title">' . e($i['name']) . '</span><span class="li-sub">' . e(implode(' · ', $sub) ?: (stock_categories()[$i['category']] ?? '')) . '</span></span>'
        . '<span class="li-end"><span class="qty">' . $qty . '×</span>' . ($i['price'] !== null ? '<span class="li-sub">' . fmt_money($i['price']) . '</span>' : '') . '</span></a></li>';
}
