<?php
declare(strict_types=1);

/* ---------- Fahrzeuge ---------- */

function vehicles(bool $withArchived = false): array
{
    $sql = 'SELECT * FROM vehicles WHERE user_id = ?' . ($withArchived ? '' : ' AND archived = 0') . ' ORDER BY archived, sort, type, name';
    return rows($sql, [uid()]);
}

/** Fahrzeug des angemeldeten Nutzers oder 404 */
function vehicle_or_404($id): array
{
    $v = row('SELECT * FROM vehicles WHERE id = ? AND user_id = ?', [(int)$id, uid()]);
    if (!$v) not_found();
    return $v;
}

/** Datensatz aus einer Kindtabelle laden und Besitz prüfen */
function owned(string $table, $id): array
{
    $r = row("SELECT t.* FROM $table t JOIN vehicles v ON v.id = t.vehicle_id WHERE t.id = ? AND v.user_id = ?", [(int)$id, uid()]);
    if (!$r) not_found();
    return $r;
}

function not_found(): never
{
    http_response_code(404);
    page_header('Nicht gefunden');
    echo '<div class="empty"><div class="empty-icon">' . icon('search') . '</div><h2>Nicht gefunden</h2><p>Diesen Eintrag gibt es nicht (mehr).</p><a class="btn" href="' . e(url()) . '">Zur Übersicht</a></div>';
    page_footer();
    exit;
}

/** Aktueller Kilometerstand aus allen Quellen */
function current_km(array $v): ?int
{
    $id = $v['id'];
    $km = val('SELECT MAX(m) FROM (
        SELECT MAX(odometer) AS m FROM fuelings WHERE vehicle_id = ?
        UNION ALL SELECT MAX(odometer) FROM costs WHERE vehicle_id = ?
        UNION ALL SELECT MAX(km) FROM odometer_log WHERE vehicle_id = ?
    ) x', [$id, $id, $id]);
    $base = $v['purchase_km'] !== null ? (int)$v['purchase_km'] : null;
    if ($km === null) return $base;
    return max((int)$km, (int)$base);
}

/** Kilometerstand zu einem Datum (für km-Statistiken, linear zwischen Messpunkten) */
function km_points(int $vid): array
{
    return rows('SELECT d, km FROM (
        SELECT date AS d, odometer AS km FROM fuelings WHERE vehicle_id = ? AND odometer IS NOT NULL
        UNION ALL SELECT date, odometer FROM costs WHERE vehicle_id = ? AND odometer IS NOT NULL
        UNION ALL SELECT date, km FROM odometer_log WHERE vehicle_id = ?
    ) x ORDER BY d, km', [$vid, $vid, $vid]);
}
function km_at(array $points, string $date): ?float
{
    if (!$points) return null;
    $prev = null;
    $next = null;
    foreach ($points as $p) {
        if ($p['d'] <= $date) {
            if (!$prev || $p['d'] > $prev['d'] || $p['km'] > $prev['km']) $prev = $p;
        } elseif (!$next) {
            $next = $p;
        }
    }
    if (!$prev) return (float)$next['km'];
    if (!$next || $prev['d'] === $date) return (float)$prev['km'];
    $span = strtotime($next['d']) - strtotime($prev['d']);
    $f = (strtotime($date) - strtotime($prev['d'])) / $span;
    return $prev['km'] + ($next['km'] - $prev['km']) * $f;
}
function km_driven(int $vid, string $from, string $to): ?float
{
    $pts = km_points($vid);
    if (count($pts) < 2) return null;
    $first = $pts[0]['d'];
    $last = end($pts)['d'];
    $from = max($from, $first);
    $to = min($to, $last);
    if ($to <= $from) return null;
    return max(0, km_at($pts, $to) - km_at($pts, $from));
}

/* ---------- Verbrauch ---------- */

