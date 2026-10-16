<?php
/**
 * Täglicher Cronjob:
 *  - E-Mail-Erinnerungen für fällige Fristen und Verschleißteile
 *  - Spritmonitor-Abgleich für verknüpfte Fahrzeuge
 *  - Aufräumen (abgelaufene Anmelde-Token)
 *
 * Aufruf per CLI:  php /pfad/zu/garage/cron/run.php
 * oder per URL:    https://deine-domain/cron/run.php?key=<cron_key aus config.php>
 */
declare(strict_types=1);

define('NO_SESSION', true);
require dirname(__DIR__) . '/src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    $key = (string)cfg('cron_key', '');
    if (strlen($key) < 16 || !hash_equals($key, (string)($_GET['key'] ?? ''))) {
        http_response_code(403);
        exit("Zugriff verweigert\n");
    }
}

$log = function (string $m) { echo '[' . date('H:i:s') . "] $m\n"; };
q('DELETE FROM remember_tokens WHERE expires < ?', [now()]);

foreach (rows('SELECT * FROM users') as $user) {
    $_SESSION['uid'] = (int)$user['id'];

    // 1) Wiederkehrende Kosten
    materialize_recurring((int)$user['id']);

    // 2) Spritmonitor
    if ($user['sm_token'] && cfg('spritmonitor_app_id')) {
        foreach (rows('SELECT * FROM vehicles WHERE user_id = ? AND sm_vehicle_id IS NOT NULL AND archived = 0', [$user['id']]) as $v) {
            try {
                [$f, $c] = sm_sync_vehicle($v, $user['sm_token']);
                if ($f || $c) log_sync('spritmonitor', "Automatisch: $f Tankungen, $c Kosten ({$v['name']})", (int)$user['id']);
                $log("Spritmonitor {$user['email']} / {$v['name']}: +$f / +$c");
            } catch (Throwable $e) {
                $log('Spritmonitor-Fehler: ' . $e->getMessage());
            }
        }
    }

    // 3) Erinnerungen
    if (!$user['notify_email']) continue;
    $items = [];
    foreach (rows('SELECT * FROM vehicles WHERE user_id = ? AND archived = 0', [$user['id']]) as $v) {
        $km = current_km($v);
        foreach (rows('SELECT * FROM reminders WHERE vehicle_id = ? AND done_at IS NULL', [$v['id']]) as $r) {
            $st = reminder_status($r, $km);
            if ($st['status'] === 'ok') continue;
            // pro Frist: einmal bei "bald", einmal bei "überfällig"
            $stage = $st['status'];
            $lastStage = (string)($r['notified_stage'] ?? '');
            if ($lastStage === $stage || $lastStage === 'overdue') continue;
            $items[] = ['v' => $v, 'title' => $r['title'], 'text' => $st['text'], 'status' => $stage];
            update('reminders', ['notified_at' => now(), 'notified_stage' => $stage], 'id = ?', [$r['id']]);
        }
    }
    if (!$items) continue;
    $base = rtrim((string)cfg('base_url'), '/');
    $text = "Hallo" . ($user['name'] ? ' ' . $user['name'] : '') . ",\n\nfolgende Termine stehen an:\n\n";
    $html = '<p>Folgende Termine stehen an:</p><table style="width:100%;border-collapse:collapse">';
    foreach ($items as $i) {
        $label = $i['status'] === 'overdue' ? 'ÜBERFÄLLIG' : 'bald fällig';
        $text .= "• {$i['v']['name']}: {$i['title']} – {$i['text']} ($label)\n";
        $color = $i['status'] === 'overdue' ? '#dc2626' : '#c2410c';
        $html .= '<tr><td style="padding:10px 0;border-bottom:1px solid #eee"><strong>' . e($i['title']) . '</strong><br><span style="color:#666">' . e($i['v']['name'] . ' · ' . $i['text']) . '</span></td><td style="padding:10px 0;border-bottom:1px solid #eee;text-align:right;color:' . $color . ';font-weight:600;white-space:nowrap">' . e($label) . '</td></tr>';
    }
    $html .= '</table>';
    $text .= "\nZur App: $base/\n";
    $ok = send_mail($user['email'], count($items) === 1 ? 'Garage: ' . $items[0]['title'] . ' steht an' : 'Garage: ' . count($items) . ' Termine stehen an', $text, mail_html('Anstehende Termine', $html, 'Garage öffnen', $base . '/'));
    $log("Erinnerung an {$user['email']}: " . count($items) . ' Einträge' . ($ok ? '' : ' (Versand fehlgeschlagen)'));
}
$log('Fertig.');
