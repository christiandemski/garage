<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

$routes = [
    // ohne Anmeldung
    'login' => false, 'register' => false, 'verify' => false, 'forgot' => false, 'reset' => false, 'logout' => false,
    // mit Anmeldung
    'dashboard' => true, 'vehicles' => true, 'vehicle' => true, 'vehicle_edit' => true,
    'fuel_edit' => true, 'cost_edit' => true, 'odo_edit' => true, 'reminder_edit' => true, 'reminder_done' => true,
    'part_edit' => true, 'doc_edit' => true, 'file' => true,
    'costs' => true, 'reminders' => true, 'documents' => true, 'more' => true,
    'settings' => true, 'import' => true, 'export' => true,
];

$page = (string)($_GET['p'] ?? 'dashboard');
if (!isset($routes[$page])) $page = 'dashboard';

if ($routes[$page]) {
    require_login();
    materialize_recurring();
} elseif (current_user() && in_array($page, ['login', 'register'], true)) {
    redirect(url());
}

if (isset($_GET['v'])) $GLOBALS['CURRENT_VEHICLE'] = (int)$_GET['v'];

$file = __DIR__ . '/pages/' . (str_starts_with($page, 'login') || in_array($page, ['register', 'verify', 'forgot', 'reset', 'logout'], true) ? 'auth' : $page) . '.php';
require $file;
