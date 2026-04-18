<?php
/**
 * Run inside the WP container. Queues the fixture backup, runs the tick
 * loop synchronously until the queue is empty or a timeout/abort hits,
 * then prints the final queue state for run.sh to assert on.
 *
 * Env: SBU_MAX_TICKS (default 30), SBU_TICK_SLEEP_MS (default 500).
 */

$max_ticks = (int) ( getenv( 'SBU_MAX_TICKS' ) ?: 30 );
$sleep_ms  = (int) ( getenv( 'SBU_TICK_SLEEP_MS' ) ?: 500 );

if ( ! class_exists( 'SBU_Plugin' ) ) {
    fwrite( STDERR, "SBU_Plugin not loaded\n" );
    exit( 1 );
}

$plugin = null;
foreach ( $GLOBALS as $val ) {
    if ( $val instanceof SBU_Plugin ) { $plugin = $val; break; }
}
if ( $plugin === null ) {
    $plugin = new SBU_Plugin();
}

// Seed the queue via the same private method the AJAX endpoint uses.
$ref = new ReflectionMethod( $plugin, 'create_upload_queue' );
$ref->setAccessible( true );
$seed = $ref->invoke( $plugin );
if ( is_wp_error( $seed ) ) {
    fwrite( STDERR, "create_upload_queue failed: " . $seed->get_error_message() . "\n" );
    exit( 2 );
}
echo "queue seeded: " . ( is_array( $seed ) ? 'array' : (string) $seed ) . "\n";

echo "trigger: tick loop up to $max_ticks iterations\n";

for ( $i = 1; $i <= $max_ticks; $i++ ) {
    do_action( SBU_CRON_HOOK );

    $queue = get_option( SBU_QUEUE );
    if ( ! is_array( $queue ) ) {
        echo "tick $i: queue empty — done\n";
        break;
    }

    $status = $queue['status'] ?? '?';
    $file   = $queue['current_file'] ?? '?';
    $off    = (int) ( $queue['offset'] ?? 0 );

    echo sprintf( "tick %d: status=%s file=%s offset=%d\n", $i, $status, $file, $off );

    if ( in_array( $status, [ 'done', 'error', 'aborted' ], true ) ) {
        echo "tick $i: terminal status reached\n";
        break;
    }

    if ( $sleep_ms > 0 ) { usleep( $sleep_ms * 1000 ); }
}

$final = get_option( SBU_QUEUE );
if ( is_array( $final ) ) {
    echo "FINAL_QUEUE_STATUS=" . ( $final['status'] ?? '?' ) . "\n";
} else {
    echo "FINAL_QUEUE_STATUS=empty\n";
}

$verified = get_option( 'sbu_verified', [] );
echo "VERIFIED_COUNT=" . ( is_array( $verified ) ? count( $verified ) : 0 ) . "\n";
