<?php
/**
 * Tests for SBU_Plugin::compute_retry_delay() — exponential backoff with
 * separate tiers for "empty body" failures vs. plain transient errors.
 *
 * "empty body" (HTTP 200/206 with zero-byte payload) signals a cold
 * server-side backend fetch; hammering a cold backend makes the problem
 * worse. Plain transient (network hiccup, 502/504, connect timeout)
 * usually clears in seconds to minutes, so the gentler tier lets the
 * queue recover faster.
 */

declare( strict_types = 1 );

namespace SBU\Tests\Unit;

use SBU\Tests\Helpers\PluginLoader;
use SBU\Tests\Helpers\TestCase;
use SBU_Plugin;

/**
 * @covers \SBU_Plugin::compute_retry_delay
 */
final class RetryDelayTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        PluginLoader::load();
    }

    public function test_transient_tier_first_attempt_is_60s(): void {
        $this->assertSame( 60, SBU_Plugin::compute_retry_delay( 1, 'transient' ) );
    }

    public function test_empty_tier_first_attempt_is_60s(): void {
        $this->assertSame( 60, SBU_Plugin::compute_retry_delay( 1, 'empty' ) );
    }

    public function test_empty_tier_jumps_faster_than_transient(): void {
        // At attempt 3, empty should already be 900 s while transient is
        // still at 240 s. The wider gap is intentional: empty = cold
        // backend, which takes minutes to warm up, so there's no point
        // knocking every two minutes.
        $this->assertSame( 900, SBU_Plugin::compute_retry_delay( 3, 'empty' ) );
        $this->assertSame( 240, SBU_Plugin::compute_retry_delay( 3, 'transient' ) );
    }

    public function test_both_tiers_cap_at_one_hour(): void {
        $this->assertSame( 3600, SBU_Plugin::compute_retry_delay( 99, 'empty' ) );
        $this->assertSame( 3600, SBU_Plugin::compute_retry_delay( 99, 'transient' ) );
    }

    public function test_attempt_zero_clamped_to_one(): void {
        // 0 and negatives are treated as attempt=1 so callers that
        // accidentally pass ( $retries ) instead of ( $retries + 1 ) still
        // get a valid backoff rather than a 0-second retry.
        $this->assertSame( 60, SBU_Plugin::compute_retry_delay( 0, 'empty' ) );
        $this->assertSame( 60, SBU_Plugin::compute_retry_delay( -5, 'transient' ) );
    }

    public function test_unknown_kind_defaults_to_transient_tier(): void {
        // Calling with a typo or future error code should fall back to
        // the gentler transient curve, not crash.
        $this->assertSame( 120, SBU_Plugin::compute_retry_delay( 2, 'whatever' ) );
    }

    public function test_empty_tier_monotonic(): void {
        $prev = 0;
        foreach ( range( 1, 10 ) as $n ) {
            $d = SBU_Plugin::compute_retry_delay( $n, 'empty' );
            $this->assertGreaterThanOrEqual( $prev, $d, "attempt {$n}" );
            $prev = $d;
        }
    }

    public function test_transient_tier_monotonic(): void {
        $prev = 0;
        foreach ( range( 1, 12 ) as $n ) {
            $d = SBU_Plugin::compute_retry_delay( $n, 'transient' );
            $this->assertGreaterThanOrEqual( $prev, $d, "attempt {$n}" );
            $prev = $d;
        }
    }
}
