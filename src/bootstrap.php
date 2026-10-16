<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('APP_VERSION', '1.0.0');

$configFile = APP_ROOT . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><title>Garage – Einrichtung</title><body style="font-family:system-ui;max-width:560px;margin:60px auto;padding:0 20px;line-height:1.5">'
        . '<h1>Fast geschafft</h1><p>Bitte <code>config.sample.php</code> nach <code>config.php</code> kopieren und die Datenbank-Zugangsdaten eintragen. Danach diese Seite neu laden.</p></body>';
    exit;
}
$GLOBALS['CONFIG'] = require $configFile;

require APP_ROOT . '/src/helpers.php';
require APP_ROOT . '/src/db.php';
require APP_ROOT . '/src/auth.php';
require APP_ROOT . '/src/model.php';
require APP_ROOT . '/src/icons.php';
require APP_ROOT . '/src/layout.php';
require APP_ROOT . '/src/components.php';
require APP_ROOT . '/src/importers.php';

date_default_timezone_set((string)cfg('timezone', 'Europe/Berlin'));
mb_internal_encoding('UTF-8');

if (cfg('debug')) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
}

if (!is_dir(APP_ROOT . '/data')) @mkdir(APP_ROOT . '/data', 0775, true);

if (PHP_SAPI !== 'cli' && !defined('NO_SESSION')) {
    // Sessions im eigenen Ordner, damit der Shared-Hosting-GC sie nicht zu früh löscht
    $sessDir = APP_ROOT . '/data/sessions';
    if (!is_dir($sessDir)) @mkdir($sessDir, 0770, true);
    if (is_writable($sessDir)) session_save_path($sessDir);
    ini_set('session.gc_maxlifetime', (string)(14 * 86400));
    ini_set('session.use_strict_mode', '1');
    session_name('garage_sid');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');
}

migrate();
