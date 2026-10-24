<?php
/**
 * Upload pipeline (wp_generate_attachment_metadata hooks). WP 7.1 runs the
 * metadata filter twice for browser-processed uploads ('create' then
 * 'update' after /finalize), so every hook must be idempotent.
 */

$m   = array( 'dir' => wp_upload_dir()['path'] );
$tmp = get_temp_dir() . 'et-upload-src-' . wp_rand() . '.jpg';
imagejpeg( et_noise( 1600, 1000, 99 ), $tmp, 88 );

et_options( array(
    'erdo_auto_webp'      => 1,
    'erdo_auto_sanitize'  => 1,
    'erdo_keep_original'  => 0,
    'erdo_convert_format' => 'webp',
    'erdo_max_width'      => 0,
) );

$id = et_upload( $tmp, 'Upload Test_Photo.JPG' );

et_test( 'upload with auto-convert succeeds', function () use ( $id ) {
    et_not_error( $id );
} );

if ( is_wp_error( $id ) ) {
    return;
}

et_test( 'auto-sanitize: name becomes upload-test-photo', function () use ( $id ) {
    $stem = strtolower( pathinfo( preg_replace( '/\.(webp|avif)$/', '', get_attached_file( $id ) ), PATHINFO_FILENAME ) );
    // -N suffix allowed: Windows keeps earlier runs' files "delete-pending".
    et_assert( (bool) preg_match( '/^upload-test-photo(-\d+)?$/', $stem ), "got $stem" );
} );

et_test( 'auto-convert: original upload is kept', function () use ( $id ) {
    $orig = preg_replace( '/\.(webp|avif)$/i', '', get_attached_file( $id ) );
    et_assert( (bool) preg_match( '/\.jpe?g$/i', $orig ) && file_exists( $orig ), 'original JPEG gone: ' . basename( $orig ) );
}, 'F2' );

et_test( 'auto-convert: every generated size has a WebP rendition', function () use ( $id ) {
    $missing = array();
    foreach ( et_attachment_files( $id ) as $f ) {
        if ( ! preg_match( '/\.webp$/i', $f ) && ! file_exists( $f . '.webp' ) ) {
            $missing[] = basename( $f );
        }
    }
    et_assert( empty( $missing ), 'no WebP for: ' . implode( ', ', $missing ) );
}, 'F2' );

et_test( "idempotent: a second metadata pass ('update', as WP 7.1 sends) changes nothing", function () use ( $id ) {
    $dir    = dirname( get_attached_file( $id ) );
    $before = scandir( $dir );
    $mime   = get_post_mime_type( $id );
    $meta   = wp_get_attachment_metadata( $id );
    $out    = apply_filters( 'wp_generate_attachment_metadata', $meta, $id, 'update' );
    clearstatcache();
    et_assert( is_array( $out ), 'filter returned non-array' );
    et_eq( $before, scandir( $dir ), 'files changed on second pass' );
    et_eq( $mime, get_post_mime_type( $id ), 'mime changed on second pass' );
    foreach ( et_attachment_files( $id ) as $f ) {
        et_assert( file_exists( $f ), 'metadata points at missing ' . basename( $f ) );
    }
}, 'F2' );

et_test( 'max width: large upload is scaled down', function () use ( $tmp ) {
    update_option( 'erdo_max_width', 1000 );
    $id2 = et_not_error( et_upload( $tmp, 'max-width-test.jpg' ) );
    update_option( 'erdo_max_width', 0 );
    $meta = wp_get_attachment_metadata( $id2 );
    et_assert( (int) $meta['width'] <= 1000, 'width ' . $meta['width'] );
    $size = getimagesize( get_attached_file( $id2 ) );
    et_assert( $size[0] <= 1000, 'file width ' . $size[0] );
} );

et_test( 'upload without auto-convert leaves the file alone', function () use ( $tmp ) {
    update_option( 'erdo_auto_webp', 0 );
    $id3 = et_not_error( et_upload( $tmp, 'plain-upload.jpg' ) );
    et_eq( 'image/jpeg', get_post_mime_type( $id3 ) );
    et_eq( array(), array_filter( et_attachment_files( $id3 ), function ( $f ) {
        return file_exists( $f . '.webp' );
    } ) );
} );

wp_delete_file( $tmp );
