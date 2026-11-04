<?php
declare(strict_types=1);

$id = (int)($_GET['id'] ?? 0);
$bs = bikes();
if ($id) {
    $s = owned('services', $id);
    $v = vehicle_or_404($s['vehicle_id']);
} else {
    $v = isset($_GET['v']) ? vehicle_or_404($_GET['v']) : ($bs[0] ?? null);
    if (!$v) redirect(url('bike_service'));
    $kind = array_key_exists($_GET['kind'] ?? '', SERVICE_KINDS) ? $_GET['kind'] : 'inspektion';
    $s = ['id' => 0, 'vehicle_id' => $v['id'], 'kind' => $kind, 'title' => '', 'status' => $_GET['status'] ?? 'erledigt', 'date' => today(), 'odometer' => current_km($v),
        'done_by' => 'werkstatt', 'workshop' => '', 'work' => '', 'amount' => null, 'cost_id' => null, 'note' => ''];
}
if (!in_array($s['status'], ['geplant', 'erledigt'], true)) $s['status'] = 'erledigt';
// Archivierte Räder bleiben auswählbar, wenn der Eintrag zu ihnen gehört
if (!in_array((int)$v['id'], array_map('intval', array_column($bs, 'id')), true)) $bs[] = $v;
$back = safe_target($_GET['back'] ?? '', url('bike_service'));

