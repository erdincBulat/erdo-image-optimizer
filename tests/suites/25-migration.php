<?php
/**
 * 2.x → 3.0 repair. 2.x replaced originals with .webp and left old URLs in
 * content. Contract: every damaged image is detected; with a backup the
 * original comes back, without one every reference (content, serialized meta,
 * escaped JSON, theme mods) is repointed to the WebP; nothing is left broken,
 * and running the repair twice changes nothing.
 */

$m = et_fixtures();
et_options( array( 'erdo_convert_format' => 'webp', 'erdo_serve_webp' => 1, 'erdo_webp_serve_method' => 'picture' ) );

$with_backup = array( 'block' );
$no_backup   = array( 'badname', 'sized', 'serialized', 'elementor', 'acf_url', 'header' );
$before      = array();
foreach ( $with_backup as $n ) {
    $before[ $n ] = get_attached_file( $m['img'][ $n ] );
    et_simulate_legacy_conversion( $m['img'][ $n ], true );
}
foreach ( $no_backup as $n ) {
    et_simulate_legacy_conversion( $m['img'][ $n ] );
}
$legacy_webp = get_attached_file( $m['img']['block'] ); // fx-block-used.webp written by "2.x"

// The Studio site may hold real images converted by 2.2.2 in earlier
// sessions; those get repaired too, but assertions only cover the fixtures.
et_test( 'detects every image damaged by 2.x', function () use ( $m, $with_backup, $no_backup ) {
    et_assert( et_detect_legacy() >= count( $with_backup ) + count( $no_backup ), 'count too low' );
    $found   = et_legacy_ids();
    $missing = array_filter( array_merge( $with_backup, $no_backup ), function ( $n ) use ( $m, $found ) {
        return ! in_array( $m['img'][ $n ], $found, true );
    } );
    et_assert( empty( $missing ), 'not detected: ' . implode( ', ', $missing ) );
} );

et_test( 'dry-run plan: backup → restore, no backup → repoint', function () use ( $m ) {
    et_eq( 'restore', et_migration_plan( $m['img']['block'] )['mode'] );
    et_eq( 'repoint', et_migration_plan( $m['img']['badname'] )['mode'] );
    et_assert( et_migration_plan( $m['img']['badname'] )['refs'] > 0, 'no references counted for badname' );
} );

et_test( 'before repair: rescue filter keeps damaged pages showing images', function () use ( $m ) {
    et_assert_page_ok( $m['post']['classic'], 'classic', array( 'broken' ) );
    et_assert_page_ok( $m['post']['block'], 'block', array( 'broken' ) );
} );

$totals = et_migrate_all();

et_test( 'repair runs without errors and clears the flag', function () use ( $totals, $with_backup, $no_backup ) {
    et_eq( array(), $totals['errors'] );
    et_assert( $totals['restore'] >= count( $with_backup ), 'restore count ' . $totals['restore'] );
    et_assert( $totals['repoint'] >= count( $no_backup ), 'repoint count ' . $totals['repoint'] );
    et_eq( 0, et_detect_legacy() );
} );

et_test( 'restore: original JPEG is back as the attachment file', function () use ( $m, $before ) {
    $id = $m['img']['block'];
    et_eq( $before['block'], get_attached_file( $id ) );
    et_eq( 'image/jpeg', get_post_mime_type( $id ) );
    foreach ( et_served_files( $id ) as $f ) {
        et_assert( file_exists( $f ), 'missing ' . basename( $f ) );
    }
} );

et_test( 'restore: WebP siblings created, backup copy removed, 2.x .webp kept for old links', function () use ( $m, $before, $legacy_webp ) {
    $id = $m['img']['block'];
    et_assert( file_exists( $before['block'] . '.webp' ), 'no sibling for main file' );
    et_assert( ! file_exists( dirname( $before['block'] ) . '/_erdo_backup/' . basename( $before['block'] ) ), 'backup copy still there' );
    et_assert( file_exists( $legacy_webp ), '2.x .webp deleted (content may link to it)' );
    et_assert_page_ok( $m['post']['block'] );
} );

et_test( 'repoint: classic content now links the WebP files (full + sized)', function () use ( $m ) {
    $c = et_post_content( $m['post']['classic'] );
    et_assert( false === strpos( $c, 'Fx_Bad_Name.jpg' ), 'old full-size URL still in content' );
    et_assert( false === strpos( $c, 'fx-sized-only-300x210.png' ), 'old sized URL still in content' );
    et_assert( false !== strpos( $c, 'Fx_Bad_Name.webp' ) && false !== strpos( $c, 'fx-sized-only-300x210.webp' ), 'WebP URLs not in content' );
    et_assert_page_ok( $m['post']['classic'] );
} );

et_test( 'repoint: serialized meta stays valid and points at the WebP', function () use ( $m ) {
    wp_cache_delete( $m['post']['serialized'], 'post_meta' );
    $v = get_post_meta( $m['post']['serialized'], 'fx_slider', true );
    et_assert( is_array( $v ), 'meta corrupted' );
    et_eq( wp_get_attachment_url( $m['img']['serialized'] ), $v['slides'][0]['img'] );
    et_eq( 'Çağrı ünïcode', $v['slides'][0]['caption'] );
} );

et_test( 'repoint: Elementor JSON with escaped slashes is updated', function () use ( $m ) {
    wp_cache_delete( $m['post']['elementor'], 'post_meta' );
    $raw  = get_post_meta( $m['post']['elementor'], '_elementor_data', true );
    $data = json_decode( $raw, true );
    et_assert( is_array( $data ), 'JSON corrupted' );
    et_eq( wp_get_attachment_url( $m['img']['elementor'] ), $data[0]['settings']['image']['url'] );
    et_assert( false !== strpos( $raw, '\\/' ), 'escaping style was not preserved' );
} );

et_test( 'repoint: plain URL meta (ACF) and theme mods (header, incl. stdClass) are updated', function () use ( $m ) {
    wp_cache_delete( $m['post']['acf'], 'post_meta' );
    et_eq( wp_get_attachment_url( $m['img']['acf_url'] ), get_post_meta( $m['post']['acf'], 'hero_bg', true ) );
    wp_cache_delete( 'alloptions', 'options' );
    $url = wp_get_attachment_url( $m['img']['header'] );
    et_eq( $url, get_theme_mod( 'header_image' ) );
    et_eq( $url, get_theme_mod( 'header_image_data' )->url ?? null );
} );

et_test( 'repair is idempotent', function () use ( $m ) {
    $c      = et_post_content( $m['post']['classic'] );
    $totals = et_migrate_all();
    et_eq( 0, $totals['restore'] + $totals['repoint'] );
    et_eq( $c, et_post_content( $m['post']['classic'] ) );
} );

et_test( '2.x markers are cleaned up', function () use ( $m ) {
    foreach ( $m['img'] as $id ) {
        et_eq( '', get_post_meta( $id, '_erdo_savings_bytes', true ), "marker left on $id" );
    }
} );
