# Verifikationsmatrix

Bindeglied zwischen Bootstrap-Nachweisen, Änderungen und Audit. Jede
STANDARD-Anforderung und jede aktive Profilpflicht trägt hier einen
belastbaren Nachweis (Befehl + Ergebnis + Anker). Ausbaustufe: **STANDARD**
(siehe [ADR-0001](adr/ADR-0001-ausbaustufe-profile-toolchain.md)).

Referenz-Stand der letzten Vollverifikation: Commit `HEAD` (Audit-Remediation
+ Dep-Update), Toolchain PHP 8.5.8 lokal / CI-Matrix PHP 8.2–8.4,
Datum 2026-07-16.

| Anforderung | Evidenz / Befehl | Ergebnis (Anker) |
|---|---|---|
| Reproduzierbarer Build (Zip) | `composer build` → `dist/seafile-updraft-backup-uploader-<version>.zip` | Erfolg; 31 Einträge, Top-Level-Slug-Ordner, nur Runtime-Dateien |
| Tests | `composer test` (`./vendor/bin/phpunit`) | **140 Tests, 389 Assertions, OK** |
| Statische Analyse | `composer phpstan` (Level 5, phpstan.neon.dist) | **No errors** (PHPStan 2.2.5) |
| Coding Standards | `composer phpcs` (WordPress, phpcs.xml.dist) | **0 Errors, 0 Warnings** (WPCS 3.4.0) |
| Syntax-Lint | `composer lint` | keine Syntaxfehler |
| Secret-Scan | `gitleaks detect --no-banner --redact` (lokal) + CI-Job `gitleaks` | **no leaks found** |
| Dependency-Audit | `composer audit --locked` + CI-Job `audit` | **No security vulnerability advisories found** |
| Rollback-Probe (Worktree) | `git worktree add /tmp/sbu-rollback v1.0.7 && composer install && phpunit` | v1.0.7 aus sich heraus baubar, **123 Tests OK** (`5572f7e`), Worktree entfernt; durchgeführt 2026-07-16 |
| CI grün | `.github/workflows/ci.yml` — lint (8.2/8.3/8.4), phpcs, phpstan, phpunit (8.2/8.3/8.4), gitleaks, audit | lokal alle Gates grün; CI-Bestätigung nach Push |

## Audit-Remediation (KURZAUDIT 2026.11)

Alle sieben Findings des Abnahme-Kurzaudits behoben; Nachweise:

| Finding | Fix-Nachweis |
|---|---|
| BUG-01 (Restore-Korruption bei HTTP-200) | `RestoreOkPrefixTest` (5 Fälle); Re-Audit deckte einen zweiten, im ersten Anlauf übersehenen Prefix-Block im Retry-Pfad auf — in v1.0.9 ebenfalls auf `ok_prefix()` umgestellt, Duplizierung beseitigt |
| BUG-02 (Pause-Verlust bei Terminal-Write) | `CrashDetectionGateTest::test_crash_recovery_preserves_concurrent_pause` + `…skip…` |
| SEC-01 (DOM-XSS) | Picker/Boxen über `textContent`; `node --check` grün |
| SEC-02 (Nonce-Refresh) | `verify_ajax_request()` im Handler; JS sendet Nonce über `P()` |
| OPS-01 (Upload-Shutdown-Netz) | `register_shutdown_function` symmetrisch zum Restore-Flow |
| PRIV-01 (IPv6-Maskierung) | `LogSanitizerTest` +2 (IPv6 + Zeitstempel-Schutz) |
| DOC-01 (Doku-Drift) | ARCHITECTURE.md/SECURITY.md korrigiert |

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
| PHPUnit auf 11.x, php_codesniffer auf 3.x gepinnt (keine Major-Updates) | PHPUnit 13 zieht die PHP-8.4-Toolchain nach und bräche die PHP-8.2-Testabdeckung; php_codesniffer 4 wird von wpcs 3.4.0 / phpcompatibility noch nicht unterstützt | malziland | sobald WPCS 4 erscheint bzw. die PHP-Mindestversion angehoben wird |
| ~~Queue-Lock ohne Ownership-Token (OPS-01)~~ | **Behoben (v1.0.10).** `acquire_lock()` schreibt einen Per-Acquire-Token, `release_lock()` löscht nur das Lock mit eigenem Token; `force_release_lock()` für den bewussten Abbruch. `LockOwnershipTest` (8 Fälle) deckt das Race ab. | — | erledigt |

## Externe Kontrollen (Plattform, gesetzt 2026-07-16)

Verifiziert per `gh api` (Read-back):

| Kontrolle | Status |
|---|---|
| Branch Protection auf `main` | aktiv — die 10 CI-Checks als Required Status Checks; Force-Push und Löschung gesperrt; `enforce_admins: false` (Solo-Maintainer behält direkten Push) |
| Secret Scanning (Plattform) | enabled (zusätzlich zum Gitleaks-CI-Job) |
| Push Protection | enabled |
| Dependabot Vulnerability Alerts | enabled |
| Dependabot Security Updates (Auto-Fix-PRs) | enabled |
| 2FA (Account `malziland`) | enabled (Authenticator-App), vom Betreiber per Screenshot bestätigt |

Damit sind die produktionskritischen externen Kontrollen verifiziert.
**PRODUCTION_READY: CONDITIONAL** — READY bis auf das dokumentierte
OPS-01-Lock-Ownership-Restrisiko (kein Blocker, siehe Ausnahme-Register).

## Pflege

Diese Matrix wird bei jeder Änderung nachgezogen, die einen bestehenden
Nachweis ungültig macht oder einen neuen Pflicht-Nachweis erzeugt
(CHANGE-DELIVERY-Regel). Bei Audit-Remediation trägt jede Finding-Behebung
hier ihren Verifikations-Nachweis nach.
