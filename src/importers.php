<?php
declare(strict_types=1);

/* =========================================================
   CSV-Import (Spritmonitor-Export, Garage-Export, eigene Listen)
   ========================================================= */

function csv_read(string $path): array
{
    $raw = (string)file_get_contents($path);
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    if (!mb_check_encoding($raw, 'UTF-8')) $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    $lines = preg_split('/\r\n|\r|\n/', trim($raw));
    if (!$lines) return [[], []];
    $first = $lines[0];
    $delim = substr_count($first, ';') >= substr_count($first, ',') ? ';' : ',';
    if (substr_count($first, "\t") > substr_count($first, $delim)) $delim = "\t";
    $rows = [];
    foreach ($lines as $l) {
        if (trim($l) === '') continue;
        $rows[] = str_getcsv($l, $delim, '"', '\\');
    }
    $header = array_map(fn($h) => mb_strtolower(trim((string)$h)), array_shift($rows) ?? []);
    return [$header, $rows];
}

/** Findet die Spalte, deren Überschrift eines der Stichworte enthält */
function csv_col(array $header, array $needles, array $exclude = []): ?int
{
    foreach ($needles as $n) {
        foreach ($header as $i => $h) {
            if (str_contains($h, $n)) {
                foreach ($exclude as $x) if (str_contains($h, $x)) continue 2;
                return $i;
            }
        }
    }
    return null;
}

function guess_category(string $text): string
{
    $t = mb_strtolower($text);
    $map = [
        'versicher' => 'versicherung', 'insurance' => 'versicherung',
        'steuer' => 'steuer', 'tax' => 'steuer',
        'hauptuntersuchung' => 'pruefung', 'tüv' => 'pruefung', 'tuev' => 'pruefung', 'hu/au' => 'pruefung', 'dekra' => 'pruefung', 'inspection' => 'wartung',
        'inspektion' => 'wartung', 'wartung' => 'wartung', 'service' => 'wartung', 'öl' => 'wartung', 'oel' => 'wartung', 'oil' => 'wartung', 'maintenance' => 'wartung',
        'reparatur' => 'reparatur', 'werkstatt' => 'reparatur', 'repair' => 'reparatur',
        'reifen' => 'reifen', 'tire' => 'reifen', 'tyre' => 'reifen',
        'leasing' => 'finanzierung', 'kredit' => 'finanzierung', 'finanz' => 'finanzierung', 'rate' => 'finanzierung',
        'wäsche' => 'pflege', 'waesche' => 'pflege', 'pflege' => 'pflege', 'wash' => 'pflege', 'reinigung' => 'pflege',
        'park' => 'parken', 'maut' => 'parken', 'toll' => 'parken', 'vignette' => 'parken',
        'zubehör' => 'zubehoer', 'zubehoer' => 'zubehoer', 'tuning' => 'zubehoer', 'accessor' => 'zubehoer',
        'adblue' => 'sonstiges', 'kraftstoff' => 'kraftstoff', 'tanken' => 'kraftstoff',
    ];
    foreach ($map as $k => $c) if (str_contains($t, $k)) return $c;
    if (array_key_exists($t, COST_CATEGORIES)) return $t;
    foreach (COST_CATEGORIES as $k => [$label]) if (mb_strtolower($label) === $t) return $k;
    return 'sonstiges';
}

/**
 * Importiert eine CSV-Datei in ein Fahrzeug. Gibt [fuel, costs, skipped, type] zurück.
 */
