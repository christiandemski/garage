<?php
declare(strict_types=1);

$id = (int)($_GET['id'] ?? 0);
if ($id) {
    $p = owned('parts', $id);
    $v = vehicle_or_404($p['vehicle_id']);
} else {
    $v = vehicle_or_404($_GET['v'] ?? 0);
    $cat = $_GET['cat'] ?? (is_motor($v) ? 'reifen' : 'kette');
    $p = ['id' => 0, 'category' => $cat, 'name' => '', 'brand' => null, 'installed_date' => today(), 'installed_km' => current_km($v),
        'limit_km' => is_motor($v) ? null : (PART_CATEGORIES[$cat][1] ?? null), 'limit_months' => null, 'removed_date' => null, 'removed_km' => null,
        'price' => null, 'season' => null, 'dot' => null, 'tread_mm' => null, 'storage' => null, 'note' => null];
}
$vid = (int)$v['id'];
$back = url('vehicle', ['id' => $vid, 'tab' => 'parts']);
$km = current_km($v);

if (is_post()) {
    csrf_check();
    $do = $_POST['do'] ?? 'save';
    if ($id && $do === 'delete') {
        q('DELETE FROM parts WHERE id = ?', [$id]);
        flash('Teil gelöscht.');
        redirect($back);
    }
    if ($id && $do === 'remove') {
        update('parts', ['removed_date' => date_or_null($_POST['removed_date']) ?? today(), 'removed_km' => int_or_null($_POST['removed_km']) ?? $km, 'storage' => str_or_null($_POST['storage'] ?? '', 120)], 'id = ?', [$id]);
        flash('Als ausgebaut markiert.');
        redirect($back);
    }
    if ($id && $do === 'mount') {
        update('parts', ['removed_date' => null, 'removed_km' => null], 'id = ?', [$id]);
        // Gefahrene km bis zum Ausbau bleiben erhalten: Einbau-km so verschieben, dass die bisherige Laufleistung mitzählt
        $used = ($p['removed_km'] !== null && $p['installed_km'] !== null) ? (int)$p['removed_km'] - (int)$p['installed_km'] : 0;
        if ($km !== null) update('parts', ['installed_km' => $km - $used, 'installed_date' => today()], 'id = ?', [$id]);
        flash('Wieder montiert – die bisherige Laufleistung wird weitergezählt.');
        redirect($back);
    }
    $cat = array_key_exists($_POST['category'] ?? '', PART_CATEGORIES) ? $_POST['category'] : 'sonstiges';
    $data = [
        'vehicle_id' => $vid, 'category' => $cat, 'name' => str_or_null($_POST['name'], 160) ?? part_label($cat), 'brand' => str_or_null($_POST['brand'] ?? '', 80),
        'installed_date' => date_or_null($_POST['installed_date']), 'installed_km' => int_or_null($_POST['installed_km']),
        'limit_km' => int_or_null($_POST['limit_km']), 'price' => num($_POST['price'] ?? ''),
        'season' => in_array($_POST['season'] ?? '', ['sommer', 'winter', 'ganzjahr'], true) ? $_POST['season'] : null,
        'dot' => str_or_null($_POST['dot'] ?? '', 10), 'tread_mm' => num($_POST['tread_mm'] ?? ''), 'storage' => str_or_null($_POST['storage'] ?? '', 120),
        'note' => str_or_null($_POST['note'] ?? '', 2000),
    ];
    if ($id) {
        update('parts', $data, 'id = ?', [$id]);
    } else {
        $data['created_at'] = now();
        insert('parts', $data);
        if (!empty($_POST['add_cost']) && $data['price']) {
            insert('costs', ['vehicle_id' => $vid, 'date' => $data['installed_date'] ?? today(), 'category' => $cat === 'reifen' ? 'reifen' : 'teile', 'title' => $data['name'], 'amount' => $data['price'], 'odometer' => $data['installed_km'], 'created_at' => now()]);
        }
    }
    flash('Gespeichert.');
    redirect($back);
}

$w = $id ? part_wear($p, $km) : null;
page_header('Teil', ['nav' => 'vehicles', 'back' => $back, 'heading' => ($id ? $p['name'] : 'Neues Teil') . ' · ' . $v['name']]);
if ($w && $w['used'] !== null): ?>
  <div class="card form-card mb">
    <div class="row-between"><div><div class="muted small">Laufleistung</div><div class="kpi-value"><?= fmt_km($w['used']) ?></div></div>
    <?php if ($w['pct'] !== null): ?><div class="right"><div class="muted small">von <?= fmt_km($p['limit_km']) ?></div><div class="kpi-value"><?= round($w['pct']) ?> %</div></div><?php endif; ?></div>
    <?php if ($w['pct'] !== null): ?><div class="wear <?= $w['pct'] >= 100 ? 'overdue' : ($w['pct'] >= 80 ? 'soon' : '') ?>"><span style="width:<?= min(100, round($w['pct'])) ?>%"></span></div><?php endif; ?>
  </div>
