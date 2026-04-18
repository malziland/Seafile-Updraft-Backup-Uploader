<?php
/**
 * Tests for the per-site cron-ping key: generation, persistence, and format.
 */

declare( strict_types = 1 );

namespace SBU\Tests\Unit;

use Brain\Monkey\Functions;
use SBU\Tests\Helpers\PluginLoader;
use SBU\Tests\Helpers\TestCase;
use SBU_Plugin;

/**
 * @covers \SBU_Plugin::get_cron_key
 */
final class CronKeyTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        PluginLoader::load();
        Functions\when( 'wp_generate_password' )->alias( static function ( $length, $special ) {
            // Deterministic but length-correct replacement for wp_generate_password.
            return str_repeat( 'x', (int) $length );
        } );
    }

    public function test_cron_key_is_generated_and_persisted_on_first_access(): void {
        $plugin = new SBU_Plugin();

        $ref = new \ReflectionMethod( SBU_Plugin::class, 'get_cron_key' );
        if ( PHP_VERSION_ID < 80100 ) {
            $ref->setAccessible( true );
        }
        $key = $ref->invoke( $plugin );

        $this->assertIsString( $key );
        $this->assertSame( 32, strlen( $key ), 'Cron key must be 32 characters (matches wp_generate_password length).' );
        $this->assertSame( $key, $this->options['sbu_cron_key'] ?? null, 'Generated key must be persisted.' );
    }

    public function test_existing_cron_key_is_reused(): void {
        $this->options['sbu_cron_key'] = 'preexisting-key-value';

        $plugin = new SBU_Plugin();
        $ref = new \ReflectionMethod( SBU_Plugin::class, 'get_cron_key' );
        if ( PHP_VERSION_ID < 80100 ) {
            $ref->setAccessible( true );
        }

        $this->assertSame( 'preexisting-key-value', $ref->invoke( $plugin ) );
    }
}
