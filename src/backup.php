<?php
declare(strict_types=1);

/*
 * Datensicherung: Datenbank + alle Uploads (Belege, Dokumente, Fotos) als ZIP unter data/backups.
 * data/ ist per .htaccess gesperrt; heruntergeladen wird nur über die Einstellungen (Admin).
 */

// Zufallsteil im Namen: Sicherungen sind nicht erratbar, selbst wenn data/ einmal erreichbar wäre
const BACKUP_PATTERN = '/^garage-backup-\d{4}-\d{2}-\d{2}-\d{6}-[a-f0-9]{8}\.zip$/';

function backup_dir(): string
{
    $d = APP_ROOT . '/data/backups';
    if (!is_dir($d)) mkdir($d, 0775, true);
    return $d;
}

/** Vorhandene Sicherungen, neueste zuerst: [[name, size, time], …] */
function backup_list(): array
{
    $out = [];
    foreach (glob(backup_dir() . '/garage-backup-*.zip') ?: [] as $f) {
        if (preg_match(BACKUP_PATTERN, basename($f))) $out[] = ['name' => basename($f), 'size' => filesize($f), 'time' => filemtime($f)];
    }
    usort($out, fn($a, $b) => $b['time'] <=> $a['time']);
    return $out;
}

function backup_path(string $name): ?string
{
    return preg_match(BACKUP_PATTERN, $name) && is_file(backup_dir() . '/' . $name) ? backup_dir() . '/' . $name : null;
}

/**
 * Erstellt eine Sicherung und löscht ältere über die Aufbewahrung hinaus.
 * Gibt [name, size, files] zurück; wirft bei Fehlern eine Exception.
 */
function backup_create(): array
{
    if (!class_exists('ZipArchive')) throw new RuntimeException('PHP-Erweiterung „zip“ fehlt.');
    @set_time_limit(300);
    $name = 'garage-backup-' . date('Y-m-d-His') . '-' . bin2hex(random_bytes(4)) . '.zip';
    $target = backup_dir() . '/' . $name;
    $part = $target . '.part';
    $temps = [];

    $zip = new ZipArchive();
    if ($zip->open($part, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('ZIP-Datei konnte nicht angelegt werden.');
    try {
        // Datenbank
        if (is_sqlite()) {
            $tmp = tempnam(backup_dir(), 'tmp');
            @unlink($tmp);
            try { db()->exec('VACUUM INTO ' . db()->quote($tmp)); } catch (PDOException $e) { copy(cfg('db')['path'], $tmp); }
            $zip->addFile($tmp, 'datenbank/garage.sqlite');
        } else {
            $tmp = backup_mysql_dump();
            $zip->addFile($tmp, 'datenbank/garage.sql');
        }
        $temps[] = $tmp;

        // Uploads aller Konten
        $files = 0;
        $root = APP_ROOT . '/data/uploads';
        if (is_dir($root)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if (!$f->isFile()) continue;
                $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
                $zip->addFile($f->getPathname(), 'uploads/' . $rel);
                $files++;
            }
        }
        $zip->addFromString('LIESMICH.txt', backup_readme($files));
        if (!$zip->close()) throw new RuntimeException('ZIP-Datei konnte nicht geschrieben werden.');
    } catch (Throwable $e) {
        @$zip->close();
        @unlink($part);
        foreach ($temps as $t) @unlink($t);
        throw $e;
    }
    foreach ($temps as $t) @unlink($t);
    rename($part, $target);
    setting_set('backup_last', now());

    // Aufbewahrung: die neuesten N behalten
    $keep = max(1, (int)cfg('backup_keep', 14));
    foreach (array_slice(backup_list(), $keep) as $old) @unlink(backup_dir() . '/' . $old['name']);

    return ['name' => $name, 'size' => filesize($target), 'files' => $files];
}

/** SQL-Dump (MySQL/MariaDB) ohne mysqldump – zum Einspielen z. B. per phpMyAdmin */
function backup_mysql_dump(): string
{
    $tmp = tempnam(backup_dir(), 'tmp');
    $fh = fopen($tmp, 'w');
    fwrite($fh, "-- Garage " . APP_VERSION . " · Sicherung vom " . date('d.m.Y H:i') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");
    foreach (db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $create = db()->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM)[1];
        fwrite($fh, "DROP TABLE IF EXISTS `$t`;\n$create;\n\n");
        $st = db()->query("SELECT * FROM `$t`");
        $batch = [];
        while ($r = $st->fetch(PDO::FETCH_NUM)) {
            $batch[] = '(' . implode(',', array_map(fn($v) => $v === null ? 'NULL' : db()->quote((string)$v), $r)) . ')';
            if (count($batch) >= 200) { fwrite($fh, "INSERT INTO `$t` VALUES\n" . implode(",\n", $batch) . ";\n"); $batch = []; }
        }
        if ($batch) fwrite($fh, "INSERT INTO `$t` VALUES\n" . implode(",\n", $batch) . ";\n");
        fwrite($fh, "\n");
    }
    fwrite($fh, "SET FOREIGN_KEY_CHECKS = 1;\n");
    fclose($fh);
    return $tmp;
}

function backup_readme(int $files): string
{
    $db = is_sqlite() ? 'datenbank/garage.sqlite (SQLite-Datei)' : 'datenbank/garage.sql (SQL-Dump für MySQL/MariaDB)';
    return "Garage – Datensicherung vom " . date('d.m.Y H:i') . "\n"
        . "App-Version " . APP_VERSION . ", Datenbank-Schema " . SCHEMA_VERSION . "\n\n"
        . "Inhalt\n"
        . "  $db\n"
        . "  uploads/  – $files Dateien (Belege, Dokumente, Fahrzeugfotos), je Konto ein Ordner\n\n"
        . "Wiederherstellen\n"
        . "  1. App-Dateien wie gewohnt bereitstellen (Plesk-Git) und config.php anlegen.\n"
        . (is_sqlite()
            ? "  2. garage.sqlite an den in config.php eingetragenen Pfad kopieren.\n"
            : "  2. In Plesk die Datenbank öffnen (phpMyAdmin) → Importieren → garage.sql.\n")
        . "  3. Den Ordner uploads/ nach data/uploads/ auf dem Webspace hochladen.\n\n"
        . "Nicht enthalten: config.php (Zugangsdaten) – die bitte separat aufbewahren.\n";
}

function fmt_bytes(int $b): string
{
    if ($b < 1024 * 1024) return fmt_num($b / 1024, 0) . ' KB';
    return fmt_num($b / 1024 / 1024, $b < 100 * 1024 * 1024 ? 1 : 0) . ' MB';
}
