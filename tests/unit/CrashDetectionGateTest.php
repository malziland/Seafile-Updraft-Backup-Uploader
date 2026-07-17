<?php
/**
 * Tests for SBU_Queue_Engine::tick_is_gated() and
 * SBU_Plugin::detect_worker_crash_and_defer().
 *
 * Together these two guards are what prevents the classic failure mode of
 * a worker silently dying (OOM, Cloudflare 524) and then every subsequent
 * loopback/cron fire wedging the same queue back to life: the gate backs
 * off, and the crash detector installs the gate.
 *
 * Seit ARCH-001 Schritt 3 lebt die Gate-Prüfung in SBU_Queue_Engine; die
 * Gate-Tests rufen sie direkt dort auf, Crash-Detection-Tests bleiben auf
 * SBU_Plugin weil detect_worker_crash_and_defer() mit Queue-Mutation und
 * Activity-Logging an die Plugin-Klasse gekoppelt bleibt.
 */

declare( strict_types = 1 );

namespace SBU\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversMethod;
use SBU\Tests\Helpers\PluginLoader;
use SBU\Tests\Helpers\TestCase;
use SBU_Plugin;
use SBU_Queue_Engine;

#[CoversMethod(SBU_Queue_Engine::class, 'tick_is_gated')]
#[CoversMethod(SBU_Plugin::class, 'detect_worker_crash_and_defer')]
final class CrashDetectionGateTest extends TestCase {

    private SBU_Plugin $plugin;

    /**
     * Engine instance used for the gate tests. Unabhängig vom Plugin
     * konstruiert — Cron-Key/Adaptive-Limits werden für tick_is_gated()
     * gar nicht ausgewertet, daher reichen triviale Provider.
     */
    private SBU_Queue_Engine $engine;

    protected function setUp(): void {
        parent::setUp();
        PluginLoader::load();
        $this->plugin = new SBU_Plugin();
        $this->engine = new SBU_Queue_Engine(
            static function () {
                return 'test-key';
            },
            static function () {
                return array( 'tick_time' => 25 );
            }
        );
    }

    // =====================================================================
    // tick_is_gated()
    // =====================================================================

    public function test_gate_returns_false_when_no_queue(): void {
        $this->assertFalse( $this->engine->tick_is_gated() );
    }

    public function test_gate_returns_false_when_no_gate_field(): void {
        $this->options[ SBU_QUEUE ] = [ 'status' => 'uploading' ];
        $this->assertFalse( $this->engine->tick_is_gated() );
    }

    public function test_gate_returns_false_when_gate_is_in_the_past(): void {
        $this->options[ SBU_QUEUE ] = [
            'status'               => 'uploading',
            'next_allowed_tick_ts' => time() - 1,
        ];
        $this->assertFalse( $this->engine->tick_is_gated() );
    }

