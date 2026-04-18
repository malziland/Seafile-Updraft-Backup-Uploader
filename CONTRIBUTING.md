# Contributing to Seafile Updraft Backup Uploader

Thank you for considering contributing to this project!

## How to contribute

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/my-feature`)
3. Make your changes
4. Test thoroughly with a WordPress + UpdraftPlus + Seafile setup
5. Commit your changes (`git commit -m 'Add my feature'`)
6. Push to the branch (`git push origin feature/my-feature`)
7. Open a Pull Request

## Coding Standards

- Follow [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/)
- All user-facing strings must use `__()` with the text domain `seafile-updraft-backup-uploader`
- Add PHPDoc comments to all public and private methods
- Use descriptive variable and method names

## Security

If you discover a security vulnerability, please report it privately via email rather than opening a public issue.

## Translations

**Source language: German.** All `__()`-wrapped strings in the source are German; German users need no translation file and fall back to the source strings directly.

Translation files live in `/languages/`. The template is regenerated from source whenever user-facing strings change:

```bash
./scripts/regen-pot.sh   # regenerates .pot, merges into any existing .po, compiles .mo
./scripts/check-i18n.sh  # CI-style smoke test; run before releasing
```

To add a new language (e.g. English):

1. Run `./scripts/regen-pot.sh` to make sure the template is current.
2. `cp languages/seafile-updraft-backup-uploader.pot languages/seafile-updraft-backup-uploader-en_US.po`
3. Translate `msgstr ""` entries.
4. Re-run `./scripts/regen-pot.sh` — it compiles `.mo` for any `.po` in the folder.
5. Submit a Pull Request.

### i18n rules for contributors

- Every user-facing string must be wrapped in `__()`, `_e()`, `esc_html__()`, `esc_html_e()`, `esc_attr__()`, or `esc_attr_e()` with the text domain `seafile-updraft-backup-uploader`.
- Variables in messages: use `sprintf( __( '... %s ...', 'seafile-updraft-backup-uploader' ), $var )` with a `/* translators: %s is ... */` comment above.
- Do **not** derive the translations folder from `__FILE__` inside `/includes` classes — use `SBU_SLUG . '/languages'`. The legacy `dirname( plugin_basename( __FILE__ ) )` pattern silently breaks when the caller is not the main plugin file; the i18n smoke test flags any regression.
- `wp_send_json_error( 'ok' )` and `'OK'` are whitelisted protocol sentinels, not user-facing text. Everything else needs wrapping.

## Testing

Before submitting a PR, please test:

- [ ] Plugin activation/deactivation
- [ ] Settings save and load correctly
- [ ] Connection test succeeds with valid Seafile credentials
- [ ] Backup upload works (small file)
- [ ] Chunked upload works (file larger than chunk size)
- [ ] Retention management deletes old backups
- [ ] Email notifications are sent
- [ ] Dashboard widget displays correctly
- [ ] Backup list loads and displays correctly
- [ ] Download from Seafile works
- [ ] Delete on Seafile works
- [ ] Plugin uninstall removes all data
