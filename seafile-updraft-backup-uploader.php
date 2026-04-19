<?php
/**
 * Seafile Updraft Backup Uploader
 *
 * Uploads UpdraftPlus backups to a Seafile server using the native Seafile
 * Upload API with chunked upload support. Each chunk is sent as a
 * separate HTTP request, staying under reverse proxy upload limits (e.g.
 * Cloudflare free tier: 100 MB).
 *
 * This plugin requires UpdraftPlus (free or premium) to create backups.
 * It replaces UpdraftPlus's built-in WebDAV remote storage with a direct
 * Seafile API integration that supports chunked uploads - something WebDAV
 * on Seafile cannot do.
 *
 * Why use this instead of WebDAV?
 * - WebDAV on Seafile does NOT support chunked uploads
 * - Files larger than the proxy limit (e.g. 100 MB on Cloudflare) fail via WebDAV
 * - This plugin splits uploads into small chunks (e.g. 40 MB each)
 * - Uses the same API that Seafile's own web interface uses
 * - Each chunk uploaded individually, so only failed chunks need retrying
 * - Automatic cleanup of UpdraftPlus history when local files are deleted
 * - Automatic retention management (old backups cleaned up automatically)
 * - Email notifications on failure - no need to check manually
 *
 * @wordpress-plugin
 * Plugin Name: Seafile Updraft Backup Uploader
 * Plugin URI:  https://github.com/malziland/seafile-updraft-backup-uploader
 * Description: Uploads UpdraftPlus backups to Seafile via chunked API upload. Bypasses WebDAV and Cloudflare limits. Dashboard widget, email alerts, retention management.
 * Version:     1.0.6
 * Author:      malziland - learning | training | consulting
 * Author URI:  https://malziland.at
 * License:     MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: seafile-updraft-backup-uploader
 * Requires at least: 6.0
 * Requires PHP: 8.2
 */

defined( 'ABSPATH' ) || exit;

// --- Constants ---------------------------------------------------------------

define( 'SBU_VER', '1.0.6' );
define( 'SBU_OPT', 'sbu_settings' );
define( 'SBU_LOG', 'sbu_log' );
define( 'SBU_STAT', 'sbu_status' );
define( 'SBU_TOK', 'sbu_token' );
define( 'SBU_NONCE', 'sbu_nonce_v1' );
define( 'SBU_SLUG', 'seafile-updraft-backup-uploader' );
define( 'SBU_TIMEOUT_API', 30 );
define( 'SBU_TIMEOUT_UPLOAD', 120 );
define( 'SBU_TIMEOUT_DOWNLOAD', 180 );
// Download chunk ceiling. Real chunk size is computed adaptively from the
// tick budget (see SBU_Plugin::compute_adaptive_limits()) so a 25 s tick
// picks 5 MB and a 250 s tick picks 20 MB. Ceiling kept at 20 MB because
// Cloudflare Tunnel drops long-running HTTP responses around 100 s; at a
// worst-case 0.25 MB/s that still fits in a single chunk window.
define( 'SBU_DOWNLOAD_CHUNK_MB_DEFAULT', 20 );
define( 'SBU_DOWNLOAD_CHUNK_MB_MIN', 4 );
define( 'SBU_HASHES', 'sbu_backup_hashes' );
define( 'SBU_ACTIVITY', 'sbu_activity_log' );
define( 'SBU_ACTIVITY_MAX', 500 );
// Zeit-basierte Aufbewahrung des Aktivitätsprotokolls (Tage). Default 30 Tage.
// Erlaubte Werte: 0 (deaktiviert, nur Zeilen-Cap greift) oder 7..365. Wird per
// daily-Cron und opportunistisch bei activity_log()-Aufrufen angewendet.
define( 'SBU_ACTIVITY_RETENTION_DAYS_DEFAULT', 30 );
define( 'SBU_ACTIVITY_RETENTION_CRON_HOOK', 'sbu_activity_retention_tick' );
define( 'SBU_QUEUE', 'sbu_upload_queue' );
define( 'SBU_CRON_HOOK', 'sbu_cron_tick' );
// Legacy ceiling for the tick budget — the real per-request value is
// computed adaptively at runtime from max_execution_time (see
// SBU_Plugin::get_adaptive_limits()). Kept as a hard upper bound so the
// adaptive value can never exceed a sane ceiling even if someone sets
// max_execution_time=0 with a wild memory budget.
define( 'SBU_TICK_TIME', 250 );
define( 'SBU_CHUNK_RETRIES', 3 );
// Legacy floor for the queue timeout — the real per-queue value is
// size-based (see SBU_Plugin::compute_queue_timeout()) so a 50 GB restore
// doesn't hit a hardcoded 12 h wall.
define( 'SBU_QUEUE_TIMEOUT', 43200 );
// Cap on parallel download chunks. Adaptive logic trims this further when
// memory is tight — see SBU_Plugin::get_adaptive_limits().
define( 'SBU_PARALLEL_DOWNLOADS_MAX', 8 );
// Expected restore throughput used to size the queue timeout. Conservative
// 2 MB/s assumes a worst-case TLS + proxy path; real-world throughput is
// usually higher, which just means the timeout is overbudgeted.
define( 'SBU_RESTORE_THROUGHPUT_BPS', 2 * 1024 * 1024 );
// Hard upper bound on in-process sleep inside ajax_cron_ping while a
// backoff gate is active. Prevents a single request from holding a PHP-FPM
// worker for a full tick window; the loopback-spawn path picks up the rest.
define( 'SBU_CRON_SLEEP_MAX', 15 );

// --- Class loading -----------------------------------------------------------

require_once __DIR__ . '/includes/class-sbu-crypto.php';
require_once __DIR__ . '/includes/class-sbu-seafile-api.php';
require_once __DIR__ . '/includes/class-sbu-activity-log.php';
require_once __DIR__ . '/includes/class-sbu-mail-notifier.php';
require_once __DIR__ . '/includes/class-sbu-queue-engine.php';
require_once __DIR__ . '/includes/trait-sbu-admin-ajax.php';
require_once __DIR__ . '/includes/trait-sbu-upload-flow.php';
require_once __DIR__ . '/includes/trait-sbu-restore-flow.php';
require_once __DIR__ . '/includes/class-sbu-plugin.php';

// --- Initialize ---------------------------------------------------------------

new SBU_Plugin();

// --- Activation / Deactivation / Uninstall -----------------------------------

register_activation_hook(
	__FILE__,
	function () {
		if ( false === get_option( SBU_OPT ) ) {
			add_option( SBU_OPT, array() );
		}
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		delete_transient( SBU_TOK );
		wp_clear_scheduled_hook( SBU_ACTIVITY_RETENTION_CRON_HOOK );
	}
);

register_uninstall_hook( __FILE__, 'sbu_uninstall' );

function sbu_uninstall() {
	delete_option( SBU_OPT );
	delete_option( SBU_LOG );
	delete_option( SBU_STAT );
	delete_option( SBU_ACTIVITY );
	delete_option( SBU_QUEUE );
	delete_option( SBU_HASHES );
	delete_option( 'sbu_verified' );
	delete_option( 'sbu_cron_key' );
	delete_option( 'sbu_queue_lock' );
	delete_transient( SBU_TOK );
	delete_transient( 'sbu_processing_lock' );
}
