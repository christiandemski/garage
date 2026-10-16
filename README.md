# Garage

Private Web-App zur Verwaltung von Auto und Fahrrädern – als installierbare App (PWA) fürs Handy und mit Desktop-Ansicht.

**Funktionen**

- **Fahrzeuge**: Auto, Fahrräder, E-Bike, Motorrad, Anhänger – mit Foto, Farbe, Kennzeichen, FIN, HSN/TSN, Reifengröße, Öl, Versicherung, Kaufdaten
- **Tanken/Laden**: Verbrauch nach Volltank-Methode, Literpreis-Rechner, Verbrauchsdiagramm, Statistik
- **Kosten**: 13 Kategorien, Belege als PDF/Foto, wiederkehrende Kosten (z. B. Versicherung monatlich/jährlich), Auswertung nach Monat, Kategorie und Fahrzeug, Kosten pro km, CSV-Export
- **Fristen**: HU/AU, Inspektion, Ölwechsel, Reifenwechsel, Bremsflüssigkeit, Verbandskasten … nach Datum *und/oder* Kilometer, mit Intervall – „Erledigt“ legt automatisch die nächste Frist an und kann gleich die Kosten buchen
- **Reifen & Verschleißteile**: Laufleistung je Teil (Kette, Kassette, Bremsbeläge, Reifen …), Warnung ab 80 %, Sommer/Winter-Sätze mit DOT, Profiltiefe und Lagerort, Ein-/Ausbau mit weiterlaufender Laufleistung
- **Kilometer**: Tachostände oder Fahrten (praktisch fürs Fahrrad), Kilometer pro Jahr
- **Dokumente**: Fahrzeugschein, Rechnungen, HU-Berichte, Verträge
- **Import**: Spritmonitor (CSV-Export und API-Abgleich), Garage-CSV, eigene Tabellen – Duplikate werden erkannt
- **MySkoda**: Kilometerstand sowie Inspektions- und Ölservice-Fälligkeit automatisch aus dem Auto (per GitHub Actions)
- **E-Mail-Erinnerungen** bei fälligen Fristen (täglicher Cronjob)
- **Konten** mit E-Mail und Passwort, „Angemeldet bleiben“, Passwort-Reset per E-Mail, Schutz gegen Passwort-Raten
- **Hell/Dunkel/Automatisch**, Smartphone-Navigation unten, Seitenleiste am Desktop
- **JSON-API** für Home-Dashboard, Skripte und später z. B. Garmin-Fahrten

Technik: PHP 8.1+ und MySQL/MariaDB (oder SQLite), keine Abhängigkeiten, keine externen Skripte oder Schriften (DSGVO-freundlich).

---

## Einrichtung auf Netcup (Webhosting 8000)

### 1. Datenbank und Subdomain

Im Netcup-Kundenbereich (WCP / Plesk):

1. **Datenbank anlegen** (MySQL/MariaDB) – Name, Benutzer und Passwort notieren.
2. **Subdomain anlegen**, z. B. `garage.deinedomain.de`, mit eigenem Dokumentenstamm, z. B. `/garage`.
3. **SSL-Zertifikat** (Let’s Encrypt) für die Subdomain aktivieren.
4. Unter *PHP-Einstellungen* PHP **8.1 oder neuer** wählen.

### 2. Dateien hochladen – automatisch per GitHub

Der Workflow `.github/workflows/deploy.yml` lädt bei jedem Push auf `main` alles per FTP hoch.

Im GitHub-Repository unter **Settings → Secrets and variables → Actions → New repository secret** anlegen:

| Secret | Inhalt |
|---|---|
| `FTP_SERVER` | FTP-Server aus dem WCP (z. B. `hostXXXX.netcup.net`) |
| `FTP_USERNAME` | FTP-Benutzer |
| `FTP_PASSWORD` | FTP-Passwort |
| `FTP_DIR` | Zielordner = Dokumentenstamm der Subdomain, z. B. `/garage/` |

Danach unter **Actions → Deploy auf Netcup → Run workflow** einmal starten (oder einfach etwas pushen).

> Alternativ: Repository als ZIP herunterladen und im Plesk-Dateimanager hochladen und entpacken.

### 3. `config.php` anlegen

Im Plesk-Dateimanager im Ordner der App `config.sample.php` kopieren, als `config.php` speichern und anpassen:

- `base_url` → `https://garage.deinedomain.de`
- `db` → Zugangsdaten aus Schritt 1 (`driver` bleibt `mysql`, `host` meist `localhost`)
- `mail_from` → eine Adresse deiner Domain (z. B. `garage@deinedomain.de`)
- `cron_key` → lange zufällige Zeichenkette

`config.php` wird vom Deploy **nie überschrieben** und ist per `.htaccess` gesperrt.

