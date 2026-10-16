<?php
declare(strict_types=1);

$id = (int)($_GET['id'] ?? 0);
if ($id) {
    $c = owned('costs', $id);
    $v = vehicle_or_404($c['vehicle_id']);
} else {
    $v = vehicle_or_404($_GET['v'] ?? 0);
    $cat = $_GET['cat'] ?? 'wartung';
    $c = ['id' => 0, 'date' => today(), 'category' => array_key_exists($cat, COST_CATEGORIES) ? $cat : 'wartung', 'title' => '', 'amount' => null,
        'odometer' => null, 'vendor' => null, 'repeat_months' => 0, 'note' => null, 'source' => 'manual'];
}
$vid = (int)$v['id'];
$back = safe_target($_GET['back'] ?? '', url('vehicle', ['id' => $vid, 'tab' => 'costs']));

if (is_post()) {
    csrf_check();
    if ($id && ($_POST['do'] ?? '') === 'delete') {
        q('UPDATE documents SET cost_id = NULL WHERE cost_id = ?', [$id]);
        q('DELETE FROM costs WHERE id = ?', [$id]);
        flash('Eintrag gelöscht.');
        redirect($back);
    }
    $amount = num($_POST['amount'], true);
    $category = array_key_exists($_POST['category'] ?? '', COST_CATEGORIES) ? $_POST['category'] : 'sonstiges';
    if ($amount === null) {
        flash('Bitte einen Betrag angeben.', 'err');
        $c = array_merge($c, $_POST);
    } else {
        $data = [
            'vehicle_id' => $vid, 'date' => date_or_null($_POST['date']) ?? today(), 'category' => $category,
            'title' => str_or_null($_POST['title'], 160) ?? cat_label($category), 'amount' => $amount,
            'odometer' => int_or_null($_POST['odometer']), 'vendor' => str_or_null($_POST['vendor'] ?? '', 120),
            'repeat_months' => max(0, (int)($_POST['repeat_months'] ?? 0)), 'note' => str_or_null($_POST['note'] ?? '', 4000),
        ];
        if ($id) {
            if ($data['repeat_months'] != $c['repeat_months']) $data['repeated'] = 0;
            update('costs', $data, 'id = ?', [$id]);
        } else {
            $data['created_at'] = now();
            $id = insert('costs', $data);
        }
        if (!empty($_FILES['receipt']['name'])) {
            if ($up = store_upload($_FILES['receipt'])) {
                insert('documents', ['vehicle_id' => $vid, 'cost_id' => $id, 'title' => $data['title'] . ' (' . fmt_date($data['date']) . ')', 'kind' => 'rechnung',
                    'filename' => $up['name'], 'stored_name' => $up['stored'], 'mime' => $up['mime'], 'size' => $up['size'], 'created_at' => now()]);
            }
        }
        // Frist direkt erledigen?
        if (!empty($_POST['complete_reminder'])) {
            $r = row('SELECT r.* FROM reminders r JOIN vehicles v ON v.id = r.vehicle_id WHERE r.id = ? AND v.user_id = ?', [(int)$_POST['complete_reminder'], uid()]);
            if ($r && !$r['done_at']) complete_reminder($r, $data['date'], $data['odometer'] ?? current_km($v));
        }
        materialize_recurring();
        flash('Gespeichert.');
        redirect($back);
    }
}

$docs = $id ? rows('SELECT * FROM documents WHERE cost_id = ?', [$id]) : [];
$suggest = [
    'wartung' => ['Inspektion', 'Ölwechsel', 'Service', 'Klimaservice', 'Bremsflüssigkeit'],
    'reparatur' => ['Werkstatt', 'Bremsen', 'Batterie', 'Steinschlag'],
    'reifen' => ['Reifenwechsel', 'Neue Reifen', 'Einlagerung', 'Wuchten'],
    'pruefung' => ['HU/AU'],
    'versicherung' => ['Kfz-Versicherung', 'Fahrradversicherung'],
    'steuer' => ['Kfz-Steuer'],
    'pflege' => ['Autowäsche', 'Innenreinigung', 'Pflegemittel'],
    'parken' => ['Parken', 'Maut', 'Vignette'],
    'teile' => ['Kette', 'Bremsbeläge', 'Mantel', 'Schlauch', 'Wischerblätter'],
    'zubehoer' => ['Zubehör', 'Werkzeug'],
    'finanzierung' => ['Leasingrate', 'Kreditrate'],
];
$openReminders = !$c['id'] ? rows('SELECT * FROM reminders WHERE vehicle_id = ? AND done_at IS NULL ORDER BY due_date', [$vid]) : [];