<?php endif; ?>
<div class="card form-card">
<?= form_open() ?>
  <div class="form-grid">
    <label class="field"><span class="label">Kategorie</span><span class="control select"><select name="category" id="part-cat">
      <?php foreach (PART_CATEGORIES as $k => [$label, $lim]): ?><option value="<?= e($k) ?>" data-km="<?= e(is_motor($v) ? '' : $lim) ?>"<?= $p['category'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
    </select><?= icon('chevron-down', 'select-ic') ?></span></label>
    <?= f_text('name', 'Bezeichnung', $p['name'], ['required' => true, 'placeholder' => is_motor($v) ? 'z. B. Winterreifen Conti' : 'z. B. Shimano CN-M8100']) ?>
    <?= f_text('brand', 'Marke / Modell', $p['brand'], ['placeholder' => 'optional']) ?>
    <?= f_num('price', 'Preis', $p['price'], ['suffix' => '€']) ?>
    <div class="form-section">Einbau & Laufleistung</div>
    <?= f_date('installed_date', 'Eingebaut am', $p['installed_date']) ?>
    <?= f_num('installed_km', 'bei Kilometerstand', $p['installed_km'], ['dec' => 0, 'suffix' => 'km']) ?>
    <?= f_num('limit_km', 'Erwartete Laufleistung', $p['limit_km'], ['dec' => 0, 'suffix' => 'km', 'hint' => 'Ab 80 % erscheint ein Hinweis.', 'class' => 'full']) ?>
    <div class="form-section">Reifen (optional)</div>
    <?= f_select('season', 'Saison', ['sommer' => 'Sommer', 'winter' => 'Winter', 'ganzjahr' => 'Ganzjahr'], $p['season'], ['empty' => '–']) ?>
    <?= f_text('dot', 'DOT', $p['dot'], ['placeholder' => 'z. B. 2324', 'maxlength' => 10]) ?>
    <?= f_num('tread_mm', 'Profiltiefe', $p['tread_mm'], ['dec' => 1, 'suffix' => 'mm']) ?>
    <?= f_text('storage', 'Lagerort', $p['storage'], ['placeholder' => 'z. B. Keller, Reifenhotel']) ?>
    <div class="full"><?= f_textarea('note', 'Notiz', $p['note'], ['rows' => 2]) ?></div>
    <?php if (!$id): ?><div class="full"><?= f_toggle('add_cost', 'Preis als Kosten verbuchen', true) ?></div><?php endif; ?>
  </div>
  <div class="form-actions">
    <a class="btn btn-ghost" href="<?= e($back) ?>">Abbrechen</a>
    <button class="btn btn-primary" type="submit"><?= icon('check') ?> Speichern</button>
  </div>
</form>
</div>

<?php if ($id): ?>
<div class="card form-card mt">
  <?php if (!$p['removed_date']): ?>
    <h2>Ausbauen / einlagern</h2>
    <form method="post" class="form mt"><?= csrf_field() ?><input type="hidden" name="do" value="remove">
      <div class="form-grid">
        <?= f_date('removed_date', 'Ausgebaut am', today()) ?>
        <?= f_num('removed_km', 'bei Kilometerstand', $km, ['dec' => 0, 'suffix' => 'km']) ?>
        <div class="full"><?= f_text('storage', 'Lagerort (optional)', $p['storage']) ?></div>
      </div>
      <div class="form-actions"><button class="btn" type="submit"><?= icon('archive') ?> Ausbauen</button></div>
    </form>
  <?php else: ?>
    <h2>Ausgebaut am <?= fmt_date($p['removed_date']) ?></h2>
    <p class="muted small mt">Beim erneuten Montieren (z. B. Saisonwechsel) wird die bisherige Laufleistung weitergezählt.</p>
    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="mount"><button class="btn btn-primary" type="submit"><?= icon('refresh') ?> Wieder montieren</button></form>
  <?php endif; ?>
</div>
<div class="mt"><?= delete_button('', 'Das Teil') ?></div>
<?php endif;
page_footer();
