# Architecture

This document describes how the upload engine is wired: the queue state machine,
the locking model, backoff and retry, and which entry points drive work
forward. It's aimed at contributors — users don't need to read this.

## At a glance

```
seafile-updraft-backup-uploader.php   bootstrap: constants, autoload, activation
includes/class-sbu-crypto.php         AES-256-CBC (random IV, legacy IV migration)
includes/class-sbu-seafile-api.php    stateless Seafile REST client
includes/class-sbu-plugin.php         admin, AJAX, queue engine, retention, logs
views/admin-page.php                  settings page template
assets/js/admin.js                    admin UI (progress polling, pause/resume)
assets/css/admin.css                  admin UI styles
tests/                                PHPUnit + Brain\Monkey
```

## Queue state machine

The upload queue is a single WordPress option (`sbu_upload_queue`). Its
`status` field is the source of truth for the entire lifecycle.

```
                    ┌──────────────────────────┐
                    │                          │
  (admin click)     │                          │
  create_upload_    │        process_          │  ajax_pause_upload
  queue()           ▼        queue_tick()      │  ──────────────────►
  ───────────►  uploading ◄────────────┐       │         paused
                    │                  │       │           │
                    │                  │       │           │  ajax_resume_
                    │ chunk loop       │       │           │  upload()
                    │                  │       │           │
                    ▼                  │       │           ▼
                (all files done)       │       │       uploading
                    │                  │       │           │
                    ▼                  │       │           ▼
                   done                │       │     (continues where
                                       │       │      it was paused,
  ajax_abort_upload  ──────────────────┼───────┤      at exact offset)
                                       │       │
                                       ▼       │
                                   aborted     │
                                               │
  12 h elapsed / detected crash ───────────────┘
                          │
                          ▼
                        error
```

**Terminal states:** `done`, `aborted`, `error`, `paused`.
Mid-tick writes via `safe_queue_update()` refuse to overwrite any of these —
a running tick cannot clobber a concurrent Pause/Abort signal.

**Restore is the same machine** with `status = restoring` instead of
`uploading` and `dir` holding the source folder. `process_restore_tick()`
mirrors `process_queue_tick()`'s loop shape and retry semantics.

## Tick entry points

Work is moved forward by *ticks*. A tick is one invocation of
`process_queue_tick()` under the queue lock, bounded by `SBU_TICK_TIME` (55 s).
There are four ways a tick gets fired:

| Entry point | Trigger | Purpose |
|---|---|---|
| `cron_process_queue` | WP-Cron scheduled event (`SBU_CRON_HOOK`) | Scheduled forward progress |
| `ajax_cron_ping` | External `wp-cron.php`-style ping with per-site secret | For hosts with broken WP-Cron |
| `ajax_kick` | Admin-UI poll (every 5 s while the banner is visible) | Admin-fallback when the browser is open |
| `check_stalled_queue` | `admin_init` hook when last_activity > 90 s | Recovery when the cron fires but the tick died |

All four call `tick_is_gated()` first. If the queue has a `next_allowed_tick_ts`
in the future, they drop the call (return `gated` / early-return). This lets
the loopback spawn fire unconditionally after every tick without defeating
retry backoff — the gate bounces the premature ping.

## Locking

Queue processing is serialised by a single lock option, `sbu_queue_lock`.

```php
acquire_queue_lock( $ttl )    // atomic add_option — succeeds iff row absent
release_queue_lock()          // delete the row
queue_lock_ttl()              // SBU_TICK_TIME + 10 s safety margin
```

Why `add_option` instead of `set_transient`? `add_option` is atomic at the DB
level (INSERT with unique key on `option_name`). `set_transient` is a
two-step read + write — two concurrent ticks both read "absent" and both
write, both winning the race and entering `process_queue_tick()` at the same
time — the atomic insert prevents that.

Stale locks are picked up by comparing their embedded timestamp to
`queue_lock_ttl()` + 30 s — a crashed worker doesn't wedge the queue forever.

## Retry and backoff

On any chunk failure the tick installs:

```php
$queue['files'][ $idx ]['retries']++
$delay                         = min( $retries * 60, 600 )   // seconds
$queue['next_retry_delay']     = $delay                       // drives schedule_next_tick
$queue['next_allowed_tick_ts'] = time() + $delay              // drives tick_is_gated
```

