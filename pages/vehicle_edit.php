<?php
declare(strict_types=1);

$id = (int)($_GET['id'] ?? 0);
$newType = array_key_exists($_GET['type'] ?? '', VEHICLE_TYPES) ? $_GET['type'] : 'auto';
$v = $id ? vehicle_or_404($id) : [
    'id' => 0, 'type' => $newType, 'name' => '', 'make' => '', 'model' => '', 'variant' => '', 'year' => null,
    'plate' => '', 'vin' => '', 'hsn_tsn' => '', 'fuel_type' => 'benzin', 'tank_l' => null, 'color' => '#0f766e', 'photo' => null,
    'first_registration' => null, 'purchase_date' => null, 'purchase_price' => null, 'purchase_km' => null, 'tire_size' => '',
    'oil_spec' => '', 'insurance_company' => '', 'insurance_number' => '', 'frame_number' => '', 'frame_size' => '', 'drive_unit' => '',
    'battery_wh' => null, 'notes' => '', 'sm_vehicle_id' => '', 'sm_tank_id' => '', 'archived' => 0,
];
// Der Bereich (Autos / Fahrräder) steht fest – gewählt wird nur der Typ innerhalb des Bereichs
$group = vehicle_group($v);
$bike = $group === 'fahrraeder';
$types = group_types($group);

