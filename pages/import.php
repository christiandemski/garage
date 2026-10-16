<?php
declare(strict_types=1);

$u = current_user();
$vs = vehicles();
$cars = array_values(array_filter($vs, 'is_motor'));
$smVehicles = $_SESSION['sm_vehicles'] ?? null;

if (is_post()) {
    csrf_check();
    $do = $_POST['do'] ?? '';
    try {
        switch ($do) {
            case 'csv':
                $v = vehicle_or_404($_POST['vehicle'] ?? 0);
                if (($_FILES['csv']['error'] ?? 1) !== UPLOAD_ERR_OK) throw new RuntimeException('Bitte eine CSV-Datei auswählen.');
                [$nf, $nc, $sk, $mode] = import_csv($v, $_FILES['csv']['tmp_name'], $_POST['mode'] ?? 'auto');
                flash("Import fertig: $nf Tankungen, $nc Kosten" . ($sk ? ", $sk übersprungen (Duplikate/leer)" : '') . '.');
                log_sync('csv', "CSV-Import {$v['name']}: $nf Tankungen, $nc Kosten");
                redirect(url('vehicle', ['id' => $v['id'], 'tab' => $nf ? 'fuel' : 'costs']));
            case 'sm_token':
                if (str_starts_with((string)($_POST['sm_token'] ?? ''), '••')) { flash('Token unverändert.'); break; }
                update('users', ['sm_token' => str_or_null($_POST['sm_token'] ?? '', 500)], 'id = ?', [$u['id']]);
                unset($_SESSION['sm_vehicles']);
                flash($_POST['sm_token'] ? 'Spritmonitor-Token gespeichert.' : 'Spritmonitor-Token entfernt.');
                break;
            case 'sm_load':
                if (!$u['sm_token']) throw new RuntimeException('Bitte zuerst den Spritmonitor-Token hinterlegen.');
                $_SESSION['sm_vehicles'] = sm_vehicles($u['sm_token']);
                flash(count($_SESSION['sm_vehicles']) . ' Fahrzeug(e) bei Spritmonitor gefunden.');
                break;
            case 'sm_link':
                $v = vehicle_or_404($_POST['vehicle'] ?? 0);
                update('vehicles', ['sm_vehicle_id' => str_or_null($_POST['sm_id'] ?? '', 20)], 'id = ?', [$v['id']]);
                flash('Verknüpft: ' . $v['name']);
                break;
            case 'sm_sync':
                if (!$u['sm_token']) throw new RuntimeException('Bitte zuerst den Spritmonitor-Token hinterlegen.');
                $tf = $tc = 0;
                foreach ($vs as $v) {
                    if (!$v['sm_vehicle_id']) continue;
                    [$f, $c] = sm_sync_vehicle($v, $u['sm_token']);
                    $tf += $f; $tc += $c;
                }
                log_sync('spritmonitor', "Abgleich: $tf Tankungen, $tc Kosten");
                flash("Spritmonitor-Abgleich: $tf neue Tankungen, $tc neue Kosten.");
                break;
        }
    } catch (Throwable $e) {
        flash($e->getMessage(), 'err');
    }
    redirect(url('import'));
}

$log = rows('SELECT * FROM sync_log WHERE user_id = ? ORDER BY at DESC LIMIT 8', [$u['id']]);
$skodaLast = setting_get('skoda_last_' . $u['id']);
$apiBase = rtrim(absolute_url(rtrim((string)cfg('base_url'), '/') . '/'), '/') . '/api.php';
$smReady = (string)cfg('spritmonitor_app_id', '') !== '';

