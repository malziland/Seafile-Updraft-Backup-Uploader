#!/usr/bin/env bash
# Generate a fake UpdraftPlus backup set for the smoke test.
# Three files chosen so the 50 MB plugins.zip triggers chunked upload
# (default chunk = 40 MB -> 2 chunks), while the others exercise the
# small-file code path.
#
# Output: scripts/smoke-test/fixtures/updraft/

set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OUT="$HERE/../fixtures/updraft"

mkdir -p "$OUT"

STAMP="2026-04-17-1200"
SITE="TestSite"
HASH="abc123def456"
BASE="backup_${STAMP}_${SITE}_${HASH}"

# 1) 5 MB pseudo-DB dump (gzipped random-ish bytes so SHA1 differs each run)
dd if=/dev/urandom of="$OUT/${BASE}-db.gz"      bs=1M count=5   status=none

# 2) 50 MB "plugins" zip — forces 2 chunks with default 40 MB chunk size
dd if=/dev/urandom of="$OUT/${BASE}-plugins.zip" bs=1M count=50  status=none

# 3) 500 KB themes zip — small-file path
dd if=/dev/urandom of="$OUT/${BASE}-themes.zip"  bs=1K count=500 status=none

# UpdraftPlus history file (not consumed by plugin but makes the dir realistic)
cat > "$OUT/log.${HASH}.txt" <<EOF
Smoke-test fixture — generated $(date -u +%FT%TZ)
EOF

echo "fixture written to: $OUT"
ls -lh "$OUT"
