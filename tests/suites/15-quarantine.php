<?php
/**
 * Quarantine instead of delete. Contract: bulk cleanup only ever touches
 * images nothing references (index + "file name appears anywhere" check);
 * quarantined files leave the public uploads tree but the attachment stays;
 * restore puts everything back byte-for-byte; purge is a separate, explicit step.
 */

et_index_rebuild();
$m = et_fixtures();
et_options( array( 'erdo_convert_format' => 'webp' ) );

$expected_unused = array_keys( array_filter( et_expected_usage(), function ( $u ) {
    return ! $u;
} ) );

et_test( 'bulk-cleanup list = exactly the unused fixtures', function () use ( $m, $expected_unused ) {
    $unused  = et_unused_ids();
    $offered = array();
    foreach ( $m['img'] as $name => $id ) {
        if ( in_array( $id, $unused, true ) ) {
            $offered[] = $name;
        }
    }
    sort( $offered );
    $want = $expected_unused;
    sort( $want );
    et_eq( $want, $offered );
} );

et_test( 'strict quarantine refuses an image in use', function () use ( $m ) {
    $r = et_quarantine( $m['img']['logo'], true );
    et_assert( is_wp_error( $r ) && 'in_use' === $r->get_error_code(), 'not refused: ' . ( is_wp_error( $r ) ? $r->get_error_code() : 'moved!' ) );
    et_assert( file_exists( get_attached_file( $m['img']['logo'] ) ), 'logo file moved' );
} );

et_test( 'strict quarantine refuses when the file name appears somewhere the index cannot resolve', function () use ( $m ) {
    // A plugin setting holding just the file name (no uploads URL, no ID).
    $id = $m['img']['dup_b'];
    update_post_meta( $m['post']['good'], 'fx_plain_name', 'hero: ' . basename( get_attached_file( $id ) ) );
    $r = et_quarantine( $id, true );
    delete_post_meta( $m['post']['good'], 'fx_plain_name' );
    et_assert( is_wp_error( $r ) && 'maybe_in_use' === $r->get_error_code(), 'not refused: ' . ( is_wp_error( $r ) ? $r->get_error_code() : 'moved!' ) );
} );

// Error/activity-log plugins write file paths into options; that must not
// lock an unused image, while a real plugin setting must.
et_test( 'file name in a log/cache option does not block cleanup, in a settings option it does', function () use ( $m ) {
    $id   = $m['img']['attached_only'];
    $name = basename( get_attached_file( $id ) );
    update_option( 'fx_plugin_error_log', array( "Warning: unlink($name): Permission denied" ), false );
    $r = et_quarantine( $id, true );
    delete_option( 'fx_plugin_error_log' );
    et_not_error( $r, 'blocked by a log option' );
    et_not_error( et_unquarantine( $id ) );

    update_option( 'fx_plugin_settings', array( 'hero' => $name ), false );
    $r = et_quarantine( $id, true );
    delete_option( 'fx_plugin_settings' );
    et_assert( is_wp_error( $r ) && 'maybe_in_use' === $r->get_error_code(), 'settings option did not block' );
} );

// --- Full cycle on a converted, unused image ---------------------------
$id = $m['img']['unused'];
et_convert( $id, 'webp' );
$files = array();
foreach ( et_attachment_files( $id ) as $f ) {
    $files[ $f ] = md5_file( $f );
    if ( file_exists( $f . '.webp' ) ) {
        $files[ $f . '.webp' ] = md5_file( $f . '.webp' );
    }
}
$url = wp_get_attachment_url( $id );
$res = et_quarantine( $id, true );

et_test( 'quarantine moves every file (incl. WebP copies) out of place', function () use ( $res, $files ) {
    et_not_error( $res );
    $left = array_filter( array_keys( $files ), 'file_exists' );
    et_assert( empty( $left ), 'still in place: ' . implode( ', ', array_map( 'basename', $left ) ) );
} );

// nginx / PHP's built-in server ignore .htaccess, so the folder name must not be guessable.
et_test( 'quarantined files are not publicly reachable at a guessable URL', function () use ( $url, $id ) {
    $s = et_status( $url );
    et_assert( $s && 200 !== $s['code'], 'original URL still 200' );
    $q = trailingslashit( wp_upload_dir()['baseurl'] ) . '_erdo_quarantine/' . $id . '/' . basename( $url );
    $s = et_status( $q );
    et_assert( $s && 200 !== $s['code'], 'guessable quarantine URL answers 200' );
} );

