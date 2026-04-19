<?php
/**
 * Tests for SBU_Crypto: AES-256-CBC encryption, decryption, and legacy-IV
 * migration path.
 */

declare( strict_types = 1 );

namespace SBU\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use SBU\Tests\Helpers\PluginLoader;
use SBU\Tests\Helpers\TestCase;
use SBU_Crypto;

#[CoversClass(SBU_Crypto::class)]
final class CryptoTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        PluginLoader::load();
    }

    public function test_encrypt_then_decrypt_round_trips_a_plaintext(): void {
        $plain  = 'correct horse battery staple';
        $cipher = SBU_Crypto::encrypt( $plain );

        $this->assertNotSame( $plain, $cipher, 'Ciphertext must not equal plaintext.' );
        $this->assertNotEmpty( $cipher );

        $decoded = base64_decode( $cipher, true );
        $this->assertGreaterThanOrEqual( 17, strlen( (string) $decoded ), 'Modern ciphertext carries a 16-byte IV plus payload.' );

        $recovered = SBU_Crypto::decrypt( $cipher );
        $this->assertSame( $plain, $recovered );
    }

    public function test_encrypting_the_same_plaintext_twice_yields_different_ciphertexts(): void {
        $plain = 'repeat-me';
        $a = SBU_Crypto::encrypt( $plain );
        $b = SBU_Crypto::encrypt( $plain );

        $this->assertNotSame( $a, $b, 'Random IV must produce distinct ciphertexts for identical plaintext.' );
    }

    public function test_decrypt_returns_empty_string_for_empty_input(): void {
        $this->assertSame( '', SBU_Crypto::decrypt( '' ) );
    }

    public function test_legacy_static_iv_ciphertext_is_decrypted_and_migrated_in_place(): void {
        $plain = 'legacy-secret';

        // Recreate the pre-1.1 ciphertext format: static IV derived from secure_auth salt.
        $key = 'test-salt-auth';
        $iv  = substr( hash( 'sha256', 'test-salt-secure_auth' ), 0, 16 );
        $raw = openssl_encrypt( $plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
        $legacy_cipher = base64_encode( $raw );

        $this->options[ SBU_OPT ] = [ 'pass' => $legacy_cipher ];

        $recovered = SBU_Crypto::decrypt( $legacy_cipher );
        $this->assertSame( $plain, $recovered, 'Legacy ciphertext must decrypt with the old static-IV scheme.' );

        $this->assertArrayHasKey( 'pass', $this->options[ SBU_OPT ] );
        $this->assertNotSame( $legacy_cipher, $this->options[ SBU_OPT ]['pass'], 'Password option must be re-encrypted in place.' );

        $upgraded = base64_decode( $this->options[ SBU_OPT ]['pass'], true );
        $this->assertGreaterThanOrEqual( 17, strlen( (string) $upgraded ), 'Migrated ciphertext must carry a random 16-byte IV.' );

        $this->assertArrayHasKey( SBU_ACTIVITY, $this->options, 'Migration must be recorded in the activity log.' );
        $this->assertStringContainsString( 'MIGRATION', (string) $this->options[ SBU_ACTIVITY ] );
    }

    public function test_tampered_ciphertext_returns_empty_string(): void {
        $plain  = 'valid';
        $cipher = SBU_Crypto::encrypt( $plain );

        $raw = base64_decode( $cipher, true );
        // Flip the last payload byte so MAC/padding check (PKCS#7) rejects it.
        $raw[ strlen( $raw ) - 1 ] = chr( ord( $raw[ strlen( $raw ) - 1 ] ) ^ 0xFF );
        $tampered = base64_encode( $raw );

        $result = @SBU_Crypto::decrypt( $tampered );
        $this->assertSame( '', $result );
    }
}