if (is_post()) {
    csrf_check();
    $do = $_POST['do'] ?? 'save';
    if ($id && $do === 'delete') {
        sync_service_cost(['status' => 'geplant'] + $s);
        q('UPDATE documents SET service_id = NULL WHERE service_id = ?', [$id]);
        q('DELETE FROM services WHERE id = ?', [$id]);
        flash('Service gelöscht.');
        redirect($back);
    }
    $nv = vehicle_or_404($_POST['vehicle_id'] ?? 0);
    if (vehicle_group($nv) !== 'fahrraeder') not_found();
    $kind = array_key_exists($_POST['kind'] ?? '', SERVICE_KINDS) ? $_POST['kind'] : 'sonstiges';
    $status = ($_POST['status'] ?? '') === 'geplant' ? 'geplant' : 'erledigt';
    $tasks = array_values(array_intersect((array)($_POST['work'] ?? []), SERVICE_KINDS[$kind][3]));
    $extra = array_filter(array_map('trim', explode("\n", str_replace("\r", '', (string)($_POST['work_extra'] ?? '')))));
    $data = [
        'vehicle_id' => (int)$nv['id'], 'kind' => $kind, 'status' => $status,
        'title' => str_or_null($_POST['title'] ?? '', 160) ?? service_label($kind),
        'date' => date_or_null($_POST['date'] ?? '') ?? ($status === 'erledigt' ? today() : null),
        'odometer' => int_or_null($_POST['odometer'] ?? ''),
        'done_by' => ($_POST['done_by'] ?? '') === 'selbst' ? 'selbst' : 'werkstatt',
        'workshop' => str_or_null($_POST['workshop'] ?? '', 120),
        'work' => mb_substr(implode("\n", array_merge($tasks, $extra)), 0, 4000) ?: null,
        'amount' => num($_POST['amount'] ?? ''),
        'note' => str_or_null($_POST['note'] ?? '', 2000),
    ];
    if ($id) {
        update('services', $data, 'id = ?', [$id]);
    } else {
        $id = insert('services', $data + ['created_at' => now()]);
    }
    $costId = sync_service_cost(['cost_id' => $s['cost_id']] + $data);
    update('services', ['cost_id' => $costId], 'id = ?', [$id]);

    // Erledigt mit höherem Tachostand: Kilometerstand mitschreiben
    if ($status === 'erledigt' && $data['odometer'] !== null && $data['odometer'] > (int)current_km($nv)) {
        insert('odometer_log', ['vehicle_id' => $nv['id'], 'date' => $data['date'], 'km' => $data['odometer'], 'source' => 'manual', 'note' => $data['title'], 'created_at' => now()]);
    }

    // Rechnungen / Belege
    $n = 0;
    $files = $_FILES['files'] ?? null;
    if ($files && is_array($files['name'])) {
        foreach ($files['name'] as $i => $name) {
            $f = ['name' => $name, 'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i], 'size' => $files['size'][$i]];
            if ($up = store_upload($f)) {
                insert('documents', ['user_id' => uid(), 'vehicle_id' => $nv['id'], 'service_id' => $id, 'cost_id' => $costId, 'kind' => 'rechnung',
                    'title' => $data['title'] . ($data['date'] ? ' (' . fmt_date($data['date']) . ')' : ''), 'doc_date' => $data['date'],
                    'filename' => $up['name'], 'stored_name' => $up['stored'], 'mime' => $up['mime'], 'size' => $up['size'], 'created_at' => now()]);
                $n++;
            }
        }
    }
    if ($costId) q('UPDATE documents SET cost_id = ? WHERE service_id = ?', [$costId, $id]);

    $msg = $status === 'geplant' ? 'Service geplant.' : 'Service gespeichert.';
    $next = (int)($_POST['next_months'] ?? 0);
    if ($status === 'erledigt' && $next > 0 && $data['date']) {
        insert('services', ['vehicle_id' => $nv['id'], 'kind' => $kind, 'title' => $data['title'], 'status' => 'geplant', 'date' => add_months($data['date'], $next),
            'done_by' => $data['done_by'], 'workshop' => $data['workshop'], 'created_at' => now()]);
        $msg .= ' Nächster Termin: ' . fmt_date(add_months($data['date'], $next)) . '.';
    }
    flash($msg . ($n ? " $n Beleg" . ($n > 1 ? 'e' : '') . ' hochgeladen.' : ''));
    redirect($back);
}

// Gespeicherte Arbeiten in Vorlagen (Checkboxen) und freie Zeilen aufteilen
$lines = array_filter(array_map('trim', explode("\n", (string)$s['work'])));
$known = SERVICE_KINDS[$s['kind']][3] ?? [];
$checked = array_values(array_intersect($lines, $known));
$extra = implode("\n", array_diff($lines, $known));
$docs = $id ? rows('SELECT * FROM documents WHERE service_id = ? ORDER BY created_at', [$id]) : [];

page_header('Service', ['nav' => 'fahrraeder', 'back' => $back, 'heading' => $id ? $s['title'] : 'Neuer Service']);
?>
<div class="card form-card">
<?= form_open('', ['files' => true, 'class' => 'form']) ?>
  <div data-service>
  <div class="form-grid">
    <?= f_select('vehicle_id', 'Fahrrad', array_column($bs, 'name', 'id'), $s['vehicle_id'], ['required' => true]) ?>
    <label class="field"><span class="label">Art</span><span class="control select"><select name="kind" id="svc-kind">
      <?php foreach (SERVICE_KINDS as $k => [$l, , $months]): ?><option value="<?= e($k) ?>" data-months="<?= e((string)$months) ?>"<?= $s['kind'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
    </select><?= icon('chevron-down', 'select-ic') ?></span></label>

    <div class="field full"><span class="label">Status</span>
      <div class="radio-chips chips">
        <?php foreach (['erledigt' => ['Erledigt', 'check-circle'], 'geplant' => ['Geplant', 'calendar']] as $k => [$l, $ic]): ?>
          <label><input type="radio" name="status" value="<?= $k ?>"<?= $s['status'] === $k ? ' checked' : '' ?>><span class="chip"><?= icon($ic) ?><?= $l ?></span></label>
        <?php endforeach; ?>
      </div>
    </div>
    <?= f_date('date', 'Datum', $s['date'], ['hint' => '<span data-when="geplant">Termin, an dem der Service ansteht.</span><span data-when="erledigt">Tag, an dem der Service gemacht wurde.</span>']) ?>
    <?= f_num('odometer', 'Kilometerstand', $s['odometer'], ['dec' => 0, 'suffix' => 'km']) ?>
    <?= f_text('title', 'Bezeichnung', $s['title'] === service_label($s['kind']) ? '' : $s['title'], ['placeholder' => 'optional – sonst die Art', 'class' => 'full']) ?>

    <div class="form-section">Wer macht's?</div>
    <div class="field full">
      <div class="radio-chips chips">
        <?php foreach (['werkstatt' => ['Werkstatt', 'home'], 'selbst' => ['Selbst', 'wrench']] as $k => [$l, $ic]): ?>
          <label><input type="radio" name="done_by" value="<?= $k ?>"<?= $s['done_by'] === $k ? ' checked' : '' ?>><span class="chip"><?= icon($ic) ?><?= $l ?></span></label>
        <?php endforeach; ?>
      </div>
    </div>
    <?= f_text('workshop', 'Werkstatt', $s['workshop'], ['placeholder' => 'z. B. Radhaus Müller', 'class' => 'full', 'list' => 'workshops']) ?>

    <div class="form-section">Arbeiten</div>
    <?php foreach (SERVICE_KINDS as $k => [, , , $tasks]): if (!$tasks) continue; ?>
      <fieldset class="full task-list" data-tasks="<?= e($k) ?>"<?= $k !== $s['kind'] ? ' hidden' : '' ?>>
        <?php foreach ($tasks as $t): ?>
          <label class="check"><input type="checkbox" name="work[]" value="<?= e($t) ?>"<?= $k === $s['kind'] && in_array($t, $checked, true) ? ' checked' : '' ?>><?= e($t) ?></label>
        <?php endforeach; ?>
      </fieldset>
    <?php endforeach; ?>
    <div class="full"><?= f_textarea('work_extra', 'Weitere Arbeiten & Teile', $extra, ['rows' => 3, 'placeholder' => 'eine Arbeit pro Zeile, z. B. „Schlauch hinten gewechselt“']) ?></div>

    <div class="form-section">Kosten & Belege</div>
    <?= f_num('amount', 'Kosten', $s['amount'], ['suffix' => '€', 'hint' => '<span data-when="erledigt">Wird als Kosten „Wartung“ verbucht.</span><span data-when="geplant">Voraussichtlich – verbucht wird erst beim Erledigen.</span>']) ?>
    <label class="field"><span class="label">Rechnung / Beleg</span><span class="control"><input type="file" name="files[]" multiple accept="application/pdf,image/*"></span><small class="hint">PDF oder Foto · max. 20 MB</small></label>
    <?php if ($docs): ?>
      <div class="full file-list">
        <?php foreach ($docs as $d): ?><a class="chip" href="<?= e(url('file', ['f' => $d['stored_name'], 'name' => $d['filename']])) ?>" target="_blank" rel="noopener"><?= icon('file') ?><?= e($d['filename']) ?></a><?php endforeach; ?>
      </div>
    <?php endif; ?>
    <div class="full"><?= f_textarea('note', 'Notiz', $s['note'], ['rows' => 2]) ?></div>

    <div class="full" data-when="erledigt">
      <?= f_select('next_months', 'Nächsten Service gleich planen', ['0' => 'Nein', '3' => 'in 3 Monaten', '6' => 'in 6 Monaten', '12' => 'in 12 Monaten', '24' => 'in 24 Monaten'],
          $id ? '0' : (string)(SERVICE_KINDS[$s['kind']][2] ?? 0), ['id' => 'svc-next']) ?>
    </div>
  </div>
  </div>
  <div class="form-actions">
    <a class="btn btn-ghost" href="<?= e($back) ?>">Abbrechen</a>
    <button class="btn btn-primary" type="submit"><?= icon('check') ?> Speichern</button>
  </div>
</form>
<datalist id="workshops"><?php foreach (rows("SELECT DISTINCT s.workshop FROM services s JOIN vehicles v ON v.id = s.vehicle_id WHERE v.user_id = ? AND s.workshop IS NOT NULL ORDER BY s.workshop", [uid()]) as $w): ?><option value="<?= e($w['workshop']) ?>"><?php endforeach; ?></datalist>
</div>
<?php if ($id): ?><div class="mt"><?= delete_button('', 'Den Service') ?></div><?php endif;
page_footer();