and schedules a WP-Cron tick at `time() + $delay`. The gate prevents any
faster ticks (loopback, admin kick, stall check) from short-circuiting the
wait.

**Reset on success.** The first successful chunk after a failure clears both
`retries` and `next_allowed_tick_ts` via `unset()` — a transient network
blip doesn't leave a permanent slowdown behind.

## Crash detection

Reverse proxies (Cloudflare Tunnel, nginx) and PHP memory-limit kills can
terminate a worker mid-chunk. From WP's point of view the tick just never
returns. `detect_worker_crash_and_defer()` runs at the top of every
`process_queue_tick()` / `process_restore_tick()`:

```
if last_activity older than queue_lock_ttl() + 30 s
   and no gate is currently active
then
    bump retries
    install backoff via next_allowed_tick_ts
    log WARNUNG with file, offset, idle minutes
    reschedule and return
```

Without this, a silent worker death would turn into a 12-hour wait for
`SBU_QUEUE_TIMEOUT` to fire. With it, the next tick notices the stale
`last_activity`, logs the crash, and installs backoff instead of immediately
re-entering the same upload that just killed the worker.

## safe_queue_update: protecting terminal writes

Mid-tick writes must not overwrite a user-initiated terminal status
(`aborted`, `paused`) or an externally-written `error` / `done`.

```php
private function safe_queue_update( array $queue ): string {
    wp_cache_delete( SBU_QUEUE, 'options' );
    $fresh = get_option( SBU_QUEUE );
    $current = $fresh['status'] ?? '';
    if ( in_array( $current, [ 'aborted', 'paused', 'error', 'done' ], true ) ) {
        $queue['status'] = $current;    // preserve terminal
    }
    update_option( SBU_QUEUE, $queue, false );
    return $queue['status'];
}
```

Every mid-loop write inside the chunk iteration goes through this helper.
The inner chunk loop also *reads* the fresh status after every chunk and
breaks out on `aborted` / `paused` — the helper is a belt, the re-read is
the suspenders.

## SHA1 verification (strict mode)

Opt-in setting `strict_verify`. The plugin never computes SHA1 when the
setting is off — that would be a pure-waste cost, especially for multi-GB
backup sets.

**When on:**
1. SHA1 is computed *lazily* inside the tick, at the moment a file's first
   chunk is about to be uploaded (`offset === 0`). That keeps the AJAX
   request that creates the queue fast, and spreads the SHA1 cost across
   tick budgets.
2. After the upload completes, `verify_backup()` streams each file back
   from Seafile to a temp file, computes SHA1, and compares with
   `hash_equals()` against the stored value.
3. A mismatch is logged as FEHLER and the verify badge shows
   *unvollständig* with the offending filename and hash prefixes.

**Why not earlier verification?** Seafile doesn't expose content SHA1 (the
`id` field returned by `file/detail` is an internal commit hash, not a
byte-level checksum). Downloading the file back is the only way to prove
bit-for-bit equality. It doubles bandwidth, which is why it's opt-in.

## Activity log

A capped ring buffer (`SBU_ACTIVITY_MAX = 200`) stored in `sbu_activity_log`.
Every non-routine event appends an entry: upload start / success /
failure / retry, crash detection, pause / resume / abort, SHA1 mismatch,
slow chunks, duplicate detection, retention deletions, restore events,
settings changes.

The log is user-visible (Admin → Seafile Backup → Aktivitätsprotokoll)
and exportable as plain text. It is **the** debugging surface for this
plugin — logs go there first, PHP's error_log second.

## Security boundaries

- **All admin AJAX endpoints**: `manage_options` capability + nonce
  (`verify_ajax_request()`). 20 admin endpoints total.
- **One public endpoint**: `sbu_cron_ping` — per-site 32-char secret,
  compared with `hash_equals()` for timing-safe equality. No other
  public surface.
- **Password at rest**: AES-256-CBC with a random IV per encryption
  operation.
- **Path traversal**: all user-supplied path segments go through
  `sanitize_path_segment()` which rejects `..`, absolute paths, and
  non-printable characters.
- **Multipart boundary injection**: filenames in `Content-Disposition` are
  `addslashes()`-escaped.
- **TLS**: Seafile API calls use `sslverify => true`. The one exception is
  the internal loopback to `admin-ajax.php` — verification is disabled
  there because it's a request to the same host.

See [SECURITY.md](SECURITY.md) for the threat model and disclosure process.