if (is_post()) {
    csrf_check();
    $do = $_POST['do'] ?? 'save';
    if ($id && $do === 'delete') {
        delete_vehicle_files($id);
        q('DELETE FROM vehicles WHERE id = ? AND user_id = ?', [$id, uid()]);
        flash('Fahrzeug gelöscht.');
        redirect(url($group));
    }
    if ($id && $do === 'archive') {
        update('vehicles', ['archived' => $v['archived'] ? 0 : 1], 'id = ?', [$id]);
        flash($v['archived'] ? 'Fahrzeug wieder aktiv.' : 'Fahrzeug archiviert.');
        redirect(url('vehicle', ['id' => $id]));
    }
    $type = isset($types[$_POST['type'] ?? '']) ? $_POST['type'] : array_key_first($types);
    $has = fn(string $f) => vfield($type, $f);
    $data = [
        'type' => $type,
        'name' => str_or_null($_POST['name'] ?? '', 120) ?? 'Mein ' . VEHICLE_TYPES[$type],
        'make' => str_or_null($_POST['make'] ?? '', 80),
        'model' => str_or_null($_POST['model'] ?? '', 80),
        'variant' => str_or_null($_POST['variant'] ?? '', 80),
        'year' => int_or_null($_POST['year'] ?? ''),
        'plate' => $has('plate') ? str_or_null(mb_strtoupper((string)($_POST['plate'] ?? '')), 20) : null,
        'vin' => $has('vin') ? str_or_null(mb_strtoupper(str_replace(' ', '', (string)($_POST['vin'] ?? ''))), 32) : null,
        'hsn_tsn' => $has('hsn_tsn') ? str_or_null($_POST['hsn_tsn'] ?? '', 20) : null,
        'fuel_type' => $has('fuel_type') && array_key_exists($_POST['fuel_type'] ?? '', FUEL_TYPES) ? $_POST['fuel_type'] : null,
        'tank_l' => $has('tank_l') ? num($_POST['tank_l'] ?? '') : null,
        'color' => preg_match('/^#[0-9a-f]{6}$/i', (string)($_POST['color'] ?? '')) ? $_POST['color'] : '#0f766e',
        'first_registration' => $has('first_registration') ? date_or_null($_POST['first_registration'] ?? '') : null,
        'purchase_date' => date_or_null($_POST['purchase_date'] ?? ''),
        'purchase_price' => num($_POST['purchase_price'] ?? '', true),
        'purchase_km' => int_or_null($_POST['purchase_km'] ?? ''),
        'tire_size' => $has('tire_size') ? str_or_null($_POST['tire_size'] ?? '', 60) : null,
        'oil_spec' => $has('oil_spec') ? str_or_null($_POST['oil_spec'] ?? '', 60) : null,
        'insurance_company' => str_or_null($_POST['insurance_company'] ?? '', 80),
        'insurance_number' => str_or_null($_POST['insurance_number'] ?? '', 60),
        'frame_number' => $has('frame_number') ? str_or_null($_POST['frame_number'] ?? '', 60) : null,
        'frame_size' => $has('frame_size') ? str_or_null($_POST['frame_size'] ?? '', 20) : null,
        'drive_unit' => $has('drive_unit') ? str_or_null($_POST['drive_unit'] ?? '', 80) : null,
        'battery_wh' => $has('battery_wh') ? int_or_null($_POST['battery_wh'] ?? '') : null,
        'notes' => str_or_null($_POST['notes'] ?? '', 5000),
        'sm_vehicle_id' => $has('spritmonitor') ? str_or_null($_POST['sm_vehicle_id'] ?? '', 20) : null,
        'sm_tank_id' => $has('spritmonitor') ? str_or_null($_POST['sm_tank_id'] ?? '', 10) : null,
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
        // Startfristen (Kfz) bzw. erster geplanter Service (Fahrrad)
        if ($has('hu') && ($hu = date_or_null($_POST['next_hu'] ?? ''))) {
            insert('reminders', ['vehicle_id' => $id, 'kind' => 'hu', 'title' => 'HU/AU (TÜV)', 'due_date' => $hu, 'interval_months' => 24, 'warn_days' => 45, 'warn_km' => 0, 'created_at' => now()]);
        }
        $sd = $has('service') ? date_or_null($_POST['next_service'] ?? '') : null;
        $sk = $has('service_km') ? int_or_null($_POST['next_service_km'] ?? '') : null;
        if ($sd || $sk) {
            insert('reminders', ['vehicle_id' => $id, 'kind' => 'inspektion', 'title' => 'Inspektion', 'due_date' => $sd, 'due_km' => $sk, 'interval_months' => 24, 'interval_km' => 30000, 'warn_days' => 30, 'warn_km' => 1500, 'created_at' => now()]);
        }
        if ($has('bike_service') && ($bd = date_or_null($_POST['next_bike_service'] ?? ''))) {
            insert('services', ['vehicle_id' => $id, 'kind' => 'inspektion', 'title' => 'Inspektion', 'status' => 'geplant', 'date' => $bd, 'done_by' => 'werkstatt', 'created_at' => now()]);
        }
        if ($data['purchase_km'] !== null) {
            insert('odometer_log', ['vehicle_id' => $id, 'date' => $data['purchase_date'] ?? today(), 'km' => $data['purchase_km'], 'source' => 'manual', 'note' => 'Startwert', 'created_at' => now()]);
        }
        flash($data['name'] . ' ist jetzt in deiner Garage.');
    }
    redirect(url('vehicle', ['id' => $id]));
}

/** Formularzeile nur für bestimmte Fahrzeugtypen (Umschalten per JS, serverseitig passend vorbelegt) */
$only = function (string $field, string $html, string $cls = '') use ($v): string {
    $ts = array_keys(array_filter(VEHICLE_FIELDS, fn($fs) => in_array($field, $fs, true)));
    return '<div class="' . $cls . '" data-types="' . e(implode(' ', $ts)) . '"' . (vfield($v['type'], $field) ? '' : ' hidden') . '>' . $html . '</div>';
};
$section = function (string $label, array $fields, array $labels = []) use ($v): string {
    $ts = array_keys(array_filter(VEHICLE_FIELDS, fn($fs) => (bool)array_intersect($fields, $fs)));
    $lbl = $labels ? ' data-lbl="' . e(json_encode($labels + array_fill_keys($ts, $label), JSON_UNESCAPED_UNICODE)) . '"' : '';
    return '<div class="form-section" data-types="' . e(implode(' ', $ts)) . '"' . $lbl . (array_intersect($fields, VEHICLE_FIELDS[$v['type']] ?? []) ? '' : ' hidden') . '>' . e($labels[$v['type']] ?? $label) . '</div>';
};
// Platzhalter je Typ: Startwert passend zum Typ, beim Umschalten per JS (data-ph)
$ph = fn(array $m) => ['placeholder' => $m[$v['type']] ?? reset($m), 'data' => ['ph' => json_encode($m, JSON_UNESCAPED_UNICODE)]];
$title = $id ? $v['name'] . ' bearbeiten' : ($bike ? 'Neues Fahrrad' : 'Neues Fahrzeug');
page_header($title, ['nav' => $group, 'back' => $id ? url('vehicle', ['id' => $id]) : url($group)]);
?>
<div class="card form-card">
<?= form_open('', ['files' => true]) ?>
  <div class="form-grid" data-vehicle-form>
