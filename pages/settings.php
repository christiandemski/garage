<?php
declare(strict_types=1);

$u = current_user();
$newToken = null;

if (is_post()) {
    csrf_check();
    $do = $_POST['do'] ?? '';
    switch ($do) {
        case 'profile':
            $email = mb_strtolower(trim((string)$_POST['email']));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { flash('Ungültige E-Mail-Adresse.', 'err'); break; }
            if ($email !== $u['email'] && val('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, $u['id']])) { flash('Diese E-Mail-Adresse wird schon verwendet.', 'err'); break; }
            update('users', ['name' => str_or_null($_POST['name'], 120), 'email' => $email], 'id = ?', [$u['id']]);
            flash('Profil gespeichert.');
            break;
        case 'password':
            if (!password_verify((string)$_POST['current'], $u['password_hash'])) { flash('Das aktuelle Passwort stimmt nicht.', 'err'); break; }
            if ($p = password_problem((string)$_POST['password'])) { flash($p, 'err'); break; }
            if ($_POST['password'] !== $_POST['password2']) { flash('Die Passwörter stimmen nicht überein.', 'err'); break; }
            update('users', ['password_hash' => password_hash((string)$_POST['password'], PASSWORD_DEFAULT)], 'id = ?', [$u['id']]);
            q('DELETE FROM remember_tokens WHERE user_id = ?', [$u['id']]);
            flash('Passwort geändert. Andere Geräte wurden abgemeldet.');
            break;
        case 'theme':
            $t = in_array($_POST['theme'] ?? '', ['system', 'light', 'dark'], true) ? $_POST['theme'] : 'system';
            update('users', ['theme' => $t], 'id = ?', [$u['id']]);
            flash('Design gespeichert.');
            break;
        case 'notify':
            update('users', ['notify_email' => (int)!empty($_POST['notify_email'])], 'id = ?', [$u['id']]);
            flash('Gespeichert.');
            break;
        case 'testmail':
            $ok = send_mail($u['email'], 'Testnachricht von Garage', "Hallo,\n\ndas ist eine Testnachricht. Der E-Mail-Versand funktioniert.", mail_html('Alles klar!', '<p>Der E-Mail-Versand deiner Garage funktioniert.</p>'));
            flash($ok ? 'Test-E-Mail verschickt an ' . $u['email'] . '.' : 'Der Versand ist fehlgeschlagen (Details in data/mail.log).', $ok ? 'ok' : 'err');
            break;
        case 'token':
            $newToken = 'gar_' . bin2hex(random_bytes(20));
            update('users', ['api_token_hash' => hash('sha256', $newToken), 'api_token_hint' => substr($newToken, -6)], 'id = ?', [$u['id']]);
            $_SESSION['new_token'] = $newToken;
            flash('Neuer API-Schlüssel erstellt. Er wird nur einmal angezeigt.');
            redirect(url('settings', ['s' => 'api']));
        case 'token_revoke':
            update('users', ['api_token_hash' => null, 'api_token_hint' => null], 'id = ?', [$u['id']]);
            flash('API-Schlüssel widerrufen.');
            break;
        case 'registration':
            if ($u['is_admin']) { setting_set('registration_open', !empty($_POST['open']) ? '1' : '0'); flash('Gespeichert.'); }
            break;
        case 'delete_account':
            if (!password_verify((string)$_POST['current'], $u['password_hash'])) { flash('Das Passwort stimmt nicht.', 'err'); break; }
            foreach (rows('SELECT id FROM vehicles WHERE user_id = ?', [$u['id']]) as $v) delete_vehicle_files((int)$v['id']);
            q('DELETE FROM users WHERE id = ?', [$u['id']]);
            logout_user();
            flash('Dein Konto wurde gelöscht.');
            redirect(url('login'));
    }
    redirect(url('settings', ['s' => $_GET['s'] ?? '']));
}

$newToken = $_SESSION['new_token'] ?? null;
unset($_SESSION['new_token']);
$section = $_GET['s'] ?? '';
$base = rtrim(absolute_url(rtrim((string)cfg('base_url'), '/') . '/'), '/');

page_header('Einstellungen', ['nav' => 'settings', 'back' => url('more')]);
?>
<div class="grid grid-2">
<div>
  <div class="card">
    <h2 class="mb">Profil</h2>
    <form method="post" action="<?= e(url('settings')) ?>" class="form"><?= csrf_field() ?><input type="hidden" name="do" value="profile">
      <?= f_text('name', 'Name', $u['name'], ['autocomplete' => 'name']) ?>
      <?= f_text('email', 'E-Mail (Anmeldung)', $u['email'], ['type' => 'email', 'required' => true]) ?>
      <div class="form-actions"><button class="btn btn-primary" type="submit">Speichern</button></div>
    </form>
  </div>

  <div class="card">
    <h2 class="mb">Design</h2>
    <form method="post" action="<?= e(url('settings')) ?>" class="form"><?= csrf_field() ?><input type="hidden" name="do" value="theme">
      <div class="seg" style="width:100%">
        <?php foreach (['system' => ['Automatisch', 'monitor'], 'light' => ['Hell', 'sun'], 'dark' => ['Dunkel', 'moon']] as $k => [$l, $ic]): ?>
          <label style="flex:1"><input type="radio" name="theme" value="<?= $k ?>" hidden<?= $u['theme'] === $k ? ' checked' : '' ?> onchange="this.form.querySelectorAll('.seg-btn').forEach(b=>b.classList.toggle('on',b.previousElementSibling.checked));this.form.submit()"><span class="seg-btn<?= $u['theme'] === $k ? ' on' : '' ?>" style="width:100%;justify-content:center"><?= icon($ic) ?><?= $l ?></span></label>
        <?php endforeach; ?>
      </div>
    </form>
  </div>

  <div class="card">
    <h2 class="mb">Benachrichtigungen</h2>
    <form method="post" action="<?= e(url('settings')) ?>"><?= csrf_field() ?><input type="hidden" name="do" value="notify">
      <?= f_toggle('notify_email', 'E-Mail bei fälligen Fristen', (bool)$u['notify_email'], 'Einmal pro Frist, wenn sie in den Vorlauf kommt, und wenn sie überfällig wird.') ?>
      <div class="form-actions"><button class="btn btn-ghost" type="submit" formaction="<?= e(url('settings')) ?>" name="do" value="testmail">Test-E-Mail</button><button class="btn btn-primary" type="submit">Speichern</button></div>
    </form>
    <p class="muted small mt">Voraussetzung: ein täglicher Cronjob auf dem Webspace (siehe README).</p>
  </div>

  <div class="card">
    <h2 class="mb">Passwort ändern</h2>
    <form method="post" action="<?= e(url('settings')) ?>" class="form"><?= csrf_field() ?><input type="hidden" name="do" value="password">
      <?= f_text('current', 'Aktuelles Passwort', '', ['type' => 'password', 'autocomplete' => 'current-password', 'required' => true]) ?>
      <?= f_text('password', 'Neues Passwort', '', ['type' => 'password', 'autocomplete' => 'new-password', 'required' => true, 'hint' => 'Mindestens 10 Zeichen']) ?>
      <?= f_text('password2', 'Wiederholen', '', ['type' => 'password', 'autocomplete' => 'new-password', 'required' => true]) ?>
      <div class="form-actions"><button class="btn btn-primary" type="submit">Ändern</button></div>
    </form>
  </div>
</div>

<div>
  <div class="card flush settings-nav" id="daten">
    <h2 style="padding:18px 18px 4px">Daten</h2>
    <ul class="list">
      <li><a class="list-item" href="<?= e(url('import')) ?>"><span class="li-ic c-teal"><?= icon('refresh') ?></span><span class="li-main"><span class="li-title">Import &amp; Abgleich</span><span class="li-sub">Spritmonitor, MySkoda, CSV</span></span><?= icon('chevron-right', 'li-chev') ?></a></li>
      <li><a class="list-item" href="<?= e(url('export', ['what' => 'all'])) ?>"><span class="li-ic c-sky"><?= icon('download') ?></span><span class="li-main"><span class="li-title">Daten exportieren</span><span class="li-sub">Alle Einträge als CSV (ZIP)</span></span><?= icon('chevron-right', 'li-chev') ?></a></li>
    </ul>
  </div>

  <div class="card" id="api">
    <h2 class="mb">API-Schlüssel</h2>
    <p class="muted small">Für den automatischen MySkoda-Abgleich, das Home-Dashboard oder eigene Skripte.</p>
    <?php if ($newToken): ?>
      <div class="banner warn mb"><?= icon('key') ?><p>Kopiere den Schlüssel jetzt – er wird nicht noch einmal angezeigt.</p></div>
      <div class="token mb"><span><?= e($newToken) ?></span><button class="icon-btn" type="button" data-copy="<?= e($newToken) ?>" aria-label="Kopieren"><?= icon('copy') ?></button></div>
    <?php elseif ($u['api_token_hint']): ?>
      <div class="token mb"><span>gar_••••••••••••<?= e($u['api_token_hint']) ?></span><?= icon('lock') ?></div>
    <?php endif; ?>
    <div class="row wrap">
      <form method="post" action="<?= e(url('settings')) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="token"><button class="btn" type="submit"><?= icon('key') ?> <?= $u['api_token_hint'] ? 'Neu erzeugen' : 'Schlüssel erzeugen' ?></button></form>
      <?php if ($u['api_token_hint']): ?><form method="post" action="<?= e(url('settings')) ?>" class="inline" data-confirm="Schlüssel widerrufen? Verbundene Dienste funktionieren dann nicht mehr."><?= csrf_field() ?><input type="hidden" name="do" value="token_revoke"><button class="btn btn-ghost btn-danger" type="submit">Widerrufen</button></form><?php endif; ?>
    </div>
    <dl class="kv mt small">
      <dt>API-Adresse</dt><dd><code><?= e($base) ?>/api.php</code></dd>
    </dl>
  </div>

  <?php if ($u['is_admin']): ?>
  <div class="card">
    <h2 class="mb">Administration</h2>
    <form method="post" action="<?= e(url('settings')) ?>"><?= csrf_field() ?><input type="hidden" name="do" value="registration">
      <?= f_toggle('open', 'Registrierung offen', setting_get('registration_open', '0') === '1', 'Andere können sich selbst ein Konto anlegen (z. B. Partnerin/Partner). Danach am besten wieder schließen.') ?>
      <div class="form-actions"><button class="btn btn-primary" type="submit">Speichern</button></div>
    </form>
    <div class="mt">
      <div class="label mb">Konten</div>
      <?php foreach (rows('SELECT email, name, is_admin, last_login FROM users ORDER BY id') as $x): ?>
        <div class="stat-line"><span><?= e($x['name'] ?: $x['email']) ?> <?= $x['is_admin'] ? '<span class="pill pill-accent">Admin</span>' : '' ?></span><span class="muted small"><?= $x['last_login'] ? fmt_date($x['last_login']) : '–' ?></span></div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <div class="card">
    <h2 class="mb">Konto löschen</h2>
    <p class="muted small">Löscht dein Konto mit allen Fahrzeugen, Einträgen und Dokumenten endgültig.</p>
    <form method="post" action="<?= e(url('settings')) ?>" class="form" data-confirm="Konto und alle Daten endgültig löschen?"><?= csrf_field() ?><input type="hidden" name="do" value="delete_account">
      <?= f_text('current', 'Passwort zur Bestätigung', '', ['type' => 'password', 'required' => true, 'autocomplete' => 'current-password']) ?>
      <div class="form-actions"><button class="btn btn-ghost btn-danger" type="submit"><?= icon('trash') ?> Konto löschen</button></div>
    </form>
  </div>
</div>
</div>
<?php page_footer();
