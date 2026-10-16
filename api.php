<?php
/**
 * Garage – JSON-API
 * Authentifizierung: Header "Authorization: Bearer <API-Schlüssel>" (Einstellungen → API-Schlüssel)
 *
 *  GET  api.php?action=summary            Übersicht für Home-Dashboard
 *  GET  api.php?action=vehicles           Fahrzeuge mit Kilometerstand
 *  POST api.php?action=skoda              MySkoda-Bericht (JSON, siehe tools/skoda_sync.py)
 *  POST api.php?action=odometer           {"vehicle_id"|"vin", "km", "date"?, "note"?}
 *  POST api.php?action=trip               {"vehicle_id", "km" (Strecke), "date"?, "note"?}  z. B. für Fahrradtouren
 */
declare(strict_types=1);

define('NO_SESSION', true);
require __DIR__ . '/src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') exit;

function out($data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$user = api_user_from_request();
if (!$user) out(['error' => 'Ungültiger oder fehlender API-Schlüssel'], 401);
$_SESSION['uid'] = (int)$user['id'];

$action = $_GET['action'] ?? 'summary';
$body = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $raw = (string)file_get_contents('php://input');
    $body = json_decode($raw, true) ?: $_POST;
}

function find_vehicle(array $b): array
{
    if (!empty($b['vehicle_id'])) {
        $v = row('SELECT * FROM vehicles WHERE id = ? AND user_id = ?', [(int)$b['vehicle_id'], uid()]);
    } elseif (!empty($b['vin'])) {
        $v = row('SELECT * FROM vehicles WHERE UPPER(vin) = ? AND user_id = ?', [mb_strtoupper((string)$b['vin']), uid()]);
    } else {
        $v = null;
    }
    if (!$v) out(['error' => 'Fahrzeug nicht gefunden'], 404);
    return $v;
}

try {
    switch ($action) {
        case 'vehicles':
            out(array_map(fn($v) => ['id' => (int)$v['id'], 'name' => $v['name'], 'type' => $v['type'], 'plate' => $v['plate'], 'vin' => $v['vin'], 'km' => current_km($v)], vehicles()));

        case 'summary':
            $vs = vehicles();
            $ids = array_column($vs, 'id');
            $year = date('Y');
            $entries = cost_entries($ids, "$year-01-01", "$year-12-31");
            $items = array_map(fn($d) => ['vehicle' => $d['v']['name'], 'title' => $d['title'], 'status' => $d['status'], 'text' => $d['text'], 'due_date' => $d['r']['due_date'] ?? null], array_slice(due_items(), 0, 10));
            out([
                'generated_at' => date('c'),
                'costs_year' => round(array_sum(array_column($entries, 'amount')), 2),
                'vehicles' => array_map(function ($v) {
                    $c = is_motor($v) ? consumption_stats((int)$v['id']) : null;
                    return ['id' => (int)$v['id'], 'name' => $v['name'], 'type' => $v['type'], 'km' => current_km($v),
                        'consumption_avg' => $c && $c['avg'] ? round($c['avg'], 2) : null, 'unit' => fuel_unit($v)];
                }, $vs),
                'due' => $items,
            ]);

        case 'skoda':
            if (!$body) out(['error' => 'Leerer Bericht'], 400);
            out(['ok' => true, 'result' => apply_skoda_report($user, $body)]);

        case 'odometer':
            $v = find_vehicle($body);
            $km = int_or_null($body['km'] ?? null);
            if (!$km) out(['error' => 'km fehlt'], 400);
            $id = insert('odometer_log', ['vehicle_id' => $v['id'], 'date' => date_or_null((string)($body['date'] ?? '')) ?? today(), 'km' => $km, 'source' => 'api', 'note' => str_or_null($body['note'] ?? '', 200), 'created_at' => now()]);
            out(['ok' => true, 'id' => $id, 'km' => current_km($v)]);

        case 'trip':
            $v = find_vehicle($body);
            $trip = num($body['km'] ?? null);
            if (!$trip) out(['error' => 'km fehlt'], 400);
            $ext = str_or_null($body['external_id'] ?? '', 40);
            if ($ext && val("SELECT id FROM odometer_log WHERE vehicle_id = ? AND note LIKE ?", [$v['id'], '%[' . $ext . ']'])) out(['ok' => true, 'duplicate' => true]);
            $km = (int)round((current_km($v) ?? 0) + $trip);
            $note = trim((string)($body['note'] ?? '') . ($ext ? ' [' . $ext . ']' : ''));
            $id = insert('odometer_log', ['vehicle_id' => $v['id'], 'date' => date_or_null((string)($body['date'] ?? '')) ?? today(), 'km' => $km, 'trip' => $trip, 'source' => (string)($body['source'] ?? 'api') === 'garmin' ? 'garmin' : 'api', 'note' => mb_substr($note, 0, 200) ?: null, 'created_at' => now()]);
            out(['ok' => true, 'id' => $id, 'km' => $km]);

        default:
            out(['error' => 'Unbekannte Aktion'], 404);
    }
} catch (Throwable $e) {
    out(['error' => $e->getMessage()], 422);
}