<?php if ($bike): ?>
    <div class="form-section">Rad</div>
    <?= f_select('type', 'Art', $types, $v['type'], ['id' => 'vehicle-type']) ?>
    <?= f_text('name', 'Name', $v['name'], ['required' => true] + $ph(['fahrrad' => 'z. B. Rennrad, Gravelbike', 'ebike' => 'z. B. E-MTB, Pendlerrad'])) ?>
    <?= f_text('make', 'Marke', $v['make'], $ph(['fahrrad' => 'z. B. Canyon, Rose, Specialized', 'ebike' => 'z. B. Cube, Riese & Müller'])) ?>
    <?= f_text('model', 'Modell', $v['model'], $ph(['fahrrad' => 'z. B. Grizl CF SL 8', 'ebike' => 'z. B. Stereo Hybrid 140'])) ?>
    <?= f_text('variant', 'Ausstattung / Schaltgruppe', $v['variant'], $ph(['fahrrad' => 'z. B. Shimano GRX 820, 1×12', 'ebike' => 'z. B. Shimano Deore XT, 1×12'])) ?>
    <?= f_num('year', 'Modelljahr', $v['year'], ['dec' => 0, 'placeholder' => date('Y')]) ?>

    <?= $section('Rahmen & Laufräder', ['frame_size', 'frame_number', 'tire_size']) ?>
    <?= $only('frame_size', f_text('frame_size', 'Rahmengröße', $v['frame_size'], ['placeholder' => 'z. B. M oder 56 cm', 'maxlength' => 20])) ?>
    <?= $only('tire_size', f_text('tire_size', 'Reifengröße', $v['tire_size'], $ph(['fahrrad' => 'z. B. 40-622 (700×40C)', 'ebike' => 'z. B. 29×2,4" (60-622)']))) ?>
    <?= $only('frame_number', f_text('frame_number', 'Rahmennummer', $v['frame_number'], ['placeholder' => 'meist unter dem Tretlager eingraviert', 'maxlength' => 60, 'hint' => 'Wichtig für Versicherung und Diebstahlanzeige.']), 'full') ?>

    <?= $section('E-Antrieb', ['drive_unit', 'battery_wh']) ?>
    <?= $only('drive_unit', f_text('drive_unit', 'Motor / System', $v['drive_unit'], ['placeholder' => 'z. B. Bosch Performance Line CX', 'maxlength' => 80])) ?>
    <?= $only('battery_wh', f_num('battery_wh', 'Akku', $v['battery_wh'], ['dec' => 0, 'suffix' => 'Wh', 'placeholder' => 'z. B. 625'])) ?>
