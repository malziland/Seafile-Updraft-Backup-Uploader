# Verifikationsmatrix

Bindeglied zwischen Bootstrap-Nachweisen, Änderungen und Audit. Jede
STANDARD-Anforderung und jede aktive Profilpflicht trägt hier einen
belastbaren Nachweis (Befehl + Ergebnis + Anker). Ausbaustufe: **STANDARD**
(siehe [ADR-0001](adr/ADR-0001-ausbaustufe-profile-toolchain.md)).

Referenz-Stand der letzten Vollverifikation: Commit `16fdc07`,
Toolchain PHP 8.5.8 lokal / CI-Matrix PHP 8.2–8.4, Datum 2026-07-16.

| Anforderung | Evidenz / Befehl | Ergebnis (Anker) |
|---|---|---|
| Reproduzierbarer Build (Zip) | `composer build` → `dist/seafile-updraft-backup-uploader-<version>.zip` | Erfolg; 31 Einträge, Top-Level-Slug-Ordner, nur Runtime-Dateien; `16fdc07` |
| Tests | `composer test` (`./vendor/bin/phpunit`) | **123 Tests, 343 Assertions, OK**; `16fdc07` |
| Statische Analyse | `composer phpstan` (Level 5, phpstan.neon.dist) | **No errors**; `16fdc07` |
| Coding Standards | `composer phpcs` (WordPress, phpcs.xml.dist) | **0 Errors, 0 Warnings**; `16fdc07` |
| Syntax-Lint | `composer lint` | 31 Dateien, keine Syntaxfehler; `16fdc07` |
| Secret-Scan | `gitleaks detect --no-banner --redact` (lokal) + CI-Job `gitleaks` | **no leaks found**, 19 Commits gescannt; `16fdc07` |
| Dependency-Audit | `composer audit --locked` + CI-Job `audit` | **No security vulnerability advisories found**; `16fdc07` |
| Rollback-Probe (Worktree) | `git worktree add /tmp/sbu-rollback v1.0.7 && composer install && phpunit` | v1.0.7 aus sich heraus baubar, **123 Tests OK** (`5572f7e`), Worktree entfernt; durchgeführt 2026-07-16 |
| CI grün | `.github/workflows/ci.yml` — lint (8.2/8.3/8.4), phpcs, phpstan, phpunit (8.2/8.3/8.4), gitleaks, audit | lokal alle Gates grün auf `16fdc07`; CI-Bestätigung nach Push |

## Profilpflichten

| Profil / Pflicht | Nachweis | Status |
|---|---|---|
| **UI** — kritischster Nutzerfluss E2E | Docker-Smoke-Test `scripts/smoke-test/run.sh` fährt WP + Seafile hoch, konfiguriert das Plugin, spielt ein Fixture-Backup ein, lässt die Queue durchlaufen und prüft die Dateien per Seafile-API auf Byte-Gleichheit | vorhanden (Harness im Repo); Lauf erfordert Docker |
| **UI** — Accessibility-Check | Kein axe-core-Tooling. Ersatz: manueller Tastatur-Smoketest, Checkliste in [RUNBOOK.md](RUNBOOK.md) | **Risikoakzeptanz** — Owner malziland, Überprüfung mit v1.1.0 bzw. 2027-01 (ADR-0001 §3) |
| **Distribution an Dritte** — SBOM | Zip enthält ausschließlich Eigencode, keine Runtime-Dependencies; SBOM wäre leer | **Begründete Ausnahme** — Owner malziland, wird Pflicht sobald eine Runtime-Dependency ins Artefakt kommt (ADR-0001 §7) |

## Sicherheits-/Ausnahme-Register

| Ausnahme | Begründung | Owner | Ablauf/Überprüfung |
|---|---|---|---|
| Keine SBOM im Build | Artefakt ohne Runtime-Dependencies | malziland | bei erster Runtime-Dependency |
| Kein automatisierter A11y-Check | Admin-UI hinter Login, kein öffentliches Frontend; manueller Tastatur-Smoketest als Ersatz | malziland | v1.1.0 / 2027-01 |
| Kein Toolchain-Version-File | Kein Versionsmanager im Einsatz; Anker sind composer.json + CI-Matrix | malziland | bei Team-Erweiterung |

## Pflege

Diese Matrix wird bei jeder Änderung nachgezogen, die einen bestehenden
Nachweis ungültig macht oder einen neuen Pflicht-Nachweis erzeugt
(CHANGE-DELIVERY-Regel). Bei Audit-Remediation trägt jede Finding-Behebung
hier ihren Verifikations-Nachweis nach.
