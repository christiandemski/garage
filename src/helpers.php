<?php
declare(strict_types=1);

function cfg(string $key, $default = null)
{
    return $GLOBALS['CONFIG'][$key] ?? $default;
}

function e($s): string
{
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function now(): string
{
    return gmdate('Y-m-d H:i:s');
}
function today(): string
{
    return date('Y-m-d');
}

function url(string $page = '', array $params = []): string
{
    $base = rtrim((string)cfg('base_url', ''), '/');
    if ($page !== '') $params = ['p' => $page] + $params;
    $qs = $params ? '?' . http_build_query($params) : '';
    return ($base === '' ? '' : $base) . '/index.php' . $qs;
}
function asset(string $path): string
{
    $file = APP_ROOT . '/assets/' . $path;
    $v = is_file($file) ? substr(md5((string)filemtime($file)), 0, 8) : '1';
    return rtrim((string)cfg('base_url', ''), '/') . '/assets/' . $path . '?v=' . $v;
}

function redirect(string $to): never
{
    header('Location: ' . $to);
    exit;
}
/** Nur app-interne Ziele zulassen (Schutz vor Open Redirect), sonst $fallback */
function safe_target($to, string $fallback): string
{
    $to = (string)($to ?? '');
    if ($to === '' || preg_match('/[\x00-\x1f\x7f\\\\]/', $to)) return $fallback;
    $base = rtrim((string)cfg('base_url', ''), '/');
    if ($base !== '' && str_starts_with($to, $base . '/')) $to = substr($to, strlen($base));
    if (!str_starts_with($to, '/') || str_starts_with($to, '//')) return $fallback;
    return $to;
}
function back(string $fallback): never
{
    redirect(safe_target($_POST['_back'] ?? $_GET['back'] ?? '', $fallback));
}

function flash(string $msg, string $type = 'ok'): void
{
    $_SESSION['flash'][] = ['m' => $msg, 't' => $type];
}
function flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
    return $_SESSION['csrf'];
}
function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}
function csrf_check(): void
{
    if (!hash_equals(csrf_token(), (string)($_POST['_csrf'] ?? ''))) {
        http_response_code(419);
        flash('Die Sitzung ist abgelaufen. Bitte noch einmal versuchen.', 'err');
        redirect($_SERVER['REQUEST_URI'] ?? url());
    }
}
function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** "1.234,56" oder "1234.56" → 1234.56 */
/** $thousands: "27.500" als 27500 deuten (für km und größere Beträge) */
function num($v, bool $thousands = false): ?float
{
    if ($v === null) return null;
    $v = trim((string)$v);
    if ($v === '') return null;
    $v = str_replace([' ', '€', "\u{00A0}"], '', $v);
    if ($thousands && !str_contains($v, ',') && preg_match('/^-?\d{1,3}(\.\d{3})+$/', $v)) {
        $v = str_replace('.', '', $v); // 27.500 → 27500
    } elseif (str_contains($v, ',')) {
        $v = str_replace('.', '', $v);
        $v = str_replace(',', '.', $v);
    }
    return is_numeric($v) ? (float)$v : null;
}
function int_or_null($v): ?int
{
    $n = num($v, true);
    return $n === null ? null : (int)round($n);
}
function str_or_null($v, int $max = 255): ?string
{
    $v = trim((string)($v ?? ''));
    return $v === '' ? null : mb_substr($v, 0, $max);
}
function date_or_null($v): ?string
{
    $v = trim((string)($v ?? ''));
    if ($v === '') return null;
    if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{2,4})$/', $v, $m)) {
        $y = strlen($m[3]) === 2 ? '20' . $m[3] : $m[3];
        return sprintf('%04d-%02d-%02d', $y, $m[2], $m[1]);
    }
    $t = strtotime($v);
    return $t ? date('Y-m-d', $t) : null;
}

