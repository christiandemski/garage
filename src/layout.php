<?php
declare(strict_types=1);

function page_header(string $title, array $o = []): void
{
    $u = current_user();
    $theme = $u['theme'] ?? 'system';
    $active = $o['nav'] ?? '';
    $back = $o['back'] ?? null;
    $bare = $o['bare'] ?? false;
    $GLOBALS['BARE'] = $bare;
    $GLOBALS['NAV'] = $active;
    ?>
<!doctype html>
<html lang="de" data-theme="<?= e($theme) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#f5f6f8" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#0c0d10" media="(prefers-color-scheme: dark)">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="Garage">
<title><?= e($title) ?> · Garage</title>
<link rel="manifest" href="<?= e(rtrim((string)cfg('base_url'), '/')) ?>/manifest.webmanifest">
<link rel="icon" href="<?= e(asset('icon.svg')) ?>" type="image/svg+xml">
<link rel="apple-touch-icon" href="<?= e(asset('icon-180.png')) ?>">
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
<script src="<?= e(asset('app.js')) ?>" defer></script>
</head>
<body class="<?= $bare ? 'bare' : '' ?>">
<?php if ($bare): ?>
<main class="auth-wrap">
<?php else: ?>
<div class="shell">
  <aside class="sidebar">
    <a class="brand" href="<?= e(url()) ?>"><span class="brand-mark"><?= icon('car') ?></span><span>Garage</span></a>
    <nav class="side-nav">
      <?= nav_link('dashboard', 'Übersicht', 'home', $active) ?>
      <?= nav_link('vehicles', 'Fahrzeuge', 'grid', $active) ?>
      <?= nav_link('costs', 'Kosten', 'pie', $active) ?>
      <?= nav_link('reminders', 'Fristen', 'bell', $active) ?>
      <?= nav_link('documents', 'Dokumente', 'files', $active) ?>
    </nav>
    <button class="btn btn-primary side-add" data-sheet="quickadd"><?= icon('plus') ?> Eintrag</button>
    <div class="side-foot">
      <?= nav_link('settings', 'Einstellungen', 'settings', $active) ?>
      <div class="side-user"><span class="avatar"><?= e(mb_strtoupper(mb_substr($u['name'] ?: $u['email'], 0, 1))) ?></span><span class="muted small"><?= e($u['name'] ?: $u['email']) ?></span></div>
    </div>
  </aside>
  <div class="main">
    <header class="topbar">
      <?php if ($back): ?>
        <a class="icon-btn" href="<?= e($back) ?>" aria-label="Zurück"><?= icon('chevron-left') ?></a>
      <?php else: ?>
        <a class="brand brand-mobile" href="<?= e(url()) ?>"><span class="brand-mark"><?= icon('car') ?></span></a>
      <?php endif; ?>
      <h1 class="topbar-title"><?= e($o['heading'] ?? $title) ?></h1>
      <div class="topbar-actions"><?= $o['actions'] ?? '' ?></div>
    </header>
    <main class="content">
<?php endif;
    foreach (flashes() as $f) {
        echo '<div class="toast toast-' . e($f['t']) . '" role="status">' . icon($f['t'] === 'err' ? 'alert' : 'check-circle') . '<span>' . e($f['m']) . '</span></div>';
    }
}

function page_footer(): void
{
    if (!empty($GLOBALS['BARE']) || !current_user()) { echo '</main></body></html>'; return; }
    ?>
    </main>
  </div>
  <nav class="tabbar">
    <?= tab_link('dashboard', 'Übersicht', 'home') ?>
    <?= tab_link('vehicles', 'Fahrzeuge', 'grid') ?>
    <button class="tab-add" data-sheet="quickadd" aria-label="Neuer Eintrag"><?= icon('plus') ?></button>
    <?= tab_link('costs', 'Kosten', 'pie') ?>
    <?= tab_link('more', 'Mehr', 'more') ?>
  </nav>
</div>
<?php quickadd_sheet(); ?>
</body>
</html>
<?php
}

