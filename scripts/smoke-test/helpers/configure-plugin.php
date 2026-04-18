<?php
/**
 * Runs inside the WordPress container via `wp eval-file`. Writes plugin
 * settings directly into wp_options, encrypting the password with
 * SBU_Crypto so the plugin reads it exactly like it would in production.
 *
 * Required env: SBU_URL, SBU_USER, SBU_PASS, SBU_LIB, SBU_FOLDER.
 */

$required = [ 'SBU_URL', 'SBU_USER', 'SBU_PASS', 'SBU_LIB', 'SBU_FOLDER' ];
foreach ( $required as $k ) {
    if ( getenv( $k ) === false || getenv( $k ) === '' ) {
        fwrite( STDERR, "missing env: $k\n" );
        exit( 1 );
    }
}

if ( ! class_exists( 'SBU_Crypto' ) ) {
    fwrite( STDERR, "SBU_Crypto not loaded — is the plugin active?\n" );
    exit( 1 );
}

$opts = [
    'url'       => rtrim( (string) getenv( 'SBU_URL' ), '/' ),
    'user'      => (string) getenv( 'SBU_USER' ),
    'pass'      => SBU_Crypto::encrypt( (string) getenv( 'SBU_PASS' ) ),
    'lib'       => (string) getenv( 'SBU_LIB' ),
    'folder'    => '/' . trim( (string) getenv( 'SBU_FOLDER' ), '/ ' ),
    'chunk'     => 40,
    'retention' => 4,
    'email'     => 'admin@example.com',
    'notify'    => 'error',
];

update_option( SBU_OPT, $opts, false );

echo "plugin configured: url={$opts['url']} user={$opts['user']} lib={$opts['lib']} folder={$opts['folder']}\n";
