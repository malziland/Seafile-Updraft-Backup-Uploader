# Contributing to Seafile Updraft Backup Uploader

Thanks for considering a contribution. This is a one-maintainer project, so
the contributor's biggest help is keeping changes small, focused and covered
by the existing quality gates — rebasing a sprawling PR is what actually
slows things down.

## How to contribute

1. Fork the repository.
2. Create a feature branch (`git checkout -b feature/my-feature` or
   `fix/short-bug-name`).
3. Make your changes, ideally with a test that fails before and passes
   after. See [Testing](#testing).
4. Run the full quality-gate locally before pushing — see
   [Quality gates](#quality-gates).
5. Commit with a concise message that explains the *why*
   (`git commit -m "Fix: retry counter double-bumps on deadline errors"`).
6. Push and open a Pull Request against `main`. CI will run the same gates
   on PHP 8.2 / 8.3 / 8.4.

## Development setup

Requirements:

- PHP 8.2+ with the `openssl` extension (PHP 8.2, 8.3, 8.4 are the
  supported matrix).
- Composer 2.x.
- A WordPress + UpdraftPlus + Seafile test environment for end-to-end
  verification. A Docker smoke-test harness is maintained separately —
  ask if you need access.

```bash
git clone https://github.com/malziland/Seafile-Updraft-Backup-Uploader.git
cd Seafile-Updraft-Backup-Uploader
composer install        # pulls phpunit 11, phpcs, phpstan, brain/monkey etc.
```

## Quality gates

Every push and pull request must pass three gates. Run them locally before
opening a PR — red CI is a stop-the-line signal.

### PHPUnit

```bash
./vendor/bin/phpunit
```

The suite is PHPUnit 11 with Brain\Monkey for WordPress function mocking.
Tests live in `tests/unit/` and use PHP 8 attributes (`#[CoversClass]` /
`#[CoversMethod]`) — not legacy `@covers` docblocks.

When fixing a bug, add a test that fails against the pre-fix code and passes
with the fix. When adding a feature, cover the contract, not the
implementation — the tests should survive refactors of the code under test.

### PHPCS (WordPress Coding Standards)

```bash
./vendor/bin/phpcs --extensions=php \
    --ignore=vendor,languages,assets,tests,.github,scripts .
```

Reasoned exclusions (short array syntax, Yoda conditions, several
docblock sniffs) live in `phpcs.xml.dist`. Security-relevant sniffs stay
active.

### PHPStan (Level 5)

```bash
./vendor/bin/phpstan analyse --level=5 --memory-limit=1G --no-progress
```

Level 5 with `szepeviktor/phpstan-wordpress` for WordPress function
signatures. If you need to narrow a type that PHPStan can't prove, prefer a
short `@phpstan-*` docblock at the method level over a `@phpstan-ignore-line`.

## Coding standards

- Follow the [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/)
  modulo the relaxations in `phpcs.xml.dist`.
- Text domain for every user-facing string: `seafile-updraft-backup-uploader`.
- Use `__()`, `esc_html__()`, `esc_attr__()` (etc.) — never concatenate
  variables into translatable strings; use `sprintf()` with a
  `/* translators: %s is ... */` comment.
- PHPDoc on public and private methods is encouraged but not mandatory.
  Prefer comments that explain *why*, not *what* — the *what* is visible in
  the code itself.
- When adding a new option, constant or AJAX endpoint, update
  [ARCHITECTURE.md](ARCHITECTURE.md) in the same PR.

## Testing a change end-to-end

Before submitting a PR that touches the upload or restore paths, please
verify manually:

- [ ] Plugin activation / deactivation leaves no orphaned options.
- [ ] Settings save and reload correctly.
- [ ] Connection test succeeds with valid Seafile credentials.
- [ ] Small-file upload works (single chunk).
- [ ] Large-file chunked upload works (file larger than the configured chunk).
- [ ] Pause / Resume preserves the exact byte offset.
- [ ] Retention management deletes old backups on the Seafile side.
- [ ] E-mail notifications are sent when `notify` is set to `error` or
      `always` and fire for the right cases.
- [ ] Dashboard widget displays the latest status.
- [ ] Backup list loads and renders type badges.
- [ ] Full restore from Seafile works (the only restore path; single-file
      downloads were removed in 1.0.6).
- [ ] Delete on Seafile works.
- [ ] Plugin uninstall removes all options.

If your change doesn't touch those paths, say so in the PR description —
please don't paste a blank checklist.

## Security

Do not file security issues as public GitHub issues. See
[SECURITY.md](SECURITY.md) for the responsible-disclosure process.

## Translations

**Source language: German.** All `__()`-wrapped strings in the source are
German; German users need no translation file and fall back to the source
strings directly.

Translation files live in `/languages/`. The template is regenerated from
source whenever user-facing strings change:

```bash
./scripts/regen-pot.sh   # regenerates .pot, merges into any existing .po, compiles .mo
./scripts/check-i18n.sh  # CI-style smoke test; run before releasing
```

To add a new language (e.g. English):

1. Run `./scripts/regen-pot.sh` to make sure the template is current.
2. `cp languages/seafile-updraft-backup-uploader.pot languages/seafile-updraft-backup-uploader-en_US.po`
3. Translate `msgstr ""` entries.
4. Re-run `./scripts/regen-pot.sh` — it compiles `.mo` for any `.po` in
   the folder.
5. Submit a Pull Request.

### i18n rules for contributors

- Every user-facing string must be wrapped in `__()`, `_e()`,
  `esc_html__()`, `esc_html_e()`, `esc_attr__()`, or `esc_attr_e()` with
  the text domain `seafile-updraft-backup-uploader`.
- Variables in messages: use `sprintf( __( '... %s ...',
  'seafile-updraft-backup-uploader' ), $var )` with a
  `/* translators: %s is ... */` comment above.
- Do **not** derive the translations folder from `__FILE__` inside
  `/includes` classes — use `SBU_SLUG . '/languages'`. The legacy
  `dirname( plugin_basename( __FILE__ ) )` pattern silently breaks when the
  caller is not the main plugin file; the i18n smoke test flags any
  regression.
- `wp_send_json_error( 'ok' )` and `'OK'` are whitelisted protocol
  sentinels, not user-facing text. Everything else needs wrapping.

## Release process

Releases are cut by the maintainer after the quality gates pass on `main`.
The full workflow:

1. Update `CHANGELOG.md` with a German entry under a new `## <version>`
   heading. Group bullets by *Neu*, *Verbessert*, *Behoben*, *Breaking*
   where applicable.
2. Update `readme.txt` — bump `Stable tag`, add a matching Upgrade-Notice
   block and a Changelog entry in German.
3. Bump `Version:` in `seafile-updraft-backup-uploader.php` and the `SBU_VER`
   constant.
4. Build the installable ZIP. The plugin is distributed via
   `Plugins → Upload` in WP Admin, which requires the archive to contain
   exactly one top-level folder named like the slug
   (`seafile-updraft-backup-uploader/`). Stage the runtime files into a
   temp directory with `rsync`, excluding dev artefacts, then zip:

   ```bash
   VERSION=1.0.x
   TMP=$(mktemp -d)
   rsync -a \
     --exclude='.git' --exclude='.github' --exclude='.claude' \
     --exclude='vendor' --exclude='tests' --exclude='scripts' \
     --exclude='composer.*' --exclude='phpunit.xml*' \
     --exclude='phpcs.xml*' --exclude='phpstan.neon*' \
     --exclude='*.zip' --exclude='.DS_Store' \
     ./ "$TMP/seafile-updraft-backup-uploader/"
   (cd "$TMP" && zip -rq "seafile-updraft-backup-uploader-${VERSION}.zip" \
       "seafile-updraft-backup-uploader")
   mv "$TMP/seafile-updraft-backup-uploader-${VERSION}.zip" ./
   ```

5. Commit, push, then tag: `git tag v<version> && git push --tags`.
6. Create the GitHub Release (`gh release create v<version>
   seafile-updraft-backup-uploader-<version>.zip`) — the download links
   from `readme.txt` and `README.md` point at the tagged release.

CI blocks merges on a red quality-gate; please do not push a tag until `main`
is green on GitHub.
