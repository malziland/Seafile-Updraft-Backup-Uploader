# Security-Modell

Skizze der Assets, Datenflüsse und Vertrauensgrenzen als Referenz für
Entwicklung und Audit. Meldeweg und Threat-Model-Details: [SECURITY.md](../SECURITY.md).
Tiefenprüfung übernimmt das LANGAUDIT. Stand: v1.0.7, 2026-07-16.

## Assets

| Asset | Ablageort | Schutz |
|---|---|---|
| Seafile-Passwort | `sbu_settings` (wp_options) | AES-256-CBC, zufälliger IV, Key aus `wp_salt('auth')` (`includes/class-sbu-crypto.php`) |
| Seafile-API-Token | Transient `sbu_token` | Kurzlebig; bei Deaktivierung gelöscht |
| Cron-Ping-Secret | Option `sbu_cron_key` | 32 Zeichen, zufällig, rotierbar über die Admin-UI |
| Backup-Dateien | UpdraftPlus-Verzeichnis + Seafile-Library | Enthalten die komplette Site (DB-Dump inkl. PII der Site) |
| Aktivitätsprotokoll | Option `sbu_activity_log` | Kann Host, Library-ID, Pfade, Admin-E-Mail enthalten; anonymisierter Export vorhanden |

## Rollen und Akteure

- **WP-Administrator** (`manage_options`): einzige Rolle mit Zugriff auf
  Einstellungen, Upload, Restore, Log. Es gibt kein feineres Rollenmodell.
- **Unauthentifizierter Externer**: erreicht ausschließlich
  `admin-ajax.php?action=sbu_cron_ping` (Secret-geschützt, `hash_equals()`).
- **Seafile-Server**: selbst gehostetes Ziel; seine API-Antworten werden als
  nicht vertrauenswürdige Eingabe behandelt (Parsing/Validierung im Client).
- **UpdraftPlus**: liefert die Backup-Dateien und die Hook-Punkte.

## Datenflüsse

1. **WP → Seafile** (HTTPS, `sslverify => true`): Auth, Library-Auflösung,
   Chunk-Upload, Range-Download, Directory-Ops (`class-sbu-seafile-api.php`).
2. **WP → WP (Loopback)**: Tick-Anstoß über `admin-ajax.php` auf demselben
   Host; TLS-Verifikation dort bewusst deaktiviert (gleicher Host).
3. **WP → Mail**: Erfolg-/Fehler-Benachrichtigung an die Admin-Adresse via
   `wp_mail` (`class-sbu-mail-notifier.php`). Keine weiteren Dritt-Dienste,
   kein Analytics, kein externes Error-Tracking.

## Vertrauensgrenzen

- **Browser ↔ Admin-AJAX**: alle Admin-Endpunkte verlangen `manage_options`
  + Nonce (`verify_ajax_request()` in `trait-sbu-admin-ajax.php`).
- **Internet ↔ Cron-Ping**: einziger öffentlicher Endpunkt; Secret per
  Header `X-SBU-Cron-Key` (empfohlen) oder Query-String (Legacy, leakt in
  Access-Logs — in SECURITY.md dokumentiert).
- **wp-config.php als Schlüsselgrenze**: Wer `wp-config.php` lesen kann,
  kann die gespeicherten Seafile-Credentials entschlüsseln. Das ist dieselbe
  Grenze, die WordPress für Sessions nutzt; Härtung der Dateirechte ist
  Betreiberpflicht.
- **Pfad-Eingaben**: alle nutzergelieferten Pfadsegmente laufen durch
  `sanitize_path_segment()` (kein `..`, keine absoluten Pfade, keine
  nicht druckbaren Zeichen).

## Wesentliche Missbrauchsfälle und Gegenmaßnahmen

| Missbrauchsfall | Gegenmaßnahme |
|---|---|
| Raten des Cron-Ping-Secrets | 32 Zeichen zufällig, timing-sicherer Vergleich, rotierbar |
| CSRF auf Admin-Aktionen | Nonce + Capability auf allen 20+ Admin-Endpunkten |
| Credential-Diebstahl aus DB-Dump | Ciphertext ohne wp-config-Salts wertlos |
| Log-Weitergabe im Support | anonymisierter Export maskiert Host, Library, Pfade, E-Mail, IPs, Nonces |
| Path Traversal über Ordner-Picker | `sanitize_path_segment()` |
| Worker-Tod / hängende Queue (Betriebs-Integrität) | Queue-Lock atomar (`add_option`), Stale-Lock-Recovery, Crash-Notbremse (Retry → Chunk-Halbierung → Skip) |

## Aufbewahrung und Löschung

- Aktivitätsprotokoll: Zeilen-Cap + konfigurierbare Retention (0 = aus,
  7–365 Tage), täglicher Cron-Prune.
- Backup-Retention auf Seafile: konfigurierbar (z. B. letzte 4 Sets).
- Uninstall (`sbu_uninstall()`): löscht sämtliche Plugin-Optionen,
  Transients und Schlüssel.

## Privacy-Notiz (Datenklasse: Credentials)

Das Plugin verarbeitet keine Endnutzer-PII über Betriebsmetadaten hinaus.
Personenbezug: Admin-E-Mail (Benachrichtigungen, Verbindungs-Test) und
Site-Hostname in Backup-Dateinamen. Empfänger: ausschließlich der selbst
gehostete Seafile-Server und der Mail-Weg der WP-Installation. Die
Backup-Inhalte selbst (DB-Dumps) können PII der Site enthalten — deren
Schutz liegt beim Betreiber (Seafile-Zugriffskontrolle, Verschlüsselung
serverseitig). Keine Rechtsberatung; Implementierungsrisiken prüft das Audit.

## Annahmen

- Der Seafile-Server ist selbst gehostet und dem Betreiber zuzurechnen.
- Die WordPress-Instanz selbst ist nicht kompromittiert; ein Admin-Account
  gilt als vertrauenswürdig (kein Schutzziel „Admin gegen Admin").

Sicherheitsausnahmen jeder Art nur dokumentiert mit Begründung, Owner und
Ablaufdatum — Register: docs/VERIFICATION.md.
