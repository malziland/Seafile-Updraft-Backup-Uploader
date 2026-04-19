<?php
/**
 * Tests for the pause/resume AJAX handlers added in 1.3.0.
 *
 * The invariant under test: exact-offset continuation. A Pause while
 * chunk N is being uploaded at offset X must leave the queue such that
 * Resume picks up at that same offset X (not 0, not the next chunk
 * boundary). Anything else re-uploads data or silently skips bytes.
 */

declare( strict_types = 1 );

namespace SBU\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversMethod;
use SBU\Tests\Helpers\JsonResponse;
use SBU\Tests\Helpers\PluginLoader;
use SBU\Tests\Helpers\TestCase;
use SBU_Plugin;

#[CoversMethod(SBU_Plugin::class, 'ajax_pause_upload')]
#[CoversMethod(SBU_Plugin::class, 'ajax_resume_upload')]
final class PauseResumeTest extends TestCase {

    private SBU_Plugin $plugin;

    protected function setUp(): void {
        parent::setUp();
        PluginLoader::load();
        $this->plugin = new SBU_Plugin();
    }

    // =====================================================================
    // ajax_pause_upload
    // =====================================================================

    public function test_pause_rejects_when_no_queue(): void {
        $resp = $this->invokeExpectingJson( 'ajax_pause_upload' );
        $this->assertFalse( $resp->success );
    }

    public function test_pause_rejects_when_queue_not_running(): void {
        $this->options[ SBU_QUEUE ] = [ 'status' => 'done' ];
        $resp = $this->invokeExpectingJson( 'ajax_pause_upload' );
        $this->assertFalse( $resp->success );
        $this->assertSame( 'done', $this->options[ SBU_QUEUE ]['status'], 'existing terminal status must not be flipped' );
    }

    public function test_pause_flips_status_and_installs_long_gate(): void {
        $this->options[ SBU_QUEUE ] = $this->runningQueue();
        $before = time();

        $resp = $this->invokeExpectingJson( 'ajax_pause_upload' );
        $this->assertTrue( $resp->success );

        $q = $this->options[ SBU_QUEUE ];
        $this->assertSame( 'paused', $q['status'] );
        $this->assertGreaterThanOrEqual( $before, $q['paused_ts'] );
        // Long-term gate to keep any rogue loopback/cron from waking the queue.
        $this->assertGreaterThan( time() + 30 * DAY_IN_SECONDS, $q['next_allowed_tick_ts'] );
    }

    public function test_pause_preserves_offset_and_counters(): void {
        $this->options[ SBU_QUEUE ] = $this->runningQueue( [
            'files' => [
                [ 'path' => '/srv/backup-db.gz', 'size' => 10_000_000, 'offset' => 4_500_000, 'status' => 'uploading' ],
            ],
            'ok'    => 2,
            'err'   => 1,
            'total_bytes' => 4_500_000,
        ] );

        $this->invokeExpectingJson( 'ajax_pause_upload' );

        $q = $this->options[ SBU_QUEUE ];
        $this->assertSame( 4_500_000, $q['files'][0]['offset'], 'pause must preserve the exact byte offset' );
        $this->assertSame( 2, $q['ok'] );
        $this->assertSame( 1, $q['err'] );
        $this->assertSame( 4_500_000, $q['total_bytes'] );
    }

    public function test_pause_logs_progress_with_file_basename(): void {
        $this->options[ SBU_QUEUE ] = $this->runningQueue( [
            'files' => [
                [ 'path' => '/srv/wp-content/updraft/backup-db.gz', 'size' => 10_000_000, 'offset' => 5_000_000, 'status' => 'uploading' ],
            ],
        ] );

        $this->invokeExpectingJson( 'ajax_pause_upload' );

        $log = $this->options[ SBU_ACTIVITY ] ?? '';
        $this->assertStringContainsString( 'pausiert', $log );
        $this->assertStringContainsString( 'backup-db.gz', $log );
        $this->assertStringContainsString( '50%', $log, 'progress percentage must appear in log' );
    }

    // =====================================================================
    // ajax_resume_upload
    // =====================================================================

    public function test_resume_rejects_when_queue_is_not_paused(): void {
        $this->options[ SBU_QUEUE ] = $this->runningQueue();
        $resp = $this->invokeExpectingJson( 'ajax_resume_upload' );
        $this->assertFalse( $resp->success );
        $this->assertSame( 'uploading', $this->options[ SBU_QUEUE ]['status'] );
    }

    public function test_resume_rejects_when_no_queue(): void {
        $resp = $this->invokeExpectingJson( 'ajax_resume_upload' );
        $this->assertFalse( $resp->success );
    }

