#!/usr/bin/env bash
# Regenerate the translation template (.pot) and merge into all existing
# .po translations. Run this whenever user-facing strings change.
#
#   ./scripts/regen-pot.sh
#
# Requires: gettext tools (xgettext, msgmerge, msgfmt).
#   macOS:  brew install gettext
#   Debian: apt install gettext
set -euo pipefail

cd "$(dirname "$0")/.."

VERSION=$(grep -E "^define\( 'SBU_VER'" seafile-updraft-backup-uploader.php | sed -E "s/.*'([0-9.]+)'.*/\1/")
POT="languages/seafile-updraft-backup-uploader.pot"

echo "→ extracting strings (version $VERSION)"
xgettext \
    --language=PHP \
    --from-code=UTF-8 \
    --copyright-holder="malziland e.U." \
    --package-name="Seafile Updraft Backup Uploader" \
    --package-version="$VERSION" \
    --msgid-bugs-address="info@malziland.at" \
    --keyword=__:1 \
    --keyword=_e:1 \
    --keyword=esc_html__:1 \
    --keyword=esc_html_e:1 \
    --keyword=esc_attr__:1 \
    --keyword=esc_attr_e:1 \
    --keyword=_n:1,2 \
    --keyword=_x:1,2c \
    --keyword=_ex:1,2c \
    --keyword=_nx:1,2,4c \
    --output="$POT" \
    seafile-updraft-backup-uploader.php \
    includes/class-sbu-crypto.php \
    includes/class-sbu-plugin.php \
    includes/class-sbu-seafile-api.php \
    views/admin-page.php

# Fix generic xgettext header so the template looks like a WordPress .pot.
# Critically: do NOT stamp PO-Revision-Date with the current time — that
# would make every regen produce a new diff and break the idempotency check
# in scripts/check-i18n.sh. POT-Creation-Date is enough of a freshness
# marker, and xgettext fills it in automatically.
sed -i.bak -E \
    -e "s/^# SOME DESCRIPTIVE TITLE\.$/# Seafile Updraft Backup Uploader - Translation Template/" \
    -e "s/^# Copyright \(C\) YEAR/# Copyright (C) $(date +%Y)/" \
    -e 's/^# FIRST AUTHOR.*$/# Translators work on this file - see CONTRIBUTING.md./' \
    -e "s/^#, fuzzy$//" \
    -e 's/^"Last-Translator: .*$/"Last-Translator: malziland <info@malziland.at>\\n"/' \
    -e 's/^"Language-Team: .*$/"Language-Team: malziland <info@malziland.at>\\n"/' \
    "$POT"
rm -f "$POT.bak"

echo "→ merging translations"
for po in languages/*.po; do
    [ -e "$po" ] || continue
    echo "  - $po"
    msgmerge --quiet --update --backup=none "$po" "$POT"
done

echo "→ compiling .mo files"
for po in languages/*.po; do
    [ -e "$po" ] || continue
    mo="${po%.po}.mo"
    msgfmt --output-file="$mo" "$po"
    echo "  - $mo"
done

echo ""
echo "✓ Done. Review the diff with: git diff languages/"
