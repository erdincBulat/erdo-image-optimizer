<?php
/**
 * Conversion safety. 3.0 contract: originals are never touched, every
 * rendition gets a modern-format sibling, every URL the site already links
 * to keeps answering 200, and restore is clean.
 */

$m = et_fixtures();
et_options( array(
    'erdo_keep_original'     => 0,
    'erdo_lossless'          => 0,
    'erdo_smart_quality'     => 0,
    'erdo_quality'           => 80,
    'erdo_serve_webp'        => 1,
    'erdo_webp_serve_method' => 'picture',
) );

$cases = array(
    'badname'    => 'JPEG',
    'sized'      => 'PNG',
    'gif_static' => 'static GIF',
    'oversized'  => 'JPEG with -scaled main file',
    'alpha'      => 'transparent PNG',
);

// Sample transparent pixels of the alpha PNG before anything touches it.
$alpha_file = get_attached_file( $m['img']['alpha'] );
$alpha_im   = imagecreatefrompng( $alpha_file );
$clear_px   = array();
for ( $y = 0; $y < imagesy( $alpha_im ) && count( $clear_px ) < 5; $y += 7 ) {
    for ( $x = 0; $x < imagesx( $alpha_im ) && count( $clear_px ) < 5; $x += 13 ) {
        if ( 127 === ( ( imagecolorat( $alpha_im, $x, $y ) >> 24 ) & 0x7F ) ) {
            $clear_px[] = array( $x, $y );
        }
    }
}

foreach ( $cases as $name => $label ) {
    $id     = $m['img'][ $name ];
    $before = et_attachment_files( $id );
    $urls   = et_attachment_urls( $id );
    $sizes  = array();
    foreach ( $before as $f ) {
        $sizes[ $f ] = filesize( $f );
    }
    $res = et_convert( $id, 'webp' );

    et_test( "convert $label: succeeds", function () use ( $res ) {
        et_eq( 'ok', $res );
    } );

    et_test( "convert $label: original files untouched", function () use ( $before, $sizes ) {
        $gone = array();
        foreach ( $before as $f ) {
            clearstatcache( true, $f );
            if ( ! file_exists( $f ) || filesize( $f ) !== $sizes[ $f ] ) {
                $gone[] = basename( $f );
            }
        }
        et_assert( empty( $gone ), 'missing/changed: ' . implode( ', ', $gone ) );
    }, 'F2' );

    et_test( "convert $label: every rendition has a modern sibling (x.ext.webp)", function () use ( $before ) {
        $missing = array();
        foreach ( $before as $f ) {
            if ( ! file_exists( $f . '.webp' ) ) {
                $missing[] = basename( $f ) . '.webp';
            }
        }
        et_assert( empty( $missing ), 'missing: ' . implode( ', ', $missing ) );
    }, 'F2' );

    et_test( "convert $label: modern files are smaller than their source", function () use ( $id, $sizes ) {
        $orig_total = array_sum( $sizes );
        $new_total  = 0;
        foreach ( et_modern_files( $id ) as $f ) {
            $new_total += filesize( $f );
        }
        et_assert( $new_total > 0, 'no modern files' );
        et_assert( $new_total < $orig_total, "modern $new_total B >= original $orig_total B" );
    }, 'F2' ); // 3.0 keeps a sibling only when it is smaller

    et_test( "convert $label: every previously published URL still answers 200", function () use ( $urls ) {
        $bad = array();
        foreach ( $urls as $u ) {
            $s = et_status( $u );
            if ( ! $s || 200 !== $s['code'] ) {
                $bad[] = basename( $u ) . '=' . ( $s ? $s['code'] : 'ERR' );
            }
        }
        et_assert( empty( $bad ), implode( ', ', $bad ) );
    }, 'F2' );
}

et_test( 'pages using converted images render without broken images', function () use ( $m ) {
    et_assert_page_ok( $m['post']['classic'], 'classic' );
    et_assert_page_ok( $m['post']['misc'], 'misc' );
}, 'F2' );

