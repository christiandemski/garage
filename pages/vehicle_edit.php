<?php
declare(strict_types=1);

$id = (int)($_GET['id'] ?? 0);
$v = $id ? vehicle_or_404($id) : [
    'id' => 0, 'type' => $_GET['type'] ?? 'auto', 'name' => '', 'make' => '', 'model' => '', 'variant' => '', 'year' => null,
    'plate' => '', 'vin' => '', 'hsn_tsn' => '', 'fuel_type' => 'benzin', 'tank_l' => null, 'color' => '#0f766e', 'photo' => null,
    'first_registration' => null, 'purchase_date' => null, 'purchase_price' => null, 'purchase_km' => null, 'tire_size' => '',
    'oil_spec' => '', 'insurance_company' => '', 'insurance_number' => '', 'frame_number' => '', 'notes' => '',
    'sm_vehicle_id' => '', 'sm_tank_id' => '', 'archived' => 0,
];

if (is_post()) {
    csrf_check();
    $do = $_POST['do'] ?? 'save';
    if ($id && $do === 'delete') {
        delete_vehicle_files($id);
        q('DELETE FROM vehicles WHERE id = ? AND user_id = ?', [$id, uid()]);
        flash('Fahrzeug gelöscht.');
        redirect(url('vehicles'));
    }
    if ($id && $do === 'archive') {
        update('vehicles', ['archived' => $v['archived'] ? 0 : 1], 'id = ?', [$id]);
        flash($v['archived'] ? 'Fahrzeug wieder aktiv.' : 'Fahrzeug archiviert.');
        redirect(url('vehicle', ['id' => $id]));
    }
    $type = array_key_exists($_POST['type'] ?? '', VEHICLE_TYPES) ? $_POST['type'] : 'auto';
    $motor = in_array($type, MOTOR_TYPES, true);
    $data = [
        'type' => $type,
        'name' => str_or_null($_POST['name'], 120) ?? 'Mein ' . VEHICLE_TYPES[$type],
        'make' => str_or_null($_POST['make'], 80),
        'model' => str_or_null($_POST['model'], 80),
        'variant' => str_or_null($_POST['variant'], 80),
        'year' => int_or_null($_POST['year']),
        'plate' => $motor ? str_or_null(mb_strtoupper((string)$_POST['plate']), 20) : null,
        'vin' => $motor ? str_or_null(mb_strtoupper(str_replace(' ', '', (string)$_POST['vin'])), 32) : null,
        'hsn_tsn' => $motor ? str_or_null($_POST['hsn_tsn'], 20) : null,
        'fuel_type' => $motor && array_key_exists($_POST['fuel_type'] ?? '', FUEL_TYPES) ? $_POST['fuel_type'] : null,
        'tank_l' => $motor ? num($_POST['tank_l']) : null,
        'color' => preg_match('/^#[0-9a-f]{6}$/i', (string)$_POST['color']) ? $_POST['color'] : '#0f766e',
        'first_registration' => $motor ? date_or_null($_POST['first_registration']) : null,
        'purchase_date' => date_or_null($_POST['purchase_date']),
        'purchase_price' => num($_POST['purchase_price'], true),
        'purchase_km' => int_or_null($_POST['purchase_km']),
        'tire_size' => str_or_null($_POST['tire_size'], 60),
        'oil_spec' => $motor ? str_or_null($_POST['oil_spec'], 60) : null,
        'insurance_company' => str_or_null($_POST['insurance_company'], 80),
        'insurance_number' => str_or_null($_POST['insurance_number'], 60),
        'frame_number' => !$motor ? str_or_null($_POST['frame_number'], 60) : null,
        'notes' => str_or_null($_POST['notes'], 5000),
        'sm_vehicle_id' => str_or_null($_POST['sm_vehicle_id'] ?? '', 20),
        'sm_tank_id' => str_or_null($_POST['sm_tank_id'] ?? '', 10),
    ];
    if (!empty($_FILES['photo']['name'])) {
        $photo = store_photo($_FILES['photo']);
        if ($photo) {
            if ($v['photo']) @unlink(upload_dir() . '/' . basename($v['photo']));
            $data['photo'] = $photo;
        }
    } elseif (!empty($_POST['remove_photo']) && $v['photo']) {
        @unlink(upload_dir() . '/' . basename($v['photo']));
        $data['photo'] = null;
    }
    if ($id) {
        update('vehicles', $data, 'id = ? AND user_id = ?', [$id, uid()]);
        flash('Gespeichert.');
    } else {
        $data += ['user_id' => uid(), 'created_at' => now(), 'sort' => (int)val('SELECT COUNT(*) FROM vehicles WHERE user_id = ?', [uid()])];
        $id = insert('vehicles', $data);
        // Startfristen
        if ($hu = date_or_null($_POST['next_hu'] ?? '')) {
            insert('reminders', ['vehicle_id' => $id, 'kind' => 'hu', 'title' => 'HU/AU (TÜV)', 'due_date' => $hu, 'interval_months' => 24, 'warn_days' => 45, 'warn_km' => 0, 'created_at' => now()]);
        }
        $sd = date_or_null($_POST['next_service'] ?? '');
        $sk = int_or_null($_POST['next_service_km'] ?? '');
        if ($sd || $sk) {
            insert('reminders', ['vehicle_id' => $id, 'kind' => 'inspektion', 'title' => 'Inspektion', 'due_date' => $sd, 'due_km' => $sk, 'interval_months' => $motor ? 24 : 12, 'interval_km' => $motor ? 30000 : null, 'warn_days' => 30, 'warn_km' => 1500, 'created_at' => now()]);
        }
        if ($data['purchase_km'] !== null) {
            insert('odometer_log', ['vehicle_id' => $id, 'date' => $data['purchase_date'] ?? today(), 'km' => $data['purchase_km'], 'source' => 'manual', 'note' => 'Startwert', 'created_at' => now()]);
        }
        flash($data['name'] . ' ist jetzt in deiner Garage.');
    }
    redirect(url('vehicle', ['id' => $id]));
}