/** Liefert Tankungen mit berechnetem Verbrauch (Volltank-Methode) */
function fuelings_with_consumption(int $vid): array
{
    $list = rows('SELECT * FROM fuelings WHERE vehicle_id = ? ORDER BY date, odometer, id', [$vid]);
    $lastFullKm = null;
    $acc = 0.0;
    foreach ($list as &$f) {
        $f['consumption'] = null;
        $f['ppu'] = $f['quantity'] > 0 ? $f['total'] / $f['quantity'] : null;
        $acc += (float)$f['quantity'];
        if ($f['full_tank'] && $f['odometer'] !== null) {
            if ($lastFullKm !== null && !$f['missed_before'] && $f['odometer'] > $lastFullKm) {
                $f['consumption'] = $acc / ($f['odometer'] - $lastFullKm) * 100;
                $f['distance'] = $f['odometer'] - $lastFullKm;
            }
            $lastFullKm = (int)$f['odometer'];
            $acc = 0.0;
        } elseif ($f['missed_before']) {
            $lastFullKm = null;
            $acc = 0.0;
        }
    }
    return $list;
}

function consumption_stats(int $vid): array
{
    $list = fuelings_with_consumption($vid);
    $vals = array_values(array_filter(array_column($list, 'consumption'), fn($c) => $c !== null));
    $dists = [];
    $qty = 0;
    $dist = 0;
    foreach ($list as $f) {
        if ($f['consumption'] !== null) {
            $dist += $f['distance'];
            $qty += $f['consumption'] * $f['distance'] / 100;
        }
    }
    $last = $vals ? end($vals) : null;
    return [
        'avg' => $dist > 0 ? $qty / $dist * 100 : null,
        'last' => $last,
        'min' => $vals ? min($vals) : null,
        'max' => $vals ? max($vals) : null,
        'count' => count($list),
        'list' => $list,
        'avg_ppu' => ($t = array_sum(array_column($list, 'quantity'))) > 0 ? array_sum(array_column($list, 'total')) / $t : null,
    ];
}

/* ---------- Kosten ---------- */

/** Alle Kosten (inkl. Tankungen) als einheitliche Liste */
function cost_entries(array $vehicleIds, ?string $from = null, ?string $to = null, ?string $category = null): array
{
    if (!$vehicleIds) return [];
    $in = implode(',', array_map('intval', $vehicleIds));
    $where = '';
    $p = [];
    if ($from) { $where .= ' AND date >= ?'; $p[] = $from; }
    if ($to) { $where .= ' AND date <= ?'; $p[] = $to; }
    $parts = [];
    $params = [];
    if (!$category || $category === 'kraftstoff') {
        $parts[] = "SELECT 'fuel' AS src, id, vehicle_id, date, 'kraftstoff' AS category, " . (is_sqlite() ? "'Tanken'" : "'Tanken'") . " AS title, total AS amount, odometer, station AS vendor, note, quantity FROM fuelings WHERE vehicle_id IN ($in) $where";
        $params = array_merge($params, $p);
    }
    $catWhere = $category ? ' AND category = ?' : '';
    $parts[] = "SELECT 'cost' AS src, id, vehicle_id, date, category, title, amount, odometer, vendor, note, NULL AS quantity FROM costs WHERE vehicle_id IN ($in) $where$catWhere";
    $params = array_merge($params, $p, $category ? [$category] : []);
    return rows('SELECT * FROM (' . implode(' UNION ALL ', $parts) . ') x ORDER BY date DESC, id DESC', $params);
}

function sum_by(array $entries, callable $key): array
{
    $out = [];
    foreach ($entries as $e) {
        $k = $key($e);
        $out[$k] = ($out[$k] ?? 0) + (float)$e['amount'];
    }
    return $out;
}

/** Wiederkehrende Kosten fortschreiben (z. B. Versicherung jährlich) */
function materialize_recurring(?int $userId = null): void
{
    $list = rows('SELECT c.* FROM costs c JOIN vehicles v ON v.id = c.vehicle_id WHERE v.user_id = ? AND c.repeat_months > 0 AND c.repeated = 0', [$userId ?? uid()]);
    foreach ($list as $c) {
        $cur = $c;
        $guard = 0;
        while (($next = add_months($cur['date'], (int)$cur['repeat_months'])) <= today() && $guard++ < 60) {
            update('costs', ['repeated' => 1], 'id = ?', [$cur['id']]);
            $new = $cur;
            unset($new['id']);
            $new['date'] = $next;
            $new['odometer'] = null;
            $new['repeated'] = 0;
            $new['source'] = 'auto';
            $new['created_at'] = now();
            $new['id'] = insert('costs', $new);
            $cur = $new;
        }
    }
}

