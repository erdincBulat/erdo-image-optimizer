<?php
/**
 * Access control over real HTTP. Every registered wp_ajax_erdo_* action is
 * found automatically, so new handlers are covered without editing this file.
 * A subscriber with a VALID nonce, and an admin WITHOUT one, must both be
 * refused, and nothing may change on the site as a side effect.
 */

$m = et_fixtures();

$sub_id = et_temp_user( 'et_subscriber', 'subscriber' );
$admin_id = get_current_user_id();

/** Builds auth cookies + an erdo_nonce for a user, the way a browser session would have them. */
$session = function ( $user_id ) {
    $exp     = time() + HOUR_IN_SECONDS;
    $token   = WP_Session_Tokens::get_instance( $user_id )->create( $exp );
    $auth    = wp_generate_auth_cookie( $user_id, $exp, 'auth', $token );
    $secure  = wp_generate_auth_cookie( $user_id, $exp, 'secure_auth', $token ); // read instead of AUTH_COOKIE over HTTPS
    $logged  = wp_generate_auth_cookie( $user_id, $exp, 'logged_in', $token );
    $prev    = $_COOKIE;
    $_COOKIE[ LOGGED_IN_COOKIE ] = $logged;
    wp_set_current_user( $user_id );
    $nonce   = wp_create_nonce( 'erdo_nonce' );
    $_COOKIE = $prev;
    return array(
        'cookies' => array(
            new WP_Http_Cookie( array( 'name' => AUTH_COOKIE, 'value' => $auth ) ),
            new WP_Http_Cookie( array( 'name' => SECURE_AUTH_COOKIE, 'value' => $secure ) ),
            new WP_Http_Cookie( array( 'name' => LOGGED_IN_COOKIE, 'value' => $logged ) ),
        ),
        'nonce'   => $nonce,
    );
};

$sub   = $session( $sub_id );
$admin = $session( $admin_id );
wp_set_current_user( $admin_id );

// Only actions whose callback lives in this plugin — sibling "Erdo" plugins
// on the test site register wp_ajax_erdo_* hooks of their own.
$actions = array();
$root    = wp_normalize_path( ERDO_PATH );
foreach ( $GLOBALS['wp_filter'] as $hook => $wp_hook ) {
    if ( 0 !== strpos( $hook, 'wp_ajax_erdo_' ) ) {
        continue;
    }
    foreach ( $wp_hook->callbacks as $cbs ) {
        foreach ( $cbs as $cb ) {
            $fn  = $cb['function'];
            $ref = is_array( $fn ) ? new ReflectionMethod( $fn[0], $fn[1] ) : ( $fn instanceof Closure || is_string( $fn ) ? new ReflectionFunction( $fn ) : null );
            if ( $ref && 0 === strpos( wp_normalize_path( $ref->getFileName() ), $root ) ) {
                $actions[] = substr( $hook, 8 );
                break 2;
            }
        }
    }
}
ET::info( count( $actions ) . ' AJAX actions found' );

$victim     = $m['img']['unused'];
$htaccess   = trailingslashit( wp_upload_dir()['basedir'] ) . '.htaccess';
$ht_before  = file_exists( $htaccess ) ? file_get_contents( $htaccess ) : null; // phpcs:ignore
$opt_before = get_option( 'erdo_quality' );

$post = function ( $action, $s, $with_nonce ) use ( $victim ) {
    $body = array( 'action' => $action, 'id' => $victim, 'post_id' => $victim, 'ids' => array( $victim ), 'erdo_quality' => 11 );
    if ( $with_nonce ) {
        $body['nonce'] = $s['nonce'];
    }
    $r = wp_remote_post( admin_url( 'admin-ajax.php' ), array( 'timeout' => 30, 'cookies' => $s['cookies'], 'body' => $body ) );
    return array( (int) wp_remote_retrieve_response_code( $r ), wp_remote_retrieve_body( $r ) );
};

$refused = function ( $res ) {
    list( $code, $body ) = $res;
    $json = json_decode( $body, true );
    if ( is_array( $json ) && ! empty( $json['success'] ) ) {
        return false;
    }
    return $code >= 400 || '-1' === trim( $body ) || '0' === trim( $body ) || ( is_array( $json ) && isset( $json['success'] ) && false === $json['success'] );
};

// Positive control: proves the session/nonce plumbing works, so the
// "refused" results below mean the plugin refused — not that our fake
// browser session was broken.
et_test( 'control: admin with valid nonce is accepted on a read-only action', function () use ( $post, $admin ) {
    list( $code, $body ) = $post( 'erdo_get_stats', $admin, true );
    $json = json_decode( $body, true );
    et_assert( 200 === $code && ! empty( $json['success'] ), "HTTP $code: " . substr( $body, 0, 120 ) );
} );

foreach ( $actions as $action ) {
    et_test( "ajax $action: subscriber with valid nonce is refused", function () use ( $post, $refused, $action, $sub ) {
        $res = $post( $action, $sub, true );
        et_assert( $refused( $res ), "accepted (HTTP {$res[0]}): " . substr( $res[1], 0, 120 ) );
    } );
    et_test( "ajax $action: admin without nonce is refused", function () use ( $post, $refused, $action, $admin ) {
        $res = $post( $action, $admin, false );
        et_assert( $refused( $res ), "accepted (HTTP {$res[0]}): " . substr( $res[1], 0, 120 ) );
    } );
}

et_test( 'no side effects from refused requests', function () use ( $victim, $htaccess, $ht_before, $opt_before ) {
    clean_post_cache( $victim );
    et_assert( (bool) get_post( $victim ), 'victim attachment was deleted' );
    et_assert( file_exists( get_attached_file( $victim ) ), 'victim file changed' );
    wp_cache_delete( 'alloptions', 'options' );
    et_eq( $opt_before, get_option( 'erdo_quality' ), 'settings changed' );
    $ht_now = file_exists( $htaccess ) ? file_get_contents( $htaccess ) : null; // phpcs:ignore
    et_eq( $ht_before, $ht_now, '.htaccess changed' );
} );

et_test( 'REST: anonymous requests are refused', function () {
    $routes = array_filter( array_keys( rest_get_server()->get_routes() ), function ( $r ) {
        // Skip the namespace index (/erdo/v1): WordPress always serves it publicly.
        return 0 === strpos( $r, '/erdo/' ) && false === strpos( $r, '(?P' ) && substr_count( $r, '/' ) > 2;
    } );
    et_assert( ! empty( $routes ), 'no erdo REST routes found' );
    foreach ( $routes as $route ) {
        $r = wp_remote_get( rest_url( ltrim( $route, '/' ) ), array( 'timeout' => 15 ) );
        $c = (int) wp_remote_retrieve_response_code( $r );
        et_assert( in_array( $c, array( 401, 403 ), true ), "$route answered $c" );
    }
} );

et_test( 'admin page is not reachable for a subscriber', function () use ( $sub ) {
    $r = wp_remote_get( admin_url( 'admin.php?page=erdo-image-optimizer' ), array( 'timeout' => 15, 'cookies' => $sub['cookies'], 'redirection' => 0 ) );
    $c = (int) wp_remote_retrieve_response_code( $r );
    et_assert( 200 !== $c || false === strpos( wp_remote_retrieve_body( $r ), 'erdo' ), "HTTP $c with plugin UI" );
} );

// Clean up: queue/cron a refused request might have started, the test user.
if ( class_exists( 'ERDO_Queue' ) ) {
    ERDO_Queue::cancel();
}
et_delete_temp_user( $sub_id );