function fmt_num($n, int $dec = 0): string
{
    if ($n === null || $n === '') return '–';
    return number_format((float)$n, $dec, ',', '.');
}
function fmt_money($n, bool $cents = true): string
{
    if ($n === null || $n === '') return '–';
    return number_format((float)$n, $cents ? 2 : 0, ',', '.') . ' €';
}
function fmt_km($n): string
{
    return $n === null ? '–' : fmt_num($n) . ' km';
}
function fmt_date(?string $d, bool $short = false): string
{
    if (!$d) return '–';
    $t = strtotime($d);
    return $short ? date('d.m.y', $t) : date('d.m.Y', $t);
}
const MONTHS_DE = ['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];
const MONTHS_DE_LONG = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
function fmt_month(string $ym, bool $long = false): string
{
    [$y, $m] = explode('-', $ym);
    return ($long ? MONTHS_DE_LONG : MONTHS_DE)[(int)$m - 1] . ($long ? ' ' . $y : ' ' . substr($y, 2));
}
/** Kurzes Monatslabel für Diagramme: "Nov", im Januar mit Jahr */
function fmt_month_short(string $ym): string
{
    [$y, $m] = explode('-', $ym);
    return MONTHS_DE[(int)$m - 1] . ((int)$m === 1 ? ' ' . substr($y, 2) : '');
}
function rel_days(?string $date): string
{
    if (!$date) return '';
    $d = (int)floor((strtotime($date) - strtotime(today())) / 86400);
    if ($d === 0) return 'heute';
    if ($d === 1) return 'morgen';
    if ($d === -1) return 'gestern';
    if ($d < 0) return 'seit ' . fmt_days(-$d);
    return 'in ' . fmt_days($d);
}
function fmt_days(int $d): string
{
    if ($d >= 60) return round($d / 30.4) . ' Monaten';
    if ($d >= 14) return round($d / 7) . ' Wochen';
    return $d . ' Tag' . ($d === 1 ? '' : 'en');
}
function add_months(string $date, int $months): string
{
    $d = new DateTime($date);
    $day = (int)$d->format('d');
    $d->modify('first day of this month')->modify("+$months months");
    $last = (int)$d->format('t');
    $d->setDate((int)$d->format('Y'), (int)$d->format('m'), min($day, $last));
    return $d->format('Y-m-d');
}

/* ---------- Stammdaten ---------- */

const VEHICLE_TYPES = [
    'auto'      => 'Auto',
    'fahrrad'   => 'Fahrrad',
    'ebike'     => 'E-Bike',
    'motorrad'  => 'Motorrad',
    'anhaenger' => 'Anhänger',
    'sonstiges' => 'Sonstiges',
];
const MOTOR_TYPES = ['auto', 'motorrad'];
// Für Kategorien: zu welchen Fahrzeugtypen sie passen (ohne Angabe: zu allen)
const T_BIKE = ['fahrrad', 'ebike'];
const T_FUEL = ['auto', 'motorrad', 'sonstiges'];
const T_ROAD = ['auto', 'motorrad', 'anhaenger', 'sonstiges'];

const FUEL_TYPES = [
    'benzin'  => 'Benzin',
    'diesel'  => 'Diesel',
    'elektro' => 'Elektro',
    'hybrid'  => 'Hybrid (Benzin)',
    'phev'    => 'Plug-in-Hybrid',
    'lpg'     => 'Autogas (LPG)',
    'cng'     => 'Erdgas (CNG)',
];

const COST_CATEGORIES = [
    'kraftstoff'   => ['Kraftstoff', '#0ea5a4', T_FUEL],
    'wartung'      => ['Wartung & Inspektion', '#6366f1'],
    'reparatur'    => ['Reparatur', '#ef4444'],
    'reifen'       => ['Reifen', '#f59e0b'],
    'teile'        => ['Teile & Verschleiß', '#f97316'],
    'pruefung'     => ['HU/AU & Prüfungen', '#8b5cf6', T_ROAD],
    'versicherung' => ['Versicherung', '#3b82f6'],
    'steuer'       => ['Kfz-Steuer', '#64748b', T_ROAD],
    'finanzierung' => ['Finanzierung & Leasing', '#a855f7'],
    'pflege'       => ['Pflege & Wäsche', '#06b6d4'],
    'parken'       => ['Parken & Maut', '#84cc16', T_FUEL],
    'zubehoer'     => ['Zubehör & Ausrüstung', '#ec4899'],
    'sonstiges'    => ['Sonstiges', '#94a3b8'],
];
function cat_label(string $c): string { return COST_CATEGORIES[$c][0] ?? ucfirst($c); }
function cat_color(string $c): string { return COST_CATEGORIES[$c][1] ?? '#94a3b8'; }

const REMINDER_KINDS = [
    'hu'             => ['HU/AU (TÜV)', 'shield', 24, null, T_ROAD],
    'inspektion'     => ['Inspektion', 'wrench', 24, 30000],
    'oel'            => ['Ölwechsel', 'droplet', 12, 15000, T_FUEL],
    'reifen'         => ['Reifenwechsel', 'tire', 6, null, T_FUEL],
    'bremsfluessig'  => ['Bremsflüssigkeit', 'droplet', 24, null, ['auto', 'motorrad', 'fahrrad', 'ebike', 'sonstiges']],
    'verbandskasten' => ['Verbandskasten', 'cross', 60, null, ['auto', 'sonstiges']],
    'versicherung'   => ['Versicherung', 'umbrella', 12, null],
    'steuer'         => ['Kfz-Steuer', 'receipt', 12, null, T_ROAD],
    'service'        => ['Service', 'wrench', 12, null],
    'sonstiges'      => ['Sonstiges', 'bell', null, null],
];
function kind_label(string $k): string { return REMINDER_KINDS[$k][0] ?? 'Sonstiges'; }

const PART_CATEGORIES = [
    'reifen'      => ['Reifen', 6000],
    'kette'       => ['Kette', 3000, ['fahrrad', 'ebike', 'motorrad', 'sonstiges']],
    'kassette'    => ['Kassette', 10000, ['fahrrad', 'ebike', 'sonstiges']],
    'kettenblatt' => ['Kettenblatt', 15000, ['fahrrad', 'ebike', 'motorrad', 'sonstiges']],
    'bremsbelag'  => ['Bremsbeläge', 3000],
    'bremsscheibe'=> ['Bremsscheibe', 15000],
    'schlauch'    => ['Schlauch/Dichtmilch', null, ['fahrrad', 'ebike', 'motorrad', 'sonstiges']],
    'zuege'       => ['Züge & Hüllen', 10000, ['fahrrad', 'ebike', 'motorrad', 'sonstiges']],
    'lenkerband'  => ['Lenkerband/Griffe', null, ['fahrrad', 'ebike', 'motorrad', 'sonstiges']],
    'gabel'       => ['Gabel/Dämpfer-Service', null, ['fahrrad', 'ebike', 'motorrad', 'sonstiges']],
    'akku'        => ['Akku/Batterie', null, ['auto', 'motorrad', 'ebike', 'sonstiges']],
    'licht'       => ['Licht', null],
    'wischer'     => ['Scheibenwischer', null, ['auto', 'sonstiges']],
    'filter'      => ['Filter', 15000, ['auto', 'motorrad', 'sonstiges']],
    'sonstiges'   => ['Sonstiges', null],
];
function part_label(string $c): string { return PART_CATEGORIES[$c][0] ?? 'Sonstiges'; }

/**
 * Filtert COST_CATEGORIES / REMINDER_KINDS / PART_CATEGORIES auf die Einträge, die zum Fahrzeugtyp passen.
 * $idx = Position der Typ-Liste im Eintrag; $keep bleibt immer drin (z. B. der bereits gespeicherte Wert).
 */
function for_vehicle(array $list, array $v, int $idx, ?string $keep = null): array
{
    return array_filter($list, fn($e, $k) => $k === $keep || !isset($e[$idx]) || in_array($v['type'], $e[$idx], true), ARRAY_FILTER_USE_BOTH);
}

const DOC_KINDS = [
    'fahrzeugschein' => 'Fahrzeugschein',
    'fahrzeugbrief'  => 'Fahrzeugbrief',
    'rechnung'       => 'Rechnung',
    'hu'             => 'HU-Bericht',
    'versicherung'   => 'Versicherung',
    'kaufvertrag'    => 'Kaufvertrag',
    'garantie'       => 'Garantie',
    'anleitung'      => 'Anleitung',
    'foto'           => 'Foto',
    'sonstiges'      => 'Sonstiges',
];

function is_motor(array $v): bool
{
    return in_array($v['type'], MOTOR_TYPES, true);
}
function is_electric(array $v): bool
{
    return ($v['fuel_type'] ?? '') === 'elektro';
}
function fuel_unit(array $v): string
{
    return is_electric($v) ? 'kWh' : 'l';
}

/* ---------- Mail ---------- */

function send_mail(string $to, string $subject, string $text, ?string $html = null): bool
{
    $from = (string)cfg('mail_from', 'garage@localhost');
    $name = (string)cfg('mail_from_name', 'Garage');
    $boundary = 'b' . bin2hex(random_bytes(8));
    $headers = [
        'From: ' . mb_encode_mimeheader($name) . " <$from>",
        'Reply-To: ' . $from,
        'MIME-Version: 1.0',
    ];
    if ($html) {
        $headers[] = "Content-Type: multipart/alternative; boundary=\"$boundary\"";
        $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n$text\r\n"
            . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n$html\r\n--$boundary--";
    } else {
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $body = $text;
    }
    $ok = false;
    if (function_exists('mail')) {
        $ok = @mail($to, mb_encode_mimeheader($subject), $body, implode("\r\n", $headers), '-f' . $from);
    }
    if (!$ok || cfg('debug')) {
        @file_put_contents(APP_ROOT . '/data/mail.log', '[' . date('c') . "] To: $to | $subject\n$text\n\n", FILE_APPEND);
    }
    return $ok;
}

function mail_html(string $title, string $bodyHtml, ?string $button = null, ?string $link = null): string
{
    $btn = $button && $link ? '<p style="margin:28px 0"><a href="' . e($link) . '" style="background:#0f766e;color:#fff;padding:12px 22px;border-radius:10px;text-decoration:none;font-weight:600">' . e($button) . '</a></p>' : '';
    return '<!doctype html><html><body style="margin:0;background:#f4f5f7;font-family:-apple-system,Segoe UI,Roboto,sans-serif;color:#111">'
        . '<div style="max-width:520px;margin:32px auto;background:#fff;border-radius:16px;padding:32px">'
        . '<div style="font-weight:700;font-size:18px;margin-bottom:20px">Garage</div>'
        . '<h1 style="font-size:20px;margin:0 0 12px">' . e($title) . '</h1>' . $bodyHtml . $btn
        . '<p style="color:#888;font-size:12px;margin-top:32px">Diese E-Mail wurde automatisch von deiner Garage-App verschickt.</p>'
        . '</div></body></html>';
}

function client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}
