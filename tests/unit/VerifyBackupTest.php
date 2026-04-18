<?php
/**
 * Tests for SBU_Plugin::verify_backup().
 *
 * Verify runs AFTER a successful upload to confirm what Seafile sees
 * matches what we intended to ship. Since 1.3.4 the verify step is
 * size-only — integrity hashes are captured lazily at upload time and
 * verified streamingly on restore (no second full-file readback).
 *
 * The download-time integrity check is exercised by the Docker smoke-test
 * harness and by RestoreIntegrityTest; this file covers the post-upload
 * size matrix.
 */

declare( strict_types = 1 );

namespace SBU\Tests\Unit;

use Brain\Monkey\Functions;
use SBU\Tests\Helpers\PluginLoader;
use SBU\Tests\Helpers\TestCase;
use SBU_Plugin;

/**
 * @covers \SBU_Plugin::verify_backup
 */
final class VerifyBackupTest extends TestCase {

    private SBU_Plugin $plugin;

    protected function setUp(): void {
        parent::setUp();
        PluginLoader::load();
        $this->plugin = new SBU_Plugin();

        Functions\when( 'wp_remote_retrieve_response_code' )->alias( static function ( $r ) {
            return $r['response']['code'] ?? 200;
        } );
        Functions\when( 'wp_remote_retrieve_body' )->alias( static function ( $r ) {
            return $r['body'] ?? '';
        } );
    }

    // =====================================================================
    // Error paths
    // =====================================================================

    public function test_returns_error_when_list_directory_returns_wp_error(): void {
        Functions\when( 'wp_remote_get' )->justReturn( new \WP_Error( 'http', 'timeout' ) );

        $result = $this->verify();

        $this->assertSame( 'error', $result['status'] );
        $this->assertSame( 'Verzeichnis nicht lesbar', $result['msg'] );
        $this->assertArrayHasKey( 'checked', $result );
    }

    public function test_returns_error_when_list_directory_returns_http_500(): void {
        $this->stubRemoteListing( [], 500 );

        $result = $this->verify();

        $this->assertSame( 'error', $result['status'] );
    }

    // =====================================================================
    // Size-mode matrix
    // =====================================================================

    public function test_size_mode_is_complete_when_all_sizes_match(): void {
        $this->stubRemoteListing( [
            [ 'type' => 'file', 'name' => 'backup-db.gz',      'size' => 1_000_000 ],
            [ 'type' => 'file', 'name' => 'backup-plugins.zip', 'size' => 5_000_000 ],
        ] );

        $result = $this->verify( $this->doneQueue( [
            [ 'path' => '/srv/backup-db.gz',      'size' => 1_000_000, 'sha1' => 'aaaa' ],
            [ 'path' => '/srv/backup-plugins.zip', 'size' => 5_000_000, 'sha1' => 'bbbb' ],
        ] ) );

        $this->assertSame( 'complete', $result['status'] );
        $this->assertSame( 2, $result['files'] );
        $this->assertSame( 'size', $result['mode'] );
        $this->assertSame( 2, $result['sha1_kept'], 'both captured hashes are counted for restore-time verification' );
    }

    public function test_size_mode_flags_file_missing_on_seafile(): void {
        $this->stubRemoteListing( [
            [ 'type' => 'file', 'name' => 'backup-db.gz', 'size' => 1_000_000 ],
            // plugins.zip never landed on Seafile.
        ] );

        $result = $this->verify( $this->doneQueue( [
            [ 'path' => '/srv/backup-db.gz',      'size' => 1_000_000 ],
            [ 'path' => '/srv/backup-plugins.zip', 'size' => 5_000_000 ],
        ] ) );

        $this->assertSame( 'incomplete', $result['status'] );
        $this->assertSame( 1, $result['ok'] );
        $this->assertSame( 2, $result['total'] );
        $this->assertCount( 1, $result['issues'] );
        $this->assertStringContainsString( 'backup-plugins.zip', $result['issues'][0] );
        $this->assertStringContainsString( 'fehlt auf Seafile', $result['issues'][0] );
    }

