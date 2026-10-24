<?php
/**
 * Hardening (PLAN-3.0 Faz 6): file operations stay inside uploads, tampered
 * meta can't move files elsewhere, manual resize keeps the original,
 * removed features are really gone, and every admin.js string is translatable.
 */

$m    = et_fixtures();
$base = wp_upload_dir()['basedir'];

/* --- Path guard -------------------------------------------------------- */
et_test( 'path guard: inside uploads yes; outside, traversal and missing folders no', function () use ( $m, $base ) {
    et_eq( true, et_in_uploads( get_attached_file( $m['img']['good'] ) ) );
    et_eq( true, et_in_uploads( $base . '/new-file-that-does-not-exist-yet.jpg' ) );
    et_eq( false, et_in_uploads( ABSPATH . 'wp-config.php' ) );
    et_eq( false, et_in_uploads( $base . '/../../wp-config.php' ) );
    et_eq( false, et_in_uploads( $base . '/no-such-folder/x.jpg' ) );
    et_eq( false, et_in_uploads( '' ) );
} );

/* --- Quarantine -------------------------------------------------------- */
et_test( 'quarantine also moves media-editor backups (_wp_attachment_backup_sizes) and brings them back', function () use ( $m ) {
    $id   = $m['img']['unused'];
    $file = get_attached_file( $id );
    $bak  = dirname( $file ) . '/fx-truly-unused-e1700000000000.jpg';
    copy( $file, $bak );
    update_post_meta( $id, '_wp_attachment_backup_sizes', array( 'full-orig' => array( 'file' => basename( $bak ), 'width' => 800, 'height' => 500 ) ) );
    et_not_error( et_quarantine( $id, false ) );
    et_assert( ! file_exists( $bak ), 'backup left in the public folder' );
    et_not_error( et_unquarantine( $id ) );
    et_assert( file_exists( $bak ), 'backup not restored' );
} );

et_test( 'quarantine restore refuses a tampered target outside uploads', function () use ( $m ) {
    $id = $m['img']['attached_only'];
    et_not_error( et_quarantine( $id, false ) );
    $data  = get_post_meta( $id, '_erdo_quarantine', true );
    $files = $data['files'];
    $evil  = array();
    foreach ( $files as $orig => $q ) {
        $evil[ ABSPATH . 'erdo-evil-' . basename( $orig ) ] = $q;
    }
    update_post_meta( $id, '_erdo_quarantine', array_merge( $data, array( 'files' => $evil ) ) );
    $r = et_unquarantine( $id );
    et_assert( is_wp_error( $r ), 'restore accepted a path outside uploads' );
    foreach ( array_keys( $evil ) as $p ) {
        et_assert( ! file_exists( $p ), 'file written outside uploads: ' . $p );
    }
    update_post_meta( $id, '_erdo_quarantine', $data ); // put the real map back
    et_not_error( et_unquarantine( $id ) );
} );

/* --- 2.x repair -------------------------------------------------------- */
et_test( 'repair: a backup path outside uploads is ignored (repoint instead of copy)', function () use ( $m ) {
    $id = $m['img']['header'];
    et_simulate_legacy_conversion( $id, false );
    update_post_meta( $id, '_erdo_backup_path', ABSPATH . 'wp-config.php' );
    et_eq( 'repoint', et_migration_plan( $id )['mode'] ?? null );
} );

/* --- Manual resize ----------------------------------------------------- */
$rid    = $m['img']['oversized'];
$before = wp_get_attachment_metadata( $rid );

et_test( 'resize: refuses to upscale or to keep the same size', function () use ( $rid, $before ) {
    et_eq( false, et_resize( $rid, $before['width'] + 100, $before['height'] + 100 ) );
    et_eq( false, et_resize( $rid, $before['width'], $before['height'] ) );
    et_eq( null, et_resize_backup( $rid ), 'backup created by a refused resize' );
} );

