<?php
/**
 * Loader helper that stubs just enough WordPress functions to `require` the
 * main plugin file inside a PHPUnit process.
 *
 * The plugin currently instantiates itself at the bottom of the bootstrap file
 * (`new SBU_Plugin()`), which registers hooks. We stub those registrations so
 * the constructor becomes a no-op side-effect-free enough to inspect via
 * reflection.
 */

declare( strict_types = 1 );

namespace SBU\Tests\Helpers;

use Brain\Monkey\Functions;

final class PluginLoader {

    private static bool $loaded = false;

    /**
     * Ensure the main plugin bootstrap file is loaded exactly once per process.
     * Safe to call from every test; subsequent calls are no-ops.
     */
    public static function load(): void {
        if ( self::$loaded ) {
            return;
        }
        self::stubConstructorDependencies();
        require_once SBU_PLUGIN_ROOT . '/seafile-updraft-backup-uploader.php';
        self::$loaded = true;
    }

    /**
     * Stub the WP primitives that run during plugin construction / autoload.
     * These return harmless values; individual tests override them as needed.
     */
    private static function stubConstructorDependencies(): void {
        Functions\when( 'add_action' )->justReturn( true );
        Functions\when( 'add_filter' )->justReturn( true );
        Functions\when( 'plugin_basename' )->alias( static function ( $file ) {
            return basename( (string) $file );
        } );
        Functions\when( 'register_setting' )->justReturn( true );
        Functions\when( 'load_plugin_textdomain' )->justReturn( true );
        Functions\when( 'register_activation_hook' )->justReturn( true );
        Functions\when( 'register_deactivation_hook' )->justReturn( true );
        Functions\when( 'register_uninstall_hook' )->justReturn( true );
    }
}
