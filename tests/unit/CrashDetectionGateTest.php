<?php
/**
 * Tests for SBU_Plugin::tick_is_gated() and
 * SBU_Plugin::detect_worker_crash_and_defer().
 *
 * Together these two guards are what prevents the classic failure mode of
 * a worker silently dying (OOM, Cloudflare 524) and then every subsequent
 * loopback/cron fire wedging the same queue back to life: the gate backs
 * off, and the crash detector installs the gate.
 */

declare( strict_types = 1 );

namespace SBU\Tests\Unit;

use SBU\Tests\Helpers\PluginLoader;
use SBU\Tests\Helpers\TestCase;
use SBU_Plugin;

/**
 * @covers \SBU_Plugin::tick_is_gated
 * @covers \SBU_Plugin::detect_worker_crash_and_defer
 */
final class CrashDetectionGateTest extends TestCase {

    private SBU_Plugin $plugin;

    protected function setUp(): void {
        parent::setUp();
        PluginLoader::load();
        $this->plugin = new SBU_Plugin();
    }

    // =====================================================================
    // tick_is_gated()
    // =====================================================================

    public function test_gate_returns_false_when_no_queue(): void {
        $this->assertFalse( $this->callPrivate( $this->plugin, 'tick_is_gated' ) );
    }

    public function test_gate_returns_false_when_no_gate_field(): void {
        $this->options[ SBU_QUEUE ] = [ 'status' => 'uploading' ];
        $this->assertFalse( $this->callPrivate( $this->plugin, 'tick_is_gated' ) );
    }

    public function test_gate_returns_false_when_gate_is_in_the_past(): void {
        $this->options[ SBU_QUEUE ] = [
            'status'               => 'uploading',
            'next_allowed_tick_ts' => time() - 1,
        ];
        $this->assertFalse( $this->callPrivate( $this->plugin, 'tick_is_gated' ) );
    }

    public function test_gate_returns_true_when_gate_is_in_the_future(): void {
        $this->options[ SBU_QUEUE ] = [
            'status'               => 'uploading',
            'next_allowed_tick_ts' => time() + 300,
        ];
        $this->assertTrue( $this->callPrivate( $this->plugin, 'tick_is_gated' ) );
    }

    // =====================================================================
    // detect_worker_crash_and_defer()
    // =====================================================================

    /**
     * A brand-new queue has last_activity === 0: there has been no tick yet,
     * so "idle for N seconds" is not meaningful. Must not trigger a crash.
     */
    public function test_no_crash_when_last_activity_is_zero(): void {
        $queue = $this->baseQueue( [ 'last_activity' => 0 ] );
        $this->options[ SBU_QUEUE ] = $queue;
        $ret = $this->callPrivate( $this->plugin, 'detect_worker_crash_and_defer', [ $queue ] );
        $this->assertFalse( $ret );
        $this->assertArrayNotHasKey( 'next_allowed_tick_ts', $this->options[ SBU_QUEUE ] );
    }

    /**
     * If we are already inside a backoff window (gate in future), the
     * detector must not stack a fresh backoff on top.
     */
    public function test_no_crash_when_gate_already_in_future(): void {
        $queue = $this->baseQueue( [
            'last_activity'        => time() - 10_000,
            'next_allowed_tick_ts' => time() + 120,
        ] );
        $this->options[ SBU_QUEUE ] = $queue;
        $ret = $this->callPrivate( $this->plugin, 'detect_worker_crash_and_defer', [ $queue ] );
        $this->assertFalse( $ret );
        // Gate untouched.
        $this->assertSame( $queue['next_allowed_tick_ts'], $this->options[ SBU_QUEUE ]['next_allowed_tick_ts'] );
    }

    /**
     * Idle below queue_lock_ttl()+30: a slow tick, not a dead tick.
     */
    public function test_no_crash_when_idle_under_threshold(): void {
        $queue = $this->baseQueue( [ 'last_activity' => time() - 60 ] );
        $this->options[ SBU_QUEUE ] = $queue;
        $ret = $this->callPrivate( $this->plugin, 'detect_worker_crash_and_defer', [ $queue ] );
        $this->assertFalse( $ret );
    }