    public function test_gate_returns_true_when_gate_is_in_the_future(): void {
        $this->options[ SBU_QUEUE ] = [
            'status'               => 'uploading',
            'next_allowed_tick_ts' => time() + 300,
        ];
        $this->assertTrue( $this->engine->tick_is_gated() );
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
     * Retry cap: once a single file has racked up CRASH_RETRY_CAP crashes,
     * skip it and let the queue continue with the next file. Replaces the
     * old "infinite backoff up to 600s" behaviour that produced the 17h
     * production loop the cap was added to prevent.
     */
    public function test_file_is_skipped_after_retry_cap(): void {
        $queue = $this->baseQueue( [
            'last_activity' => time() - 10_000,
            'files'         => [
                [ 'path' => '/srv/backup-uploads.zip', 'retries' => 42, 'offset' => 0 ],
                [ 'path' => '/srv/backup-wpcore.zip', 'retries' => 0, 'offset' => 0 ],
            ],
        ] );
        $this->options[ SBU_QUEUE ] = $queue;

        $this->callPrivate( $this->plugin, 'detect_worker_crash_and_defer', [ $queue ] );

        $persisted = $this->options[ SBU_QUEUE ];
        $this->assertSame( 'error', $persisted['files'][0]['status'] );
        $this->assertSame( 1, $persisted['err'] );
        $this->assertSame( 1, $persisted['file_idx'], 'queue must advance past the stuck file' );
        $this->assertArrayNotHasKey( 'next_allowed_tick_ts', $persisted );

        $log = $this->options[ SBU_ACTIVITY ] ?? '';
        $this->assertStringContainsString( 'FEHLER', $log );
        $this->assertStringContainsString( 'backup-uploads.zip', $log );
    }

    /**
     * Repeat crash at the *same byte offset* halves the per-file chunk
     * size. The next tick will then send a smaller request that fits
     * under the host's PHP limits.
     */
    public function test_same_offset_crash_halves_chunk_size(): void {
        $queue = $this->baseQueue( [
            'last_activity' => time() - 500,
            'chunk_size'    => 40 * 1024 * 1024,
            'files'         => [ [
                'path'              => '/srv/backup-uploads.zip',
                'retries'           => 1,
                'offset'            => 125_829_120, // 120 MB — the actual stall point from the production log
                'crash_offset'      => 125_829_120,
                'crashes_at_offset' => 1,
            ] ],
        ] );
        $this->options[ SBU_QUEUE ] = $queue;

        $this->callPrivate( $this->plugin, 'detect_worker_crash_and_defer', [ $queue ] );

        $persisted = $this->options[ SBU_QUEUE ];
        $this->assertArrayHasKey( 'chunk_size_override', $persisted['files'][0] );
        $this->assertSame( 20 * 1024 * 1024, $persisted['files'][0]['chunk_size_override'] );
        $this->assertSame( 2, $persisted['files'][0]['crashes_at_offset'] );

        $log = $this->options[ SBU_ACTIVITY ] ?? '';
        $this->assertStringContainsString( 'an gleicher Stelle', $log );
        $this->assertStringContainsString( '20 MB', $log );
    }

    /**
     * When the chunk size has already been shrunk to the floor and we
     * still crash at the same offset, skip the file. This is the case
     * where the offending byte is not a chunk-size problem at all
     * (Seafile-side stuck PUT, corrupt source byte, etc.) so retrying
     * forever can't help.
     */
    public function test_same_offset_crash_at_chunk_floor_skips_file(): void {
        $queue = $this->baseQueue( [
            'last_activity' => time() - 500,
            'chunk_size'    => 40 * 1024 * 1024,
            'files'         => [
                [
                    'path'                => '/srv/backup-uploads.zip',
                    'retries'             => 1,
                    'offset'              => 125_829_120,
                    'crash_offset'        => 125_829_120,
                    'crashes_at_offset'   => 1,
                    'chunk_size_override' => 4 * 1024 * 1024, // already at floor
                ],
                [ 'path' => '/srv/backup-wpcore.zip', 'retries' => 0, 'offset' => 0 ],
            ],
        ] );
        $this->options[ SBU_QUEUE ] = $queue;

        $this->callPrivate( $this->plugin, 'detect_worker_crash_and_defer', [ $queue ] );

        $persisted = $this->options[ SBU_QUEUE ];
        $this->assertSame( 'error', $persisted['files'][0]['status'] );
        $this->assertSame( 1, $persisted['file_idx'] );
        $this->assertSame( 1, $persisted['err'] );
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

    /**
     * BUG-02 regression: the crash-recovery write must not clobber a status
     * a concurrent request set in the DB while this tick was mid-flight. The
     * detector works off an in-memory 'uploading' snapshot, but if the user
     * pressed Pause in the meantime the DB row is already 'paused' — the
     * recovery write goes through safe_queue_update(), which preserves it.
     */
    public function test_crash_recovery_preserves_concurrent_pause(): void {
        // The snapshot this tick is working with — still 'uploading'.
        $in_memory = $this->baseQueue( [ 'last_activity' => time() - 500 ] );
        // Meanwhile the user pressed Pause: the DB row is already 'paused'
        // and carries the far-future gate that Pause installs.
        $this->options[ SBU_QUEUE ] = [
            'status'               => 'paused',
            'files'                => [ [ 'path' => '/srv/backup-db.gz', 'retries' => 0, 'offset' => 0 ] ],
            'file_idx'             => 0,
            'ok'                   => 0,
            'err'                  => 0,
            'total_bytes'          => 0,
            'next_allowed_tick_ts' => time() + 31_536_000,
        ];

        $ret = $this->callPrivate( $this->plugin, 'detect_worker_crash_and_defer', [ $in_memory ] );
        $this->assertTrue( $ret, 'crash is still detected from the in-memory snapshot' );
        $this->assertSame( 'paused', $this->options[ SBU_QUEUE ]['status'], 'recovery write must not un-pause the queue' );
    }

    /**
     * BUG-02, give-up branch: skipping a repeatedly-crashing file also runs
     * through safe_queue_update(), so a concurrent pause survives here too.
     */
    public function test_crash_skip_preserves_concurrent_pause(): void {
        $in_memory = $this->baseQueue( [
            'last_activity' => time() - 10_000,
            'files'         => [ [ 'path' => '/srv/backup-uploads.zip', 'retries' => 42, 'offset' => 0 ] ],
        ] );
        $this->options[ SBU_QUEUE ] = [
            'status'               => 'paused',
            'files'                => [ [ 'path' => '/srv/backup-uploads.zip', 'retries' => 42, 'offset' => 0 ] ],
            'file_idx'             => 0,
            'ok'                   => 0,
            'err'                  => 0,
            'total_bytes'          => 0,
            'next_allowed_tick_ts' => time() + 31_536_000,
        ];

        $this->callPrivate( $this->plugin, 'detect_worker_crash_and_defer', [ $in_memory ] );
        $this->assertSame( 'paused', $this->options[ SBU_QUEUE ]['status'], 'skip write must not un-pause the queue' );
    }

    // =====================================================================
    // idle_credit (BUG-03)
    // =====================================================================

    /**
     * BUG-03: the measured dead time must be banked as idle_credit so the
     * queue timeout only budgets active runtime. Without this, a queue
     * resumed after a long silent crash is killed by the very next
     * timeout check (production log 2026-07-17: 14.3 h dead → "Queue-
     * Timeout nach 14.8h" two minutes after resume).
     */
    public function test_crash_banks_idle_time_as_credit(): void {
        $queue = $this->baseQueue( [ 'last_activity' => time() - 500 ] );
        $this->options[ SBU_QUEUE ] = $queue;

        $this->callPrivate( $this->plugin, 'detect_worker_crash_and_defer', [ $queue ] );

        $credit = $this->options[ SBU_QUEUE ]['idle_credit'] ?? 0;
        $this->assertGreaterThanOrEqual( 500, $credit );
        $this->assertLessThanOrEqual( 510, $credit, 'credit must be the measured idle, not more' );
    }

    /**
     * Repeated crashes accumulate: each detection adds its own idle window
     * on top of what earlier crashes already banked.
     */
    public function test_crash_accumulates_idle_credit(): void {
        $queue = $this->baseQueue( [
            'last_activity' => time() - 500,
            'idle_credit'   => 1_000,
        ] );
        $this->options[ SBU_QUEUE ] = $queue;

        $this->callPrivate( $this->plugin, 'detect_worker_crash_and_defer', [ $queue ] );

        $credit = $this->options[ SBU_QUEUE ]['idle_credit'] ?? 0;
        $this->assertGreaterThanOrEqual( 1_500, $credit );
        $this->assertLessThanOrEqual( 1_510, $credit );
    }

    /**
     * The give-up branch (file skipped after retry cap) banks the idle
     * time too — the queue continues with the next files and must not
     * inherit a burned budget from the stall it just escaped.
     */
    public function test_crash_skip_banks_idle_time_as_credit(): void {
        $queue = $this->baseQueue( [
            'last_activity' => time() - 10_000,
            'files'         => [
                [ 'path' => '/srv/backup-uploads.zip', 'retries' => 42, 'offset' => 0 ],
                [ 'path' => '/srv/backup-wpcore.zip', 'retries' => 0, 'offset' => 0 ],
            ],
        ] );
        $this->options[ SBU_QUEUE ] = $queue;

        $this->callPrivate( $this->plugin, 'detect_worker_crash_and_defer', [ $queue ] );

        $credit = $this->options[ SBU_QUEUE ]['idle_credit'] ?? 0;
        $this->assertGreaterThanOrEqual( 10_000, $credit );
        $this->assertLessThanOrEqual( 10_010, $credit );
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