function nav_link(string $page, string $label, string $ic, string $active): string
{
    $cls = $page === $active ? ' active' : '';
    return '<a class="nav-link' . $cls . '" href="' . e(url($page)) . '">' . icon($ic) . '<span>' . e($label) . '</span></a>';
}
function tab_link(string $page, string $label, string $ic): string
{
    $cur = $GLOBALS['NAV'] ?? '';
    $cls = $page === $cur || ($page === 'more' && in_array($cur, ['settings', 'reminders', 'documents', 'import'], true)) ? ' active' : '';
    return '<a class="tab' . $cls . '" href="' . e(url($page)) . '">' . icon($ic) . '<span>' . e($label) . '</span></a>';
}

function quickadd_sheet(): void
{
    $vs = vehicles();
    $cur = (int)($GLOBALS['CURRENT_VEHICLE'] ?? 0);
    ?>
<div class="sheet" id="sheet-quickadd" hidden>
  <div class="sheet-backdrop" data-close></div>
  <div class="sheet-panel" role="dialog" aria-label="Neuer Eintrag">
    <div class="sheet-grip"></div>
    <div class="sheet-head"><h2>Neuer Eintrag</h2><button class="icon-btn" data-close aria-label="Schließen"><?= icon('x') ?></button></div>
    <?php if (!$vs): ?>
      <p class="muted">Lege zuerst ein Fahrzeug an.</p>
      <a class="btn btn-primary btn-block" href="<?= e(url('vehicle_edit')) ?>"><?= icon('plus') ?> Fahrzeug anlegen</a>
    <?php else: ?>
      <div class="seg seg-scroll" role="tablist" id="qa-vehicles">
        <?php foreach ($vs as $i => $v): ?>
          <button type="button" class="seg-btn<?= ($cur ? $v['id'] == $cur : $i === 0) ? ' on' : '' ?>" data-vid="<?= (int)$v['id'] ?>" data-motor="<?= is_motor($v) ? 1 : 0 ?>"><?= icon(vehicle_icon($v)) ?><?= e($v['name']) ?></button>
        <?php endforeach; ?>
      </div>
      <div class="qa-grid">
        <a class="qa-item" data-href="<?= e(url('fuel_edit', ['v' => '__V__'])) ?>" data-motor-only><span class="qa-ic c-teal"><?= icon('fuel') ?></span>Tanken</a>
        <a class="qa-item" data-href="<?= e(url('cost_edit', ['v' => '__V__'])) ?>"><span class="qa-ic c-indigo"><?= icon('receipt') ?></span>Kosten</a>
        <a class="qa-item" data-href="<?= e(url('odo_edit', ['v' => '__V__'])) ?>"><span class="qa-ic c-sky"><?= icon('route') ?></span>Kilometer</a>
        <a class="qa-item" data-href="<?= e(url('reminder_edit', ['v' => '__V__'])) ?>"><span class="qa-ic c-amber"><?= icon('bell') ?></span>Frist</a>
        <a class="qa-item" data-href="<?= e(url('part_edit', ['v' => '__V__'])) ?>"><span class="qa-ic c-orange"><?= icon('gauge') ?></span>Teil</a>
        <a class="qa-item" data-href="<?= e(url('doc_edit', ['v' => '__V__'])) ?>"><span class="qa-ic c-slate"><?= icon('file') ?></span>Dokument</a>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php
}

/* ---------- Bausteine ---------- */

function vehicle_avatar(array $v, string $size = ''): string
{
    $color = $v['color'] ?: '#0f766e';
    if ($v['photo']) {
        return '<span class="vavatar ' . $size . ' has-photo" style="--vc:' . e($color) . '"><img src="' . e(file_url($v['photo'])) . '" alt="" loading="lazy"></span>';
    }
    return '<span class="vavatar ' . $size . '" style="--vc:' . e($color) . '">' . icon(vehicle_icon($v)) . '</span>';
}

function status_pill(string $status, ?string $label = null): string
{
    $labels = ['overdue' => 'Überfällig', 'soon' => 'Bald fällig', 'ok' => 'OK', 'done' => 'Erledigt'];
    return '<span class="pill pill-' . e($status) . '">' . e($label ?? $labels[$status] ?? $status) . '</span>';
}

function empty_state(string $ic, string $title, string $text, ?string $btn = null, ?string $href = null): string
{
    $b = $btn ? '<a class="btn btn-primary" href="' . e($href) . '">' . icon('plus') . ' ' . e($btn) . '</a>' : '';
    return '<div class="empty"><div class="empty-icon">' . icon($ic) . '</div><h3>' . e($title) . '</h3><p>' . e($text) . '</p>' . $b . '</div>';
}

