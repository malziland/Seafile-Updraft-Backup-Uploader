#!/usr/bin/env bash
# End-to-end smoke test for seafile-updraft-backup-uploader.
#
# Brings up WordPress + Seafile in Docker, installs & activates the plugin,
# configures it against the live Seafile, drops a fixture UpdraftPlus backup
# into wp-content/updraft/, runs the plugin's queue ticks until completion,
# and then verifies that all files are reachable via Seafile's API with
# byte-sizes matching the fixtures.
#
# Env knobs:
#   CLEANUP=0           keep the stack up after the run (default: 1 = teardown)
#   SKIP_UP=1           assume stack is already up (faster re-runs)
#   SKIP_FIXTURE=1      reuse existing fixture files (faster re-runs)

set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$HERE"

CLEANUP="${CLEANUP:-1}"
SKIP_UP="${SKIP_UP:-0}"
SKIP_FIXTURE="${SKIP_FIXTURE:-0}"

COMPOSE="docker compose"
WP="$COMPOSE exec -T wp-cli wp --allow-root"

G='\033[0;32m'; Y='\033[0;33m'; R='\033[0;31m'; N='\033[0m'
say()  { printf "${G}==>${N} %s\n" "$*"; }
warn() { printf "${Y}--- %s${N}\n" "$*"; }
fail() { printf "${R}FAIL${N} %s\n" "$*" >&2; exit 1; }

teardown() {
  if [ "$CLEANUP" = "1" ]; then
    say "teardown"
    $COMPOSE down -v --remove-orphans >/dev/null 2>&1 || true
  else
    warn "CLEANUP=0 — stack left running. Tear down with: $COMPOSE down -v"
  fi
}
trap teardown EXIT

# --- 0. preflight -----------------------------------------------------------

command -v docker >/dev/null 2>&1 || fail "docker not found"
$COMPOSE version >/dev/null 2>&1 || fail "docker compose plugin not available"

# --- 1. fixture -------------------------------------------------------------

if [ "$SKIP_FIXTURE" != "1" ] || [ ! -d "fixtures/updraft" ]; then
  say "generating fixture backup set"
  bash "helpers/make-fixture-backup.sh"
else
  say "reusing existing fixtures/updraft/"
fi

# --- 2. stack up ------------------------------------------------------------

if [ "$SKIP_UP" != "1" ]; then
  say "docker compose up -d"
  $COMPOSE up -d
fi

say "waiting for WordPress to be healthy"
for i in $(seq 1 60); do
  state=$($COMPOSE ps --format json wordpress 2>/dev/null | grep -o '"Health":"[^"]*"' | head -n1 | cut -d: -f2 | tr -d '"' || true)
  if [ "$state" = "healthy" ]; then break; fi
  sleep 2
done
[ "$state" = "healthy" ] || fail "WordPress did not become healthy"

say "waiting for Seafile to be healthy (can take ~2 min on first boot)"
for i in $(seq 1 90); do
  state=$($COMPOSE ps --format json seafile 2>/dev/null | grep -o '"Health":"[^"]*"' | head -n1 | cut -d: -f2 | tr -d '"' || true)
  if [ "$state" = "healthy" ]; then break; fi
  sleep 3
done
[ "$state" = "healthy" ] || fail "Seafile did not become healthy"

# --- 3. WordPress install + plugin activate ---------------------------------

if ! $WP core is-installed >/dev/null 2>&1; then
  say "installing WordPress"
  $WP core install \
    --url="http://localhost:8080" \
    --title="SBU Smoke Test" \
    --admin_user="admin" \
    --admin_password="admin" \
    --admin_email="admin@example.com" \
    --skip-email
fi

say "activating plugin"
$WP plugin activate seafile-updraft-backup-uploader

# --- 4. Seafile setup: API token + library ---------------------------------

SF_ADMIN="admin@example.com"
SF_PASS="SeafileAdmin123!"
SF_INTERNAL="http://seafile"   # docker-network hostname

say "fetching Seafile admin token"
SF_TOKEN=$($COMPOSE exec -T wordpress curl -sSf \
  -d "username=${SF_ADMIN}&password=${SF_PASS}" \
  "${SF_INTERNAL}/api2/auth-token/" | grep -o '"token":"[^"]*"' | cut -d'"' -f4)
[ -n "$SF_TOKEN" ] || fail "could not obtain Seafile token"

say "creating/finding test library"
LIB_ID=$($COMPOSE exec -T wordpress curl -sSf \
  -H "Authorization: Token $SF_TOKEN" \
  -d "name=smoke-test&desc=smoke-test" \
  "${SF_INTERNAL}/api2/repos/" | grep -o '"repo_id":"[^"]*"' | head -n1 | cut -d'"' -f4)