et_test( 'quarantined image disappears from the library but the attachment remains', function () use ( $id ) {
    et_assert( (bool) get_post( $id ), 'attachment row deleted' );
    et_assert( in_array( $id, et_quarantined_ids(), true ), 'not listed in quarantine' );
    $lib = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => -1, 'fields' => 'ids' ) );
    et_assert( ! in_array( $id, $lib, true ), 'still in media library queries' );
    et_assert( ! in_array( $id, et_unused_ids(), true ), 'offered for cleanup again' );
} );

et_test( 'restore puts every file back byte-for-byte', function () use ( $id, $files, $url ) {
    et_not_error( et_unquarantine( $id ) );
    foreach ( $files as $f => $hash ) {
        et_assert( file_exists( $f ) && md5_file( $f ) === $hash, 'missing/changed: ' . basename( $f ) );
    }
    et_eq( 'inherit', get_post( $id )->post_status ); // get_post_status() maps unattached "inherit" to "publish"
    $s = et_status( $url . '?r=' . wp_rand() );
    et_eq( 200, $s ? $s['code'] : 0, 'URL after restore' );
} );

et_test( 'purge deletes the attachment and all its files', function () use ( $id, $files ) {
    et_not_error( et_quarantine( $id, true ) );
    et_not_error( et_purge( $id ) );
    clean_post_cache( $id );
    et_assert( ! get_post( $id ), 'attachment still exists' );
    foreach ( array_keys( $files ) as $f ) {
        et_assert( ! file_exists( $f ), 'file left: ' . basename( $f ) );
    }
    et_assert( empty( glob( trailingslashit( wp_upload_dir()['basedir'] ) . '_erdo_quarantine/' . $id . '-*' ) ), 'quarantine folder left' );
} );

et_test( 'explicit single quarantine of a used image is allowed but flagged', function () use ( $m ) {
    $r = et_not_error( et_quarantine( $m['img']['gif_static'], false ) );
    et_eq( true, $r['in_use'] );
    et_not_error( et_unquarantine( $m['img']['gif_static'] ) );
    et_assert_page_ok( $m['post']['misc'], '', array( 'broken' ) );
} );

et_test( 'quarantine record stores forward-slash paths (update_post_meta strips backslashes on Windows)', function () use ( $m ) {
    $id = $m['img']['gif_static'];
    et_not_error( et_quarantine( $id, false ) );
    $rec = et_quarantine_record( $id );
    foreach ( $rec['files'] as $orig => $q ) {
        et_assert( false === strpos( $orig . $q, chr( 92 ) ), 'backslash in stored path: ' . $q );
        et_assert( file_exists( $q ), 'stored path does not point at the file: ' . $q );
    }
    et_not_error( et_unquarantine( $id ) );
} );

et_test( 'restore still finds the files of an old record whose backslashes were stripped', function () use ( $m ) {
    $id = $m['img']['gif_static'];
    $files = array();
    foreach ( et_quarantine_files( $id ) as $f ) {
        $files[ $f ] = md5_file( $f );
    }
    et_not_error( et_quarantine( $id, false ) );
    // What a pre-3.0 record looked like on Windows: the backslashes of ABSPATH
    // ("C:\Users\…\site/wp-content/…") were stripped on save ("C:Users…site/wp-content/…").
    $rec  = et_quarantine_record( $id );
    $root = wp_normalize_path( ABSPATH );
    foreach ( $rec['files'] as $orig => $q ) {
        $rec['files'][ $orig ] = str_replace( $root, str_replace( '/', '', untrailingslashit( $root ) ) . '/', $q );
        et_assert( ! file_exists( $rec['files'][ $orig ] ), 'simulated path still resolves' );
    }
    et_set_quarantine_record( $id, $rec );
    et_not_error( et_unquarantine( $id ) );
    foreach ( $files as $f => $hash ) {
        et_assert( file_exists( $f ) && md5_file( $f ) === $hash, 'not restored: ' . basename( $f ) );
    }
} );