$title = $id ? $v['name'] . ' bearbeiten' : 'Neues Fahrzeug';
page_header($title, ['nav' => 'vehicles', 'back' => $id ? url('vehicle', ['id' => $id]) : url('vehicles')]);
$types = VEHICLE_TYPES;
?>
<div class="card form-card">
<?= form_open('', ['files' => true]) ?>
  <div class="form-grid">
    <?= f_select('type', 'Art', $types, $v['type'], ['id' => 'vehicle-type']) ?>
    <?= f_text('name', 'Name', $v['name'], ['required' => true, 'placeholder' => 'z. B. Octavia, Rennrad, MTB']) ?>
    <?= f_text('make', 'Marke', $v['make'], ['placeholder' => 'z. B. Škoda, Canyon']) ?>
    <?= f_text('model', 'Modell', $v['model'], ['placeholder' => 'z. B. Octavia Combi']) ?>
    <?= f_text('variant', 'Ausstattung / Motor', $v['variant'], ['placeholder' => 'z. B. 2.0 TDI, Ultegra Di2']) ?>
    <?= f_num('year', 'Baujahr', $v['year'], ['dec' => 0, 'placeholder' => date('Y')]) ?>

    <div class="form-section" data-motor>Zulassung & Technik</div>
    <span data-motor><?= f_text('plate', 'Kennzeichen', $v['plate'], ['placeholder' => 'E-AB 1234']) ?></span>
    <span data-motor><?= f_date('first_registration', 'Erstzulassung', $v['first_registration']) ?></span>
    <span data-motor><?= f_select('fuel_type', 'Antrieb', FUEL_TYPES, $v['fuel_type']) ?></span>
    <span data-motor><?= f_num('tank_l', 'Tankinhalt', $v['tank_l'], ['dec' => 1, 'suffix' => 'l / kWh']) ?></span>
    <span data-motor class="full"><?= f_text('vin', 'Fahrgestellnummer (FIN)', $v['vin'], ['placeholder' => '17 Zeichen', 'maxlength' => 32, 'hint' => 'Wird auch für den Abgleich mit der MySkoda-App genutzt.']) ?></span>
    <span data-motor><?= f_text('hsn_tsn', 'HSN / TSN', $v['hsn_tsn'], ['placeholder' => '8004 / ABC']) ?></span>
    <span data-motor><?= f_text('oil_spec', 'Motoröl', $v['oil_spec'], ['placeholder' => 'z. B. VW 504 00, 0W-30']) ?></span>
    <span data-bike class="full"><?= f_text('frame_number', 'Rahmennummer', $v['frame_number']) ?></span>
    <?= f_text('tire_size', 'Reifengröße', $v['tire_size'], ['placeholder' => 'z. B. 225/45 R17 oder 28-622']) ?>
    <label class="field"><span class="label">Farbe in der App</span><span class="control"><input type="color" name="color" value="<?= e($v['color'] ?: '#0f766e') ?>"></span></label>

    <div class="form-section">Kauf</div>
    <?= f_date('purchase_date', 'Kaufdatum', $v['purchase_date']) ?>
    <?= f_num('purchase_price', 'Kaufpreis', $v['purchase_price'], ['suffix' => '€']) ?>
    <?= f_num('purchase_km', 'Kilometerstand bei Kauf / Start', $v['purchase_km'], ['dec' => 0, 'suffix' => 'km', 'class' => 'full']) ?>

    <?php if (!$id): ?>
      <div class="form-section">Erste Fristen (optional)</div>
      <span data-motor><?= f_date('next_hu', 'Nächste HU/AU', '') ?></span>
      <?= f_date('next_service', 'Nächster Service / Inspektion', '') ?>
      <span data-motor class="full"><?= f_num('next_service_km', 'Service fällig bei', '', ['dec' => 0, 'suffix' => 'km']) ?></span>
    <?php endif; ?>

    <div class="form-section">Versicherung</div>
    <?= f_text('insurance_company', 'Versicherung', $v['insurance_company']) ?>
    <?= f_text('insurance_number', 'Vertragsnummer', $v['insurance_number']) ?>

    <div class="form-section">Foto</div>
    <div class="full">
      <?php if ($v['photo']): ?><img class="photo-preview" src="<?= e(file_url($v['photo'])) ?>" alt=""><label class="check mb"><input type="checkbox" name="remove_photo" value="1"> Foto entfernen</label><?php endif; ?>
      <label class="field"><span class="control"><input type="file" name="photo" accept="image/*"></span></label>
    </div>

    <div class="full"><?= f_textarea('notes', 'Notizen', $v['notes'], ['rows' => 3]) ?></div>

    <div class="full">
      <details class="more"<?= $v['sm_vehicle_id'] ? ' open' : '' ?>><summary>Spritmonitor-Verknüpfung <?= icon('chevron-down') ?></summary>
        <div class="form-grid">
          <?= f_text('sm_vehicle_id', 'Spritmonitor Fahrzeug-ID', $v['sm_vehicle_id'], ['inputmode' => 'numeric', 'hint' => 'Wird beim API-Abgleich unter „Import“ automatisch gesetzt.']) ?>
          <?= f_text('sm_tank_id', 'Tank-ID', $v['sm_tank_id'], ['inputmode' => 'numeric', 'placeholder' => '1']) ?>
        </div>
      </details>
    </div>
  </div>
  <div class="form-actions">
    <a class="btn btn-ghost" href="<?= e($id ? url('vehicle', ['id' => $id]) : url('vehicles')) ?>">Abbrechen</a>
    <button class="btn btn-primary" type="submit"><?= icon('check') ?> Speichern</button>
  </div>
</form>
</div>

<?php if ($id): ?>
<div class="card form-card mt">
  <h2>Fahrzeug verwalten</h2>
  <p class="muted small mt">Archivierte Fahrzeuge verschwinden aus Übersicht und Schnell-Eintrag, ihre Daten bleiben erhalten. Beim Löschen werden alle Einträge und Dokumente endgültig entfernt.</p>
  <div class="row wrap">
    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="archive"><button class="btn" type="submit"><?= icon('archive') ?> <?= $v['archived'] ? 'Wieder aktivieren' : 'Archivieren' ?></button></form>
    <?= delete_button('', 'Das Fahrzeug mit allen Einträgen') ?>
  </div>
</div>
<?php endif;
page_footer();