function import_csv(array $vehicle, string $path, string $mode = 'auto'): array
{
    [$h, $rows] = csv_read($path);
    $vid = (int)$vehicle['id'];
    $cDate = csv_col($h, ['datum', 'date']);
    $cOdo = csv_col($h, ['kilometerstand', 'odometer', 'tachostand', 'km-stand', 'kilometer'], ['strecke', 'trip', '/100']);
    $cTrip = csv_col($h, ['strecke', 'trip', 'distance', 'gefahren']);
    $cQty = csv_col($h, ['menge', 'quantity', 'liter', 'getankt', 'kwh'], ['einheit', 'unit']);
    $cTotal = csv_col($h, ['gesamtpreis', 'gesamtkosten', 'betrag', 'kosten', 'cost', 'price', 'preis', 'amount'], ['/l', 'pro ', 'per ', 'einheit', 'währung', 'waehrung', 'currency', 'kostenart', 'cost type']);
    $cType = csv_col($h, ['tanktyp', 'betankungsart', 'typ', 'type', 'vollgetankt', 'voll'], ['kraftstoff', 'fuel', 'reifen', 'kosten', 'cost']);
    $cNote = csv_col($h, ['notiz', 'note', 'bemerkung', 'kommentar', 'comment']);
    $cCat = csv_col($h, ['kostenart', 'kategorie', 'category', 'cost type', 'costtype']);
    $cTitle = csv_col($h, ['titel', 'title', 'bezeichnung', 'beschreibung', 'description']);
    $cStation = csv_col($h, ['tankstelle', 'station', 'ort', 'location']);
    $cFuelSort = csv_col($h, ['kraftstoff', 'sorte', 'fuel']);
    $cKind = csv_col($h, ['art']);

    if ($cDate === null || $cTotal === null) {
        throw new RuntimeException('Spalten für Datum und Betrag nicht gefunden. Erkannt: ' . implode(', ', $h));
    }
    if ($mode === 'auto') $mode = $cQty !== null ? 'fuel' : 'costs';

    $fuel = $costs = $skipped = 0;
    db()->beginTransaction();
    try {
        foreach ($rows as $r) {
            $get = fn(?int $i) => $i === null ? null : trim((string)($r[$i] ?? ''));
            $date = date_or_null($get($cDate));
            $total = num($get($cTotal));
            if (!$date || $total === null) { $skipped++; continue; }
            $odo = int_or_null($get($cOdo));
            $note = str_or_null($get($cNote), 2000);
            // Garage-Export: Zeilen-Art beachten
            $rowKind = mb_strtolower((string)$get($cKind));
            $isFuel = $rowKind === 'tanken' || ($rowKind !== 'kosten' && $mode === 'fuel');
            if ($cQty !== null && $mode !== 'costs') {
                $qty = num($get($cQty));
                if ($qty && $rowKind !== 'kosten') {
                    if (val('SELECT id FROM fuelings WHERE vehicle_id = ? AND date = ? AND ABS(total - ?) < 0.01 AND ABS(quantity - ?) < 0.01', [$vid, $date, $total, $qty])) { $skipped++; continue; }
                    $type = mb_strtolower((string)$get($cType));
                    $full = !preg_match('/teil|not ?full|partial|nein|^0$|false/', $type);
                    $missed = (bool)preg_match('/ungültig|invalid|erste|first|fehl/', $type);
                    insert('fuelings', [
                        'vehicle_id' => $vid, 'date' => $date, 'odometer' => $odo, 'trip' => num($get($cTrip)), 'quantity' => $qty, 'total' => $total,
                        'full_tank' => (int)$full, 'missed_before' => (int)$missed, 'fuel_sort' => str_or_null($get($cFuelSort), 30),
                        'station' => str_or_null($get($cStation), 120), 'note' => $note, 'source' => 'csv', 'created_at' => now(),
                    ]);
                    $fuel++;
                    continue;
                }
            }
            if ($isFuel && $mode === 'fuel') { $skipped++; continue; }
            if ($total == 0.0) { $skipped++; continue; }
            $catText = (string)($get($cCat) ?? '');
            $title = str_or_null($get($cTitle), 160) ?? str_or_null($catText, 160) ?? str_or_null($note, 160) ?? 'Import';
            $cat = guess_category($catText . ' ' . $title);
            if (val('SELECT id FROM costs WHERE vehicle_id = ? AND date = ? AND ABS(amount - ?) < 0.01 AND title = ?', [$vid, $date, $total, $title])) { $skipped++; continue; }
            insert('costs', [
                'vehicle_id' => $vid, 'date' => $date, 'category' => $cat, 'title' => $title, 'amount' => $total, 'odometer' => $odo,
                'note' => $note !== $title ? $note : null, 'source' => 'csv', 'created_at' => now(),
            ]);
            $costs++;
        }
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
    return [$fuel, $costs, $skipped, $mode];
}

/* =========================================================
   Spritmonitor-API  (https://api.spritmonitor.de/v1)
   ========================================================= */

function sm_request(string $token, string $path): array
{
    $appId = (string)cfg('spritmonitor_app_id', '');
    if ($appId === '') throw new RuntimeException('In der config.php fehlt die Spritmonitor Application-ID (beim Spritmonitor-Support anfragen).');
    $ch = curl_init('https://api.spritmonitor.de/v1' . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Application-ID: ' . $appId,
            'Accept: application/json',
            'User-Agent: Garage/' . APP_VERSION,
        ],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false) throw new RuntimeException('Spritmonitor nicht erreichbar: ' . $err);
    if ($code === 401 || $code === 403) throw new RuntimeException('Spritmonitor lehnt den Zugriff ab – bitte Token prüfen.');
    if ($code >= 400) throw new RuntimeException("Spritmonitor-Fehler $code: " . mb_substr((string)$body, 0, 200));
    $json = json_decode((string)$body, true);
    if (!is_array($json)) throw new RuntimeException('Unerwartete Antwort von Spritmonitor.');
    if (isset($json['errors']) && $json['errors']) throw new RuntimeException('Spritmonitor: ' . json_encode($json['errormessages'] ?? $json['errors']));
    return $json;
}

