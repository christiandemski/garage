<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $c = cfg('db');
    if (($c['driver'] ?? 'mysql') === 'sqlite') {
        $dir = dirname($c['path']);
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $pdo = new PDO('sqlite:' . $c['path']);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
    } else {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'] ?? 3306, $c['name']);
        $pdo = new PDO($dsn, $c['user'], $c['password']);
        $pdo->exec("SET time_zone = '+00:00'");
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    return $pdo;
}

function is_sqlite(): bool
{
    return (cfg('db')['driver'] ?? 'mysql') === 'sqlite';
}

/** Kurzformen für Abfragen */
function q(string $sql, array $p = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($p);
    return $st;
}
function row(string $sql, array $p = []): ?array
{
    $r = q($sql, $p)->fetch();
    return $r ?: null;
}
function rows(string $sql, array $p = []): array
{
    return q($sql, $p)->fetchAll();
}
function val(string $sql, array $p = [])
{
    $v = q($sql, $p)->fetchColumn();
    return $v === false ? null : $v;
}
function insert(string $table, array $data): int
{
    $cols = array_keys($data);
    $sql = 'INSERT INTO ' . $table . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
    q($sql, array_values($data));
    return (int)db()->lastInsertId();
}
function update(string $table, array $data, string $where, array $wp = []): void
{
    $set = implode(',', array_map(fn($c) => "$c = ?", array_keys($data)));
    q("UPDATE $table SET $set WHERE $where", array_merge(array_values($data), $wp));
}

/** Datenbankschema – wird bei jedem Start geprüft und bei Bedarf angelegt/erweitert */
const SCHEMA_VERSION = 1;

