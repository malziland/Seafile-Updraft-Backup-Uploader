<?php
/**
 * Tests for SBU_Seafile_API: thin HTTP client for the Seafile v2 API.
 *
 * The class is a pure HTTP wrapper — no WordPress options, no queue state —
 * so the tests exercise the observable contract: request shape in, response
 * parsing out. wp_remote_post/wp_remote_get and the wp_remote_retrieve_*
 * helpers are stubbed via Brain\Monkey so the tests run without a live
 * Seafile server.
 *
 * What's worth covering here:
 *   - get_token caches on success, re-authenticates on force, returns
 *     WP_Error with the server-supplied message on failure.
 *   - find_library resolves the repo ID by name and surfaces a useful error
 *     (listing available names) when the library is absent.
 *   - get_upload_link / get_download_link decode the JSON string the API
 *     returns and fall back to WP_Error on garbage.
 *   - create_directory treats non-2xx codes as a hard failure.
 */

declare( strict_types = 1 );

namespace SBU\Tests\Unit;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversClass;
use SBU\Tests\Helpers\PluginLoader;
use SBU\Tests\Helpers\TestCase;
use SBU_Seafile_API;
use WP_Error;

#[CoversClass(SBU_Seafile_API::class)]
final class SeafileApiTest extends TestCase {

	/**
	 * In-memory transient store; lets us assert caching without touching WP.
	 *
	 * @var array<string,mixed>
	 */
	private array $transients = [];

