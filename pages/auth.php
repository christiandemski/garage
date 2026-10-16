<?php
declare(strict_types=1);
/** @var string $page */

function auth_card(string $title, string $sub, string $body, string $foot = ''): void
{
    page_header($title, ['bare' => true]);
    echo '<div class="auth-card"><div class="auth-brand"><span class="brand-mark lg">' . icon('car') . '</span><span>Garage</span></div>'
        . '<h1>' . e($title) . '</h1><p class="muted">' . $sub . '</p>' . $body . '</div>'
        . ($foot ? '<div class="auth-foot">' . $foot . '</div>' : '');
    page_footer();
}

switch ($page) {

case 'logout':
    // Nur per POST mit CSRF-Token – sonst könnte eine fremde Seite dich per Link/Bild abmelden
    if (!is_post()) redirect(url('more'));
    csrf_check();
    logout_user();
    flash('Du bist abgemeldet.');
    redirect(url('login'));

case 'login':
    $email = '';
    if (is_post()) {
        csrf_check();
        $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
        $pw = (string)($_POST['password'] ?? '');
        $u = row('SELECT * FROM users WHERE email = ?', [$email]);
        if (too_many_attempts() || account_locked($u)) {
            flash('Zu viele Anmeldeversuche. Bitte warte ein paar Minuten.', 'err');
        } else {
            if ($u && password_verify($pw, $u['password_hash'])) {
                if (cfg('require_email_verification', true) && !$u['verified_at']) {
                    send_verification($u);
                    flash('Bitte bestätige zuerst deine E-Mail-Adresse. Wir haben dir den Link noch einmal geschickt.', 'err');
                } else {
                    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
                        update('users', ['password_hash' => password_hash($pw, PASSWORD_DEFAULT)], 'id = ?', [$u['id']]);
                    }
                    login_user($u, !empty($_POST['remember']));
                    $to = $_SESSION['after_login'] ?? null;
                    unset($_SESSION['after_login']);
                    redirect(safe_target($to, url()));
                }
            } else {
                record_attempt();
                if ($u) record_account_failure($u);
                flash('E-Mail-Adresse oder Passwort stimmen nicht.', 'err');
            }
        }
    }
    $body = form_open(url('login'))
        . f_text('email', 'E-Mail', $email, ['type' => 'email', 'autocomplete' => 'email', 'required' => true, 'autofocus' => true])
        . f_text('password', 'Passwort', '', ['type' => 'password', 'autocomplete' => 'current-password', 'required' => true])
        . '<div class="row-between"><label class="check"><input type="checkbox" name="remember" value="1" checked> Angemeldet bleiben</label><a class="small" href="' . e(url('forgot')) . '">Passwort vergessen?</a></div>'
        . '<button class="btn btn-primary btn-block btn-lg" type="submit">Anmelden</button></form>';
    $foot = registration_open() ? 'Noch kein Konto? <a href="' . e(url('register')) . '">Registrieren</a>' : '';
    auth_card('Willkommen zurück', 'Melde dich mit deiner E-Mail-Adresse an.', $body, $foot);
    break;

case 'register':
    if (!registration_open()) {
        flash('Die Registrierung ist derzeit geschlossen.', 'err');
        redirect(url('login'));
    }
    $first = (int)val('SELECT COUNT(*) FROM users') === 0;
    $d = ['name' => '', 'email' => ''];
    if (is_post()) {
        csrf_check();
        $d['name'] = trim((string)($_POST['name'] ?? ''));
        $d['email'] = mb_strtolower(trim((string)($_POST['email'] ?? '')));
        $pw = (string)($_POST['password'] ?? '');
        $err = null;
        if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL)) $err = 'Bitte gib eine gültige E-Mail-Adresse an.';
        elseif ($p = password_problem($pw)) $err = $p;
        elseif ($pw !== (string)($_POST['password2'] ?? '')) $err = 'Die Passwörter stimmen nicht überein.';
        elseif (val('SELECT id FROM users WHERE email = ?', [$d['email']])) $err = 'Für diese E-Mail-Adresse gibt es schon ein Konto.';
        if ($err) {
            flash($err, 'err');
        } else {
            $verified = $first || !cfg('require_email_verification', true);
            $id = insert('users', [
                'email' => $d['email'], 'name' => $d['name'] ?: null,
                'password_hash' => password_hash($pw, PASSWORD_DEFAULT),
                'is_admin' => $first ? 1 : 0,
                'verified_at' => $verified ? now() : null,
                'created_at' => now(),
            ]);
            $u = row('SELECT * FROM users WHERE id = ?', [$id]);
            if ($verified) {
                login_user($u, true);
                flash($first ? 'Willkommen! Dein Admin-Konto ist eingerichtet. Lege jetzt dein erstes Fahrzeug an.' : 'Willkommen in deiner Garage!');
                redirect(url($first ? 'vehicle_edit' : 'dashboard'));
            }
            send_verification($u);
            auth_card('Fast fertig', 'Wir haben dir eine E-Mail an <strong>' . e($d['email']) . '</strong> geschickt. Klicke auf den Link darin, um dein Konto zu aktivieren.', '<a class="btn btn-block" href="' . e(url('login')) . '">Zur Anmeldung</a>');
            exit;
        }
    }
    $body = form_open(url('register'))
        . f_text('name', 'Name', $d['name'], ['autocomplete' => 'name', 'placeholder' => 'Wie sollen wir dich nennen?'])
        . f_text('email', 'E-Mail', $d['email'], ['type' => 'email', 'autocomplete' => 'email', 'required' => true])
        . f_text('password', 'Passwort', '', ['type' => 'password', 'autocomplete' => 'new-password', 'required' => true, 'hint' => 'Mindestens 10 Zeichen'])
        . f_text('password2', 'Passwort wiederholen', '', ['type' => 'password', 'autocomplete' => 'new-password', 'required' => true])
        . '<button class="btn btn-primary btn-block btn-lg" type="submit">Konto erstellen</button></form>';
    auth_card($first ? 'Garage einrichten' : 'Konto erstellen', $first ? 'Du bist der erste Nutzer – dein Konto wird zum Admin-Konto.' : 'Verwalte deine Fahrzeuge an einem Ort.', $body, 'Schon ein Konto? <a href="' . e(url('login')) . '">Anmelden</a>');
    break;

