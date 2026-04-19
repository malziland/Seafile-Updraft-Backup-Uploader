<?php
/**
 * Restore-Flow — ausgelagert aus SBU_Plugin (ARCH-001 Schritt 5).
 *
 * Kompletter Restore-Lebenszyklus: Integritätsprüfung (verify_restored_file)
 * und der Tick-Runner (process_restore_tick) mit parallelen Range-Chunks,
 * Stream-First-Pfad und exponentiellem Backoff. Als Trait, weil der Flow tief
 * in private Plugin-Helfer greift (safe_queue_update, maybe_notify_stall,
 * get_adaptive_limits, tick_budget_exhausted, compute_queue_timeout,
 * is_aborted, log_failed_files, get_updraft_dir, get_memory_limit).
 * Trait-Komposition hält alle Zugriffe intakt, ohne Visibility-Promotion.
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
 *   @set_time_limit / @sha1_file / @fopen müssen fehlertolerant bleiben —
 *   Hoster schalten diese Funktionen manchmal ab, der Restore-Pfad muss
 *   mit unverified/unverifizierter Prüfsumme dennoch weiterlaufen.
 *
 * WordPress.WP.AlternativeFunctions.*
 *   Parallele Range-Chunks nutzen fopen()/fwrite()/fclose() zum Streamen,
 *   unlink() für Cleanup. WP_Filesystem bietet die Stream-Semantik nicht.
 */
// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged
// phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fread
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose

trait SBU_Restore_Flow {

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
}