	protected function setUp(): void {
		parent::setUp();
		PluginLoader::load();

		$this->transients = [];
		Functions\when( 'get_transient' )->alias( function ( $key ) {
			return $this->transients[ $key ] ?? false;
		} );
		Functions\when( 'set_transient' )->alias( function ( $key, $value ) {
			$this->transients[ $key ] = $value;
			return true;
		} );
		Functions\when( 'delete_transient' )->alias( function ( $key ) {
			unset( $this->transients[ $key ] );
			return true;
		} );

		Functions\when( 'wp_remote_retrieve_body' )->alias( static function ( $response ) {
			return is_array( $response ) ? ( $response['body'] ?? '' ) : '';
		} );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static function ( $response ) {
			return is_array( $response ) ? (int) ( $response['response']['code'] ?? 0 ) : 0;
		} );
		Functions\when( 'wp_remote_retrieve_header' )->alias( static function ( $response, $header ) {
			return is_array( $response ) ? ( $response['headers'][ $header ] ?? '' ) : '';
		} );
	}

	private static function httpResponse( int $code, string $body ): array {
		return [
			'body'     => $body,
			'response' => [ 'code' => $code ],
			'headers'  => [],
		];
	}

	// ---- get_token ----------------------------------------------------------

	public function test_get_token_returns_cached_when_present(): void {
		$this->transients[ SBU_TOK ] = 'cached-token';
		$called                      = false;
		Functions\when( 'wp_remote_post' )->alias( static function () use ( &$called ) {
			$called = true;
			return [];
		} );

		$token = SBU_Seafile_API::get_token( 'https://seafile.example', 'u', 'p' );

		$this->assertSame( 'cached-token', $token );
		$this->assertFalse( $called, 'Cache hit must not issue an HTTP request' );
	}

	public function test_get_token_force_bypasses_cache_and_stores_new(): void {
		$this->transients[ SBU_TOK ] = 'stale-token';
		Functions\when( 'wp_remote_post' )->justReturn(
			self::httpResponse( 200, json_encode( [ 'token' => 'fresh-token' ] ) )
		);

		$token = SBU_Seafile_API::get_token( 'https://seafile.example', 'u', 'p', true );

		$this->assertSame( 'fresh-token', $token );
		$this->assertSame( 'fresh-token', $this->transients[ SBU_TOK ] ?? null );
	}

	public function test_get_token_returns_wp_error_on_auth_failure(): void {
		Functions\when( 'wp_remote_post' )->justReturn(
			self::httpResponse(
				400,
				json_encode( [ 'non_field_errors' => [ 'Unable to login.' ] ] )
			)
		);

		$result = SBU_Seafile_API::get_token( 'https://seafile.example', 'u', 'bad' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'Unable to login.', $result->get_error_message() );
	}

	public function test_get_token_wraps_wp_error_transport_error(): void {
		Functions\when( 'wp_remote_post' )->justReturn(
			new WP_Error( 'http_request_failed', 'Connection refused' )
		);

		$result = SBU_Seafile_API::get_token( 'https://seafile.example', 'u', 'p' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'Connection refused', $result->get_error_message() );
	}

	// ---- find_library ------------------------------------------------------

	public function test_find_library_returns_repo_id_on_match(): void {
		Functions\when( 'wp_remote_get' )->justReturn(
			self::httpResponse(
				200,
				json_encode( [
					[ 'id' => 'abc-123', 'name' => 'Backups' ],
					[ 'id' => 'def-456', 'name' => 'Docs' ],
				] )
			)
		);

		$rid = SBU_Seafile_API::find_library( 'https://seafile.example', 'tok', 'Docs' );

		$this->assertSame( 'def-456', $rid );
	}

	public function test_find_library_returns_wp_error_with_available_names(): void {
		Functions\when( 'wp_remote_get' )->justReturn(
			self::httpResponse(
				200,
				json_encode( [
					[ 'id' => 'a', 'name' => 'Backups' ],
					[ 'id' => 'b', 'name' => 'Docs' ],
				] )
			)
		);

		$result = SBU_Seafile_API::find_library( 'https://seafile.example', 'tok', 'Missing' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( '404', $result->get_error_code() );
		$this->assertStringContainsString( 'Backups', $result->get_error_message() );
		$this->assertStringContainsString( 'Docs', $result->get_error_message() );
	}

	public function test_find_library_forwards_transport_error(): void {
		$transport = new WP_Error( 'http_request_failed', 'DNS failure' );
		Functions\when( 'wp_remote_get' )->justReturn( $transport );

		$result = SBU_Seafile_API::find_library( 'https://seafile.example', 'tok', 'x' );

		$this->assertSame( $transport, $result );
	}

	// ---- get_upload_link ---------------------------------------------------

	public function test_get_upload_link_decodes_json_string(): void {
		Functions\when( 'wp_remote_get' )->justReturn(
			self::httpResponse( 200, json_encode( 'https://seafile.example/upload-api/xyz' ) )
		);

		$link = SBU_Seafile_API::get_upload_link( 'https://seafile.example', 'tok', 'repo-1', '/backups' );

		$this->assertSame( 'https://seafile.example/upload-api/xyz', $link );
	}

	public function test_get_upload_link_rejects_non_string_body(): void {
		Functions\when( 'wp_remote_get' )->justReturn(
			self::httpResponse( 200, json_encode( [ 'error' => 'boom' ] ) )
		);

		$result = SBU_Seafile_API::get_upload_link( 'https://seafile.example', 'tok', 'repo-1' );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	// ---- get_download_link -------------------------------------------------

	public function test_get_download_link_uses_reuse_flag_and_returns_url(): void {
		$captured_url = '';
		Functions\when( 'wp_remote_get' )->alias( static function ( $url ) use ( &$captured_url ) {
			$captured_url = $url;
			return [
				'body'     => json_encode( 'https://seafile.example/d/signed' ),
				'response' => [ 'code' => 200 ],
				'headers'  => [],
			];
		} );

		$link = SBU_Seafile_API::get_download_link( 'https://seafile.example', 'tok', 'repo-1', '/f.zip' );

		$this->assertSame( 'https://seafile.example/d/signed', $link );
		$this->assertStringContainsString( 'reuse=1', $captured_url, 'Must request the replay-safe delivery path' );
	}

	// ---- create_directory / list_directory ---------------------------------

	public function test_create_directory_success_returns_true(): void {
		Functions\when( 'wp_remote_post' )->justReturn( self::httpResponse( 201, '' ) );

		$result = SBU_Seafile_API::create_directory( 'https://seafile.example', 'tok', 'repo-1', '/deep/new' );

		$this->assertTrue( $result );
	}

	public function test_create_directory_non_2xx_returns_error(): void {
		Functions\when( 'wp_remote_post' )->justReturn( self::httpResponse( 500, '' ) );

		$result = SBU_Seafile_API::create_directory( 'https://seafile.example', 'tok', 'repo-1', '/x' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'mkdir', $result->get_error_code() );
	}

	public function test_list_directory_returns_decoded_entries(): void {
		Functions\when( 'wp_remote_get' )->justReturn(
			self::httpResponse(
				200,
				json_encode( [
					[ 'name' => 'a.zip', 'type' => 'file' ],
					[ 'name' => 'sub',   'type' => 'dir' ],
				] )
			)
		);

		$entries = SBU_Seafile_API::list_directory( 'https://seafile.example', 'tok', 'repo-1', '/backups' );

		$this->assertIsArray( $entries );
		$this->assertCount( 2, $entries );
		$this->assertSame( 'a.zip', $entries[0]['name'] );
	}

	public function test_list_directory_non_200_returns_error(): void {
		Functions\when( 'wp_remote_get' )->justReturn( self::httpResponse( 404, '' ) );

		$result = SBU_Seafile_API::list_directory( 'https://seafile.example', 'tok', 'repo-1', '/missing' );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_list_directory_empty_body_returns_empty_array(): void {
		Functions\when( 'wp_remote_get' )->justReturn( self::httpResponse( 200, 'null' ) );

		$result = SBU_Seafile_API::list_directory( 'https://seafile.example', 'tok', 'repo-1', '/empty' );

		$this->assertSame( [], $result );
	}

	// ---- delete_directory --------------------------------------------------

	public function test_delete_directory_non_2xx_returns_error(): void {
		Functions\when( 'wp_remote_request' )->justReturn( self::httpResponse( 403, '' ) );

		$result = SBU_Seafile_API::delete_directory( 'https://seafile.example', 'tok', 'repo-1', '/x' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'delete', $result->get_error_code() );
	}

	public function test_delete_directory_success_returns_true(): void {
		Functions\when( 'wp_remote_request' )->justReturn( self::httpResponse( 200, '' ) );

		$result = SBU_Seafile_API::delete_directory( 'https://seafile.example', 'tok', 'repo-1', '/x' );

		$this->assertTrue( $result );
	}
}
