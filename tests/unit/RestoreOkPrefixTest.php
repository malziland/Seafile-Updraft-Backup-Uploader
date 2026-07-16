<?php
/**
 * Tests for SBU_Restore_Flow::ok_prefix() — the committable-prefix detector
 * for parallel range-download batches.
 *
 * BUG-01 regression: a server that ignores the Range header and answers a
 * resumed request (offset > 0) with HTTP 200 (the whole file from byte 0)
 * must NOT be treated as a whole-file result. Committing it would append a
 * full copy behind the bytes already on disk and corrupt the restore.
 */

declare( strict_types = 1 );

namespace SBU\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversMethod;
use SBU\Tests\Helpers\PluginLoader;
use SBU\Tests\Helpers\TestCase;
use SBU_Plugin;

#[CoversMethod(SBU_Plugin::class, 'ok_prefix')]
final class RestoreOkPrefixTest extends TestCase {

	private SBU_Plugin $plugin;

	protected function setUp(): void {
		parent::setUp();
		PluginLoader::load();
		$this->plugin = new SBU_Plugin();
	}

	private function prefix( array $results, int $offset ): array {
		return $this->callPrivate( $this->plugin, 'ok_prefix', [ $results, $offset ] );
	}

	/** At offset 0 a 200 means "here is the whole file" — valid, whole_file. */
	public function test_whole_file_200_at_offset_zero_is_accepted(): void {
		$out = $this->prefix( [ [ 'ok' => true, 'code' => 200, 'bytes' => 5000, 'end' => 4999 ] ], 0 );
		$this->assertSame( 1, $out['count'] );
		$this->assertSame( 5000, $out['bytes'] );
		$this->assertTrue( $out['whole_file'] );
	}

	/**
	 * The core BUG-01 guard: a 200 on a resumed batch (offset > 0) must be
	 * rejected — count 0, not a whole-file — so the batch retries instead of
	 * appending a duplicate copy.
	 */
	public function test_whole_file_200_at_nonzero_offset_is_rejected(): void {
		$out = $this->prefix( [ [ 'ok' => true, 'code' => 200, 'bytes' => 5000, 'end' => 4999 ] ], 4_000_000 );
		$this->assertSame( 0, $out['count'], 'mid-file 200 must not be committed' );
		$this->assertFalse( $out['whole_file'] );
	}

	/** Normal 206 range responses accumulate into a contiguous prefix. */
	public function test_contiguous_206_prefix(): void {
		$out = $this->prefix( [
			[ 'ok' => true, 'code' => 206, 'bytes' => 1000, 'end' => 4_000_999 ],
			[ 'ok' => true, 'code' => 206, 'bytes' => 1000, 'end' => 4_001_999 ],
		], 4_000_000 );
		$this->assertSame( 2, $out['count'] );
		$this->assertSame( 2000, $out['bytes'] );
		$this->assertFalse( $out['whole_file'] );
	}

	/** A failed chunk breaks the prefix; later oks are not counted. */
	public function test_failure_breaks_prefix(): void {
		$out = $this->prefix( [
			[ 'ok' => true,  'code' => 206, 'bytes' => 1000, 'end' => 4_000_999 ],
			[ 'ok' => false, 'code' => 0 ],
			[ 'ok' => true,  'code' => 206, 'bytes' => 1000, 'end' => 4_002_999 ],
		], 4_000_000 );
		$this->assertSame( 1, $out['count'] );
		$this->assertSame( 1000, $out['bytes'] );
	}

	/** A mid-file 200 after some good 206 chunks banks the 206 prefix only. */
	public function test_206_prefix_then_mid_file_200_banks_only_the_206s(): void {
		$out = $this->prefix( [
			[ 'ok' => true, 'code' => 206, 'bytes' => 1000, 'end' => 4_000_999 ],
			[ 'ok' => true, 'code' => 200, 'bytes' => 9_999_999, 'end' => 9_999_998 ],
		], 4_000_000 );
		$this->assertSame( 1, $out['count'], 'only the clean 206 chunk is committable' );
		$this->assertFalse( $out['whole_file'] );
	}
}
