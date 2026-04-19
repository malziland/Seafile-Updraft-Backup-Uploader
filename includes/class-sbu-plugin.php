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
		foreach ( array( 'test', 'upload', 'list', 'download', 'download_all', 'delete', 'get_log', 'export_log', 'export_log_anon', 'clear_log', 'upload_status', 'load_libs', 'load_dirs', 'create_dir', 'save_settings', 'reset_settings', 'refresh_nonce', 'abort_upload', 'pause_upload', 'resume_upload', 'kick', 'dismiss_restore_banner', 'rotate_cron_key' ) as $a ) {
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
	 * AJAX: Test Seafile connection and settings.
	 *
	 * @return void Sends JSON response.
	 */
	public function ajax_test() {
		$this->verify_ajax_request();
		$creds  = $this->get_picker_credentials();
		$s      = $this->get_settings();
		$lib    = ! empty( $_POST['sbu_lib'] ) ? sanitize_text_field( wp_unslash( $_POST['sbu_lib'] ) ) : $s['lib'];
		$folder = ! empty( $_POST['sbu_folder'] ) ? '/' . trim( sanitize_text_field( wp_unslash( $_POST['sbu_folder'] ) ), '/ ' ) : $s['folder'];
		$o      = array();

		// Debug: track password source. Passwords pass 1:1 to Seafile — sanitize_text_field
		// would strip tabs/whitespace and break credentials with those characters (see BUG-001).
		$dots     = str_repeat( "\xe2\x80\xa2", 8 );
		$raw_post = isset( $_POST['sbu_pass'] ) ? (string) wp_unslash( $_POST['sbu_pass'] ) : '';
		if ( ! empty( $raw_post ) && $raw_post !== $dots ) {
			/* translators: %d is the number of characters in the submitted password */
			$pass_source = sprintf( __( 'Formular (Länge: %d)', 'seafile-updraft-backup-uploader' ), strlen( $creds['pass'] ) );
		} else {
			$db_enc = $s['pass'] ?? '';
			$db_dec = SBU_Crypto::decrypt( $db_enc );
			/* translators: %1$d is encrypted-byte count, %2$d is decrypted-character count */
			$pass_source = sprintf( __( 'Datenbank (verschlüsselt: %1$d Bytes, entschlüsselt: %2$d Zeichen)', 'seafile-updraft-backup-uploader' ), strlen( $db_enc ), strlen( $db_dec ) );
		}

		// Always force fresh token to verify actual credentials (no cache)
		delete_transient( SBU_TOK );
		$t = SBU_Seafile_API::get_token( $creds['url'], $creds['user'], $creds['pass'], true );
		if ( is_wp_error( $t ) ) {
			/* translators: %1$s is the upstream error message, %2$s is where the password came from (form/database) */
			wp_send_json_error( sprintf( __( "Authentifizierung fehlgeschlagen: %1\$s\nPasswort-Quelle: %2\$s", 'seafile-updraft-backup-uploader' ), $t->get_error_message(), $pass_source ) );
		}
		/* translators: %s is where the password came from (form/database) */
		$o[] = sprintf( __( '✓ Authentifizierung erfolgreich (%s)', 'seafile-updraft-backup-uploader' ), $pass_source );

		if ( $lib ) {
			$r = SBU_Seafile_API::find_library( $creds['url'], $t, $lib );
			if ( is_wp_error( $r ) ) {
				/* translators: %s is the upstream error message */
				wp_send_json_error( implode( "\n", $o ) . "\n" . sprintf( __( '✗ Bibliothek: %s', 'seafile-updraft-backup-uploader' ), $r->get_error_message() ) );
			}
			/* translators: %s is the Seafile library name */
			$o[] = sprintf( __( "✓ Bibliothek '%s' gefunden", 'seafile-updraft-backup-uploader' ), $lib );

			$l = SBU_Seafile_API::get_upload_link( $creds['url'], $t, $r, $folder );
			if ( is_wp_error( $l ) ) {
				/* translators: %s is the upstream error message */
				wp_send_json_error( implode( "\n", $o ) . "\n" . sprintf( __( '✗ Upload-Link: %s', 'seafile-updraft-backup-uploader' ), $l->get_error_message() ) );
			}
			$o[] = __( '✓ Upload-Link erhalten', 'seafile-updraft-backup-uploader' );
		}

		$o[]             = '';
		$o[]             = __( 'Verbindung erfolgreich!', 'seafile-updraft-backup-uploader' );
		$retention_label = $s['retention'] > 0 ? (string) $s['retention'] : __( 'unbegrenzt', 'seafile-updraft-backup-uploader' );
		/* translators: %1$d is chunk size in MB, %2$s is retention value or "unbegrenzt" */
		$o[] = sprintf( __( '  Chunk: %1$d MB | Aufbewahrung: %2$s', 'seafile-updraft-backup-uploader' ), $s['chunk'], $retention_label );
		if ( $lib ) {
			/* translators: %s is the full target path (library + folder) */
			$o[] = sprintf( __( '  Ziel: %s/YYYY-MM-DD_HHMM/', 'seafile-updraft-backup-uploader' ), $lib . $folder );
		}

		// Auto-save credentials on successful test
		$save         = $s;
		$save['url']  = $creds['url'];
		$save['user'] = $creds['user'];
		if ( $creds['pass'] !== '' ) {
			$save['pass'] = SBU_Crypto::encrypt( $creds['pass'] );
		}
		if ( $lib ) {
			$save['lib'] = $lib;
		}
		if ( $folder ) {
			$save['folder'] = $folder;
		}
		update_option( SBU_OPT, $save );
		delete_transient( SBU_TOK );
		$o[] = __( '  ✓ Einstellungen gespeichert', 'seafile-updraft-backup-uploader' );

		$this->activity_logger->log( 'TEST', __( 'Verbindungstest erfolgreich', 'seafile-updraft-backup-uploader' ) . ( $lib ? ' → ' . $lib . $folder : '' ) );
		wp_send_json_success( implode( "\n", $o ) );
	}

	/**
	 * AJAX: Trigger manual backup upload to Seafile.
	 *
	 * @return void Sends JSON response.
	 */
	public function ajax_upload() {
		$this->verify_ajax_request();
		delete_option( 'sbu_abort_ts' );
		delete_transient( 'sbu_abort_flag' );
		$existing = get_option( SBU_QUEUE );
		if ( $existing && in_array( $existing['status'] ?? '', array( 'uploading', 'restoring' ), true ) ) {
			wp_send_json_error( __( 'Ein Upload oder Download läuft bereits. Bitte warten oder abbrechen.', 'seafile-updraft-backup-uploader' ) );
		}
		$result = $this->create_upload_queue();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}
		wp_send_json_success( $result );
	}

	/**
	 * AJAX: Abort a running upload queue.
	 */
	public function ajax_abort_upload() {
		$this->verify_ajax_request();

		// Write abort timestamp to a SEPARATE option (can't be overwritten by queue updates)
		update_option( 'sbu_abort_ts', time(), false );

		// Also set transient flag
		set_transient( 'sbu_abort_flag', true, 300 );

		wp_cache_delete( SBU_QUEUE, 'options' );
		$queue = get_option( SBU_QUEUE );
		if ( $queue && in_array( $queue['status'], array( 'uploading', 'restoring', 'error' ), true ) ) {
			$queue['status'] = 'aborted';
			update_option( SBU_QUEUE, $queue, false );
		}
		// Clear scheduled WP-Cron tick. Do NOT release the queue lock here —
		// a tick may still be running and will release its own lock on exit.
		// Releasing it from here would let a parallel tick start immediately
		// and race against the terminated tick's stale mid-loop writes.
		wp_clear_scheduled_hook( SBU_CRON_HOOK );
		delete_transient( 'sbu_progress' );
		$this->activity_logger->log( 'INFO', __( 'Vorgang manuell abgebrochen', 'seafile-updraft-backup-uploader' ) );
		wp_send_json_success( 'ok' );
	}

	/**
	 * AJAX: Pause a running upload queue. Preserves the offset so a later
	 * Resume picks up on the same chunk. Unlike Abort, the queue is kept.
	 */
	public function ajax_pause_upload() {
		$this->verify_ajax_request();

		wp_cache_delete( SBU_QUEUE, 'options' );
		$queue = get_option( SBU_QUEUE );
		if ( ! is_array( $queue ) || ! in_array( $queue['status'] ?? '', array( 'uploading', 'restoring' ), true ) ) {
			wp_send_json_error( __( 'Kein laufender Vorgang zum Pausieren gefunden.', 'seafile-updraft-backup-uploader' ) );
		}

		$queue['status']    = 'paused';
		$queue['paused_ts'] = time();
		// Prevent any scheduled/loopback tick from restarting work until Resume.
		$queue['next_allowed_tick_ts'] = time() + YEAR_IN_SECONDS;
		update_option( SBU_QUEUE, $queue, false );

		wp_clear_scheduled_hook( SBU_CRON_HOOK );

		$idx = (int) ( $queue['file_idx'] ?? 0 );
		$fi  = $queue['files'][ $idx ] ?? null;
		if ( $fi ) {
			$fn     = basename( $fi['path'] );
			$offset = (int) ( $fi['offset'] ?? 0 );
			$size   = (int) ( $fi['size'] ?? 0 );
			$this->activity_logger->log(
				'INFO',
				sprintf(
					/* translators: %1$s file, %2$s progress */
					__( 'Upload pausiert: %1$s bei %2$s', 'seafile-updraft-backup-uploader' ),
					$fn,
					$this->format_progress( $offset, $size )
				)
			);
		} else {
			$this->activity_logger->log( 'INFO', __( 'Upload pausiert', 'seafile-updraft-backup-uploader' ) );
		}

		wp_send_json_success( 'ok' );
	}

	/**
	 * AJAX: Resume a paused upload queue. Flips status back to uploading/
	 * restoring, clears the gate, and kicks the next tick so work restarts
	 * immediately.
	 */
	public function ajax_resume_upload() {
		$this->verify_ajax_request();

		wp_cache_delete( SBU_QUEUE, 'options' );
		$queue = get_option( SBU_QUEUE );
		if ( ! is_array( $queue ) || ( $queue['status'] ?? '' ) !== 'paused' ) {
			wp_send_json_error( __( 'Kein pausierter Vorgang gefunden.', 'seafile-updraft-backup-uploader' ) );
		}

		$is_restore             = ! empty( $queue['restore'] ) || isset( $queue['dir'] );
		$queue['status']        = $is_restore ? 'restoring' : 'uploading';
		$queue['last_activity'] = time();
		unset( $queue['paused_ts'], $queue['next_allowed_tick_ts'], $queue['next_retry_delay'] );
		update_option( SBU_QUEUE, $queue, false );

		$idx = (int) ( $queue['file_idx'] ?? 0 );
		$fi  = $queue['files'][ $idx ] ?? null;
		if ( $fi ) {
			$fn     = basename( $fi['path'] );
			$offset = (int) ( $fi['offset'] ?? 0 );
			$size   = (int) ( $fi['size'] ?? 0 );
			$this->activity_logger->log(
				'INFO',
				sprintf(
					/* translators: %1$s file, %2$s progress */
					__( 'Upload fortgesetzt: %1$s ab %2$s', 'seafile-updraft-backup-uploader' ),
					$fn,
					$this->format_progress( $offset, $size )
				)
			);
		} else {
			$this->activity_logger->log( 'INFO', __( 'Upload fortgesetzt', 'seafile-updraft-backup-uploader' ) );
		}

		$this->queue_engine->schedule_next_tick( 5 );
		$this->queue_engine->spawn_next_tick();
		wp_send_json_success( 'ok' );
	}


	/**
	 * AJAX: List backup folders on Seafile.
	 *
	 * @return void Sends JSON response with HTML.
	 */
	public function ajax_list() {
		$this->verify_ajax_request();
		$s = $this->get_settings();
		// Formularwerte bevorzugen (wie bei Test)
		$dots = str_repeat( "\xe2\x80\xa2", 8 );
		$url  = ! empty( $_POST['sbu_url'] ) ? sanitize_url( wp_unslash( $_POST['sbu_url'] ) ) : $s['url'];
		$user = ! empty( $_POST['sbu_user'] ) ? sanitize_email( wp_unslash( $_POST['sbu_user'] ) ) : $s['user'];
		// Passwort ohne sanitize_text_field: die Funktion strippt Tags, Tabs
		// und Mehrfach-Leerzeichen und würde Passwörter mit solchen Zeichen
		// verfälschen. Unslash reicht; das Passwort geht 1:1 an Seafile.
		$raw_pass = isset( $_POST['sbu_pass'] ) ? (string) wp_unslash( $_POST['sbu_pass'] ) : '';
		$pass     = ( $raw_pass !== '' && $raw_pass !== $dots ) ? $raw_pass : SBU_Crypto::decrypt( $s['pass'] );
		$lib      = ! empty( $_POST['sbu_lib'] ) ? sanitize_text_field( wp_unslash( $_POST['sbu_lib'] ) ) : $s['lib'];
		$folder   = ! empty( $_POST['sbu_folder'] ) ? sanitize_text_field( wp_unslash( $_POST['sbu_folder'] ) ) : $s['folder'];

		$t = SBU_Seafile_API::get_token( $url, $user, $pass );
		if ( is_wp_error( $t ) ) {
			wp_send_json_error( __( 'Authentifizierung fehlgeschlagen.', 'seafile-updraft-backup-uploader' ) );
		}
		$r = SBU_Seafile_API::find_library( $url, $t, $lib );
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( __( 'Bibliothek nicht gefunden.', 'seafile-updraft-backup-uploader' ) );
		}
		$items = SBU_Seafile_API::list_directory( $url, $t, $r, $folder );
		if ( is_wp_error( $items ) ) {
			// Token-Refresh Retry
			$t = SBU_Seafile_API::get_token( $url, $user, $pass, true );
			if ( ! is_wp_error( $t ) ) {
				$items = SBU_Seafile_API::list_directory( $url, $t, $r, $folder );
			}
		}
		if ( is_wp_error( $items ) ) {
			wp_send_json_error( __( 'Verzeichnis nicht lesbar.', 'seafile-updraft-backup-uploader' ) );
		}

		$dirs = array();
		foreach ( $items as $it ) {
			if ( ( $it['type'] ?? '' ) === 'dir' ) {
				$dirs[] = $it['name'];
			}
		}
		if ( empty( $dirs ) ) {
			wp_send_json_success( '<p style="color:#646970">' . __( 'Keine Backups auf Seafile gefunden.', 'seafile-updraft-backup-uploader' ) . '</p>' );
		}
		rsort( $dirs );

		$h            = '';
		$idx          = 0;
		$verified     = get_option( 'sbu_verified', array() );
		$active_queue = get_option( SBU_QUEUE );
		$active_ts    = ( ! empty( $active_queue['status'] ) && $active_queue['status'] === 'uploading' ) ? ( $active_queue['ts'] ?? '' ) : '';
		$updraft_dir  = $this->get_updraft_dir();
		$up_url       = admin_url( 'options-general.php?page=updraftplus' );
		foreach ( $dirs as $dn ) {
			++$idx;
			$dp          = rtrim( $folder, '/' ) . '/' . $dn;
			$files       = SBU_Seafile_API::list_directory( $url, $t, $r, $dp );
			$fc          = 0;
			$ts          = 0;
			$types       = array();
			$local_ok    = 0;
			$local_stale = 0;
			$local_total = 0;
			if ( ! is_wp_error( $files ) ) {
				foreach ( $files as $f ) {
					if ( ( $f['type'] ?? 'file' ) === 'file' ) {
						++$fc;
						$fsize = (int) ( $f['size'] ?? 0 );
						$ts   += $fsize;
						if ( preg_match( '/-(plugins|themes|uploads|others|db)\d*\./', $f['name'], $tm ) ) {
							$types[ $tm[1] ] = true;
						}
						++$local_total;
						if ( $updraft_dir ) {
							$local_path = $updraft_dir . '/' . $f['name'];
							if ( file_exists( $local_path ) ) {
								$lsz = (int) @filesize( $local_path );
								if ( $lsz === $fsize ) {
									++$local_ok;
								} else {
									++$local_stale;
								}
							}
						}
					}
				}
			}
			$mb = round( $ts / 1024 / 1024, 1 );

			// Lokal-Status bestimmen: vollständig / teilweise / nur remote.
			if ( $local_total > 0 && $local_ok === $local_total ) {
				$local_state = 'full';
			} elseif ( $local_ok > 0 || $local_stale > 0 ) {
				$local_state = 'partial';
			} else {
				$local_state = 'remote';
			}

			$nice = $dn;
			if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})_(\d{2})(\d{2})/', $dn, $m ) ) {
				$nice = "{$m[3]}.{$m[2]}.{$m[1]} {$m[4]}:{$m[5]}";
			}

			// Content type badges
			$badges      = '';
			$type_labels = array(
				'db'      => 'DB',
				'plugins' => 'Plugins',
				'themes'  => 'Themes',
				'uploads' => 'Uploads',
				'others'  => __( 'Andere', 'seafile-updraft-backup-uploader' ),
			);
			foreach ( $type_labels as $tk => $tl ) {
				if ( isset( $types[ $tk ] ) ) {
					$badges .= '<span class="sbu-badge sbu-badge-' . $tk . '">' . $tl . '</span> ';
				}
			}

			$h .= '<div class="sbu-bk">';
			$h .= '<div class="sbu-bk-head">';
			$h .= '<div class="sbu-bk-row1">';
			$h .= '<strong class="sbu-bk-date">' . esc_html( $nice ) . '</strong>';
			$h .= '<span class="sbu-bk-meta">' . $fc . ' ' . __( 'Dateien', 'seafile-updraft-backup-uploader' ) . ' &middot; ' . $mb . ' MB</span>';
			if ( $local_state === 'full' ) {
				$h .= '<span class="sbu-bk-local sbu-bk-local-yes" title="' . esc_attr__( 'Alle Dateien dieses Backups liegen lokal im UpdraftPlus-Ordner vor.', 'seafile-updraft-backup-uploader' ) . '">&#10003; ' . esc_html__( 'Lokal vollständig', 'seafile-updraft-backup-uploader' ) . '</span>';
			} elseif ( $local_state === 'partial' ) {
				$tip = sprintf(
					/* translators: %1$d local file count, %2$d total file count */
					__( '%1$d von %2$d Dateien lokal vorhanden — beim Wiederherstellen werden nur die fehlenden geladen.', 'seafile-updraft-backup-uploader' ),
					$local_ok,
					$local_total
				);
				$h .= '<span class="sbu-bk-local sbu-bk-local-stale" title="' . esc_attr( $tip ) . '">&#9888; ' . esc_html__( 'Teilweise lokal', 'seafile-updraft-backup-uploader' ) . '</span>';
			} else {
				$h .= '<span class="sbu-bk-local sbu-bk-local-remote" title="' . esc_attr__( 'Nur auf Seafile gespeichert — für den Restore werden alle Dateien geladen.', 'seafile-updraft-backup-uploader' ) . '">&#9729; ' . esc_html__( 'Nur remote', 'seafile-updraft-backup-uploader' ) . '</span>';
			}
			$vstat = $verified[ $dn ] ?? null;
			if ( $vstat ) {
				if ( $vstat['status'] === 'complete' ) {
					$is_sha = ( ( $vstat['mode'] ?? 'size' ) === 'sha1' );
					$label  = $is_sha
						? __( 'Bit-für-bit', 'seafile-updraft-backup-uploader' )
						: __( 'Vollständig', 'seafile-updraft-backup-uploader' );
					$tip    = __( 'Geprüft', 'seafile-updraft-backup-uploader' ) . ': ' . ( $vstat['checked'] ?? '' );
					if ( $is_sha ) {
						$tip .= ' — SHA1 ' . ( $vstat['sha1_ok'] ?? 0 ) . '/' . ( $vstat['files'] ?? 0 );
					}
					$h .= '<span class="sbu-verify sbu-verify-ok" title="' . esc_attr( $tip ) . '">&#10003; ' . esc_html( $label ) . '</span>';
				} else {
					$tip = ( $vstat['ok'] ?? 0 ) . '/' . ( $vstat['total'] ?? '?' ) . ' OK';
					if ( ! empty( $vstat['issues'] ) ) {
						$tip .= ' — ' . implode( ', ', $vstat['issues'] );
					}
					$h .= '<span class="sbu-verify sbu-verify-err" title="' . esc_attr( $tip ) . '">&#10007; ' . __( 'Unvollständig', 'seafile-updraft-backup-uploader' ) . '</span>';
				}
			} elseif ( $active_ts === $dn ) {
				$h .= '<span class="sbu-verify sbu-verify-err" title="' . esc_attr( __( 'Upload läuft…', 'seafile-updraft-backup-uploader' ) ) . '">&#10007; ' . __( 'Unvollständig', 'seafile-updraft-backup-uploader' ) . '</span>';
			}
			$h .= '</div>';
			$h .= '<div class="sbu-bk-row2">' . $badges . '</div>';
			$h .= '<div class="sbu-bk-row3">';
			$h .= '<a href="#" class="sbu-toggle" data-sbu-action="toggle-files" data-target="sbu-files-' . (int) $idx . '">' . __( 'Dateien anzeigen', 'seafile-updraft-backup-uploader' ) . '</a>';
			if ( $local_state === 'full' ) {
				// Alle Dateien lokal — Wiederherstellen weglassen und direkt
				// zu UpdraftPlus verlinken. Das spart einen Download-Durchlauf,
				// der nur Duplikate prüfen würde.
				$h .= '<a href="' . esc_url( $up_url ) . '" class="button button-small btn-restore">' . esc_html__( 'In UpdraftPlus öffnen', 'seafile-updraft-backup-uploader' ) . '</a>';
			} else {
				$h .= '<button class="button button-small btn-restore" data-sbu-action="restore-all" data-dir="' . esc_attr( $dn ) . '">' . __( 'Wiederherstellen', 'seafile-updraft-backup-uploader' ) . '</button>';
			}
			$h .= '<button class="button button-small btn-delete" data-sbu-action="delete-backup" data-dir="' . esc_attr( $dn ) . '">' . __( 'Löschen', 'seafile-updraft-backup-uploader' ) . '</button>';
			$h .= '</div></div>';

			// Collapsible file list
			$h .= '<div class="sbu-bk-files" id="sbu-files-' . $idx . '" style="display:none">';
			if ( ! is_wp_error( $files ) ) {
				foreach ( $files as $f ) {
					if ( ( $f['type'] ?? 'file' ) !== 'file' ) {
						continue;
					}
					$fn = esc_html( $f['name'] );
					$fm = round( ( $f['size'] ?? 0 ) / 1024 / 1024, 1 );
					$h .= '<div class="sbu-bk-file">';
					$h .= '<span class="sbu-bk-fn">' . $fn . '</span>';
					$h .= '<span class="sbu-bk-fs">' . $fm . ' MB</span>';
					$h .= '<button class="button button-small" data-sbu-action="download-file" data-dir="' . esc_attr( $dn ) . '" data-file="' . esc_attr( $f['name'] ) . '">' . __( 'Download', 'seafile-updraft-backup-uploader' ) . '</button>';
					$h .= '</div>';
				}
			}
			$h .= '</div></div>';
		}

		wp_send_json_success( $h );
	}

	/**
	 * AJAX: Download a single file from Seafile to the UpdraftPlus directory.
	 *
	 * @return void Sends JSON response.
	 */
	public function ajax_download() {
		$this->verify_ajax_request();
		@set_time_limit( 600 );
		$dir = $this->sanitize_path_segment( isset( $_POST['dir'] ) ? wp_unslash( $_POST['dir'] ) : '' );
		$fn  = $this->sanitize_path_segment( isset( $_POST['file'] ) ? wp_unslash( $_POST['file'] ) : '' );
		if ( ! $dir || ! $fn ) {
			wp_send_json_error( __( 'Ungültige Parameter.', 'seafile-updraft-backup-uploader' ) );
		}

		$s  = $this->get_settings();
		$pw = SBU_Crypto::decrypt( $s['pass'] );
		$t  = SBU_Seafile_API::get_token( $s['url'], $s['user'], $pw );
		if ( is_wp_error( $t ) ) {
			wp_send_json_error( __( 'Authentifizierungsfehler.', 'seafile-updraft-backup-uploader' ) );
		}
		$r = SBU_Seafile_API::find_library( $s['url'], $t, $s['lib'] );
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( __( 'Bibliotheksfehler.', 'seafile-updraft-backup-uploader' ) );
		}

		$path = rtrim( $s['folder'], '/' ) . '/' . $dir . '/' . $fn;
		$ud   = $this->get_updraft_dir();
		if ( ! $ud ) {
			wp_send_json_error( __( 'UpdraftPlus-Verzeichnis nicht gefunden.', 'seafile-updraft-backup-uploader' ) );
		}

		$dest   = $ud . '/' . $fn;
		$result = SBU_Seafile_API::download_file( $s['url'], $t, $r, $path, $dest );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		$mb = round( filesize( $dest ) / 1024 / 1024, 1 );
		$this->activity_logger->log( 'RESTORE', __( 'Einzelne Datei heruntergeladen', 'seafile-updraft-backup-uploader' ) . ": {$fn} ({$mb} MB) ← {$dir}" );
		wp_send_json_success( $fn . " ({$mb} MB)\n\n" . __( 'In UpdraftPlus auf "Lokalen Ordner neu scannen" klicken.', 'seafile-updraft-backup-uploader' ) );
	}

	/**
	 * AJAX: Download all files from a backup folder to UpdraftPlus directory.
	 *
	 * @return void Sends JSON response.
	 */
	public function ajax_download_all() {
		$this->verify_ajax_request();
		delete_option( 'sbu_abort_ts' );
		delete_transient( 'sbu_abort_flag' );
		// Frisches Banner-Target — altes Erfolgs-Banner muss beim Neustart weg.
		delete_option( 'sbu_last_restore_success' );

		$dir = $this->sanitize_path_segment( isset( $_POST['dir'] ) ? wp_unslash( $_POST['dir'] ) : '' );
		if ( ! $dir ) {
			wp_send_json_error( __( 'Kein Ordner angegeben.', 'seafile-updraft-backup-uploader' ) );
		}

		// Prevent concurrent operations
		$existing = get_option( SBU_QUEUE );
		if ( $existing && in_array( $existing['status'] ?? '', array( 'uploading', 'restoring' ), true ) ) {
			wp_send_json_error( __( 'Ein Upload oder Download läuft bereits. Bitte warten oder abbrechen.', 'seafile-updraft-backup-uploader' ) );
		}

		$s  = $this->get_settings();
		$pw = SBU_Crypto::decrypt( $s['pass'] );
		$t  = SBU_Seafile_API::get_token( $s['url'], $s['user'], $pw );
		if ( is_wp_error( $t ) ) {
			wp_send_json_error( __( 'Authentifizierungsfehler.', 'seafile-updraft-backup-uploader' ) );
		}
		$r = SBU_Seafile_API::find_library( $s['url'], $t, $s['lib'] );
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( __( 'Bibliotheksfehler.', 'seafile-updraft-backup-uploader' ) );
		}

		$ud = $this->get_updraft_dir();
		if ( ! $ud ) {
			wp_send_json_error( __( 'UpdraftPlus-Verzeichnis nicht gefunden.', 'seafile-updraft-backup-uploader' ) );
		}

		$dp    = rtrim( $s['folder'], '/' ) . '/' . $dir;
		$files = SBU_Seafile_API::list_directory( $s['url'], $t, $r, $dp );
		if ( is_wp_error( $files ) || empty( $files ) ) {
			wp_send_json_error( __( 'Keine Dateien in diesem Backup gefunden.', 'seafile-updraft-backup-uploader' ) );
		}

		// Pull stored upload-time hashes for this backup folder, if any.
		// Backups uploaded before 1.3.4 have no map, so integrity check on
		// restore degrades gracefully to "unverified".
		$all_hashes = get_option( SBU_HASHES, array() );
		$hash_map   = is_array( $all_hashes ) && isset( $all_hashes[ $dp ] ) && is_array( $all_hashes[ $dp ] )
			? $all_hashes[ $dp ]
			: array();

		$file_list = array();
		foreach ( $files as $f ) {
			if ( ( $f['type'] ?? 'file' ) !== 'file' ) {
				continue;
			}
			$dest = $ud . '/' . $f['name'];
			// Skip files already present with correct size
			if ( file_exists( $dest ) && filesize( $dest ) === (int) ( $f['size'] ?? 0 ) ) {
				continue;
			}
			$file_list[] = array(
				'name'          => $f['name'],
				'size'          => (int) ( $f['size'] ?? 0 ),
				'path'          => $dp . '/' . $f['name'],
				'dest'          => $dest,
				'status'        => 'pending',
				'offset'        => 0,
				'expected_sha1' => $hash_map[ $f['name'] ] ?? '',
			);
		}

		if ( empty( $file_list ) ) {
			$this->activity_logger->log( 'DUPLIKAT', __( 'Restore übersprungen: alle Dateien bereits lokal vorhanden', 'seafile-updraft-backup-uploader' ) . ": {$dir}" );
			wp_send_json_success( __( 'Alle Backup-Dateien sind bereits lokal vorhanden.', 'seafile-updraft-backup-uploader' ) . "\n\n" . __( 'In UpdraftPlus auf "Lokalen Ordner neu scannen" klicken.', 'seafile-updraft-backup-uploader' ) );
		}

		// Chunk size for downloads — intentionally smaller than upload chunks.
		// Downloads through reverse proxies (Cloudflare Tunnel) run slower and
		// need shorter per-request durations to stay below the ~100 s
		// connection limits.
		$csz = ( $s['download_chunk'] ?? SBU_DOWNLOAD_CHUNK_MB_DEFAULT ) * 1024 * 1024;

		$queue = array(
			'status'        => 'restoring',
			'mode'          => 'restore',
			'files'         => $file_list,
			'file_idx'      => 0,
			'dir'           => $dir,
			'library_id'    => $r,
			'chunk_size'    => $csz,
			'started'       => time(),
			'last_activity' => time(),
			'ok'            => 0,
			'err'           => 0,
			'total_bytes'   => 0,
		);
		update_option( SBU_QUEUE, $queue, false );

		$count = count( $file_list );
		$this->activity_logger->log( 'RESTORE', "Wiederherstellung gestartet: {$dir} ({$count} Dateien)" );

		// Start processing immediately
		$this->queue_engine->schedule_next_tick( 5 );
		$this->queue_engine->spawn_next_tick();

		/* translators: %d is the number of files being restored */
		wp_send_json_success( sprintf( __( 'Wiederherstellung gestartet: %d Dateien werden von Seafile heruntergeladen.', 'seafile-updraft-backup-uploader' ), $count ) );
	}

	/**
	 * AJAX: Delete a backup folder from Seafile.
	 *
	 * @return void Sends JSON response.
	 */
	public function ajax_delete() {
		$this->verify_ajax_request();
		$dir = $this->sanitize_path_segment( isset( $_POST['dir'] ) ? wp_unslash( $_POST['dir'] ) : '' );
		if ( ! $dir ) {
			wp_send_json_error( __( 'Kein Ordner angegeben.', 'seafile-updraft-backup-uploader' ) );
		}

		$s  = $this->get_settings();
		$pw = SBU_Crypto::decrypt( $s['pass'] );
		$t  = SBU_Seafile_API::get_token( $s['url'], $s['user'], $pw );
		if ( is_wp_error( $t ) ) {
			wp_send_json_error( __( 'Authentifizierungsfehler.', 'seafile-updraft-backup-uploader' ) );
		}
		$r = SBU_Seafile_API::find_library( $s['url'], $t, $s['lib'] );
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( __( 'Bibliotheksfehler.', 'seafile-updraft-backup-uploader' ) );
		}

		$path   = rtrim( $s['folder'], '/' ) . '/' . $dir;
		$result = SBU_Seafile_API::delete_directory( $s['url'], $t, $r, $path );
		if ( is_wp_error( $result ) ) {
			// Retry with fresh token
			$t = SBU_Seafile_API::get_token( $s['url'], $s['user'], $pw, true );
			if ( ! is_wp_error( $t ) ) {
				$result = SBU_Seafile_API::delete_directory( $s['url'], $t, $r, $path );
			}
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( $result->get_error_message() );
			}
		}
		$this->activity_logger->log( 'LÖSCHEN', __( 'Backup manuell gelöscht', 'seafile-updraft-backup-uploader' ) . ": {$dir}" );

		// Drop stored integrity hashes for this folder — nothing to verify
		// against anymore, and keeping them bloats the option row.
		$hashes = get_option( SBU_HASHES, array() );
		if ( is_array( $hashes ) && isset( $hashes[ $path ] ) ) {
			unset( $hashes[ $path ] );
			update_option( SBU_HASHES, $hashes, false );
		}

		/* translators: %s is the backup folder name that was deleted from Seafile. */
		wp_send_json_success( sprintf( __( 'Backup "%s" deleted.', 'seafile-updraft-backup-uploader' ), $dir ) );
	}

	/**
	 * AJAX: Return the formatted activity log for live refresh.
	 *
	 * @return void Sends JSON response with formatted log HTML.
	 */
	public function ajax_get_log() {
		$this->verify_ajax_request();
		wp_cache_delete( SBU_ACTIVITY, 'options' );
		$log = get_option( SBU_ACTIVITY, '' );
		wp_send_json_success( $log ? $this->activity_logger->format( $log ) : '' );
	}

	/**
	 * AJAX: Export the raw activity log as plain text for download.
	 *
	 * @return void Sends JSON response with raw log text.
	 */
	public function ajax_export_log() {
		$this->verify_ajax_request();
		$log     = get_option( SBU_ACTIVITY, '' );
		$header  = "Seafile Updraft Backup Uploader - Activity Log\n";
		$header .= 'Website: ' . get_bloginfo( 'name' ) . ' (' . home_url() . ")\n";
		$header .= 'Export:  ' . current_time( 'd.m.Y H:i:s' ) . "\n";
		// Datenschutz-Warnung: der Rohexport enthält Hostname, Seafile-
		// Library-IDs, Ordnerpfade, Backup-Dateinamen (inkl. Site-Host via
		// UpdraftPlus-Default) und E-Mails — alles, was der "Anonymisiert
		// exportieren"-Knopf maskieren würde. Der Hinweis steht im Export
		// selbst, damit er beim Weiterleiten nicht verloren geht.
		$header .= "WARNUNG: Dieser Export enthält identifizierende Daten (Host, Bibliothek, Pfade, E-Mail).\n";
		$header .= "Für Support-Weitergabe bitte 'Anonymisiert exportieren' verwenden.\n";
		$header .= str_repeat( '=', 60 ) . "\n\n";
		wp_send_json_success( $header . ( $log ? $log : __( 'Noch keine Aktivität aufgezeichnet.', 'seafile-updraft-backup-uploader' ) ) );
	}

	/**
	 * AJAX: Clear the entire activity log.
	 *
	 * @return void Sends JSON response.
	 */
	public function ajax_clear_log() {
		$this->verify_ajax_request();
		update_option( SBU_ACTIVITY, '', false );
		wp_send_json_success( 'ok' );
	}

	/**
	 * AJAX: Dismiss the post-restore success banner.
	 *
	 * @return void Sends JSON response.
	 */
	public function ajax_dismiss_restore_banner() {
		$this->verify_ajax_request();
		$last = get_option( 'sbu_last_restore_success', array() );
		if ( is_array( $last ) && ! empty( $last ) ) {
			$last['dismissed'] = 1;
			update_option( 'sbu_last_restore_success', $last, false );
		}
		wp_send_json_success( 'ok' );
	}

	/**
	 * AJAX: Export the activity log with identifying data masked.
	 *
	 * Used when sharing logs for support. Masks the Seafile host, library
	 * UUIDs, folder paths, user e-mails, IPv4 addresses and the UpdraftPlus
	 * nonce so a log can be posted publicly without leaking tenant data.
	 *
	 * @return void Sends JSON response with anonymized log text.
	 */
	public function ajax_export_log_anon() {
		$this->verify_ajax_request();
		$s   = $this->get_settings();
		$log = get_option( SBU_ACTIVITY, '' );

		$host = '';
		$url  = (string) ( $s['url'] ?? '' );
		if ( $url !== '' ) {
			$parts = wp_parse_url( $url );
			if ( is_array( $parts ) && ! empty( $parts['host'] ) ) {
				$host = $parts['host'];
			}
		}
		$lib    = (string) ( $s['lib'] ?? '' );
		$folder = trim( (string) ( $s['folder'] ?? '' ), '/' );
		$user   = (string) ( $s['user'] ?? '' );

		$masked = (string) $log;

		if ( $host !== '' ) {
			$masked = str_ireplace( $host, '[SERVER]', $masked );
		}
		if ( $lib !== '' ) {
			$masked = str_ireplace( $lib, '[LIB]', $masked );
		}
		if ( $folder !== '' ) {
			$masked = str_ireplace( $folder, '[PATH]', $masked );
		}
		if ( $user !== '' ) {
			$masked = str_ireplace( $user, '[USER]', $masked );
		}

		// Any remaining e-mails / IPs / UUIDs / UpdraftPlus nonces.
		$masked = preg_replace( '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', '[USER]', $masked );
		$masked = preg_replace( '/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/', '[IP]', $masked );
		$masked = preg_replace( '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i', '[LIB]', $masked );
		$masked = preg_replace( '/(_)[a-f0-9]{12}(-)/i', '$1[NONCE]$2', $masked );

		$header  = "Seafile Updraft Backup Uploader - Activity Log (anonymized)\n";
		$header .= "Website: [HOSTNAME]\n";
		$header .= 'Export:  ' . current_time( 'd.m.Y H:i:s' ) . "\n";
		$header .= "Note:    Host / library ID / folder / user e-mail / IPs / UUIDs are masked.\n";
		$header .= str_repeat( '=', 60 ) . "\n\n";

		wp_send_json_success( $header . ( $masked !== '' ? $masked : __( 'Noch keine Aktivität aufgezeichnet.', 'seafile-updraft-backup-uploader' ) ) );
	}

	/**
	 * AJAX: Return current upload progress (for live polling).
	 *
	 * @return void Sends JSON response with progress data.
	 */
	public function ajax_upload_status() {
		$this->verify_ajax_request();

		// Check restore progress (legacy transient)
		$restore = get_transient( 'sbu_progress' );
		if ( is_array( $restore ) && ! empty( $restore['active'] ) ) {
			wp_send_json_success( $restore );
		}

		// Check upload/restore queue
		wp_cache_delete( SBU_QUEUE, 'options' );
		$queue = get_option( SBU_QUEUE );
		if ( ! $queue || ! in_array( $queue['status'] ?? '', array( 'uploading', 'restoring', 'paused' ), true ) ) {
			wp_send_json_success( array( 'active' => false ) );
		}
		$is_paused    = ( $queue['status'] ?? '' ) === 'paused';
		$total_files  = count( $queue['files'] );
		$current_idx  = min( $queue['file_idx'] ?? 0, $total_files - 1 );
		$current_file = isset( $queue['files'][ $current_idx ] )
			? basename( $queue['files'][ $current_idx ]['path'] ?? $queue['files'][ $current_idx ]['name'] ?? '' )
			: '';
		// Calculate progress including current file's chunk offset
		$done_bytes     = (int) ( $queue['total_bytes'] ?? 0 );
		$total_bytes    = 0;
		$current_offset = 0;
		foreach ( $queue['files'] as $fi ) {
			$total_bytes += (int) ( $fi['size'] ?? 0 );
		}
		if ( isset( $queue['files'][ $current_idx ] ) ) {
			$current_offset = (int) ( $queue['files'][ $current_idx ]['offset'] ?? 0 );
		}
		$pct        = $total_bytes > 0 ? round( ( $done_bytes + $current_offset ) / $total_bytes * 100 ) : 0;
		$is_restore = ( $queue['status'] ?? '' ) === 'restoring'
			|| ( $is_paused && ( ! empty( $queue['restore'] ) || isset( $queue['dir'] ) ) );
		wp_send_json_success(
			array(
				'active'     => true,
				'paused'     => $is_paused,
				'mode'       => $is_restore ? 'restore' : 'upload',
				'file'       => $current_file,
				'file_num'   => $current_idx + 1,
				'file_total' => $total_files,
				'pct'        => $pct,
				'ok'         => $queue['ok'],
				'err'        => $queue['err'],
				'stalled'    => ! $is_paused && ( time() - ( $queue['last_activity'] ?? time() ) ) > 120,
			)
		);
	}


	/**
	 * AJAX: Actively process upload queue from the browser (replaces WP-Cron dependency).
	 *
	 * @return void Sends JSON response.
	 */
	public function ajax_kick() {
		$this->verify_ajax_request();
		$queue = get_option( SBU_QUEUE );
		if ( ! $queue || ! in_array( $queue['status'] ?? '', array( 'uploading', 'restoring' ), true ) ) {
			wp_send_json_success(
				array(
					'kicked' => false,
					'reason' => 'no active queue',
				)
			);
		}
		if ( $this->queue_engine->tick_is_gated() ) {
			wp_send_json_success(
				array(
					'kicked' => false,
					'reason' => 'backoff',
				)
			);
		}
		if ( ! $this->queue_engine->acquire_lock( $this->queue_engine->default_lock_ttl() ) ) {
			wp_send_json_success(
				array(
					'kicked' => false,
					'reason' => 'already processing',
				)
			);
		}
		try {
			$this->process_queue_tick();
		} finally {
			$this->queue_engine->release_lock();
		}
		wp_send_json_success( array( 'kicked' => true ) );
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
	 * AJAX: External cron endpoint. Key-protected, no login required.
	 *
	 * Recommended transport:
	 *   curl -H "X-SBU-Cron-Key: SECRET" \
	 *        "/wp-admin/admin-ajax.php?action=sbu_cron_ping"
	 *
	 * Legacy transport (kept working, but key leaks into URL logs):
	 *   /wp-admin/admin-ajax.php?action=sbu_cron_ping&key=SECRET
	 *
	 * @return void Sends JSON response.
	 */
	public function ajax_cron_ping() {
		$key = $this->extract_cron_key_from_request();
		if ( ! hash_equals( $this->get_cron_key(), $key ) ) {
			$this->record_cron_key_failure();
			wp_send_json_error( __( 'Ungültiger Schlüssel.', 'seafile-updraft-backup-uploader' ), 403 );
		}
		$queue = get_option( SBU_QUEUE );
		if ( ! $queue || ! in_array( $queue['status'] ?? '', array( 'uploading', 'restoring' ), true ) ) {
			wp_send_json_success( array( 'status' => 'idle' ) );
		}
		// Zero-traffic self-pacing. The loopback chain (and a shutdown
		// fallback) already kicks the next tick. But if the queue is in
		// a backoff window, bouncing off the gate here would break the
		// chain — on a site with no traffic nothing else would wake it
		// until WP-Cron fires, which itself needs a visitor. So we
		// absorb a *bounded* wait in-process: sleep up to
		// SBU_CRON_SLEEP_MAX (15 s), then either run the tick (gate
		// expired) or hand off to a fresh loopback (gate still held).
		//
		// The hard 15 s cap keeps a single PHP-FPM worker from being
		// held for a full tick window, which would amplify a leaked
		// key into a worker-pool DoS. Longer backoff waits are walked
		// by a chain of short sleeps, each spawned via a fresh
		// loopback — same net latency, much smaller blast radius.
		if ( $this->queue_engine->tick_is_gated() ) {
			$q2      = get_option( SBU_QUEUE );
			$gate_ts = is_array( $q2 ) ? (int) ( $q2['next_allowed_tick_ts'] ?? 0 ) : 0;
			$lim     = $this->get_adaptive_limits();
			$budget  = max( 1, (int) $lim['tick_time'] - 5 );
			$remain  = $gate_ts - time();
			if ( $remain > 0 ) {
				$wait = min( $remain, $budget, SBU_CRON_SLEEP_MAX );
				@set_time_limit( $wait + (int) $lim['tick_time'] + 30 );
				sleep( $wait );
			}
			if ( $this->queue_engine->tick_is_gated() ) {
				$this->queue_engine->spawn_next_tick();
				wp_send_json_success(
					array(
						'status' => 'slept',
						'next'   => 'loopback',
					)
				);
			}
			// Gate expired during sleep — fall through and run a tick.
		}
		if ( ! $this->queue_engine->acquire_lock( $this->queue_engine->default_lock_ttl() ) ) {
			wp_send_json_success( array( 'status' => 'locked' ) );
		}
		try {
			$this->process_queue_tick();
		} finally {
			$this->queue_engine->release_lock();
		}
		wp_send_json_success( array( 'status' => 'processed' ) );
	}

	/**
	 * AJAX: Rotate the cron-ping key. Invalidates any external crontab
	 * using the old key — the user must update their crontab afterwards.
	 *
	 * @return void Sends JSON response containing the new key and URLs.
	 */
	public function ajax_rotate_cron_key() {
		$this->verify_ajax_request();
		$new_key = wp_generate_password( 32, false );
		update_option( 'sbu_cron_key', $new_key, false );
		$this->activity_logger->log( 'SETTINGS', __( 'Externer Cron-Schlüssel rotiert', 'seafile-updraft-backup-uploader' ) );
		wp_send_json_success(
			array(
				'key'        => $new_key,
				'url_legacy' => admin_url( 'admin-ajax.php?action=sbu_cron_ping&key=' . rawurlencode( $new_key ) ),
				'url_header' => admin_url( 'admin-ajax.php?action=sbu_cron_ping' ),
			)
		);
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
	 * AJAX: Load available Seafile libraries for the picker dropdown.
	 *
	 * @return void Sends JSON response with library list.
	 */
	public function ajax_load_libs() {
		$this->verify_ajax_request();
		$s = $this->get_settings();

		// Use form values if provided (settings not yet saved)
		$url  = ! empty( $_POST['sbu_url'] ) ? esc_url_raw( rtrim( trim( wp_unslash( $_POST['sbu_url'] ) ), '/' ) ) : $s['url'];
		$user = ! empty( $_POST['sbu_user'] ) ? sanitize_email( wp_unslash( $_POST['sbu_user'] ) ) : $s['user'];
		$pass = ! empty( $_POST['sbu_pass'] ) ? wp_unslash( $_POST['sbu_pass'] ) : SBU_Crypto::decrypt( $s['pass'] );

		// Don't use masked dots as password
		$dots = str_repeat( "\xe2\x80\xa2", 8 );
		if ( $pass === $dots ) {
			$pass = SBU_Crypto::decrypt( $s['pass'] );
		}

		if ( ! $url || ! $user || ! $pass ) {
			wp_send_json_error( __( 'Bitte zuerst URL, Benutzername und Passwort ausfüllen.', 'seafile-updraft-backup-uploader' ) );
		}

		$t = SBU_Seafile_API::get_token( $url, $user, $pass );
		if ( is_wp_error( $t ) ) {
			/* translators: %s is the upstream authentication error message */
			wp_send_json_error( sprintf( __( 'Authentifizierung fehlgeschlagen: %s', 'seafile-updraft-backup-uploader' ), $t->get_error_message() ) );
		}

		$r = wp_remote_get(
			$url . '/api2/repos/',
			array(
				'timeout' => SBU_TIMEOUT_API,
				'headers' => array( 'Authorization' => 'Token ' . $t ),
			)
		);
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( $r->get_error_message() );
		}

		$repos = json_decode( wp_remote_retrieve_body( $r ), true );
		if ( ! is_array( $repos ) ) {
			wp_send_json_error( __( 'Ungültige Antwort.', 'seafile-updraft-backup-uploader' ) );
		}

		$libs = array();
		foreach ( $repos as $rp ) {
			if ( ! empty( $rp['name'] ) ) {
				$libs[] = array(
					'name' => $rp['name'],
					'id'   => $rp['id'],
					'size' => $rp['size'] ?? 0,
				);
			}
		}
		usort(
			$libs,
			function ( $a, $b ) {
				return strcasecmp( $a['name'], $b['name'] );
			}
		);
		wp_send_json_success( $libs );
	}

	/**
	 * AJAX: Load subdirectories of a Seafile library root for the picker.
	 *
	 * @return void Sends JSON response with directory names.
	 */
	public function ajax_load_dirs() {
		$this->verify_ajax_request();
		$creds    = $this->get_picker_credentials();
		$lib_name = sanitize_text_field( wp_unslash( $_POST['lib'] ?? '' ) );
		if ( ! $lib_name ) {
			wp_send_json_error( __( 'Keine Bibliothek angegeben.', 'seafile-updraft-backup-uploader' ) );
		}

		$t = SBU_Seafile_API::get_token( $creds['url'], $creds['user'], $creds['pass'] );
		if ( is_wp_error( $t ) ) {
			wp_send_json_error( __( 'Authentifizierungsfehler.', 'seafile-updraft-backup-uploader' ) );
		}

		$rid = SBU_Seafile_API::find_library( $creds['url'], $t, $lib_name );
		if ( is_wp_error( $rid ) ) {
			wp_send_json_error( $rid->get_error_message() );
		}

		$items = SBU_Seafile_API::list_directory( $creds['url'], $t, $rid, '/' );
		if ( is_wp_error( $items ) ) {
			wp_send_json_error( __( 'Verzeichnis nicht lesbar.', 'seafile-updraft-backup-uploader' ) );
		}

		$dirs = array();
		foreach ( $items as $it ) {
			if ( ( $it['type'] ?? '' ) === 'dir' ) {
				$dirs[] = $it['name'];
			}
		}
		sort( $dirs );
		wp_send_json_success( $dirs );
	}

	/**
	 * AJAX: Create a new subdirectory in a Seafile library.
	 *
	 * @return void Sends JSON response.
	 */
	public function ajax_create_dir() {
		$this->verify_ajax_request();
		$creds    = $this->get_picker_credentials();
		$lib_name = sanitize_text_field( wp_unslash( $_POST['lib'] ?? '' ) );
		$dirname  = sanitize_file_name( wp_unslash( $_POST['dirname'] ?? '' ) );
		if ( ! $lib_name || ! $dirname ) {
			wp_send_json_error( __( 'Parameter fehlen.', 'seafile-updraft-backup-uploader' ) );
		}

		$t = SBU_Seafile_API::get_token( $creds['url'], $creds['user'], $creds['pass'] );
		if ( is_wp_error( $t ) ) {
			wp_send_json_error( __( 'Authentifizierungsfehler.', 'seafile-updraft-backup-uploader' ) );
		}

		$rid = SBU_Seafile_API::find_library( $creds['url'], $t, $lib_name );
		if ( is_wp_error( $rid ) ) {
			wp_send_json_error( $rid->get_error_message() );
		}

		SBU_Seafile_API::create_directory( $creds['url'], $t, $rid, '/' . $dirname );
		wp_send_json_success( 'OK' );
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
	 * AJAX: Auto-save settings from form fields.
	 *
	 * @return void Sends JSON response.
	 */
	public function ajax_save_settings() {
		$this->verify_ajax_request();
		$input                                = array();
		$input['url']                         = isset( $_POST['sbu_url'] ) ? wp_unslash( $_POST['sbu_url'] ) : '';
		$input['user']                        = isset( $_POST['sbu_user'] ) ? wp_unslash( $_POST['sbu_user'] ) : '';
		$input['pass']                        = isset( $_POST['sbu_pass'] ) ? wp_unslash( $_POST['sbu_pass'] ) : '';
		$input['lib']                         = isset( $_POST['sbu_lib'] ) ? wp_unslash( $_POST['sbu_lib'] ) : '';
		$input['folder']                      = isset( $_POST['sbu_folder'] ) ? wp_unslash( $_POST['sbu_folder'] ) : '/';
		$input['chunk']                       = isset( $_POST['sbu_chunk'] ) ? wp_unslash( $_POST['sbu_chunk'] ) : 40;
		$input['retention']                   = isset( $_POST['sbu_retention'] ) ? wp_unslash( $_POST['sbu_retention'] ) : 4;
		$input['email']                       = isset( $_POST['sbu_email'] ) ? wp_unslash( $_POST['sbu_email'] ) : '';
		$input['notify']                      = isset( $_POST['sbu_notify'] ) ? wp_unslash( $_POST['sbu_notify'] ) : 'error';
		$input['auto']                        = isset( $_POST['sbu_auto'] ) ? wp_unslash( $_POST['sbu_auto'] ) : 0;
		$input['del_local']                   = isset( $_POST['sbu_del_local'] ) ? wp_unslash( $_POST['sbu_del_local'] ) : 0;
		$input['debug_log']                   = isset( $_POST['sbu_debug_log'] ) ? wp_unslash( $_POST['sbu_debug_log'] ) : 0;
		$input['activity_log_retention_days'] = isset( $_POST['sbu_activity_log_retention_days'] )
			? wp_unslash( $_POST['sbu_activity_log_retention_days'] )
			: SBU_ACTIVITY_RETENTION_DAYS_DEFAULT;
		// download_chunk is no longer user-configurable — stream-first handles
		// downloads, the chunk path is only a fallback for files > 500 MB and
		// stream failures. Default from SBU_DOWNLOAD_CHUNK_MB_DEFAULT stays.
		$clean = $this->sanitize( $input );
		update_option( SBU_OPT, $clean );

		// Log settings change (mask password)
		$log_parts   = array();
		$log_parts[] = 'URL=' . ( $clean['url'] ?: '(leer)' );
		$log_parts[] = 'User=' . ( $clean['user'] ?: '(leer)' );
		$log_parts[] = 'Lib=' . ( $clean['lib'] ?: '(leer)' );
		$log_parts[] = 'Folder=' . ( $clean['folder'] ?: '/' );
		$log_parts[] = 'Chunk=' . $clean['chunk'] . 'MB';
		$log_parts[] = 'Retention=' . $clean['retention'];
		$log_parts[] = 'Auto=' . ( $clean['auto'] ? 'Ja' : 'Nein' );
		$log_parts[] = 'DelLocal=' . ( $clean['del_local'] ? 'Ja' : 'Nein' );
		$log_parts[] = 'Notify=' . $clean['notify'];
		$this->activity_logger->log( 'SETTINGS', implode( ', ', $log_parts ) );

		wp_send_json_success( 'ok' );
	}

	/**
	 * AJAX: Reset all settings to defaults.
	 *
	 * @return void Sends JSON response.
	 */
	public function ajax_reset_settings() {
		$this->verify_ajax_request();
		$this->activity_logger->log( 'SETTINGS', __( 'Einstellungen zurückgesetzt', 'seafile-updraft-backup-uploader' ) );
		delete_option( SBU_OPT );
		delete_transient( SBU_TOK );
		wp_send_json_success( 'ok' );
	}

	/**
	 * AJAX: Return a fresh nonce (for long-running sessions).
	 *
	 * @return void Sends JSON response with new nonce.
	 */
	public function ajax_refresh_nonce() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Zugriff verweigert.', 'seafile-updraft-backup-uploader' ) );
		}
		wp_send_json_success( wp_create_nonce( SBU_NONCE ) );
	}

	// =========================================================================
	// =========================================================================
	// QUEUE-BASED UPLOAD SYSTEM
	// =========================================================================

	/**
	 * Hook callback: auto-upload after UpdraftPlus backup completes.
	 *
	 * The `updraftplus_backup_complete` hook is a filter whose first
	 * argument is the `$delete_jobdata` bool — it does NOT carry backup
	 * info. We grab the nonce from UpdraftPlus's global singleton so
	 * `find_backup_files()` uploads ONLY this backup's files and doesn't
	 * pick up leftover local backups from earlier jobs.
	 */
	public function on_backup_complete( $info = null ) {
		$s = $this->get_settings();
		if ( ! $s['auto'] ) {
			return $info;
		}

		// Abort any running queue (new backup is more important)
		$existing = get_option( SBU_QUEUE );
		if ( $existing && in_array( $existing['status'] ?? '', array( 'uploading', 'restoring' ), true ) ) {
			$existing['status'] = 'aborted';
			update_option( SBU_QUEUE, $existing, false );
			wp_clear_scheduled_hook( SBU_CRON_HOOK );
			$this->queue_engine->release_lock();
			delete_transient( 'sbu_progress' );
			delete_option( 'sbu_abort_ts' );
			delete_transient( 'sbu_abort_flag' );
			$this->activity_logger->log( 'INFO', __( 'Vorheriger Vorgang abgebrochen: neues Backup von UpdraftPlus', 'seafile-updraft-backup-uploader' ) );
		}

		$nonce = $this->current_updraft_nonce();
		$this->create_upload_queue( $nonce );

		// `updraftplus_backup_complete` is registered in UpdraftPlus via
		// apply_filters(), so return the incoming $delete_jobdata untouched
		// to avoid breaking downstream filter callbacks.
		return $info;
	}

	/**
	 * Read the active backup nonce from the UpdraftPlus singleton.
	 *
	 * UpdraftPlus assigns a fresh 12-char nonce per backup via
	 * `backup_time_nonce()` and stores it on both `->nonce` and
	 * `->file_nonce`. We tolerate either property, and fall back to an
	 * empty string (→ newest-set fallback in find_backup_files) if the
	 * global isn't reachable (unit tests, future UDP refactors).
	 *
	 * @return string Lowercase 12-char nonce or '' if unavailable.
	 */
	private function current_updraft_nonce() {
		global $updraftplus;
		if ( is_object( $updraftplus ) ) {
			$n = $updraftplus->file_nonce ?? $updraftplus->nonce ?? '';
			if ( is_string( $n ) && preg_match( '/^[a-f0-9]{12}$/i', $n ) ) {
				return strtolower( $n );
			}
		}
		return '';
	}

	/**
	 * Create an upload queue from current UpdraftPlus backup files.
	 *
	 * @param string $nonce Optional 12-char UpdraftPlus backup nonce. When
	 *                      supplied, only files belonging to that backup
	 *                      set are uploaded; otherwise the newest local
	 *                      set is picked (see find_backup_files).
	 * @return string|WP_Error Status message or error.
	 */
	private function create_upload_queue( $nonce = '' ) {
		$s = $this->get_settings();

		if ( ! $s['url'] || ! $s['user'] || ! $s['pass'] ) {
			$this->activity_logger->log( 'FEHLER', __( 'Zugangsdaten unvollständig', 'seafile-updraft-backup-uploader' ) );
			$this->mail_notifier->send( false, 'Credentials incomplete.' );
			return new \WP_Error( 'cfg', __( 'Zugangsdaten unvollständig.', 'seafile-updraft-backup-uploader' ) );
		}

		$pw = SBU_Crypto::decrypt( $s['pass'] );
		$ud = $this->get_updraft_dir();
		if ( ! $ud ) {
			$this->activity_logger->log( 'FEHLER', __( 'UpdraftPlus-Verzeichnis nicht gefunden', 'seafile-updraft-backup-uploader' ) );
			return new \WP_Error( 'dir', __( 'UpdraftPlus-Verzeichnis nicht gefunden.', 'seafile-updraft-backup-uploader' ) );
		}

		$found = $this->find_backup_files( $ud, $nonce );
		if ( empty( $found ) ) {
			return __( 'Keine Backup-Dateien gefunden.', 'seafile-updraft-backup-uploader' );
		}

		$t = SBU_Seafile_API::get_token( $s['url'], $s['user'], $pw );
		if ( is_wp_error( $t ) ) {
			$this->activity_logger->log( 'FEHLER', __( 'Authentifizierung fehlgeschlagen', 'seafile-updraft-backup-uploader' ) . ': ' . $t->get_error_message() );
			$this->mail_notifier->send( false, 'Authentication failed.' );
			return $t;
		}

		$rid = SBU_Seafile_API::find_library( $s['url'], $t, $s['lib'] );
		if ( is_wp_error( $rid ) ) {
			$this->activity_logger->log( 'FEHLER', __( 'Bibliothek nicht gefunden', 'seafile-updraft-backup-uploader' ) . ': ' . $s['lib'] );
			return $rid;
		}

		if ( $s['folder'] !== '/' ) {
			SBU_Seafile_API::ensure_directory_exists( $s['url'], $t, $rid, $s['folder'] );
		}

		// Duplicate detection: compare local backup fingerprint with existing remote backups
		$local_fingerprint = array();
		foreach ( $found as $fp ) {
			$local_fingerprint[ basename( $fp ) ] = (int) filesize( $fp );
		}
		ksort( $local_fingerprint );
		$local_count = count( $local_fingerprint );
		$local_names = array_keys( $local_fingerprint );
		sort( $local_names );

		$this->activity_logger->log( 'INFO', "Duplikatprüfung: {$local_count} lokale Dateien, erste: {$local_names[0]}" );

		$existing = SBU_Seafile_API::list_directory( $s['url'], $t, $rid, $s['folder'] );
		if ( is_wp_error( $existing ) ) {
			$this->activity_logger->log( 'INFO', 'Duplikatprüfung: Ordner konnte nicht gelesen werden: ' . $existing->get_error_message() );
		} elseif ( ! empty( $existing ) ) {
			$dirs_checked = 0;
			foreach ( $existing as $it ) {
				if ( ( $it['type'] ?? '' ) !== 'dir' ) {
					continue;
				}
				++$dirs_checked;
				$subdir       = rtrim( $s['folder'], '/' ) . '/' . $it['name'];
				$remote_files = SBU_Seafile_API::list_directory( $s['url'], $t, $rid, $subdir );
				if ( is_wp_error( $remote_files ) || empty( $remote_files ) ) {
					$this->activity_logger->log( 'INFO', "Duplikatprüfung: {$it['name']} übersprungen (leer oder Fehler)" );
					continue;
				}

				$remote_names = array();
				foreach ( $remote_files as $rf ) {
					if ( ( $rf['type'] ?? '' ) === 'file' ) {
						$remote_names[] = $rf['name'];
					}
				}
				sort( $remote_names );

				// Match by filenames
				if ( $remote_names === $local_names ) {
					$this->activity_logger->log( 'DUPLIKAT', __( 'Backup bereits vorhanden auf Seafile', 'seafile-updraft-backup-uploader' ) . ': ' . $it['name'] . ' (' . $local_count . ' Dateien)' );
					return __( 'Backup bereits vorhanden auf Seafile – Upload übersprungen.', 'seafile-updraft-backup-uploader' );
				} else {
					$this->activity_logger->log( 'INFO', "Duplikatprüfung: {$it['name']}: " . count( $remote_names ) . " Remote vs {$local_count} lokal, keine Übereinstimmung" );
				}
			}
			$this->activity_logger->log( 'INFO', "Duplikatprüfung abgeschlossen: kein Duplikat ({$dirs_checked} Ordner geprüft)" );
		}

		$ts = current_time( 'Y-m-d_Hi' );
		// Check for timestamp collision
		if ( ! is_wp_error( $existing ) ) {
			foreach ( $existing as $it ) {
				if ( ( $it['name'] ?? '' ) === $ts && ( $it['type'] ?? '' ) === 'dir' ) {
					$ts .= current_time( 's' );
					break;
				}
			}
		}
		$bdir = rtrim( $s['folder'], '/' ) . '/' . $ts;
		SBU_Seafile_API::create_directory( $s['url'], $t, $rid, $bdir );

		// Safe chunk size
		$csz = $s['chunk'] * 1024 * 1024;
		$mem = $this->get_memory_limit();
		if ( $mem > 0 ) {
			$avail = $mem - memory_get_usage( true );
			$safe  = intval( $avail / 3 );
			if ( $safe < $csz && $safe > 1048576 ) {
				$csz = $safe;
			}
		}

		// SHA1 is captured lazily *inside* the tick (see process_queue_tick),
		// not here — sha1_file() over GB of data would blow past the AJAX
		// request's max_execution_time / proxy timeout and produce HTTP 500.
		$file_list = array();
		foreach ( $found as $fp ) {
			$file_list[] = array(
				'path'   => $fp,
				'status' => 'pending',
				'offset' => 0,
				'size'   => filesize( $fp ),
				'sha1'   => '',
			);
		}

		$queue = array(
			'status'        => 'uploading',
			'files'         => $file_list,
			'file_idx'      => 0,
			'bdir'          => $bdir,
			'ts'            => $ts,
			'library_id'    => $rid,
			'chunk_size'    => $csz,
			'started'       => time(),
			'last_activity' => time(),
			'ok'            => 0,
			'err'           => 0,
			'total_bytes'   => 0,
		);
		update_option( SBU_QUEUE, $queue, false );

		// Set initial verification badge to "incomplete" so it shows immediately
		$verified        = get_option( 'sbu_verified', array() );
		$verified[ $ts ] = array(
			'status'  => 'uploading',
			'ok'      => 0,
			'total'   => count( $file_list ),
			'issues'  => array( __( 'Upload läuft…', 'seafile-updraft-backup-uploader' ) ),
			'checked' => current_time( 'd.m.Y H:i' ),
		);
		if ( count( $verified ) > 50 ) {
			$verified = array_slice( $verified, -50, 50, true );
		}
		update_option( 'sbu_verified', $verified, false );

		$count = count( $file_list );
		$this->activity_logger->log( 'UPLOAD', "Queue erstellt: {$count} Dateien \xe2\x86\x92 {$ts}" );

		// Start processing (self-chain + cron fallback)
		$this->queue_engine->schedule_next_tick( 5 );
		$this->queue_engine->spawn_next_tick();

		return "Upload gestartet: {$count} Dateien \xe2\x86\x92 {$ts}";
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
	 * Process one tick. Tick length is adaptive (see get_adaptive_limits()),
	 * so this sets the PHP time limit to the same value with a small safety
	 * margin — no point asking for 300 s when the server enforces 30 s, and
	 * no point leaving it at 30 s when the server allows 300 s.
	 */
	private function process_queue_tick() {
		$lim = $this->get_adaptive_limits();
		@set_time_limit( $lim['tick_time'] + max( SBU_TIMEOUT_UPLOAD, SBU_TIMEOUT_DOWNLOAD ) + 30 );

		// Check abort signal (separate option, can't be overwritten by queue updates)
		wp_cache_delete( 'sbu_abort_ts', 'options' );
		$abort_ts = (int) get_option( 'sbu_abort_ts', 0 );
		if ( $abort_ts > 0 && ( time() - $abort_ts ) < 120 ) {
			$queue = get_option( SBU_QUEUE );
			if ( $queue && in_array( $queue['status'] ?? '', array( 'uploading', 'restoring' ), true ) ) {
				$queue['status'] = 'aborted';
				update_option( SBU_QUEUE, $queue, false );
			}
			delete_option( 'sbu_abort_ts' );
			delete_transient( 'sbu_abort_flag' );
			wp_clear_scheduled_hook( SBU_CRON_HOOK );
			delete_transient( 'sbu_progress' );
			return;
		}

		$queue = get_option( SBU_QUEUE );
		if ( ! $queue || ! in_array( $queue['status'] ?? '', array( 'uploading', 'restoring' ), true ) ) {
			return;
		}

		// Make silent worker deaths visible, install backoff, and return.
		if ( $this->detect_worker_crash_and_defer( $queue ) ) {
			return;
		}

		// Queue timeout: abort if running longer than the dynamic per-queue
		// budget. The budget is sized from total bytes × 1.5 / expected
		// throughput, floored at SBU_QUEUE_TIMEOUT so tiny backups still get
		// the legacy 12 h grace, and capped at 24 h so a runaway can't hang
		// forever.
		$started = $queue['started'] ?? 0;
		$timeout = $this->compute_queue_timeout( $queue );
		if ( $started > 0 && ( time() - $started ) > $timeout ) {
			$hours           = round( ( time() - $started ) / 3600, 1 );
			$queue['status'] = 'error';
			update_option( SBU_QUEUE, $queue, false );
			wp_clear_scheduled_hook( SBU_CRON_HOOK );
			delete_transient( 'sbu_progress' );
			$this->activity_logger->log( 'FEHLER', "Queue-Timeout nach {$hours}h: {$queue['ok']} OK, {$queue['err']} Fehler" );
			$this->mail_notifier->send( false, "Queue timed out after {$hours}h." );
			return;
		}

		// Restore mode: delegate to separate handler
		if ( $queue['status'] === 'restoring' ) {
			$this->process_restore_tick( $queue );
			return;
		}

		// Check dedicated abort flag
		if ( get_transient( 'sbu_abort_flag' ) ) {
			delete_transient( 'sbu_abort_flag' );
			$queue['status'] = 'aborted';
			update_option( SBU_QUEUE, $queue, false );
			wp_clear_scheduled_hook( SBU_CRON_HOOK );
			$this->activity_logger->log( 'INFO', __( 'Upload abgebrochen (Abort-Flag)', 'seafile-updraft-backup-uploader' ) );
			return;
		}

		// Pre-schedule next tick in case PHP crashes during this one
		$this->queue_engine->schedule_next_tick( 90 );

		$s          = $this->get_settings();
		$pw         = SBU_Crypto::decrypt( $s['pass'] );
		$tick_start = time();

		$t = SBU_Seafile_API::get_token( $s['url'], $s['user'], $pw );
		if ( is_wp_error( $t ) ) {
			$t = SBU_Seafile_API::get_token( $s['url'], $s['user'], $pw, true );
			if ( is_wp_error( $t ) ) {
				$queue['status'] = 'error';
				update_option( SBU_QUEUE, $queue, false );
				$this->activity_logger->log( 'FEHLER', __( 'Upload abgebrochen: Auth fehlgeschlagen', 'seafile-updraft-backup-uploader' ) );
				$this->mail_notifier->send( false, 'Auth failed during upload.' );
				return;
			}
		}

		$rid  = $queue['library_id'];
		$bdir = $queue['bdir'];
		$csz  = $queue['chunk_size'];

		$file_count = count( $queue['files'] );
		while ( $queue['file_idx'] < $file_count ) {
			if ( $this->tick_budget_exhausted( $tick_start ) ) {
				break;
			}

			// Check for abort or pause (bust cache to see changes from other requests)
			wp_cache_delete( SBU_QUEUE, 'options' );
			$fresh      = get_option( SBU_QUEUE );
			$abort_flag = get_transient( 'sbu_abort_flag' );
			if ( ! empty( $abort_flag ) || ( is_array( $fresh ) && ( $fresh['status'] ?? '' ) === 'aborted' ) ) {
				delete_transient( 'sbu_abort_flag' );
				$queue['status'] = 'aborted';
				update_option( SBU_QUEUE, $queue, false );
				wp_clear_scheduled_hook( SBU_CRON_HOOK );
				$this->activity_logger->log( 'INFO', __( 'Upload abgebrochen', 'seafile-updraft-backup-uploader' ) );
				return;
			}
			if ( is_array( $fresh ) && ( $fresh['status'] ?? '' ) === 'paused' ) {
				// User pressed Pause. Persist our in-memory progress into the
				// paused queue DB row so resume continues at the right offset.
				$this->safe_queue_update( $queue );
				wp_clear_scheduled_hook( SBU_CRON_HOOK );
				$this->activity_logger->log( 'INFO', __( 'Upload pausiert', 'seafile-updraft-backup-uploader' ) );
				return;
			}

			$idx = $queue['file_idx'];
			$fp  = $queue['files'][ $idx ]['path'];
			$fn  = basename( $fp );

			if ( ! file_exists( $fp ) ) {
				$queue['files'][ $idx ]['status'] = 'error';
				++$queue['file_idx'];
				++$queue['err'];
				$this->activity_logger->log( 'FEHLER', "Datei nicht gefunden: {$fn}" );
				continue;
			}

			$fs     = filesize( $fp );
			$offset = $queue['files'][ $idx ]['offset'];

			if ( $offset === 0 ) {
				$queue['files'][ $idx ]['status'] = 'uploading';
				$mb                               = round( $fs / 1024 / 1024, 1 );
				$this->activity_logger->log( 'UPLOAD', "Start: {$fn} ({$mb} MB) — Datei " . ( $idx + 1 ) . '/' . count( $queue['files'] ) );

				// Lazy SHA1: once per file (offset===0 = first touch). sha1_file
				// is streamed but iterates every byte, so we spread the cost
				// across ticks instead of blocking the AJAX request. The hash
				// is persisted on upload success and verified streamingly on
				// restore — no full-file readback needed.
				if ( empty( $queue['files'][ $idx ]['sha1'] ) ) {
					$sha_t0 = microtime( true );
					$sha    = @sha1_file( $fp );
					$sha_dt = microtime( true ) - $sha_t0;
					if ( $sha ) {
						$queue['files'][ $idx ]['sha1'] = $sha;
						$this->activity_logger->log(
							'INFO',
							sprintf(
								/* translators: %1$s file, %2$s sha1 prefix, %3$.1f seconds */
								__( 'SHA1 berechnet: %1$s (%2$s…, %3$ss)', 'seafile-updraft-backup-uploader' ),
								$fn,
								substr( $sha, 0, 8 ),
								round( $sha_dt, 1 )
							)
						);
					} else {
						$this->activity_logger->log(
							'WARNUNG',
							sprintf(
								/* translators: %s file name */
								__( 'SHA1 konnte nicht berechnet werden: %s — strikte Prüfung wird für diese Datei übersprungen', 'seafile-updraft-backup-uploader' ),
								$fn
							)
						);
					}
					$queue['last_activity'] = time();
					if ( $this->safe_queue_update( $queue ) !== 'uploading' ) {
						wp_clear_scheduled_hook( SBU_CRON_HOOK );
						return;
					}
				}
			}

			$link = SBU_Seafile_API::get_upload_link( $s['url'], $t, $rid, $bdir );
			if ( is_wp_error( $link ) ) {
				$t = SBU_Seafile_API::get_token( $s['url'], $s['user'], $pw, true );
				if ( ! is_wp_error( $t ) ) {
					$link = SBU_Seafile_API::get_upload_link( $s['url'], $t, $rid, $bdir );
				}
				if ( is_wp_error( $link ) ) {
					$retries                           = ( $queue['files'][ $idx ]['retries'] ?? 0 ) + 1;
					$queue['files'][ $idx ]['retries'] = $retries;
					$delay                             = min( $retries * 60, 600 );
					$queue['next_retry_delay']         = $delay;
					$queue['next_allowed_tick_ts']     = time() + $delay;
					$queue['last_activity']            = time();
					if ( $this->safe_queue_update( $queue ) !== 'uploading' ) {
						wp_clear_scheduled_hook( SBU_CRON_HOOK );
						return;
					}
					$this->activity_logger->log(
						'RETRY',
						sprintf(
							/* translators: %1$s file name, %2$s error, %3$d attempt, %4$d seconds */
							__( 'Upload-Link fehlgeschlagen: %1$s (%2$s) — Versuch %3$d, nächster Versuch in %4$ds', 'seafile-updraft-backup-uploader' ),
							$fn,
							$link->get_error_message(),
							$retries,
							$delay
						)
					);
					break;
				}
			}

			// Upload chunks until file done or time up
			while ( $offset < $fs ) {
				if ( $this->tick_budget_exhausted( $tick_start ) ) {
					break;
				}
				// Check for abort or pause between chunks
				wp_cache_delete( SBU_QUEUE, 'options' );
				$fresh_q    = get_option( SBU_QUEUE );
				$abort_flag = get_transient( 'sbu_abort_flag' );
				if ( ! empty( $abort_flag ) || ( is_array( $fresh_q ) && ( $fresh_q['status'] ?? '' ) === 'aborted' ) ) {
					delete_transient( 'sbu_abort_flag' );
					$queue['status'] = 'aborted';
					update_option( SBU_QUEUE, $queue, false );
					wp_clear_scheduled_hook( SBU_CRON_HOOK );
					$this->activity_logger->log( 'INFO', __( 'Upload abgebrochen', 'seafile-updraft-backup-uploader' ) );
					return;
				}
				if ( is_array( $fresh_q ) && ( $fresh_q['status'] ?? '' ) === 'paused' ) {
					$queue['files'][ $idx ]['offset'] = $offset;
					$this->safe_queue_update( $queue );
					wp_clear_scheduled_hook( SBU_CRON_HOOK );
					$this->activity_logger->log(
						'INFO',
						sprintf(
							/* translators: %1$s file name, %2$s progress string */
							__( 'Upload pausiert bei %1$s (%2$s)', 'seafile-updraft-backup-uploader' ),
							$fn,
							$this->format_progress( $offset, $fs )
						)
					);
					return;
				}

				$chunk_t0 = microtime( true );
				$result   = $this->upload_one_chunk( $link, $t, $bdir, $fp, $offset, $fs, $csz );
				$chunk_dt = microtime( true ) - $chunk_t0;

				if ( is_wp_error( $result ) ) {
					// Retry on 401 with fresh token
					if ( strpos( $result->get_error_message(), 'HTTP 401' ) !== false ) {
						$t = SBU_Seafile_API::get_token( $s['url'], $s['user'], $pw, true );
						if ( ! is_wp_error( $t ) ) {
							$link = SBU_Seafile_API::get_upload_link( $s['url'], $t, $rid, $bdir );
							if ( ! is_wp_error( $link ) ) {
								$result = $this->upload_one_chunk( $link, $t, $bdir, $fp, $offset, $fs, $csz );
							}
						}
					}

					// Still error: save state, let next tick retry (never give up)
					if ( is_wp_error( $result ) ) {
						$retries                           = ( $queue['files'][ $idx ]['retries'] ?? 0 ) + 1;
						$queue['files'][ $idx ]['retries'] = $retries;
						$delay                             = min( $retries * 60, 600 );
						$queue['next_retry_delay']         = $delay;
						$queue['next_allowed_tick_ts']     = time() + $delay;
						$queue['files'][ $idx ]['offset']  = $offset;
						$queue['last_activity']            = time();
						if ( $this->safe_queue_update( $queue ) !== 'uploading' ) {
							wp_clear_scheduled_hook( SBU_CRON_HOOK );
							return;
						}
						$chunk_mb = round( min( $csz, $fs - $offset ) / 1024 / 1024, 1 );
						$this->activity_logger->log(
							'RETRY',
							sprintf(
								/* translators: %1$s file, %2$s progress, %3$.1f chunk MB, %4$.1f duration sec, %5$s error, %6$d attempt, %7$d delay sec */
								__( '%1$s: Fehler bei %2$s nach %3$s MB Chunk in %4$ss (%5$s) — Versuch %6$d, nächster Versuch in %7$ds', 'seafile-updraft-backup-uploader' ),
								$fn,
								$this->format_progress( $offset, $fs ),
								$chunk_mb,
								round( $chunk_dt, 1 ),
								$result->get_error_message(),
								$retries,
								$delay
							)
						);
						break 2; // Exit both chunk loop AND file loop
					}
				}

				// Slow-chunk warning: surface the problem before it times out
				// entirely on the next attempt. Threshold at 75% of the timeout
				// so the warning only fires when a chunk is genuinely close to
				// the limit, not every time it takes a normal-ish amount of time.
				if ( $chunk_dt > ( SBU_TIMEOUT_UPLOAD * 0.75 ) ) {
					$chunk_mb = round( min( $csz, $fs - $offset ) / 1024 / 1024, 1 );
					$this->activity_logger->log(
						'WARNUNG',
						sprintf(
							/* translators: %1$s file, %2$.1f MB chunk size, %3$.1f seconds, %4$d timeout */
							__( 'Langsamer Chunk: %1$s %2$s MB in %3$ss (Timeout: %4$ds)', 'seafile-updraft-backup-uploader' ),
							$fn,
							$chunk_mb,
							round( $chunk_dt, 1 ),
							SBU_TIMEOUT_UPLOAD
						)
					);
				}

				// Success - reset retry counter and clear any standing backoff
				$queue['files'][ $idx ]['retries'] = 0;
				unset( $queue['next_allowed_tick_ts'] );

				if ( $result === true ) {
					$offset = $fs;
				} else {
					$offset = (int) $result;
				}

				$queue['files'][ $idx ]['offset'] = $offset;
				$queue['last_activity']           = time();
				// Save after every chunk for crash recovery. If another
				// request flipped us to a terminal state (abort/pause), stop.
				if ( $this->safe_queue_update( $queue ) !== 'uploading' ) {
					wp_clear_scheduled_hook( SBU_CRON_HOOK );
					return;
				}
			}

			// File done?
			if ( $offset >= $fs ) {
				if ( $queue['files'][ $idx ]['status'] !== 'error' ) {
					$queue['files'][ $idx ]['status'] = 'done';
					++$queue['ok'];
					$queue['total_bytes'] += $fs;
					$mb                    = round( $fs / 1024 / 1024, 1 );
					$this->activity_logger->log( 'UPLOAD', "\xe2\x9c\x93 {$fn} ({$mb} MB)" );
					if ( $s['del_local'] ) {
						@unlink( $fp );
					}
				}
				++$queue['file_idx'];
			}

			if ( $this->safe_queue_update( $queue ) !== 'uploading' ) {
				wp_clear_scheduled_hook( SBU_CRON_HOOK );
				return;
			}
		}

		$queue['last_activity'] = time();
		if ( $this->safe_queue_update( $queue ) !== 'uploading' ) {
			wp_clear_scheduled_hook( SBU_CRON_HOOK );
			return;
		}

		if ( $queue['file_idx'] >= count( $queue['files'] ) ) {
			$this->finish_queue( $queue );
		} else {
			$delay = $queue['next_retry_delay'] ?? 60;
			unset( $queue['next_retry_delay'] );
			if ( $this->safe_queue_update( $queue ) !== 'uploading' ) {
				wp_clear_scheduled_hook( SBU_CRON_HOOK );
				return;
			}
			$this->queue_engine->schedule_next_tick( $delay );
			// Always fire a loopback ping. Its receivers (cron_process_queue,
			// ajax_cron_ping, ajax_kick) honor next_allowed_tick_ts and drop
			// the tick during the backoff window, so the ping is a no-op
			// until the gate opens. This keeps broken-WP-Cron sites moving
			// without the immediate-retry spam that existed before 1.2.1.
			$this->queue_engine->spawn_next_tick();
		}
	}

	/**
	 * Verify a restored file against its stored upload-time SHA1.
	 *
	 * @param string $filename Filename (for log messages).
	 * @param string $dest     Absolute path to the downloaded file.
	 * @param string $expected Hex SHA1 captured at upload time. Empty string
	 *                         if this backup predates 1.3.4 or the hash was
	 *                         not captured.
	 * @return string 'verified' | 'unverified' | 'mismatch'
	 */
	private function verify_restored_file( $filename, $dest, $expected ) {
		if ( $expected === '' || ! file_exists( $dest ) ) {
			return 'unverified';
		}
		$actual = @sha1_file( $dest );
		if ( ! $actual ) {
			$this->activity_logger->log(
				'WARNUNG',
				sprintf(
					/* translators: %s filename */
					__( 'Integritätsprüfung übersprungen: %s — Prüfsumme konnte nicht berechnet werden', 'seafile-updraft-backup-uploader' ),
					$filename
				)
			);
			return 'unverified';
		}
		if ( ! hash_equals( $expected, $actual ) ) {
			$this->activity_logger->log(
				'FEHLER',
				sprintf(
					/* translators: %1$s file, %2$s expected hash, %3$s actual hash */
					__( 'Integritätsfehler: %1$s — erwartet %2$s, tatsächlich %3$s. Datei ist beschädigt und sollte nicht verwendet werden.', 'seafile-updraft-backup-uploader' ),
					$filename,
					substr( $expected, 0, 12 ) . '…',
					substr( $actual, 0, 12 ) . '…'
				)
			);
			return 'mismatch';
		}
		return 'verified';
	}

	/**
	 * Process one restore tick: download files for up to SBU_TICK_TIME seconds.
	 */
	private function process_restore_tick( $queue ) {
		// Check abort signal (separate DB option)
		wp_cache_delete( 'sbu_abort_ts', 'options' );
		$abort_ts = (int) get_option( 'sbu_abort_ts', 0 );
		if ( $abort_ts > 0 && ( time() - $abort_ts ) < 120 ) {
			$queue['status'] = 'aborted';
			update_option( SBU_QUEUE, $queue, false );
			delete_option( 'sbu_abort_ts' );
			delete_transient( 'sbu_abort_flag' );
			wp_clear_scheduled_hook( SBU_CRON_HOOK );
			delete_transient( 'sbu_progress' );
			$this->activity_logger->log( 'INFO', __( 'Wiederherstellung abgebrochen', 'seafile-updraft-backup-uploader' ) );
			return;
		}

		// Make silent worker deaths visible, install backoff, and return.
		if ( $this->detect_worker_crash_and_defer( $queue ) ) {
			return;
		}

		// Stall notifier: if the current file's offset hasn't moved for an
		// hour, email the admin once. Do NOT abort — keep retrying with
		// longer backoffs. The user ran an 8 h restore that silently froze
		// at 88 MB with no outside signal; this gives a signal, not a
		// guillotine. Re-notify at most every 4 h so a long stall doesn't
		// spam the inbox.
		$queue = $this->maybe_notify_stall( $queue );

		// Safety net: if this tick dies from a PHP fatal or hard time-limit
		// SIGKILL (shouldn't happen with the deadline-aware pump, but the
		// pre-1.4.2 overnight gap showed we can't assume), this shutdown
		// callback spawns a loopback ping so the restore resumes without
		// waiting for an admin to visit. WP-Cron only fires on page hits,
		// so a truly idle site is the worst case — this lets us kick off
		// one more tick before giving up. Filter on fatal error types so
		// normal returns (where error_get_last() is null or a warning)
		// don't trigger a spurious spawn.
		$self = $this;
		register_shutdown_function(
			function () use ( $self ) {
				$err = error_get_last();
				if ( $err && in_array( $err['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) ) {
						$self->release_queue_lock_public();
						$self->spawn_next_tick_public();
				}
			}
		);

		$tick_start = time();
		$s          = $this->get_settings();
		$pw         = SBU_Crypto::decrypt( $s['pass'] );
		$lim        = $this->get_adaptive_limits();
		// The adaptive limits above are static per-request ceilings. The
		// live transfer parameters come from the rate_state on the queue,
		// which an AIMD controller updates after every batch: halve on
		// failure, two failures in a row -> emergency mode (1 × 2 MB).
		// On a brand-new queue (or a pre-1.5.0 queue resumed after
		// upgrade) we seed the state from the adaptive ceilings.
		if ( empty( $queue['rate_state'] ) || ! is_array( $queue['rate_state'] ) ) {
			$queue['rate_state'] = array(
				'chunk_mb'        => (int) $lim['chunk_mb_download'],
				'parallel'        => (int) $lim['parallel_downloads'],
				'consecutive_bad' => 0,
				'mode'            => 'cruise',
			);
		}

		$t = SBU_Seafile_API::get_token( $s['url'], $s['user'], $pw );
		if ( is_wp_error( $t ) ) {
			$t = SBU_Seafile_API::get_token( $s['url'], $s['user'], $pw, true );
			if ( is_wp_error( $t ) ) {
				$queue['status'] = 'error';
				update_option( SBU_QUEUE, $queue, false );
				$this->activity_logger->log( 'FEHLER', __( 'Restore abgebrochen: Auth fehlgeschlagen', 'seafile-updraft-backup-uploader' ) );
				return;
			}
		}

		$rid = $queue['library_id'];

		// Log the adaptive config on the first tick of a restore so users
		// can see what the plugin picked for their server. Gated on a
		// per-queue flag so long restores don't re-log every tick.
		if ( empty( $queue['adaptive_logged'] ) ) {
			$this->activity_logger->log(
				'INFO',
				sprintf(
					/* translators: %1$d tick budget seconds, %2$d parallel downloads, %3$d chunk MB */
					__( 'Restore-Konfiguration: %1$ds Tick-Budget, %2$d parallele Chunks à %3$d MB', 'seafile-updraft-backup-uploader' ),
					$lim['tick_time'],
					$lim['parallel_downloads'],
					$lim['chunk_mb_download']
				)
			);
			$queue['adaptive_logged'] = true;
			$this->safe_queue_update( $queue );
		}

		// Debug-only: mark the start of this tick with the queue fingerprint
		// (done / pending / current) so the debug log can be grep'd by tick
		// boundary. Not on in normal mode — a 70-file restore would spam this.
		$done_count    = 0;
		$pending_count = 0;
		$current_fn    = '';
		foreach ( $queue['files'] as $ridx => $rf ) {
			$rstatus = $rf['status'] ?? '';
			if ( $rstatus === 'done' ) {
				++$done_count;
			} elseif ( $ridx === ( $queue['file_idx'] ?? 0 ) ) {
				$current_fn = $rf['name'];
			} elseif ( $rstatus !== 'error' ) {
				++$pending_count;
			}
		}
		$this->activity_logger->log(
			'TICK',
			sprintf(
				'start, budget=%ds, mode=%s, chunk_mb=%d, parallel=%d, files=%d done / %s current / %d pending',
				(int) $lim['tick_time'],
				$queue['rate_state']['mode'] ?? 'cruise',
				(int) ( $queue['rate_state']['chunk_mb'] ?? $lim['chunk_mb_download'] ),
				(int) ( $queue['rate_state']['parallel'] ?? $lim['parallel_downloads'] ),
				$done_count,
				$current_fn !== '' ? $current_fn : '—',
				$pending_count
			),
			'debug'
		);

		$restore_file_count = count( $queue['files'] );
		while ( ( $queue['file_idx'] ?? 0 ) < $restore_file_count ) {
			if ( $this->tick_budget_exhausted( $tick_start ) ) {
				break;
			}

			// Check abort
			if ( $this->is_aborted() ) {
				$queue['status'] = 'aborted';
				update_option( SBU_QUEUE, $queue, false );
				wp_clear_scheduled_hook( SBU_CRON_HOOK );
				$this->activity_logger->log( 'INFO', __( 'Wiederherstellung abgebrochen', 'seafile-updraft-backup-uploader' ) );
				return;
			}

			$idx    = $queue['file_idx'];
			$fi     = $queue['files'][ $idx ];
			$fn     = $fi['name'];
			$path   = $fi['path'];
			$dest   = $fi['dest'];
			$fs     = $fi['size'];
			$offset = $fi['offset'] ?? 0;

			if ( $offset === 0 ) {
				$queue['files'][ $idx ]['status'] = 'downloading';
				@unlink( $dest ); // Clean start
				$file_num = $idx + 1;
				$total    = count( $queue['files'] );
				$this->activity_logger->log(
					'RESTORE',
					sprintf(
						/* translators: %1$d file number, %2$d total files, %3$s filename, %4$.1f MB size */
						__( 'Datei %1$d/%2$d: Lade %3$s (%4$s MB) von Seafile…', 'seafile-updraft-backup-uploader' ),
						$file_num,
						$total,
						$fn,
						number_format_i18n( round( $fs / 1048576, 1 ), 1 )
					)
				);
			}

			// Get download link
			$link = SBU_Seafile_API::get_download_link( $s['url'], $t, $rid, $path );
			if ( is_wp_error( $link ) ) {
				$t = SBU_Seafile_API::get_token( $s['url'], $s['user'], $pw, true );
				if ( ! is_wp_error( $t ) ) {
					$link = SBU_Seafile_API::get_download_link( $s['url'], $t, $rid, $path );
				}
				if ( is_wp_error( $link ) ) {
					$retries                           = ( $queue['files'][ $idx ]['retries'] ?? 0 ) + 1;
					$queue['files'][ $idx ]['retries'] = $retries;
					$delay                             = self::compute_retry_delay( $retries, 'transient' );
					$queue['next_retry_delay']         = $delay;
					$queue['next_allowed_tick_ts']     = time() + $delay;
					$queue['last_activity']            = time();
					if ( $this->safe_queue_update( $queue ) !== 'restoring' ) {
						wp_clear_scheduled_hook( SBU_CRON_HOOK );
						return;
					}
					$this->activity_logger->log(
						'RETRY',
						sprintf(
							/* translators: %1$s file name, %2$s error, %3$d attempt, %4$d delay seconds */
							__( 'Restore %1$s: Download-Link fehlgeschlagen (%2$s) — Versuch %3$d, nächster Versuch in %4$ds', 'seafile-updraft-backup-uploader' ),
							$fn,
							$link->get_error_message(),
							$retries,
							$delay
						)
					);
					break; // Exit file loop, next tick will retry
				}
			}

			// Stream-first: a single non-Range GET before falling back to
			// the Range-chunking path below. This hits the same server
			// code path Seafile's web client and desktop client use.
			// The 1.5.1 dogfood restore showed the Range path serves
			// HTTP 200/206 with empty bodies past ~88 MB on large files;
			// single-stream doesn't. We only attempt this at offset 0 so
			// partially-downloaded files resume on the Range path without
			// discarding already-committed bytes, and only once per file
			// (stream_tried flag) so a failed stream doesn't burn 95 s
			// out of every subsequent tick.
			$stream_cap_bytes = 500 * 1024 * 1024; // 500 MB — upper bound where a single stream comfortably fits in CF Tunnel's ~100 s response window even on slow links.
			$stream_tried     = ! empty( $queue['files'][ $idx ]['stream_tried'] );
			if ( $offset === 0 && $fs > 0 && $fs <= $stream_cap_bytes && ! $stream_tried ) {
				$tick_time_s                            = (int) $lim['tick_time'];
				$stream_budget                          = max( 30, min( 95, $tick_time_s - 30 ) );
				$stream_t0                              = microtime( true );
				$stream_res                             = SBU_Seafile_API::download_whole_file_stream(
					$s['url'],
					$t,
					$rid,
					$path,
					$dest,
					$stream_budget
				);
				$stream_dt                              = microtime( true ) - $stream_t0;
				$queue['files'][ $idx ]['stream_tried'] = true;
				if ( $stream_res === true ) {
					$offset                            = $fs;
					$queue['files'][ $idx ]['offset']  = $offset;
					$queue['files'][ $idx ]['retries'] = 0;
					$queue['last_activity']            = time();
					unset( $queue['next_allowed_tick_ts'] );
					$this->activity_logger->log(
						'RESTORE',
						sprintf(
							'%s: Stream-Download ok (%.1f MB in %.1fs)',
							$fn,
							round( $fs / 1048576, 1 ),
							round( $stream_dt, 1 )
						),
						'debug'
					);
				} else {
					@unlink( $dest );
					$err_msg = $stream_res instanceof \WP_Error
						? $stream_res->get_error_message()
						: 'unknown';
					$this->activity_logger->log(
						'INFO',
						sprintf(
							/* translators: %1$s filename, %2$s error, %3$.1f seconds */
							__( 'Stream-Download für %1$s fehlgeschlagen (%2$s) nach %3$ss — Fallback auf Range-Chunks', 'seafile-updraft-backup-uploader' ),
							$fn,
							$err_msg,
							round( $stream_dt, 1 )
						)
					);
				}
			}

			// Range-based chunked download loop. Each iteration fetches up
			// to $parallel contiguous Range requests simultaneously via
			// curl_multi. Chunks are streamed to per-batch temp files
			// (dest.partN) so RAM stays bounded at parallel × chunk_size.
			// parallel and chunk size are read from rate_state each batch
			// so the AIMD controller can shrink the next batch after a
			// stall and grow it back after successful runs.
			// This is now the *fallback* path — the stream attempt above
			// handles most files in one request.
			while ( $offset < $fs ) {
				if ( $this->tick_budget_exhausted( $tick_start ) ) {
					break;
				}
				if ( $this->is_aborted() ) {
					$queue['status'] = 'aborted';
					update_option( SBU_QUEUE, $queue, false );
					wp_clear_scheduled_hook( SBU_CRON_HOOK );
					$this->activity_logger->log( 'INFO', __( 'Wiederherstellung abgebrochen', 'seafile-updraft-backup-uploader' ) );
					return;
				}
				// Pause check: persist offset and exit quietly.
				wp_cache_delete( SBU_QUEUE, 'options' );
				$fresh_q = get_option( SBU_QUEUE );
				if ( is_array( $fresh_q ) && ( $fresh_q['status'] ?? '' ) === 'paused' ) {
					$queue['files'][ $idx ]['offset'] = $offset;
					$this->safe_queue_update( $queue );
					wp_clear_scheduled_hook( SBU_CRON_HOOK );
					$this->activity_logger->log(
						'INFO',
						sprintf(
							/* translators: %1$s file name, %2$s progress string */
							__( 'Wiederherstellung pausiert bei %1$s (%2$s)', 'seafile-updraft-backup-uploader' ),
							$fn,
							$this->format_progress( $offset, $fs )
						)
					);
					return;
				}

				// Live rate-state read — the AIMD controller may have
				// adjusted parallel/chunk_mb on the previous batch's
				// outcome. Clamped against the static ceilings so a
				// corrupted queue can't request more than the host can do.
				$parallel = max(
					1,
					min(
						(int) $lim['parallel_downloads'],
						(int) ( $queue['rate_state']['parallel'] ?? $lim['parallel_downloads'] )
					)
				);
				$csz      = max(
					1,
					min(
						(int) $lim['chunk_mb_download'],
						(int) ( $queue['rate_state']['chunk_mb'] ?? $lim['chunk_mb_download'] )
					)
				) * 1048576;

				// Build the next contiguous batch. Each chunk gets its own
				// fresh signed URL — Seafile's signed download links are
				// effectively single-use / replay-protected, so firing one
				// URL N× in parallel returned HTTP 403 on all chunks in the
				// 1.4.0 dogfood restore. N × API roundtrip is cheap (the
				// /file/ endpoint returns a small JSON body); the cost is
				// dwarfed by the actual chunk transfers.
				$ranges = array();
				$cursor = $offset;
				for ( $n = 0; $n < $parallel && $cursor < $fs; $n++ ) {
					$link_n = SBU_Seafile_API::get_download_link( $s['url'], $t, $rid, $path );
					if ( is_wp_error( $link_n ) ) {
						$t_retry = SBU_Seafile_API::get_token( $s['url'], $s['user'], $pw, true );
						if ( ! is_wp_error( $t_retry ) ) {
							$t      = $t_retry;
							$link_n = SBU_Seafile_API::get_download_link( $s['url'], $t, $rid, $path );
						}
						if ( is_wp_error( $link_n ) ) {
							break 2; // Next tick retries
						}
					}
					$end      = min( $cursor + $csz - 1, $fs - 1 );
					$ranges[] = array(
						'url'   => $link_n,
						'start' => $cursor,
						'end'   => $end,
						'tmp'   => $dest . '.part' . $n,
					);
					$cursor   = $end + 1;
				}

				// Deadline that we hand to the parallel pump: it must return
				// before the PHP hard limit kicks in, so we clamp at the
				// tick budget minus a small safety margin. Per-chunk cURL
				// timeout is a bit smaller still, so a single frozen chunk
				// gets killed by cURL before the whole pump hits the wall.
				$tick_time_s   = (int) $lim['tick_time'];
				$chunk_timeout = max( 15, min( $tick_time_s - 3, 90 ) );
				$deadline_ts   = $tick_start + max( 10, $tick_time_s - 2 );

				$batch_t0 = microtime( true );
				$results  = SBU_Seafile_API::download_chunks_parallel( $t, $ranges, $chunk_timeout, $deadline_ts );
				$batch_dt = microtime( true ) - $batch_t0;

				// Detect the contiguous successful prefix starting at
				// index 0. Even one failed chunk in the middle of the
				// batch forces us to break the prefix — downstream code
				// only commits successful chunks whose offsets form an
				// unbroken range starting at the current file offset.
				ksort( $results );
				$prefix_ok_count = 0;
				$prefix_bytes    = 0;
				$got_whole_file  = false;
				foreach ( $results as $r ) {
					if ( empty( $r['ok'] ) ) {
						break;
					}
					++$prefix_ok_count;
					$prefix_bytes += (int) ( $r['bytes'] ?? 0 );
					if ( ( $r['code'] ?? 0 ) === 200 ) {
						$got_whole_file = true;
						break;
					}
				}
				$has_error      = $prefix_ok_count < count( $results );
				$full_batch_ok  = ! $has_error;
				$partial_prefix = $prefix_ok_count > 0 && $has_error;

				// One-shot fast retry. Only attempted when nothing in the
				// batch came back ok — a partial prefix means bytes are on
				// disk and we'd rather bank them and defer the remainder to
				// the next tick than throw them away. The retry path still
				// handles expired signed URLs, stale keepalives, and 0 byte
				// responses that aren't deadline-related.
				$deadline_hit_any = false;
				foreach ( $results as $r ) {
					if ( empty( $r['ok'] ) && isset( $r['error'] ) && $r['error'] instanceof \WP_Error && $r['error']->get_error_code() === 'deadline' ) {
						$deadline_hit_any = true;
						break;
					}
				}
				if ( $has_error && $prefix_ok_count === 0 && ! $deadline_hit_any ) {
					foreach ( $results as $r ) {
						if ( ! empty( $r['tmp'] ) && file_exists( $r['tmp'] ) ) {
							@unlink( $r['tmp'] );
						}
					}
					$t_retry = SBU_Seafile_API::get_token( $s['url'], $s['user'], $pw, true );
					if ( ! is_wp_error( $t_retry ) ) {
						$t          = $t_retry;
						$refresh_ok = true;
						foreach ( $ranges as $ri => $range ) {
							$fresh = SBU_Seafile_API::get_download_link( $s['url'], $t, $rid, $path );
							if ( is_wp_error( $fresh ) ) {
								$refresh_ok = false;
								break; }
							$ranges[ $ri ]['url'] = $fresh;
						}
						if ( $refresh_ok ) {
							$this->activity_logger->log(
								'INFO',
								sprintf(
									/* translators: %s filename */
									__( 'Download-Batch hängt. Sofort-Wiederholung mit frischen Links: %s', 'seafile-updraft-backup-uploader' ),
									$fn
								)
							);
							$batch_t0 = microtime( true );
							$results  = SBU_Seafile_API::download_chunks_parallel( $t, $ranges, $chunk_timeout, $deadline_ts );
							$batch_dt = microtime( true ) - $batch_t0;
							ksort( $results );
							$prefix_ok_count = 0;
							$prefix_bytes    = 0;
							$got_whole_file  = false;
							foreach ( $results as $r ) {
								if ( empty( $r['ok'] ) ) {
									break;
								}
								++$prefix_ok_count;
								$prefix_bytes += (int) ( $r['bytes'] ?? 0 );
								if ( ( $r['code'] ?? 0 ) === 200 ) {
									$got_whole_file = true;
									break;
								}
							}
							$has_error      = $prefix_ok_count < count( $results );
							$full_batch_ok  = ! $has_error;
							$partial_prefix = $prefix_ok_count > 0 && $has_error;
						}
					}
				}

				// AIMD rate-controller update. Classify every failed chunk
				// once, remember the dominant non-transient class for the
				// retry-gating below, and fold the aggregate outcome into
				// rate_state so the next batch grows or shrinks chunk_mb
				// and parallel without us needing to pass the full history
				// around. Transient / overload results feed the "bad" side;
				// deadline / auth / signed_url / client fire as warnings
				// but don't ding the speed dial (they're not transport
				// health signals).
				$any_transient  = false;
				$dominant_fatal = null;
				foreach ( $results as $r ) {
					if ( ! empty( $r['ok'] ) ) {
						continue;
					}
					$cls = self::classify_chunk_error( $r );
					if ( $cls === 'transient' || $cls === 'overload' ) {
						$any_transient = true;
					} elseif ( $dominant_fatal === null ) {
						$dominant_fatal = $cls;
					}
				}
				$outcome             = array(
					'ok'            => $full_batch_ok,
					'any_transient' => $any_transient,
				);
				$prev_state          = $queue['rate_state'];
				$queue['rate_state'] = self::update_rate_state(
					$prev_state,
					$outcome,
					array(
						'chunk_mb_max' => (int) $lim['chunk_mb_download'],
						'parallel_max' => (int) $lim['parallel_downloads'],
					)
				);
				// Log every rate-controller change. This is the signal an
				// admin watches to understand *why* the plugin shrank the
				// transfer window — without it the tick just "feels slow".
				// Mode flips and parameter changes go to the normal log;
				// unchanged batches emit a debug-only BATCH line further
				// down so the normal log doesn't drown in per-batch noise.
				$prev_mode  = $prev_state['mode'] ?? 'cruise';
				$curr_mode  = $queue['rate_state']['mode'];
				$prev_chunk = (int) ( $prev_state['chunk_mb'] ?? 0 );
				$curr_chunk = (int) $queue['rate_state']['chunk_mb'];
				$prev_par   = (int) ( $prev_state['parallel'] ?? 0 );
				$curr_par   = (int) $queue['rate_state']['parallel'];
				if ( $prev_mode !== $curr_mode || $prev_chunk !== $curr_chunk || $prev_par !== $curr_par ) {
					$reason = $full_batch_ok
						? 'batch ok'
						: ( $any_transient
							? ( $dominant_fatal ? "transient + {$dominant_fatal}" : 'transient' )
							: ( $dominant_fatal ?: 'batch fail' ) );
					$this->activity_logger->log(
						'RATE',
						sprintf(
							/* translators: %1$s prev mode, %2$s new mode, %3$d prev chunk MB, %4$d new chunk MB, %5$d prev parallel, %6$d new parallel, %7$s reason */
							__( '%1$s → %2$s (chunk_mb %3$d→%4$d, parallel %5$d→%6$d, Grund: %7$s)', 'seafile-updraft-backup-uploader' ),
							$prev_mode,
							$curr_mode,
							$prev_chunk,
							$curr_chunk,
							$prev_par,
							$curr_par,
							$reason
						)
					);
				}

				// Debug-only: per-chunk + per-batch transcript. The normal
				// log stays readable with just the file/error events, but
				// flipping the debug_log setting surfaces exactly which
				// byte range came back with which curl_errno / http code
				// / duration. This is what lets us tell a Cloudflare-
				// induced low-speed kill (errno 28, dauer≈15 s) from a
				// reset-by-peer (errno 56, dauer < 1 s) from an expired
				// signed URL (http 403) without guessing from aggregates.
				foreach ( $results as $r ) {
					$start_mb = round( ( $r['start'] ?? 0 ) / 1048576, 1 );
					$end_mb   = round( ( ( $r['end'] ?? 0 ) + 1 ) / 1048576, 1 );
					if ( ! empty( $r['ok'] ) ) {
						$this->activity_logger->log(
							'CHUNK',
							sprintf(
								'%s range=%s-%s MB OK bytes=%d dauer=%.2fs http=%d',
								$fn,
								$start_mb,
								$end_mb,
								(int) ( $r['bytes'] ?? 0 ),
								(float) ( $r['duration_s'] ?? 0 ),
								(int) ( $r['code'] ?? 0 )
							),
							'debug'
						);
					} else {
						$err_code = '';
						$err_msg  = '';
						if ( isset( $r['error'] ) && $r['error'] instanceof \WP_Error ) {
							$err_code = $r['error']->get_error_code();
							$err_msg  = $r['error']->get_error_message();
						}
						$this->activity_logger->log(
							'CHUNK',
							sprintf(
								'%s range=%s-%s MB FAIL bytes=%d dauer=%.2fs curl_errno=%d http=%d class=%s err=%s msg=%s',
								$fn,
								$start_mb,
								$end_mb,
								(int) ( $r['bytes'] ?? 0 ),
								(float) ( $r['duration_s'] ?? 0 ),
								(int) ( $r['curl_errno'] ?? 0 ),
								(int) ( $r['code'] ?? 0 ),
								self::classify_chunk_error( $r ),
								$err_code,
								$err_msg
							),
							'debug'
						);
					}
				}
				$this->activity_logger->log(
					'BATCH',
					sprintf(
						'%s %d/%d ok, %.1f MB in %.2fs, mode=%s chunk_mb=%d parallel=%d',
						$fn,
						$prefix_ok_count,
						count( $results ),
						$prefix_bytes / 1048576,
						$batch_dt,
						$curr_mode,
						$curr_chunk,
						$curr_par
					),
					'debug'
				);

				// If we have a successful prefix, commit it even on a
				// partial batch. This banks progress from partial deadlines
				// and stops the 160 MB infinite-loop pattern seen in 1.4.1
				// where a hanging 4th chunk threw away 3 completed chunks.
				if ( $prefix_ok_count > 0 ) {
					$fh = @fopen( $dest, $offset === 0 ? 'wb' : 'ab' );
					if ( ! $fh ) {
						foreach ( $results as $r ) {
							if ( ! empty( $r['tmp'] ) && file_exists( $r['tmp'] ) ) {
								@unlink( $r['tmp'] );
							}
						}
						/* translators: %s is the restore target filename that could not be opened for writing. */
						$this->activity_logger->log( 'FEHLER', sprintf( __( 'Restore %s: Zieldatei nicht beschreibbar', 'seafile-updraft-backup-uploader' ), $fn ) );
						break;
					}
					$new_offset = $offset;
					$i_written  = 0;
					foreach ( $results as $r ) {
						if ( $i_written >= $prefix_ok_count ) {
							break;
						}
						$th = @fopen( $r['tmp'], 'rb' );
						if ( $th ) {
							while ( ! feof( $th ) ) {
								fwrite( $fh, fread( $th, 65536 ) );
							}
							fclose( $th );
						}
						@unlink( $r['tmp'] );
						if ( ( $r['code'] ?? 0 ) === 200 ) {
							$got_whole_file = true;
							$new_offset     = $fs;
							++$i_written;
							break;
						}
						$new_offset = (int) $r['end'] + 1;
						++$i_written;
					}
					fclose( $fh );
					// Clean up unwritten temp files (those past the prefix
					// boundary, typically deadline or error chunks).
					foreach ( $results as $r ) {
						if ( ! empty( $r['tmp'] ) && file_exists( $r['tmp'] ) ) {
							@unlink( $r['tmp'] );
						}
					}
					$offset                           = $got_whole_file ? $fs : $new_offset;
					$queue['files'][ $idx ]['offset'] = $offset;
					$queue['last_activity']           = time();
					if ( $full_batch_ok ) {
						$queue['files'][ $idx ]['retries'] = 0;
						unset( $queue['next_allowed_tick_ts'] );
					}
					if ( $batch_dt > ( $tick_time_s * 0.75 ) ) {
						$batch_mb = round( $prefix_bytes / 1024 / 1024, 1 );
						$this->activity_logger->log(
							'WARNUNG',
							sprintf(
								/* translators: %1$s file, %2$s MB, %3$.1f seconds */
								__( 'Langsamer Download-Batch: %1$s %2$s MB in %3$ss', 'seafile-updraft-backup-uploader' ),
								$fn,
								$batch_mb,
								round( $batch_dt, 1 )
							)
						);
					}
					if ( $this->safe_queue_update( $queue ) !== 'restoring' ) {
						wp_clear_scheduled_hook( SBU_CRON_HOOK );
						return;
					}
				}

				// Partial prefix: some chunks ok, some failed. Persist the
				// ok bytes (already done above), log a retry for the tail,
				// schedule a short backoff, break out of BOTH the batch
				// loop and the file loop (break 2) — the tick is over. A
				// plain break would re-enter the same file on the outer
				// loop and retry immediately inside the same tick, which
				// is the 4-retries-per-minute pattern from the 1.4.3 log.
				if ( $partial_prefix ) {
					$first_err = null;
					foreach ( $results as $r ) {
						if ( empty( $r['ok'] ) && isset( $r['error'] ) ) {
							$first_err = $r['error'];
							break; }
					}
					$err_kind = ( $first_err instanceof \WP_Error && $first_err->get_error_code() === 'empty' )
						? 'empty' : 'transient';
					// Retry counter only counts transport-level flakiness.
					// A pure deadline/auth/signed_url/client failure is
					// not evidence of a flaky transport, so we schedule a
					// short pause without inflating the retry budget.
					// Partial prefix means bytes landed, so we use a
					// softer cap than the total-failure path below (half
					// the exponential tiers, capped at 15 min).
					if ( $any_transient ) {
						$retries                           = ( $queue['files'][ $idx ]['retries'] ?? 0 ) + 1;
						$queue['files'][ $idx ]['retries'] = $retries;
						$delay                             = min( (int) ceil( self::compute_retry_delay( $retries, $err_kind ) / 2 ), 900 );
					} else {
						$retries = ( $queue['files'][ $idx ]['retries'] ?? 0 );
						$delay   = 10;
					}
					$queue['last_activity']        = time();
					$queue['next_retry_delay']     = $delay;
					$queue['next_allowed_tick_ts'] = time() + $delay;
					if ( $this->safe_queue_update( $queue ) !== 'restoring' ) {
						wp_clear_scheduled_hook( SBU_CRON_HOOK );
						return;
					}
					$err_msg = $first_err instanceof \WP_Error ? $first_err->get_error_message() : 'unknown';
					$this->activity_logger->log(
						'INFO',
						sprintf(
							/* translators: %1$s file, %2$d committed chunks, %3$d total, %4$s error, %5$d delay */
							__( 'Restore %1$s: Teilweise erfolgreich (%2$d/%3$d Chunks committed, Rest: %4$s) — Fortsetzung in %5$ds', 'seafile-updraft-backup-uploader' ),
							$fn,
							$prefix_ok_count,
							count( $results ),
							$err_msg,
							$delay
						)
					);
					break 2;
				}

				// Total failure (no prefix): persist, defer, install
				// backoff, end the tick. break 2 exits both the batch
				// loop and the outer file loop — otherwise the outer
				// loop re-enters the same file in the same tick and
				// burns through retries in seconds (1.4.3 bug).
				if ( $has_error && $prefix_ok_count === 0 ) {
					$first_err = null;
					foreach ( $results as $r ) {
						if ( ! empty( $r['tmp'] ) && file_exists( $r['tmp'] ) ) {
							@unlink( $r['tmp'] );
						}
						if ( empty( $r['ok'] ) && $first_err === null && isset( $r['error'] ) ) {
							$first_err = $r['error'];
						}
					}
					$err_kind = ( $first_err instanceof \WP_Error && $first_err->get_error_code() === 'empty' )
						? 'empty' : 'transient';
					if ( $any_transient ) {
						$retries                           = ( $queue['files'][ $idx ]['retries'] ?? 0 ) + 1;
						$queue['files'][ $idx ]['retries'] = $retries;
						$delay                             = self::compute_retry_delay( $retries, $err_kind );
					} else {
						$retries = ( $queue['files'][ $idx ]['retries'] ?? 0 );
						$delay   = 15;
					}
					$queue['files'][ $idx ]['offset'] = $offset;
					$queue['last_activity']           = time();
					$queue['next_retry_delay']        = $delay;
					$queue['next_allowed_tick_ts']    = time() + $delay;
					if ( $this->safe_queue_update( $queue ) !== 'restoring' ) {
						wp_clear_scheduled_hook( SBU_CRON_HOOK );
						return;
					}
					$err_msg = $first_err instanceof \WP_Error ? $first_err->get_error_message() : 'unknown';
					$this->activity_logger->log(
						'RETRY',
						sprintf(
							/* translators: %1$s file, %2$s progress, %3$.1f duration, %4$s error, %5$d attempt, %6$d delay */
							__( 'Restore %1$s: Fehler bei %2$s nach %3$ss (%4$s) — Versuch %5$d, nächster Versuch in %6$ds', 'seafile-updraft-backup-uploader' ),
							$fn,
							$this->format_progress( $offset, $fs ),
							round( $batch_dt, 1 ),
							$err_msg,
							$retries,
							$delay
						)
					);
					break 2;
				}
			}

			// File done?
			if ( $offset >= $fs ) {
				if ( $queue['files'][ $idx ]['status'] !== 'error' ) {
					$queue['files'][ $idx ]['status'] = 'done';
					++$queue['ok'];
					$queue['total_bytes'] += $fs;
					$mb                    = file_exists( $dest ) ? round( filesize( $dest ) / 1024 / 1024, 1 ) : 0;

					// Integrity check: compare the downloaded file's SHA1 to
					// the hash captured at upload time. No extra bandwidth —
					// the file is already on disk. sha1_file streams, so RAM
					// usage stays bounded even for multi-GB files.
					$expected      = $queue['files'][ $idx ]['expected_sha1'] ?? '';
					$verify_status = $this->verify_restored_file( $fn, $dest, $expected );
					if ( $verify_status === 'mismatch' ) {
						$queue['files'][ $idx ]['status'] = 'error';
						$queue['err']                     = ( $queue['err'] ?? 0 ) + 1;
						$queue['ok']                      = max( 0, $queue['ok'] - 1 );
					} else {
						$badge = $verify_status === 'verified' ? "\xe2\x9c\x93\xe2\x9c\x93" : "\xe2\x9c\x93";
						$this->activity_logger->log( 'RESTORE', "{$badge} {$fn} ({$mb} MB)" );
					}
				}
				++$queue['file_idx'];
			}

			$queue['last_activity'] = time();
			if ( $this->safe_queue_update( $queue ) !== 'restoring' ) {
				wp_clear_scheduled_hook( SBU_CRON_HOOK );
				return;
			}
		}

		if ( ( $queue['file_idx'] ?? 0 ) >= count( $queue['files'] ) ) {
			$queue['status'] = 'done';
			update_option( SBU_QUEUE, $queue, false );
			delete_transient( 'sbu_progress' );
			$ok  = $queue['ok'];
			$err = $queue['err'] ?? 0;
			$dir = $queue['dir'] ?? '?';
			if ( $err > 0 ) {
				$this->activity_logger->log( 'FEHLER', "Wiederherstellung mit Fehlern: {$dir} ({$ok} OK, {$err} Fehler)" );
				$this->log_failed_files( $queue, 'Restore' );
			} else {
				$this->activity_logger->log( 'RESTORE', __( 'Backup vollständig wiederhergestellt', 'seafile-updraft-backup-uploader' ) . ": {$dir} ({$ok} Dateien)" );
				$total_bytes = 0;
				foreach ( $queue['files'] as $_fi ) {
					$total_bytes += (int) ( $_fi['size'] ?? 0 );
				}
				$dir_nice = $dir;
				if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})_(\d{2})(\d{2})/', $dir, $_m ) ) {
					$dir_nice = "{$_m[3]}.{$_m[2]}.{$_m[1]} {$_m[4]}:{$_m[5]}";
				}
				update_option(
					'sbu_last_restore_success',
					array(
						'ts'        => time(),
						'dir'       => $dir,
						'dir_nice'  => $dir_nice,
						'files'     => (int) $ok,
						'bytes'     => $total_bytes,
						'duration'  => max( 0, time() - (int) ( $queue['started'] ?? time() ) ),
						'dismissed' => 0,
					),
					false
				);
			}
		} else {
			$delay = $queue['next_retry_delay'] ?? 60;
			unset( $queue['next_retry_delay'] );
			if ( $this->safe_queue_update( $queue ) !== 'restoring' ) {
				wp_clear_scheduled_hook( SBU_CRON_HOOK );
				return;
			}
			$this->queue_engine->schedule_next_tick( $delay );
			// Always spawn a loopback — entry-point gate (next_allowed_tick_ts)
			// bounces premature pings during backoff, so this is safe.
			$this->queue_engine->spawn_next_tick();
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

	/**
	 * Upload one chunk of a file.
	 *
	 * @return int|true|WP_Error New offset, true if done, or error.
	 */
	private function upload_one_chunk( $link, $tok, $bdir, $fp, $offset, $fs, $csz ) {
		$fn  = basename( $fp );
		$len = min( $csz, $fs - $offset );

		$fh = fopen( $fp, 'rb' );
		if ( ! $fh ) {
			return new \WP_Error( 'read', "Cannot open: {$fn}" );
		}
		if ( $offset > 0 ) {
			fseek( $fh, $offset );
		}
		$data = fread( $fh, $len );
		fclose( $fh );

		if ( $data === false ) {
			return new \WP_Error( 'read', "Read error: {$fn}" );
		}

		$actual = strlen( $data );
		$st     = $offset;
		$en     = $offset + $actual - 1;
		$b      = wp_generate_password( 24, false );

		$body  = "--{$b}\r\nContent-Disposition: form-data; name=\"parent_dir\"\r\n\r\n{$bdir}\r\n";
		$body .= "--{$b}\r\nContent-Disposition: form-data; name=\"replace\"\r\n\r\n1\r\n";
		$body .= "--{$b}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"" . addslashes( $fn ) . "\"\r\nContent-Type: application/octet-stream\r\n\r\n";
		$body .= $data . "\r\n--{$b}--\r\n";
		unset( $data );

		$headers = array(
			'Authorization' => 'Token ' . $tok,
			'Content-Type'  => 'multipart/form-data; boundary=' . $b,
		);
		if ( $fs > $csz ) {
			$headers['Content-Range']       = "bytes {$st}-{$en}/{$fs}";
			$headers['Content-Disposition'] = 'attachment; filename="' . addslashes( $fn ) . '"';
		}

		$resp = wp_remote_post(
			$link . '?ret-json=1',
			array(
				'timeout' => SBU_TIMEOUT_UPLOAD,
				'headers' => $headers,
				'body'    => $body,
			)
		);
		unset( $body );

		$result = $this->validate_response( $resp, $fn );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$new_offset = $offset + $actual;
		return $new_offset >= $fs ? true : $new_offset;
	}

	/**
	 * Validate an HTTP response.
	 */
	private function validate_response( $resp, $ctx ) {
		if ( is_wp_error( $resp ) ) {
			return new \WP_Error( 'net', "{$ctx}: " . $resp->get_error_message() );
		}
		$code = wp_remote_retrieve_response_code( $resp );
		if ( $code < 200 || $code >= 300 ) {
			$body = substr( wp_remote_retrieve_body( $resp ), 0, 200 );
			return new \WP_Error( 'http', "{$ctx}: HTTP {$code} - {$body}" );
		}
		return true;
	}

	/**
	 * Finalize the queue.
	 */
	private function finish_queue( $queue ) {
		$s  = $this->get_settings();
		$pw = SBU_Crypto::decrypt( $s['pass'] );

		$queue['status'] = 'done';
		update_option( SBU_QUEUE, $queue, false );

		$tmb     = round( $queue['total_bytes'] / 1024 / 1024, 1 );
		$ok      = $queue['ok'];
		$err     = $queue['err'];
		$ts      = $queue['ts'];
		$success = ( $err === 0 );
		$sum     = "{$ok} " . __( 'hochgeladen', 'seafile-updraft-backup-uploader' ) . ", {$err} " . __( 'Fehler', 'seafile-updraft-backup-uploader' ) . " ({$tmb} MB)";

		update_option(
			SBU_STAT,
			array(
				'success' => $success,
				'date'    => current_time( 'd.m.Y H:i' ),
				'files'   => $ok,
				'size_mb' => $tmb,
				'errors'  => $err,
				'dir'     => $ts,
			),
			false
		);

		if ( $success ) {
			$this->activity_logger->log( 'UPLOAD', __( 'Backup komplett', 'seafile-updraft-backup-uploader' ) . ": {$ok} Dateien ({$tmb} MB) \xe2\x86\x92 {$ts}" );
		} else {
			$this->activity_logger->log( 'FEHLER', __( 'Upload mit Fehlern', 'seafile-updraft-backup-uploader' ) . ": {$ok} OK, {$err} Fehler \xe2\x86\x92 {$ts}" );
			$this->log_failed_files( $queue, 'Upload' );
		}

		$t = SBU_Seafile_API::get_token( $s['url'], $s['user'], $pw );
		if ( ! is_wp_error( $t ) ) {
			// Verify upload integrity
			$bdir                     = $queue['bdir'] ?? '';
			$vresult                  = $this->verify_backup( $s['url'], $t, $queue['library_id'], $bdir, $queue );
			$verified                 = get_option( 'sbu_verified', array() );
			$verified[ $queue['ts'] ] = $vresult;
			// Keep only last 50 entries
			if ( count( $verified ) > 50 ) {
				$verified = array_slice( $verified, -50, 50, true );
			}
			update_option( 'sbu_verified', $verified, false );
			if ( $vresult['status'] === 'complete' ) {
				$this->persist_backup_hashes( $queue );
				$this->activity_logger->log(
					'VERIFIZIERT',
					sprintf(
						/* translators: %1$d total, %2$d with sha1 */
						__( 'Größe OK: %1$d Dateien (%2$d mit Prüfsumme für Restore)', 'seafile-updraft-backup-uploader' ),
						$vresult['files'] ?? 0,
						$vresult['sha1_kept'] ?? 0
					)
				);
			} else {
				$issue_list = implode( ', ', $vresult['issues'] ?? array() );
				$this->activity_logger->log( 'WARNUNG', __( 'Backup unvollständig', 'seafile-updraft-backup-uploader' ) . ': ' . $issue_list );
			}

			$this->enforce_retention( $s, $t, $queue['library_id'] );
		}

		if ( $s['del_local'] && $ok > 0 ) {
			$this->cleanup_updraft_history();
		}

		$this->mail_notifier->send( $success, $sum );
		wp_clear_scheduled_hook( SBU_CRON_HOOK );
	}

	/**
	 * Verify uploaded backup against Seafile via size check.
	 *
	 * SHA1 hashes for each file are captured at upload time (lazy, one
	 * sha1_file per file during its first tick) and persisted in the
	 * SBU_HASHES option after this verification succeeds. The restore path
	 * streamingly re-computes SHA1 during download and compares against the
	 * stored value — no second full-file readback is needed.
	 *
	 * @param string $url   Seafile URL.
	 * @param string $t     Auth token.
	 * @param string $rid   Library ID.
	 * @param string $bdir  Backup directory path on Seafile.
	 * @param array  $queue Upload queue with file list.
	 * @return array Verification result with status, file count, and issues.
	 */
	private function verify_backup( $url, $t, $rid, $bdir, $queue ) {
		$remote_files = SBU_Seafile_API::list_directory( $url, $t, $rid, $bdir );
		if ( is_wp_error( $remote_files ) ) {
			return array(
				'status'  => 'error',
				'msg'     => 'Verzeichnis nicht lesbar',
				'checked' => current_time( 'd.m.Y H:i' ),
			);
		}

		$remote_map = array();
		foreach ( $remote_files as $f ) {
			if ( ( $f['type'] ?? 'file' ) === 'file' ) {
				$remote_map[ $f['name'] ] = $f['size'] ?? 0;
			}
		}

		$total     = 0;
		$ok        = 0;
		$sha_count = 0;
		$issues    = array();
		foreach ( $queue['files'] as $fi ) {
			if ( ( $fi['status'] ?? '' ) !== 'done' ) {
				continue;
			}
			++$total;
			$fn = basename( $fi['path'] );

			if ( ! isset( $remote_map[ $fn ] ) ) {
				$issues[] = $fn . ': ' . __( 'fehlt auf Seafile', 'seafile-updraft-backup-uploader' );
				continue;
			}
			if ( absint( $remote_map[ $fn ] ) !== absint( $fi['size'] ) ) {
				$local_mb  = round( $fi['size'] / 1024 / 1024, 2 );
				$remote_mb = round( $remote_map[ $fn ] / 1024 / 1024, 2 );
				$issues[]  = $fn . ": lokal {$local_mb} MB / remote {$remote_mb} MB";
				continue;
			}
			++$ok;
			if ( ! empty( $fi['sha1'] ) ) {
				++$sha_count;
			}
		}

		$checked = current_time( 'd.m.Y H:i' );
		if ( empty( $issues ) && $ok > 0 ) {
			return array(
				'status'    => 'complete',
				'files'     => $ok,
				'sha1_kept' => $sha_count,
				'mode'      => 'size',
				'checked'   => $checked,
			);
		}
		return array(
			'status'    => 'incomplete',
			'ok'        => $ok,
			'sha1_kept' => $sha_count,
			'total'     => $total,
			'issues'    => $issues,
			'mode'      => 'size',
			'checked'   => $checked,
		);
	}

	/**
	 * Persist SHA1 hashes of successfully uploaded files, keyed by backup folder.
	 *
	 * The restore path looks up this map to verify integrity during download
	 * without needing an extra round-trip. Only files that were uploaded "done"
	 * AND have a captured sha1 are stored; files missing a hash stay silently
	 * unverifiable (will log a WARNUNG on restore if that happens).
	 *
	 * @param array $queue Upload queue after completion.
	 */
	private function persist_backup_hashes( $queue ) {
		$bdir = $queue['bdir'] ?? '';
		if ( $bdir === '' ) {
			return;
		}

		$hashes = array();
		foreach ( $queue['files'] as $fi ) {
			if ( ( $fi['status'] ?? '' ) !== 'done' ) {
				continue;
			}
			$sha = $fi['sha1'] ?? '';
			if ( $sha === '' ) {
				continue;
			}
			$fn            = basename( $fi['path'] );
			$hashes[ $fn ] = $sha;
		}

		if ( empty( $hashes ) ) {
			return;
		}

		$all = get_option( SBU_HASHES, array() );
		if ( ! is_array( $all ) ) {
			$all = array();
		}
		$all[ $bdir ] = $hashes;

		// Keep at most 50 folders — retention deletes older ones, but guard
		// against drift if a user turns retention off entirely.
		if ( count( $all ) > 50 ) {
			$all = array_slice( $all, -50, 50, true );
		}
		update_option( SBU_HASHES, $all, false );
	}

	// RETENTION
	// =========================================================================

	/**
	 * Delete old backup folders exceeding the retention limit.
	 *
	 * @param array  $s   Plugin settings.
	 * @param string $t   Auth token.
	 * @param string $rid Library ID.
	 */
	private function enforce_retention( $s, $t, $rid ) {
		if ( intval( $s['retention'] ) === 0 ) {
			return; // 0 = keep all
		}

		$items = SBU_Seafile_API::list_directory( $s['url'], $t, $rid, $s['folder'] );
		if ( is_wp_error( $items ) ) {
			return;
		}

		$dirs = array();
		foreach ( $items as $it ) {
			if ( ( $it['type'] ?? '' ) === 'dir' ) {
				$dirs[] = $it['name'];
			}
		}
		if ( count( $dirs ) <= $s['retention'] ) {
			return;
		}

		rsort( $dirs );
		$old            = array_slice( $dirs, $s['retention'] );
		$hashes         = get_option( SBU_HASHES, array() );
		$hashes_changed = false;
		foreach ( $old as $d ) {
			$path   = rtrim( $s['folder'], '/' ) . '/' . $d;
			$result = SBU_Seafile_API::delete_directory( $s['url'], $t, $rid, $path );
			if ( is_wp_error( $result ) ) {
				$this->activity_logger->log( 'FEHLER', __( 'Altes Backup konnte nicht gelöscht werden', 'seafile-updraft-backup-uploader' ) . ": {$d} — " . $result->get_error_message() );
			} else {
				$this->activity_logger->log( 'LÖSCHEN', __( 'Altes Backup automatisch gelöscht (Aufbewahrung)', 'seafile-updraft-backup-uploader' ) . ": {$d}" );
				if ( is_array( $hashes ) && isset( $hashes[ $path ] ) ) {
					unset( $hashes[ $path ] );
					$hashes_changed = true;
				}
			}
		}
		if ( $hashes_changed ) {
			update_option( SBU_HASHES, $hashes, false );
		}
	}

	/**
	 * Removes UpdraftPlus backup history entries where local files no longer exist.
	 * This prevents "ghost" entries in UpdraftPlus after local files are deleted.
	 */
	private function cleanup_updraft_history() {
		$history = get_option( 'updraft_backup_history', array() );
		if ( empty( $history ) || ! is_array( $history ) ) {
			return;
		}

		$ud = $this->get_updraft_dir();
		if ( ! $ud ) {
			return;
		}

		$changed = false;
		foreach ( $history as $ts => $backup ) {
			// Check if any file from this backup set still exists locally
			$has_local = false;
			$file_keys = array( 'plugins', 'themes', 'uploads', 'others', 'db' );
			foreach ( $file_keys as $key ) {
				if ( ! empty( $backup[ $key ] ) && is_array( $backup[ $key ] ) ) {
					foreach ( $backup[ $key ] as $file ) {
						if ( file_exists( $ud . '/' . $file ) ) {
							$has_local = true;
							break 2;
						}
					}
				}
				// DB can also be a string
				if ( $key === 'db' && ! empty( $backup[ $key ] ) && is_string( $backup[ $key ] ) ) {
					if ( file_exists( $ud . '/' . $backup[ $key ] ) ) {
						$has_local = true;
						break;
					}
				}
			}

			if ( ! $has_local ) {
				unset( $history[ $ts ] );
				$changed = true;
			}
		}

		if ( $changed ) {
			update_option( 'updraft_backup_history', $history );
			$this->activity_logger->log( 'BEREINIGUNG', __( 'UpdraftPlus-Verlauf bereinigt (lokale Dateien gelöscht, Einträge entfernt)', 'seafile-updraft-backup-uploader' ) );
		}
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
	 * Find UpdraftPlus backup files (zip/gz/crypt/sql) belonging to a single
	 * backup set in a directory.
	 *
	 * UpdraftPlus writes filenames of the form
	 *     backup_YYYY-MM-DD-HHMM_Site-Name_<12hexNonce>-<type><N>.ext
	 * Every file in a single backup set shares the same 12-char hex nonce.
	 * Without filtering by nonce, a new backup would pick up ANY leftover
	 * backup files from prior runs (common on sites that keep several local
	 * backups) and re-upload them as part of the current job, producing
	 * bloated Seafile folders with mixed backup sets.
	 *
	 * @param string $dir   Directory to scan.
	 * @param string $nonce Optional 12-char UpdraftPlus backup nonce. When
	 *                      provided, only files matching that nonce are
	 *                      returned. When empty, the newest backup set
	 *                      (by highest file mtime within a nonce group) is
	 *                      returned.
	 * @return array Sorted list of absolute file paths belonging to one set.
	 */
	private function find_backup_files( $dir, $nonce = '' ) {
		$all = array_merge(
			glob( $dir . '/backup_*.zip' ) ?: array(),
			glob( $dir . '/backup_*.gz' ) ?: array(),
			glob( $dir . '/backup_*.crypt' ) ?: array(),
			glob( $dir . '/backup_*.sql' ) ?: array()
		);
		$all = array_filter(
			$all,
			function ( $x ) {
				return ! preg_match( '/\.(tmp|list\.tmp|list-temp\.tmp)$/i', $x );
			}
		);

		if ( $nonce !== '' && preg_match( '/^[a-f0-9]{12}$/i', $nonce ) ) {
			$filtered = array();
			foreach ( $all as $fp ) {
				if ( self::extract_backup_nonce( basename( $fp ) ) === strtolower( $nonce ) ) {
					$filtered[] = $fp;
				}
			}
			sort( $filtered );
			return $filtered;
		}

		// No nonce given (manual upload trigger): group by nonce and pick
		// the newest set. Files without a parseable nonce land under '' and
		// share one group, preserving legacy behavior for edge-case layouts.
		$groups = array();
		$newest = array();
		foreach ( $all as $fp ) {
			$n              = self::extract_backup_nonce( basename( $fp ) );
			$groups[ $n ][] = $fp;
			$mtime          = @filemtime( $fp );
			if ( $mtime !== false && ( ! isset( $newest[ $n ] ) || $mtime > $newest[ $n ] ) ) {
				$newest[ $n ] = $mtime;
			}
		}
		if ( empty( $groups ) ) {
			return array();
		}
		arsort( $newest );
		$pick = array_key_first( $newest );
		$set  = $groups[ $pick ] ?? array();
		sort( $set );
		return $set;
	}

	/**
	 * Extract the 12-char hex nonce from an UpdraftPlus backup filename.
	 *
	 * Examples:
	 *   backup_2026-04-10-2052_My-Site_4f3ce36c22a1-db.gz    → '4f3ce36c22a1'
	 *   backup_2026-04-10-2052_My-Site_4f3ce36c22a1-uploads13.zip → '4f3ce36c22a1'
	 *   not-a-backup.zip                                     → ''
	 *
	 * @param string $basename Filename without path.
	 * @return string Lowercase 12-char nonce, or '' if not recognized.
	 */
	public static function extract_backup_nonce( $basename ) {
		// Last underscore-delimited token before the extension contains
		// "<12hex>-<type><N>". Site-name segments may themselves contain
		// underscores and hyphens, so we anchor on the hex-dash pattern.
		if ( preg_match( '/_([a-f0-9]{12})-[A-Za-z0-9]+\.(?:zip|gz|crypt|sql)$/i', $basename, $m ) ) {
			return strtolower( $m[1] );
		}
		return '';
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