et_test( 'transparent PNG keeps its transparency', function () use ( $m, $clear_px ) {
    if ( empty( $clear_px ) ) {
        et_skip( 'fixture has no transparent sample pixels' );
    }
    $files = et_modern_files( $m['img']['alpha'] );
    et_assert( ! empty( $files ), 'no modern file' );
    $full = null;
    foreach ( $files as $f ) {
        $info = getimagesize( $f );
        if ( 600 === $info[0] ) {
            $full = $f;
        }
    }
    et_assert( (bool) $full, 'no full-size modern file' );
    $im = imagecreatefromwebp( $full );
    foreach ( $clear_px as $p ) {
        $a = ( imagecolorat( $im, $p[0], $p[1] ) >> 24 ) & 0x7F;
        et_assert( $a > 100, "pixel {$p[0]},{$p[1]} alpha=$a (expected transparent)" );
    }
} );

et_test( 'converting twice is a no-op', function () use ( $m ) {
    $id    = $m['img']['badname'];
    $count = count( et_modern_files( $id ) );
    $res   = et_convert( $id, 'webp' );
    et_assert( in_array( $res, array( 'skipped', 'ok' ), true ), "second convert returned $res" );
    et_eq( $count, count( et_modern_files( $id ) ), 'file count changed' );
} );

et_test( 'restore removes modern files and keeps the site working', function () use ( $m ) {
    $id = $m['img']['badname'];
    et_assert( et_restore( $id ), 'restore returned false' );
    et_eq( array(), et_modern_files( $id ), 'modern files left behind' );
    foreach ( et_attachment_files( $id ) as $f ) {
        et_assert( file_exists( $f ), 'missing after restore: ' . basename( $f ) );
    }
    et_assert_page_ok( $m['post']['classic'] );
}, 'F2' );

et_test( 'animated GIF is never flattened (skipped or converted to animated WebP)', function () use ( $m ) {
    $id   = $m['img']['anim'];
    $orig = get_attached_file( $id );
    $res  = et_convert( $id, 'webp' );
    et_assert( file_exists( $orig ), 'original GIF deleted' );
    if ( 'skipped' === $res ) {
        return;
    }
    foreach ( et_modern_files( $id ) as $f ) {
        et_assert( false !== strpos( file_get_contents( $f ), 'ANIM' ), basename( $f ) . ' lost its animation' ); // phpcs:ignore
    }
}, 'F2' );

et_test( 'lossless mode is pixel-identical', function () use ( $m ) {
    $id  = $m['img']['logo'];
    $src = imagecreatefrompng( get_attached_file( $id ) );
    update_option( 'erdo_lossless', 1 );
    et_eq( 'ok', et_convert( $id, 'webp' ) );
    update_option( 'erdo_lossless', 0 );
    $full = null;
    foreach ( et_modern_files( $id ) as $f ) {
        if ( 400 === getimagesize( $f )[0] ) {
            $full = $f;
        }
    }
    et_assert( (bool) $full, 'no full-size modern file' );
    $out  = imagecreatefromwebp( $full );
    $diff = 0;
    for ( $i = 0; $i < 200; $i++ ) {
        $x = ( $i * 37 ) % 400;
        $y = ( $i * 11 ) % 120;
        if ( ( imagecolorat( $src, $x, $y ) & 0xFFFFFF ) !== ( imagecolorat( $out, $x, $y ) & 0xFFFFFF ) ) {
            $diff++;
        }
    }
    et_eq( 0, $diff, 'differing sample pixels' );
}, 'F2' );

et_test( 'AVIF output is a real, decodable AVIF', function () use ( $m ) {
    if ( ! function_exists( 'imageavif' ) ) {
        et_skip( 'no AVIF encoder on this host' );
    }
    $id = $m['img']['good'];
    et_eq( 'ok', et_convert( $id, 'avif' ) );
    $avif = array_values( array_filter( et_modern_files( $id ), function ( $f ) {
        return '.avif' === substr( $f, -5 );
    } ) );
    et_assert( ! empty( $avif ), 'no .avif file written' );
    foreach ( $avif as $f ) {
        et_assert( filesize( $f ) > 100, basename( $f ) . ' is empty (broken encoder)' );
        $info = getimagesize( $f );
        et_eq( 'image/avif', $info['mime'] ?? null, basename( $f ) );
    }
} );
