<?php
/**
 * Entry point for one test suite. Run through WP-CLI so WordPress is loaded:
 *   studio wp eval-file <repo>/tests/bootstrap.php <suite> [key=value ...]
 * Normally invoked by run.ps1, which runs every suite in its own process
 * (AJAX handlers end the process via wp_die(), see feedback notes).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit( "Run via wp eval-file.\n" );
}

$et_args  = isset( $args ) ? (array) $args : array();
$et_suite = array_shift( $et_args );
$et_opts  = array();
foreach ( $et_args as $a ) {
    if ( false !== strpos( $a, '=' ) ) {
        list( $k, $v ) = explode( '=', $a, 2 );
        $et_opts[ $k ] = $v;
    }
}

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/fixtures.php';
require_once __DIR__ . '/lib/adapter.php';
require_once __DIR__ . '/lib/http.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

$et_file = __DIR__ . '/suites/' . basename( (string) $et_suite ) . '.php';
if ( ! $et_suite || ! file_exists( $et_file ) ) {
    echo "FAIL|bootstrap|-|unknown suite|" . $et_suite . "\n";
    return;
}

ET::$suite = basename( $et_suite );
// "admin" on the local sites; staging sites have another administrator login.
$et_admin = get_user_by( 'login', 'admin' ) ?: current( get_users( array( 'role' => 'administrator', 'number' => 1 ) ) );
wp_set_current_user( $et_admin ? (int) $et_admin->ID : 0 );

$et_snapshot = et_snapshot_state();
register_shutdown_function( function () use ( $et_snapshot ) {
    // Fixtures first: deleting attachments fires plugin hooks that touch
    // options, which the state restore below then resets.
    if ( empty( $GLOBALS['et_keep_fixtures'] ) ) {
        et_cleanup_fixtures();
    }
    et_restore_state( $et_snapshot );
    echo 'DONE|' . ET::$suite . '|' . ET::$counts['PASS'] . '|' . ET::$counts['FAIL'] . '|' . ET::$counts['SKIP'] . '|' . (int) ET::$finished . "\n";
} );

$GLOBALS['et_keep_fixtures'] = ! empty( $et_opts['keep'] );
et_cleanup_fixtures();

require $et_file;
ET::$finished = true;
