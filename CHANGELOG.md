# Changelog

Alle relevanten Änderungen an diesem Plugin werden in dieser Datei dokumentiert.

Format nach [Keep a Changelog](https://keepachangelog.com/de/1.1.0/), Versionierung nach [Semantic Versioning](https://semver.org/lang/de/).

## [1.0.3] — 2026-04-19

Zweiter Audit-Durchgang: UI-Feinschliff, Härtung des internen Cron-Pfads, Rausziehen dreier Services aus der God-Class.

### Hinzugefügt

- **Brute-Force-Schutz für den internen Cron-Schlüssel** — fünf ungültige Versuche innerhalb einer Stunde lösen einmalig eine `WARNUNG`-Zeile im Aktivitätsprotokoll aus („Wenn das nicht du warst, Schlüssel rotieren"). Der Zähler läuft als 1-Stunden-Transient und wird bei der Warnung zurückgesetzt, damit echte Angriffs-Wiederholungen nicht stumm hochzählen.
- **Zero-Traffic-Backstop für das Log-Pruning** — zusätzlich zum täglichen WP-Cron räumt die Retention jetzt auch beim ersten `admin_init` pro Admin-Zugriff auf. Idle-Sites ohne Queue-Aktivität bleiben damit innerhalb des Retention-Fensters.

### Geändert

- **Cron-Key im Header statt im POST-Body** — der interne Loopback-Spawn sendet den Schlüssel jetzt über den `X-SBU-Cron-Key`-Header. Er taucht damit nicht mehr in Debug-/Error-Tracker-Dumps auf, die Request-Bodies mitloggen. Rückwärtskompatibel: der Empfänger prüft weiterhin Header, Query-Param und Body in dieser Reihenfolge.
- **Status-Pillen in der Backup-Liste zentriert** — `Lokal vollständig` / `Teilweise lokal` / `Nur remote` und der Verify-Status sitzen jetzt auf einer sauberen Baseline statt treppenartig versetzt. Ursache war ein `align-items:baseline`-Flex-Layout, das bei Mixed-Font-Size-Kindern eine Treppe baut; jetzt `align-items:center` mit konsistenter `line-height`.
- **24 Inline-`onclick`-Handler aus der Backup-Liste entfernt** — die Buttons in `ajax_list` nutzen jetzt ebenfalls `data-sbu-action`-Attribute. Kombiniert mit dem 1.0.2-Cleanup im Admin-Template ist das Plugin damit vollständig inline-JavaScript-frei — strengere Content-Security-Policies durchsetzbar.

### Refaktoriert

- **ARCH-001 Schritt 1 — `SBU_Activity_Log` als eigene Klasse** — Schreiben, Zeilen- und Zeit-basierte Retention, Cron-Scheduling und Admin-Render-Helfer leben in `includes/class-sbu-activity-log.php`. Konstruktor bekommt einen Settings-Provider als `callable` injiziert, damit die Klasse nicht auf `SBU_Plugin` koppelt. 77 Aufrufstellen umgestellt, Verhalten identisch.
- **ARCH-001 Schritt 2 — `SBU_Mail_Notifier` als eigene Klasse** — Admin-E-Mails (Erfolg/Fehler/Stillstand) fliegen jetzt durch `includes/class-sbu-mail-notifier.php`. Gleicher DI-Ansatz via Settings-Provider-Callable.
- **ARCH-001 Schritt 3 — `SBU_Queue_Engine` als eigene Klasse** — die reine Queue-Infrastruktur (atomares Lock, Lock-TTL, Gate-Check, Loopback-Spawn, WP-Cron-Scheduling) wohnt in `includes/class-sbu-queue-engine.php`. Konstruktor bekommt Cron-Key-Provider und Adaptive-Limits-Provider als Callables. Tick-Work, Crash-Detection und Upload/Restore-State bleiben vorerst in `SBU_Plugin`.
- **ARCH-001 Schritte 4 + 5 verschoben auf v1.0.4** — die Ajax-Controller-Extraktion (24 Handler mit tiefer Plugin-Verflechtung) und die Upload-/Restore-Flow-Trennung brauchen einen isolierten Release, damit Regressions-Risiken in produktiven Backup-Pfaden gezielt reviewbar bleiben.

### Sicherheit

- **Cron-Key-Transport gehärtet** — Header-First-Reihenfolge ist jetzt auch im Loopback-Pfad durchgezogen; POST-Body als Transport gilt damit intern als deprecated.
- **Brute-Force-Signal im Protokoll** — ein Angreifer, der mit zufälligen Schlüsseln gegen den `sbu_cron_ping`-Endpoint hämmert, hinterlässt nach fünf Versuchen eine sichtbare `WARNUNG`-Zeile im Aktivitätsprotokoll, statt stumm 403s zu produzieren.

### Tests

92 PHPUnit-Tests / 263 Assertions — alle grün. PHPCS (WordPress-Standard) und PHPStan (Level 5) sauber. `CrashDetectionGateTest` testet `tick_is_gated()` jetzt direkt auf `SBU_Queue_Engine`, die Crash-Detection bleibt auf `SBU_Plugin`.

## [1.0.2] — 2026-04-19

Audit-Umsetzung: Datenschutz, Wartbarkeit, CI-Schärfe.

### Hinzugefügt

- **Zeit-basierte Aufbewahrung für das Aktivitätsprotokoll** (Default 30 Tage, konfigurierbar 7–365 Tage oder 0 = deaktiviert). Alte Einträge werden einmal täglich per Cron und opportunistisch beim Schreiben gelöscht. Das bisherige Zeilen-Limit (500 Zeilen) bleibt als zusätzliche Obergrenze erhalten.
- **Warn-Header im Log-Export** (unmaskierte Variante) weist jetzt unübersehbar darauf hin, dass der Export identifizierende Daten (Host, Bibliothek, Pfade, E-Mail) enthält — für Support-Weitergabe wird „Anonymisiert exportieren" empfohlen.
- **Neue Einstellung „Aktivitätsprotokoll aufbewahren"** auf der Plugin-Seite mit Laien-Erklärung (Standard-Empfehlung 30 Tage, Hinweis auf DSGVO-Datensparsamkeit).
- **5 neue Unit-Tests** für die Retention-Logik (alte Einträge werden verworfen, 0 = unverändert durchreichen, unparsebar formatierte Zeilen werden nie verloren, Panik-Eingaben werden auf 7 Tage hochgeklemmt).

### Geändert

- **Inline-`onclick`-Handler aus dem Admin-Template entfernt** — 9 Buttons nutzen jetzt `data-sbu-action`-Attribute, ein zentraler Event-Delegate in `admin.js` routet auf die Funktionen. Erleichtert künftige Wartung und strengere Content-Security-Policies.
- **CI-Pipeline scharf gestellt** — PHPCS und PHPStan brechen den Build jetzt bei Fehlern ab (vorher mit `|| true` maskiert). PHPStan läuft mit `--memory-limit=2G`, um OOM auf großen Code-Pfaden zu vermeiden.
- **Ein Code-Review-Durchlauf** mit echten Bugfixes statt nur Rauschen: `wp_unslash()` vor `sanitize_path_segment()` in den Ajax-Download-/Delete-Endpoints, gecachte `count()`-Aufrufe in zwei Schleifen, `translators`-Kommentare für zwei komplexe i18n-Strings, Umbenennung kollidierender Parameter.

### Sicherheit

- **Retention-Clamp gegen Panik-Eingaben** — Werte zwischen 1 und 6 Tagen werden automatisch auf 7 angehoben, damit ein Fehlklick im Admin das Protokoll nicht in einem Tick vollständig leert. 0 bleibt als explizite Deaktivierung erlaubt.
- **Datenminimierung im Default-Fall** — Aktivitätsprotokoll-Einträge älter als 30 Tage werden ohne weitere Konfiguration entfernt. Unterstützt die DSGVO-Forderung nach Datensparsamkeit.

### Tests

92 PHPUnit-Tests / 263 Assertions, alle grün. PHPCS (WordPress-Standard) und PHPStan (Level 5) sauber.

## [1.0.1] — 2026-04-18

UI-Politur der Einstellungsseite.

### Geändert

- Integritätsprüfungs-Hinweis aus dem Einstellungsblock in den Erklär-Bereich „So funktioniert das Plugin" verschoben — dort gehört er hin, nicht neben die Speichern-Checkboxen.
- Toolbar im Aktivitätsprotokoll sauber in zwei Gruppen aufgeteilt (Filter links, Exporte + Log leeren rechts). Auf schmalen Bildschirmen (< 600 px) stapeln die Gruppen untereinander statt chaotisch umzubrechen, Buttons teilen sich die Breite gleichmäßig.

## [1.0.0] — 2026-04-18

Erste öffentliche Version.

### Überblick

Das Plugin koppelt UpdraftPlus an einen selbst gehosteten Seafile-Server über die native Seafile-Upload-API — nicht über WebDAV. Das ist notwendig, weil WebDAV auf Seafile keine Chunked Uploads unterstützt: Dateien größer als das Reverse-Proxy-Limit (etwa 100 MB bei Cloudflare Tunnel Free Tier) lassen sich über WebDAV schlicht nicht hochladen.

### Funktionsumfang

- **Chunked Upload** (Default 40 MB pro Stück, konfigurierbar 5–90 MB) — schlägt keine 100-MB-Grenze, jedes übertragene Stück bleibt gesichert, bei Fehler wird nur das fehlgeschlagene Stück wiederholt.
- **Stream-First-Restore** — Downloads nutzen zuerst denselben Pfad wie die Seafile-Web-Oberfläche (ein einzelner HTTP-GET ohne Range-Header). Für Dateien über 500 MB oder wenn der Stream scheitert, fällt das Plugin automatisch auf parallele Range-Chunks zurück.
- **Adaptive Konfiguration ohne Einstellungen** — Tick-Länge, Parallelität und Chunk-Größe werden beim Restore automatisch aus `max_execution_time` und `memory_limit` berechnet. Der Nutzer sieht die gewählten Werte beim Restore-Start im Activity-Log.
- **Exponentielles Backoff mit zwei Kurven** — unterscheidet zwischen leeren Server-Antworten (Backend kalt, 60 s / 300 s / 900 s / 1800 s / 3600 s) und echten Transportfehlern (Netzwerk wackelt, 60 s / 120 s / 240 s / 480 s / …). Harte Obergrenze 1 Stunde.
- **AIMD-Rate-Controller** — Chunk-Größe und Parallelität schrumpfen nach Fehlern und wachsen nach erfolgreichen Batches gestaffelt zurück. Zwei Fehler in Folge drücken den Flow in einen Emergency-Modus (2 MB × 1 Stream).
- **Stillstand-Meldung per Mail ohne Abbruch** — wenn eine Datei 1 h keinen Fortschritt macht, geht eine Info-Mail raus. Folge-Mails alle 4 h. Die Queue wird nicht abgebrochen, sie läuft mit frischen Download-Links weiter, bis sie durch ist.
- **Integritätsprüfung ohne Extra-Bandbreite** — beim Upload wird pro Datei streamend eine SHA1-Prüfsumme berechnet und persistiert. Beim Wiederherstellen wird dieselbe Prüfsumme aus dem Download-Stream gebildet und verglichen. Mismatch ⇒ Datei wird als Fehler markiert.
- **Pause & Resume** — Uploads und Restores lassen sich pausieren und an exakt derselben Byte-Position fortsetzen. Abbrechen bleibt als separate Aktion.
- **Zero-Traffic-Betrieb** — das Plugin läuft vollständig ohne externe Dienste und ohne Besucher auf der Seite. Ein interner WordPress-Loopback zieht jeden Tick nach, ein Shutdown-Handler feuert nach einem Fatal-Error einen Loopback nach, während Backoff-Phasen wartet `ajax_cron_ping()` in-Process statt abzuprallen. WP-Cron ist nur zusätzliches Sicherheitsnetz.
- **Optionaler externer Heartbeat** (für Hosting-Umgebungen, die Loopbacks komplett blockieren) — schlüsselgeschützte `admin-ajax.php?action=sbu_cron_ping&key=…`-URL mit Crontab-Beispiel direkt im Admin.
- **Lokal-Status pro Backup-Set** im Backup-Browser — farbiges Badge „Lokal vollständig / Teilweise lokal / Nur remote", bei vollständig lokalem Set wird der Wiederherstellen-Button durch „In UpdraftPlus öffnen" ersetzt.
- **Erfolgs-Banner nach Restore** — grüner Kasten mit Backup-Set, Zeitpunkt, Dateianzahl, Größe und Dauer, verlinkt direkt in die UpdraftPlus-Oberfläche.
- **Anonymisierter Log-Export** — zusätzlicher Export-Button, der Seafile-Host, Library-ID, Ordnerpfad, Benutzer-E-Mail, IPs und UUIDs maskiert. Gedacht zum Teilen im Support ohne Datenschutz-Bedenken.
- **Kategorisierter Log-Filter im Admin** (Alle / Nur Fehler / Restore-Flow / Nur Debug).
- **Detailliertes Debug-Log** als Setting — schreibt pro Restore-Chunk eine Zeile mit Byte-Range, Dauer, HTTP-Code, cURL-Fehlernummer und Klassifikation. Nur für konkrete Diagnosen einschalten.
- **Dashboard-Widget** mit Status des letzten Backups.
- **E-Mail-Benachrichtigungen** (bei Fehler / immer / nie).
- **Retention-Management auf Seafile** (4 Default, 0 = alle behalten).
- **UpdraftPlus-History-Sync** — beim Löschen lokaler Backups nach Upload werden die UpdraftPlus-History-Einträge ohne zugehörige Dateien entfernt.
- **AES-256-CBC-Passwortverschlüsselung** mit zufälligem IV.
- **Loopback-Gate** (`next_allowed_tick_ts`) — alle Tick-Entry-Points prüfen das Gate und droppen verfrühte Aufrufe, damit Backoffs nicht durch aggressive Loopback-Spawn-Ketten unterlaufen werden.
- **Mehrsprachige Oberfläche** (Deutsch + Englisch, über die WordPress-Locale).

### Getestet gegen

Dogfood-Restore vom 18.04.2026: 70 Dateien (etwa 3,4 GB) in 48 Minuten über einen Cloudflare Tunnel Free Tier. `uploads.zip` (194 MB) in 55 Sekunden über den Stream-First-Pfad.

### Tests

87 PHPUnit-Tests / 257 Assertions, alle grün. Getestet werden u. a.:
- die Backoff-Kurven in `compute_retry_delay()`
- die adaptive Limits-Heuristik gegen 10 Server-Profile
- der AIMD-Rate-Controller (Staffel-Erholung aus Emergency)
- Chunk-Fehlerklassifikation über alle sieben Kategorien
- Pause/Resume-Roundtrip mit Byte-Offset-Erhalt
- Legacy-IV-Migration in `SBU_Crypto`

### Anforderungen

- WordPress 6.0+
- PHP 7.4+
- UpdraftPlus (Free oder Premium)
- Ein Seafile-Server (self-hosted oder Cloud)

[1.0.0]: https://github.com/malziland/seafile-updraft-backup-uploader/releases/tag/v1.0.0
