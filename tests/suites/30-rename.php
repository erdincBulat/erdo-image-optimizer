<?php
/**
 * Renaming. Contract: every reference to every rendition is updated
 * (content, block markup, escaped JSON, serialized meta) without corrupting
 * data, old URLs 301 to the new ones, and names can't escape the uploads dir.
 */

$m = et_fixtures();
et_options( array( 'erdo_append_original_filename' => 0 ) );

$ref = function ( $id ) {
    return array( 'files' => et_attachment_files( $id ), 'urls' => et_attachment_urls( $id ) );
};

/* --- Fix Filename on a full-size reference ------------------------- */
$bad_before = $ref( $m['img']['badname'] );
$bad_res    = et_rename( $m['img']['badname'] );

et_test( 'fix filename: Fx_Bad_Name.jpg becomes fx-bad-name.jpg', function () use ( $m, $bad_res ) {
    et_not_error( $bad_res );
    et_eq( 'fx-bad-name.jpg', strtolower( basename( get_attached_file( $m['img']['badname'] ) ) ) );
} );

et_test( 'fix filename: every rendition moved, none left under the old name', function () use ( $m, $bad_before ) {
    foreach ( $bad_before['files'] as $f ) {
        et_assert( ! file_exists( $f ) || 0 === strcasecmp( $f, get_attached_file( $m['img']['badname'] ) ), 'still on disk: ' . basename( $f ) );
    }
    foreach ( et_attachment_files( $m['img']['badname'] ) as $f ) {
        et_assert( file_exists( $f ), 'missing: ' . basename( $f ) );
    }
} );

et_test( 'fix filename: post content points at the new URL', function () use ( $m ) {
    $c = et_post_content( $m['post']['classic'] );
    et_assert( false === strpos( $c, 'Fx_Bad_Name' ), 'old name still in content' );
    et_assert( false !== strpos( $c, wp_get_attachment_url( $m['img']['badname'] ) ), 'new URL not in content' );
    et_assert_page_ok( $m['post']['classic'], '', array( 'broken' ) );
} );

et_test( 'fix filename: old URLs redirect (301) to the new ones', function () use ( $bad_before ) {
    $s = et_status( $bad_before['urls'][0] );
    et_eq( 301, $s ? $s['code'] : 0, 'old full-size URL' );
    et_assert( false !== strpos( $s['location'], 'fx-bad-name' ), 'redirect target: ' . $s['location'] );
}, 'F4' );

/* --- Image referenced only through a sized (-300xN) URL ------------ */
$sized_before = $ref( $m['img']['sized'] );
et_rename( $m['img']['sized'], 'renamed-sized' );

et_test( 'rename: sized-only reference (-300xN URL) is updated', function () use ( $m, $sized_before ) {
    $c = et_post_content( $m['post']['classic'] );
    et_assert( false === strpos( $c, 'fx-sized-only' ), 'old sized URL still in content' );
    et_assert_page_ok( $m['post']['classic'], '', array( 'broken' ) );
}, 'F4' );

/* --- Serialized meta ------------------------------------------------ */
et_rename( $m['img']['serialized'], 'renamed-serialized-longer-name' );

et_test( 'rename: serialized meta stays valid and holds the new URL', function () use ( $m ) {
    wp_cache_delete( $m['post']['serialized'], 'post_meta' );
    $v = get_post_meta( $m['post']['serialized'], 'fx_slider', true );
    et_assert( is_array( $v ), 'meta no longer unserializes (corrupted)' );
    et_eq( wp_get_attachment_url( $m['img']['serialized'] ), $v['slides'][0]['img'] );
    et_eq( 'Çağrı ünïcode', $v['slides'][0]['caption'], 'caption damaged' );
}, 'F4' );

/* --- Page-builder JSON with escaped slashes ------------------------ */
et_rename( $m['img']['elementor'], 'renamed-elementor' );

