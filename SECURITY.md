# Security Policy

## Supported Versions

| Version | Supported          |
| ------- | ------------------ |
| 1.0.x   | :white_check_mark: |

Only the latest 1.0.x release receives security fixes.

## Reporting a Vulnerability

If you discover a security vulnerability, please report it responsibly:

**Email:** info@malziland.at

Please do **not** open a public GitHub issue for security vulnerabilities.

## What's in scope

- Authentication and session handling in WordPress admin pages.
- AJAX endpoints exposed under `admin-ajax.php` (20 capability-protected admin actions plus the single public, key-protected `sbu_cron_ping`).
- Passwords at rest (AES-256-CBC with a random IV per encryption operation).
- File-system interactions — the plugin writes to the UpdraftPlus backup directory only.
- Seafile API interactions (HTTP requests to the configured Seafile instance).

## What's not in scope

- Seafile server vulnerabilities — report those to the Seafile project directly.
- UpdraftPlus vulnerabilities — report those to UpdraftPlus directly.
- WordPress core vulnerabilities — report those to the WordPress security team.

## Threat model notes

- **Credential encryption boundary.** The stored Seafile password is encrypted
  with AES-256-CBC using `wp_salt('auth')` as the key. `wp_salt()` derives its
  value from the constants in `wp-config.php`. Anyone who can read
  `wp-config.php` on the WordPress host can therefore also decrypt the stored
  Seafile credentials. This is the same trust boundary WordPress itself uses
  for cookies and logged-in sessions — hardening `wp-config.php` file
  permissions (typically `0440` owned by the webserver user) is the
  operator's responsibility, not the plugin's.
- **Activity log contents.** The activity log (`sbu_activity_log` option) can
  contain the Seafile library ID, folder path, backup filenames (which often
  include the site hostname via UpdraftPlus' default naming), and the user
  e-mail shown during connection tests. Use the *anonymized export* when
  sharing logs for support; raw exports leak the above to whoever receives
  them.
- **Cron-ping key.** The optional external heartbeat endpoint
  (`admin-ajax.php?action=sbu_cron_ping`) accepts the 32-char site-local key
  via HTTP header (`X-SBU-Cron-Key`, recommended) or query string (legacy).
  Query-string transport leaks into reverse-proxy access logs, browser
  history, and shell history — prefer the header form. The key can be
  rotated from Settings → Seafile Backup.

## Response expectations

I aim to respond to security reports within 72 hours and to ship a fix or mitigation within two weeks for confirmed issues. This is a one-maintainer project; timelines may stretch for complex cases, and you'll hear from me if that happens.