    public function test_resume_flips_to_uploading_and_clears_gate(): void {
        $this->options[ SBU_QUEUE ] = $this->pausedQueue();

        $resp = $this->invokeExpectingJson( 'ajax_resume_upload' );
        $this->assertTrue( $resp->success );

        $q = $this->options[ SBU_QUEUE ];
        $this->assertSame( 'uploading', $q['status'] );
        $this->assertArrayNotHasKey( 'paused_ts', $q );
        $this->assertArrayNotHasKey( 'next_allowed_tick_ts', $q, 'gate must be cleared so the next tick runs immediately' );
        $this->assertArrayNotHasKey( 'next_retry_delay', $q );
    }

    /**
     * A restore queue (has `restore` or `dir` key) must flip back to
     * `restoring`, not `uploading`.
     */
    public function test_resume_flips_restore_queue_back_to_restoring(): void {
        $queue = $this->pausedQueue();
        $queue['restore'] = true;
        $queue['dir']     = 'Backups/2026-04-17';
        $this->options[ SBU_QUEUE ] = $queue;

        $this->invokeExpectingJson( 'ajax_resume_upload' );

        $this->assertSame( 'restoring', $this->options[ SBU_QUEUE ]['status'] );
    }

    public function test_resume_logs_progress(): void {
        $this->options[ SBU_QUEUE ] = $this->pausedQueue();

        $this->invokeExpectingJson( 'ajax_resume_upload' );

        $log = $this->options[ SBU_ACTIVITY ] ?? '';
        $this->assertStringContainsString( 'fortgesetzt', $log );
        $this->assertStringContainsString( 'backup-db.gz', $log );
    }

    // =====================================================================
    // Pause → Resume roundtrip
    // =====================================================================

    /**
     * The headline guarantee: after Pause followed by Resume, the exact
     * byte offset, file index, and counters survive verbatim.
     */
    public function test_pause_then_resume_preserves_exact_offset(): void {
        $this->options[ SBU_QUEUE ] = $this->runningQueue( [
            'file_idx' => 1,
            'files' => [
                [ 'path' => '/srv/backup-db.gz',      'size' => 10_000_000, 'offset' => 10_000_000, 'status' => 'uploaded' ],
                [ 'path' => '/srv/backup-plugins.zip', 'size' => 50_000_000, 'offset' =>  7_345_678, 'status' => 'uploading' ],
                [ 'path' => '/srv/backup-themes.zip',  'size' => 20_000_000, 'offset' =>          0, 'status' => 'pending' ],
            ],
            'ok'          => 1,
            'err'         => 0,
            'total_bytes' => 17_345_678,
        ] );

        $this->invokeExpectingJson( 'ajax_pause_upload' );
        $this->invokeExpectingJson( 'ajax_resume_upload' );

        $q = $this->options[ SBU_QUEUE ];
        $this->assertSame( 'uploading', $q['status'] );
        $this->assertSame( 1, $q['file_idx'] );
        $this->assertSame( 10_000_000, $q['files'][0]['offset'] );
        $this->assertSame(  7_345_678, $q['files'][1]['offset'], 'in-flight offset must survive the roundtrip byte-for-byte' );
        $this->assertSame(          0, $q['files'][2]['offset'] );
        $this->assertSame( 1, $q['ok'] );
        $this->assertSame( 0, $q['err'] );
        $this->assertSame( 17_345_678, $q['total_bytes'] );
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * Invoke an AJAX handler and capture the JsonResponse it throws via
     * the stubbed wp_send_json_* functions.
     */
    private function invokeExpectingJson( string $method ): JsonResponse {
        try {
            $this->plugin->$method();
        } catch ( JsonResponse $r ) {
            return $r;
        }
        $this->fail( "Expected {$method}() to call wp_send_json_success/error" );
    }

    /**
     * Queue shaped like one currently being processed.
     */
    private function runningQueue( array $overrides = [] ): array {
        return array_merge( [
            'status'        => 'uploading',
            'files'         => [
                [ 'path' => '/srv/backup-db.gz', 'size' => 10_000_000, 'offset' => 5_000_000, 'status' => 'uploading' ],
            ],
            'file_idx'      => 0,
            'ok'            => 0,
            'err'           => 0,
            'total_bytes'   => 5_000_000,
            'last_activity' => time(),
        ], $overrides );
    }

    /**
     * Queue shaped the way Pause leaves it.
     */
    private function pausedQueue( array $overrides = [] ): array {
        return array_merge( [
            'status'               => 'paused',
            'files'                => [
                [ 'path' => '/srv/backup-db.gz', 'size' => 10_000_000, 'offset' => 5_000_000, 'status' => 'uploading' ],
            ],
            'file_idx'             => 0,
            'ok'                   => 0,
            'err'                  => 0,
            'total_bytes'          => 5_000_000,
            'last_activity'        => time() - 60,
            'paused_ts'            => time() - 30,
            'next_allowed_tick_ts' => time() + YEAR_IN_SECONDS,
        ], $overrides );
    }
}
