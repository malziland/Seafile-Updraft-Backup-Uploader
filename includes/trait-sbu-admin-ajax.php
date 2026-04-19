<?php
/**
 * Admin-Ajax handlers — ausgelagert aus SBU_Plugin (ARCH-001 Schritt 4).
 *
 * 24 Ajax-Endpoints für die Admin-Oberfläche: Verbindungstest, Upload/Restore-
 * Queue-Kontrolle, Backup-Liste, Download-Handler, Log-Export, Settings-
 * Autosave und die beiden extern erreichbaren Cron-Endpoints. Komplett als
 * Trait, weil die Methoden tief auf private SBU_Plugin-Helfer angewiesen sind
 * (verify_ajax_request, get_picker_credentials, create_upload_queue,
 * format_progress, get_updraft_dir, sanitize_path_segment, get_cron_key,
 * record_cron_key_failure, extract_cron_key_from_request, current_updraft_nonce).
 * Ein Trait komponiert zur Compile-Zeit in die Klasse — alle Private-Zugriffe
 * bleiben intakt, ohne massive Visibility-Promotion oder fragile Plugin-Ref.
 *
 * @package SeafileUpdraftBackupUploader
 */

defined( 'ABSPATH' ) || exit;

/*
 * PHPCS file-scope suppressions (identisch zu class-sbu-plugin.php, weil die
 * Handler physisch hierher gewandert sind — siehe dort für die Rationale):
 *
 * WordPress.Security.NonceVerification
 *   Jeder Ajax-Handler betritt seinen Body über $this->verify_ajax_request(),
 *   das wp_verify_nonce() auf $_POST['_nonce'] prüft. Der WPCS-Sniff erkennt
 *   nur globale Funktionsaufrufe, nicht $this->method(), und feuert deshalb
 *   false-positives auf jedem $_POST/$_REQUEST-Zugriff.
 *
 * WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
 *   Sanitization läuft über $this->sanitize(), $this->sanitize_path_segment(),
 *   SBU_Crypto::... — benutzerdefinierte Helfer, die der Sniff nicht als
 *   Sanitizer registriert. MissingUnslash bleibt aktiv, damit echte Unslash-
 *   Lücken weiter aufschlagen.
 *
 * WordPress.PHP.NoSilencedErrors
 *   @set_time_limit / @filesize werden in den Ajax-Handlern genutzt, weil
 *   Hoster sie abschalten können und das non-fatal sein muss.
 */
// phpcs:disable WordPress.Security.NonceVerification
// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged

trait SBU_Admin_Ajax {

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
}
