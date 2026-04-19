<?php
/**
 * Tests for the activity-log retention prune logic. Verifies that entries
 * older than the configured window are dropped, that disabled retention
 * passes everything through, and that lines without a recognizable
 * timestamp prefix are preserved (never lose data on format surprise).
 */

declare( strict_types = 1 );

namespace SBU\Tests\Unit;

use Brain\Monkey\Functions;
use DateTime;
use DateTimeZone;
use SBU\Tests\Helpers\PluginLoader;
use SBU\Tests\Helpers\TestCase;
use SBU_Plugin;

/**
 * @covers \SBU_Plugin::prune_activity_log_lines
 * @covers \SBU_Plugin::get_activity_retention_days
 */
final class ActivityLogRetentionTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        PluginLoader::load();
        Functions\when( 'wp_timezone' )->alias( static function () {
            return new DateTimeZone( 'UTC' );
        } );
    }

    public function test_prune_drops_entries_older_than_retention_window(): void {
        $now     = time();
        $old_ts  = $now - ( 45 * DAY_IN_SECONDS );
        $new_ts  = $now - ( 2 * DAY_IN_SECONDS );
        $old_str = ( new DateTime( '@' . $old_ts ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'd.m.Y H:i:s' );
        $new_str = ( new DateTime( '@' . $new_ts ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'd.m.Y H:i:s' );

        $lines = array(
            '[' . $new_str . '] UPLOAD: frisch, bleibt',
            '[' . $old_str . '] UPLOAD: alt, fällt raus',
        );

        $plugin = new SBU_Plugin();
        $kept   = $this->callPrivate( $plugin, 'prune_activity_log_lines', array( $lines, 30 ) );

        $this->assertCount( 1, $kept );
        $this->assertStringContainsString( 'frisch', $kept[0] );
    }

    public function test_prune_with_zero_days_returns_input_unchanged(): void {
        $plugin = new SBU_Plugin();
        $lines  = array( '[01.01.2020 00:00:00] UPLOAD: uralt', '[01.01.2020 00:00:01] UPLOAD: genauso uralt' );

        $kept = $this->callPrivate( $plugin, 'prune_activity_log_lines', array( $lines, 0 ) );

        $this->assertSame( $lines, $kept );
    }

    public function test_prune_preserves_lines_without_timestamp_prefix(): void {
        $plugin = new SBU_Plugin();
        $lines  = array( '', 'Freitext-Zeile ohne Prefix', '[nicht-parsebar] UPLOAD: Sonderfall' );

        $kept = $this->callPrivate( $plugin, 'prune_activity_log_lines', array( $lines, 30 ) );

        $this->assertSame( $lines, $kept, 'Unrecognized lines must pass through — never lose data on format surprise.' );
    }

    public function test_get_activity_retention_days_clamps_panic_inputs_to_seven(): void {
        $this->options[ SBU_OPT ] = array( 'activity_log_retention_days' => 3 );
        $plugin                   = new SBU_Plugin();

        $this->assertSame( 7, $this->callPrivate( $plugin, 'get_activity_retention_days' ) );
    }

    public function test_get_activity_retention_days_honors_zero_as_disabled(): void {
        $this->options[ SBU_OPT ] = array( 'activity_log_retention_days' => 0 );
        $plugin                   = new SBU_Plugin();

        $this->assertSame( 0, $this->callPrivate( $plugin, 'get_activity_retention_days' ) );
    }
}
