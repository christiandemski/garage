<?php
declare(strict_types=1);

$id = (int)($_GET['id'] ?? 0);
if ($id) {
    $d = owned('documents', $id);
    $v = vehicle_or_404($d['vehicle_id']);
} else {
    $v = vehicle_or_404($_GET['v'] ?? 0);
    $d = ['id' => 0, 'title' => '', 'kind' => $_GET['kind'] ?? 'rechnung'];
}
$vid = (int)$v['id'];
$back = url('vehicle', ['id' => $vid, 'tab' => 'docs']);

if (is_post()) {
    csrf_check();
    if ($id && ($_POST['do'] ?? '') === 'delete') {
        @unlink(upload_dir() . '/' . basename($d['stored_name']));
        q('DELETE FROM documents WHERE id = ?', [$id]);
        flash('Dokument gelöscht.');
        redirect($back);
    }
    $kind = array_key_exists($_POST['kind'] ?? '', DOC_KINDS) ? $_POST['kind'] : 'sonstiges';
    $title = str_or_null($_POST['title'], 160);
    if ($id) {
        update('documents', ['title' => $title ?? $d['title'], 'kind' => $kind], 'id = ?', [$id]);
        flash('Gespeichert.');
        redirect($back);
    }
    $files = $_FILES['files'] ?? null;
    $n = 0;
    if ($files && is_array($files['name'])) {
        foreach ($files['name'] as $i => $name) {
            $f = ['name' => $name, 'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i], 'size' => $files['size'][$i]];
            if ($up = store_upload($f)) {
                insert('documents', ['vehicle_id' => $vid, 'title' => $title && count($files['name']) === 1 ? $title : pathinfo($up['name'], PATHINFO_FILENAME),
                    'kind' => $kind, 'filename' => $up['name'], 'stored_name' => $up['stored'], 'mime' => $up['mime'], 'size' => $up['size'], 'created_at' => now()]);
                $n++;
            }
        }
    }
    if ($n) { flash($n === 1 ? 'Dokument hochgeladen.' : "$n Dokumente hochgeladen."); redirect($back); }
    flash('Bitte eine Datei auswählen.', 'err');
}

page_header('Dokument', ['nav' => 'vehicles', 'back' => $back, 'heading' => ($id ? 'Dokument bearbeiten' : 'Dokument hochladen') . ' · ' . $v['name']]);
?>
<div class="card form-card">
<?= form_open('', ['files' => true]) ?>
  <div class="form-grid">
    <?= f_select('kind', 'Art', DOC_KINDS, $d['kind']) ?>
    <?= f_text('title', 'Titel', $d['title'], ['placeholder' => $id ? '' : 'optional – sonst Dateiname']) ?>
    <?php if (!$id): ?>
      <label class="field full"><span class="label">Dateien</span><span class="control"><input type="file" name="files[]" multiple accept="application/pdf,image/*" required></span><small class="hint">PDF, JPG, PNG, WEBP · max. 20 MB je Datei</small></label>
    <?php else: ?>
      <div class="full"><a class="btn" href="<?= e(url('file', ['f' => $d['stored_name'], 'name' => $d['filename']])) ?>" target="_blank"><?= icon('eye') ?> <?= e($d['filename']) ?></a></div>
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
