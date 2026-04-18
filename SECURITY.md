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

## Response expectations

I aim to respond to security reports within 72 hours and to ship a fix or mitigation within two weeks for confirmed issues. This is a one-maintainer project; timelines may stretch for complex cases, and you'll hear from me if that happens.
