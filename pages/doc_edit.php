<?php
declare(strict_types=1);

$id = (int)($_GET['id'] ?? 0);
$v = null;
if ($id) {
    $d = owned_doc($id);
    if ($d['vehicle_id']) $v = vehicle_or_404($d['vehicle_id']);
} else {
    if (!empty($_GET['v'])) $v = vehicle_or_404($_GET['v']);
    $d = ['id' => 0, 'title' => '', 'kind' => isset(DOC_KINDS[$_GET['kind'] ?? '']) ? $_GET['kind'] : 'rechnung', 'vehicle_id' => $v['id'] ?? null,
        'area' => null, 'doc_date' => null, 'valid_until' => null];
    if (!$v && ($_GET['area'] ?? '') !== 'fahrraeder') redirect(url('documents'));
}
// Fahrradbereich: Rad frei wählbar, auch „Allgemein“ ohne Rad
$bikeArea = ($_GET['area'] ?? '') === 'fahrraeder' || (!$v) || vehicle_group($v) === 'fahrraeder';
$bs = $bikeArea ? bikes() : [];
if ($bikeArea && $v && !in_array((int)$v['id'], array_map('intval', array_column($bs, 'id')), true)) $bs[] = $v;
$fallback = $bikeArea && (($_GET['area'] ?? '') === 'fahrraeder' || !$v) ? url('bike_docs') : url('vehicle', ['id' => $v['id'] ?? 0, 'tab' => 'docs']);
$back = safe_target($_POST['_back'] ?? $_GET['back'] ?? '', $fallback);

if (is_post()) {
    csrf_check();
    if ($id && ($_POST['do'] ?? '') === 'delete') {
        @unlink(upload_dir() . '/' . basename($d['stored_name']));
        q('DELETE FROM documents WHERE id = ?', [$id]);
        flash('Dokument gelöscht.');
        redirect($back);
    }
    $vid = $v['id'] ?? null;
    if ($bikeArea) {
        $sel = (string)($_POST['vehicle_id'] ?? '');
        $vid = $sel === '' ? null : (int)vehicle_or_404($sel)['id'];
    }
    $kind = array_key_exists($_POST['kind'] ?? '', DOC_KINDS) ? $_POST['kind'] : 'sonstiges';
    $title = str_or_null($_POST['title'] ?? '', 160);
    $meta = ['kind' => $kind, 'vehicle_id' => $vid, 'area' => $vid ? null : 'fahrraeder', 'user_id' => uid(),
        'doc_date' => date_or_null($_POST['doc_date'] ?? ''), 'valid_until' => date_or_null($_POST['valid_until'] ?? '')];
    if ($id) {
        update('documents', ['title' => $title ?? $d['title']] + $meta, 'id = ?', [$id]);
        flash('Gespeichert.');
        redirect($back);
    }
    $files = $_FILES['files'] ?? null;
    $n = 0;
    if ($files && is_array($files['name'])) {
        foreach ($files['name'] as $i => $name) {
            $f = ['name' => $name, 'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i], 'size' => $files['size'][$i]];
            if ($up = store_upload($f)) {
                insert('documents', $meta + ['title' => $title && count($files['name']) === 1 ? $title : pathinfo($up['name'], PATHINFO_FILENAME),
                    'filename' => $up['name'], 'stored_name' => $up['stored'], 'mime' => $up['mime'], 'size' => $up['size'], 'created_at' => now()]);
                $n++;
            }
        }
    }
    if ($n) { flash($n === 1 ? 'Dokument hochgeladen.' : "$n Dokumente hochgeladen."); redirect($back); }
    flash('Bitte eine Datei auswählen.', 'err');
}

$kinds = $bikeArea ? array_intersect_key(DOC_KINDS, array_flip(BIKE_DOC_KINDS)) + [$d['kind'] => DOC_KINDS[$d['kind']] ?? 'Sonstiges'] : DOC_KINDS;
$heading = ($id ? 'Dokument bearbeiten' : 'Dokument hochladen') . ($v && !$bikeArea ? ' · ' . $v['name'] : '');
page_header('Dokument', ['nav' => $v ? vehicle_group($v) : 'fahrraeder', 'back' => $back, 'heading' => $heading]);
?>
<div class="card form-card">
<?= form_open('', ['files' => true]) ?>
  <input type="hidden" name="_back" value="<?= e($back) ?>">
  <div class="form-grid">
    <?php if ($bikeArea): ?>
      <?= f_select('vehicle_id', 'Fahrrad', array_column($bs, 'name', 'id'), $d['vehicle_id'] ?? '', ['empty' => 'Allgemein (kein bestimmtes Rad)', 'class' => 'full']) ?>
    <?php endif; ?>
    <?= f_select('kind', 'Art', $kinds, $d['kind'], ['id' => 'doc-kind']) ?>
    <?= f_text('title', 'Titel', $d['title'], ['placeholder' => $id ? '' : 'optional – sonst Dateiname']) ?>
    <?= f_date('doc_date', 'Datum', $d['doc_date'], ['hint' => 'Rechnungs- bzw. Kaufdatum']) ?>
    <?= f_date('valid_until', 'Garantie bis', $d['valid_until'], ['hint' => 'Optional – 90 Tage vorher erscheint ein Hinweis.']) ?>
    <?php if (!$id): ?>
      <label class="field full"><span class="label">Dateien</span><span class="control"><input type="file" name="files[]" multiple accept="application/pdf,image/*" required></span><small class="hint">PDF, JPG, PNG, WEBP · max. 20 MB je Datei</small></label>
    <?php else: ?>
      <div class="full"><a class="btn" href="<?= e(url('file', ['f' => $d['stored_name'], 'name' => $d['filename']])) ?>" target="_blank" rel="noopener"><?= icon('eye') ?> <?= e($d['filename']) ?></a></div>
    <?php endif; ?>
  </div>
  <div class="form-actions">
    <a class="btn btn-ghost" href="<?= e($back) ?>">Abbrechen</a>
    <button class="btn btn-primary" type="submit"><?= icon($id ? 'check' : 'upload') ?> <?= $id ? 'Speichern' : 'Hochladen' ?></button>
  </div>
</form>
</div>
<?php if ($id): ?><div class="mt"><?= delete_button('', 'Das Dokument') ?></div><?php endif;
page_footer();
