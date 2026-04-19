<?php
/**
 * Activity log service — extracted from SBU_Plugin (ARCH-001 Schritt 1).
 *
 * Kapselt das Aktivitätsprotokoll in einer eigenen Klasse: Schreiben,
 * Zeilen- und Zeit-basierte Retention, Cron-Scheduling, Render-Hilfe fürs
 * Admin. Früher im God-Class-Pfad rausgereichert, damit zukünftige
 * Retention-Policies (z. B. DSGVO-Export-Opt-out, Log-Shipping) hier
 * lokal wachsen können ohne SBU_Plugin weiter zu fluten.
 *
 * @package SeafileUpdraftBackupUploader
 */

defined( 'ABSPATH' ) || exit;

final class SBU_Activity_Log {

	/**
	 * Settings-Provider als Callable, damit die Klasse nicht an SBU_Plugin
	 * koppelt. Wird in log() und get_retention_days() aufgerufen.
	 *
	 * @var callable
	 */
	private $settings_provider;

	/**
	 * Konstruktor.
	 *
	 * @param callable $settings_provider Gibt die aktuellen Plugin-Settings zurück (Array).
	 */
	public function __construct( callable $settings_provider ) {
		$this->settings_provider = $settings_provider;
	}

	/**
	 * Append a line to the activity log.
	 *
	 * @param string $prefix Category token shown at the start of the line
	 *                       (e.g. "RESTORE", "CHUNK", "BATCH", "RATE").
	 * @param string $msg    Free-form message body.
	 * @param string $level  'info' (always logged) or 'debug' (only logged
	 *                       when the debug_log setting is on). Debug events
	 *                       — per-chunk transcripts, tick markers — flood
	 *                       the log during real restores, so they're gated
	 *                       behind the setting and off by default.
	 */
	public function log( $prefix, $msg, $level = 'info' ) {
		$settings = ( $this->settings_provider )();
		if ( $level === 'debug' && empty( $settings['debug_log'] ) ) {
			return;
		}
		$entry = '[' . current_time( 'd.m.Y H:i:s' ) . '] ' . $prefix . ': ' . $msg;
		wp_cache_delete( SBU_ACTIVITY, 'options' );
		$log = get_option( SBU_ACTIVITY, '' );
		// Prepend new entry (newest first)
		$log = $entry . "\n" . $log;
		// Trim to max lines
		$lines = explode( "\n", $log );
		if ( count( $lines ) > SBU_ACTIVITY_MAX ) {
			$lines = array_slice( $lines, 0, SBU_ACTIVITY_MAX );
		}
		// Zeit-basiertes Prune direkt im Append-Pfad, damit das Log auch ohne
		// aktiven Daily-Cron nicht über die Retention-Grenze hinauswächst. Die
		// Kosten sind gering — wir parsen nur die vorderen Zeilen bis zur
		// ersten "alten" und schneiden dort ab (Append erzeugt Newest-First).
		$retention_days = $this->get_retention_days();
		if ( $retention_days > 0 ) {
			$lines = $this->prune_lines( $lines, $retention_days );
		}
		update_option( SBU_ACTIVITY, implode( "\n", $lines ), false );
	}

	/**
	 * Current retention window in days, or 0 if disabled.
	 *
	 * @return int
	 */
	public function get_retention_days() {
		$s    = ( $this->settings_provider )();
		$days = intval( $s['activity_log_retention_days'] ?? SBU_ACTIVITY_RETENTION_DAYS_DEFAULT );
		if ( 0 === $days ) {
			return 0;
		}
		return max( 7, min( 365, $days ) );
	}

