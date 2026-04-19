<?php
/**
 * Tests for SBU_Plugin::compute_adaptive_limits(): the tick budget and
 * download parallelism must scale with the server's actual limits, not
 * stay hardcoded. A 300 s server should get longer ticks; a 30 s server
 * must stay safely under the PHP hard timeout.
 */

declare( strict_types = 1 );

namespace SBU\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversMethod;
use SBU\Tests\Helpers\PluginLoader;
use SBU\Tests\Helpers\TestCase;
use SBU_Plugin;

#[CoversMethod(SBU_Plugin::class, 'compute_adaptive_limits')]
#[CoversMethod(SBU_Plugin::class, 'compute_queue_timeout')]
final class AdaptiveLimitsTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        PluginLoader::load();
    }

    public function test_tick_time_scales_with_max_execution_time(): void {
        $thirty = SBU_Plugin::compute_adaptive_limits( 30, 256 * 1024 * 1024 );
        $this->assertSame( 25, $thirty['tick_time'], '30s server → 25s tick (5s buffer)' );

        $sixty = SBU_Plugin::compute_adaptive_limits( 60, 256 * 1024 * 1024 );
        $this->assertSame( 55, $sixty['tick_time'], '60s server → 55s tick' );

        $three_hundred = SBU_Plugin::compute_adaptive_limits( 300, 256 * 1024 * 1024 );
        $this->assertSame( 250, $three_hundred['tick_time'], '300s server → hits ceiling (SBU_TICK_TIME)' );
    }

    public function test_chunk_mb_scales_with_tick_budget(): void {
        // 25 s tick → ceil(25 * 0.2) = 5 MB
        $small = SBU_Plugin::compute_adaptive_limits( 30, 256 * 1024 * 1024 );
        $this->assertSame( 5, $small['chunk_mb_download'], '25s tick → 5 MB chunk fits' );

        // 55 s tick → ceil(55 * 0.2) = 11 MB
        $mid = SBU_Plugin::compute_adaptive_limits( 60, 256 * 1024 * 1024 );
        $this->assertSame( 11, $mid['chunk_mb_download'], '55s tick → 11 MB chunk' );

        // 250 s tick → ceil(250 * 0.2) = 50 → capped at 20 MB ceiling
        $big = SBU_Plugin::compute_adaptive_limits( 300, 256 * 1024 * 1024 );
        $this->assertSame( 20, $big['chunk_mb_download'], '250s tick → ceiling' );
    }

    public function test_chunk_mb_floor_on_low_budgets(): void {
        // 20 s floor tick → ceil(20 * 0.2) = 4, exactly the floor
        $lim = SBU_Plugin::compute_adaptive_limits( 15, 256 * 1024 * 1024 );
        $this->assertSame( 4, $lim['chunk_mb_download'], '20s tick floors at 4 MB' );
    }

    public function test_tick_time_floor_handles_absurdly_low_limits(): void {
        $lim = SBU_Plugin::compute_adaptive_limits( 15, 256 * 1024 * 1024 );
        $this->assertSame( 20, $lim['tick_time'], 'Hard floor at 20s so a tick can actually do work' );
    }

    public function test_tick_time_unlimited_max_execution_time(): void {
        $lim = SBU_Plugin::compute_adaptive_limits( 0, 256 * 1024 * 1024 );
        $this->assertSame( 250, $lim['tick_time'], 'met=0 is unlimited → use the ceiling' );
    }

    public function test_parallel_downloads_scales_with_memory(): void {
        // At 60s tick the adaptive chunk size is 11 MB, so
        // parallel = floor(mem / (11 MB * 3)).
        $tiny = SBU_Plugin::compute_adaptive_limits( 60, 128 * 1024 * 1024 );
        $this->assertSame( 3, $tiny['parallel_downloads'], '128 MB / (11 MB × 3) = 3' );

        $med = SBU_Plugin::compute_adaptive_limits( 60, 256 * 1024 * 1024 );
        $this->assertSame( 7, $med['parallel_downloads'], '256 MB / (11 MB × 3) = 7' );

        $big = SBU_Plugin::compute_adaptive_limits( 60, 1024 * 1024 * 1024 );
        $this->assertSame( 8, $big['parallel_downloads'], '1 GB → hits SBU_PARALLEL_DOWNLOADS_MAX ceiling' );
    }

    public function test_parallel_downloads_floor_is_one(): void {
        $lim = SBU_Plugin::compute_adaptive_limits( 60, 32 * 1024 * 1024 );
        $this->assertSame( 1, $lim['parallel_downloads'], 'Always at least 1 parallel chunk' );
    }

    public function test_parallel_downloads_unlimited_memory_uses_ceiling(): void {
        $lim = SBU_Plugin::compute_adaptive_limits( 60, 0 );
        $this->assertSame( 8, $lim['parallel_downloads'], 'mem=0 is unlimited → ceiling' );
    }

    public function test_chunk_mb_at_ceiling(): void {
        // Any tick ≥ 100s picks the 20 MB ceiling.
        $lim = SBU_Plugin::compute_adaptive_limits( 120, 256 * 1024 * 1024 );
        $this->assertSame( 20, $lim['chunk_mb_download'] );
    }

    /**
     * 80 GB at 2 MB/s × 1.5 = ~17 h. Above the legacy 12 h floor but
     * below the 24 h cap, so the size-derived value wins.
     */
    public function test_queue_timeout_scales_with_total_size(): void {
        $plugin = new SBU_Plugin();
        $queue_big = [
            'size_total' => 80 * 1024 * 1024 * 1024,
        ];
        $t = $this->callPrivate( $plugin, 'compute_queue_timeout', [ $queue_big ] );
        $this->assertGreaterThan( SBU_QUEUE_TIMEOUT, $t, 'Big queue must exceed the 12h floor' );
        $this->assertLessThanOrEqual( 86400, $t, 'Never more than 24h' );
    }

    public function test_queue_timeout_tiny_queue_gets_floor(): void {
        $plugin = new SBU_Plugin();
        $queue_small = [
            'size_total' => 10 * 1024 * 1024,
        ];
        $t = $this->callPrivate( $plugin, 'compute_queue_timeout', [ $queue_small ] );
        $this->assertSame( (int) SBU_QUEUE_TIMEOUT, $t, 'Tiny queues floor at SBU_QUEUE_TIMEOUT' );
    }

    public function test_queue_timeout_capped_at_24h(): void {
        $plugin = new SBU_Plugin();
        $queue_huge = [
            'size_total' => 5 * 1024 * 1024 * 1024 * 1024,
        ];
        $t = $this->callPrivate( $plugin, 'compute_queue_timeout', [ $queue_huge ] );
        $this->assertSame( 86400, $t, 'Hard cap at 24h even for enormous queues' );
    }

    public function test_queue_timeout_sums_file_sizes_when_total_absent(): void {
        $plugin = new SBU_Plugin();
        $queue = [
            'files' => [
                [ 'size' => 40 * 1024 * 1024 * 1024 ],
                [ 'size' => 40 * 1024 * 1024 * 1024 ],
            ],
        ];
        $t = $this->callPrivate( $plugin, 'compute_queue_timeout', [ $queue ] );
        $this->assertGreaterThan( SBU_QUEUE_TIMEOUT, $t );
    }
}