/* ---------- Fristen ---------- */

function reminder_status(array $r, ?int $km): array
{
    $status = 'ok';
    $days = null;
    $kmLeft = null;
    if ($r['due_date']) {
        $days = (int)floor((strtotime($r['due_date']) - strtotime(today())) / 86400);
        if ($days < 0) $status = 'overdue';
        elseif ($days <= (int)$r['warn_days']) $status = 'soon';
    }
    if ($r['due_km'] !== null && $km !== null) {
        $kmLeft = (int)$r['due_km'] - $km;
        if ($kmLeft < 0) $status = 'overdue';
        elseif ($kmLeft <= (int)$r['warn_km'] && $status !== 'overdue') $status = 'soon';
    }
    $text = [];
    if ($r['due_date']) $text[] = fmt_date($r['due_date']) . ' · ' . rel_days($r['due_date']);
    if ($r['due_km'] !== null) {
        $text[] = 'bei ' . fmt_km($r['due_km']) . ($kmLeft !== null ? ($kmLeft >= 0 ? ' · noch ' . fmt_km($kmLeft) : ' · ' . fmt_km(-$kmLeft) . ' drüber') : '');
    }
    // Sortierschlüssel: geschätzte Tage bis fällig
    $sort = $days ?? 9999;
    if ($kmLeft !== null) $sort = min($sort, (int)($kmLeft / 40));
    return ['status' => $status, 'days' => $days, 'km_left' => $kmLeft, 'text' => implode(' · ', $text), 'sort' => $sort];
}

/** Offene Fristen + Verschleißteile über alle Fahrzeuge, nach Dringlichkeit sortiert */
function due_items(?array $only = null): array
{
    $vs = $only ? [$only] : vehicles();
    $items = [];
    foreach ($vs as $v) {
        $km = current_km($v);
        foreach (rows('SELECT * FROM reminders WHERE vehicle_id = ? AND done_at IS NULL', [$v['id']]) as $r) {
            $st = reminder_status($r, $km);
            $items[] = ['type' => 'reminder', 'v' => $v, 'r' => $r, 'title' => $r['title'], 'icon' => REMINDER_KINDS[$r['kind']][1] ?? 'bell'] + $st;
        }
        foreach (rows('SELECT * FROM parts WHERE vehicle_id = ? AND removed_date IS NULL AND limit_km IS NOT NULL', [$v['id']]) as $p) {
            $w = part_wear($p, $km);
            if ($w['pct'] === null || $w['pct'] < 80) continue;
            $items[] = ['type' => 'part', 'v' => $v, 'r' => $p, 'title' => part_label($p['category']) . ': ' . $p['name'], 'icon' => 'gauge',
                'status' => $w['pct'] >= 100 ? 'overdue' : 'soon', 'text' => fmt_num($w['used']) . ' von ' . fmt_km($p['limit_km']) . ' (' . round($w['pct']) . ' %)', 'sort' => (int)((100 - $w['pct']) * 3)];
        }
    }
    usort($items, fn($a, $b) => $a['sort'] <=> $b['sort']);
    return $items;
}

function part_wear(array $p, ?int $km): array
{
    $end = $p['removed_km'] ?? $km;
    $used = ($end !== null && $p['installed_km'] !== null) ? max(0, (int)$end - (int)$p['installed_km']) : null;
    $pct = ($used !== null && $p['limit_km']) ? $used / (int)$p['limit_km'] * 100 : null;
    return ['used' => $used, 'pct' => $pct];
}

