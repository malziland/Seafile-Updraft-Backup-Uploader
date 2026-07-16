# ADR-0001: Ausbaustufe, Profile und Toolchain-Entscheidungen

- **Status:** Akzeptiert
- **Datum:** 2026-07-16
- **Kontext-Version:** Plugin v1.0.7

## Kontext

Das Projekt existiert seit v1.0.0 (April 2026) und wird nachträglich auf den
Familien-Standard (PROJEKTSTART / CHANGE DELIVERY / KURZAUDIT / LANGAUDIT)
nachgezogen. Toolchain, CI, Tests und Release-Praxis existierten bereits;
dieser ADR hält die bisher impliziten Entscheidungen formell fest und ergänzt
die fehlenden.

## Entscheidungen

### 1. Ausbaustufe: STANDARD

Das Plugin ist produktiv im Einsatz, wird als Zip an Dritte ausgeliefert
(GitHub-Releases) und verarbeitet die Datenklasse **Credentials/Tokens**
(Seafile-Zugangsdaten). MINIMAL ist damit ausgeschlossen. ENTERPRISE
(signierte Tags, Build-Provenance, CODEOWNERS) wäre für ein
Ein-Personen-OSS-Projekt Over-Engineering ohne realen Sicherheitsgewinn.

### 2. Aktive Profile

- **UI**: Das Plugin hat eine Admin-Oberfläche (Einstellungsseite,
  Backup-Browser, Dashboard-Widget). Sie liegt vollständig hinter dem
  WordPress-Login (`manage_options`).
- **Artefakt-Distribution an Dritte**: WP-installierbare Zips über
  GitHub-Releases.
- **Kein SERVICE_API**: Das Plugin betreibt keinen eigenen Netzdienst. Der
  einzige öffentliche Endpunkt (`sbu_cron_ping`, Secret-geschützt) ist im
  Security-Modell (docs/SECURITY-MODEL.md) als Vertrauensgrenze erfasst.

### 3. UI-Profilpflicht: pragmatische Erfüllung (Risikoakzeptanz)

Der kritischste Nutzerfluss ist der **Backup-Upload**; er ist durch den
Docker-Smoke-Test (`scripts/smoke-test/run.sh`) End-to-End abgedeckt.
Auf ein automatisiertes Accessibility-Tooling (axe-core o. Ä.) wird
verzichtet: Die UI ist eine reine Admin-Seite hinter Login, kein
öffentliches Frontend. Ersatz: dokumentierter manueller Tastatur-Smoketest
in docs/RUNBOOK.md.

- **Owner der Ausnahme:** malziland
- **Überprüfung spätestens:** mit v1.1.0, sonst 2027-01

### 4. Versionierung und Sprache

- **SemVer** (`vMAJOR.MINOR.PATCH`), annotierte Git-Tags je Release.
- CHANGELOG.md, readme.txt-Changelog und GitHub-Release-Notes: **Deutsch**.
- Code, Bezeichner und Code-Kommentare: **Englisch**; UI-Strings: Deutsch
  (übersetzbar via Text-Domain).
- Betriebs- und Entscheidungs-Doku unter docs/: Deutsch (Zielleser ist der
  Betreiber); bestehende Contributor-Doku (ARCHITECTURE.md, CONTRIBUTING.md,
  SECURITY.md) bleibt Englisch.

### 5. Toolchain-Pinning ohne Version-Datei

Verbindlicher Kompatibilitätsanker ist `composer.json` (`php >= 8.2`) plus
die CI-Matrix **PHP 8.2 / 8.3 / 8.4**. Eine `.tool-versions`-/mise-Datei wird
nicht angelegt: Auf der Entwicklungsmaschine ist kein Versionsmanager im
Einsatz, eine solche Datei wäre wirkungsloses Inventar. Lokale Entwicklung
auf neuerem PHP (aktuell 8.5) ist zulässig, solange die CI-Matrix grün ist.

### 6. Keine Devcontainer-Kapselung

Solo-Projekt ohne lokale Dienste im Normalbetrieb (der Docker-Smoke-Test ist
optional und selbst gekapselt). Toolchain-Pinning per Composer genügt.

### 7. SBOM: begründete Ausnahme statt Tooling

Der Familien-Standard verlangt eine SBOM, sobald Artefakte an Dritte gehen.
Das ausgelieferte Zip enthält **ausschließlich Eigencode** — keine
Runtime-Dependencies, kein vendor/-Verzeichnis (`composer.lock` pinnt nur
Dev-Werkzeuge, die nie ins Artefakt gelangen). Eine generierte SBOM würde
nur das Plugin selbst listen. Stattdessen: diese dokumentierte Ausnahme,
geführt in docs/VERIFICATION.md.

- **Owner der Ausnahme:** malziland
- **Überprüfung:** sobald eine erste Runtime-Dependency ins Artefakt kommt,
  wird eine SBOM (CycloneDX) im Build-Skript verpflichtend.

## Konsequenzen

- Audits (KURZAUDIT/LANGAUDIT 2026.11) finden die Nachweise in
  docs/VERIFICATION.md; Ausnahmen sind hier begründet und befristet.
- Änderungen laufen künftig nach CHANGE DELIVERY (Modus benennen, kleinster
  Schnitt, Beweis-Befehle, Doku-Folgepflege).
- Wer Architektur- oder Styling-Paradigmen ändern will, schreibt zuerst
  einen neuen ADR.
