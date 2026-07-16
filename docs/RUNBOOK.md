# RUNBOOK

Release-, Rollback- und Betriebspfade. Stand: v1.0.7, 2026-07-16.

## Release-Prozess

Voraussetzung: CI grün auf `main`, CHANGELOG-Abschnitt für die neue Version
geschrieben (Deutsch), Freigabe des Betreibers liegt vor.

1. Version bumpen: Plugin-Header + `SBU_VER` in
   `seafile-updraft-backup-uploader.php`, `Stable tag` in `readme.txt`.
2. CHANGELOG.md und readme.txt-Changelog ergänzen (Deutsch).
3. Qualitäts-Gates lokal: `composer test`, `composer phpcs`,
   `composer phpstan`.
4. Zip bauen: `composer build` → `dist/seafile-updraft-backup-uploader_<version>.zip`
   (Top-Level-Ordner im Zip ist der Plugin-Slug, sonst lehnt WordPress ab).
5. Commit `vX.Y.Z — <Kurzbeschreibung>`, Push nach Freigabe.
6. Annotierten Tag setzen: `git tag -a vX.Y.Z -m "vX.Y.Z"` und pushen.
7. GitHub-Release anlegen mit dem Zip als Asset, Release-Notes auf Deutsch.
8. Stichprobe: Zip aus dem Release herunterladen und via WP-Admin →
   Plugins → Hochladen auf einer Testinstanz installieren.

Kein Schritt 5–7 ohne ausdrückliche Freigabe.

## Rollback

### Installierte Site auf vorherige Version zurücksetzen

1. Zip der Vorversion aus den GitHub-Releases laden.
2. WP-Admin → Plugins → Installieren → Plugin hochladen → Zip wählen →
   „Aktuelle ersetzen". Einstellungen und Queue-Optionen bleiben erhalten
   (Optionen werden bei Updates nicht gelöscht, nur bei Uninstall).
3. Falls eine laufende Queue klemmt: Upload abbrechen (Button in der
   Admin-UI) und neu starten.

Die Options-Formate sind bisher abwärtskompatibel; sollte eine künftige
Version ein Options-Format ändern, gilt Expand-Contract (additive Änderung
zuerst, destruktive erst wenn keine unterstützte Version sie mehr liest)
und dieser Abschnitt wird um die betroffene Version ergänzt.

### Repo-Stand reproduzieren (Rollback-Probe)

Jeder getaggte Stand ist aus sich heraus baubar. Probe-Weg:

```bash
git worktree add /tmp/sbu-rollback vX.Y.Z
cd /tmp/sbu-rollback
composer install
./vendor/bin/phpunit
cd - && git worktree remove /tmp/sbu-rollback
```

Ergebnis der zuletzt durchgeführten Probe: siehe docs/VERIFICATION.md.

## Betrieb

### Wie die Queue vorankommt (Zero-Traffic-Anforderung)

Die Queue muss ohne Besucher, ohne verlässlichen WP-Cron und ohne externe
Dienste durchlaufen. Vier Tick-Eingänge (Details: ARCHITECTURE.md):

| Eingang | Trigger |
|---|---|
| `cron_process_queue` | WP-Cron-Event |
| `ajax_cron_ping` | externer Ping mit Site-Secret (für kaputten WP-Cron) |
| `ajax_kick` | Admin-UI-Poll, solange die Seite offen ist |
| `check_stalled_queue` | `admin_init`-Recovery, wenn > 90 s nichts passiert |

### Störung: Upload hängt

1. Admin → Seafile Backup → **Aktivitätsprotokoll** lesen (erste
   Debugging-Quelle; `error_log` zweitrangig).
2. Typische Muster:
   - `WARNUNG: Worker … abgestürzt` mehrfach am gleichen Offset → die
     Crash-Notbremse halbiert die Chunk-Größe selbständig; ab 5 Fehlversuchen
     bzw. am 4-MiB-Floor wird die Datei übersprungen (FEHLER-Eintrag).
   - Queue steht, kein Log-Fortschritt → Stale-Lock-Recovery greift nach
     Lock-TTL + 30 s automatisch; sonst Pause/Resume über die UI.
3. Letzter Ausweg: Upload abbrechen und neu starten — bereits hochgeladene
   Dateien werden per Duplikat-Erkennung übersprungen.

### Störung: Restore hängt

Gleiches Muster wie Upload (dieselbe State-Machine, Status `restoring`).
Fixe Obergrenze beachten: Queue-Timeout ist größenbasiert
(`compute_queue_timeout()`), nicht pauschal 12 h.

## Manueller Tastatur-Smoketest (UI-Profilpflicht, je Release)

Vor jedem Release einmal durchgehen, Ergebnis formlos im Release-Commit
oder in docs/VERIFICATION.md festhalten:

- [ ] Einstellungen → Seafile Backup nur mit Tab/Shift-Tab durchlaufbar,
      Fokus-Reihenfolge folgt der visuellen Reihenfolge.
- [ ] Fokus-Indikator auf allen Buttons/Feldern sichtbar.
- [ ] „Laden"-, „Speichern"-, Upload-/Restore-Buttons per Enter auslösbar.
- [ ] Statusmeldungen (Erfolg/Fehler) erscheinen als Text, nicht nur als Farbe.
- [ ] Admin-Seite bei < 600 px Breite ohne chaotische Umbrüche (Flex-Gruppen).