function sm_vehicles(string $token): array
{
    return sm_request($token, '/vehicles.json');
}

/** Holt alle Einträge seitenweise */
function sm_all(string $token, string $path): array
{
    $all = [];
    $offset = 0;
    do {
        $page = sm_request($token, $path . (str_contains($path, '?') ? '&' : '?') . "limit=200&offset=$offset");
        $page = array_values(array_filter($page, 'is_array'));
        $all = array_merge($all, $page);
        $offset += 200;
    } while (count($page) === 200 && $offset < 20000);
    return $all;
}

function sm_pick(array $a, array $keys)
{
    foreach ($keys as $k) if (isset($a[$k]) && $a[$k] !== '') return $a[$k];
    return null;
}

/** Abgleich eines verknüpften Fahrzeugs. Gibt [neue Tankungen, neue Kosten] zurück. */
function sm_sync_vehicle(array $vehicle, string $token): array
{
    $vid = (int)$vehicle['id'];
    $smId = (string)$vehicle['sm_vehicle_id'];
    $nf = 0; $nc = 0;

    $costTypes = [];
    try {
        foreach (sm_request($token, '/costtypes.json') as $ct) {
            if (is_array($ct) && isset($ct['id'])) $costTypes[(string)$ct['id']] = (string)($ct['name'] ?? '');
        }
    } catch (Throwable $e) { /* optional */ }

    foreach (sm_all($token, "/vehicle/$smId/fuelings.json") as $f) {
        $ext = (string)sm_pick($f, ['id', 'fuelingid']);
        if ($ext === '' || val('SELECT id FROM fuelings WHERE vehicle_id = ? AND ext_id = ?', [$vid, 'sm:' . $ext])) continue;
        $type = (string)sm_pick($f, ['type', 'fuelingtype']);
        $qty = num(sm_pick($f, ['quantity', 'amount']));
        $total = num(sm_pick($f, ['cost', 'price', 'total', 'costs']));
        $date = date_or_null((string)sm_pick($f, ['date']));
        if (!$qty || $total === null || !$date) continue;
        insert('fuelings', [
            'vehicle_id' => $vid, 'date' => $date, 'odometer' => int_or_null(sm_pick($f, ['odometer'])), 'trip' => num(sm_pick($f, ['trip'])),
            'quantity' => $qty, 'total' => $total, 'full_tank' => (int)($type !== 'notfull'), 'missed_before' => (int)in_array($type, ['first', 'invalid'], true),
            'station' => str_or_null((string)sm_pick($f, ['location', 'stationname', 'station']), 120), 'note' => str_or_null((string)sm_pick($f, ['note']), 2000),
            'source' => 'spritmonitor', 'ext_id' => 'sm:' . $ext, 'created_at' => now(),
        ]);
        $nf++;
    }

    foreach (sm_all($token, "/vehicle/$smId/costsnotes.json") as $c) {
        $ext = (string)sm_pick($c, ['id', 'costnoteid']);
        if ($ext === '' || val('SELECT id FROM costs WHERE vehicle_id = ? AND ext_id = ?', [$vid, 'sm:' . $ext])) continue;
        $amount = num(sm_pick($c, ['cost', 'price', 'amount']));
        $date = date_or_null((string)sm_pick($c, ['date']));
        if (!$amount || !$date) continue; // reine Notizen überspringen
        $typeName = $costTypes[(string)sm_pick($c, ['costtype', 'costtypeid'])] ?? (string)sm_pick($c, ['costtypename']);
        $note = (string)sm_pick($c, ['note']);
        $title = str_or_null(sm_pick($c, ['title']) ?? ($typeName ?: $note), 160) ?? 'Spritmonitor';
        insert('costs', [
            'vehicle_id' => $vid, 'date' => $date, 'category' => guess_category($typeName . ' ' . $note . ' ' . $title), 'title' => $title, 'amount' => $amount,
            'odometer' => int_or_null(sm_pick($c, ['odometer'])), 'note' => $note !== $title ? str_or_null($note, 2000) : null,
            'source' => 'spritmonitor', 'ext_id' => 'sm:' . $ext, 'created_at' => now(),
        ]);
        $nc++;
    }
    return [$nf, $nc];
}

