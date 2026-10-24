<?php
/** Environment sanity + capability report. Everything else depends on this. */

et_test( 'plugin is active', function () {
    et_assert( defined( 'ERDO_VERSION' ), 'ERDO_VERSION not defined' );
} );

et_test( 'Studio site answers over HTTP', function () {
    et_assert( et_site_up(), 'site down — run: studio start (in ' . ABSPATH . ')' );
} );

et_test( 'uploads folder is writable', function () {
    et_assert( wp_is_writable( wp_upload_dir()['basedir'] ), 'uploads not writable' );
} );

ET::info( 'erdo=' . ( defined( 'ERDO_VERSION' ) ? ERDO_VERSION : '?' ) . ' wp=' . get_bloginfo( 'version' ) . ' php=' . PHP_VERSION . ' theme=' . get_stylesheet() );
ET::info( 'gd=' . (int) extension_loaded( 'gd' ) . ' gd_webp=' . (int) function_exists( 'imagewebp' ) . ' gd_avif=' . (int) function_exists( 'imageavif' ) . ' imagick=' . (int) extension_loaded( 'imagick' ) . ' editor=' . _wp_image_editor_choose( array( 'mime_type' => 'image/jpeg' ) ) );
ET::info( 'server=' . ( wp_remote_retrieve_header( wp_remote_head( home_url( '/' ) ), 'server' ) ?: 'unknown' ) . ' big_image_threshold=' . apply_filters( 'big_image_size_threshold', 2560, array( 0, 0 ), '', 0 ) );

et_test( 'fixture catalogue builds and cleans up', function () {
    $m = et_fixtures();
    et_eq( count( et_expected_usage() ), count( $m['img'] ), 'every expected-usage entry has a fixture' );
    foreach ( $m['img'] as $name => $id ) {
        et_assert( file_exists( get_attached_file( $id ) ), "missing file for $name" );
    }
    et_assert( false !== strpos( get_attached_file( $m['img']['oversized'] ), '-scaled' ), 'oversized fixture should get a -scaled main file' );
    et_cleanup_fixtures();
    et_assert( ! get_post( $m['img']['good'] ), 'cleanup left attachments behind' );
} );
