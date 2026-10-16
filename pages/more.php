<?php
declare(strict_types=1);

$u = current_user();
$dueCount = count(array_filter(due_items(), fn($d) => $d['status'] !== 'ok'));
page_header('Mehr', ['nav' => 'more']);
$item = fn(string $href, string $ic, string $tone, string $title, string $sub = '', string $end = '') =>
    '<li><a class="list-item" href="' . e($href) . '"><span class="li-ic ' . $tone . '">' . icon($ic) . '</span><span class="li-main"><span class="li-title">' . e($title) . '</span>' . ($sub ? '<span class="li-sub">' . e($sub) . '</span>' : '') . '</span>' . $end . icon('chevron-right', 'li-chev') . '</a></li>';
?>
<div class="card flush mb"><div class="list-item"><span class="avatar" style="width:44px;height:44px;font-size:17px"><?= e(mb_strtoupper(mb_substr($u['name'] ?: $u['email'], 0, 1))) ?></span><span class="li-main"><span class="li-title"><?= e($u['name'] ?: 'Mein Konto') ?></span><span class="li-sub"><?= e($u['email']) ?></span></span></div></div>

<div class="card flush settings-nav"><ul class="list">
  <?= $item(url('reminders'), 'bell', 'c-amber', 'Fristen', 'HU, Inspektion, Reifenwechsel …', $dueCount ? status_pill('soon', (string)$dueCount) : '') ?>
  <?= $item(url('documents'), 'files', 'c-slate', 'Dokumente', 'Fahrzeugschein, Rechnungen, Verträge') ?>
  <?= $item(url('import'), 'refresh', 'c-teal', 'Import & Abgleich', 'Spritmonitor, MySkoda, CSV') ?>
</ul></div>

<div class="card flush settings-nav mt"><ul class="list">
  <?= $item(url('settings'), 'settings', 'c-indigo', 'Einstellungen', 'Profil, Design, Benachrichtigungen, API') ?>
  <?= $item(url('export', ['what' => 'all']), 'download', 'c-sky', 'Daten exportieren', 'Alle Einträge als CSV (ZIP)') ?>
</ul></div>
<form method="post" action="<?= e(url('logout')) ?>" class="card flush settings-nav mt"><?= csrf_field() ?>
  <button type="submit" class="list-item" style="width:100%;background:none;border:0;font:inherit;text-align:left;cursor:pointer"><span class="li-ic c-red"><?= icon('logout') ?></span><span class="li-main"><span class="li-title">Abmelden</span></span><?= icon('chevron-right', 'li-chev') ?></button>
</form>
<p class="muted xs mt" style="text-align:center">Garage <?= e(APP_VERSION) ?></p>
<?php page_footer();
