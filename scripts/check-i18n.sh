#!/usr/bin/env bash
# i18n smoke test. Fails if:
#  1. load_plugin_textdomain() uses __FILE__-based path (prone to the
#     "moved to /includes, path still points at /includes/languages" bug).
#  2. .pot file is out of sync with source strings.
#  3. Any wp_send_json_error/_success call passes a bare string literal
#     instead of a translated string (user-facing sentinels 'ok'/'OK'
#     are whitelisted).
#
# Run before every release. Exit non-zero on any finding.
set -euo pipefail

cd "$(dirname "$0")/.."

RED="\033[0;31m"
GREEN="\033[0;32m"
YELLOW="\033[0;33m"
NC="\033[0m"

fail=0

echo "→ i18n check 1/3: load_plugin_textdomain path"
if grep -rn --include='*.php' "load_plugin_textdomain.*__FILE__" includes/ seafile-updraft-backup-uploader.php; then
    echo -e "${RED}FAIL${NC}: load_plugin_textdomain() derives the path from __FILE__. Use SBU_SLUG instead — __FILE__ breaks when the caller moves between /includes and the plugin root."
    fail=1
else
    echo -e "${GREEN}ok${NC}"
fi

echo "→ i18n check 2/3: .pot is in sync with source"
# Regen to a temp file and compare content-only (strip POT-Creation-Date
# so an idempotent regen isn't flagged just because the timestamp moved).
tmp_pot=$(mktemp)
trap 'rm -f "$tmp_pot"' EXIT
cp languages/seafile-updraft-backup-uploader.pot "$tmp_pot.orig"
./scripts/regen-pot.sh > /dev/null
cp languages/seafile-updraft-backup-uploader.pot "$tmp_pot.new"
# Restore the committed .pot so a failed check doesn't leave a dirty tree.
mv "$tmp_pot.orig" languages/seafile-updraft-backup-uploader.pot
strip_dates() {
    grep -vE '^"(POT-Creation-Date|PO-Revision-Date):' "$1"
}
if ! diff -q <(strip_dates "$tmp_pot.new") <(strip_dates languages/seafile-updraft-backup-uploader.pot) > /dev/null; then
    echo -e "${RED}FAIL${NC}: languages/seafile-updraft-backup-uploader.pot is out of sync with source strings."
    echo "       Run ./scripts/regen-pot.sh and commit the result."
    diff <(strip_dates languages/seafile-updraft-backup-uploader.pot) <(strip_dates "$tmp_pot.new") | head -40
    fail=1
else
    echo -e "${GREEN}ok${NC}"
fi

echo "→ i18n check 3/3: unwrapped user-facing strings in wp_send_json_*"
# Whitelist: protocol sentinels 'ok', 'OK' are JS contract strings, not
# user-facing text — they are explicitly allowed as bare literals.
unwrapped=$(grep -rnE "wp_send_json_(error|success)\s*\(\s*['\"][^'\"]+['\"]" includes/ \
    | grep -v "__(\|_e(\|esc_html\|esc_attr\|sprintf" \
    | grep -vE "wp_send_json_(error|success)\s*\(\s*['\"](ok|OK)['\"]" \
    || true)
if [ -n "$unwrapped" ]; then
    echo -e "${RED}FAIL${NC}: bare string literals in JSON responses — wrap in __()."
    echo "$unwrapped"
    fail=1
else
    echo -e "${GREEN}ok${NC}"
fi

echo ""
if [ "$fail" -ne 0 ]; then
    echo -e "${RED}i18n check failed.${NC} Fix the issues above before tagging a release."
    exit 1
fi
echo -e "${GREEN}i18n check passed.${NC}"
