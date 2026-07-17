<?php
/**
 * Tests for SBU_Plugin::queue_timed_out() (BUG-03).
 *
 * The invariant under test: the queue timeout budgets *active* runtime
 * only. Dead time from silent worker crashes and user pauses is banked
 * in 'idle_credit' and must not count against the budget — otherwise a
 * correctly resumed queue is killed by the very next timeout check.
 *
 * Regression source: production log 2026-07-17 (INTERIORISTA). Worker
 * died silently at 00:16 during file 35/72, admin fallback resumed at
 * 14:33, timeout aborted the healthy queue at 14:35 with "Queue-Timeout
 * nach 14.8h: 34 OK, 0 Fehler".
 */

declare( strict_types = 1 );

namespace SBU\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversMethod;
use SBU\Tests\Helpers\PluginLoader;
use SBU\Tests\Helpers\TestCase;
use SBU_Plugin;

#[CoversMethod(SBU_Plugin::class, 'queue_timed_out')]
final class QueueTimeoutTest extends TestCase {

    private SBU_Plugin $plugin;

    protected function setUp(): void {
        parent::setUp();
        PluginLoader::load();
        $this->plugin = new SBU_Plugin();
    }

    public function test_no_timeout_without_started_timestamp(): void {
        $ret = $this->callPrivate( $this->plugin, 'queue_timed_out', [ [ 'size_total' => 0 ] ] );
        $this->assertFalse( $ret );
    }

    public function test_no_timeout_while_inside_budget(): void {
        $queue = [
            'started'    => time() - 3600, // 1 h active, budget floor is 12 h
            'size_total' => 0,
        ];
        $this->assertFalse( $this->callPrivate( $this->plugin, 'queue_timed_out', [ $queue ] ) );
    }

    public function test_timeout_fires_on_genuine_overrun(): void {
        $queue = [
            'started'    => time() - 53_280, // 14.8 h, no idle credit
            'size_total' => 13_000_000_000,  // dynamic estimate ~2.5 h → floored to 12 h
        ];
        $hours = $this->callPrivate( $this->plugin, 'queue_timed_out', [ $queue ] );
        $this->assertSame( 14.8, $hours );
    }

    /**
     * The 2026-07-17 production scenario byte-for-byte: 14.8 h wall clock,
     * of which 14.3 h was a dead worker. With the crash idle banked as
     * credit, active time is ~30 min — far inside the 12 h budget, so the
     * resumed queue must be allowed to finish its remaining 38 files.
     */
    public function test_idle_credit_from_crash_prevents_false_timeout(): void {
        $queue = [
            'started'     => time() - 53_280, // queue created 14.8 h ago
            'idle_credit' => 51_480,          // 14.3 h silent worker death
            'size_total'  => 13_000_000_000,
        ];
        $this->assertFalse(
            $this->callPrivate( $this->plugin, 'queue_timed_out', [ $queue ] ),
            'dead time must not burn the timeout budget (BUG-03)'
        );
    }

    /**
     * The credit must not disable the safety net: a queue whose *active*
     * time exceeds the budget still times out, idle credit or not.
     */
    public function test_timeout_still_fires_when_active_time_exceeds_budget(): void {
        $queue = [
            'started'     => time() - 93_600, // 26 h wall clock
            'idle_credit' => 43_200,          // 12 h idle → 14 h active > 12 h budget
            'size_total'  => 0,
        ];
        $hours = $this->callPrivate( $this->plugin, 'queue_timed_out', [ $queue ] );
        $this->assertSame( 14.0, $hours );
    }
}
