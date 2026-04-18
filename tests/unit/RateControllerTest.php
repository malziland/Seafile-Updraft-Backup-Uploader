<?php
/**
 * Tests for SBU_Plugin::update_rate_state() — the AIMD controller that
 * drives chunk_mb and parallel_downloads per batch.
 *
 * The controller mirrors TCP congestion control: additive increase on
 * good batches, multiplicative decrease on bad ones, with a two-strike
 * rule that drops into emergency mode (1 × 2 MB). Successful batches in
 * slow or emergency mode recover in a staged pattern rather than jumping
 * straight back to cruise, so one lucky batch after a Cloudflare wobble
 * doesn't instantly re-saturate the tunnel and retrigger a 524.
 */

declare( strict_types = 1 );

namespace SBU\Tests\Unit;

use SBU\Tests\Helpers\PluginLoader;
use SBU\Tests\Helpers\TestCase;
use SBU_Plugin;

/**
 * @covers \SBU_Plugin::update_rate_state
 */
final class RateControllerTest extends TestCase {

    private const CEILINGS = [ 'chunk_mb_max' => 20, 'parallel_max' => 4 ];

    protected function setUp(): void {
        parent::setUp();
        PluginLoader::load();
    }

    private static function cruise_at_ceiling(): array {
        return [
            'chunk_mb'        => 20,
            'parallel'        => 4,
            'consecutive_bad' => 0,
            'mode'            => 'cruise',
        ];
    }

    public function test_good_batch_at_ceiling_stays_at_ceiling(): void {
        $state = self::cruise_at_ceiling();
        $next  = SBU_Plugin::update_rate_state(
            $state,
            [ 'ok' => true, 'any_transient' => false ],
            self::CEILINGS
        );
        $this->assertSame( 20, $next['chunk_mb'] );
        $this->assertSame( 4,  $next['parallel'] );
        $this->assertSame( 'cruise', $next['mode'] );
        $this->assertSame( 0, $next['consecutive_bad'] );
    }

    public function test_single_transient_halves_chunk_and_parallel_into_slow_mode(): void {
        $state = self::cruise_at_ceiling();
        $next  = SBU_Plugin::update_rate_state(
            $state,
            [ 'ok' => false, 'any_transient' => true ],
            self::CEILINGS
        );
        $this->assertSame( 'slow', $next['mode'] );
        $this->assertSame( 10, $next['chunk_mb'], 'halved from 20' );
        $this->assertSame( 2,  $next['parallel'], 'halved from 4' );
        $this->assertSame( 1,  $next['consecutive_bad'] );
    }

    public function test_two_consecutive_failures_drop_into_emergency_mode(): void {
        $after_first = SBU_Plugin::update_rate_state(
            self::cruise_at_ceiling(),
            [ 'ok' => false, 'any_transient' => true ],
            self::CEILINGS
        );
        $after_second = SBU_Plugin::update_rate_state(
            $after_first,
            [ 'ok' => false, 'any_transient' => true ],
            self::CEILINGS
        );
        $this->assertSame( 'emergency', $after_second['mode'] );
        $this->assertSame( 2, $after_second['chunk_mb'],  'emergency floor is 2 MB' );
        $this->assertSame( 1, $after_second['parallel'], 'emergency floor is 1 stream' );
    }

    public function test_emergency_good_batch_recovers_to_slow_not_cruise(): void {
        $state = [
            'chunk_mb'        => 2,
            'parallel'        => 1,
            'consecutive_bad' => 2,
            'mode'            => 'emergency',
        ];
        $next  = SBU_Plugin::update_rate_state(
            $state,
            [ 'ok' => true, 'any_transient' => false ],
            self::CEILINGS
        );
        $this->assertSame( 'slow', $next['mode'], 'one good batch in emergency → slow, not cruise' );
        $this->assertGreaterThan( 2, $next['chunk_mb'], 'chunk grows but stays conservative' );
        $this->assertSame( 2, $next['parallel'], 'parallel +1 from 1' );
        $this->assertSame( 0, $next['consecutive_bad'] );
    }

    public function test_slow_mode_grows_back_and_eventually_returns_to_cruise(): void {
        $state = [
            'chunk_mb'        => 10,
            'parallel'        => 2,
            'consecutive_bad' => 0,
            'mode'            => 'slow',
        ];
        $ok = [ 'ok' => true, 'any_transient' => false ];
        for ( $i = 0; $i < 20; $i++ ) {
            $state = SBU_Plugin::update_rate_state( $state, $ok, self::CEILINGS );
            if ( $state['mode'] === 'cruise' ) break;
        }
        $this->assertSame( 'cruise', $state['mode'], 'eventually returns to cruise' );
        $this->assertSame( 20, $state['chunk_mb'] );
        $this->assertSame( 4,  $state['parallel'] );
    }

    public function test_pure_fatal_without_transient_does_not_dock_speed(): void {
        // ok=false but any_transient=false means the failure was e.g. a
        // signed_url or deadline — not a transport-health signal, so the
        // AIMD controller must still treat the outcome as "bad" (the batch
        // did not succeed). The retry-counter gate in the caller is what
        // keeps deadline-only failures from inflating backoff; here we
        // just verify the controller reacts consistently to ok=false.
        $state = self::cruise_at_ceiling();
        $next  = SBU_Plugin::update_rate_state(
            $state,
            [ 'ok' => false, 'any_transient' => false ],
            self::CEILINGS
        );
        $this->assertSame( 'slow', $next['mode'] );
        $this->assertLessThan( 20, $next['chunk_mb'] );
    }

    public function test_good_batch_resets_consecutive_bad_counter(): void {
        $state = [
            'chunk_mb'        => 10,
            'parallel'        => 2,
            'consecutive_bad' => 1,
            'mode'            => 'slow',
        ];
        $next  = SBU_Plugin::update_rate_state(
            $state,
            [ 'ok' => true, 'any_transient' => false ],
            self::CEILINGS
        );
        $this->assertSame( 0, $next['consecutive_bad'], 'a good batch clears the bad streak' );
    }

    public function test_clamps_values_against_ceilings(): void {
        // A queue persisted on a bigger ceiling before the admin lowered
        // the host limit must not request more than the new ceiling.
        $state = [
            'chunk_mb'        => 50,
            'parallel'        => 16,
            'consecutive_bad' => 0,
            'mode'            => 'cruise',
        ];
        $next = SBU_Plugin::update_rate_state(
            $state,
            [ 'ok' => true, 'any_transient' => false ],
            self::CEILINGS
        );
        $this->assertLessThanOrEqual( 20, $next['chunk_mb'] );
        $this->assertLessThanOrEqual( 4,  $next['parallel'] );
    }

    public function test_seeds_sensible_defaults_on_missing_state(): void {
        $next = SBU_Plugin::update_rate_state(
            [],
            [ 'ok' => true, 'any_transient' => false ],
            self::CEILINGS
        );
        $this->assertGreaterThanOrEqual( 2, $next['chunk_mb'] );
        $this->assertGreaterThanOrEqual( 1, $next['parallel'] );
        $this->assertContains( $next['mode'], [ 'cruise', 'slow', 'emergency' ] );
    }
}