/** Frist erledigen und ggf. Folgefrist anlegen */
function complete_reminder(array $r, ?string $date = null, ?int $km = null): ?int
{
    $date = $date ?: today();
    update('reminders', ['done_at' => $date . ' 12:00:00'], 'id = ?', [$r['id']]);
    if (!$r['interval_months'] && !$r['interval_km']) return null;
    $new = [
        'vehicle_id' => $r['vehicle_id'], 'kind' => $r['kind'], 'title' => $r['title'],
        'due_date' => $r['interval_months'] ? add_months($date, (int)$r['interval_months']) : null,
        'due_km' => ($r['interval_km'] && $km !== null) ? $km + (int)$r['interval_km'] : null,
        'interval_months' => $r['interval_months'], 'interval_km' => $r['interval_km'],
        'warn_days' => $r['warn_days'], 'warn_km' => $r['warn_km'], 'note' => $r['note'],
        'source' => $r['source'], 'created_at' => now(),
    ];
    return insert('reminders', $new);
}

/* ---------- Fotos & Dateien ---------- */

function upload_dir(): string
{
    $d = APP_ROOT . '/data/uploads/' . uid();
    if (!is_dir($d)) mkdir($d, 0775, true);
    return $d;
}

const ALLOWED_UPLOADS = [
    'application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
    'image/heic' => 'heic', 'image/heif' => 'heic', 'text/plain' => 'txt', 'text/csv' => 'csv',
];

/** Speichert einen Upload sicher; gibt [stored, mime, size, name] zurück */
function store_upload(array $file, array $allowed = ALLOWED_UPLOADS, int $maxMb = 20): ?array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
    if ($file['size'] > $maxMb * 1024 * 1024) { flash("Die Datei ist größer als $maxMb MB.", 'err'); return null; }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: 'application/octet-stream';
    if (!isset($allowed[$mime])) { flash('Dieser Dateityp wird nicht unterstützt (' . e($mime) . ').', 'err'); return null; }
    $stored = bin2hex(random_bytes(12)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], upload_dir() . '/' . $stored)) {
        flash('Die Datei konnte nicht gespeichert werden.', 'err');
        return null;
    }
    return ['stored' => $stored, 'mime' => $mime, 'size' => (int)$file['size'], 'name' => mb_substr(basename($file['name']), 0, 200)];
}

/** Fahrzeugfoto auf max. 1200px verkleinern */
function store_photo(array $file): ?string
{
    $up = store_upload($file, ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'], 25);
    if (!$up) return null;
    $path = upload_dir() . '/' . $up['stored'];
    if (function_exists('imagecreatefromstring')) {
        $img = @imagecreatefromstring((string)file_get_contents($path));
        if ($img) {
            if ($up['mime'] === 'image/jpeg' && function_exists('exif_read_data')) {
                $exif = @exif_read_data($path);
                $o = $exif['Orientation'] ?? 1;
                if ($o == 3) $img = imagerotate($img, 180, 0);
                if ($o == 6) $img = imagerotate($img, -90, 0);
                if ($o == 8) $img = imagerotate($img, 90, 0);
            }
            $w = imagesx($img); $h = imagesy($img);
            $s = min(1, 1200 / max($w, $h));
            $nw = (int)($w * $s); $nh = (int)($h * $s);
            $dst = imagecreatetruecolor($nw, $nh);
            imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
            $stored = bin2hex(random_bytes(12)) . '.jpg';
            imagejpeg($dst, upload_dir() . '/' . $stored, 84);
            @unlink($path);
            return $stored;
        }
    }
    return $up['stored'];
}

function file_url(string $stored): string
{
    return url('file', ['f' => $stored]);
}

function delete_vehicle_files(int $vid): void
{
    foreach (rows('SELECT stored_name FROM documents WHERE vehicle_id = ?', [$vid]) as $d) {
        @unlink(upload_dir() . '/' . basename($d['stored_name']));
    }
    $photo = val('SELECT photo FROM vehicles WHERE id = ?', [$vid]);
    if ($photo) @unlink(upload_dir() . '/' . basename((string)$photo));
}

function log_sync(string $source, string $msg, ?int $userId = null): void
{
    insert('sync_log', ['user_id' => $userId ?? uid(), 'source' => $source, 'message' => $msg, 'at' => now()]);
}
