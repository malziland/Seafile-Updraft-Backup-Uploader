<?php
/**
 * PHPUnit bootstrap for seafile-updraft-backup-uploader.
 *
 * Loads the Composer autoloader, initializes Brain\Monkey for WordPress
 * function mocking, and defines the plugin's constants so that the plugin
 * source can be loaded without WordPress being installed.
 */

declare( strict_types = 1 );

define( 'SBU_TESTS_ROOT', __DIR__ );
define( 'SBU_PLUGIN_ROOT', dirname( __DIR__ ) );

require SBU_PLUGIN_ROOT . '/vendor/autoload.php';

// WordPress constants that the plugin's top-level guard expects.
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', '/tmp/sbu-tests-wordpress/' );
}

// WordPress core time constants used by the queue engine.
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) )   { define( 'HOUR_IN_SECONDS',   60 * MINUTE_IN_SECONDS ); }
if ( ! defined( 'DAY_IN_SECONDS' ) )    { define( 'DAY_IN_SECONDS',    24 * HOUR_IN_SECONDS ); }
if ( ! defined( 'WEEK_IN_SECONDS' ) )   { define( 'WEEK_IN_SECONDS',    7 * DAY_IN_SECONDS ); }
if ( ! defined( 'YEAR_IN_SECONDS' ) )   { define( 'YEAR_IN_SECONDS',  365 * DAY_IN_SECONDS ); }

// Minimal WP_Error stand-in: the plugin only uses get_error_message() and
// relies on is_wp_error() to detect instances.
if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        private string $code;
        private string $message;
        public function __construct( string $code = '', string $message = '' ) {
            $this->code    = $code;
            $this->message = $message;
        }
        public function get_error_code(): string    { return $this->code; }
        public function get_error_message(): string { return $this->message; }
    }
}

// Plugin constants (SBU_VER, SBU_OPT, ...) are defined by the plugin bootstrap
// file itself when PluginLoader::load() runs. Tests must call load() before
// referencing any SBU_* constant.

require_once SBU_TESTS_ROOT . '/helpers/JsonResponse.php';
require_once SBU_TESTS_ROOT . '/helpers/TestCase.php';
require_once SBU_TESTS_ROOT . '/helpers/PluginLoader.php';