function kpi(string $label, string $value, ?string $sub = null, string $ic = '', string $tone = ''): string
{
    return '<div class="kpi ' . e($tone) . '">' . ($ic ? '<span class="kpi-ic">' . icon($ic) . '</span>' : '') . '<div class="kpi-label">' . e($label) . '</div><div class="kpi-value">' . $value . '</div>' . ($sub ? '<div class="kpi-sub">' . $sub . '</div>' : '') . '</div>';
}

/* Formularfelder */
function f_text(string $name, string $label, $value = '', array $o = []): string
{
    $type = $o['type'] ?? 'text';
    $attrs = '';
    foreach (['placeholder', 'inputmode', 'autocomplete', 'step', 'min', 'max', 'pattern', 'list', 'maxlength'] as $a) {
        if (isset($o[$a])) $attrs .= ' ' . $a . '="' . e($o[$a]) . '"';
    }
    if (!empty($o['required'])) $attrs .= ' required';
    if (!empty($o['autofocus'])) $attrs .= ' autofocus';
    $suffix = isset($o['suffix']) ? '<span class="suffix">' . e($o['suffix']) . '</span>' : '';
    $hint = isset($o['hint']) ? '<small class="hint">' . $o['hint'] . '</small>' : '';
    $cls = $o['class'] ?? '';
    return '<label class="field ' . e($cls) . '"><span class="label">' . e($label) . '</span><span class="control' . ($suffix ? ' has-suffix' : '') . '"><input type="' . e($type) . '" name="' . e($name) . '" value="' . e($value) . '"' . $attrs . '>' . $suffix . '</span>' . $hint . '</label>';
}
function f_num(string $name, string $label, $value, array $o = []): string
{
    $dec = $o['dec'] ?? 2;
    if ($value === null || $value === '') $v = '';
    elseif (!is_numeric($value)) $v = (string)$value;
    elseif ($dec === 0) $v = (string)(int)round((float)$value);
    else {
        $v = number_format((float)$value, $dec, ',', '');
        if (str_contains($v, ',')) $v = rtrim(rtrim($v, '0'), ',');
    }
    return f_text($name, $label, $v, $o + ['inputmode' => $dec ? 'decimal' : 'numeric']);
}
function f_date(string $name, string $label, $value, array $o = []): string
{
    return f_text($name, $label, $value, $o + ['type' => 'date']);
}
function f_select(string $name, string $label, array $options, $value, array $o = []): string
{
    $h = '<label class="field ' . e($o['class'] ?? '') . '"><span class="label">' . e($label) . '</span><span class="control select"><select name="' . e($name) . '"' . (!empty($o['required']) ? ' required' : '') . (isset($o['id']) ? ' id="' . e($o['id']) . '"' : '') . '>';
    if (isset($o['empty'])) $h .= '<option value="">' . e($o['empty']) . '</option>';
    foreach ($options as $k => $l) {
        $h .= '<option value="' . e($k) . '"' . ((string)$k === (string)$value ? ' selected' : '') . '>' . e(is_array($l) ? $l[0] : $l) . '</option>';
    }
    $h .= '</select>' . icon('chevron-down', 'select-ic') . '</span>' . (isset($o['hint']) ? '<small class="hint">' . $o['hint'] . '</small>' : '') . '</label>';
    return $h;
}
function f_textarea(string $name, string $label, $value, array $o = []): string
{
    return '<label class="field ' . e($o['class'] ?? '') . '"><span class="label">' . e($label) . '</span><span class="control"><textarea name="' . e($name) . '" rows="' . (int)($o['rows'] ?? 3) . '" placeholder="' . e($o['placeholder'] ?? '') . '">' . e($value) . '</textarea></span></label>';
}
function f_toggle(string $name, string $label, bool $on, ?string $hint = null): string
{
    return '<label class="toggle-row"><span><span class="label-strong">' . e($label) . '</span>' . ($hint ? '<small class="hint">' . e($hint) . '</small>' : '') . '</span><input type="hidden" name="' . e($name) . '" value="0"><input type="checkbox" class="switch" name="' . e($name) . '" value="1"' . ($on ? ' checked' : '') . '></label>';
}