    public function test_size_mode_flags_truncated_remote_file(): void {
        $this->stubRemoteListing( [
            [ 'type' => 'file', 'name' => 'backup-db.gz', 'size' => 900_000 ], // truncated
        ] );

        $result = $this->verify( $this->doneQueue( [
            [ 'path' => '/srv/backup-db.gz', 'size' => 1_000_000 ],
        ] ) );

        $this->assertSame( 'incomplete', $result['status'] );
        $this->assertSame( 0, $result['ok'] );
        $this->assertCount( 1, $result['issues'] );
        $this->assertStringContainsString( 'backup-db.gz', $result['issues'][0] );
        $this->assertStringContainsString( 'lokal 0.95 MB', $result['issues'][0] );
        $this->assertStringContainsString( 'remote 0.86 MB', $result['issues'][0] );
    }

    public function test_size_mode_skips_files_not_marked_done(): void {
        $this->stubRemoteListing( [
            [ 'type' => 'file', 'name' => 'backup-db.gz', 'size' => 1_000_000 ],
        ] );

        $queue = [
            'files' => [
                [ 'path' => '/srv/backup-db.gz',      'size' => 1_000_000, 'status' => 'done' ],
                [ 'path' => '/srv/backup-plugins.zip', 'size' => 5_000_000, 'status' => 'uploading' ],
                [ 'path' => '/srv/backup-themes.zip',  'size' => 2_000_000, 'status' => 'pending' ],
            ],
        ];

        $result = $this->verify( $queue );

        $this->assertSame( 'complete', $result['status'] );
        $this->assertSame( 1, $result['files'], 'only the done file counts' );
    }

    public function test_size_mode_ignores_remote_subdirectories(): void {
        $this->stubRemoteListing( [
            [ 'type' => 'file', 'name' => 'backup-db.gz', 'size' => 1_000_000 ],
            [ 'type' => 'dir',  'name' => 'old-snapshot' ], // must be ignored
        ] );

        $result = $this->verify( $this->doneQueue( [
            [ 'path' => '/srv/backup-db.gz', 'size' => 1_000_000 ],
        ] ) );

        $this->assertSame( 'complete', $result['status'] );
    }

    // =====================================================================
    // Hash capture for restore-time verification
    // =====================================================================

    /**
     * A file uploaded without a captured SHA1 (e.g. before 1.3.4, or when
     * sha1_file failed at upload time) still counts as ok for size verify,
     * but is excluded from the sha1_kept counter — the restore path will
     * log a WARNUNG and skip integrity verification for that file.
     */
    public function test_files_without_sha1_do_not_count_toward_sha1_kept(): void {
        $this->stubRemoteListing( [
            [ 'type' => 'file', 'name' => 'backup-db.gz', 'size' => 1_000_000 ],
        ] );

        $result = $this->verify( $this->doneQueue( [
            [ 'path' => '/srv/backup-db.gz', 'size' => 1_000_000, 'sha1' => '' ],
        ] ) );

        $this->assertSame( 'complete', $result['status'] );
        $this->assertSame( 1, $result['files'] );
        $this->assertSame( 0, $result['sha1_kept'] );
    }

    public function test_mode_is_always_size(): void {
        $this->stubRemoteListing( [
            [ 'type' => 'file', 'name' => 'backup-db.gz', 'size' => 1_000_000 ],
        ] );

        $result = $this->verify( $this->doneQueue( [
            [ 'path' => '/srv/backup-db.gz', 'size' => 1_000_000 ],
        ] ) );

        $this->assertSame( 'size', $result['mode'] );
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * Stub wp_remote_get to return a response that will decode to the
     * given file list with the given HTTP status.
     */
    private function stubRemoteListing( array $files, int $code = 200 ): void {
        Functions\when( 'wp_remote_get' )->justReturn( [
            'response' => [ 'code' => $code ],
            'body'     => json_encode( $files ),
        ] );
    }

    /**
     * Call verify_backup() with a canned URL/token/repo/dir and the given
     * queue (or a single-file default).
     */
    private function verify( ?array $queue = null ): array {
        $queue = $queue ?? $this->doneQueue( [ [ 'path' => '/srv/backup-db.gz', 'size' => 1_000_000 ] ] );
        return $this->callPrivate( $this->plugin, 'verify_backup', [
            'https://seafile.test',
            'token-abc',
            'repo-id',
            '/Backups/2026-04-17',
            $queue,
        ] );
    }

    /**
     * Build a queue whose files are all marked done, with the given
     * per-file overrides.
     *
     * @param array<int, array<string,mixed>> $files
     */
    private function doneQueue( array $files ): array {
        $normalized = array_map( static function ( $fi ) {
            return array_merge( [ 'status' => 'done' ], $fi );
        }, $files );
        return [ 'files' => $normalized, 'chunk_size' => 0 ];
    }
}
