<?php
/**
 * Garage – Konfiguration
 * Diese Datei als config.php kopieren und anpassen.
 * config.php wird NICHT ins Git-Repository übernommen.
 */
return [
    // Öffentliche Adresse der App (ohne Slash am Ende)
    'base_url' => 'https://garage.example.de',

    // Datenbank: 'mysql' (Netcup) oder 'sqlite' (lokal testen)
    'db' => [
        'driver'   => 'mysql',
        'host'     => 'localhost',
        'port'     => 3306,
        'name'     => 'garage',
        'user'     => 'garage',
        'password' => 'GEHEIM',
        // nur für sqlite:
        'path'     => __DIR__ . '/data/garage.sqlite',
    ],

    // E-Mail-Versand (PHP mail() – funktioniert auf Netcup-Webhosting)
    'mail_from'      => 'garage@example.de',
    'mail_from_name' => 'Garage',

    // Neue Konten müssen ihre E-Mail-Adresse bestätigen (das erste Konto ist automatisch bestätigt)
    'require_email_verification' => true,

    // Schlüssel für Cron-Aufrufe per URL: /cron/run.php?key=...
    'cron_key' => 'bitte-lange-zufaellige-zeichenkette',

    // Spritmonitor: Application-ID (beim Spritmonitor-Support anfragen)
    'spritmonitor_app_id' => '',

    'timezone' => 'Europe/Berlin',
    'debug'    => false,
];