function form_open(string $action = '', array $o = []): string
{
    $enc = !empty($o['files']) ? ' enctype="multipart/form-data"' : '';
    return '<form method="post" action="' . e($action) . '"' . $enc . ' class="' . e($o['class'] ?? 'form') . '">' . csrf_field();
}

function delete_button(string $action, string $what = 'Eintrag'): string
{
    return '<form method="post" action="' . e($action) . '" class="inline" data-confirm="' . e($what) . ' wirklich löschen?">' . csrf_field() . '<input type="hidden" name="do" value="delete"><button class="btn btn-ghost btn-danger" type="submit">' . icon('trash') . ' Löschen</button></form>';
}

/* ---------- Diagramme (SVG, ohne externe Bibliotheken) ---------- */

function nice_max(float $v): float
{
    if ($v <= 0) return 1;
    $exp = floor(log10($v));
    $f = $v / pow(10, $exp);
    $nf = $f <= 1 ? 1 : ($f <= 2 ? 2 : ($f <= 2.5 ? 2.5 : ($f <= 5 ? 5 : 10)));
    return $nf * pow(10, $exp);
}

/**
 * Säulendiagramm. $series = [label => value] oder [label => [cat => value, ...]] (gestapelt)
 */
function bar_chart(array $series, array $o = []): string
{
    if (!$series) return '';
    $h = $o['height'] ?? 180;
    $unit = $o['unit'] ?? '€';
    $colors = $o['colors'] ?? [];
    $n = count($series);
    $w = max(360, $n * 34);
    $padL = 44; $padB = 26; $padT = 10;
    $plotH = $h - $padB - $padT;
    $plotW = $w - $padL - 4;
    $totals = array_map(fn($v) => is_array($v) ? array_sum($v) : (float)$v, $series);
    $max = nice_max(max($totals ?: [0]));
    $bw = $plotW / $n;
    $bar = min(28, $bw * 0.62);
    $svg = '<svg class="chart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img">';
    for ($i = 0; $i <= 4; $i++) {
        $y = $padT + $plotH - $plotH * $i / 4;
        $val = $max * $i / 4;
        $svg .= '<line x1="' . $padL . '" x2="' . $w . '" y1="' . $y . '" y2="' . $y . '" class="grid"/>';
        $svg .= '<text x="' . ($padL - 6) . '" y="' . ($y + 4) . '" class="axis" text-anchor="end">' . e(fmt_num($val, $val < 10 && $max < 10 ? 1 : 0)) . '</text>';
    }
    $i = 0;
    foreach ($series as $label => $v) {
        $x = $padL + $bw * $i + ($bw - $bar) / 2;
        $stack = is_array($v) ? $v : ['_' => (float)$v];
        $y0 = $padT + $plotH;
        $tip = $label . ': ' . fmt_num($totals[$label], 2) . ' ' . $unit;
        foreach ($stack as $cat => $val) {
            if ($val <= 0) continue;
            $bh = $plotH * $val / $max;
            $y0 -= $bh;
            $col = $colors[$cat] ?? null;
            $svg .= '<rect x="' . round($x, 1) . '" y="' . round($y0, 1) . '" width="' . round($bar, 1) . '" height="' . round(max($bh, 0.5), 1) . '" rx="' . ($bh > 6 ? 4 : 1) . '" class="bar"' . ($col ? ' style="fill:' . e($col) . '"' : '') . '><title>' . e($tip . ($cat !== '_' ? ' · ' . cat_label($cat) . ': ' . fmt_num($val, 2) . ' ' . $unit : '')) . '</title></rect>';
        }
        if ($i % (int)ceil($n / ($o['max_labels'] ?? 7)) === 0) {
            $svg .= '<text x="' . round($x + $bar / 2, 1) . '" y="' . ($h - 8) . '" class="axis" text-anchor="middle">' . e($label) . '</text>';
        }
        $i++;
    }
    $svg .= '</svg>';
    return '<div class="chart-wrap">' . $svg . '</div>';
}