### 4. Erstes Konto

`https://garage.deinedomain.de` öffnen → du landest bei der Registrierung. **Das erste Konto wird Admin** und ist sofort aktiv. Danach ist die Registrierung geschlossen; unter *Einstellungen → Administration* kannst du sie kurz öffnen, z. B. für deine Partnerin.

Die Tabellen werden beim ersten Aufruf automatisch angelegt.

### 5. Täglicher Cronjob (Erinnerungen & Spritmonitor)

Plesk → **Geplante Aufgaben → Aufgabe hinzufügen → URL abrufen**:

```
https://garage.deinedomain.de/cron/run.php?key=DEIN_CRON_KEY
```

Täglich, z. B. 7:00 Uhr. Unter *Einstellungen → Benachrichtigungen* kannst du eine Test-E-Mail schicken.

### 6. Als App aufs Handy

Seite im Browser öffnen → **„Zum Startbildschirm hinzufügen“** (iPhone: Teilen-Menü, Android: Drei-Punkte-Menü).

---

## Spritmonitor

**CSV (sofort nutzbar):** Bei Spritmonitor *Fahrzeug → Export* je eine CSV für Tankungen und Kosten herunterladen und unter *Mehr → Import & Abgleich* hochladen. Mehrfaches Importieren ist unproblematisch – Duplikate werden übersprungen.

**API-Abgleich (automatisch):**

1. Beim Spritmonitor-Support (Kontaktformular) eine **Application-ID** für die API anfragen und in `config.php` bei `spritmonitor_app_id` eintragen.
2. Bei Spritmonitor unter *Mein Konto → Passwort ändern* ein **Token für Apps** erzeugen und in Garage unter *Import & Abgleich* speichern.
3. *Fahrzeuge laden* → Fahrzeug verknüpfen → *Jetzt abgleichen*. Danach läuft es täglich über den Cronjob.

## MySkoda

Škoda bietet keine offizielle Schnittstelle. Der Abgleich nutzt die inoffizielle Open-Source-Bibliothek [myskoda](https://github.com/skodaconnect/myskoda) und läuft kostenlos als GitHub Action (`.github/workflows/skoda-sync.yml`, alle 4 Stunden).

1. In Garage: *Einstellungen → API-Schlüssel erzeugen* und kopieren.
2. Beim Auto die **FIN** eintragen.
3. Im GitHub-Repository diese Secrets anlegen:

| Secret | Inhalt |
|---|---|
| `SKODA_USER` | E-Mail deines MySkoda-Kontos |
| `SKODA_PASSWORD` | Passwort deines MySkoda-Kontos |
| `GARAGE_URL` | `https://garage.deinedomain.de` |
| `GARAGE_TOKEN` | der API-Schlüssel aus Schritt 1 |

4. *Actions → MySkoda-Abgleich → Run workflow* zum ersten Test.

Übernommen werden Kilometerstand und die Fälligkeiten für Inspektion und Ölservice (als Fristen mit „MySkoda“-Kennzeichnung).

Hinweise: Das Repository sollte **privat** sein. GitHub pausiert geplante Workflows nach 60 Tagen ohne Commit – dann unter *Actions* wieder aktivieren. Ändert Škoda seine Schnittstelle, kann der Abgleich zeitweise ausfallen, bis die Bibliothek aktualisiert ist.

---

## API

Alle Aufrufe mit Header `Authorization: Bearer <API-Schlüssel>`.

| Aufruf | Zweck |
|---|---|
| `GET /api.php?action=summary` | Kosten im Jahr, Fahrzeuge mit km & Verbrauch, anstehende Fristen – z. B. fürs Home-Dashboard |
| `GET /api.php?action=vehicles` | Fahrzeuge mit ID und Kilometerstand |
| `POST /api.php?action=odometer` | `{"vehicle_id": 1, "km": 50480}` oder mit `"vin"` |
| `POST /api.php?action=trip` | `{"vehicle_id": 2, "km": 87.4, "note": "Ruhrtal", "external_id": "garmin-123"}` – addiert eine Fahrt (z. B. Fahrrad) |
| `POST /api.php?action=skoda` | wird vom MySkoda-Skript genutzt |

---

## Lokal testen

```bash
cp config.sample.php config.php   # driver auf 'sqlite', base_url auf '' setzen
php -S localhost:8000
```

## Sicherheit

- Passwörter mit `password_hash` (bcrypt/argon), CSRF-Schutz für alle Formulare, Prepared Statements
- Uploads außerhalb des Web-Zugriffs (`data/`), Auslieferung nur an den Besitzer
- „Angemeldet bleiben“ über rotierende, gehashte Token
- API-Schlüssel wird nur gehasht gespeichert
- `config.php`, `data/`, `src/`, `pages/` per `.htaccess` gesperrt
