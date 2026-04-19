<?php
/**
 * Queue engine service — extracted from SBU_Plugin (ARCH-001 Schritt 3).
 *
 * Kapselt die reine Queue-Infrastruktur: atomares Lock, Lock-TTL, Gate-Check,
 * Loopback-Spawn und WP-Cron-Scheduling. Kein Tick-Work, kein Upload/Restore-
 * State — das bleibt in SBU_Plugin (bzw. zieht in späteren ARCH-Schritten in
 * SBU_Upload_Flow / SBU_Restore_Flow um).
 *
 * Warum callable-basiert statt Plugin-Referenz: die Engine liest den Cron-Key
 * und die adaptiven Limits nur zum Zeitpunkt des Aufrufs — so bleibt sie
 * testbar ohne volle Plugin-Instanzierung und koppelt nicht an SBU_Plugin.
 *
 * @package SeafileUpdraftBackupUploader
 */

defined( 'ABSPATH' ) || exit;

final class SBU_Queue_Engine {

	/**
	 * Name der Lock-Option. Identisch zur Legacy-Konstante in SBU_Plugin,
	 * damit Mid-Upgrade-Queues (< 1.0.3) nicht verloren gehen.
	 */
	const LOCK_OPTION = 'sbu_queue_lock';

	/**
	 * Callable, liefert den aktuellen Cron-Key als String.
	 *
	 * @var callable
	 */
	private $cron_key_provider;

	/**
	 * Callable, liefert das Array aus SBU_Plugin::get_adaptive_limits().
	 * Erwartet mindestens den Key 'tick_time' (Sekunden).
	 *
	 * @var callable
	 */
	private $adaptive_limits_provider;

	/**
	 * Konstruktor.
	 *
	 * @param callable $cron_key_provider        Liefert den aktuellen Cron-Key (string).
	 * @param callable $adaptive_limits_provider Liefert die adaptiven Limits (array mit 'tick_time').
	 */
	public function __construct( callable $cron_key_provider, callable $adaptive_limits_provider ) {
		$this->cron_key_provider        = $cron_key_provider;
		$this->adaptive_limits_provider = $adaptive_limits_provider;
	}

	/**
	 * Try to acquire the queue-processing lock atomically.
	 *
	 * Relies on `add_option()`'s built-in existence check so that two
	 * concurrent ticks can't both enter process_queue_tick(). Stores the
	 * lock's expiry timestamp so a process that died mid-tick doesn't
	 * permanently wedge the queue: a lock whose expiry is in the past is
	 * deleted and takeover is attempted.
	 *
	 * @param int $ttl Seconds until the lock is considered stale.
	 * @return bool True if the caller now holds the lock.
	 */
	public function acquire_lock( $ttl ) {
		wp_cache_delete( self::LOCK_OPTION, 'options' );
		$existing = (int) get_option( self::LOCK_OPTION, 0 );

		if ( $existing > 0 && $existing > time() ) {
			return false;
		}
		if ( $existing > 0 ) {
			delete_option( self::LOCK_OPTION );
		}

		return (bool) add_option( self::LOCK_OPTION, time() + $ttl, '', false );
	}

	/**
	 * Release the queue-processing lock. Also clears the legacy transient
	 * from <1.2 installs mid-queue during the upgrade.
	 */
	public function release_lock() {
		delete_option( self::LOCK_OPTION );
		delete_transient( 'sbu_processing_lock' );
	}

	/**
	 * Default lock TTL covering one adaptive tick plus the longest per-chunk
	 * timeout plus a safety margin.
	 *
	 * @return int Seconds.
	 */
	public function default_lock_ttl() {
		$lim = ( $this->adaptive_limits_provider )();
		return $lim['tick_time'] + max( SBU_TIMEOUT_UPLOAD, SBU_TIMEOUT_DOWNLOAD ) + 30;
	}

	/**
	 * Whether the queue is within a backoff window and a fresh tick should
	 * not start yet. Kept out of the acquire-lock path so loopback pings
	 * and WP-Cron fires can be rejected cheaply while admin-visible state
	 * (paused, done) passes through the entry points normally.
	 *
	 * Reads mutable DB state (SBU_QUEUE) fresh on every call — never memoize,
	 * the gate value shifts as ticks schedule/clear backoff windows.
	 *
	 * @phpstan-impure
	 * @return bool
	 */
	public function tick_is_gated() {
		wp_cache_delete( SBU_QUEUE, 'options' );
		$queue = get_option( SBU_QUEUE );
		if ( ! is_array( $queue ) ) {
			return false;
		}
		$gate = (int) ( $queue['next_allowed_tick_ts'] ?? 0 );
		return $gate > time();
	}

	/**
	 * Spawn a non-blocking self-request to continue queue processing.
	 * Works without WP-Cron and without external services.
	 *
	 * Transport:
	 * - Action als Query-Parameter, damit WordPress admin-ajax.php die Route kennt.
	 * - Cron-Key über den `X-SBU-Cron-Key`-Header, nicht im POST-Body. So landet
	 *   der Schlüssel nicht in möglicherweise geleakten Body-Dumps (Debug-Plugins,
	 *   Error-Tracker, die POST-Daten einfangen). Der Request-Handler prüft
	 *   Header vor Query-Param, der Loopback ist damit header-first konsistent.
	 */
	public function spawn_next_tick() {
		$url = add_query_arg( 'action', 'sbu_cron_ping', admin_url( 'admin-ajax.php' ) );
		// sslverify=false is acceptable here: non-blocking loopback to our own admin-ajax.php.
		// Response is discarded (timeout 0.01, blocking=false), so strict TLS would only cause
		// unnecessary failures on sites with self-signed dev certs or reverse proxies terminating TLS.
		wp_remote_post(
			$url,
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => false,
				'headers'   => array(
					'X-SBU-Cron-Key' => ( $this->cron_key_provider )(),
				),
			)
		);
	}

	/**
	 * Schedule next queue tick via WP-Cron as a backstop to the loopback spawn.
	 *
	 * @param int $delay Seconds from now.
	 */
	public function schedule_next_tick( $delay = 60 ) {
		wp_clear_scheduled_hook( SBU_CRON_HOOK );
		wp_schedule_single_event( time() + $delay, SBU_CRON_HOOK );
	}
}
