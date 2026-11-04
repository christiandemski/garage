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
        'odometer' => null, 'vendor' => null, 'repeat_months' => 0, 'repeat_until' => null, 'note' => null, 'source' => 'manual'];
}
$vid = (int)$v['id'];
$items = $id ? cost_items($id) : [];
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
    $title = str_or_null($_POST['title'], 160);
    // Positionen (Kombitermin): Kategorie je Position, Gesamtbetrag = Summe
    $items = [];
    $itemErr = false;
    foreach ((array)($_POST['items']['amount'] ?? []) as $i => $a) {
        $it = ['category' => array_key_exists($_POST['items']['category'][$i] ?? '', COST_CATEGORIES) ? $_POST['items']['category'][$i] : 'sonstiges',
            'title' => str_or_null($_POST['items']['title'][$i] ?? '', 160), 'amount' => num($a, true)];
        if ($it['amount'] === null && $it['title'] === null) continue;
        if ($it['amount'] === null) $itemErr = true;
        $items[] = $it;
    }
    if (count($items) === 1 && !$itemErr) {
        // Eine einzelne Position ist ein normaler Eintrag
        [$category, $amount, $title] = [$items[0]['category'], $items[0]['amount'], $title ?? $items[0]['title']];
        $items = [];
    } elseif ($items && !$itemErr) {
        $amount = array_sum(array_column($items, 'amount'));
        $top = $items[0];
        foreach ($items as $it) if ($it['amount'] > $top['amount']) $top = $it;
        $category = $top['category'];
        $title ??= mb_substr(implode(', ', array_map(fn($it) => $it['title'] ?? cat_label($it['category']), $items)), 0, 160);
    }
    if ($itemErr || $amount === null) {
        flash($itemErr ? 'Bitte bei jeder Position einen Betrag angeben.' : 'Bitte einen Betrag angeben.', 'err');
        $c = array_merge($c, $_POST);
    } else {
        $data = [
            'vehicle_id' => $vid, 'date' => date_or_null($_POST['date']) ?? today(), 'category' => $category,
            'title' => $title ?? cat_label($category), 'amount' => $amount,
            'odometer' => int_or_null($_POST['odometer']), 'vendor' => str_or_null($_POST['vendor'] ?? '', 120),
            'repeat_months' => max(0, (int)($_POST['repeat_months'] ?? 0)), 'note' => str_or_null($_POST['note'] ?? '', 4000),
        ];
        $data['repeat_until'] = $data['repeat_months'] ? date_or_null($_POST['repeat_until'] ?? '') : null;
        if ($data['repeat_until'] && $data['repeat_until'] < $data['date']) $data['repeat_until'] = $data['date'];
        if ($id) {
            if ($data['repeat_months'] != $c['repeat_months']) $data['repeated'] = 0;
            update('costs', $data, 'id = ?', [$id]);
            // Enddatum geändert: auf die bereits fortgeschriebenen Folgeeinträge übertragen
            if ($data['repeat_months'] && $data['repeat_months'] == $c['repeat_months'] && $data['repeat_until'] != $c['repeat_until']) {
                $removed = end_recurring($id, $data);
                if ($removed) flash($removed . ' automatisch erzeugte ' . ($removed === 1 ? 'Buchung' : 'Buchungen') . ' nach dem Enddatum entfernt.');
            }
        } else {
            $data['created_at'] = now();
            $id = insert('costs', $data);
        }
        save_cost_items($id, $items);
        if (!empty($_FILES['receipt']['name'])) {
            if ($up = store_upload($_FILES['receipt'])) {
                insert('documents', ['user_id' => uid(), 'vehicle_id' => $vid, 'cost_id' => $id, 'title' => $data['title'] . ' (' . fmt_date($data['date']) . ')', 'kind' => 'rechnung',
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
$suggest = in_array($v['type'], T_BIKE, true) ? [
    'wartung' => ['Inspektion', 'Service', 'Kette ölen', 'Schaltung einstellen', 'Bremsen entlüften'],
    'reparatur' => ['Werkstatt', 'Bremsen', 'Platten', 'Speichen'],
    'reifen' => ['Neue Reifen', 'Mantel', 'Schlauch', 'Dichtmilch'],
    'versicherung' => ['Fahrradversicherung'],
    'pflege' => ['Reinigung', 'Pflegemittel', 'Kettenöl'],
    'teile' => ['Kette', 'Kassette', 'Bremsbeläge', 'Schlauch', 'Griffe'],
    'zubehoer' => ['Zubehör', 'Werkzeug', 'Schloss', 'Licht', 'Helm'],
    'finanzierung' => ['Leasingrate', 'Kreditrate'],
    'garage' => ['Fahrradbox', 'Stellplatzmiete', 'Fahrradgarage'],
] : [
    'wartung' => ['Inspektion', 'Ölwechsel', 'Service', 'Klimaservice', 'Bremsflüssigkeit'],
    'reparatur' => ['Werkstatt', 'Bremsen', 'Batterie', 'Steinschlag'],
    'reifen' => ['Reifenwechsel', 'Neue Reifen', 'Einlagerung', 'Wuchten'],
    'pruefung' => ['HU/AU'],
    'versicherung' => ['Kfz-Versicherung'],
    'steuer' => ['Kfz-Steuer'],
    'pflege' => ['Autowäsche', 'Innenreinigung', 'Pflegemittel'],
    'parken' => ['Parken', 'Maut', 'Vignette'],
    'garage' => ['Garagenmiete', 'Stellplatzmiete', 'Tiefgaragenplatz', 'Carport'],
    'teile' => $v['type'] === 'motorrad' ? ['Kette', 'Kettensatz', 'Bremsbeläge', 'Batterie'] : ['Bremsbeläge', 'Batterie', 'Wischerblätter', 'Filter'],
    'zubehoer' => ['Zubehör', 'Werkzeug'],
    'finanzierung' => ['Leasingrate', 'Kreditrate'],
];
$openReminders = !$c['id'] ? rows('SELECT * FROM reminders WHERE vehicle_id = ? AND done_at IS NULL ORDER BY due_date', [$vid]) : [];

$cats = array_filter(for_vehicle(COST_CATEGORIES, $v, 2, $c['category']), fn($k) => !($k === 'kraftstoff' && is_motor($v) && $c['category'] !== 'kraftstoff'), ARRAY_FILTER_USE_KEY);
$itemRow = function (array $it) use ($cats): string {
    $opts = '';
    foreach ($cats as $k => [$label]) $opts .= '<option value="' . e($k) . '"' . ($it['category'] === $k ? ' selected' : '') . '>' . e($label) . '</option>';
    $amount = $it['amount'] === null || $it['amount'] === '' ? '' : (is_numeric($it['amount']) ? number_format((float)$it['amount'], 2, ',', '') : $it['amount']);
    return '<div class="item-row">'
        . '<span class="control select"><select name="items[category][]" aria-label="Kategorie">' . $opts . '</select>' . icon('chevron-down', 'select-ic') . '</span>'
        . '<span class="control"><input name="items[title][]" value="' . e($it['title'] ?? '') . '" list="title-suggest" placeholder="Bezeichnung" aria-label="Bezeichnung"></span>'
        . '<span class="control has-suffix"><input name="items[amount][]" value="' . e($amount) . '" inputmode="decimal" placeholder="0,00" aria-label="Betrag"><span class="suffix">€</span></span>'
        . '<button type="button" class="icon-btn js-del-item" aria-label="Position entfernen" title="Position entfernen">' . icon('x') . '</button></div>';
};

page_header('Kosten', ['nav' => vehicle_group($v), 'back' => $back, 'heading' => ($id ? 'Kosten bearbeiten' : 'Neue Kosten') . ' · ' . $v['name']]);
?>
<div class="card form-card">
<?= form_open('', ['files' => true]) ?>
  <div class="field js-cat"<?= $items ? ' hidden' : '' ?>><span class="label">Kategorie</span>
    <div class="chips radio-chips">
      <?php foreach ($cats as $k => [$label, $color]): ?>
        <label><input type="radio" name="category" value="<?= e($k) ?>"<?= $c['category'] === $k ? ' checked' : '' ?>><span class="chip"><i class="dot-c" style="background:<?= e($color) ?>"></i><?= e($label) ?></span></label>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="form-grid">
    <div class="full"><?= f_text('title', 'Bezeichnung', $c['title'], ['list' => 'title-suggest', 'placeholder' => 'z. B. Inspektion, Versicherung 2026']) ?></div>
    <datalist id="title-suggest"><?php foreach (array_unique(array_merge(...array_values($suggest))) as $s) echo '<option value="' . e($s) . '">'; ?></datalist>
    <div class="full js-items"<?= $items ? '' : ' hidden' ?>>
      <span class="label">Positionen</span>
      <div class="items mt"><?php foreach ($items as $it) echo $itemRow($it); ?></div>
      <button type="button" class="btn btn-sm btn-ghost mt js-add-item"><?= icon('plus') ?> Position</button>
    </div>
    <div class="full js-split split-cta"<?= $items ? ' hidden' : '' ?>>
      <button type="button" class="btn btn-sm btn-ghost"><?= icon('layers') ?> In Positionen aufteilen</button>
      <small class="hint">z. B. Kombitermin in der Werkstatt: Inspektion, Reifenwechsel und HU auf einer Rechnung</small>
    </div>
    <template id="item-tpl"><?= $itemRow(['category' => $c['category'], 'title' => null, 'amount' => null]) ?></template>
    <?= f_num('amount', 'Betrag', $c['amount'], ['suffix' => '€', 'required' => true, 'autofocus' => !$id]) ?>
    <?= f_date('date', 'Datum', $c['date'], ['required' => true]) ?>
    <?= f_num('odometer', 'Kilometerstand', $c['odometer'], ['dec' => 0, 'suffix' => 'km', 'placeholder' => 'optional']) ?>
    <?= f_text('vendor', 'Werkstatt / Händler', $c['vendor'], ['placeholder' => 'optional']) ?>
    <?= f_select('repeat_months', 'Wiederholen', [0 => 'Einmalig', 1 => 'Monatlich', 3 => 'Vierteljährlich', 6 => 'Halbjährlich', 12 => 'Jährlich'], (int)$c['repeat_months'], ['hint' => 'Wiederkehrende Kosten (z. B. Versicherung) werden automatisch fortgeschrieben.']) ?>
    <?= f_date('repeat_until', 'Wiederholen bis', $c['repeat_until'], ['class' => 'js-repeat-until', 'hint' => 'Optional – z. B. bei Kündigung. Bei Preisänderung hier beenden und eine neue Buchung mit neuem Betrag anlegen.']) ?>
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
<script>(()=>{const s=document.querySelector('select[name=repeat_months]'),u=document.querySelector('.js-repeat-until');const f=()=>{u.hidden=s.value==='0'};s.addEventListener('change',f);f();})();
(()=>{
  const $ = q => document.querySelector(q), list = $('.items'), box = $('.js-items'), split = $('.js-split'), cat = $('.js-cat'),
    amount = $('input[name=amount]'), amountLabel = amount.closest('.field').querySelector('.label'), title = $('input[name=title]');
  const parse = v => { v = v.replace(/[\s€]/g, ''); if (v.includes(',')) v = v.replace(/\./g, '').replace(',', '.'); return parseFloat(v) || 0; };
  const add = (it = {}) => {
    list.append($('#item-tpl').content.cloneNode(true));
    const row = list.lastElementChild;
    if (it.category) row.querySelector('select').value = it.category;
    if (it.title) row.querySelector('input[name="items[title][]"]').value = it.title;
    if (it.amount) row.querySelector('input[name="items[amount][]"]').value = it.amount;
    return row;
  };
  const sync = () => {
    const on = list.children.length > 0;
    box.hidden = !on; split.hidden = on; cat.hidden = on;
    amount.readOnly = on; amountLabel.textContent = on ? 'Gesamtbetrag' : 'Betrag';
    if (on) amount.value = [...list.querySelectorAll('input[name="items[amount][]"]')].reduce((s, i) => s + parse(i.value), 0).toFixed(2).replace('.', ',');
  };
  split.querySelector('button').addEventListener('click', () => {
    const c = document.querySelector('input[name=category]:checked');
    add({category: c && c.value, title: title.value, amount: amount.value});
    add().querySelector('input[name="items[title][]"]').focus();
    title.value = '';
    sync();
  });
  $('.js-add-item').addEventListener('click', () => { add().querySelector('select').focus(); sync(); });
  list.addEventListener('input', sync);
  list.addEventListener('click', e => { const b = e.target.closest('.js-del-item'); if (b) { b.closest('.item-row').remove(); sync(); } });
  sync();
})();</script>
<?php if ($id): ?><div class="mt"><?= delete_button('', 'Den Eintrag') ?></div><?php endif;
page_footer();
