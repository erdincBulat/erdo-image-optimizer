<?php
/**
 * Front-end delivery of modern formats, lazy-loading and LCP behaviour,
 * and the 3.0 rescue filter for libraries damaged by 2.x conversions.
 */

$m = et_fixtures();
et_options( array(
    'erdo_keep_original'     => 0,
    'erdo_lossless'          => 0,
    'erdo_smart_quality'     => 0,
    'erdo_serve_webp'        => 1,
    'erdo_webp_serve_method' => 'picture',
    'erdo_lazy_loading'      => 1,
) );

$good_orig = get_attached_file( $m['img']['good'] );
et_convert( $m['img']['good'], 'webp' );
et_convert( $m['img']['dup_a'], 'webp' );
$tags = et_image_tags( et_fetch( get_permalink( $m['post']['good'] ) ) );

et_test( 'picture mode: page has no broken images or invalid markup', function () use ( $m ) {
    et_assert_page_ok( $m['post']['good'] );
}, 'F2' );

et_test( 'picture mode: <source> offers the WebP via srcset', function () use ( $tags ) {
    $found = false;
    foreach ( $tags as $t ) {
        if ( 'source' === $t['tag'] && 'image/webp' === ( $t['type'] ?? '' ) && false !== strpos( $t['srcset'] ?? '', '.webp' ) ) {
            $found = true;
        }
    }
    et_assert( $found, 'no <source type=image/webp srcset=...webp>' );
}, 'F2' );

et_test( 'picture mode: responsive srcset is preserved for every width', function () use ( $tags ) {
    // Compare the first <source> with the <img> of the same <picture> (the next
    // img after it); an earlier img, e.g. the theme's site logo, is not wrapped.
    $img_w = $src_w = 0;
    foreach ( $tags as $t ) {
        if ( 'source' === $t['tag'] && ! $src_w ) {
            $src_w = count( et_srcset_urls( $t['srcset'] ?? '' ) );
        } elseif ( 'img' === $t['tag'] && $src_w && ! $img_w ) {
            $img_w = count( et_srcset_urls( $t['srcset'] ?? '' ) );
        }
    }
    et_assert( $img_w > 1, 'img lost its srcset' );
    et_eq( $img_w, $src_w, 'source srcset candidate count' );
}, 'F2' );

et_test( 'LCP: first content image is not lazy-loaded', function () use ( $tags ) {
    $first = null;
    foreach ( $tags as $t ) {
        if ( 'img' === $t['tag'] && false !== strpos( $t['class'] ?? '', 'wp-image-' ) ) {
            $first = $t;
            break;
        }
    }
    et_assert( (bool) $first, 'no content image found' );
    et_assert( 'lazy' !== ( $first['loading'] ?? '' ), 'first image has loading=lazy (hurts LCP)' );
}, 'F2' );

et_test( 'LCP: no image is both fetchpriority=high and loading=lazy', function () use ( $tags ) {
    foreach ( $tags as $t ) {
        et_assert( ! ( 'high' === ( $t['fetchpriority'] ?? '' ) && 'lazy' === ( $t['loading'] ?? '' ) ), 'conflict on ' . basename( $t['src'] ?? '?' ) );
    }
}, 'F2' );

et_test( '.htaccess rule looks for the file name the converter actually writes', function () use ( $good_orig ) {
    // 3.0 contract: siblings are x.jpg.webp and the rule tests REQUEST_FILENAME.webp.
    et_assert( false !== strpos( et_htaccess_rules(), '%{REQUEST_FILENAME}.webp' ), 'rule does not test REQUEST_FILENAME.webp' );
    et_assert( file_exists( $good_orig . '.webp' ), 'converter did not write ' . basename( $good_orig ) . '.webp' );
}, 'F2' );

et_test( '.htaccess rules send Vary: Accept', function () {
    et_assert( false !== stripos( et_htaccess_rules(), 'Vary Accept' ), 'no Vary header' );
} );

/* --- Server rewrites over real HTTP: Apache/LiteSpeed with the plugin's
   .htaccess block, nginx with get_nginx_config() (tests/docker installs it).
   The Studio PHP server has no rewrite layer, so this skips there. ------- */
et_test( 'server rewrite: the .jpg URL serves the WebP to browsers that accept it, the JPEG to others', function () use ( $m ) {
    $server = strtolower( (string) wp_remote_retrieve_header( wp_remote_head( home_url( '/' ) ), 'server' ) );
    $apache = false !== strpos( $server, 'apache' ) || false !== strpos( $server, 'litespeed' );
    if ( ! $apache && false === strpos( $server, 'nginx' ) ) {
        et_skip( 'server "' . $server . '" has no rewrite layer' );
    }
    $file = trailingslashit( wp_upload_dir()['basedir'] ) . '.htaccess';
    $had  = file_exists( $file ) ? file_get_contents( $file ) : null; // phpcs:ignore
    if ( $apache ) {
        ERDO_WebP_Server::write_htaccess_rules();
    }
    try {
        $url  = wp_get_attachment_url( $m['img']['good'] );
        $webp = et_status( $url, 'image/avif,image/webp,*/*' ); // no AVIF copy: falls through to WebP
        $jpeg = et_status( $url, 'image/png,*/*' );
        et_eq( 200, $webp ? $webp['code'] : 0, 'webp request' );
        et_assert( false !== strpos( $webp['type'], 'image/webp' ), 'webp request got ' . $webp['type'] );
        et_assert( false !== stripos( $webp['vary'], 'accept' ), 'no Vary: Accept (' . $webp['vary'] . ')' );
        et_assert( false !== strpos( $jpeg['type'], 'image/jpeg' ), 'plain request got ' . $jpeg['type'] );
    } finally {
        if ( $apache ) {
            null === $had ? wp_delete_file( $file ) : file_put_contents( $file, $had ); // phpcs:ignore
        }
    }
} );

/* --- 3.0 safety net for 2.x-damaged libraries ---------------------- */
et_simulate_legacy_conversion( $m['img']['block'] );
et_detect_legacy();

et_test( 'rescue: page with URLs to files deleted by 2.x still shows the image', function () use ( $m ) {
    et_assert_page_ok( $m['post']['block'] );
}, 'F2' );
