<?php
/*
 * PHPCS file-scope suppressions:
 *
 * WordPress.WP.AlternativeFunctions.curl_*
 *   This class implements curl_multi-based parallel chunk downloads. The
 *   WordPress HTTP API (wp_remote_*) does not expose curl_multi and the
 *   plugin needs the concurrency to hit Seafile restore budgets.
 *
 * WordPress.WP.AlternativeFunctions.file_system_operations_*,
 * WordPress.WP.AlternativeFunctions.unlink_unlink
 *   Stream-based chunked uploads require fopen()/fwrite()/fclose() and
 *   eager tempnam() cleanup. WP_Filesystem is not suitable for the
 *   request-scoped temp handling this class needs.
 *
 * WordPress.PHP.NoSilencedErrors, Generic.PHP.DeprecatedFunctions
 *   curl_close() is a no-op since PHP 8.0 but releases the handle eagerly
 *   on PHP 7.4 (our supported floor). We keep the call with error
 *   suppression for the 8.x deprecation notice.
 *
 * WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_*
 *   Seafile returns chunked upload acks as base64 blocks; decoding is
 *   required by the protocol.
 */
// phpcs:disable WordPress.WP.AlternativeFunctions
// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged
// phpcs:disable Generic.PHP.DeprecatedFunctions.Deprecated
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.urlencode_urlencode

/**
 * Thin HTTP client for the Seafile v2 API.
 *
 * Each public method issues a single (or tightly related) set of `wp_remote_*`
 * requests against a Seafile server and returns either the decoded response
 * or a {@see WP_Error}. All methods are stateless and `public static` — the
 * class holds no instance state and never touches WordPress settings, so it
 * can be unit-tested in isolation.
 *
 * The caller is responsible for:
 *   - obtaining credentials and passing them in (see {@see self::get_token()}),
 *   - deciding how long to cache the auth token, and
 *   - retrying on HTTP 401 after calling `get_token( ..., true )` for a fresh
 *     token.
 *
 * @package seafile-updraft-backup-uploader
 */

defined( 'ABSPATH' ) || exit;

final class SBU_Seafile_API {

	/**
	 * Obtain an auth token. Returns the cached transient unless `$force` is
	 * true, in which case the transient is discarded and a fresh token
	 * fetched.
	 *
	 * @param string $url   Seafile base URL (no trailing slash).
	 * @param string $user  Account username / e-mail.
	 * @param string $pass  Plain password.
	 * @param bool   $force Bypass the transient cache and re-authenticate.
	 * @return string|\WP_Error Auth token or error.
	 */
	public static function get_token( $url, $user, $pass, $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( SBU_TOK );
			if ( $cached ) {
				return $cached;
			}
		}
		delete_transient( SBU_TOK );

