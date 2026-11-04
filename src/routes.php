<?php
declare(strict_types=1);

/* Schöne Adressen (/autos/fristen, /fahrzeug/3/kosten, /kosten/neu …) – alles läuft über index.php */

/** Seiten mit fester Adresse */
const PAGE_PATHS = [
    'dashboard' => '', 'autos' => 'autos', 'reminders' => 'autos/fristen', 'documents' => 'autos/dokumente',
    'fahrraeder' => 'fahrraeder', 'bike_service' => 'fahrraeder/service', 'bike_stock' => 'fahrraeder/lager', 'bike_docs' => 'fahrraeder/dokumente',
    'costs' => 'kosten', 'more' => 'mehr', 'settings' => 'einstellungen', 'import' => 'import', 'export' => 'export', 'file' => 'datei',
    'login' => 'anmelden', 'register' => 'registrieren', 'verify' => 'bestaetigen', 'forgot' => 'passwort-vergessen', 'reset' => 'passwort-neu', 'logout' => 'abmelden',
];
/** Einzelne Einträge: /<basis>/neu bzw. /<basis>/<id> */
const ITEM_PATHS = [
    'cost_edit' => 'kosten', 'fuel_edit' => 'tanken', 'odo_edit' => 'kilometer', 'reminder_edit' => 'fristen', 'part_edit' => 'teile',
    'doc_edit' => 'dokumente', 'service_edit' => 'service', 'stock_edit' => 'lager',
];
/** Reiter der Fahrzeugseite: /fahrzeug/<id>/<reiter> */
const TAB_PATHS = ['fuel' => 'tanken', 'costs' => 'kosten', 'service' => 'service', 'reminders' => 'fristen', 'parts' => 'teile', 'km' => 'kilometer', 'docs' => 'dokumente', 'data' => 'daten'];

/** ID des einzigen Autos (nur mit Anmeldung) – dessen Seite heißt dann einfach /auto */
function single_car_id(): ?int
{
    if (!function_exists('current_user') || !current_user()) return null;
    $car = single_car();
    return $car ? (int)$car['id'] : null;
}

function base_path(): string
{
    return rtrim((string)parse_url((string)cfg('base_url', ''), PHP_URL_PATH), '/');
}

function url(string $page = '', array $params = []): string
{
    $page = $page === '' ? 'dashboard' : $page;
    $id = isset($params['id']) && $params['id'] !== '' ? (string)$params['id'] : null;
    if ($page === 'vehicle' || $page === 'vehicle_edit') {
        if ($id === null) {
            $path = $page === 'vehicle_edit' ? 'fahrzeug/neu' : 'autos';
        } else {
            $root = $page === 'vehicle' && (int)$id === single_car_id() ? 'auto' : 'fahrzeug/' . rawurlencode($id);
            $seg = $page === 'vehicle_edit' ? 'bearbeiten' : (TAB_PATHS[$params['tab'] ?? ''] ?? '');
            $path = $root . ($seg !== '' ? '/' . $seg : '');
        }
        unset($params['id'], $params['tab']);
    } elseif ($page === 'reminder_done') {
        $path = 'fristen/' . rawurlencode($id ?? '0') . '/erledigt';
        unset($params['id']);
    } elseif (isset(ITEM_PATHS[$page])) {
        $path = ITEM_PATHS[$page] . '/' . ($id !== null ? rawurlencode($id) : 'neu');
        unset($params['id']);
    } else {
        $path = PAGE_PATHS[$page] ?? '';
    }
    $qs = $params ? '?' . http_build_query($params) : '';
    return rtrim((string)cfg('base_url', ''), '/') . '/' . $path . $qs;
}

/**
 * Adresse → [Seite, Parameter]. Unbekannte Adressen ergeben Seite '404'.
 */
function route_request(string $uriPath): array
{
    $path = rawurldecode($uriPath);
    $base = base_path();
    if ($base !== '' && str_starts_with($path, $base)) $path = substr($path, strlen($base));
    $path = trim($path, '/');
    if ($path === '' || $path === 'index.php') return ['dashboard', []];
    $s = explode('/', $path);
    $n = count($s);
    $isId = fn(int $i) => isset($s[$i]) && ctype_digit($s[$i]);

    $page = array_search($path, PAGE_PATHS, true);
    if ($page !== false) return [$page, []];
    if ($path === 'fristen') return ['reminders', []];

    // Das einzige Auto: /auto, /auto/<reiter>
    if ($s[0] === 'auto' && $n <= 2) {
        $car = single_car_id();
        if (!$car) return ['autos', []];
        $tab = $n === 2 ? array_search($s[1], TAB_PATHS, true) : 'overview';
        return $tab === false ? ['404', []] : ['vehicle', ['id' => $car, 'tab' => $tab]];
    }
    if ($s[0] === 'fahrzeug') {
        if ($n === 2 && $s[1] === 'neu') return ['vehicle_edit', []];
        if ($isId(1) && $n === 2) return ['vehicle', ['id' => $s[1]]];
        if ($isId(1) && $n === 3 && $s[2] === 'bearbeiten') return ['vehicle_edit', ['id' => $s[1]]];
        if ($isId(1) && $n === 3 && ($tab = array_search($s[2], TAB_PATHS, true)) !== false) return ['vehicle', ['id' => $s[1], 'tab' => $tab]];
        return ['404', []];
    }
    if ($s[0] === 'fristen' && $n === 3 && $isId(1) && $s[2] === 'erledigt') return ['reminder_done', ['id' => $s[1]]];
    $item = array_search($s[0], ITEM_PATHS, true);
    if ($item !== false && $n === 2) {
        if ($s[1] === 'neu') return [$item, []];
        if ($isId(1)) return [$item, ['id' => $s[1]]];
    }
    return ['404', []];
}
