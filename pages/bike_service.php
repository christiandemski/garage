<?php
declare(strict_types=1);

$bs = bikes(true);
$ids = array_map('intval', array_column($bs, 'id'));
$names = array_column($bs, 'name', 'id');
$vf = (int)($_GET['v'] ?? 0);
if (!in_array($vf, $ids, true)) $vf = 0;
$sel = $vf ? [$vf] : $ids;
$in = $sel ? implode(',', $sel) : '0';

$planned = rows("SELECT * FROM services WHERE vehicle_id IN ($in) AND status = 'geplant' ORDER BY date IS NULL, date");
$done = rows("SELECT * FROM services WHERE vehicle_id IN ($in) AND status = 'erledigt' ORDER BY date DESC, id DESC");
$addUrl = url('service_edit', $vf ? ['v' => $vf] : []);

page_header('Service', ['nav' => 'fahrraeder', 'heading' => 'Fahrräder',
    'actions' => $bs ? '<a class="btn btn-primary btn-sm" href="' . e($addUrl) . '">' . icon('plus') . '<span>Service</span></a>' : '']);
echo area_nav('fahrraeder', 'bike_service');

if (!$bs) {
    echo '<div class="card">' . empty_state('bike', 'Noch keine Fahrräder', 'Lege zuerst ein Fahrrad an – danach planst und dokumentierst du hier Inspektionen und Bremsenservice.', 'Fahrrad anlegen', url('vehicle_edit', ['type' => 'fahrrad'])) . '</div>';
    page_footer();
    return;
}
?>
<?php if (count($bs) > 1): ?>
<div class="chips mb">
  <a class="chip<?= !$vf ? ' on' : '' ?>" href="<?= e(url('bike_service')) ?>">Alle Räder</a>
  <?php foreach ($bs as $b): ?><a class="chip<?= $vf === (int)$b['id'] ? ' on' : '' ?>" href="<?= e(url('bike_service', ['v' => $b['id']])) ?>"><?= icon(vehicle_icon($b)) ?><?= e($b['name']) ?></a><?php endforeach; ?>
</div>
<?php endif; ?>

<div class="svc-status">
  <?php foreach ($bs as $b): if ($vf && $vf !== (int)$b['id']) continue; if ($b['archived'] && !$vf) continue;
      $last = last_services((int)$b['id']); ?>
    <div class="card svc-card">
      <div class="row mb-s"><?= vehicle_avatar($b, 'sm') ?><h2 class="grow ellipsis"><?= e($b['name']) ?></h2><a class="icon-btn" href="<?= e(url('service_edit', ['v' => $b['id']])) ?>" aria-label="Service für <?= e($b['name']) ?> anlegen"><?= icon('plus') ?></a></div>
      <dl class="svc-last">
        <?php foreach (['inspektion', 'bremsen'] as $k): $l = $last[$k] ?? null; ?>
          <div><dt><?= icon(SERVICE_KINDS[$k][1]) ?><?= e(service_label($k)) ?></dt>
            <dd><?= $l ? '<b>' . e(fmt_date($l['date'])) . '</b><span>' . e(rel_days($l['date'])) . '</span>' : '<span>noch nicht erfasst</span>' ?></dd></div>
        <?php endforeach; ?>
      </dl>
    </div>
  <?php endforeach; ?>
</div>

<?php
$kmBy = [];
foreach ($bs as $b) $kmBy[$b['id']] = current_km($b);
$rem = rows("SELECT r.*, v.name AS vname FROM reminders r JOIN vehicles v ON v.id = r.vehicle_id WHERE r.vehicle_id IN ($in) AND r.done_at IS NULL");
$wear = array_filter(due_items(), fn($d) => $d['type'] === 'part' && in_array((int)$d['v']['id'], $sel, true));
if ($rem || $wear): ?>
  <h3 class="section-title">Fristen & Verschleiß</h3>
  <?php if ($rem) echo reminders_list($rem, $kmBy, !$vf); ?>
  <?php if ($wear): ?>
    <div class="card flush<?= $rem ? ' mt' : '' ?>"><ul class="list">
      <?php foreach ($wear as $d): ?>
        <li><a class="list-item" href="<?= e($d['href']) ?>"><span class="li-ic <?= $d['status'] === 'overdue' ? 'c-red' : 'c-amber' ?>"><?= icon('gauge') ?></span><span class="li-main"><span class="li-title"><?= e($d['title']) ?></span><span class="li-sub"><?= e($d['v']['name'] . ' · ' . $d['text']) ?></span></span><?= status_pill($d['status']) ?></a></li>
      <?php endforeach; ?>
    </ul></div>
  <?php endif; ?>
<?php endif; ?>

<h3 class="section-title">Geplant</h3>
<?php if ($planned): ?>
  <div class="card flush"><ul class="list"><?php foreach ($planned as $s) echo service_row($s, $names, !$vf); ?></ul></div>
<?php else: ?>
  <div class="card card-quiet"><p class="muted small m0">Kein Service geplant. Lege einen Termin an – z. B. den nächsten Werkstatt-Termin oder den Bremsenservice im Frühjahr.</p></div>
<?php endif; ?>

<h3 class="section-title">Erledigt</h3>
<?php if ($done): ?>
  <div class="card flush"><ul class="list"><?php foreach ($done as $s) echo service_row($s, $names, !$vf); ?></ul></div>
<?php else: ?>
  <div class="card"><?= empty_state('wrench', 'Noch kein Service dokumentiert', 'Halte fest, was gemacht wurde – selbst oder in der Werkstatt, mit Arbeiten, Kosten und Rechnung.', 'Service erfassen', $addUrl) ?></div>
<?php endif;
page_footer();