	/**
	 * Drop activity-log lines whose timestamp is older than $days.
	 *
	 * Input is the newest-first line array used by log(); lines without a
	 * parseable timestamp (e.g. a blank trailing line) are kept so we never
	 * lose data on a format surprise.
	 *
	 * @param array $lines Newest-first array of log lines.
	 * @param int   $days  Retention window in days (> 0).
	 * @return array Pruned lines, still newest-first.
	 */
	public function prune_lines( array $lines, $days ) {
		if ( $days <= 0 || empty( $lines ) ) {
			return $lines;
		}
		// Cutoff als UTC-Unixzeit. DateTime::createFromFormat() mit
		// wp_timezone() unten liefert getTimestamp() ebenfalls UTC — damit
		// ist der Vergleich korrekt, unabhängig von der Site-Zeitzone.
		$cutoff = time() - ( $days * DAY_IN_SECONDS );
		$kept   = array();
		foreach ( $lines as $line ) {
			if ( ! preg_match( '/^\[(\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}:\d{2})\]/', $line, $m ) ) {
				$kept[] = $line;
				continue;
			}
			// strtotime() erwartet 'd.m.Y H:i:s' als deutsches Datum; wandel
			// das explizit in ein DateTime, weil strtotime() in englischer
			// Locale auf '03.04.2026' stolpert (würde als 3. April eingelesen,
			// was zufällig klappt — aber wir wollen es deterministisch).
			$dt = \DateTime::createFromFormat( 'd.m.Y H:i:s', $m[1], wp_timezone() );
			if ( ! $dt ) {
				$kept[] = $line;
				continue;
			}
			if ( $dt->getTimestamp() >= $cutoff ) {
				$kept[] = $line;
			}
		}
		return $kept;
	}

	/**
	 * Ensure the daily retention cron is scheduled exactly once.
	 *
	 * Hooked on admin_init so bestehende Installationen bekommen den Cron
	 * beim ersten Admin-Aufruf — ohne dass der User re-aktivieren muss.
	 * Doppelscheduling ist idempotent (wp_next_scheduled-Guard).
	 */
	public function ensure_cron() {
		if ( ! wp_next_scheduled( SBU_ACTIVITY_RETENTION_CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', SBU_ACTIVITY_RETENTION_CRON_HOOK );
		}
	}

	/**
	 * Daily cron callback: re-read the log and prune by retention window.
	 *
	 * Runs independently of the append path so an idle site (no new
	 * activity) still gets its retention enforced. Bails out quickly when
	 * retention is disabled (0) or the log is empty.
	 */
	public function cron_prune() {
		$days = $this->get_retention_days();
		if ( $days <= 0 ) {
			return;
		}
		wp_cache_delete( SBU_ACTIVITY, 'options' );
		$log = get_option( SBU_ACTIVITY, '' );
		if ( $log === '' ) {
			return;
		}
		$lines = explode( "\n", $log );
		$kept  = $this->prune_lines( $lines, $days );
		if ( count( $kept ) !== count( $lines ) ) {
			update_option( SBU_ACTIVITY, implode( "\n", $kept ), false );
		}
	}

	/**
	 * Format activity log text with colored HTML spans per category.
	 * Called from views/admin-page.php.
	 *
	 * @param string $l Raw activity log text.
	 * @return string HTML-formatted log.
	 */
	public function format( $l ) {
		$l = esc_html( $l );
		// Each line is wrapped in a div carrying a data-cat attribute so
		// the admin-page JS can show/hide categories without having to
		// round-trip to the server. The span inside handles colouring.
		$cats      = array(
			'UPLOAD'      => 'g',
			'LÖSCHEN'     => 'del',
			'RESTORE'     => 'res',
			'BEREINIGUNG' => 'dim',
			'TEST'        => 'b',
			'FEHLER'      => 'e',
			'WARNUNG'     => 'e',
			'INFO'        => 'b',
			'RETRY'       => 'b',
			'DUPLIKAT'    => 'b',
			'SETTINGS'    => 'dim',
			'VERIFIZIERT' => 'g',
			'TICK'        => 'dim',
			'BATCH'       => 'dim',
			'CHUNK'       => 'dim',
			'RATE'        => 'b',
		);
		$out_lines = array();
		foreach ( explode( "\n", $l ) as $line ) {
			if ( $line === '' ) {
				continue;
			}
			if ( preg_match( '/^(\[.*?\]) ([A-ZÄÖÜ]+): /u', $line, $m ) ) {
				$cat         = $m[2];
				$klass       = $cats[ $cat ] ?? 'b';
				$body        = substr( $line, strlen( $m[1] ) + 1 );
				$out_lines[] = '<div class="sbu-log-line" data-cat="' . esc_attr( $cat ) . '">'
					. $m[1] . ' <span class="' . esc_attr( $klass ) . '">' . $body . '</span></div>';
			} else {
				$out_lines[] = '<div class="sbu-log-line" data-cat="OTHER">' . $line . '</div>';
			}
		}
		return implode( '', $out_lines );
	}
}