page_header('Kosten', ['nav' => 'vehicles', 'back' => $back, 'heading' => ($id ? 'Kosten bearbeiten' : 'Neue Kosten') . ' · ' . $v['name']]);
?>
<div class="card form-card">
<?= form_open('', ['files' => true]) ?>
  <div class="field"><span class="label">Kategorie</span>
    <div class="chips radio-chips">
      <?php foreach (COST_CATEGORIES as $k => [$label, $color]): if ($k === 'kraftstoff' && is_motor($v) && $c['category'] !== 'kraftstoff') continue; ?>
        <label><input type="radio" name="category" value="<?= e($k) ?>"<?= $c['category'] === $k ? ' checked' : '' ?>><span class="chip"><i class="dot-c" style="background:<?= e($color) ?>"></i><?= e($label) ?></span></label>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="form-grid">
    <div class="full"><?= f_text('title', 'Bezeichnung', $c['title'], ['list' => 'title-suggest', 'placeholder' => 'z. B. Inspektion, Versicherung 2026']) ?></div>
    <datalist id="title-suggest"><?php foreach (array_merge(...array_values($suggest)) as $s) echo '<option value="' . e($s) . '">'; ?></datalist>
    <?= f_num('amount', 'Betrag', $c['amount'], ['suffix' => '€', 'required' => true, 'autofocus' => !$id]) ?>
    <?= f_date('date', 'Datum', $c['date'], ['required' => true]) ?>
    <?= f_num('odometer', 'Kilometerstand', $c['odometer'], ['dec' => 0, 'suffix' => 'km', 'placeholder' => 'optional']) ?>
    <?= f_text('vendor', 'Werkstatt / Händler', $c['vendor'], ['placeholder' => 'optional']) ?>
    <?= f_select('repeat_months', 'Wiederholen', [0 => 'Einmalig', 1 => 'Monatlich', 3 => 'Vierteljährlich', 6 => 'Halbjährlich', 12 => 'Jährlich'], (int)$c['repeat_months'], ['hint' => 'Wiederkehrende Kosten (z. B. Versicherung) werden automatisch fortgeschrieben.', 'class' => 'full']) ?>
    <?php if ($openReminders): ?>
      <?= f_select('complete_reminder', 'Gleichzeitig Frist erledigen', array_column(array_map(fn($r) => [$r['id'], $r['title'] . ($r['due_date'] ? ' (' . fmt_date($r['due_date']) . ')' : '')], $openReminders), 1, 0), $_GET['reminder'] ?? '', ['empty' => '– keine –', 'class' => 'full']) ?>
    <?php endif; ?>
    <div class="full"><?= f_textarea('note', 'Notiz', $c['note'], ['rows' => 3, 'placeholder' => 'Was wurde gemacht? Teilenummern, Garantie …']) ?></div>
    <div class="full">
      <span class="label">Beleg</span>
      <?php foreach ($docs as $d): ?><div class="row small mt"><?= icon('file') ?><a href="<?= e(url('file', ['f' => $d['stored_name'], 'name' => $d['filename']])) ?>" target="_blank"><?= e($d['filename']) ?></a></div><?php endforeach; ?>
      <label class="field mt"><span class="control"><input type="file" name="receipt" accept="application/pdf,image/*"></span><small class="hint">PDF oder Foto – landet auch unter „Dokumente“.</small></label>
    </div>
  </div>
  <?php if ($c['source'] === 'auto'): ?><div class="banner"><?= icon('refresh') ?><p>Automatisch aus einer wiederkehrenden Buchung erzeugt.</p></div><?php endif; ?>
  <div class="form-actions">
    <a class="btn btn-ghost" href="<?= e($back) ?>">Abbrechen</a>
    <button class="btn btn-primary" type="submit"><?= icon('check') ?> Speichern</button>
  </div>
</form>
</div>
<?php if ($id): ?><div class="mt"><?= delete_button('', 'Den Eintrag') ?></div><?php endif;
page_footer();
