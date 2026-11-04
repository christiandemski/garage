<?php
declare(strict_types=1);

$cats = stock_categories();
$id = (int)($_GET['id'] ?? 0);
if ($id) {
    $it = row('SELECT * FROM stock_items WHERE id = ? AND user_id = ?', [$id, uid()]);
    if (!$it) not_found();
} else {
    $it = ['id' => 0, 'category' => isset($cats[$_GET['cat'] ?? '']) ? $_GET['cat'] : 'kette', 'name' => '', 'brand' => '', 'quantity' => 1, 'location' => '',
        'price' => null, 'purchase_date' => today(), 'shop' => '', 'fits' => '', 'note' => ''];
}
$back = url('bike_stock');
$bs = bikes();

if (is_post()) {
    csrf_check();
    $do = $_POST['do'] ?? 'save';
    if ($id && $do === 'delete') {
        q('DELETE FROM stock_items WHERE id = ?', [$id]);
        flash('Artikel gelöscht.');
        redirect($back);
    }
    if ($id && $do === 'qty') {
        $q = max(0, (int)$it['quantity'] + ((int)($_POST['delta'] ?? 0) < 0 ? -1 : 1));
        update('stock_items', ['quantity' => $q], 'id = ?', [$id]);
        redirect(url('stock_edit', ['id' => $id]));
    }
    if ($id && $do === 'mount') {
        $v = vehicle_or_404($_POST['vehicle_id'] ?? 0);
        if ((int)$it['quantity'] <= 0 || !stock_mountable($it['category'])) { flash('Dieser Artikel kann nicht eingebaut werden.', 'err'); redirect(url('stock_edit', ['id' => $id])); }
        $date = date_or_null($_POST['date'] ?? '') ?? today();
        $km = int_or_null($_POST['km'] ?? '') ?? current_km($v);
        insert('parts', ['vehicle_id' => $v['id'], 'category' => $it['category'], 'name' => $it['name'], 'brand' => $it['brand'],
            'installed_date' => $date, 'installed_km' => $km, 'limit_km' => int_or_null($_POST['limit_km'] ?? ''), 'price' => $it['price'],
            'note' => 'Aus dem Lager' . ($it['shop'] ? ' · gekauft bei ' . $it['shop'] : ''), 'created_at' => now()]);
        if (!empty($_POST['add_cost']) && $it['price']) {
            insert('costs', ['vehicle_id' => $v['id'], 'date' => $date, 'category' => $it['category'] === 'reifen' ? 'reifen' : 'teile', 'title' => $it['name'],
                'amount' => $it['price'], 'odometer' => $km, 'vendor' => $it['shop'] ?: null, 'created_at' => now()]);
        }
        update('stock_items', ['quantity' => (int)$it['quantity'] - 1], 'id = ?', [$id]);
        flash($it['name'] . ' ist jetzt an ' . $v['name'] . ' montiert.');
        redirect(url('vehicle', ['id' => $v['id'], 'tab' => 'parts']));
    }
    $cat = isset($cats[$_POST['category'] ?? '']) ? $_POST['category'] : 'sonstiges';
    $data = [
        'category' => $cat, 'name' => str_or_null($_POST['name'] ?? '', 160) ?? $cats[$cat],
        'brand' => str_or_null($_POST['brand'] ?? '', 80), 'quantity' => max(0, (int)($_POST['quantity'] ?? 1)),
        'location' => str_or_null($_POST['location'] ?? '', 120), 'price' => num($_POST['price'] ?? ''),
        'purchase_date' => date_or_null($_POST['purchase_date'] ?? ''), 'shop' => str_or_null($_POST['shop'] ?? '', 120),
        'fits' => str_or_null($_POST['fits'] ?? '', 160), 'note' => str_or_null($_POST['note'] ?? '', 2000),
    ];
    if ($id) update('stock_items', $data, 'id = ?', [$id]);
    else insert('stock_items', $data + ['user_id' => uid(), 'created_at' => now()]);
    flash('Gespeichert.');
    redirect($back);
}

$locations = array_column(rows('SELECT DISTINCT location FROM stock_items WHERE user_id = ? AND location IS NOT NULL ORDER BY location', [uid()]), 'location');
page_header('Lager', ['nav' => 'fahrraeder', 'back' => $back, 'heading' => $id ? $it['name'] : 'Neuer Lagerartikel']);

