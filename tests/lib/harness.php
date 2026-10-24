<?php
/**
 * Erdo test harness — minimal assertion library.
 *
 * Output protocol (one line each, parsed by run.ps1):
 *   PASS|<suite>|<phase>|<name>
 *   FAIL|<suite>|<phase>|<name>|<detail>
 *   SKIP|<suite>|<phase>|<name>|<reason>
 *   INFO|<suite>|<text>
 *   DONE|<suite>|<pass>|<fail>|<skip>|<finished 0/1>
 *
 * <phase> is the PLAN-3.0.md phase in which the behaviour is expected to be
 * delivered ("-" = must already pass). A FAIL with phase F2 on the 2.2.2
 * codebase is an expected, tracked gap — not a regression.
 */

class ET_Fail extends Exception {}
class ET_Skip extends Exception {}

final class ET {
    public static $suite    = '';
    public static $counts   = array( 'PASS' => 0, 'FAIL' => 0, 'SKIP' => 0 );
    public static $finished = false;

    public static function out( $type, $phase, $name, $detail = '' ) {
        self::$counts[ $type ]++;
        $detail = str_replace( array( "\r", "\n", '|' ), array( ' ', ' ', '/' ), (string) $detail );
        echo $type . '|' . self::$suite . '|' . ( $phase ? $phase : '-' ) . '|' . $name . ( '' !== $detail ? '|' . $detail : '' ) . "\n";
    }

    public static function info( $text ) {
        echo 'INFO|' . self::$suite . '|' . str_replace( array( "\r", "\n" ), ' ', (string) $text ) . "\n";
    }
}

/**
 * Runs one test. The callable passes by returning normally and fails by
 * throwing (et_assert & friends) — any other Throwable is reported as a FAIL
 * too, so one broken test never stops the rest of the suite.
 */
function et_test( $name, callable $fn, $phase = null ) {
    try {
        $fn();
        ET::out( 'PASS', $phase, $name );
    } catch ( ET_Skip $e ) {
        ET::out( 'SKIP', $phase, $name, $e->getMessage() );
    } catch ( ET_Fail $e ) {
        ET::out( 'FAIL', $phase, $name, $e->getMessage() );
    } catch ( Throwable $e ) {
        ET::out( 'FAIL', $phase, $name, get_class( $e ) . ': ' . $e->getMessage() . ' @' . basename( $e->getFile() ) . ':' . $e->getLine() );
    }
}

function et_assert( $cond, $msg = 'assertion failed' ) {
    if ( ! $cond ) {
        throw new ET_Fail( $msg );
    }
}

function et_eq( $expected, $actual, $msg = '' ) {
    if ( $expected !== $actual ) {
        throw new ET_Fail( ( $msg ? $msg . ' - ' : '' ) . 'expected ' . et_export( $expected ) . ', got ' . et_export( $actual ) );
    }
}

function et_not_error( $value, $msg = '' ) {
    if ( is_wp_error( $value ) ) {
        throw new ET_Fail( ( $msg ? $msg . ' - ' : '' ) . 'WP_Error: ' . $value->get_error_message() );
    }
    return $value;
}

function et_skip( $reason ) {
    throw new ET_Skip( $reason );
}

function et_export( $v ) {
    $s = is_scalar( $v ) || null === $v ? var_export( $v, true ) : wp_json_encode( $v );
    return strlen( $s ) > 300 ? substr( $s, 0, 300 ) . '…' : $s;
}

/* ------------------------------------------------------------------
   STATE ISOLATION
   Every suite snapshots plugin options and the site-level settings that
   fixtures touch, then restores them on shutdown — shutdown also runs when an
   AJAX handler ends the process via wp_die(), so state never leaks.
------------------------------------------------------------------ */

function et_state_keys() {
    return array( 'theme_mods_' . get_stylesheet(), 'site_icon', 'site_logo', 'sidebars_widgets', 'widget_media_image', 'widget_block' );
}

function et_snapshot_state() {
    global $wpdb;
    $snap = array( 'erdo' => array(), 'site' => array() );
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    foreach ( $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'erdo\\_%'" ) as $name ) {
        $snap['erdo'][ $name ] = get_option( $name );
    }
    foreach ( et_state_keys() as $key ) {
        $snap['site'][ $key ] = get_option( $key, '__absent__' );
    }
    return $snap;
}

function et_restore_state( $snap ) {
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    foreach ( $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'erdo\\_%'" ) as $name ) {
        if ( ! array_key_exists( $name, $snap['erdo'] ) ) {
            delete_option( $name );
        }
    }
    foreach ( $snap['erdo'] as $name => $value ) {
        update_option( $name, $value );
    }
    foreach ( $snap['site'] as $key => $value ) {
        if ( '__absent__' === $value ) {
            delete_option( $key );
        } else {
            update_option( $key, $value );
        }
    }
}

/** Sets plugin options for the duration of the suite (restored on shutdown). */
function et_options( array $opts ) {
    foreach ( $opts as $k => $v ) {
        update_option( $k, $v );
    }
}
