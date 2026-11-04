<?php
declare(strict_types=1);

// Lokaler PHP-Server (php -S … index.php): vorhandene Dateien direkt ausliefern
if (PHP_SAPI === 'cli-server') {
    $f = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if ($f !== '/index.php' && is_file(__DIR__ . $f)) return false;
}

require __DIR__ . '/src/bootstrap.php';

$routes = [
    // ohne Anmeldung
    'login' => false, 'register' => false, 'verify' => false, 'forgot' => false, 'reset' => false, 'logout' => false,
    // mit Anmeldung
    'dashboard' => true, 'vehicles' => true, 'autos' => true, 'fahrraeder' => true, 'vehicle' => true, 'vehicle_edit' => true,
    'fuel_edit' => true, 'cost_edit' => true, 'odo_edit' => true, 'reminder_edit' => true, 'reminder_done' => true,
    'part_edit' => true, 'doc_edit' => true, 'file' => true,
    'bike_service' => true, 'service_edit' => true, 'bike_stock' => true, 'stock_edit' => true, 'bike_docs' => true,
    'costs' => true, 'reminders' => true, 'documents' => true, 'more' => true,
    'settings' => true, 'import' => true, 'export' => true,
];

if (isset($_GET['p'])) {
    // Alte Adressen (index.php?p=…) dauerhaft auf die neuen umleiten
    $page = (string)$_GET['p'];
    if (!isset($routes[$page])) $page = 'dashboard';
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $q = $_GET;
        unset($q['p']);
        header('Location: ' . url($page === 'vehicles' ? 'autos' : $page, $q), true, 301);
        exit;
    }
} else {
    [$page, $params] = route_request((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
    $_GET = $params + $_GET;
}
if ($page === '404') {
    require_login();
    not_found();
}

if ($routes[$page]) {
    require_login();
    materialize_recurring();
} elseif (current_user() && in_array($page, ['login', 'register'], true)) {
    redirect(url());
}

if (isset($_GET['v'])) $GLOBALS['CURRENT_VEHICLE'] = (int)$_GET['v'];

$file = __DIR__ . '/pages/' . (isset(VEHICLE_GROUPS[$page]) ? 'vehicles' : (in_array($page, ['documents', 'bike_docs'], true) ? 'area_docs' : (str_starts_with($page, 'login') || in_array($page, ['register', 'verify', 'forgot', 'reset', 'logout'], true) ? 'auth' : $page))) . '.php';
require $file;
