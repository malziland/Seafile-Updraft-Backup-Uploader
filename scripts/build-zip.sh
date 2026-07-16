#!/usr/bin/env bash
# Build the WP-installable release zip.
#
# WordPress requires the plugin files to live inside a top-level folder named
# after the plugin slug — a zip with files at its root is rejected by the
# Plugins → Upload installer. We therefore stage the shippable files in a
# temp dir and zip that folder.
#
# Shipped: main plugin file, readme.txt, LICENSE, the markdown docs, and the
# runtime directories (includes/, views/, assets/, languages/). Dev-only
# artifacts (tests/, scripts/, docs/, composer files, CI config, dotfiles)
# stay out of the artifact.
#
# Output: dist/seafile-updraft-backup-uploader-<version>.zip
# The version is read from the SBU_VER constant — single source of truth.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="seafile-updraft-backup-uploader"

VERSION="$(sed -nE "s/^define\( 'SBU_VER', '([0-9.]+)' \);$/\1/p" "$ROOT/$SLUG.php")"
if [ -z "$VERSION" ]; then
  echo "FEHLER: SBU_VER nicht aus $SLUG.php lesbar." >&2
  exit 1
fi

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$STAGE/$SLUG"

cp "$ROOT/$SLUG.php" \
   "$ROOT/readme.txt" \
   "$ROOT/LICENSE" \
   "$ROOT/README.md" \
   "$ROOT/CHANGELOG.md" \
   "$ROOT/CONTRIBUTING.md" \
   "$ROOT/SECURITY.md" \
   "$ROOT/ARCHITECTURE.md" \
   "$STAGE/$SLUG/"

rsync -a --exclude='.DS_Store' \
  "$ROOT/includes" "$ROOT/views" "$ROOT/assets" "$ROOT/languages" \
  "$STAGE/$SLUG/"

mkdir -p "$ROOT/dist"
OUT="$ROOT/dist/${SLUG}-${VERSION}.zip"
# Release artifacts are immutable: never silently overwrite an existing zip.
# Rebuilding the same version needs an explicit FORCE=1.
if [ -e "$OUT" ] && [ "${FORCE:-0}" != "1" ]; then
  echo "FEHLER: $OUT existiert bereits. Version bumpen oder bewusst mit FORCE=1 überschreiben." >&2
  exit 1
fi
( cd "$STAGE" && zip -rq "$OUT" "$SLUG" )

echo "Gebaut: $OUT"
unzip -l "$OUT" | tail -2