    /**
     * The happy path for the detector: idle >> threshold. Must bump
     * retries 0→1, install a 60s gate (retries*60), update last_activity,
     * and persist the queue.
     */
    public function test_crash_bumps_retries_and_installs_gate(): void {
        $before = time();
        $queue  = $this->baseQueue( [ 'last_activity' => time() - 500 ] );
        $this->options[ SBU_QUEUE ] = $queue;

        $ret = $this->callPrivate( $this->plugin, 'detect_worker_crash_and_defer', [ $queue ] );
        $this->assertTrue( $ret, 'crash must be detected' );

        $persisted = $this->options[ SBU_QUEUE ];
        $this->assertSame( 1, $persisted['files'][0]['retries'] );
        $this->assertGreaterThanOrEqual( $before + 60, $persisted['next_allowed_tick_ts'] );
        $this->assertLessThanOrEqual( time() + 60, $persisted['next_allowed_tick_ts'] );
        $this->assertGreaterThanOrEqual( $before, $persisted['last_activity'] );
    }

    /**
     * Exponential backoff: delay = min( retries*60, 600 ). Second crash
     * of the same file installs a 120s gate.
     */
    public function test_second_crash_installs_longer_gate(): void {
        $queue = $this->baseQueue( [
            'last_activity' => time() - 500,
            'files'         => [ [ 'path' => '/srv/backup-db.gz', 'retries' => 1 ] ],
        ] );
        $this->options[ SBU_QUEUE ] = $queue;
        $before = time();

        $ret = $this->callPrivate( $this->plugin, 'detect_worker_crash_and_defer', [ $queue ] );
        $this->assertTrue( $ret );

        $persisted = $this->options[ SBU_QUEUE ];
        $this->assertSame( 2, $persisted['files'][0]['retries'] );
        // retries=2 → delay=120
        $this->assertGreaterThanOrEqual( $before + 120, $persisted['next_allowed_tick_ts'] );
        $this->assertLessThanOrEqual( time() + 120, $persisted['next_allowed_tick_ts'] );
    }

    /**
     * Backoff cap: retries above the 10-minute ceiling stay at 600s.
     */
    public function test_backoff_is_capped_at_600_seconds(): void {
        $queue = $this->baseQueue( [
            'last_activity' => time() - 10_000,
            'files'         => [ [ 'path' => '/srv/backup-db.gz', 'retries' => 42 ] ],
        ] );
        $this->options[ SBU_QUEUE ] = $queue;
        $before = time();

        $this->callPrivate( $this->plugin, 'detect_worker_crash_and_defer', [ $queue ] );

        $gate = $this->options[ SBU_QUEUE ]['next_allowed_tick_ts'];
        $this->assertGreaterThanOrEqual( $before + 600, $gate );
        $this->assertLessThanOrEqual( time() + 600, $gate );
    }

    /**
     * A detected crash produces exactly one WARNUNG entry in the activity
     * log that contains the file basename — otherwise the user stares at
     * unexplained silence in the UI.
     */
    public function test_crash_logs_warning_with_file_basename(): void {
        $queue = $this->baseQueue( [
            'last_activity' => time() - 500,
            'files'         => [ [ 'path' => '/srv/wp-content/updraft/backup-db.gz', 'retries' => 0, 'offset' => 5_000_000 ] ],
        ] );
        $this->options[ SBU_QUEUE ] = $queue;

        $this->callPrivate( $this->plugin, 'detect_worker_crash_and_defer', [ $queue ] );

        $log = $this->options[ SBU_ACTIVITY ] ?? '';
        $this->assertStringContainsString( 'WARNUNG', $log );
        $this->assertStringContainsString( 'backup-db.gz', $log, 'log must identify which file hung' );
    }

    // =====================================================================
    // Fixtures
    // =====================================================================

    /**
     * A queue shaped like one produced by create_upload_queue(), but
     * shrunken to the fields the detector actually reads. Callers merge
     * overrides on top.
     *
     * @param array $overrides
     * @return array
     */
    private function baseQueue( array $overrides = [] ): array {
        return array_merge( [
            'status'        => 'uploading',
            'files'         => [ [ 'path' => '/srv/backup-db.gz', 'retries' => 0, 'offset' => 0 ] ],
            'file_idx'      => 0,
            'ok'            => 0,
            'err'           => 0,
            'total_bytes'   => 0,
            'last_activity' => 0,
        ], $overrides );
    }
}