<?php else: ?>
    <div class="form-section">Fahrzeug</div>
    <?= f_select('type', 'Art', $types, $v['type'], ['id' => 'vehicle-type']) ?>
    <?= f_text('name', 'Name', $v['name'], ['required' => true] + $ph(['auto' => 'z. B. Octavia, Familienauto', 'motorrad' => 'z. B. MT-07', 'anhaenger' => 'z. B. Pkw-Anhänger', 'sonstiges' => 'z. B. Wohnmobil, Roller'])) ?>
    <?= f_text('make', 'Marke', $v['make'], $ph(['auto' => 'z. B. Škoda, VW', 'motorrad' => 'z. B. Yamaha, BMW', 'anhaenger' => 'z. B. Humbaur, Böckmann', 'sonstiges' => 'z. B. Hymer'])) ?>
    <?= f_text('model', 'Modell', $v['model'], $ph(['auto' => 'z. B. Octavia Combi', 'motorrad' => 'z. B. MT-07', 'anhaenger' => 'z. B. HA 132513', 'sonstiges' => 'z. B. B-Klasse 580'])) ?>
    <?= f_text('variant', 'Motor / Ausstattung', $v['variant'], $ph(['auto' => 'z. B. 2.0 TDI, Style', 'motorrad' => 'z. B. 689 cm³, 73 PS', 'anhaenger' => 'z. B. Kofferaufbau, 1.300 kg', 'sonstiges' => 'z. B. 2.3 Diesel'])
        + ['label_data' => json_encode(['auto' => 'Motor / Ausstattung', 'motorrad' => 'Motor / Ausstattung', 'anhaenger' => 'Ausführung', 'sonstiges' => 'Motor / Ausstattung'], JSON_UNESCAPED_UNICODE)]) ?>
    <?= f_num('year', 'Baujahr', $v['year'], ['dec' => 0, 'placeholder' => date('Y')]) ?>

    <?= $section('Zulassung', ['plate', 'first_registration', 'vin', 'hsn_tsn']) ?>
    <?= $only('plate', f_text('plate', 'Kennzeichen', $v['plate'], ['placeholder' => 'z. B. E-AB 1234', 'maxlength' => 20])) ?>
    <?= $only('first_registration', f_date('first_registration', 'Erstzulassung', $v['first_registration'])) ?>
    <?= $only('vin', f_text('vin', 'Fahrgestellnummer (FIN)', $v['vin'], ['placeholder' => '17 Zeichen, Feld E im Fahrzeugschein', 'maxlength' => 32, 'hint' => 'Wird auch für den Abgleich mit der MySkoda-App genutzt.']), 'full') ?>
    <?= $only('hsn_tsn', f_text('hsn_tsn', 'HSN / TSN', $v['hsn_tsn'], ['placeholder' => 'z. B. 0603 / BTJ – Felder 2.1 und 2.2', 'maxlength' => 20]), 'full') ?>

    <?= $section('Antrieb & Technik', ['fuel_type', 'tank_l', 'oil_spec', 'tire_size'], ['anhaenger' => 'Technik']) ?>
    <?= $only('fuel_type', f_select('fuel_type', 'Antrieb', FUEL_TYPES, $v['fuel_type'] ?: 'benzin', ['id' => 'fuel-type'])) ?>
    <?= $only('tank_l', f_num('tank_l', 'Tankinhalt / Akku', $v['tank_l'], ['dec' => 1, 'suffix' => ($v['fuel_type'] ?? '') === 'elektro' ? 'kWh' : 'l', 'placeholder' => 'z. B. 45'])) ?>
    <?= $only('oil_spec', f_text('oil_spec', 'Motoröl', $v['oil_spec'], $ph(['auto' => 'z. B. VW 504 00, 0W-30', 'motorrad' => 'z. B. 10W-40, JASO MA2', 'sonstiges' => 'laut Handbuch']))) ?>
    <?= $only('tire_size', f_text('tire_size', 'Reifengröße', $v['tire_size'], $ph(['auto' => 'z. B. 225/45 R17 91W', 'motorrad' => 'z. B. 120/70 ZR17 · 180/55 ZR17', 'anhaenger' => 'z. B. 185/70 R13 C', 'sonstiges' => 'z. B. 225/75 R16 C']))) ?>