et_test( 'resize: keeps a restorable copy of the original', function () use ( $rid, $before ) {
    et_eq( true, et_resize( $rid, 1200, 1200 ) );
    $now = wp_get_attachment_metadata( $rid );
    et_assert( $now['width'] <= 1200 && $now['height'] <= 1200, "resized to {$now['width']}x{$now['height']}" );
    $bak = et_resize_backup( $rid );
    et_assert( $bak && file_exists( $bak['path'] ), 'no backup' );
    et_eq( $before['width'], $bak['width'] );
} );

et_test( 'resize: renaming carries the backup along', function () use ( $rid ) {
    et_not_error( et_rename( $rid, 'resized-then-renamed' ) );
    $bak = et_resize_backup( $rid );
    et_assert( $bak && 0 === strpos( basename( $bak['path'] ), 'resized-then-renamed' ), 'backup not renamed: ' . ( $bak ? basename( $bak['path'] ) : 'none' ) );
} );

et_test( 'resize: restore brings back the original size and removes the backup', function () use ( $rid, $before ) {
    $bak = et_resize_backup( $rid );
    et_not_error( et_restore_size( $rid ) );
    et_eq( $before['width'], wp_get_attachment_metadata( $rid )['width'] );
    et_eq( null, et_resize_backup( $rid ) );
    et_assert( ! file_exists( $bak['path'] ), 'backup file left behind' );
} );

/* --- Removed features -------------------------------------------------- */
et_test( 'SVG uploads are gone even if the old setting is still on', function () {
    update_option( 'erdo_allow_svg', 1 );
    $mimes = get_allowed_mime_types( get_current_user_id() );
    delete_option( 'erdo_allow_svg' );
    et_assert( ! isset( $mimes['svg'] ) && ! in_array( 'image/svg+xml', $mimes, true ), 'svg allowed' );
    et_assert( ! has_filter( 'wp_handle_upload_prefilter', 'erdo_sanitize_svg_on_upload' ), 'svg upload filter still hooked' );
} );

et_test( 'BunnyCDN and the network settings page are gone', function () {
    et_assert( ! has_action( 'wp_ajax_erdo_cdn_upload' ), 'cdn ajax still registered' );
    et_assert( ! function_exists( 'erdo_network_settings_page' ), 'network page still defined' );
} );

et_test( 'upgrade from 2.x removes old options and shows the what-changed notice once', function () {
    $keep = get_option( 'erdo_db_version' );
    update_option( 'erdo_bunny_enabled', 1 );
    update_option( 'erdo_bunny_api_key', 'test-key-not-real' );
    update_option( 'erdo_allow_svg', 1 );
    et_upgrade_from( 5 );
    et_eq( false, get_option( 'erdo_bunny_api_key' ), 'API key kept' );
    et_eq( false, get_option( 'erdo_allow_svg' ) );
    $flags = get_option( 'erdo_upgrade_notice' );
    et_assert( is_array( $flags ) && $flags['svg'] && $flags['bunny'], 'notice flags missing' );
    delete_option( 'erdo_upgrade_notice' );
    update_option( 'erdo_db_version', $keep );
} );

/* --- i18n -------------------------------------------------------------- */
et_test( 'every t(...) string in admin.js is in the translatable PHP map', function () {
    $js = file_get_contents( ERDO_PATH . 'assets/js/admin.js' ); // phpcs:ignore
    preg_match_all( "/\\bt\\('((?:[^'\\\\]|\\\\.)*)'\\)/", $js, $mm );
    $map     = et_js_strings();
    $missing = array_diff( array_unique( $mm[1] ), array_keys( $map ) );
    et_assert( ! $missing, 'not translatable: ' . implode( ' | ', $missing ) );
} );

et_test( 'admin.js has no hard-coded English alerts or confirms left', function () {
    $js = file_get_contents( ERDO_PATH . 'assets/js/admin.js' ); // phpcs:ignore
    et_eq( 0, preg_match_all( "/(alert|confirm)\\(\\s*'[A-Z]/", $js ), 'raw alert/confirm strings' );
} );