if ($id): ?>
<div class="card form-card mb">
  <div class="row-between">
    <div><div class="muted small">Auf Lager</div><div class="kpi-value"><?= (int)$it['quantity'] ?> <small>Stück</small></div></div>
    <div class="row">
      <?php foreach ([-1 => ['Einen entnehmen', 'x'], 1 => ['Einen hinzufügen', 'plus']] as $d => [$l, $ic]): ?>
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="qty"><input type="hidden" name="delta" value="<?= $d ?>">
          <button class="btn qty-btn" type="submit" aria-label="<?= $l ?>" title="<?= $l ?>"<?= $d < 0 && (int)$it['quantity'] <= 0 ? ' disabled' : '' ?> data-keep><?= $d < 0 ? '−' : '+' ?></button></form>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="card form-card">
<?= form_open() ?>
  <div class="form-grid">
    <?= f_select('category', 'Kategorie', $cats, $it['category']) ?>
    <?= f_num('quantity', 'Menge', $it['quantity'], ['dec' => 0, 'suffix' => 'Stk.', 'min' => 0]) ?>
    <?= f_text('name', 'Bezeichnung', $it['name'], ['required' => true, 'placeholder' => 'z. B. Shimano CN-M8100', 'class' => 'full']) ?>
    <?= f_text('brand', 'Marke / Modell', $it['brand'], ['placeholder' => 'optional']) ?>
    <?= f_text('fits', 'Passt zu', $it['fits'], ['placeholder' => 'z. B. ' . ($bs[0]['name'] ?? 'Gravelbike') . ', 12-fach', 'list' => 'bike-names']) ?>
    <?= f_text('location', 'Lagerort', $it['location'], ['placeholder' => 'z. B. Keller, Regal 2', 'list' => 'stock-locations', 'class' => 'full']) ?>
    <div class="form-section">Kauf</div>
    <?= f_num('price', 'Stückpreis', $it['price'], ['suffix' => '€']) ?>
    <?= f_date('purchase_date', 'Gekauft am', $it['purchase_date']) ?>
    <?= f_text('shop', 'Händler', $it['shop'], ['placeholder' => 'optional', 'class' => 'full']) ?>
    <div class="full"><?= f_textarea('note', 'Notiz', $it['note'], ['rows' => 2]) ?></div>
  </div>
  <div class="form-actions">
    <a class="btn btn-ghost" href="<?= e($back) ?>">Abbrechen</a>
    <button class="btn btn-primary" type="submit"><?= icon('check') ?> Speichern</button>
  </div>
</form>
<datalist id="stock-locations"><?php foreach ($locations as $l): ?><option value="<?= e($l) ?>"><?php endforeach; ?></datalist>
<datalist id="bike-names"><?php foreach ($bs as $b): ?><option value="<?= e($b['name']) ?>"><?php endforeach; ?></datalist>
</div>

<?php if ($id && stock_mountable($it['category']) && $bs && (int)$it['quantity'] > 0): ?>
<div class="card form-card mt">
  <h2>An ein Rad montieren</h2>
  <p class="muted small mt-s">Legt das Teil mit Einbau-km am Rad an – die Laufleistung zählt ab jetzt. Der Bestand sinkt um 1.</p>
  <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="do" value="mount">
    <div class="form-grid">
      <?= f_select('vehicle_id', 'Fahrrad', array_column($bs, 'name', 'id'), $bs[0]['id']) ?>
      <?= f_date('date', 'Eingebaut am', today()) ?>
      <?= f_num('km', 'bei Kilometerstand', null, ['dec' => 0, 'suffix' => 'km', 'placeholder' => 'leer = aktueller Stand']) ?>
      <?= f_num('limit_km', 'Erwartete Laufleistung', PART_CATEGORIES[$it['category']][1] ?? null, ['dec' => 0, 'suffix' => 'km']) ?>
      <?php if ($it['price']): ?><div class="full"><?= f_toggle('add_cost', 'Preis beim Rad als Kosten verbuchen', true, fmt_money($it['price']) . ' · Kategorie „' . cat_label($it['category'] === 'reifen' ? 'reifen' : 'teile') . '“') ?></div><?php endif; ?>
    </div>
    <div class="form-actions"><button class="btn btn-primary" type="submit"><?= icon('bike') ?> Montieren</button></div>
  </form>
</div>
<?php endif; ?>
<?php if ($id): ?><div class="mt"><?= delete_button('', 'Den Artikel') ?></div><?php endif;
page_footer();