function migrate(): void
{
    $sqlite = is_sqlite();
    $pk   = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
    $fk   = $sqlite ? 'INTEGER' : 'INT UNSIGNED';
    $txt  = $sqlite ? 'TEXT' : 'TEXT';
    $str  = fn(int $n) => $sqlite ? 'TEXT' : "VARCHAR($n)";
    $dec  = $sqlite ? 'REAL' : 'DECIMAL(12,3)';
    $money = $sqlite ? 'REAL' : 'DECIMAL(12,2)';
    $dt   = $sqlite ? 'TEXT' : 'DATETIME';
    $date = $sqlite ? 'TEXT' : 'DATE';
    $int  = $sqlite ? 'INTEGER' : 'INT';
    $tail = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    db()->exec("CREATE TABLE IF NOT EXISTS settings (k {$str(64)} PRIMARY KEY, v $txt)$tail");
    $ver = (int)(val('SELECT v FROM settings WHERE k = ?', ['schema_version']) ?? 0);
    if ($ver >= SCHEMA_VERSION) return;

    $tables = [
        "users (
            id $pk,
            email {$str(190)} NOT NULL UNIQUE,
            name {$str(120)},
            password_hash {$str(255)} NOT NULL,
            is_admin $int NOT NULL DEFAULT 0,
            verified_at $dt NULL,
            verify_token {$str(64)} NULL,
            reset_hash {$str(64)} NULL,
            reset_expires $dt NULL,
            theme {$str(10)} NOT NULL DEFAULT 'system',
            notify_email $int NOT NULL DEFAULT 1,
            api_token_hash {$str(64)} NULL,
            api_token_hint {$str(12)} NULL,
            sm_token $txt NULL,
            failed_logins $int NOT NULL DEFAULT 0,
            locked_until $dt NULL,
            last_login $dt NULL,
            created_at $dt NOT NULL
        )",
        "remember_tokens (
            id $pk,
            user_id $fk NOT NULL,
            selector {$str(24)} NOT NULL UNIQUE,
            validator_hash {$str(64)} NOT NULL,
            expires $dt NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )",
        "vehicles (
            id $pk,
            user_id $fk NOT NULL,
            type {$str(20)} NOT NULL DEFAULT 'auto',
            name {$str(120)} NOT NULL,
            make {$str(80)},
            model {$str(80)},
            variant {$str(80)},
            year $int NULL,
            plate {$str(20)},
            vin {$str(32)},
            hsn_tsn {$str(20)},
            fuel_type {$str(20)},
            tank_l $dec NULL,
            color {$str(9)},
            photo {$str(80)},
            first_registration $date NULL,
            purchase_date $date NULL,
            purchase_price $money NULL,
            purchase_km $int NULL,
            tire_size {$str(60)},
            oil_spec {$str(60)},
            insurance_company {$str(80)},
            insurance_number {$str(60)},
            frame_number {$str(60)},
            notes $txt,
            sm_vehicle_id {$str(20)},
            sm_tank_id {$str(10)},
            archived $int NOT NULL DEFAULT 0,
            sort $int NOT NULL DEFAULT 0,
            created_at $dt NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )",
        "fuelings (
            id $pk,
            vehicle_id $fk NOT NULL,
            date $date NOT NULL,
            odometer $int NULL,
            trip $dec NULL,
            quantity $dec NOT NULL,
            total $money NOT NULL,
            full_tank $int NOT NULL DEFAULT 1,
            missed_before $int NOT NULL DEFAULT 0,
            fuel_sort {$str(30)},
            station {$str(120)},
            note $txt,
            source {$str(20)} NOT NULL DEFAULT 'manual',
            ext_id {$str(40)},
            created_at $dt NOT NULL,
            FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
        )",
        "costs (
            id $pk,
            vehicle_id $fk NOT NULL,
            date $date NOT NULL,
            category {$str(30)} NOT NULL,
            title {$str(160)} NOT NULL,
            amount $money NOT NULL,
            odometer $int NULL,
            vendor {$str(120)},
            repeat_months $int NOT NULL DEFAULT 0,
            repeated $int NOT NULL DEFAULT 0,
            note $txt,
            source {$str(20)} NOT NULL DEFAULT 'manual',
            ext_id {$str(40)},
            created_at $dt NOT NULL,
            FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
        )",
        "odometer_log (
            id $pk,
            vehicle_id $fk NOT NULL,
            date $date NOT NULL,
            km $int NOT NULL,
            trip $dec NULL,
            source {$str(20)} NOT NULL DEFAULT 'manual',
            note {$str(200)},
            created_at $dt NOT NULL,
            FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
        )",
        "reminders (
            id $pk,
            vehicle_id $fk NOT NULL,
            kind {$str(30)} NOT NULL DEFAULT 'sonstiges',
            title {$str(160)} NOT NULL,
            due_date $date NULL,
            due_km $int NULL,
            interval_months $int NULL,
            interval_km $int NULL,
            warn_days $int NOT NULL DEFAULT 30,
            warn_km $int NOT NULL DEFAULT 1000,
            note $txt,
            source {$str(30)} NOT NULL DEFAULT 'manual',
            done_at $dt NULL,
            notified_at $dt NULL,
            notified_stage {$str(10)} NULL,
            created_at $dt NOT NULL,
            FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
        )",
        "parts (
            id $pk,
            vehicle_id $fk NOT NULL,
            category {$str(30)} NOT NULL,
            name {$str(160)} NOT NULL,
            brand {$str(80)},
            installed_date $date NULL,
            installed_km $int NULL,
            limit_km $int NULL,
            limit_months $int NULL,
            removed_date $date NULL,
            removed_km $int NULL,
            price $money NULL,
            season {$str(12)},
            dot {$str(10)},
            tread_mm $dec NULL,
            storage {$str(120)},
            note $txt,
            created_at $dt NOT NULL,
            FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
        )",
        "documents (
            id $pk,
            vehicle_id $fk NOT NULL,
            cost_id $fk NULL,
            title {$str(160)} NOT NULL,
            kind {$str(30)} NOT NULL DEFAULT 'sonstiges',
            filename {$str(200)} NOT NULL,
            stored_name {$str(80)} NOT NULL,
            mime {$str(80)},
            size $int NOT NULL DEFAULT 0,
            created_at $dt NOT NULL,
            FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
        )",
        "login_attempts (
            id $pk,
            ip {$str(45)} NOT NULL,
            at $dt NOT NULL
        )",
        "sync_log (
            id $pk,
            user_id $fk NOT NULL,
            source {$str(20)} NOT NULL,
            message $txt,
            at $dt NOT NULL
        )",
    ];
    foreach ($tables as $t) {
        db()->exec("CREATE TABLE IF NOT EXISTS $t$tail");
    }
    $idx = [
        'CREATE INDEX idx_fuel_v ON fuelings (vehicle_id, date)',
        'CREATE INDEX idx_cost_v ON costs (vehicle_id, date)',
        'CREATE INDEX idx_odo_v ON odometer_log (vehicle_id, date)',
        'CREATE INDEX idx_rem_v ON reminders (vehicle_id)',
        'CREATE INDEX idx_parts_v ON parts (vehicle_id)',
        'CREATE INDEX idx_veh_u ON vehicles (user_id)',
    ];
    foreach ($idx as $i) {
        try { db()->exec($i); } catch (PDOException $e) { /* existiert bereits */ }
    }
    setting_set('schema_version', (string)SCHEMA_VERSION);
}

function setting_get(string $k, ?string $default = null): ?string
{
    $v = val('SELECT v FROM settings WHERE k = ?', [$k]);
    return $v === null ? $default : (string)$v;
}
function setting_set(string $k, string $v): void
{
    if (val('SELECT COUNT(*) FROM settings WHERE k = ?', [$k])) {
        q('UPDATE settings SET v = ? WHERE k = ?', [$v, $k]);
    } else {
        q('INSERT INTO settings (k, v) VALUES (?, ?)', [$k, $v]);
    }
}