/** Optional: neue Tankung zusätzlich an Spritmonitor senden */
function sm_push_fueling(array $vehicle, array $f, string $token): void
{
    $tank = $vehicle['sm_tank_id'] ?: '1';
    $q = http_build_query([
        'date' => date('d.m.Y', strtotime($f['date'])), 'type' => $f['full_tank'] ? 'full' : 'notfull',
        'odometer' => $f['odometer'], 'trip' => $f['trip'], 'quantity' => $f['quantity'], 'price' => $f['total'],
        'currencyid' => 0, 'quantityunitid' => is_electric($vehicle) ? 5 : 1, 'note' => $f['note'],
    ]);
    sm_request($token, '/vehicle/' . $vehicle['sm_vehicle_id'] . '/tank/' . $tank . '/fueling.json?' . $q);
}

/* =========================================================
   MySkoda-Daten (kommen per API vom Sync-Skript)
   ========================================================= */

/**
 * $d: vin, mileage_km, inspection_due_days, inspection_due_km, oil_due_days, oil_due_km, captured_at
 */
function apply_skoda_report(array $user, array $d): array
{
    $vin = mb_strtoupper(trim((string)($d['vin'] ?? '')));
    $v = $vin ? row('SELECT * FROM vehicles WHERE user_id = ? AND UPPER(vin) = ?', [$user['id'], $vin]) : null;
    if (!$v) {
        // Fallback: einziges Auto des Nutzers
        $cars = rows("SELECT * FROM vehicles WHERE user_id = ? AND type = 'auto' AND archived = 0", [$user['id']]);
        if (count($cars) === 1) $v = $cars[0];
    }
    if (!$v) throw new RuntimeException('Kein passendes Fahrzeug gefunden – bitte die FIN im Fahrzeug hinterlegen.');
    $vid = (int)$v['id'];
    $out = ['vehicle' => $v['name']];
    $date = date_or_null((string)($d['captured_at'] ?? '')) ?? today();

    $km = isset($d['mileage_km']) ? (int)$d['mileage_km'] : null;
    if ($km) {
        $last = (int)val('SELECT MAX(km) FROM odometer_log WHERE vehicle_id = ?', [$vid]);
        $today = val("SELECT id FROM odometer_log WHERE vehicle_id = ? AND source = 'skoda' AND date = ?", [$vid, $date]);
        if ($today) { update('odometer_log', ['km' => $km], 'id = ?', [$today]); }
        elseif ($km > $last) { insert('odometer_log', ['vehicle_id' => $vid, 'date' => $date, 'km' => $km, 'source' => 'skoda', 'note' => 'MySkoda', 'created_at' => now()]); }
        $out['mileage_km'] = $km;
    }

    foreach ([['inspection', 'inspektion', 'Inspektion (laut Fahrzeug)', 'wrench'], ['oil', 'oel', 'Ölservice (laut Fahrzeug)', 'droplet']] as [$key, $kind, $title]) {
        $days = $d[$key . '_due_days'] ?? null;
        $dkm = $d[$key . '_due_km'] ?? null;
        if ($days === null && $dkm === null) continue;
        $dueDate = $days !== null ? date('Y-m-d', strtotime(today() . ' ' . ((int)$days >= 0 ? '+' : '') . (int)$days . ' days')) : null;
        $dueKm = ($dkm !== null && $km) ? $km + (int)$dkm : null;
        $source = 'skoda_' . $key;
        $r = row('SELECT * FROM reminders WHERE vehicle_id = ? AND source = ? AND done_at IS NULL', [$vid, $source]);
        $data = ['due_date' => $dueDate, 'due_km' => $dueKm];
        if ($r) {
            // Wenn die Werkstatt den Service zurückgesetzt hat (Restlaufzeit springt hoch), alte Frist erledigen
            if ($r['due_date'] && $dueDate && strtotime($dueDate) - strtotime($r['due_date']) > 180 * 86400) {
                update('reminders', ['done_at' => now()], 'id = ?', [$r['id']]);
                $r = null;
            } else {
                $changed = $r['due_date'] !== $dueDate || (string)$r['due_km'] !== (string)$dueKm;
                update('reminders', $data + ($changed ? ['notified_at' => null, 'notified_stage' => null] : []), 'id = ?', [$r['id']]);
            }
        }
        if (!$r) {
            insert('reminders', $data + ['vehicle_id' => $vid, 'kind' => $kind, 'title' => $title, 'warn_days' => 30, 'warn_km' => 1500, 'source' => $source, 'created_at' => now()]);
        }
        $out[$key] = ['due_date' => $dueDate, 'due_km' => $dueKm];
    }
    log_sync('skoda', 'Abgleich ' . $v['name'] . ($km ? ': ' . fmt_km($km) : ''), (int)$user['id']);
    setting_set('skoda_last_' . $user['id'], now());
    return $out;
}
