<?php
declare(strict_types=1);

$id = (int)($_GET['id'] ?? 0);
if ($id) {
    $r = owned('reminders', $id);
    $v = vehicle_or_404($r['vehicle_id']);
} else {
    $v = vehicle_or_404($_GET['v'] ?? 0);
    $kind = $_GET['kind'] ?? (is_motor($v) ? 'hu' : 'service');
    $k = REMINDER_KINDS[$kind] ?? REMINDER_KINDS['sonstiges'];
    $r = ['id' => 0, 'kind' => $kind, 'title' => $k[0], 'due_date' => null, 'due_km' => null, 'interval_months' => $k[2], 'interval_km' => is_motor($v) ? $k[3] : null,
        'warn_days' => 30, 'warn_km' => 1000, 'note' => null, 'source' => 'manual', 'done_at' => null];
}
$vid = (int)$v['id'];
$back = url('vehicle', ['id' => $vid, 'tab' => 'reminders']);

if (is_post()) {
    csrf_check();
    $do = $_POST['do'] ?? 'save';
    if ($id && $do === 'delete') {
        q('DELETE FROM reminders WHERE id = ?', [$id]);
        flash('Frist gelöscht.');
        redirect($back);
    }
    if ($id && $do === 'reopen') {
        update('reminders', ['done_at' => null], 'id = ?', [$id]);
        flash('Frist wieder offen.');
        redirect($back);
    }
    $kind = array_key_exists($_POST['kind'] ?? '', REMINDER_KINDS) ? $_POST['kind'] : 'sonstiges';
    $data = [
        'vehicle_id' => $vid, 'kind' => $kind, 'title' => str_or_null($_POST['title'], 160) ?? kind_label($kind),
        'due_date' => date_or_null($_POST['due_date']), 'due_km' => int_or_null($_POST['due_km']),
        'interval_months' => int_or_null($_POST['interval_months']) ?: null, 'interval_km' => int_or_null($_POST['interval_km']) ?: null,
        'warn_days' => max(0, (int)(int_or_null($_POST['warn_days']) ?? 30)), 'warn_km' => max(0, (int)(int_or_null($_POST['warn_km']) ?? 1000)),
        'note' => str_or_null($_POST['note'] ?? '', 2000),
    ];
    if (!$data['due_date'] && $data['due_km'] === null) {
        flash('Bitte ein Datum und/oder einen Kilometerstand angeben.', 'err');
        $r = array_merge($r, $data);
    } else {
        if ($id) update('reminders', $data + ['notified_at' => null, 'notified_stage' => null], 'id = ?', [$id]);
        else { $data += ['source' => 'manual', 'created_at' => now()]; insert('reminders', $data); }
        flash('Frist gespeichert.');
        redirect($back);
    }
}

$km = current_km($v);
$st = $id && !$r['done_at'] ? reminder_status($r, $km) : null;
page_header('Frist', ['nav' => 'vehicles', 'back' => $back, 'heading' => ($id ? $r['title'] : 'Neue Frist') . ' · ' . $v['name']]);
if ($st): ?>
  <div class="card form-card mb">
    <div class="row-between"><div><div class="label-strong"><?= e($r['title']) ?></div><div class="muted small"><?= e($st['text']) ?></div></div><?= status_pill($st['status']) ?></div>
    <div class="row wrap mt">
      <a class="btn btn-primary" href="<?= e(url('reminder_done', ['id' => $id])) ?>"><?= icon('check') ?> Als erledigt markieren</a>
    </div>
  </div>
<?php endif;
if ($r['source'] !== 'manual'): ?>
  <div class="banner form-card mb"><?= icon('sync') ?><p>Diese Frist wird automatisch aus der MySkoda-App aktualisiert. Änderungen hier werden beim nächsten Abgleich überschrieben.</p></div>
<?php endif; ?>
<div class="card form-card">
<?= form_open() ?>
  <div class="form-grid">
    <label class="field"><span class="label">Art</span><span class="control select"><select name="kind" id="reminder-kind">
      <?php foreach (for_vehicle(REMINDER_KINDS, $v, 4, $r['kind']) as $k => [$label, $ic, $m, $kmI]): ?>
        <option value="<?= e($k) ?>" data-months="<?= e($m) ?>" data-km="<?= e(is_motor($v) ? $kmI : '') ?>"<?= $r['kind'] === $k ? ' selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select><?= icon('chevron-down', 'select-ic') ?></span></label>
    <?= f_text('title', 'Bezeichnung', $r['title'], ['required' => true]) ?>
    <div class="form-section">Fällig</div>
    <?= f_date('due_date', 'am', $r['due_date']) ?>
    <?= f_num('due_km', 'oder bei', $r['due_km'], ['dec' => 0, 'suffix' => 'km', 'hint' => $km !== null ? 'Aktuell ' . fmt_km($km) : null]) ?>
    <div class="form-section">Wiederholung</div>
    <?= f_num('interval_months', 'alle', $r['interval_months'], ['dec' => 0, 'suffix' => 'Monate']) ?>
    <?= f_num('interval_km', 'oder alle', $r['interval_km'], ['dec' => 0, 'suffix' => 'km']) ?>
    <details class="more full"><summary>Erinnerung <?= icon('chevron-down') ?></summary>
      <div class="form-grid">
        <?= f_num('warn_days', 'Vorlauf', $r['warn_days'], ['dec' => 0, 'suffix' => 'Tage']) ?>
        <?= f_num('warn_km', 'Vorlauf', $r['warn_km'], ['dec' => 0, 'suffix' => 'km']) ?>
      </div>
    </details>
    <div class="full"><?= f_textarea('note', 'Notiz', $r['note'], ['rows' => 2]) ?></div>
  </div>
  <div class="form-actions">
    <a class="btn btn-ghost" href="<?= e($back) ?>">Abbrechen</a>
    <button class="btn btn-primary" type="submit"><?= icon('check') ?> Speichern</button>
  </div>
</form>
</div>
<?php if ($id): ?>
<div class="row wrap mt">
  <?php if ($r['done_at']): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="reopen"><button class="btn" type="submit"><?= icon('refresh') ?> Wieder öffnen</button></form><?php endif; ?>
  <?= delete_button('', 'Die Frist') ?>
</div>
<?php endif;
page_footer();
