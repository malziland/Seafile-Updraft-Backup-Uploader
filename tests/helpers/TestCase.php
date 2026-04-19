<?php
/**
 * Base TestCase that wires Brain\Monkey setup/teardown and provides helpers
 * for reaching into private methods and stubbing the WordPress option store.
 */

declare( strict_types = 1 );

namespace SBU\Tests\Helpers;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase as BaseTestCase;
use ReflectionClass;
use SBU\Tests\Helpers\JsonResponse;

abstract class TestCase extends BaseTestCase {

    /**
     * In-memory replacement for the wp_options table, populated per-test.
     *
     * @var array<string,mixed>
     */
    protected $options = [];

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        $this->options = [];
        $this->registerOptionStubs();
        $this->registerCommonStubs();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Stub get_option / update_option / delete_option / add_option against the
     * per-test in-memory array. Each call behaves like the real WP function
     * from a consumer's perspective (add_option returns false if key exists).
     */
    protected function registerOptionStubs(): void {
        Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
            return $this->options[ $key ] ?? $default;
        } );
        Functions\when( 'update_option' )->alias( function ( $key, $value ) {
            $this->options[ $key ] = $value;
            return true;
        } );
        Functions\when( 'delete_option' )->alias( function ( $key ) {
            unset( $this->options[ $key ] );
            return true;
        } );
        Functions\when( 'add_option' )->alias( function ( $key, $value ) {
            if ( array_key_exists( $key, $this->options ) ) {
                return false;
            }
            $this->options[ $key ] = $value;
            return true;
        } );
    }

    /**
     * Minimal stubs for WordPress primitives the plugin touches in pure paths.
     */
    protected function registerCommonStubs(): void {
        Functions\when( 'wp_salt' )->alias( function ( $scheme = 'auth' ) {
            return 'test-salt-' . $scheme;
        } );
        Functions\when( '__' )->returnArg( 1 );
        Functions\when( 'esc_html__' )->returnArg( 1 );
        Functions\when( 'esc_html' )->returnArg( 1 );
        Functions\when( 'sanitize_text_field' )->alias( function ( $v ) {
            return is_scalar( $v ) ? trim( (string) $v ) : '';
        } );
        Functions\when( 'wp_unslash' )->alias( function ( $v ) {
            return is_string( $v ) ? stripslashes( $v ) : $v;
        } );
        Functions\when( 'current_time' )->alias( function ( $type ) {
            return $type === 'timestamp' ? 1_700_000_000 : '2026-04-17 12:00';
        } );
        // Queue-engine ancillaries. Tests can override these per-case if
        // they need to assert scheduling behavior.
        Functions\when( 'wp_cache_delete' )->justReturn( true );
        Functions\when( 'wp_parse_args' )->alias( function ( $args, $defaults = [] ) {
            if ( is_object( $args ) ) {
                $args = get_object_vars( $args );
            }
            if ( ! is_array( $args ) ) {
                parse_str( (string) $args, $args );
            }
            return array_merge( (array) $defaults, (array) $args );
        } );
        Functions\when( 'wp_schedule_single_event' )->justReturn( true );
        Functions\when( 'wp_clear_scheduled_hook' )->justReturn( true );
        Functions\when( 'wp_next_scheduled' )->justReturn( false );
        Functions\when( 'get_transient' )->justReturn( false );
        Functions\when( 'set_transient' )->justReturn( true );
        Functions\when( 'delete_transient' )->justReturn( true );
        Functions\when( 'wp_send_json_success' )->alias( function ( $data = null ) {
            throw new JsonResponse( true, $data );
        } );
        Functions\when( 'wp_send_json_error' )->alias( function ( $data = null ) {
            throw new JsonResponse( false, $data );
        } );
        Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'admin_url' )->alias( function ( $path = '' ) {
            return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
        } );
        Functions\when( 'add_query_arg' )->alias( function ( $key, $value = null, $url = null ) {
            if ( is_array( $key ) ) {
                $base = (string) $value;
                $sep  = ( strpos( $base, '?' ) === false ) ? '?' : '&';
                return $base . $sep . http_build_query( $key );
            }
            $base = (string) $url;
            $sep  = ( strpos( $base, '?' ) === false ) ? '?' : '&';
            return $base . $sep . rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
        } );
        Functions\when( 'wp_remote_post' )->justReturn( [] );
        Functions\when( 'wp_generate_password' )->alias( function ( $len = 12 ) {
            return str_repeat( 'x', (int) $len );
        } );
        Functions\when( 'is_wp_error' )->alias( function ( $thing ) {
            return $thing instanceof \WP_Error;
        } );
        Functions\when( 'absint' )->alias( function ( $v ) {
            return abs( (int) $v );
        } );
    }

    /**
     * Call a private/protected instance method by name.
     *
     * @param object $instance Object to invoke the method on.
     * @param string $method   Method to invoke.
     * @param array  $args     Arguments to pass.
     * @return mixed Whatever the method returns.
     */
    protected function callPrivate( object $instance, string $method, array $args = [] ) {
        $ref = new ReflectionClass( $instance );
        $m   = $ref->getMethod( $method );
        if ( PHP_VERSION_ID < 80100 ) {
            $m->setAccessible( true );
        }
        return $m->invokeArgs( $instance, $args );
    }

    /**
     * Call a private/protected static method by name.
     *
     * @param string $class  Fully qualified class name.
     * @param string $method Method to invoke.
     * @param array  $args   Arguments to pass.
     * @return mixed Whatever the method returns.
     */
    protected function callPrivateStatic( string $class, string $method, array $args = [] ) {
        $ref = new ReflectionClass( $class );
        $m   = $ref->getMethod( $method );
        if ( PHP_VERSION_ID < 80100 ) {
            $m->setAccessible( true ); // deprecated in 8.5, redundant from 8.1
        }
        return $m->invokeArgs( null, $args );
    }
}