<?php endif; ?>

    <div class="form-section">Kauf</div>
    <?= f_date('purchase_date', 'Kaufdatum', $v['purchase_date']) ?>
    <?= f_num('purchase_price', 'Kaufpreis', $v['purchase_price'], ['suffix' => '€', 'placeholder' => $bike ? 'z. B. 2.499' : 'z. B. 18.900']) ?>
    <?= f_num('purchase_km', 'Kilometerstand bei Kauf', $v['purchase_km'], ['dec' => 0, 'suffix' => 'km', 'class' => 'full', 'placeholder' => $bike ? '0 bei einem neuen Rad' : 'z. B. 42.000',
        'hint' => 'Startwert für Kilometer und Verschleiß.']) ?>

    <?php if (!$id): ?>
      <?= $section($bike ? 'Erster Service (optional)' : 'Erste Fristen (optional)', ['hu', 'service', 'service_km', 'bike_service']) ?>
      <?= $only('hu', f_date('next_hu', 'Nächste HU/AU', '', ['hint' => 'Steht auf der Plakette am Kennzeichen.'])) ?>
      <?= $only('service', f_date('next_service', 'Nächste Inspektion', '')) ?>
      <?= $only('service_km', f_num('next_service_km', 'Inspektion fällig bei', '', ['dec' => 0, 'suffix' => 'km', 'placeholder' => 'laut Serviceheft']), 'full') ?>
      <?= $only('bike_service', f_date('next_bike_service', 'Nächste Inspektion geplant', '', ['hint' => 'Erscheint im Serviceheft unter „Geplant“.']), 'full') ?>
    <?php endif; ?>

    <div class="form-section">Versicherung</div>
    <?= f_text('insurance_company', 'Versicherung', $v['insurance_company'], ['placeholder' => $bike ? 'z. B. Wertgarantie, Hausrat' : 'z. B. HUK-Coburg']) ?>
    <?= f_text('insurance_number', 'Vertragsnummer', $v['insurance_number'], ['placeholder' => 'optional']) ?>

    <div class="form-section">Darstellung</div>
    <div class="full">
      <?php if ($v['photo']): ?><img class="photo-preview" src="<?= e(file_url($v['photo'])) ?>" alt=""><label class="check mb"><input type="checkbox" name="remove_photo" value="1"> Foto entfernen</label><?php endif; ?>
      <label class="field"><span class="label">Foto</span><span class="control"><input type="file" name="photo" accept="image/*"></span><small class="hint">Wird automatisch auf 1200 px verkleinert.</small></label>
    </div>
    <label class="field"><span class="label">Farbe in der App</span><span class="control"><input type="color" name="color" value="<?= e($v['color'] ?: '#0f766e') ?>"></span></label>

    <div class="full"><?= f_textarea('notes', 'Notizen', $v['notes'], ['rows' => 3, 'placeholder' => $bike ? 'z. B. Rahmencodierung, Schlüsselnummer für Schloss und Akku' : 'z. B. Radio-Code, Reifendruck, Stammwerkstatt']) ?></div>

    <?php if (!$bike): ?>
    <?= $only('spritmonitor', '<details class="more"' . ($v['sm_vehicle_id'] ? ' open' : '') . '><summary>Spritmonitor-Verknüpfung ' . icon('chevron-down') . '</summary><div class="form-grid">'
        . f_text('sm_vehicle_id', 'Spritmonitor Fahrzeug-ID', $v['sm_vehicle_id'], ['inputmode' => 'numeric', 'hint' => 'Wird beim API-Abgleich unter „Import“ automatisch gesetzt.'])
        . f_text('sm_tank_id', 'Tank-ID', $v['sm_tank_id'], ['inputmode' => 'numeric', 'placeholder' => '1']) . '</div></details>', 'full') ?>
    <?php endif; ?>
  </div>
  <div class="form-actions">
    <a class="btn btn-ghost" href="<?= e($id ? url('vehicle', ['id' => $id]) : url($group)) ?>">Abbrechen</a>
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