/** Liniendiagramm für Verbrauch o. ä. $points = [[label, value], ...] */
function line_chart(array $points, array $o = []): string
{
    $points = array_values(array_filter($points, fn($p) => $p[1] !== null));
    if (count($points) < 2) return '<p class="muted small">Für ein Diagramm werden mindestens zwei Volltankungen benötigt.</p>';
    $h = $o['height'] ?? 170;
    $w = 440; $padL = 40; $padB = 24; $padT = 12;
    $vals = array_column($points, 1);
    $min = floor(min($vals) - 0.5); $max = ceil(max($vals) + 0.5);
    if ($max - $min < 2) { $max += 1; $min -= 1; }
    $plotH = $h - $padB - $padT; $plotW = $w - $padL - 8;
    $n = count($points);
    $xy = [];
    foreach ($points as $i => $p) {
        $x = $padL + ($n === 1 ? 0 : $plotW * $i / ($n - 1));
        $y = $padT + $plotH - $plotH * ($p[1] - $min) / ($max - $min);
        $xy[] = [round($x, 1), round($y, 1), $p];
    }
    $svg = '<svg class="chart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img">';
    for ($i = 0; $i <= 3; $i++) {
        $y = $padT + $plotH - $plotH * $i / 3;
        $svg .= '<line x1="' . $padL . '" x2="' . $w . '" y1="' . $y . '" y2="' . $y . '" class="grid"/><text x="' . ($padL - 6) . '" y="' . ($y + 4) . '" class="axis" text-anchor="end">' . e(fmt_num($min + ($max - $min) * $i / 3, 1)) . '</text>';
    }
    $path = 'M' . implode(' L', array_map(fn($p) => $p[0] . ',' . $p[1], $xy));
    $area = $path . ' L' . end($xy)[0] . ',' . ($padT + $plotH) . ' L' . $xy[0][0] . ',' . ($padT + $plotH) . ' Z';
    $svg .= '<path d="' . $area . '" class="area"/><path d="' . $path . '" class="line" vector-effect="non-scaling-stroke"/>';
    foreach ($xy as $i => [$x, $y, $p]) {
        $svg .= '<circle cx="' . $x . '" cy="' . $y . '" r="3" class="dot" vector-effect="non-scaling-stroke"><title>' . e($p[0] . ': ' . fmt_num($p[1], 2) . ' ' . ($o['unit'] ?? '')) . '</title></circle>';
        if ($i === 0 || $i === $n - 1 || ($n > 8 && $i % (int)ceil($n / 6) === 0)) {
            $svg .= '<text x="' . $x . '" y="' . ($h - 6) . '" class="axis" text-anchor="' . ($i === 0 ? 'start' : ($i === $n - 1 ? 'end' : 'middle')) . '">' . e($p[0]) . '</text>';
        }
    }
    return '<div class="chart-wrap">' . $svg . '</svg></div>';
}

/** Horizontale Balken für Kategorien */
function category_bars(array $byCat): string
{
    arsort($byCat);
    $total = array_sum($byCat) ?: 1;
    $h = '<ul class="catbars">';
    foreach ($byCat as $cat => $sum) {
        $pct = $sum / $total * 100;
        $h .= '<li><div class="catbar-top"><span class="dot-c" style="background:' . e(cat_color($cat)) . '"></span><span class="catbar-label">' . e(cat_label($cat)) . '</span><span class="catbar-val">' . fmt_money($sum) . '</span></div><div class="catbar-track"><span style="width:' . round($pct, 1) . '%;background:' . e(cat_color($cat)) . '"></span></div><div class="catbar-pct">' . fmt_num($pct, 0) . ' %</div></li>';
    }
    return $h . '</ul>';
}

/** Ring-Diagramm */
function donut(array $byCat, string $center = ''): string
{
    arsort($byCat);
    $total = array_sum($byCat);
    if ($total <= 0) return '';
    $r = 15.915; $off = 25;
    $svg = '<svg class="donut" viewBox="0 0 42 42"><circle cx="21" cy="21" r="' . $r . '" class="donut-bg"/>';
    foreach ($byCat as $cat => $v) {
        $pct = $v / $total * 100;
        $svg .= '<circle cx="21" cy="21" r="' . $r . '" fill="none" stroke="' . e(cat_color($cat)) . '" stroke-width="5" stroke-dasharray="' . round(max($pct - 0.6, 0.1), 2) . ' ' . round(100 - max($pct - 0.6, 0.1), 2) . '" stroke-dashoffset="' . round($off, 2) . '"><title>' . e(cat_label($cat) . ': ' . fmt_money($v)) . '</title></circle>';
        $off -= $pct;
    }
    $svg .= '</svg>';
    return '<div class="donut-wrap">' . $svg . '<div class="donut-center">' . $center . '</div></div>';
}
