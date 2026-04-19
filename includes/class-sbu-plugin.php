<?php
/**
 * Main plugin class: SBU_Plugin.
 *
 * This class contains the plugin's current monolithic implementation.
 * Subsequent refactoring steps will extract self-contained modules
 * (crypto, Seafile API client, queue, AJAX, admin page) into dedicated
 * classes under this same includes/ directory.
 *
 * @package seafile-updraft-backup-uploader
 */

defined( 'ABSPATH' ) || exit;

/*
 * PHPCS file-scope suppressions with explicit rationale:
 *
 * WordPress.Security.NonceVerification
 *   Every public ajax_* handler enters via $this->verify_ajax_request(),
 *   which calls wp_verify_nonce() on $_POST['_nonce']. The WPCS sniff only
 *   recognizes global function calls (not $this->method()), so it emits
 *   false positives on every $_POST/$_REQUEST access in these handlers.
 *   Nonce coverage is enforced by verify_ajax_request() and audited in
 *   SECURITY.md, not by this sniff.
 *
 * WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
 *   The plugin sanitizes via custom helpers ($this->sanitize(),
 *   $this->sanitize_path_segment(), SBU_Crypto::…) that the sniff can't
 *   register as "sanitizers". MissingUnslash stays active so real
 *   unslash omissions are still caught.
 *
 * WordPress.PHP.NoSilencedErrors
 *   Adaptive limit calls (@set_time_limit, @ini_set, @ignore_user_abort)
 *   must tolerate being disabled by the hoster. A failure is non-fatal
 *   by design — we continue with the default limits and log the outcome
 *   via activity_log().
 *
 * WordPress.WP.AlternativeFunctions.unlink_unlink,
 * WordPress.WP.AlternativeFunctions.file_system_operations_*
 *   Restore and upload paths stream chunks through request-scoped temp
 *   files created with tempnam()/fopen() and released deterministically.
 *   WP_Filesystem and wp_delete_file add filter hooks but don't provide
 *   the stream/eager-release semantics the queue engine relies on.
 */
// phpcs:disable WordPress.Security.NonceVerification
// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged
// phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fread
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose

final class SBU_Plugin {

	// ARCH-001 Schritt 4: alle Ajax-Handler liegen physisch im Trait, werden
	// zur Compile-Zeit in die Klasse komponiert. Private-Zugriffe auf Plugin-
	// Helfer (verify_ajax_request etc.) bleiben damit unverändert.
	use SBU_Admin_Ajax;

	// ARCH-001 Schritt 5: Upload- und Restore-Flow sind ebenfalls Traits.
	// process_queue_tick (Upload) ruft process_restore_tick (Restore) direkt auf —
	// beide Traits teilen sich über die Klasse die gemeinsamen Helfer.
	use SBU_Upload_Flow;
	use SBU_Restore_Flow;

	/**
	 * Activity-log service (ARCH-001 Schritt 1 — aus der God-Class rausgezogen).
	 *
	 * Alle schreibenden/lesenden Log-Operationen laufen durch diesen Service.
	 * SBU_Plugin ruft log()/format()/cron_prune() nur noch als Fassade auf.
	 * Öffentlich, damit views/admin-page.php direkt auf $this->activity_logger
	 * zugreifen kann — der Render-Pfad will nicht in ein Getter-Layer gezwungen
	 * werden, nur um format() aufzurufen.
	 *
	 * @var SBU_Activity_Log
	 */
	public $activity_logger;

	/**
	 * Mail-Notifier (ARCH-001 Schritt 2). Zuständig für alle admin-gerichteten
	 * E-Mails (Erfolg/Fehler/Stillstand). Siehe SBU_Mail_Notifier.
	 *
	 * @var SBU_Mail_Notifier
	 */
	private $mail_notifier;

	/**
	 * Queue-Engine (ARCH-001 Schritt 3). Atomares Lock, Gate-Check, Loopback-
	 * Spawn und WP-Cron-Scheduling. Nutzt Callable-DI, damit der
	 * Cron-Key-Zugriff (mit Lazy-Init) und die adaptiven Limits erst zum
	 * Aufrufzeitpunkt ausgewertet werden.
	 *
	 * @var SBU_Queue_Engine
	 */
	private $queue_engine;

