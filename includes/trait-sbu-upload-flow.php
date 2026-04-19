<?php
/**
 * Upload-Flow — ausgelagert aus SBU_Plugin (ARCH-001 Schritt 5).
 *
 * Kompletter Upload-Lebenszyklus: Hook auf UpdraftPlus-Fertig, Queue-Aufbau,
 * Tick-Verarbeitung (process_queue_tick), Chunk-Upload, Verification,
 * Retention und History-Cleanup. Als Trait, weil der Flow tief in private
 * Plugin-Helfer greift (safe_queue_update, detect_worker_crash_and_defer,
 * maybe_notify_stall, get_adaptive_limits, tick_budget_exhausted,
 * compute_queue_timeout, is_aborted, log_failed_files, get_updraft_dir,
 * get_cron_key). Trait-Komposition hält alle Zugriffe intakt, ohne
 * Visibility-Promotion oder fragile Plugin-Referenzen.
 *
 * @package SeafileUpdraftBackupUploader
 */

defined( 'ABSPATH' ) || exit;

/*
 * PHPCS file-scope suppressions (identisch zu class-sbu-plugin.php, weil der
 * Flow physisch hierher gewandert ist — siehe dort für die ausführliche
 * Rationale):
 *
 * WordPress.PHP.NoSilencedErrors
 *   @set_time_limit / @fopen / @unlink müssen fehlertolerant bleiben, da
 *   Hoster diese Funktionen abschalten können und der Queue-Tick dennoch
 *   sauber durchlaufen muss.
 *
 * WordPress.WP.AlternativeFunctions.*
 *   Die Chunk-Upload-Pipeline streamt mit fopen()/fread()/fclose() und
 *   nutzt unlink() für deterministische Ressourcenfreigabe. WP_Filesystem
 *   bietet die Stream-Semantik nicht, die der Rate-Controller braucht.
 */
// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged
// phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fread
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose

trait SBU_Upload_Flow {

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
}
