<?php
declare(strict_types=1);

const REMEMBER_COOKIE = 'garage_remember';
const REMEMBER_DAYS = 60;

function current_user(): ?array
{
    static $user = false;
    if ($user !== false) return $user;
    $user = null;
    if (!empty($_SESSION['uid'])) {
        $user = row('SELECT * FROM users WHERE id = ?', [$_SESSION['uid']]);
    }
    if (!$user && !empty($_COOKIE[REMEMBER_COOKIE])) {
        $user = login_from_cookie();
    }
    return $user;
}
function uid(): int
{
    return (int)(current_user()['id'] ?? 0);
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? null;
        redirect(url('login'));
    }
    return $u;
}

function login_user(array $user, bool $remember): void
{
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$user['id'];
    update('users', ['last_login' => now(), 'failed_logins' => 0, 'locked_until' => null], 'id = ?', [$user['id']]);
    if ($remember) issue_remember_token((int)$user['id']);
}

function issue_remember_token(int $userId): void
{
    $selector = bin2hex(random_bytes(9));
    $validator = bin2hex(random_bytes(32));
    insert('remember_tokens', [
        'user_id' => $userId,
        'selector' => $selector,
        'validator_hash' => hash('sha256', $validator),
        'expires' => gmdate('Y-m-d H:i:s', time() + REMEMBER_DAYS * 86400),
    ]);
    set_cookie(REMEMBER_COOKIE, $selector . ':' . $validator, time() + REMEMBER_DAYS * 86400);
}

function login_from_cookie(): ?array
{
    $parts = explode(':', (string)$_COOKIE[REMEMBER_COOKIE]);
    if (count($parts) !== 2) return null;
    [$sel, $val] = $parts;
    $t = row('SELECT * FROM remember_tokens WHERE selector = ?', [$sel]);
    if (!$t || $t['expires'] < now() || !hash_equals($t['validator_hash'], hash('sha256', $val))) {
        set_cookie(REMEMBER_COOKIE, '', time() - 3600);
        return null;
    }
    // Token rotieren
    q('DELETE FROM remember_tokens WHERE id = ?', [$t['id']]);
    $user = row('SELECT * FROM users WHERE id = ?', [$t['user_id']]);
    if (!$user) return null;
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$user['id'];
    issue_remember_token((int)$user['id']);
    return $user;
}

function logout_user(): void
{
    if (!empty($_COOKIE[REMEMBER_COOKIE])) {
        $sel = explode(':', (string)$_COOKIE[REMEMBER_COOKIE])[0];
        q('DELETE FROM remember_tokens WHERE selector = ?', [$sel]);
        set_cookie(REMEMBER_COOKIE, '', time() - 3600);
    }
    $_SESSION = [];
    session_regenerate_id(true);
}

function set_cookie(string $name, string $value, int $expires): void
{
    setcookie($name, $value, [
        'expires' => $expires,
        'path' => '/',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

/** Einfacher Schutz gegen Passwort-Raten: max. 10 Fehlversuche pro IP in 15 Minuten */
function too_many_attempts(): bool
{
    q('DELETE FROM login_attempts WHERE at < ?', [gmdate('Y-m-d H:i:s', time() - 900)]);
    return (int)val('SELECT COUNT(*) FROM login_attempts WHERE ip = ?', [client_ip()]) >= 10;
}
function record_attempt(): void
{
    insert('login_attempts', ['ip' => client_ip(), 'at' => now()]);
}

/** Zusätzlich pro Konto: nach 10 Fehlversuchen 15 Minuten gesperrt (unabhängig von der IP) */
const ACCOUNT_MAX_FAILS = 10;
function account_locked(?array $u): bool
{
    return $u && $u['locked_until'] && $u['locked_until'] > now();
}
function record_account_failure(array $u): void
{
    $fails = (int)$u['failed_logins'] + 1;
    $data = ['failed_logins' => $fails];
    if ($fails >= ACCOUNT_MAX_FAILS) {
        $data = ['failed_logins' => 0, 'locked_until' => gmdate('Y-m-d H:i:s', time() + 900)];
    }
    update('users', $data, 'id = ?', [$u['id']]);
}

function registration_open(): bool
{
    if ((int)val('SELECT COUNT(*) FROM users') === 0) return true;
    return setting_get('registration_open', '0') === '1';
}

function password_problem(string $pw): ?string
{
    if (mb_strlen($pw) < 10) return 'Das Passwort muss mindestens 10 Zeichen lang sein.';
    return null;
}

function send_verification(array $user): void
{
    $token = bin2hex(random_bytes(24));
    update('users', ['verify_token' => hash('sha256', $token)], 'id = ?', [$user['id']]);
    $link = absolute_url(url('verify', ['t' => $token, 'u' => $user['id']]));
    send_mail(
        $user['email'],
        'Bitte bestätige deine E-Mail-Adresse',
        "Hallo,\n\nbitte bestätige deine E-Mail-Adresse für Garage:\n$link\n",
        mail_html('E-Mail-Adresse bestätigen', '<p>Willkommen in deiner Garage! Bitte bestätige kurz deine E-Mail-Adresse.</p>', 'E-Mail bestätigen', $link)
    );
}

function absolute_url(string $u): string
{
    if (preg_match('#^https?://#', $u)) return $u;
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return (is_https() ? 'https://' : 'http://') . $host . $u;
}

function api_user_from_request(): ?array
{
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    $token = '';
    // Nur per Header – Tokens in der URL landen in Logs und im Browserverlauf
    if (preg_match('/Bearer\s+(\S+)/i', $h, $m)) $token = $m[1];
    if (strlen($token) < 20) return null;
    return row('SELECT * FROM users WHERE api_token_hash = ?', [hash('sha256', $token)]);
}