et_test( 'rename: Elementor JSON (escaped slashes) is updated', function () use ( $m ) {
    wp_cache_delete( $m['post']['elementor'], 'post_meta' );
    $data = json_decode( get_post_meta( $m['post']['elementor'], '_elementor_data', true ), true );
    et_assert( is_array( $data ), 'JSON corrupted' );
    et_eq( wp_get_attachment_url( $m['img']['elementor'] ), $data[0]['settings']['image']['url'] );
}, 'F4' );

/* --- Plain URL in meta (ACF URL field) ----------------------------- */
et_rename( $m['img']['acf_url'], 'renamed-acf-url' );

et_test( 'rename: plain URL in post meta is updated', function () use ( $m ) {
    wp_cache_delete( $m['post']['acf'], 'post_meta' );
    et_eq( wp_get_attachment_url( $m['img']['acf_url'] ), get_post_meta( $m['post']['acf'], 'hero_bg', true ) );
} );

/* --- Camera names / custom names ----------------------------------- */
et_test( 'fix filename: IMG_1234.JPG becomes img-1234.jpg', function () use ( $m ) {
    et_not_error( et_rename( $m['img']['camera'] ) );
    et_eq( 'img-1234.jpg', strtolower( basename( get_attached_file( $m['img']['camera'] ) ) ) );
} );

// 2.2.2 lowercases the extension when building the old URL, so content
// holding "IMG_1234.JPG" is never matched and the image breaks.
et_test( 'fix filename: uppercase-extension references (.JPG) are updated', function () use ( $m ) {
    et_assert( false === stripos( et_post_content( $m['post']['misc'] ), 'IMG_1234' ), 'old name still in content' );
    et_assert_page_ok( $m['post']['misc'], '', array( 'broken' ) );
}, 'F4' );

et_test( 'custom name + "append original filename" setting', function () use ( $m ) {
    update_option( 'erdo_append_original_filename', 1 );
    $r = et_rename( $m['img']['dscalt'], 'Istanbul' );
    update_option( 'erdo_append_original_filename', 0 );
    et_not_error( $r );
    et_eq( 'istanbul-dsc-0042.jpg', basename( get_attached_file( $m['img']['dscalt'] ) ) );
} );

et_test( 'rename cannot escape the uploads folder', function () use ( $m ) {
    $r   = et_rename( $m['img']['unused'], '../../../evil' );
    $now = wp_normalize_path( get_attached_file( $m['img']['unused'] ) );
    et_assert( 0 === strpos( $now, wp_normalize_path( $m['dir'] ) ), 'file moved to ' . $now );
    et_assert( is_wp_error( $r ) || false === strpos( basename( $now ), '..' ), 'unsafe name accepted' );
} );

et_test( 'name collision gets a unique suffix instead of overwriting', function () use ( $m ) {
    $a = et_rename( $m['img']['dup_a'], 'same-name' );
    $b = et_rename( $m['img']['dup_b'], 'same-name' );
    et_not_error( $a );
    et_not_error( $b );
    et_assert( get_attached_file( $m['img']['dup_a'] ) !== get_attached_file( $m['img']['dup_b'] ), 'both point at one file' );
    et_assert( file_exists( get_attached_file( $m['img']['dup_a'] ) ) && file_exists( get_attached_file( $m['img']['dup_b'] ) ), 'a file was overwritten' );
} );

/* --- Pattern rename (user request, 3.0) ---------------------------- */
et_test( 'pattern rename: {keyword}-{n} over a selection', function () use ( $m ) {
    $ids = array( $m['img']['woo_gallery'], $m['img']['widget_media'] );
    $r   = et_not_error( et_rename_pattern( $ids, '{keyword}-{n}', array( 'keyword' => 'istanbul' ) ) );
    et_eq( 'istanbul-1.jpg', basename( get_attached_file( $ids[0] ) ) );
    et_eq( 'istanbul-2.jpg', basename( get_attached_file( $ids[1] ) ) );
}, 'F4' );