case 'verify':
    $u = row('SELECT * FROM users WHERE id = ?', [(int)($_GET['u'] ?? 0)]);
    $t = (string)($_GET['t'] ?? '');
    if ($u && $u['verify_token'] && hash_equals($u['verify_token'], hash('sha256', $t))) {
        update('users', ['verified_at' => now(), 'verify_token' => null], 'id = ?', [$u['id']]);
        login_user($u, true);
        flash('Deine E-Mail-Adresse ist bestätigt. Willkommen!');
        redirect(url());
    }
    flash('Der Bestätigungslink ist ungültig oder wurde schon benutzt.', 'err');
    redirect(url('login'));

case 'forgot':
    if (is_post()) {
        csrf_check();
        $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
        $u = row('SELECT * FROM users WHERE email = ?', [$email]);
        if ($u && !too_many_attempts()) {
            record_attempt();
            $token = bin2hex(random_bytes(24));
            update('users', ['reset_hash' => hash('sha256', $token), 'reset_expires' => gmdate('Y-m-d H:i:s', time() + 3600)], 'id = ?', [$u['id']]);
            $link = absolute_url(url('reset', ['u' => $u['id'], 't' => $token]));
            send_mail($u['email'], 'Passwort zurücksetzen', "Hallo,\n\nüber diesen Link kannst du ein neues Passwort festlegen (1 Stunde gültig):\n$link\n\nWenn du das nicht angefordert hast, ignoriere diese E-Mail einfach.",
                mail_html('Passwort zurücksetzen', '<p>Über den Button kannst du ein neues Passwort festlegen. Der Link ist eine Stunde gültig.</p><p style="color:#666">Wenn du das nicht angefordert hast, ignoriere diese E-Mail einfach.</p>', 'Neues Passwort festlegen', $link));
        }
        auth_card('E-Mail unterwegs', 'Falls ein Konto mit dieser Adresse existiert, haben wir dir einen Link zum Zurücksetzen geschickt.', '<a class="btn btn-block" href="' . e(url('login')) . '">Zur Anmeldung</a>');
        exit;
    }
    $body = form_open(url('forgot')) . f_text('email', 'E-Mail', '', ['type' => 'email', 'required' => true, 'autofocus' => true])
        . '<button class="btn btn-primary btn-block btn-lg" type="submit">Link anfordern</button></form>';
    auth_card('Passwort vergessen', 'Wir schicken dir einen Link, mit dem du ein neues Passwort festlegen kannst.', $body, '<a href="' . e(url('login')) . '">Zurück zur Anmeldung</a>');
    break;

case 'reset':
    $u = row('SELECT * FROM users WHERE id = ?', [(int)($_GET['u'] ?? 0)]);
    $t = (string)($_GET['t'] ?? '');
    $valid = $u && $u['reset_hash'] && $u['reset_expires'] > now() && hash_equals($u['reset_hash'], hash('sha256', $t));
    if (!$valid) {
        flash('Der Link ist ungültig oder abgelaufen.', 'err');
        redirect(url('forgot'));
    }
    if (is_post()) {
        csrf_check();
        $pw = (string)($_POST['password'] ?? '');
        if ($p = password_problem($pw)) flash($p, 'err');
        elseif ($pw !== (string)($_POST['password2'] ?? '')) flash('Die Passwörter stimmen nicht überein.', 'err');
        else {
            update('users', ['password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'reset_hash' => null, 'reset_expires' => null, 'verified_at' => $u['verified_at'] ?: now()], 'id = ?', [$u['id']]);
            q('DELETE FROM remember_tokens WHERE user_id = ?', [$u['id']]);
            login_user($u, true);
            flash('Dein Passwort wurde geändert.');
            redirect(url());
        }
    }
    $body = form_open(url('reset', ['u' => $u['id'], 't' => $t]))
        . f_text('password', 'Neues Passwort', '', ['type' => 'password', 'autocomplete' => 'new-password', 'required' => true, 'hint' => 'Mindestens 10 Zeichen'])
        . f_text('password2', 'Passwort wiederholen', '', ['type' => 'password', 'autocomplete' => 'new-password', 'required' => true])
        . '<button class="btn btn-primary btn-block btn-lg" type="submit">Passwort speichern</button></form>';
    auth_card('Neues Passwort', 'Lege ein neues Passwort für <strong>' . e($u['email']) . '</strong> fest.', $body);
    break;
}