page_header('Import & Abgleich', ['nav' => 'import', 'back' => url('more')]);
if (!$vs) { echo '<div class="card">' . empty_state('car', 'Noch keine Fahrzeuge', 'Lege zuerst ein Fahrzeug an, in das importiert werden soll.', 'Fahrzeug anlegen', url('vehicle_edit')) . '</div>'; page_footer(); return; }
?>
<div class="grid grid-2">
<div>
  <div class="card">
    <div class="row mb"><span class="li-ic c-teal"><?= icon('upload') ?></span><div><h2>CSV-Import</h2><div class="muted small">Spritmonitor-Export, Garage-Export oder eigene Tabellen</div></div></div>
    <?= form_open(url('import'), ['files' => true]) ?>
      <input type="hidden" name="do" value="csv">
      <?= f_select('vehicle', 'In Fahrzeug', array_column($vs, 'name', 'id'), $cars[0]['id'] ?? $vs[0]['id']) ?>
      <?= f_select('mode', 'Inhalt', ['auto' => 'Automatisch erkennen', 'fuel' => 'Tankungen', 'costs' => 'Kosten'], 'auto') ?>
      <label class="field"><span class="label">CSV-Datei</span><span class="control"><input type="file" name="csv" accept=".csv,text/csv,text/plain" required></span>
        <small class="hint">Bei Spritmonitor unter <em>Fahrzeug → Export</em> getrennt für Tankungen und Kosten herunterladen. Doppelte Einträge werden übersprungen.</small></label>
      <div class="form-actions"><button class="btn btn-primary" type="submit"><?= icon('upload') ?> Importieren</button></div>
    </form>
  </div>

  <div class="card">
    <div class="row mb"><span class="li-ic c-sky"><?= icon('sync') ?></span><div><h2>Spritmonitor-Abgleich</h2><div class="muted small">Holt neue Tankungen und Kosten direkt per API</div></div></div>
    <?php if (!$smReady): ?>
      <div class="banner warn mb"><?= icon('info') ?><div><p>Für den API-Abgleich braucht die App eine <strong>Application-ID</strong> von Spritmonitor. Die gibt es kostenlos beim Spritmonitor-Support (Kontaktformular). Danach in der <code>config.php</code> unter <code>spritmonitor_app_id</code> eintragen.</p><p>Bis dahin funktioniert der CSV-Import.</p></div></div>
    <?php endif; ?>
    <form method="post" action="<?= e(url('import')) ?>" class="form"><?= csrf_field() ?><input type="hidden" name="do" value="sm_token">
      <?= f_text('sm_token', 'Spritmonitor-Token', $u['sm_token'] ? '••••••••' . substr($u['sm_token'], -4) : '', ['type' => $u['sm_token'] ? 'text' : 'password', 'hint' => 'Erzeugen bei Spritmonitor unter <em>Mein Konto → Passwort ändern → Token für Apps</em>.', 'autocomplete' => 'off']) ?>
      <div class="form-actions"><button class="btn" type="submit">Token speichern</button></div>
    </form>
    <?php if ($u['sm_token'] && $smReady): ?>
      <div class="row wrap mt">
        <form method="post" action="<?= e(url('import')) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="sm_load"><button class="btn" type="submit"><?= icon('search') ?> Fahrzeuge laden</button></form>
        <form method="post" action="<?= e(url('import')) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="sm_sync"><button class="btn btn-primary" type="submit"><?= icon('refresh') ?> Jetzt abgleichen</button></form>
      </div>
      <?php if ($smVehicles): ?>
        <div class="mt">
          <div class="label mb">Verknüpfen</div>
          <?php foreach ($cars as $v): ?>
            <form method="post" action="<?= e(url('import')) ?>" class="row mb"><?= csrf_field() ?><input type="hidden" name="do" value="sm_link"><input type="hidden" name="vehicle" value="<?= (int)$v['id'] ?>">
              <span class="grow ellipsis"><?= e($v['name']) ?></span>
              <span class="control select" style="flex:1.4"><select name="sm_id"><option value="">– nicht verknüpft –</option>
                <?php foreach ($smVehicles as $sv): $sid = (string)($sv['id'] ?? ''); ?><option value="<?= e($sid) ?>"<?= $v['sm_vehicle_id'] === $sid ? ' selected' : '' ?>><?= e(trim(($sv['make'] ?? '') . ' ' . ($sv['model'] ?? '')) ?: 'Fahrzeug ' . $sid) ?></option><?php endforeach; ?>
              </select><?= icon('chevron-down', 'select-ic') ?></span>
              <button class="btn btn-sm" type="submit">OK</button>
            </form>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <p class="muted small mt">Verknüpft: <?= e(implode(', ', array_column(array_filter($vs, fn($v) => $v['sm_vehicle_id']), 'name')) ?: 'noch keins') ?>. Mit Cronjob läuft der Abgleich täglich automatisch.</p>
    <?php endif; ?>
  </div>
</div>

<div>
  <div class="card">
    <div class="row mb"><span class="li-ic c-green"><?= icon('car') ?></span><div><h2>MySkoda</h2><div class="muted small">Kilometerstand & Service-Intervalle aus deinem Škoda</div></div></div>
    <p class="small">Škoda bietet keine offizielle Schnittstelle. Der Abgleich nutzt deshalb die inoffizielle Open-Source-Bibliothek <em>myskoda</em> und läuft als kleines Skript – kostenlos über <strong>GitHub Actions</strong> (alle paar Stunden), ganz ohne eigenen PC.</p>
    <ol class="small" style="padding-left:18px;line-height:1.7">
      <li>Unter <a href="<?= e(url('settings')) ?>#api">Einstellungen</a> einen API-Schlüssel erzeugen.</li>
      <li>FIN im Fahrzeug hinterlegen (sonst wird dein einziges Auto genommen).</li>
      <li>Im GitHub-Repository unter <em>Settings → Secrets → Actions</em> anlegen: <code>SKODA_USER</code>, <code>SKODA_PASSWORD</code>, <code>GARAGE_URL</code>, <code>GARAGE_TOKEN</code>.</li>
      <li>Der Workflow <em>MySkoda-Abgleich</em> läuft dann automatisch.</li>
    </ol>
    <dl class="kv small mt"><dt>Letzter Abgleich</dt><dd><?= $skodaLast ? fmt_date($skodaLast) . ' ' . date('H:i', strtotime($skodaLast . ' UTC')) : 'noch nie' ?></dd><dt>API-Adresse</dt><dd><code><?= e($apiBase) ?></code></dd></dl>
    <div class="banner mt"><?= icon('info') ?><p class="small">Übernommen werden Kilometerstand sowie Inspektion und Ölservice (Tage/km bis fällig) – als Fristen mit dem Hinweis „MySkoda“.</p></div>
  </div>

  <div class="card">
    <h2 class="mb">Protokoll</h2>
    <?php if (!$log): ?><p class="muted small">Noch keine Abgleiche.</p><?php endif; ?>
    <?php foreach ($log as $l): ?>
      <div class="stat-line"><span class="small"><?= e($l['message']) ?></span><span class="muted xs nowrap"><?= e(date('d.m. H:i', strtotime($l['at'] . ' UTC'))) ?></span></div>
    <?php endforeach; ?>
  </div>
</div>
</div>
<?php page_footer();
