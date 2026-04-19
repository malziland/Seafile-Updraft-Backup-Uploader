# Changelog

Alle relevanten Änderungen an diesem Plugin werden in dieser Datei dokumentiert.

Format nach [Keep a Changelog](https://keepachangelog.com/de/1.1.0/), Versionierung nach [Semantic Versioning](https://semver.org/lang/de/).

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
