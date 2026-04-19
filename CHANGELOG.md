# Changelog

Alle relevanten Änderungen an diesem Plugin werden in dieser Datei dokumentiert.

Format nach [Keep a Changelog](https://keepachangelog.com/de/1.1.0/), Versionierung nach [Semantic Versioning](https://semver.org/lang/de/).

## [1.0.6] — 2026-04-19

UI-Aufräumen: Einzeldatei-Download aus der Backup-Liste entfernt. Kein Feature-Verlust, nur weniger Verwirrung.

### Entfernt

- **Einzeldatei-Download-Button im „Dateien anzeigen"-Panel.** Der Knopf hieß „Download", triggerte aber **keinen** Browser-Download: `SBU_Plugin::ajax_download()` lud die Datei serverseitig von Seafile in den lokalen UpdraftPlus-Ordner und schrieb die Erfolgsmeldung in das gemeinsam genutzte `#sr`-Statusfeld. Das Feld liegt zwei Panels höher — bei gescrollter Seite außerhalb des Sichtbereichs, weshalb der Pfad sich wie „nichts passiert" anfühlte. Zusätzlich fragte der vorgelagerte `confirm(f)`-Dialog nur den rohen Dateinamen ab (z. B. `backup_2026-04-17-1630_INTERIORISTA_1d2b1dcea52d-db.gz`) ohne zu erklären, was die Bestätigung auslöst.
- **Rationale für die Entfernung statt Umbau zum Browser-Download:**
  - **Kein Use-Case:** UpdraftPlus erzeugt die Backup-Sets als nummerierte Chunks (`uploads7.zip`, `uploads14.zip`, …) ohne semantische Bedeutung der Chunk-Nummer. Eine einzelne Datei aus dem Set ist für den Nutzer blind — ohne das vollständige Set ist sie nicht restore-fähig.
  - **„Wiederherstellen" deckt den Restore-Fall ab:** Der bestehende Restore-Pfad nutzt bereits die „Teilweise lokal"-Erkennung und zieht genau die fehlenden Dateien nach. Das ist der gezielte Re-Download, den ein Einzeldatei-Button theoretisch bieten könnte.
  - **Seafile-Weboberfläche deckt den Inspektions-Fall ab:** Wer eine einzelne `.gz` außerhalb von WordPress untersuchen will, lädt sie direkt aus der Seafile-Weboberfläche — ein Plugin-Umweg bringt dort keinen Mehrwert.
  - **Jeder Button, der nicht verstanden wird, ist eine Support-Quelle.** Die aktuelle Plugin-Zielgruppe (self-hoster, der UpdraftPlus + Seafile koppelt) klickt ihn in der Praxis versehentlich und wundert sich.

### Aufgeräumt (tote Code-Pfade)

- **`SBU_Plugin::ajax_download()`** — Handler vollständig entfernt (inkl. der `'Einzelne Datei heruntergeladen: …'`- und `'Ungültige Parameter.'`-Strings, die nur hier vorkamen).
- **`'download'` aus der AJAX-Hook-Registrierung in `SBU_Plugin::boot_plugin()`** — damit ist die `wp_ajax_sbu_download`-Route weg. Wer die Route manuell aufruft, bekommt jetzt WordPress' Standard-0-Response.
- **`SBU_Seafile_API::download_file()`** — die nur von `ajax_download()` aufgerufene Methode (Range-Chunk-Download mit Link-Refresh-Retry) ist jetzt tot und wurde entfernt. Sie überschnitt sich funktional mit dem aktiven Restore-Pfad aus `SBU_Seafile_API::download_whole_file_stream()` + `download_chunks_parallel()`, die der Restore-Flow nutzt — die bleiben unverändert.
- **`SBU_Seafile_API::DEFAULT_DOWNLOAD_CHUNK`-Konstante** — nur von `download_file()` referenziert, mit der Methode entfernt. Die im Restore-Flow aktive Standard-Chunk-Größe steckt in `SBU_DOWNLOAD_CHUNK_MB_DEFAULT` (20 MB) und bleibt so wie sie war.
- **`window.sDl`** und **`'download-file'` im Event-Delegation-Map** in `assets/js/admin.js` — entfernt.
- **`<button data-sbu-action="download-file">` im „Dateien anzeigen"-Panel** in `SBU_Plugin::ajax_list()` — entfernt. Das Panel zeigt jetzt nur noch Dateiname und Größe pro Zeile, reine Inhaltsliste ohne Aktionen.

### Geändert

- **Übersetzungsvorlage (`languages/seafile-updraft-backup-uploader.pot`)** — neu generiert. Die Strings des entfernten Pfads sind nicht mehr Teil der Vorlage. Die Regeneration hat zusätzlich die alte Version-Referenz (aus einem früheren Versionszweig) auf den aktuellen Stand gezogen.
- **Dokumentation** — `README.md`, `ARCHITECTURE.md` und `CONTRIBUTING.md` auf den neuen Admin-AJAX-Handler-Stand (22 Admin-Endpunkte + 1 öffentlicher Cron-Endpoint) aktualisiert, „one-file restore" aus der Test-Checkliste gestrichen.

### Tests

**121 Tests / 333 Assertions** — unverändert. PHPCS, PHPStan Level 5 und PHPUnit 11 grün auf PHP 8.2 / 8.3 / 8.4.

## [1.0.5] — 2026-04-19

Breaking-Change-Release: PHP-Mindestanforderung auf 8.2 angehoben. Testsuite auf PHPUnit 11 gehoben und um zwei neue Abdeckungs-Schwerpunkte erweitert. Dokumentation (README, ARCHITECTURE, CONTRIBUTING) auf den aktuellen Code-Stand gezogen.

### Breaking

- **PHP-Mindestanforderung 7.4 → 8.2.** Plugin-Header (`Requires PHP`), `composer.json` (`"php": ">=8.2"`) und `readme.txt` (`Requires PHP`) jetzt konsistent auf 8.2. WordPress blockiert die Aktivierung auf älteren PHP-Versionen mit eigenem Hinweis. Supporte PHP-Matrix: **8.2 / 8.3 / 8.4** (CI-getestet auf allen drei). Hintergrund: PHPUnit 11 verlangt PHP ≥ 8.2, und das bisherige 7.4-Minimum brachte PHP-8-Syntax-Features (readonly, never, match, first-class-callable) sowieso schon außer Reichweite für die getestete Oberfläche.

### Geändert

- **PHPUnit 9.6 → 11.5.** Alle 12 bestehenden Test-Dateien von `@covers`-Docblocks auf PHP-8-Attribute (`#[CoversClass]`, `#[CoversMethod]`) migriert — PHPUnit 11 meldet dafür sonst Deprecations, die sich in einem späteren Major zu Fehlern entwickeln. Null Deprecations in der jetzigen Suite.
- **CI-Matrix** (`.github/workflows/ci.yml`): PHP-Versionen auf 8.2 / 8.3 / 8.4 umgestellt, Composer-Constraint folgt der Matrix, Gitleaks-Job unverändert.
- **PHPStan-Config** (`phpstan.neon.dist`): Projekt-weite `@phpstan-impure`-Annotationen eingepflegt, wo neue Trait-Komposition redundante Reads suggerierte.
- **PHPCS-Config** (`phpcs.xml.dist`): Exklusionen auf die post-Refactor-Verzeichnisstruktur angepasst.

### Hinzugefügt

- **`tests/unit/SeafileApiTest.php`** — 17 HTTP-gemockte Tests für `SBU_Seafile_API`. Deckt Token-Caching (inkl. `force`-Refresh und Auth-Failure-Invalidation), Library-Resolve, Upload-Link-Ausstellung, Download-Link (mit `reuse=1`-Check), Directory-Create/List/Delete. Benutzt einen lokalen `httpResponse()`-Helfer und stubt `wp_remote_*` + Retrieve-Helfer + Transient-Store.
- **`tests/unit/LogSanitizerTest.php`** — 12 Tests für den anonymisierten Log-Export (`ajax_export_log_anon`). Prüft jede Maskierungs-Regel einzeln (Host, UUID, Ordner, E-Mail, IP, Nonce-Suffix, Unknown-E-Mail) plus einen End-zu-End-Leak-Check über einen synthetischen Log mit realistischen PII-Mustern.
- **Neue Screenshots** für `readme.txt` und `README.md`: `assets/screenshot-dashboard.png` (Einstellungsseite inkl. Backup-Browser und Aktivitätsprotokoll, Demo-Daten) und `assets/screenshot-widget.png` (WordPress-Dashboard-Widget mit letztem Backup-Status).

### Dokumentation

- **`README.md`** — PHP-Requirement aktualisiert, Architektur-Tree auf den jetzigen Stand (alle Traits und extrahierten Services sichtbar), Kernkomponenten-Abschnitt pro Klasse / Trait, reproduzierbare Test-Kommandos, ein Block für den Release-Workflow.
- **`ARCHITECTURE.md`** — neue „Module boundaries"-Tabelle (wer wohnt wo und warum), erweiterter „Activity log"-Abschnitt (Retention, `SBU_Activity_Log`-Extraktion, `LogSanitizerTest`), neuer „Notifications"-Abschnitt für `SBU_Mail_Notifier`, neue „Test surface"-Tabelle mit 13 Zeilen (121 Tests / 333 Assertions).
- **`CONTRIBUTING.md`** — vollständig überarbeitet: Dev-Setup, drei Quality-Gates mit lauffähigen Kommandos, vollständiger Release-Workflow inklusive `rsync`+`zip`-Rezept für das WP-Upload-konforme Archiv (keine zusätzlichen Build-Skripte erforderlich), i18n-Regeln.

### Tests

**121 Tests / 333 Assertions** (vorher 92 / 263). PHPCS (WordPress-Standard, Plugin-Source), PHPStan Level 5 und PHPUnit 11 grün auf PHP 8.2 / 8.3 / 8.4.

## [1.0.4] — 2026-04-19

ARCH-001 abgeschlossen: die beiden letzten Monolith-Fragmente sind raus aus der God-Class. Reine Umstrukturierung — Verhalten 1:1, 92 Tests / 263 Assertions grün.

### Refaktoriert

- **ARCH-001 Schritt 4 — `SBU_Admin_Ajax` als Trait** — alle 24 Ajax-Handler (Verbindungstest, Upload-/Restore-Queue-Kontrolle, Backup-Liste, Download-Stream, Log-Export, Settings-Autosave, die beiden extern erreichbaren Cron-Endpoints) leben physisch in `includes/trait-sbu-admin-ajax.php`. Trait-Kompositon zur Compile-Zeit hält die Private-Zugriffe auf `verify_ajax_request`, `get_picker_credentials`, `format_progress`, `sanitize_path_segment` etc. intakt — keine Visibility-Promotion, keine fragile Plugin-Referenz.
- **ARCH-001 Schritt 5 — `SBU_Upload_Flow` + `SBU_Restore_Flow` als Traits** — der komplette Upload-Lebenszyklus (`on_backup_complete`, `create_upload_queue`, `process_queue_tick`, `upload_one_chunk`, `finish_queue`, `verify_backup`, `persist_backup_hashes`, `enforce_retention`, `cleanup_updraft_history`, `find_backup_files`, `extract_backup_nonce`) und der Restore-Lebenszyklus (`verify_restored_file`, `process_restore_tick`) wohnen in `includes/trait-sbu-upload-flow.php` und `includes/trait-sbu-restore-flow.php`. Gleiche Trait-Strategie wie bei Schritt 4. Die gemeinsam genutzten Helfer (`safe_queue_update`, `detect_worker_crash_and_defer`, `maybe_notify_stall`, `get_adaptive_limits`, `tick_budget_exhausted`, `compute_queue_timeout`, `is_aborted`, `log_failed_files`, `get_updraft_dir`, `get_memory_limit`) bleiben in `SBU_Plugin`, weil beide Flows sie brauchen.
- **`SBU_Plugin` jetzt ~1100 Zeilen** — Klasse ist von ursprünglich 4201 Zeilen auf rund 1100 Zeilen geschrumpft (−74 %). Sie ist damit wieder als Koordinations-Klasse lesbar: Settings, Admin-UI, Cron-Auth, Tick-Sizing, Queue-Lifecycle-Helfer, und das Zusammensetzen der drei Traits.

### Geändert

- **`SBU_Queue_Engine::tick_is_gated()` als `@phpstan-impure` markiert** — die Methode liest bei jedem Aufruf frisch aus `SBU_QUEUE` (via `wp_cache_delete`). PHPStan hielt nach der Trait-Kompositon wiederholte Aufrufe für redundant und warf einen `if.alwaysTrue`-Fehler auf der Post-Sleep-Prüfung in `ajax_cron_ping`. Korrekt ist: der Gate-Wert kann sich zwischen zwei Aufrufen ändern (Tick hat einen Backoff gesetzt, Retention-Tick hat ihn gelöscht). Die Annotation dokumentiert das und stellt den Analyzer zufrieden.

### Tests

92 PHPUnit-Tests / 263 Assertions — alle grün. PHPCS (WordPress-Standard, Plugin-Source) und PHPStan (Level 5) sauber. Keine Test-Anpassungen nötig — Traits komponieren zur Compile-Zeit in `SBU_Plugin`, die bestehenden `SBU_Plugin::*`-Referenzen in den Tests funktionieren unverändert.

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