		$response = wp_remote_post(
			$url . '/api2/auth-token/',
			array(
				'timeout'   => SBU_TIMEOUT_API,
				'sslverify' => true,
				'body'      => array(
					'username' => $user,
					'password' => $pass,
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'auth', $response->get_error_message() );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( wp_remote_retrieve_response_code( $response ) !== 200 || empty( $body['token'] ) ) {
			return new \WP_Error( 'auth', $body['non_field_errors'][0] ?? $body['detail'] ?? 'HTTP ' . wp_remote_retrieve_response_code( $response ) );
		}
		set_transient( SBU_TOK, $body['token'], HOUR_IN_SECONDS );
		return $body['token'];
	}

	/**
	 * Resolve a library name to its Seafile repo ID.
	 *
	 * @param string $url   Seafile base URL.
	 * @param string $token Auth token.
	 * @param string $name  Library display name.
	 * @return string|\WP_Error Repo ID or error (404 when not found, with
	 *                          the list of available library names).
	 */
	public static function find_library( $url, $token, $name ) {
		$response = wp_remote_get(
			$url . '/api2/repos/',
			array(
				'timeout' => SBU_TIMEOUT_API,
				'headers' => array( 'Authorization' => 'Token ' . $token ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$repos = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $repos ) ) {
			return new \WP_Error( 'api', 'Invalid response' );
		}
		foreach ( $repos as $repo ) {
			if ( ( $repo['name'] ?? '' ) === $name ) {
				return $repo['id'];
			}
		}
		return new \WP_Error(
			'404',
			"'{$name}' " . __( 'nicht gefunden. Verfügbar: ', 'seafile-updraft-backup-uploader' )
				. implode( ', ', array_column( $repos, 'name' ) )
		);
	}

	/**
	 * Request a short-lived upload URL for a target directory.
	 *
	 * @param string $url   Seafile base URL.
	 * @param string $token Auth token.
	 * @param string $rid   Repo ID.
	 * @param string $dir   Target directory path.
	 * @return string|\WP_Error Upload URL or error.
	 */
	public static function get_upload_link( $url, $token, $rid, $dir = '/' ) {
		$response = wp_remote_get(
			$url . '/api2/repos/' . $rid . '/upload-link/?p=' . urlencode( $dir ),
			array(
				'timeout' => SBU_TIMEOUT_API,
				'headers' => array( 'Authorization' => 'Token ' . $token ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$link = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_string( $link ) ? $link : new \WP_Error( 'api', 'No upload link' );
	}

	/**
	 * List the contents of a directory in a Seafile library.
	 *
	 * @param string $url   Seafile base URL.
	 * @param string $token Auth token.
	 * @param string $rid   Repo ID.
	 * @param string $dir   Directory path.
	 * @return array|\WP_Error Directory entries or error. Empty array if the
	 *                         API returned an empty / null body.
	 */
	public static function list_directory( $url, $token, $rid, $dir ) {
		$response = wp_remote_get(
			$url . '/api2/repos/' . $rid . '/dir/?p=' . urlencode( $dir ),
			array(
				'timeout' => SBU_TIMEOUT_API,
				'headers' => array( 'Authorization' => 'Token ' . $token ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return new \WP_Error( 'api', 'HTTP ' . wp_remote_retrieve_response_code( $response ) );
		}
		return json_decode( wp_remote_retrieve_body( $response ), true ) ?: array();
	}

	/**
	 * Create a directory in a Seafile library.
	 *
	 * @param string $url   Seafile base URL.
	 * @param string $token Auth token.
	 * @param string $rid   Repo ID.
	 * @param string $dir   Directory path to create.
	 * @return true|\WP_Error
	 */
	public static function create_directory( $url, $token, $rid, $dir ) {
		$response = wp_remote_post(
			$url . '/api2/repos/' . $rid . '/dir/?p=' . urlencode( $dir ),
			array(
				'timeout' => SBU_TIMEOUT_API,
				'headers' => array(
					'Authorization' => 'Token ' . $token,
					'Accept'        => 'application/json',
				),
				'body'    => array( 'operation' => 'mkdir' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error( 'mkdir', "Ordner konnte nicht erstellt werden: HTTP {$code}" );
		}
		return true;
	}

	/**
	 * Ensure a directory exists by listing its parent and creating it if the
	 * entry is missing. The create result is deliberately swallowed: if the
	 * directory already exists due to a race, the follow-up operation (e.g.
	 * upload) will reveal the issue.
	 *
	 * @param string $url   Seafile base URL.
	 * @param string $token Auth token.
	 * @param string $rid   Repo ID.
	 * @param string $dir   Absolute directory path.
	 */
	public static function ensure_directory_exists( $url, $token, $rid, $dir ) {
		$parent = dirname( $dir );
		$name   = basename( $dir );
		$exists = false;
		$items  = self::list_directory( $url, $token, $rid, $parent );
		if ( ! is_wp_error( $items ) ) {
			foreach ( $items as $item ) {
				if ( ( $item['name'] ?? '' ) === $name && ( $item['type'] ?? '' ) === 'dir' ) {
					$exists = true;
					break;
				}
			}
		}
		if ( ! $exists ) {
			self::create_directory( $url, $token, $rid, $dir );
		}
	}

	/**
	 * Request a short-lived download URL for a file.
	 *
	 * reuse=1 tells Seafile to hand out a URL that can be replayed rather
	 * than the default one-shot signed URL. Our parallel pump still
	 * fetches N distinct URLs per batch (one per Range) so we don't rely
	 * on replay, but the reuse flag flips the server onto a different
	 * internal delivery path — in the 1.5.1 dogfood restore the default
	 * path served HTTP 200/206 with empty bodies past the 88 MB mark on
	 * large files, which is unrecoverable from the client side.
	 *
	 * @param string $url   Seafile base URL.
	 * @param string $token Auth token.
	 * @param string $rid   Repo ID.
	 * @param string $path  Remote file path.
	 * @return string|\WP_Error Download URL or error.
	 */
	public static function get_download_link( $url, $token, $rid, $path ) {
		$response = wp_remote_get(
			$url . '/api2/repos/' . $rid . '/file/?p=' . urlencode( $path ) . '&reuse=1',
			array(
				'timeout' => SBU_TIMEOUT_API,
				'headers' => array( 'Authorization' => 'Token ' . $token ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$link = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_string( $link ) ) {
			return new \WP_Error( 'api', 'No download link for ' . basename( $path ) );
		}
		return $link;
	}

	/**
	 * Download the whole file in a single streamed GET — no Range header.
	 *
	 * This hits the same server code path the Seafile web client and
	 * command-line clients use, rather than the Range-serving path that
	 * the 1.5.1 dogfood restore showed serves HTTP 200/206 with empty
	 * bodies past ~88 MB on large files. The empty-body path is
	 * unrecoverable from the client side; taking the non-Range path
	 * sidesteps it entirely.
	 *
	 * The caller controls `$max_seconds` so this can be aborted inside a
	 * tick budget. Cloudflare Free Tunnel caps HTTP responses at ~100 s
	 * regardless of PHP settings, so the default is 95 s — we'd rather
	 * fail ourselves cleanly than have CF reap the socket mid-stream and
	 * leave a half-written file.
	 *
	 * @param string $url         Seafile base URL.
	 * @param string $token       Auth token.
	 * @param string $rid         Repo ID.
	 * @param string $path        Remote file path.
	 * @param string $dest        Local destination path (overwritten).
	 * @param int    $max_seconds Hard timeout for the whole transfer.
	 *                            Pass <=0 for SBU_TIMEOUT_DOWNLOAD.
	 * @return true|\WP_Error
	 */
	public static function download_whole_file_stream( $url, $token, $rid, $path, $dest, $max_seconds = 95 ) {
		if ( $max_seconds <= 0 ) {
			$max_seconds = SBU_TIMEOUT_DOWNLOAD;
		}
		$link = self::get_download_link( $url, $token, $rid, $path );
		if ( is_wp_error( $link ) ) {
			return $link;
		}

		@unlink( $dest );
		$dl = wp_remote_get(
			$link,
			array(
				'timeout'   => $max_seconds,
				'stream'    => true,
				'filename'  => $dest,
				'sslverify' => true,
				'headers'   => array(
					'Authorization' => 'Token ' . $token,
					'Connection'    => 'close',
				),
			)
		);

		// Refresh on 403 (expired signed URL) and retry once.
		if ( ! is_wp_error( $dl ) ) {
			$code = (int) wp_remote_retrieve_response_code( $dl );
			if ( $code === 403 ) {
				@unlink( $dest );
				$link = self::get_download_link( $url, $token, $rid, $path );
				if ( is_wp_error( $link ) ) {
					return $link;
				}
				$dl = wp_remote_get(
					$link,
					array(
						'timeout'   => $max_seconds,
						'stream'    => true,
						'filename'  => $dest,
						'sslverify' => true,
						'headers'   => array(
							'Authorization' => 'Token ' . $token,
							'Connection'    => 'close',
						),
					)
				);
			}
		}

		if ( is_wp_error( $dl ) ) {
			@unlink( $dest );
			return $dl;
		}
		$code = (int) wp_remote_retrieve_response_code( $dl );
		if ( $code !== 200 ) {
			@unlink( $dest );
			return new \WP_Error( 'http', "HTTP {$code}" );
		}

		$actual = file_exists( $dest ) ? (int) filesize( $dest ) : 0;
		if ( $actual <= 0 ) {
			@unlink( $dest );
			return new \WP_Error( 'empty', '0 bytes received (stream)' );
		}

		// Cross-check against Content-Length. wp_remote_get with stream=true
		// does not automatically validate this, and a truncated CF-Tunnel
		// response often returns HTTP 200 with the headers from the origin
		// but a partial body. Missing Content-Length is fine (chunked
		// transfer encoding); a mismatch is not.
		$declared = (int) wp_remote_retrieve_header( $dl, 'content-length' );
		if ( $declared > 0 && $actual < $declared ) {
			@unlink( $dest );
			return new \WP_Error( 'truncated', "Stream abgerissen: {$actual}/{$declared} Bytes" );
		}

		return true;
	}

	/**
	 * Fetch N HTTP Range requests in parallel via curl_multi. Each range
	 * streams to its own temp file so RAM usage stays bounded regardless of
	 * parallelism (peak ≈ N × {chunk_size}).
	 *
	 * Each range MUST carry its own signed 'url'. Seafile (and many
	 * reverse proxies in front of it) treat signed download URLs as
	 * single-use or replay-protected: firing the same URL four times in
	 * parallel returned HTTP 403 on every chunk in the 1.4.0 dogfood
	 * restore. The caller (process_restore_tick) pre-fetches N distinct
	 * URLs via get_download_link() and stuffs one per range.
	 *
	 * The caller is also responsible for:
	 *   - appending the temp files to the destination in offset order,
	 *   - re-fetching fresh URLs + auth token on batch failure and retrying,
	 *   - deleting leftover temp files on its own error paths.
	 *
	 * Why raw curl instead of wp_remote_get: WordPress has no multiplexing
	 * API. wp_remote_get issues one request per call, and N serial calls is
	 * exactly what we're trying to get away from. curl_multi lets all N
	 * Range requests share wall-clock time instead of stacking it.
	 *
	 * The pump is deadline-aware: when `$deadline_ts` is in the past, any
	 * handles that haven't finished yet are cancelled (curl_multi_remove_handle
	 * + curl_close) and their result carries `error` with code `'deadline'`.
	 * This prevents the caller's tick from overrunning its PHP time budget —
	 * up to 1.4.1 the pump blocked synchronously, and a single 180 s hanging
	 * chunk was enough to exceed a 25 s tick and get the worker SIGKILL'd by
	 * PHP's max_execution_time. The caller must still check partial results
	 * and decide what to commit.
	 *
	 * @param string $token         Auth token (sent in Authorization header).
	 * @param array  $ranges        List of ['url' => string, 'start' => int,
	 *                              'end' => int, 'tmp' => string] entries.
	 *                              'tmp' is where this method writes the
	 *                              chunk body; 'url' is the per-chunk
	 *                              signed download URL.
	 * @param int    $chunk_timeout Per-chunk timeout in seconds. <=0 uses
	 *                              SBU_TIMEOUT_DOWNLOAD.
	 * @param float  $deadline_ts   Absolute `microtime(true)` after which the
	 *                              pump cancels unfinished handles. Pass 0 or
	 *                              a past value to disable deadline enforcement
	 *                              (legacy behaviour — used by tests).
	 * @return array<int,array{ok:bool,error?:\WP_Error,tmp:?string,start:int,end:int,code?:int,bytes?:int}>
	 *         Keyed by the input index. On failure, 'tmp' is null and 'error'
	 *         is a WP_Error; on success, 'tmp' points at the on-disk chunk.
	 */
	public static function download_chunks_parallel( $token, $ranges, $chunk_timeout = 0, $deadline_ts = 0.0 ) {
		if ( empty( $ranges ) ) {
			return array();
		}
		if ( $chunk_timeout <= 0 ) {
			$chunk_timeout = SBU_TIMEOUT_DOWNLOAD;
		}

		$mh      = curl_multi_init();
		$handles = array();

		$batch_start = microtime( true );
		foreach ( $ranges as $i => $range ) {
			$tmp = $range['tmp'];
			$fp  = @fopen( $tmp, 'wb' );
			if ( ! $fp ) {
				$handles[ $i ] = array(
					'ch'        => null,
					'fp'        => null,
					'tmp'       => $tmp,
					'start'     => (int) $range['start'],
					'end'       => (int) $range['end'],
					'open_fail' => true,
				);
				continue;
			}
			$ch = curl_init( $range['url'] );
			// cURL options we set explicitly:
			// LOW_SPEED_LIMIT + LOW_SPEED_TIME: kill sockets that drop
			// below 10 KB/s for 15 s straight. This catches the exact
			// Cloudflare-Tunnel "0 bytes received after 40 s" pattern
			// in 15 s instead of waiting out the full chunk timeout.
			// Healthy parallel chunks aren't affected because the
			// check is per-handle.
			// TCP_KEEPALIVE/KEEPIDLE/KEEPINTVL: tell the kernel to send
			// TCP keepalive probes on idle connections so reverse
			// proxies (CF Tunnel, nginx) see traffic and don't reap
			// the socket as "idle" during slow reads.
			$opts = array(
				CURLOPT_FILE            => $fp,
				CURLOPT_HTTPHEADER      => array(
					'Authorization: Token ' . $token,
					'Connection: close',
					'Range: bytes=' . (int) $range['start'] . '-' . (int) $range['end'],
				),
				CURLOPT_TIMEOUT         => $chunk_timeout,
				CURLOPT_CONNECTTIMEOUT  => 15,
				CURLOPT_FOLLOWLOCATION  => true,
				CURLOPT_MAXREDIRS       => 3,
				CURLOPT_SSL_VERIFYPEER  => true,
				CURLOPT_SSL_VERIFYHOST  => 2,
				CURLOPT_LOW_SPEED_LIMIT => 10240,
				CURLOPT_LOW_SPEED_TIME  => 15,
				CURLOPT_TCP_KEEPALIVE   => 1,
				CURLOPT_TCP_KEEPIDLE    => 30,
				CURLOPT_TCP_KEEPINTVL   => 10,
			);
			curl_setopt_array( $ch, $opts );
			curl_multi_add_handle( $mh, $ch );
			$handles[ $i ] = array(
				'ch'           => $ch,
				'fp'           => $fp,
				'tmp'          => $tmp,
				'start'        => (int) $range['start'],
				'end'          => (int) $range['end'],
				'deadline_hit' => false,
				't0'           => $batch_start,
			);
		}

		$active         = null;
		$deadline_fired = false;
		do {
			$status = curl_multi_exec( $mh, $active );
			if ( $active ) {
				// Bounded select so we re-enter the loop regularly to check
				// the deadline even if no socket events have fired.
				curl_multi_select( $mh, 0.5 );
			}
			if ( $deadline_ts > 0 && microtime( true ) >= $deadline_ts && $active ) {
				// Cancel still-running transfers. The remove_handle call
				// stops cURL from driving the connection; the pump exits
				// the loop below. Per-handle state is collected after.
				foreach ( $handles as $i => $h ) {
					if ( empty( $h['open_fail'] ) && $h['ch'] ) {
						$info = curl_getinfo( $h['ch'] );
						if ( empty( $info['http_code'] ) ) {
							$handles[ $i ]['deadline_hit'] = true;
						}
					}
				}
				$deadline_fired = true;
				break;
			}
		} while ( $active && $status === CURLM_OK );

		$results = array();
		foreach ( $handles as $i => $h ) {
			if ( ! empty( $h['open_fail'] ) ) {
				$results[ $i ] = array(
					'ok'         => false,
					'error'      => new \WP_Error( 'io', 'Cannot open temp file ' . $h['tmp'] ),
					'tmp'        => null,
					'start'      => $h['start'],
					'end'        => $h['end'],
					'duration_s' => 0.0,
					'curl_errno' => 0,
				);
				continue;
			}
			if ( is_resource( $h['fp'] ) ) {
				fclose( $h['fp'] );
			}
			$err        = curl_error( $h['ch'] );
			$errno      = (int) curl_errno( $h['ch'] );
			$code       = (int) curl_getinfo( $h['ch'], CURLINFO_HTTP_CODE );
			$duration_s = max( 0.0, microtime( true ) - $h['t0'] );
			$size       = file_exists( $h['tmp'] ) ? (int) filesize( $h['tmp'] ) : 0;
			curl_multi_remove_handle( $mh, $h['ch'] );
			curl_close( $h['ch'] );

			$base = array(
				'start'      => $h['start'],
				'end'        => $h['end'],
				'duration_s' => round( $duration_s, 3 ),
				'curl_errno' => $errno,
				'code'       => $code,
			);

			if ( $h['deadline_hit'] ) {
				@unlink( $h['tmp'] );
				$results[ $i ] = array_merge(
					$base,
					array(
						'ok'    => false,
						'error' => new \WP_Error( 'deadline', 'Tick-Deadline erreicht' ),
						'tmp'   => null,
					)
				);
			} elseif ( $err ) {
				@unlink( $h['tmp'] );
				// CURLE_OPERATION_TIMEDOUT (28) covers both the hard TIMEOUT
				// and the LOW_SPEED_TIME stall detection we just enabled.
				// Callers use the errno to distinguish transient from
				// permanent errors; the message is only for humans.
				$results[ $i ] = array_merge(
					$base,
					array(
						'ok'    => false,
						'error' => new \WP_Error( 'http', $err ),
						'tmp'   => null,
					)
				);
			} elseif ( $code !== 206 && $code !== 200 ) {
				@unlink( $h['tmp'] );
				$results[ $i ] = array_merge(
					$base,
					array(
						'ok'    => false,
						'error' => new \WP_Error( 'http', "HTTP {$code}" ),
						'tmp'   => null,
					)
				);
			} elseif ( $size === 0 ) {
				@unlink( $h['tmp'] );
				$results[ $i ] = array_merge(
					$base,
					array(
						'ok'    => false,
						'error' => new \WP_Error( 'empty', '0 bytes received' ),
						'tmp'   => null,
					)
				);
			} else {
				$results[ $i ] = array_merge(
					$base,
					array(
						'ok'    => true,
						'tmp'   => $h['tmp'],
						'bytes' => $size,
					)
				);
			}
		}
		curl_multi_close( $mh );
		unset( $deadline_fired );

		return $results;
	}

	/**
	 * Delete a directory (recursively) from a Seafile library.
	 *
	 * @param string $url   Seafile base URL.
	 * @param string $token Auth token.
	 * @param string $rid   Repo ID.
	 * @param string $path  Directory path to delete.
	 * @return true|\WP_Error
	 */
	public static function delete_directory( $url, $token, $rid, $path ) {
		$response = wp_remote_request(
			$url . '/api2/repos/' . $rid . '/dir/?p=' . urlencode( $path ),
			array(
				'method'  => 'DELETE',
				'timeout' => SBU_TIMEOUT_API,
				'headers' => array( 'Authorization' => 'Token ' . $token ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error( 'delete', "Löschen fehlgeschlagen: HTTP {$code}" );
		}
		return true;
	}
}
