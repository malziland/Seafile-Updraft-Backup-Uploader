# AGENTS.md

Projektspezifische Regeln für Coding-Agenten. Einzige Quelle — Adapterdateien
(CLAUDE.md) verweisen nur hierher.

## Befehle

```bash
composer setup      # Dependencies installieren (= composer install)
composer lint       # PHP-Syntaxcheck aller Dateien
composer phpcs      # WordPress Coding Standards
composer phpstan    # statische Analyse (Level 5)
composer test       # PHPUnit-Unit-Suite (tests/unit/)
composer build      # WP-installierbares Zip nach dist/
bash scripts/smoke-test/run.sh   # optional: E2E gegen Docker (WP + Seafile)
bash scripts/regen-pot.sh        # i18n: POT-Template neu erzeugen
bash scripts/check-i18n.sh       # i18n: Übersetzungs-Vollständigkeit prüfen
```

`composer build` weigert sich, ein bestehendes Zip zu überschreiben
(Release-Artefakte sind unveränderlich); bewusstes Neubauen nur mit `FORCE=1`.

## Sprach- und Commit-Konventionen

- Chat/Statusberichte, CHANGELOG.md, readme.txt-Changelog und
  GitHub-Release-Notes: **Deutsch**.
- Code, Bezeichner, Code-Kommentare: **Englisch**. UI-Strings: Deutsch, immer
  über die Text-Domain `seafile-updraft-backup-uploader`.
- Commits: Deutsch mit Conventional-Prefix (`feat:`, `fix:`, `chore:`,
  `docs:`, `test:`, `refactor:`, `ci:`); Release-Commits als
  `vX.Y.Z — <Kurzbeschreibung>`.
- Version-Bump immer dreifach synchron: Plugin-Header, `SBU_VER`,
  `readme.txt` Stable tag.
- Release ausschließlich nach docs/RUNBOOK.md; **kein Push, Tag oder Release
  ohne ausdrückliche Freigabe des Betreibers.**

## Architektur-Leitplanken

- Mid-Tick-Queue-Writes NUR über `safe_queue_update()` — nie direkt
  `update_option( SBU_QUEUE, ... )` im Tick-Pfad (Terminal-Status-Schutz,
  siehe ARCHITECTURE.md).
- Jeder neue Admin-AJAX-Handler läuft durch `verify_ajax_request()`
  (Nonce + `manage_options`). Es gibt genau einen öffentlichen Endpunkt
  (`sbu_cron_ping`); ein zweiter braucht einen ADR.
- Zero-Traffic-Anforderung: Die Queue muss ohne Besucher, ohne verlässlichen
  WP-Cron und ohne externe Dienste durchlaufen. Änderungen gegen die vier
  Tick-Eingänge prüfen (ARCHITECTURE.md → "Tick entry points").
- Aktivitätsprotokoll ist die erste Debugging-Quelle: bedeutsame Ereignisse
  loggen — aber niemals Passwort, API-Token oder Cron-Key.
- Admin-UI: unter 600 px sauber layouten (Button-Reihen als Flex-Gruppen);
  lange Erklärtexte in `.sbu-info`-Sektionen, Hints an Settings max. 1–2
  Sätze mit direktem Bezug zur Eingabe.
- Die Screenshots in `assets/` sind HTML/CSS-Mockups, keine echten Captures.
  Bei UI-Änderungen den Betreiber um echte Captures bitten, nicht still
  regenerieren.

## Doku-Folgepflege (im selben Change)

- `docs/VERIFICATION.md`: wenn ein Nachweis ungültig wird oder ein neuer
  Pflicht-Nachweis entsteht.
- `docs/FLAGS.md`: bei neuen/geänderten/entfernten Flags.
- `docs/RUNBOOK.md`: bei Betriebs-, Release- oder Rollback-Folgen.
- `docs/adr/`: bei Architektur-Entscheidungen oder Abweichungen von ADRs.
- `ARCHITECTURE.md` beschreibt das Soll der Queue-Mechanik — bei Änderungen
  an Konstanten oder Abläufen synchron halten (es gab bereits Drift).
