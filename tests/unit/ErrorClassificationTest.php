<?php
/**
 * Tests for SBU_Plugin::classify_chunk_error().
 *
 * The classifier is the gate in front of the retry counter and the AIMD
 * rate controller: transient/overload should shrink the transfer window
 * and count as a retry; deadline/auth/signed_url/client must NOT inflate
 * the retry budget (they're not transport-health signals).
 */

declare( strict_types = 1 );

namespace SBU\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversMethod;
use SBU\Tests\Helpers\PluginLoader;
use SBU\Tests\Helpers\TestCase;
use SBU_Plugin;

#[CoversMethod(SBU_Plugin::class, 'classify_chunk_error')]
final class ErrorClassificationTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        PluginLoader::load();
    }

    public function test_ok_result_classifies_as_ok(): void {
        $r = [ 'ok' => true, 'bytes' => 12345, 'code' => 206 ];
        $this->assertSame( 'ok', SBU_Plugin::classify_chunk_error( $r ) );
    }

    public function test_wp_error_with_deadline_code_classifies_as_deadline(): void {
        $r = [
            'ok'    => false,
            'error' => new \WP_Error( 'deadline', 'tick deadline reached' ),
        ];
        $this->assertSame( 'deadline', SBU_Plugin::classify_chunk_error( $r ) );
    }

    public function test_http_401_classifies_as_auth(): void {
        $r = [ 'ok' => false, 'code' => 401, 'error' => new \WP_Error( 'http', '401' ) ];
        $this->assertSame( 'auth', SBU_Plugin::classify_chunk_error( $r ) );
    }

    public function test_http_403_classifies_as_signed_url(): void {
        $r = [ 'ok' => false, 'code' => 403, 'error' => new \WP_Error( 'http', '403' ) ];
        $this->assertSame( 'signed_url', SBU_Plugin::classify_chunk_error( $r ) );
    }

    public function test_http_429_classifies_as_overload(): void {
        $r = [ 'ok' => false, 'code' => 429, 'error' => new \WP_Error( 'http', '429' ) ];
        $this->assertSame( 'overload', SBU_Plugin::classify_chunk_error( $r ) );
    }

    public function test_http_503_classifies_as_overload(): void {
        $r = [ 'ok' => false, 'code' => 503, 'error' => new \WP_Error( 'http', '503' ) ];
        $this->assertSame( 'overload', SBU_Plugin::classify_chunk_error( $r ) );
    }

    public function test_http_400_404_416_classify_as_client(): void {
        foreach ( [ 400, 404, 416 ] as $http ) {
            $r = [ 'ok' => false, 'code' => $http, 'error' => new \WP_Error( 'http', (string) $http ) ];
            $this->assertSame( 'client', SBU_Plugin::classify_chunk_error( $r ), "HTTP $http → client" );
        }
    }

    public function test_curl_error_without_http_code_classifies_as_transient(): void {
        $r = [
            'ok'         => false,
            'code'       => 0,
            'curl_errno' => 28,
            'error'      => new \WP_Error( 'curl', 'Operation too slow' ),
        ];
        $this->assertSame( 'transient', SBU_Plugin::classify_chunk_error( $r ) );
    }

    public function test_http_500_classifies_as_transient(): void {
        $r = [ 'ok' => false, 'code' => 500, 'error' => new \WP_Error( 'http', '500' ) ];
        $this->assertSame( 'transient', SBU_Plugin::classify_chunk_error( $r ) );
    }

    public function test_bare_failure_without_error_object_classifies_as_transient(): void {
        $r = [ 'ok' => false, 'code' => 0 ];
        $this->assertSame( 'transient', SBU_Plugin::classify_chunk_error( $r ) );
    }

    public function test_deadline_wins_even_with_http_code(): void {
        // A deadline-coded WP_Error must classify as 'deadline' even if a
        // stale HTTP code is still on the result struct. Deadline is the
        // authoritative signal that the pump gave up, not the transport.
        $r = [
            'ok'    => false,
            'code'  => 503,
            'error' => new \WP_Error( 'deadline', 'tick budget exhausted' ),
        ];
        $this->assertSame( 'deadline', SBU_Plugin::classify_chunk_error( $r ) );
    }
}
