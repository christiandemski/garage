<?php
declare(strict_types=1);

$id = (int)($_GET['id'] ?? 0);
if ($id) {
    $f = owned('fuelings', $id);
    $v = vehicle_or_404($f['vehicle_id']);
} else {
    $v = vehicle_or_404($_GET['v'] ?? 0);
    $last = row('SELECT * FROM fuelings WHERE vehicle_id = ? ORDER BY date DESC, odometer DESC LIMIT 1', [$v['id']]);
    $f = ['id' => 0, 'date' => today(), 'odometer' => null, 'quantity' => null, 'total' => null, 'full_tank' => 1, 'missed_before' => 0,
        'fuel_sort' => $last['fuel_sort'] ?? null, 'station' => $last['station'] ?? null, 'note' => null];
}
$vid = (int)$v['id'];
$unit = fuel_unit($v);
$back = url('vehicle', ['id' => $vid, 'tab' => 'fuel']);

if (is_post()) {
    csrf_check();
    if ($id && ($_POST['do'] ?? '') === 'delete') {
        q('DELETE FROM fuelings WHERE id = ?', [$id]);
        flash('Tankvorgang gelöscht.');
        redirect($back);
    }
    if ($id && ($_POST['do'] ?? '') === 'toggle_full') {
        $full = (int)!$f['full_tank'];
        update('fuelings', ['full_tank' => $full], 'id = ?', [$id]);
        flash(fmt_date($f['date']) . ': als ' . ($full ? 'Voll' : 'Teil') . (is_electric($v) ? 'ladung' : 'betankung') . ' markiert.');
        redirect($back . '#f' . $id);
    }
    $qty = num($_POST['quantity']);
    $total = num($_POST['total']);
    $ppu = num($_POST['ppu']);
    if ($total === null && $qty && $ppu) $total = round($qty * $ppu, 2);
    $odo = int_or_null($_POST['odometer']);
    $trip = num($_POST['trip'] ?? '');
    if ($odo === null && $trip) {
        $prev = current_km($v);
        if ($prev !== null) $odo = (int)round($prev + $trip);
    }
    if (!$qty || $total === null) {
        flash('Bitte Menge und Preis angeben.', 'err');
    } else {
        $data = [
            'vehicle_id' => $vid, 'date' => date_or_null($_POST['date']) ?? today(), 'odometer' => $odo, 'trip' => $trip,
            'quantity' => $qty, 'total' => $total, 'full_tank' => (int)!empty($_POST['full_tank']), 'missed_before' => (int)!empty($_POST['missed_before']),
            'fuel_sort' => str_or_null($_POST['fuel_sort'] ?? '', 30), 'station' => str_or_null($_POST['station'] ?? '', 120), 'note' => str_or_null($_POST['note'] ?? '', 2000),
        ];
        if ($id) update('fuelings', $data, 'id = ?', [$id]);
        else { $data['created_at'] = now(); insert('fuelings', $data); }
        // Plausibilität
        $known = current_km($v);
        if ($odo !== null && $known !== null && $odo < $known && !$id) flash('Hinweis: Der Kilometerstand ist kleiner als der bisher höchste (' . fmt_km($known) . ').', 'err');
        else flash($id ? 'Gespeichert.' : 'Tankvorgang erfasst.');
        redirect($back);
    }
    $f = array_merge($f, $_POST);
}

$prevKm = current_km($v);
page_header($id ? 'Tankvorgang' : (is_electric($v) ? 'Ladevorgang' : 'Tanken'), ['nav' => 'vehicles', 'back' => $back, 'heading' => ($id ? 'Tankvorgang bearbeiten' : (is_electric($v) ? 'Laden' : 'Tanken')) . ' · ' . $v['name']]);
$ppuVal = ($f['quantity'] && $f['total'] && is_numeric($f['quantity']) && is_numeric($f['total'])) ? (float)$f['total'] / (float)$f['quantity'] : null;
?>
<div class="card form-card">
<form method="post" class="form" data-fuel>
  <?= csrf_field() ?>
  <div class="form-grid">
    <?= f_date('date', 'Datum', $f['date'], ['required' => true]) ?>
    <?= f_num('odometer', 'Kilometerstand', $f['odometer'], ['dec' => 0, 'suffix' => 'km', 'hint' => $prevKm ? 'Zuletzt ' . fmt_km($prevKm) : null, 'autofocus' => !$id]) ?>
    <?= f_num('quantity', 'Menge', $f['quantity'], ['dec' => 2, 'suffix' => $unit, 'required' => true]) ?>
    <?= f_num('total', 'Gesamtpreis', $f['total'], ['dec' => 2, 'suffix' => '€', 'required' => true]) ?>
    <?= f_num('ppu', 'Preis pro ' . ($unit === 'l' ? 'Liter' : 'kWh'), $ppuVal !== null ? round($ppuVal, 3) : '', ['dec' => 3, 'suffix' => '€/' . $unit, 'hint' => 'Wird automatisch berechnet']) ?>
    <?= f_text('station', is_electric($v) ? 'Ladestation' : 'Tankstelle', $f['station'], ['placeholder' => 'optional']) ?>
    <div class="full">
      <?= f_toggle('full_tank', is_electric($v) ? 'Vollgeladen' : 'Vollgetankt', (bool)$f['full_tank'], 'Für die Verbrauchsberechnung nötig') ?>
      <?= f_toggle('missed_before', 'Vorherige Tankung fehlt', (bool)$f['missed_before'], 'Verbrauch wird ab hier neu berechnet') ?>
    </div>
    <details class="more full"><summary>Mehr Angaben <?= icon('chevron-down') ?></summary>
      <div class="form-grid">
        <?= f_text('fuel_sort', 'Sorte', $f['fuel_sort'], ['placeholder' => 'z. B. Super E10, Diesel']) ?>
        <?= f_num('trip', 'Gefahrene Strecke', $f['trip'] ?? null, ['dec' => 1, 'suffix' => 'km', 'hint' => 'Alternativ zum Kilometerstand']) ?>
        <div class="full"><?= f_textarea('note', 'Notiz', $f['note'], ['rows' => 2]) ?></div>
      </div>
    </details>
  </div>
  <div class="form-actions">
    <a class="btn btn-ghost" href="<?= e($back) ?>">Abbrechen</a>
    <button class="btn btn-primary" type="submit"><?= icon('check') ?> Speichern</button>
  </div>
</form>
</div>
<?php if ($id): ?><div class="mt"><?= delete_button('', 'Den Tankvorgang') ?></div><?php endif;
page_footer();
