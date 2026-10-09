<?php
// Erdo test fixtures. Run: studio wp eval-file <this>   (idempotent; cleans prior fixtures)
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';

echo "ENV php=" . PHP_VERSION . " wp=" . get_bloginfo( 'version' )
    . " gd=" . (int) extension_loaded( 'gd' )
    . " webp=" . (int) function_exists( 'imagewebp' )
    . " avif=" . (int) function_exists( 'imageavif' )
    . " imagick=" . (int) extension_loaded( 'imagick' )
    . " os=" . PHP_OS_FAMILY . "\n";

// ---- cleanup previous fixtures
foreach ( get_posts( array( 'post_type' => array( 'post', 'page', 'attachment' ), 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => '_erdo_fixture', 'fields' => 'ids' ) ) as $old ) {
    wp_delete_post( $old, true );
}
remove_theme_mod( 'custom_logo' );

$dir = wp_upload_dir()['path'] . '/fx' . date('His'); wp_mkdir_p( $dir ); // unique dir: Windows keeps delete-pending handles
$ids = array();

function fx_noise( $w, $h, $seed ) {
    mt_srand( $seed );
    $im = imagecreatetruecolor( $w, $h );
    for ( $i = 0; $i < 400; $i++ ) {
        $c = imagecolorallocate( $im, mt_rand( 0, 255 ), mt_rand( 0, 255 ), mt_rand( 0, 255 ) );
        imagefilledrectangle( $im, mt_rand( 0, $w ), mt_rand( 0, $h ), mt_rand( 0, $w ), mt_rand( 0, $h ), $c );
    }
    return $im;
}

function fx_add( $file, $title, $alt = '' ) {
    global $ids;
    $mime = wp_check_filetype( $file )['type'];
    $id   = wp_insert_attachment( array( 'post_mime_type' => $mime, 'post_title' => $title, 'post_status' => 'inherit' ), $file );
    update_post_meta( $id, '_erdo_fixture', 1 );
    if ( $alt ) { update_post_meta( $id, '_wp_attachment_image_alt', $alt ); }
    wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $file ) );
    return $id;
}

// A: bad filename + no alt, jpg (also used as full-size URL in classic post)
$f = "$dir/Fx_Bad_Name.jpg"; imagejpeg( fx_noise( 1200, 800, 1 ), $f, 90 );
$ids['A_badname_fullurl'] = fx_add( $f, 'Fx_Bad_Name' );

// B: png w/ alt, used ONLY via sized (-300xN) URL in classic post
$f = "$dir/fx-sized-only.png"; imagepng( fx_noise( 1000, 700, 2 ), $f );
$ids['B_sized_url_only'] = fx_add( $f, 'fx sized only', 'Sized only image' );

// C: animated GIF (2 frames)
$mk = function ( $color ) {
    $im = imagecreate( 40, 40 );
    imagecolorallocate( $im, 255, 255, 255 );
    imagecolorallocate( $im, 255, 0, 0 );
    imagecolorallocate( $im, 0, 0, 255 );
    imagefilledrectangle( $im, 5, 5, 35, 35, $color );
    ob_start(); imagegif( $im ); return ob_get_clean();
};
$g1 = $mk( 1 ); $g2 = $mk( 2 );
$pf = ord( $g1[10] ); $gct = 3 * ( 1 << ( ( $pf & 7 ) + 1 ) );
$head = substr( $g1, 0, 13 + $gct );
$strip = function ( $g ) use ( $gct ) {
    $r = substr( $g, 13 + $gct, -1 );
    if ( "\x21\xF9" === substr( $r, 0, 2 ) ) { $r = substr( $r, 8 ); }
    return $r;
};
$gce = "\x21\xF9\x04\x00\x32\x00\x00\x00"; // 0.5s delay
$anim = $head . "\x21\xFF\x0BNETSCAPE2.0\x03\x01\x00\x00\x00" . $gce . $strip( $g1 ) . $gce . $strip( $g2 ) . "\x3B";
$f = "$dir/fx-animated.gif"; file_put_contents( $f, $anim );
echo 'GIF frames(GCE count)=' . substr_count( $anim, "\x00\x21\xF9\x04" ) + 1 . " valid=" . (int) ( @imagecreatefromgif( $f ) !== false ) . "\n";
$ids['C_animated_gif'] = fx_add( $f, 'fx animated', 'Animated' );

// D: oversized jpg (3000x2000), alt present
$f = "$dir/fx-oversized.jpg"; imagejpeg( fx_noise( 3000, 2000, 4 ), $f, 92 );
$ids['D_oversized'] = fx_add( $f, 'fx oversized', 'Oversized' );

// E: featured image of a post
$f = "$dir/fx-featured.jpg"; imagejpeg( fx_noise( 900, 600, 5 ), $f, 85 );
$ids['E_featured'] = fx_add( $f, 'fx featured', 'Featured' );

// F: site logo (referenced only in options/theme_mods)
$f = "$dir/fx-site-logo.png"; imagepng( fx_noise( 400, 120, 6 ), $f );
$ids['F_site_logo_option_only'] = fx_add( $f, 'fx site logo', 'Logo' );
set_theme_mod( 'custom_logo', $ids['F_site_logo_option_only'] );

// G: truly unused
$f = "$dir/fx-truly-unused.jpg"; imagejpeg( fx_noise( 800, 500, 7 ), $f, 85 );
$ids['G_truly_unused'] = fx_add( $f, 'fx truly unused', 'Unused' );

// H: Gutenberg block usage (control: should be detected as used)
$f = "$dir/fx-block-used.jpg"; imagejpeg( fx_noise( 800, 500, 8 ), $f, 85 );
$ids['H_block_used'] = fx_add( $f, 'fx block used', 'Block' );

// ---- posts
$full  = wp_get_attachment_url( $ids['A_badname_fullurl'] );
$sized = wp_get_attachment_image_url( $ids['B_sized_url_only'], 'medium' );
$mkpost = function ( $title, $content ) {
    $p = wp_insert_post( array( 'post_title' => $title, 'post_content' => $content, 'post_status' => 'publish' ) );
    update_post_meta( $p, '_erdo_fixture', 1 );
    return $p;
};
$ids['post_classic'] = $mkpost( 'FX Classic', '<p>x</p><img src="' . $full . '" alt="">' . '<img class="wp-image-' . $ids['B_sized_url_only'] . '" src="' . $sized . '" alt="">' );
$url_h = wp_get_attachment_url( $ids['H_block_used'] );
$ids['post_block'] = $mkpost( 'FX Block', '<!-- wp:image {"id":' . $ids['H_block_used'] . ',"sizeSlug":"full"} --><figure class="wp-block-image size-full"><img src="' . $url_h . '" alt="" class="wp-image-' . $ids['H_block_used'] . '"/></figure><!-- /wp:image -->' );
$ids['post_featured'] = $mkpost( 'FX Featured', '<p>featured test</p>' );
set_post_thumbnail( $ids['post_featured'], $ids['E_featured'] );

echo "SIZED_URL=$sized\n";
echo wp_json_encode( $ids, JSON_PRETTY_PRINT ) . "\n";
file_put_contents( __DIR__ . '/manifest.json', wp_json_encode( $ids ) );