	/**
	 * Initialize plugin hooks and actions.
	 */
	public function __construct() {
		$this->activity_logger = new SBU_Activity_Log( array( $this, 'get_settings' ) );
		$this->mail_notifier   = new SBU_Mail_Notifier( array( $this, 'get_settings' ) );
		$this->queue_engine    = new SBU_Queue_Engine(
			function () {
				return $this->get_cron_key();
			},
			function () {
				return $this->get_adaptive_limits();
			}
		);

		// Load translations
		add_action( 'init', array( $this, 'load_textdomain' ) );

		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'check_stalled_queue' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );
		add_action( 'wp_dashboard_setup', array( $this, 'register_widget' ) );
		add_action( 'updraftplus_backup_complete', array( $this, 'on_backup_complete' ) );
		add_action( SBU_CRON_HOOK, array( $this, 'cron_process_queue' ) );
		// Täglicher Cron fürs Aktivitätsprotokoll-Pruning. Scheduling im
		// Service selbst (ensure_cron beim admin_init), damit bestehende
		// Installationen ohne neue Aktivierung abgedeckt sind.
		add_action( SBU_ACTIVITY_RETENTION_CRON_HOOK, array( $this->activity_logger, 'cron_prune' ) );
		add_action( 'admin_init', array( $this->activity_logger, 'ensure_cron' ) );
		// Zero-Traffic-Backstop für die Retention: auf idle Sites ohne aktive
		// Queue feuert weder log() noch WP-Cron zuverlässig. Der admin_init-
		// Pfad greift spätestens beim Admin-Login und schlägt den verspäteten
		// Prune nach. Bei leerem Log oder retention=0 ist das ein No-Op
		// (cron_prune() bricht früh ab), also günstig.
		add_action( 'admin_init', array( $this->activity_logger, 'cron_prune' ) );
		foreach ( array( 'test', 'upload', 'list', 'download_all', 'delete', 'get_log', 'export_log', 'export_log_anon', 'clear_log', 'upload_status', 'load_libs', 'load_dirs', 'create_dir', 'save_settings', 'reset_settings', 'refresh_nonce', 'abort_upload', 'pause_upload', 'resume_upload', 'kick', 'dismiss_restore_banner', 'rotate_cron_key' ) as $a ) {
			add_action( 'wp_ajax_sbu_' . $a, array( $this, 'ajax_' . $a ) );
		}
		// External cron endpoint (no login required, key-protected)
		add_action( 'wp_ajax_nopriv_sbu_cron_ping', array( $this, 'ajax_cron_ping' ) );
		add_action( 'wp_ajax_sbu_cron_ping', array( $this, 'ajax_cron_ping' ) );
	}

	/**
	 * Load plugin translations.
	 */
	public function load_textdomain() {
		// NOTE: do not derive the path from __FILE__ here — this class lives
		// in /includes, so dirname(plugin_basename(__FILE__)) would point at
		// <plugin>/includes/languages, which doesn't exist. The translations
		// folder is at the plugin root, so we anchor on SBU_SLUG directly.
		load_plugin_textdomain( 'seafile-updraft-backup-uploader', false, SBU_SLUG . '/languages' );
	}

	// =========================================================================
	// SETTINGS
	// =========================================================================

	/**
	 * Retrieve plugin settings with defaults.
	 *
	 * @return array Merged settings.
	 */
	public function get_settings() {
		return wp_parse_args(
			get_option( SBU_OPT, array() ),
			array(
				'url'                         => '',
				'user'                        => '',
				'pass'                        => '',
				'lib'                         => '',
				'folder'                      => '/',
				'chunk'                       => 40,
				'download_chunk'              => SBU_DOWNLOAD_CHUNK_MB_DEFAULT,
				'retention'                   => 4,
				'del_local'                   => 0,
				'auto'                        => 1,
				'email'                       => get_option( 'admin_email' ),
				'notify'                      => 'error',
				'debug_log'                   => 0,
				'activity_log_retention_days' => SBU_ACTIVITY_RETENTION_DAYS_DEFAULT,
			)
		);
	}

	/**
	 * Sanitize and validate settings input.
	 *
	 * @param array $i Raw input from settings form.
	 * @return array Sanitized settings.
	 */
	public function sanitize( $i ) {
		$c                   = array();
		$c['url']            = esc_url_raw( rtrim( trim( $i['url'] ?? '' ), '/' ) );
		$c['user']           = sanitize_email( $i['user'] ?? '' );
		$c['lib']            = sanitize_text_field( $i['lib'] ?? '' );
		$c['chunk']          = max( 5, min( 90, intval( $i['chunk'] ?? 40 ) ) );
		$c['download_chunk'] = max( 4, min( 40, intval( $i['download_chunk'] ?? SBU_DOWNLOAD_CHUNK_MB_DEFAULT ) ) );
		$c['retention']      = max( 0, min( 50, intval( $i['retention'] ?? 4 ) ) );
		// Activity-Log-Retention (Tage). 0 = deaktiviert; sonst auf 7..365
		// einrasten damit Panik-Eingaben wie "1" das komplette Log binnen
		// eines Tick-Fensters rasieren. Default kommt aus der Konstante und
		// greift bei frisch installierten Plugins über get_settings().
		$raw_retention_days               = intval( $i['activity_log_retention_days'] ?? SBU_ACTIVITY_RETENTION_DAYS_DEFAULT );
		$c['activity_log_retention_days'] = ( 0 === $raw_retention_days ) ? 0 : max( 7, min( 365, $raw_retention_days ) );
		$c['del_local']                   = ! empty( $i['del_local'] ) ? 1 : 0;
		$c['auto']                        = ! empty( $i['auto'] ) ? 1 : 0;
		$c['debug_log']                   = ! empty( $i['debug_log'] ) ? 1 : 0;
		$c['email']                       = sanitize_email( $i['email'] ?? '' );
		$allowed                          = array( 'always', 'error', 'never' );
		$c['notify']                      = in_array( $i['notify'] ?? '', $allowed, true ) ? $i['notify'] : 'error';
		$sub                              = trim( sanitize_text_field( $i['folder'] ?? '' ), '/ ' );
		$c['folder']                      = '/' . $sub;

		$pw   = $i['pass'] ?? '';
		$dots = str_repeat( "\xe2\x80\xa2", 8 );
		if ( $pw !== '' && $pw !== $dots ) {
			$c['pass'] = SBU_Crypto::encrypt( $pw );
		} else {
			$old       = get_option( SBU_OPT, array() );
			$c['pass'] = $old['pass'] ?? '';
		}
		delete_transient( SBU_TOK );
		return $c;
	}

	/**
	 * Register the plugin settings with WordPress.
	 */
	public function register_settings() {
		register_setting( SBU_OPT, SBU_OPT );
	}

	// =========================================================================
	// ADMIN PAGE
	// =========================================================================

	/**
	 * Register the settings page under Settings menu.
	 */
	public function register_admin_menu() {
		add_options_page(
			'Seafile Updraft Backup Uploader',
			'Seafile Backup',
			'manage_options',
			SBU_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register the plugin's admin CSS + JS on its own settings page.
	 *
	 * Assets live in assets/css/admin.css and assets/js/admin.js; the per-page
	 * state (nonce, current library/folder, translated UI strings) is passed
	 * to JavaScript via wp_localize_script(), which avoids interpolating PHP
	 * into a <script> tag.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_styles( $hook ) {
		if ( 'settings_page_' . SBU_SLUG !== $hook ) {
			return;
		}

		$plugin_file = dirname( __DIR__ ) . '/seafile-updraft-backup-uploader.php';

		wp_enqueue_style(
			'sbu-admin',
			plugins_url( 'assets/css/admin.css', $plugin_file ),
			array(),
			SBU_VER
		);

		wp_enqueue_script(
			'sbu-admin',
			plugins_url( 'assets/js/admin.js', $plugin_file ),
			array(),
			SBU_VER,
			true
		);

		$s = $this->get_settings();
		wp_localize_script(
			'sbu-admin',
			'sbuAdmin',
			array(
				'nonce'     => wp_create_nonce( SBU_NONCE ),
				'optKey'    => SBU_OPT,
				'curLib'    => (string) $s['lib'],
				'curFolder' => (string) $s['folder'],
				'i18n'      => array(
					'wait'              => __( 'Bitte warten...', 'seafile-updraft-backup-uploader' ),
					'noActivity'        => __( 'Noch keine Aktivität aufgezeichnet.', 'seafile-updraft-backup-uploader' ),
					'hide'              => __( 'Hide', 'seafile-updraft-backup-uploader' ),
					'show'              => __( 'Show', 'seafile-updraft-backup-uploader' ),
					'restoreConfirm'    => __( 'Alle Backup-Dateien von Seafile auf den Server herunterladen (ins UpdraftPlus-Verzeichnis). Danach kann über UpdraftPlus wiederhergestellt werden. Fortfahren?', 'seafile-updraft-backup-uploader' ),
					'downloadingAll'    => __( 'Alle Dateien werden von Seafile heruntergeladen...', 'seafile-updraft-backup-uploader' ),
					'downloadProgress'  => __( 'Fortschritt wird unten angezeigt, falls der Download im Hintergrund weiterläuft.', 'seafile-updraft-backup-uploader' ),
					'downloadTimeout'   => __( 'Server-Timeout – der Download läuft möglicherweise im Hintergrund weiter. Bitte Fortschritt unten beobachten.', 'seafile-updraft-backup-uploader' ),
					'clearLogConfirm'   => __( 'Gesamtes Aktivitätsprotokoll leeren?', 'seafile-updraft-backup-uploader' ),
					'rotateCronConfirm' => __( 'Neuen externen Cron-Schlüssel generieren? Laufende externe Cronjobs mit dem alten Schlüssel werden bis zum Crontab-Update nicht mehr akzeptiert.', 'seafile-updraft-backup-uploader' ),
					'rotateCronOk'      => __( '✓ Schlüssel rotiert. Crontab aktualisieren.', 'seafile-updraft-backup-uploader' ),
				),
			)
		);
	}

	/**
	 * Render the main plugin settings and actions page. Delegates all markup
	 * to views/admin-page.php; the view has access to `$this`, `$s`, `$dots`,
	 * and `$activity` below.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied.', 'seafile-updraft-backup-uploader' ) );
		}
		$s               = $this->get_settings();
		$dots            = str_repeat( "\xe2\x80\xa2", 8 );
		$activity        = get_option( SBU_ACTIVITY, '' );
		$cron_key        = $this->get_cron_key();
		$cron_url_header = add_query_arg(
			array( 'action' => 'sbu_cron_ping' ),
			admin_url( 'admin-ajax.php' )
		);
		$cron_url        = add_query_arg(
			array(
				'action' => 'sbu_cron_ping',
				'key'    => $cron_key,
			),
			admin_url( 'admin-ajax.php' )
		);
		require dirname( __DIR__ ) . '/views/admin-page.php';
	}

	/**
	 * Render a form field. Called from views/admin-page.php — must stay
	 * public so the template can invoke it through $this.
	 *
	 * @param string $name    Field name key.
	 * @param string $label   Display label.
	 * @param string $value   Current value.
	 * @param string $type    Input type.
	 * @param string $placeholder Placeholder text.
	 * @param string $hint    Help text below the field.
	 */
	public function field( $name, $label, $value, $type = 'text', $placeholder = '', $hint = '' ) {
		echo '<div class="ff"><label>' . esc_html( $label ) . '</label>';
		echo '<input type="' . esc_attr( $type ) . '" name="' . esc_attr( SBU_OPT ) . '[' . esc_attr( $name ) . ']" value="' . esc_attr( $value ) . '"';
		if ( $placeholder ) {
			echo ' placeholder="' . esc_attr( $placeholder ) . '"';
		}
		echo ' /></div>';
		if ( $hint ) {
			echo '<p class="hint">' . esc_html( $hint ) . '</p>';
		}
	}

	// =========================================================================
	// DASHBOARD WIDGET
	// =========================================================================

	/**
	 * Register the dashboard widget.
	 */
	public function register_widget() {
		wp_add_dashboard_widget( 'sbu_w', 'Seafile Backup', array( $this, 'render_widget' ) );
	}

	/**
	 * Render the dashboard widget content.
	 */
	public function render_widget() {
		$st           = get_option( SBU_STAT, array() );
		$s            = $this->get_settings();
		$settings_url = admin_url( 'options-general.php?page=' . SBU_SLUG );
		if ( empty( $st ) ) {
			echo '<p style="color:#646970">' . esc_html__( 'Noch kein Backup hochgeladen.', 'seafile-updraft-backup-uploader' ) . '</p>';
			echo '<p><a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Einstellungen', 'seafile-updraft-backup-uploader' ) . ' &rarr;</a></p>';
			return;
		}
		$ok  = ! empty( $st['success'] );
		$col = $ok ? '#00a32a' : '#d63638';
		$ico = $ok ? "\xe2\x9c\x85" : "\xe2\x9d\x8c";
		$lbl = $ok ? __( 'Erfolgreich', 'seafile-updraft-backup-uploader' ) : __( 'Fehlgeschlagen', 'seafile-updraft-backup-uploader' );
		echo '<div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">';
		echo '<span style="font-size:28px">' . esc_html( $ico ) . '</span><div>';
		echo '<strong style="color:' . esc_attr( $col ) . ';font-size:14px">' . esc_html( $lbl ) . '</strong><br>';
		echo '<span style="color:#646970;font-size:12px">' . esc_html( $st['date'] ?? '' ) . '</span></div></div>';
		echo '<table style="width:100%;font-size:12px;color:#646970">';
		echo '<tr><td>' . esc_html__( 'Dateien:', 'seafile-updraft-backup-uploader' ) . '</td><td style="text-align:right"><strong>' . intval( $st['files'] ?? 0 ) . '</strong></td></tr>';
		echo '<tr><td>' . esc_html__( 'Größe:', 'seafile-updraft-backup-uploader' ) . '</td><td style="text-align:right"><strong>' . esc_html( number_format( $st['size_mb'] ?? 0, 1 ) ) . ' MB</strong></td></tr>';
		if ( ! empty( $st['errors'] ) ) {
			echo '<tr><td>' . esc_html__( 'Fehler:', 'seafile-updraft-backup-uploader' ) . '</td><td style="text-align:right"><strong style="color:#d63638">' . intval( $st['errors'] ) . '</strong></td></tr>';
		}
		echo '<tr><td>' . esc_html__( 'Target:', 'seafile-updraft-backup-uploader' ) . '</td><td style="text-align:right">' . esc_html( $s['lib'] . $s['folder'] ) . '</td></tr>';
		if ( ! empty( $st['dir'] ) ) {
			echo '<tr><td>' . esc_html__( 'Folder:', 'seafile-updraft-backup-uploader' ) . '</td><td style="text-align:right"><code>' . esc_html( $st['dir'] ) . '</code></td></tr>';
		}
		echo '</table>';
		echo '<p style="margin:12px 0 0"><a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Details', 'seafile-updraft-backup-uploader' ) . ' &rarr;</a></p>';
	}

	// =========================================================================
	// AJAX HANDLERS
	// =========================================================================

	/**
	 * Verify AJAX request permissions and nonce.
	 */
	private function verify_ajax_request() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Zugriff verweigert.', 'seafile-updraft-backup-uploader' ) );
		}
		$nonce = isset( $_POST['_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, SBU_NONCE ) ) {
			wp_send_json_error( __( 'Sicherheitsprüfung fehlgeschlagen. Bitte Seite neu laden.', 'seafile-updraft-backup-uploader' ) );
		}
	}


	/**
	 * Get or generate the external cron secret key.
	 *
	 * @return string 32-character secret key.
	 */
	private function get_cron_key() {
		$key = get_option( 'sbu_cron_key', '' );
		if ( empty( $key ) ) {
			$key = wp_generate_password( 32, false );
			update_option( 'sbu_cron_key', $key, false );
		}
		return $key;
	}

	/**
	 * Count invalid cron-key attempts and raise a WARNUNG once they pile up.
	 *
	 * Ein kurzlebiges Transient (1 h) zählt 403-Ablehnungen im Cron-Ping. Bei
	 * der ersten Häufung wird ein WARNUNG-Eintrag ins Aktivitätsprotokoll
	 * geschrieben — das sieht der Admin beim nächsten Login, ohne dass wir
	 * externe Alerting-Pfade brauchen. Schwelle bewusst niedrig (5 Fehl-
	 * versuche), damit ein einzelner Fehlkonfigurations-Test nicht sofort
	 * feuert, aber automatisiertes Abklopfen auffliegt.
	 */
	private function record_cron_key_failure() {
		$count = (int) get_transient( 'sbu_cron_key_fails' );
		++$count;
		set_transient( 'sbu_cron_key_fails', $count, HOUR_IN_SECONDS );
		// Flankentrigger: nur bei Überschreiten der Schwelle EINMAL loggen,
		// damit wir die Log-Zeilen nicht in einer Brute-Force-Welle fluten.
		if ( 5 === $count ) {
			$this->activity_logger->log( 'WARNUNG', 'Cron-Ping: 5 ungültige Schlüssel-Versuche in der letzten Stunde. Wenn das nicht du warst, Schlüssel über „Schlüssel rotieren" austauschen.' );
		}
	}

	/**
	 * Extract the cron-ping key from the incoming request.
	 *
	 * Prefers the `X-SBU-Cron-Key` HTTP header, falls back to the
	 * `key` query/body parameter for backwards compatibility with
	 * existing crontabs written against the legacy URL form. The
	 * header path is the recommended transport because query strings
	 * leak into reverse-proxy access logs, browser history, and shell
	 * history.
	 *
	 * @return string Raw key as supplied; empty string if nothing present.
	 */
	private function extract_cron_key_from_request() {
		if ( isset( $_SERVER['HTTP_X_SBU_CRON_KEY'] ) ) {
			return (string) wp_unslash( $_SERVER['HTTP_X_SBU_CRON_KEY'] );
		}
		if ( isset( $_REQUEST['key'] ) ) {
			return sanitize_text_field( wp_unslash( $_REQUEST['key'] ) );
		}
		return '';
	}


	/**
	 * True once a tick has burned through its adaptive budget.
	 * Extracted so callers can break out of both outer-per-file and
	 * inner-per-chunk loops with the same check.
	 *
	 * @param int $tick_start Unix timestamp when the tick began.
	 * @return bool
	 * @phpstan-impure
	 */
	private function tick_budget_exhausted( $tick_start ) {
		$lim = $this->get_adaptive_limits();
		return ( time() - $tick_start ) >= $lim['tick_time'];
	}

	/**
	 * Compute a per-queue timeout from total payload size. A 50 GB restore
	 * at 2 MB/s takes ~7 h of raw transfer; the ×1.5 factor covers tick
	 * gaps and retries. Floored at SBU_QUEUE_TIMEOUT (12 h) for small
	 * queues where the per-tick overhead dominates, capped at 24 h as a
	 * hard safety net against runaway loops.
	 *
	 * @param array $queue Queue snapshot (reads 'files[].size' or 'size_total').
	 * @return int Seconds.
	 */
	private function compute_queue_timeout( $queue ) {
		$total = (int) ( $queue['size_total'] ?? 0 );
		if ( $total <= 0 ) {
			foreach ( $queue['files'] ?? array() as $f ) {
				$total += (int) ( $f['size'] ?? 0 );
			}
		}
		if ( $total <= 0 ) {
			return (int) SBU_QUEUE_TIMEOUT;
		}
		$bps = (int) SBU_RESTORE_THROUGHPUT_BPS;
		$est = (int) ceil( ( $total / max( 1, $bps ) ) * 1.5 );
		return max( (int) SBU_QUEUE_TIMEOUT, min( $est, 86400 ) );
	}

	/** Cached adaptive limits for the current request. */
	private $adaptive_limits_cache = null;

	/**
	 * Compute per-request tick budget and download parallelism from the
	 * server's actual limits. Values scale linearly with headroom:
	 *
	 *   tick_time       = clamp(max_execution_time - 5, 20, SBU_TICK_TIME)
	 *   parallel_dl     = clamp(memory_limit / chunk_bytes / 3, 1, SBU_PARALLEL_DOWNLOADS_MAX)
	 *   chunk_mb_dl     = SBU_DOWNLOAD_CHUNK_MB_DEFAULT
	 *
	 * max_execution_time=0 (unlimited) is treated as "use the ceiling".
	 * memory_limit=-1 (unlimited) is treated as "8 parallel is plenty".
	 *
	 * The divide-by-3 for parallel downloads leaves roughly 2/3 of memory
	 * free for everything else PHP + WP need during the tick (option cache,
	 * queue snapshot, SHA1 streaming buffers, response headers).
	 *
	 * @return array{tick_time:int,parallel_downloads:int,chunk_mb_download:int}
	 */
	private function get_adaptive_limits() {
		if ( $this->adaptive_limits_cache !== null ) {
			return $this->adaptive_limits_cache;
		}
		$met                         = (int) ini_get( 'max_execution_time' );
		$mem                         = $this->get_memory_limit();
		$this->adaptive_limits_cache = self::compute_adaptive_limits( $met, $mem );
		return $this->adaptive_limits_cache;
	}

	/**
	 * Pure computation half of get_adaptive_limits(). Extracted for testability
	 * — ini_set('memory_limit', ...) is restricted in many environments, so
	 * tests pass raw values directly.
	 *
	 * @param int $met_seconds Value of ini_get('max_execution_time'). 0 = unlimited.
	 * @param int $mem_bytes   Memory limit in bytes (from get_memory_limit()). 0 = unlimited.
	 * @return array{tick_time:int,parallel_downloads:int,chunk_mb_download:int}
	 */
	public static function compute_adaptive_limits( $met_seconds, $mem_bytes ) {
		$tick_ceiling = (int) SBU_TICK_TIME;
		if ( $met_seconds <= 0 ) {
			$tick = $tick_ceiling;
		} else {
			$tick = max( 20, min( (int) $met_seconds - 5, $tick_ceiling ) );
		}

		// Chunk size scales linearly with tick budget: one chunk should
		// download in ≲ 1/5 of the tick so a batch of parallel chunks fits
		// twice over with slack. At an observed 0.3–0.6 MB/s through a
		// Cloudflare Tunnel, a 25 s tick picks 5 MB (≈16 s worst case)
		// rather than 20 MB (≈66 s — doesn't fit and SIGKILLs the worker).
		// Capped at SBU_DOWNLOAD_CHUNK_MB_DEFAULT (20 MB) because Cloudflare
		// Tunnel kills responses around 100 s regardless of server limits.
		$chunk_mb_ceiling = (int) SBU_DOWNLOAD_CHUNK_MB_DEFAULT;
		$chunk_mb_floor   = (int) SBU_DOWNLOAD_CHUNK_MB_MIN;
		$chunk_mb         = max( $chunk_mb_floor, min( (int) ceil( $tick * 0.2 ), $chunk_mb_ceiling ) );
		$chunk_bytes      = $chunk_mb * 1048576;

		$par_ceiling = (int) SBU_PARALLEL_DOWNLOADS_MAX;
		if ( $mem_bytes <= 0 ) {
			$parallel = $par_ceiling;
		} else {
			// Reserve 2/3 of memory for everything else (option cache, queue
			// snapshot, SHA1 streaming buffers, response headers). 1/3 goes
			// to the parallel chunk buffers.
			$parallel = max( 1, min( intdiv( (int) $mem_bytes, max( 1, $chunk_bytes ) * 3 ), $par_ceiling ) );
		}

		return array(
			'tick_time'          => $tick,
			'parallel_downloads' => $parallel,
			'chunk_mb_download'  => $chunk_mb,
		);
	}

	/**
	 * Exponential retry backoff in seconds, capped at 1 hour. "empty"
	 * failures (HTTP 200/206 with zero-byte body) get the harsher tier:
	 * those indicate a server-side cold backend fetch, and hammering a
	 * cold backend makes it worse. Plain transient errors (network hiccup,
	 * CF 502/504, connect timeout) get the gentler tier — those usually
	 * clear themselves in seconds to minutes.
	 *
	 * Tiers:
	 *   - empty:      60 → 300 → 900 → 1800 → 3600 s
	 *   - transient:  60 → 120 → 240 → 480 → 960 → 1920 → 3600 s
	 *
	 * @param int    $attempt 1-indexed attempt count.
	 * @param string $kind    'empty' for 0-byte responses; anything else
	 *                        treated as plain transient.
	 * @return int seconds (min 60, max 3600).
	 */
	public static function compute_retry_delay( int $attempt, string $kind = 'transient' ): int {
		$attempt = max( 1, $attempt );
		if ( $kind === 'empty' ) {
			$tiers = array( 60, 300, 900, 1800, 3600 );
		} else {
			$tiers = array( 60, 120, 240, 480, 960, 1920, 3600 );
		}
		$idx = min( $attempt - 1, count( $tiers ) - 1 );
		return (int) $tiers[ $idx ];
	}

	/**
	 * Classify a chunk-level download result into a coarse error class.
	 *
	 * Why classes and not just "error vs ok": different failures need
	 * different reactions. A 401 means the token died (refresh, don't
	 * bump the retry counter — the file itself is fine). A 403 on a
	 * signed Seafile URL means the URL expired (refresh the link, same
	 * story). A 404 is permanent (no point retrying). A cURL 28 or
	 * empty reply is the classic transient Cloudflare-Tunnel drop (bump
	 * the counter, back off, halve the chunk size). "Deadline" is our
	 * own cancel signal from the pump and must NOT count as an error at
	 * all — it just means "this chunk got caught by the tick ending".
	 *
	 * @param array $result Single entry from SBU_Seafile_API::download_chunks_parallel().
	 * @return string One of: ok | transient | auth | signed_url | client | overload | deadline
	 */
	public static function classify_chunk_error( array $result ) {
		if ( ! empty( $result['ok'] ) ) {
			return 'ok';
		}
		$err  = $result['error'] ?? null;
		$code = ( $err instanceof \WP_Error ) ? $err->get_error_code() : '';
		$http = (int) ( $result['code'] ?? 0 );

		if ( $code === 'deadline' ) {
			return 'deadline';
		}
		if ( $http === 401 ) {
			return 'auth';
		}
		if ( $http === 403 ) {
			// Seafile signed URLs expire / are single-use. In this plugin's
			// flow that's not a real auth failure — the caller just fetches
			// a fresh link and retries. Distinct from 401 so we don't bump
			// the retry counter on an expired signed URL.
			return 'signed_url';
		}
		if ( $http === 429 || $http === 503 ) {
			return 'overload';
		}
		if ( in_array( $http, array( 400, 404, 416 ), true ) ) {
			return 'client';
		}
		// cURL errno 28 (timeout, incl. LOW_SPEED_TIME stall), 7 (connect),
		// 52 (empty reply), 35 (SSL), 502/504, 0-byte body — all transient.
		return 'transient';
	}

	/**
	 * Pure AIMD rate controller. Takes the current rate_state plus the
	 * outcome of the last batch, returns the next rate_state.
	 *
	 * Inspired by TCP congestion control (Additive Increase, Multiplicative
	 * Decrease): good batches ramp up slowly, a single bad batch halves
	 * the transfer footprint, two bad in a row trip the "emergency" mode
	 * which drops to 1 chunk × 2 MB serial — the smallest possible load
	 * on a fragile Cloudflare Tunnel. Recovery from emergency is staged
	 * through a "slow" mode so we don't oscillate straight back into
	 * failure.
	 *
	 * Pure function: no side effects, deterministic, fully unit-testable.
	 *
	 * @param array $state    ['chunk_mb' => int, 'parallel' => int,
	 *                        'consecutive_bad' => int, 'mode' => 'cruise'|'slow'|'emergency']
	 * @param array $outcome  ['ok' => bool, 'any_transient' => bool]
	 * @param array $ceilings ['chunk_mb_max' => int, 'parallel_max' => int]
	 * @return array New rate_state with the same shape as $state.
	 */
	public static function update_rate_state( array $state, array $outcome, array $ceilings ) {
		$chunk_max = max( 2, (int) ( $ceilings['chunk_mb_max'] ?? 20 ) );
		$par_max   = max( 1, (int) ( $ceilings['parallel_max'] ?? 4 ) );

		$chunk_mb = max( 2, min( (int) ( $state['chunk_mb'] ?? $chunk_max ), $chunk_max ) );
		$parallel = max( 1, min( (int) ( $state['parallel'] ?? $par_max ), $par_max ) );
		$bad      = max( 0, (int) ( $state['consecutive_bad'] ?? 0 ) );
		$mode     = in_array( $state['mode'] ?? '', array( 'cruise', 'slow', 'emergency' ), true )
			? $state['mode'] : 'cruise';

		$is_good = ! empty( $outcome['ok'] ) && empty( $outcome['any_transient'] );

		if ( $is_good ) {
			$bad = 0;
			if ( $mode === 'emergency' ) {
				// Staged recovery: emergency -> slow -> cruise. Never jump
				// straight back to full throughput — that's what got us
				// killed in the first place.
				$mode     = 'slow';
				$chunk_mb = min( $chunk_max, max( 2, (int) ceil( $chunk_mb * 1.5 ) ) );
				$parallel = min( $par_max, $parallel + 1 );
			} elseif ( $mode === 'slow' ) {
				$chunk_mb = min( $chunk_max, max( 2, (int) ceil( $chunk_mb * 1.25 ) ) );
				$parallel = min( $par_max, $parallel + 1 );
				if ( $chunk_mb >= $chunk_max && $parallel >= $par_max ) {
					$mode = 'cruise';
				}
			} else { // cruise
				// Additive increase: gentle 10 % bump, capped. Once we're
				// at the ceiling, stay there — no point oscillating.
				$next     = max( $chunk_mb + 1, (int) ceil( $chunk_mb * 1.1 ) );
				$chunk_mb = min( $chunk_max, $next );
			}
		} else {
			++$bad;
			if ( $bad >= 2 ) {
				// Second strike: emergency. Minimum possible load.
				$mode     = 'emergency';
				$chunk_mb = 2;
				$parallel = 1;
			} else {
				// First strike: multiplicative decrease. Halve both.
				$mode     = 'slow';
				$chunk_mb = max( 2, (int) floor( $chunk_mb / 2 ) );
				$parallel = max( 1, (int) floor( $parallel / 2 ) );
			}
		}

		return array(
			'chunk_mb'        => $chunk_mb,
			'parallel'        => $parallel,
			'consecutive_bad' => $bad,
			'mode'            => $mode,
		);
	}

	/**
	 * Update SBU_QUEUE without clobbering a terminal status written
	 * by a concurrent request.
	 *
	 * A running tick holds an in-memory copy of the queue whose `status`
	 * field is stale the moment another request (ajax_abort_upload, a
	 * fresh on_backup_complete, finish_queue) flips the DB status to a
	 * terminal state. A naive update_option() with the stale 'uploading'
	 * status silently resurrects an aborted queue. This helper re-reads
	 * the DB, and if it finds a terminal status, preserves it while
	 * merging the caller's progress fields.
	 *
	 * @param array $queue In-memory queue snapshot to persist.
	 * @return string Effective status after the write. A return of
	 *                'aborted' | 'error' | 'done' means the caller must
	 *                stop scheduling further work.
	 */
	private function safe_queue_update( array $queue ) {
		wp_cache_delete( SBU_QUEUE, 'options' );
		$fresh = get_option( SBU_QUEUE );
		if ( is_array( $fresh ) ) {
			$current = $fresh['status'] ?? '';
			if ( in_array( $current, array( 'aborted', 'paused', 'error', 'done' ), true ) ) {
				foreach ( array( 'files', 'file_idx', 'ok', 'err', 'total_bytes' ) as $k ) {
					if ( array_key_exists( $k, $queue ) ) {
						$fresh[ $k ] = $queue[ $k ];
					}
				}
				$fresh['last_activity'] = time();
				update_option( SBU_QUEUE, $fresh, false );
				return $current;
			}
		}
		update_option( SBU_QUEUE, $queue, false );
		return $queue['status'] ?? '';
	}

	/**
	 * Detect whether the previous tick died silently (PHP killed by
	 * hosting, OOM, Cloudflare 524 on the worker path). A stale queue
	 * that is NOT in a planned backoff window implies a crash.
	 *
	 * Logs one WARNUNG per detected crash so the user has a clear trail
	 * in the activity log instead of an unexplained multi-minute
	 * silence. Also bumps the file's retry counter, installs a backoff
	 * gate, persists the queue, and reschedules the next tick. The
	 * caller should return immediately when this function returns true,
	 * deferring actual work to the scheduled tick.
	 *
	 * @param array $queue In-memory queue snapshot.
	 * @return bool True if a crash was detected and backoff installed.
	 */
	private function detect_worker_crash_and_defer( array $queue ) {
		$last = (int) ( $queue['last_activity'] ?? 0 );
		$gate = (int) ( $queue['next_allowed_tick_ts'] ?? 0 );
		if ( $last <= 0 || $gate > time() ) {
			return false;
		}
		$idle      = time() - $last;
		$threshold = $this->queue_engine->default_lock_ttl() + 30;
		if ( $idle <= $threshold ) {
			return false;
		}

		$idx  = (int) ( $queue['file_idx'] ?? 0 );
		$file = $queue['files'][ $idx ] ?? array();
		$fn   = $file['path'] ?? '';
		$fn   = $fn ? basename( $fn ) : ( $file['name'] ?? '?' );
		$off  = round( ( $file['offset'] ?? 0 ) / 1024 / 1024, 1 );
		$mins = round( $idle / 60, 1 );

		$this->activity_logger->log(
			'WARNUNG',
			sprintf(
				/* translators: %1$s minutes since last activity, %2$s file name, %3$s offset in MB */
				__( 'Worker still abgestürzt vor %1$s min — Wiederaufnahme bei %2$s @ %3$s MB', 'seafile-updraft-backup-uploader' ),
				$mins,
				$fn,
				$off
			)
		);

		$retries = ( $queue['files'][ $idx ]['retries'] ?? 0 ) + 1;
		$delay   = min( $retries * 60, 600 );
		if ( isset( $queue['files'][ $idx ] ) ) {
			$queue['files'][ $idx ]['retries'] = $retries;
		}
		$queue['next_allowed_tick_ts'] = time() + $delay;
		$queue['last_activity']        = time();
		update_option( SBU_QUEUE, $queue, false );
		$this->queue_engine->schedule_next_tick( $delay );
		return true;
	}

	/**
	 * Email the admin once when the current file hasn't gained any bytes
	 * for an hour. Keeps running — this is a signal, not an abort. A long
	 * stall can still resolve (the Seafile fileserver warms up, the object
	 * storage backend recovers) so the queue is allowed to keep trying
	 * with the exponential backoff in {@see self::compute_retry_delay()}.
	 *
	 * State lives on the queue:
	 *   - `stall_offset`      last observed byte offset on the current file
	 *   - `stall_since_ts`    wall-clock when that offset was first seen
	 *   - `stall_notified_ts` wall-clock of the last mail (0 = never)
	 *
	 * When the offset changes (progress was made), the state resets. The
	 * re-notify interval is 4 h so a multi-hour stall doesn't spam.
	 *
	 * @param array $queue Current in-memory queue snapshot.
	 * @return array Possibly-mutated queue (caller must persist).
	 */
	private function maybe_notify_stall( array $queue ) {
		if ( ( $queue['status'] ?? '' ) !== 'restoring' ) {
			return $queue;
		}
		$idx   = (int) ( $queue['file_idx'] ?? 0 );
		$files = $queue['files'] ?? array();
		if ( ! isset( $files[ $idx ] ) ) {
			return $queue;
		}
		$file = $files[ $idx ];
		$fn   = $file['name'] ?? ( basename( $file['path'] ?? '' ) ?: '?' );
		$fs   = (int) ( $file['size'] ?? 0 );
		$off  = (int) ( $file['offset'] ?? 0 );

		$last_offset = array_key_exists( 'stall_offset', $queue ) ? (int) $queue['stall_offset'] : -1;
		if ( $off !== $last_offset ) {
			// Progress (or a new file). Reset the stall window.
			$queue['stall_offset']      = $off;
			$queue['stall_since_ts']    = time();
			$queue['stall_notified_ts'] = 0;
			return $queue;
		}
		$since = (int) ( $queue['stall_since_ts'] ?? time() );
		$dur   = time() - $since;
		if ( $dur < 3600 ) {
			return $queue;
		}
		$last_notified = (int) ( $queue['stall_notified_ts'] ?? 0 );
		if ( $last_notified > 0 && ( time() - $last_notified ) < 4 * 3600 ) {
			return $queue;
		}

		$hrs_stalled = round( $dur / 3600, 1 );
		$progress    = $fs > 0 ? $this->format_progress( $off, $fs ) : ( round( $off / 1048576, 1 ) . ' MB' );
		$msg         = sprintf(
			/* translators: %1$s filename, %2$s progress string, %3$s hours stalled */
			__( 'Wiederherstellung hängt bei %1$s (%2$s) seit %3$s Stunden. Das Plugin versucht weiter mit längeren Pausen — es gibt NICHT auf. Bitte Log prüfen.', 'seafile-updraft-backup-uploader' ),
			$fn,
			$progress,
			$hrs_stalled
		);
		$this->mail_notifier->send( false, $msg );
		$this->activity_logger->log(
			'WARNUNG',
			sprintf(
				/* translators: %1$s filename, %2$s progress string, %3$s hours stalled */
				__( 'Stillstand-Meldung per Mail gesendet: %1$s hängt bei %2$s seit %3$sh', 'seafile-updraft-backup-uploader' ),
				$fn,
				$progress,
				$hrs_stalled
			)
		);
		$queue['stall_notified_ts'] = time();
		return $queue;
	}

	/**
	 * Return a human-readable progress stub for chunk-level logging:
	 * `"at 45.0 MB / 194.1 MB (23%)"`.
	 *
	 * @param int $offset Current byte offset.
	 * @param int $size   Total file size in bytes.
	 * @return string
	 */
	private function format_progress( $offset, $size ) {
		$off_mb = round( $offset / 1024 / 1024, 1 );
		$tot_mb = round( $size / 1024 / 1024, 1 );
		$pct    = $size > 0 ? (int) round( $offset / $size * 100 ) : 0;
		return "{$off_mb} MB / {$tot_mb} MB ({$pct}%)";
	}


	/**
	 * Extract Seafile credentials from POST form values or saved settings.
	 *
	 * @return array{url: string, user: string, pass: string}
	 */
	private function get_picker_credentials() {
		$s        = $this->get_settings();
		$dots     = str_repeat( "\xe2\x80\xa2", 8 );
		$url      = ! empty( $_POST['sbu_url'] ) ? esc_url_raw( rtrim( trim( wp_unslash( $_POST['sbu_url'] ) ), '/' ) ) : $s['url'];
		$user     = ! empty( $_POST['sbu_user'] ) ? sanitize_email( wp_unslash( $_POST['sbu_user'] ) ) : $s['user'];
		$raw_pass = isset( $_POST['sbu_pass'] ) ? wp_unslash( $_POST['sbu_pass'] ) : '';
		$pass     = ( ! empty( $raw_pass ) && $raw_pass !== $dots ) ? $raw_pass : SBU_Crypto::decrypt( $s['pass'] );
		return array(
			'url'  => $url,
			'user' => $user,
			'pass' => $pass,
		);
	}



	/**
	 * WP-Cron handler.
	 */
	public function cron_process_queue() {
		if ( $this->queue_engine->tick_is_gated() ) {
			return;
		}
		if ( ! $this->queue_engine->acquire_lock( $this->queue_engine->default_lock_ttl() ) ) {
			return;
		}
		try {
			$this->process_queue_tick();
		} finally {
			$this->queue_engine->release_lock();
		}
	}

	/**
	 * Admin fallback: resume stalled queue.
	 */
	public function check_stalled_queue() {
		$queue = get_option( SBU_QUEUE );
		if ( ! $queue || ! in_array( $queue['status'] ?? '', array( 'uploading', 'restoring' ), true ) ) {
			return;
		}
		if ( ( time() - ( $queue['last_activity'] ?? 0 ) ) < 90 ) {
			return;
		}
		if ( $this->queue_engine->tick_is_gated() ) {
			return;
		}

		// Atomic acquire prevents concurrent ticks from stepping on each other.
		if ( ! $this->queue_engine->acquire_lock( $this->queue_engine->default_lock_ttl() ) ) {
			return;
		}

		try {
			$this->activity_logger->log( 'INFO', __( 'Queue fortgesetzt (Admin-Fallback)', 'seafile-updraft-backup-uploader' ) );
			$this->process_queue_tick();
		} finally {
			$this->queue_engine->release_lock();
		}
	}



	/**
	 * Check if abort has been requested (via DB option or transient).
	 *
	 * Reads mutable DB state — never memoize across calls.
	 *
	 * @phpstan-impure
	 */
	private function is_aborted() {
		wp_cache_delete( 'sbu_abort_ts', 'options' );
		$abort_ts = (int) get_option( 'sbu_abort_ts', 0 );
		if ( $abort_ts > 0 && ( time() - $abort_ts ) < 120 ) {
			return true;
		}

		wp_cache_delete( 'sbu_abort_flag', 'transient' );
		wp_cache_delete( '_transient_sbu_abort_flag', 'options' );
		if ( get_transient( 'sbu_abort_flag' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Public shim for the shutdown safety net. Same semantics as
	 * spawn_next_tick(), exposed so the shutdown closure doesn't have to
	 * reach past class visibility.
	 *
	 * @internal
	 */
	public function spawn_next_tick_public() {
		$this->queue_engine->spawn_next_tick();
	}

	/**
	 * Public shim for the shutdown safety net — see release_queue_lock().
	 *
	 * @internal
	 */
	public function release_queue_lock_public() {
		$this->queue_engine->release_lock();
	}


	// =========================================================================
	// HELPERS
	// =========================================================================

	/**
	 * Get the UpdraftPlus backup directory path.
	 *
	 * @return string|null Directory path or null.
	 */
	private function get_updraft_dir() {
		if ( class_exists( 'UpdraftPlus' ) ) {
			global $updraftplus;
			if ( $updraftplus && method_exists( $updraftplus, 'backups_dir_location' ) ) {
				return $updraftplus->backups_dir_location();
			}
		}
		$d = WP_CONTENT_DIR . '/updraft';
		return is_dir( $d ) ? $d : null;
	}


	/**
	 * Sanitize a single path segment (no slashes, no null bytes).
	 *
	 * @param string $input Raw input.
	 * @return string Sanitized segment.
	 */
	private function sanitize_path_segment( $input ) {
		$clean = sanitize_file_name( $input );
		return preg_replace( '/[\/\\\\\x00]/', '', $clean );
	}

	/**
	 * Get PHP memory limit in bytes.
	 *
	 * @return int Memory limit (0 = unlimited).
	 */
	private function get_memory_limit() {
		$l = ini_get( 'memory_limit' );
		if ( $l === '-1' ) {
			return 0;
		}
		$v = intval( $l );
		$u = strtoupper( substr( $l, -1 ) );
		if ( $u === 'G' ) {
			$v *= 1073741824;
		} elseif ( $u === 'M' ) {
			$v *= 1048576;
		} elseif ( $u === 'K' ) {
			$v *= 1024;
		}
		return $v;
	}

	/**
	 * Log a summary of failed files from a queue.
	 */
	private function log_failed_files( $queue, $context = 'Upload' ) {
		$failed = array();
		foreach ( $queue['files'] ?? array() as $fi ) {
			if ( ( $fi['status'] ?? '' ) === 'error' ) {
				$fn       = basename( $fi['path'] ?? $fi['name'] ?? '?' );
				$mb       = round( ( $fi['size'] ?? 0 ) / 1024 / 1024, 1 );
				$failed[] = "{$fn} ({$mb} MB)";
			}
		}
		if ( ! empty( $failed ) ) {
			$this->activity_logger->log( 'INFO', "{$context}: fehlgeschlagene Dateien: " . implode( ', ', $failed ) );
		}
	}
}
