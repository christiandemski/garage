<?php
declare(strict_types=1);

$r = owned('reminders', $_GET['id'] ?? 0);
$v = vehicle_or_404($r['vehicle_id']);
$back = url('vehicle', ['id' => $v['id'], 'tab' => 'reminders']);
$km = current_km($v);

if (is_post()) {
    csrf_check();
    $date = date_or_null($_POST['date']) ?? today();
    $doneKm = int_or_null($_POST['km']) ?? $km;
    if ($doneKm !== null && $doneKm > (int)$km) {
        insert('odometer_log', ['vehicle_id' => $v['id'], 'date' => $date, 'km' => $doneKm, 'source' => 'manual', 'note' => $r['title'], 'created_at' => now()]);
    }
    $nextId = complete_reminder($r, $date, $doneKm);
    $amount = num($_POST['amount'] ?? '');
    if ($amount !== null) {
        $cat = ['hu' => 'pruefung', 'inspektion' => 'wartung', 'oel' => 'wartung', 'reifen' => 'reifen', 'bremsfluessig' => 'wartung', 'versicherung' => 'versicherung', 'steuer' => 'steuer', 'service' => 'wartung'][$r['kind']] ?? 'sonstiges';
        insert('costs', ['vehicle_id' => $v['id'], 'date' => $date, 'category' => $cat, 'title' => $r['title'], 'amount' => $amount, 'odometer' => $doneKm,
            'vendor' => str_or_null($_POST['vendor'] ?? '', 120), 'note' => null, 'created_at' => now()]);
    }
    $msg = 'Erledigt!';
    if ($nextId) {
        $n = row('SELECT * FROM reminders WHERE id = ?', [$nextId]);
        $msg .= ' Nächster Termin: ' . trim(($n['due_date'] ? fmt_date($n['due_date']) : '') . ($n['due_km'] ? ' / ' . fmt_km($n['due_km']) : ''), ' /');
    }
    flash($msg);
    redirect($back);
}

page_header('Erledigt', ['nav' => 'vehicles', 'back' => $back, 'heading' => $r['title'] . ' erledigen']);
?>
<div class="card form-card">
<?= form_open() ?>
  <p class="muted"><?= e($v['name']) ?><?= ($r['interval_months'] || $r['interval_km']) ? ' · Die nächste Frist wird automatisch angelegt.' : '' ?></p>
  <div class="form-grid">
    <?= f_date('date', 'Erledigt am', today(), ['required' => true]) ?>
    <?= f_num('km', 'Kilometerstand', $km, ['dec' => 0, 'suffix' => 'km']) ?>
    <div class="form-section">Kosten (optional)</div>
    <?= f_num('amount', 'Betrag', null, ['suffix' => '€', 'placeholder' => 'leer = keine Kosten']) ?>
    <?= f_text('vendor', 'Werkstatt', '', ['placeholder' => 'optional']) ?>
  </div>
  <div class="form-actions">
    <a class="btn btn-ghost" href="<?= e($back) ?>">Abbrechen</a>
    <button class="btn btn-primary" type="submit"><?= icon('check') ?> Erledigt</button>
  </div>
</form>
</div>
<?php page_footer();
