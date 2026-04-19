<?php
/**
 * Tests for the activity-log retention prune logic. Verifies that entries
 * older than the configured window are dropped, that disabled retention
 * passes everything through, and that lines without a recognizable
 * timestamp prefix are preserved (never lose data on format surprise).
 *
 * Nach ARCH-001 Schritt 1 leben die Retention-Methoden in SBU_Activity_Log;
 * die Tests injizieren den Settings-Provider direkt, damit sie ohne volle
 * Plugin-Instanzierung laufen (schneller, isoliert).
 */

declare( strict_types = 1 );

namespace SBU\Tests\Unit;

use Brain\Monkey\Functions;
use DateTime;
use DateTimeZone;
use SBU_Activity_Log;
use SBU\Tests\Helpers\PluginLoader;
use SBU\Tests\Helpers\TestCase;

/**
 * @covers \SBU_Activity_Log::prune_lines
 * @covers \SBU_Activity_Log::get_retention_days
 */
final class ActivityLogRetentionTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        PluginLoader::load();
        Functions\when( 'wp_timezone' )->alias( static function () {
            return new DateTimeZone( 'UTC' );
        } );
    }

    private function makeLogger( array $settings = array() ): SBU_Activity_Log {
        return new SBU_Activity_Log( static function () use ( $settings ) {
            return $settings;
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

        $kept = $this->makeLogger()->prune_lines( $lines, 30 );

        $this->assertCount( 1, $kept );
        $this->assertStringContainsString( 'frisch', $kept[0] );
    }

    public function test_prune_with_zero_days_returns_input_unchanged(): void {
        $lines = array( '[01.01.2020 00:00:00] UPLOAD: uralt', '[01.01.2020 00:00:01] UPLOAD: genauso uralt' );

        $kept = $this->makeLogger()->prune_lines( $lines, 0 );

        $this->assertSame( $lines, $kept );
    }

    public function test_prune_preserves_lines_without_timestamp_prefix(): void {
        $lines = array( '', 'Freitext-Zeile ohne Prefix', '[nicht-parsebar] UPLOAD: Sonderfall' );

        $kept = $this->makeLogger()->prune_lines( $lines, 30 );

        $this->assertSame( $lines, $kept, 'Unrecognized lines must pass through — never lose data on format surprise.' );
    }

    public function test_get_retention_days_clamps_panic_inputs_to_seven(): void {
        $logger = $this->makeLogger( array( 'activity_log_retention_days' => 3 ) );

        $this->assertSame( 7, $logger->get_retention_days() );
    }

    public function test_get_retention_days_honors_zero_as_disabled(): void {
        $logger = $this->makeLogger( array( 'activity_log_retention_days' => 0 ) );

        $this->assertSame( 0, $logger->get_retention_days() );
    }
}
