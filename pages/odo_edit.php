<?php
declare(strict_types=1);

$id = (int)($_GET['id'] ?? 0);
if ($id) {
    $o = owned('odometer_log', $id);
    $v = vehicle_or_404($o['vehicle_id']);
} else {
    $v = vehicle_or_404($_GET['v'] ?? 0);
    $o = ['id' => 0, 'date' => today(), 'km' => null, 'trip' => null, 'note' => null, 'source' => 'manual'];
}
$vid = (int)$v['id'];
$back = url('vehicle', ['id' => $vid, 'tab' => 'km']);
$cur = current_km($v);

if (is_post()) {
    csrf_check();
    if ($id && ($_POST['do'] ?? '') === 'delete') {
        q('DELETE FROM odometer_log WHERE id = ?', [$id]);
        flash('Eintrag gelöscht.');
        redirect($back);
    }
    $mode = $_POST['mode'] ?? 'km';
    $trip = num($_POST['trip'] ?? '');
    $km = int_or_null($_POST['km'] ?? '');
    if ($mode === 'trip' && !$id) {
        if (!$trip) { flash('Bitte die gefahrene Strecke angeben.', 'err'); redirect($_SERVER['REQUEST_URI']); }
        $km = (int)round(($cur ?? 0) + $trip);
    }
    if ($km === null) {
        flash('Bitte einen Kilometerstand angeben.', 'err');
    } else {
        $data = ['vehicle_id' => $vid, 'date' => date_or_null($_POST['date']) ?? today(), 'km' => $km, 'trip' => $mode === 'trip' ? $trip : ($id ? $o['trip'] : null), 'note' => str_or_null($_POST['note'] ?? '', 200)];
        if ($id) update('odometer_log', $data, 'id = ?', [$id]);
        else { $data += ['source' => 'manual', 'created_at' => now()]; insert('odometer_log', $data); }
        flash('Kilometerstand gespeichert: ' . fmt_km($km));
        redirect($back);
    }
}

$bike = !is_motor($v);
page_header('Kilometer', ['nav' => 'vehicles', 'back' => $back, 'heading' => 'Kilometer · ' . $v['name']]);
?>
<div class="card form-card">
<?= form_open() ?>
  <?php if (!$id): ?>
  <div class="seg" role="radiogroup">
    <label><input type="radio" name="mode" value="<?= $bike ? 'trip' : 'km' ?>" checked hidden onchange="document.getElementById('m-trip').hidden=this.value!=='trip';document.getElementById('m-km').hidden=this.value==='trip'"><span class="seg-btn on"><?= $bike ? 'Fahrt hinzufügen' : 'Kilometerstand' ?></span></label>
    <label><input type="radio" name="mode" value="<?= $bike ? 'km' : 'trip' ?>" hidden onchange="document.getElementById('m-trip').hidden=this.value!=='trip';document.getElementById('m-km').hidden=this.value==='trip'"><span class="seg-btn"><?= $bike ? 'Tachostand' : 'Fahrt hinzufügen' ?></span></label>
  </div>
  <script>document.currentScript.previousElementSibling.addEventListener('change',e=>{e.currentTarget.querySelectorAll('.seg-btn').forEach(b=>b.classList.toggle('on',b.previousElementSibling.checked))});</script>
  <?php endif; ?>
  <div class="form-grid">
    <?= f_date('date', 'Datum', $o['date'], ['required' => true]) ?>
    <div id="m-km" <?= (!$id && $bike) ? 'hidden' : '' ?>><?= f_num('km', 'Kilometerstand', $o['km'], ['dec' => 0, 'suffix' => 'km', 'hint' => $cur !== null ? 'Bisher ' . fmt_km($cur) : null]) ?></div>
    <?php if (!$id): ?><div id="m-trip" <?= $bike ? '' : 'hidden' ?>><?= f_num('trip', 'Gefahrene Strecke', null, ['dec' => 1, 'suffix' => 'km', 'hint' => 'Wird zum aktuellen Stand' . ($cur !== null ? ' (' . fmt_km($cur) . ')' : '') . ' addiert']) ?></div><?php endif; ?>
    <div class="full"><?= f_text('note', 'Notiz', $o['note'], ['placeholder' => 'z. B. Tour, Urlaubsfahrt']) ?></div>
  </div>
  <?php if ($o['source'] !== 'manual'): ?><div class="banner"><?= icon('sync') ?><p>Automatisch übernommen (<?= e($o['source']) ?>).</p></div><?php endif; ?>
  <div class="form-actions">
    <a class="btn btn-ghost" href="<?= e($back) ?>">Abbrechen</a>
    <button class="btn btn-primary" type="submit"><?= icon('check') ?> Speichern</button>
  </div>
</form>
</div>
<?php if ($id): ?><div class="mt"><?= delete_button('', 'Den Eintrag') ?></div><?php endif;
page_footer();
