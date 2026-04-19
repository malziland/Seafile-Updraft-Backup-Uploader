<?php
/**
 * Tests for SBU_Plugin::safe_queue_update(): mid-tick writes must never
 * overwrite a terminal status (aborted, paused, error, done) written by a
 * concurrent request.
 *
 * This is the single most important correctness invariant of the queue
 * engine. Regressing it silently re-introduces the 1.2.0 "abort doesn't
 * actually stop the tick" bug.
 */

declare( strict_types = 1 );

namespace SBU\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversMethod;
use SBU\Tests\Helpers\PluginLoader;
use SBU\Tests\Helpers\TestCase;
use SBU_Plugin;

#[CoversMethod(SBU_Plugin::class, 'safe_queue_update')]
final class SafeQueueUpdateTest extends TestCase {

    private SBU_Plugin $plugin;

    protected function setUp(): void {
        parent::setUp();
        PluginLoader::load();
        $this->plugin = new SBU_Plugin();
    }

    /**
     * Baseline: when the DB holds a non-terminal status, the caller's
     * in-memory queue is written through unchanged.
     */
    public function test_writes_through_when_db_status_is_non_terminal(): void {
        $this->options[ SBU_QUEUE ] = [
            'status' => 'uploading',
            'files'  => [ [ 'path' => '/a', 'status' => 'uploading', 'offset' => 0 ] ],
            'file_idx' => 0,
            'ok' => 0, 'err' => 0, 'total_bytes' => 0,
        ];
        $in_memory = [
            'status' => 'uploading',
            'files'  => [ [ 'path' => '/a', 'status' => 'uploading', 'offset' => 1_000_000 ] ],
            'file_idx' => 0,
            'ok' => 0, 'err' => 0, 'total_bytes' => 500_000,
        ];
        $ret = $this->callPrivate( $this->plugin, 'safe_queue_update', [ $in_memory ] );
        $this->assertSame( 'uploading', $ret );
        $this->assertSame( 1_000_000, $this->options[ SBU_QUEUE ]['files'][0]['offset'] );
        $this->assertSame( 500_000, $this->options[ SBU_QUEUE ]['total_bytes'] );
    }

    /**
     * The core guard: DB says 'aborted' (user just pressed Abbrechen), our
     * in-memory copy still says 'uploading'. Status in the DB must remain
     * 'aborted' and the caller must be told so it can bail out.
     */
    public function test_preserves_aborted_status_from_db(): void {
        $this->options[ SBU_QUEUE ] = [
            'status' => 'aborted',
            'files'  => [ [ 'path' => '/a', 'status' => 'uploading', 'offset' => 0 ] ],
            'file_idx' => 0,
            'ok' => 0, 'err' => 0, 'total_bytes' => 0,
        ];
        $in_memory = [
            'status' => 'uploading', // stale
            'files'  => [ [ 'path' => '/a', 'status' => 'uploading', 'offset' => 2_000_000 ] ],
            'file_idx' => 0,
            'ok' => 1, 'err' => 0, 'total_bytes' => 2_000_000,
        ];
        $ret = $this->callPrivate( $this->plugin, 'safe_queue_update', [ $in_memory ] );
        $this->assertSame( 'aborted', $ret, 'caller must learn the true status' );
        $this->assertSame( 'aborted', $this->options[ SBU_QUEUE ]['status'] );
        // Progress still merged: the tick made real progress before the abort hit.
        $this->assertSame( 2_000_000, $this->options[ SBU_QUEUE ]['files'][0]['offset'] );
        $this->assertSame( 2_000_000, $this->options[ SBU_QUEUE ]['total_bytes'] );
    }

    /**
     * Same guard, paused status (added in 1.3.0). Needed so Pause can
     * reliably land while a chunk upload is mid-flight.
     */
    public function test_preserves_paused_status_from_db(): void {
        $this->options[ SBU_QUEUE ] = [
            'status' => 'paused',
            'files'  => [ [ 'path' => '/a', 'status' => 'uploading', 'offset' => 0 ] ],
            'file_idx' => 0,
            'ok' => 0, 'err' => 0, 'total_bytes' => 0,
            'next_allowed_tick_ts' => time() + 1000,
        ];
        $in_memory = [
            'status' => 'uploading',
            'files'  => [ [ 'path' => '/a', 'status' => 'uploading', 'offset' => 3_000_000 ] ],
            'file_idx' => 0,
            'ok' => 0, 'err' => 0, 'total_bytes' => 3_000_000,
        ];
        $ret = $this->callPrivate( $this->plugin, 'safe_queue_update', [ $in_memory ] );
        $this->assertSame( 'paused', $ret );
        $this->assertSame( 'paused', $this->options[ SBU_QUEUE ]['status'] );
        $this->assertSame( 3_000_000, $this->options[ SBU_QUEUE ]['files'][0]['offset'] );
    }

    public function test_preserves_error_and_done(): void {
        foreach ( [ 'error', 'done' ] as $terminal ) {
            $this->options[ SBU_QUEUE ] = [
                'status' => $terminal,
                'files'  => [],
                'file_idx' => 0,
                'ok' => 0, 'err' => 0, 'total_bytes' => 0,
            ];
            $ret = $this->callPrivate( $this->plugin, 'safe_queue_update', [ [
                'status' => 'uploading',
                'files'  => [],
                'file_idx' => 0,
                'ok' => 0, 'err' => 0, 'total_bytes' => 0,
            ] ] );
            $this->assertSame( $terminal, $ret );
            $this->assertSame( $terminal, $this->options[ SBU_QUEUE ]['status'] );
        }
    }

    /**
     * When there is no DB row at all (queue was wiped or uninstall race),
     * the in-memory queue is written through. The caller gets back its own
     * status string.
     */
    public function test_writes_through_when_db_is_empty(): void {
        $this->assertArrayNotHasKey( SBU_QUEUE, $this->options );
        $in_memory = [
            'status' => 'uploading',
            'files'  => [],
            'file_idx' => 0,
            'ok' => 0, 'err' => 0, 'total_bytes' => 0,
        ];
        $ret = $this->callPrivate( $this->plugin, 'safe_queue_update', [ $in_memory ] );
        $this->assertSame( 'uploading', $ret );
        $this->assertSame( 'uploading', $this->options[ SBU_QUEUE ]['status'] );
    }
}
