<?php
/**
 * Tests for SBU_Queue_Engine lock ownership (OPS-01).
 *
 * The queue lock now carries a per-acquire ownership token. release_lock()
 * only deletes a lock still carrying the caller's token, so a shutdown
 * handler that fires after its tick timed out cannot free a lock a
 * concurrent tick has since taken over. force_release_lock() bypasses the
 * check for the one deliberate case (a new backup aborting a running queue).
 */

declare( strict_types = 1 );

namespace SBU\Tests\Unit;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversMethod;
use SBU\Tests\Helpers\PluginLoader;
use SBU\Tests\Helpers\TestCase;
use SBU_Queue_Engine;

#[CoversMethod(SBU_Queue_Engine::class, 'acquire_lock')]
#[CoversMethod(SBU_Queue_Engine::class, 'release_lock')]
#[CoversMethod(SBU_Queue_Engine::class, 'force_release_lock')]
final class LockOwnershipTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		PluginLoader::load();
		// Distinct token per acquire so two engines can be told apart.
		$i = 0;
		Functions\when( 'wp_generate_password' )->alias(
			function () use ( &$i ) {
				return 'tok-' . ( ++$i );
			}
		);
	}

	private function makeEngine(): SBU_Queue_Engine {
		return new SBU_Queue_Engine(
			static function () {
				return 'test-key';
			},
			static function () {
				return array( 'tick_time' => 25 );
			}
		);
	}

	public function test_acquire_stores_expiry_and_token(): void {
		$engine = $this->makeEngine();
		$this->assertTrue( $engine->acquire_lock( 100 ) );

		$lock = $this->options[ SBU_Queue_Engine::LOCK_OPTION ];
		$this->assertIsArray( $lock );
		$this->assertArrayHasKey( 'exp', $lock );
		$this->assertSame( 'tok-1', $lock['tok'] );
		$this->assertGreaterThan( time(), $lock['exp'] );
	}

	public function test_acquire_rejects_a_live_lock(): void {
		$this->makeEngine()->acquire_lock( 100 );
		// A second, independent tick must not get in while the lock is live.
		$this->assertFalse( $this->makeEngine()->acquire_lock( 100 ) );
	}

	public function test_acquire_takes_over_a_stale_lock(): void {
		$a = $this->makeEngine();
		$a->acquire_lock( 100 );
		// Make A's lock stale.
		$this->options[ SBU_Queue_Engine::LOCK_OPTION ]['exp'] = time() - 1;

		$b = $this->makeEngine();
		$this->assertTrue( $b->acquire_lock( 100 ), 'stale lock must be takeable' );
		$this->assertSame( 'tok-2', $this->options[ SBU_Queue_Engine::LOCK_OPTION ]['tok'] );
	}

	/** Legacy mid-upgrade lock was a bare integer expiry — must still be readable. */
	public function test_acquire_takes_over_a_legacy_integer_lock(): void {
		$this->options[ SBU_Queue_Engine::LOCK_OPTION ] = time() - 1; // stale legacy int
		$this->assertTrue( $this->makeEngine()->acquire_lock( 100 ) );
		$this->assertIsArray( $this->options[ SBU_Queue_Engine::LOCK_OPTION ] );
	}

	public function test_release_deletes_own_lock(): void {
		$engine = $this->makeEngine();
		$engine->acquire_lock( 100 );
		$engine->release_lock();
		$this->assertArrayNotHasKey( SBU_Queue_Engine::LOCK_OPTION, $this->options );
	}

	/**
	 * The core OPS-01 guard: a shutdown handler on a timed-out tick (A) must
	 * NOT delete the lock a concurrent tick (B) has taken over in the meantime.
	 */
	public function test_release_does_not_delete_a_lock_taken_over_by_another_tick(): void {
		$a = $this->makeEngine();
		$a->acquire_lock( 100 );            // A holds tok-1
		// A's tick stalls; its lock goes stale and B takes over.
		$this->options[ SBU_Queue_Engine::LOCK_OPTION ]['exp'] = time() - 1;
		$b = $this->makeEngine();
		$b->acquire_lock( 100 );            // B holds tok-2

		// Now A's shutdown handler fires late and tries to release.
		$a->release_lock();

		$this->assertArrayHasKey( SBU_Queue_Engine::LOCK_OPTION, $this->options, 'B\'s lock must survive A\'s late release' );
		$this->assertSame( 'tok-2', $this->options[ SBU_Queue_Engine::LOCK_OPTION ]['tok'] );
	}

	/** A caller that never acquired must not delete anyone's lock. */
	public function test_release_without_acquire_is_a_noop_on_the_lock(): void {
		$this->makeEngine()->acquire_lock( 100 ); // some other tick holds it
		$other = $this->makeEngine();             // never acquired
		$other->release_lock();
		$this->assertArrayHasKey( SBU_Queue_Engine::LOCK_OPTION, $this->options );
	}

	/** force_release_lock() is the deliberate escape hatch — drops any lock. */
	public function test_force_release_deletes_any_lock(): void {
		$this->makeEngine()->acquire_lock( 100 ); // held by another tick
		$this->makeEngine()->force_release_lock();
		$this->assertArrayNotHasKey( SBU_Queue_Engine::LOCK_OPTION, $this->options );
	}
}