if [ -z "$LIB_ID" ]; then
  LIB_ID=$($COMPOSE exec -T wordpress curl -sSf \
    -H "Authorization: Token $SF_TOKEN" \
    "${SF_INTERNAL}/api2/repos/" | grep -o '"id":"[a-f0-9-]*"' | head -n1 | cut -d'"' -f4)
fi
[ -n "$LIB_ID" ] || fail "could not get/create Seafile library"
say "library id: $LIB_ID"

# --- 5. configure plugin ----------------------------------------------------

say "configuring plugin (via SBU_Crypto::encrypt)"
$COMPOSE exec -T \
  -e SBU_URL="${SF_INTERNAL}" \
  -e SBU_USER="${SF_ADMIN}" \
  -e SBU_PASS="${SF_PASS}" \
  -e SBU_LIB="${LIB_ID}" \
  -e SBU_FOLDER="/smoketest" \
  wp-cli wp --allow-root eval-file /helpers/configure-plugin.php

# --- 6. drop fixture into wp-content/updraft/ -------------------------------

say "copying fixture backup into wp-content/updraft/"
$COMPOSE exec -T -u 33:33 wp-cli sh -c '
  mkdir -p /var/www/html/wp-content/updraft/
  cp -v /fixtures/updraft/. /var/www/html/wp-content/updraft/ 2>/dev/null || cp -v /fixtures/updraft/* /var/www/html/wp-content/updraft/
  ls -lh /var/www/html/wp-content/updraft/
'

# --- 7. run the upload queue ------------------------------------------------

say "running upload queue (ticks)"
$COMPOSE exec -T \
  -e SBU_MAX_TICKS=40 \
  -e SBU_TICK_SLEEP_MS=200 \
  wp-cli wp --allow-root eval-file /helpers/trigger-upload.php \
  | tee /tmp/sbu-smoke-trigger.log

STATUS=$(grep -o 'FINAL_QUEUE_STATUS=[a-z]*' /tmp/sbu-smoke-trigger.log | head -n1 | cut -d= -f2)
case "$STATUS" in
  done|empty) say "queue finished clean: $STATUS" ;;
  *)          fail "queue did not finish cleanly: status=$STATUS" ;;
esac

# --- 8. verify remote files -------------------------------------------------

say "verifying files on Seafile"

EXPECT_FILES=$(ls -1 fixtures/updraft/ | grep -E '^backup_' || true)
[ -n "$EXPECT_FILES" ] || fail "no backup files in fixture dir"

# find the sub-folder the plugin created (backup_2026-04-17-1200_TestSite_abc123def456)
REMOTE_ROOT=$($COMPOSE exec -T wordpress curl -sSf \
  -H "Authorization: Token $SF_TOKEN" \
  "${SF_INTERNAL}/api2/repos/${LIB_ID}/dir/?p=/smoketest" \
  | tr ',' '\n' | grep -o '"name": *"[^"]*"' | head -n1 | cut -d'"' -f4 || true)
[ -n "$REMOTE_ROOT" ] || fail "no subfolder found under /smoketest on Seafile"
say "remote subfolder: $REMOTE_ROOT"

REMOTE_LIST=$($COMPOSE exec -T wordpress curl -sSf \
  -H "Authorization: Token $SF_TOKEN" \
  "${SF_INTERNAL}/api2/repos/${LIB_ID}/dir/?p=/smoketest/${REMOTE_ROOT}")

missing=0
while IFS= read -r f; do
  local_size=$(stat -f%z "fixtures/updraft/$f" 2>/dev/null || stat -c%s "fixtures/updraft/$f")
  if ! printf '%s' "$REMOTE_LIST" | grep -q "\"name\": *\"$f\""; then
    warn "missing on Seafile: $f"
    missing=$((missing+1))
    continue
  fi
  remote_size=$(printf '%s' "$REMOTE_LIST" \
    | tr '}' '\n' | grep "\"name\": *\"$f\"" \
    | grep -o '"size": *[0-9]*' | head -n1 | grep -o '[0-9]*')
  if [ "$remote_size" != "$local_size" ]; then
    warn "size mismatch for $f: local=$local_size remote=$remote_size"
    missing=$((missing+1))
  else
    say "OK  $f  ($local_size B)"
  fi
done <<< "$EXPECT_FILES"

if [ "$missing" -gt 0 ]; then
  fail "$missing file(s) missing or size-mismatched on Seafile"
fi

printf "${G}========================================${N}\n"
printf "${G}  SMOKE TEST PASSED${N}\n"
printf "${G}========================================${N}\n"
