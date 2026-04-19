<?php
/**
 * Password encryption for the Seafile account credential.
 *
 * Uses AES-256-CBC with a fresh 16-byte random IV per encryption, keyed off
 * WordPress's `wp_salt('auth')`. Legacy ciphertexts from pre-1.1 installs
 * (which used a static IV derived from `secure_auth`) are transparently
 * migrated to the random-IV scheme the first time they are decrypted.
 *
 * All methods are static; the class holds no instance state.
 *
 * @package seafile-updraft-backup-uploader
 */

defined( 'ABSPATH' ) || exit;

final class SBU_Crypto {

	/**
	 * Encrypt a plaintext password for storage in wp_options.
	 *
	 * @param string $plain Plain text password.
	 * @return string Base64-encoded `IV || ciphertext`.
	 */
	public static function encrypt( $plain ) {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			wp_die( esc_html__( 'Seafile Updraft Backup Uploader benötigt die PHP OpenSSL-Erweiterung zur sicheren Passwortspeicherung.', 'seafile-updraft-backup-uploader' ) );
		}
		$key       = wp_salt( 'auth' );
		$iv        = openssl_random_pseudo_bytes( 16 );
		$encrypted = openssl_encrypt( $plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding binary IV+ciphertext for option storage, not code obfuscation.
		return base64_encode( $iv . $encrypted );
	}

	/**
	 * Decrypt a stored ciphertext. Returns the empty string if the input is
	 * empty, malformed, or fails to decrypt. Legacy static-IV ciphertexts
	 * are recognized, decrypted, and migrated in place via
	 * {@see self::migrate_legacy_password()}.
	 *
	 * @param string $ciphertext Base64-encoded ciphertext.
	 * @return string Plain text password, or `''` on any failure.
	 */
	public static function decrypt( $ciphertext ) {
		if ( empty( $ciphertext ) ) {
			return '';
		}
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}
		$key = wp_salt( 'auth' );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding binary IV+ciphertext from option storage, not code obfuscation.
		$raw = base64_decode( $ciphertext );
		if ( strlen( $raw ) < 17 ) {
			// Legacy format (static IV from <1.1). Decrypt with old scheme and
			// re-encrypt inline so the stored value migrates to the random-IV
			// format on first read.
			$legacy_iv = substr( hash( 'sha256', wp_salt( 'secure_auth' ) ), 0, 16 );
			$result    = openssl_decrypt( $raw, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $legacy_iv );
			if ( $result !== false && $result !== '' ) {
				self::migrate_legacy_password( $result );
				return $result;
			}
			return '';
		}
		$iv     = substr( $raw, 0, 16 );
		$ct     = substr( $raw, 16 );
		$result = openssl_decrypt( $ct, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
		return $result ?: '';
	}

	/**
	 * Re-encrypt a legacy (static-IV) password using the current random-IV
	 * scheme, persist it back to the plugin options, and log the migration.
	 * Runs at most once per stored password.
	 *
	 * @param string $plain Plain text password recovered from legacy ciphertext.
	 */
	private static function migrate_legacy_password( $plain ) {
		$opts         = get_option( SBU_OPT, array() );
		$opts['pass'] = self::encrypt( $plain );
		update_option( SBU_OPT, $opts );

		$entry = '[' . current_time( 'd.m.Y H:i' ) . '] MIGRATION: '
			. __( 'Seafile-Passwort auf AES-256-CBC mit zufälligem IV migriert.', 'seafile-updraft-backup-uploader' );

		$log   = get_option( SBU_ACTIVITY, '' );
		$log   = $entry . "\n" . $log;
		$lines = explode( "\n", $log );
		if ( count( $lines ) > SBU_ACTIVITY_MAX ) {
			$lines = array_slice( $lines, 0, SBU_ACTIVITY_MAX );
		}
		update_option( SBU_ACTIVITY, implode( "\n", $lines ), false );
	}
}
