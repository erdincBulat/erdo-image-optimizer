<?php
/**
 * Runs with the REAL 2.2.2 plugin active (tags/2.2.2): uploads images, uses them
 * in posts and converts them with 2.2.2's own converter, which deletes the
 * original and switches the attachment to .webp. upgrade.ps1 then swaps in 3.0
 * and runs upgrade-check.php.
 */
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

wp_set_current_user( 1 );

function erdo_upg_image( $name, $w, $h, $seed, $png = false ) {
    mt_srand( $seed );
    $im = imagecreatetruecolor( $w, $h );
    for ( $y = 0; $y < $h; $y += 8 ) {
        for ( $x = 0; $x < $w; $x += 8 ) {
            imagefilledrectangle( $im, $x, $y, $x + 7, $y + 7, imagecolorallocate( $im, mt_rand( 0, 255 ), mt_rand( 0, 255 ), mt_rand( 0, 255 ) ) );
        }
    }
    $tmp = wp_tempnam( $name );
    $png ? imagepng( $im, $tmp ) : imagejpeg( $im, $tmp, 90 );
    $id = media_handle_sideload( array( 'name' => $name, 'tmp_name' => $tmp ), 0 );
    if ( is_wp_error( $id ) ) {
        exit( 'sideload failed: ' . $id->get_error_message() . "\n" );
    }
    return $id;
}

function erdo_upg_block( $id, $size ) {
    return '<!-- wp:image {"id":' . $id . ',"sizeSlug":"' . $size . '"} --><figure class="wp-block-image size-' . $size . '"><img src="'
        . esc_url( wp_get_attachment_image_url( $id, $size ) ) . '" alt="" class="wp-image-' . $id . '"/></figure><!-- /wp:image -->';
}

$ids = array(
    'backup'   => erdo_upg_image( 'upgrade-backup.jpg', 1600, 1000, 1 ),   // converted with "keep original"
    'nobackup' => erdo_upg_image( 'upgrade-nobackup.jpg', 1200, 800, 2 ),  // converted without a backup
    'png'      => erdo_upg_image( 'upgrade-featured.png', 800, 600, 3, true ),
    'classic'  => erdo_upg_image( 'upgrade-classic.jpg', 1000, 700, 4 ),
);

$posts = array(
    'block'    => wp_insert_post( array( 'post_status' => 'publish', 'post_title' => 'Upgrade block', 'post_content' => erdo_upg_block( $ids['backup'], 'large' ) . erdo_upg_block( $ids['nobackup'], 'medium' ) ) ),
    'classic'  => wp_insert_post( array( 'post_status' => 'publish', 'post_title' => 'Upgrade classic', 'post_content' => '<p>Text</p><img src="' . esc_url( wp_get_attachment_url( $ids['classic'] ) ) . '" alt="">' ) ),
    'featured' => wp_insert_post( array( 'post_status' => 'publish', 'post_title' => 'Upgrade featured', 'post_content' => '<p>Featured only.</p>' ) ),
);
set_post_thumbnail( $posts['featured'], $ids['png'] );

foreach ( $ids as $key => $id ) {
    update_option( 'erdo_keep_original', 'backup' === $key ? 1 : 0 );
    $r = ERDO_Auditor::convert_to_webp( $id, true );
    echo $key, ' #', $id, ' -> ', is_wp_error( $r ) ? $r->get_error_message() : var_export( $r, true ), ' ', basename( get_attached_file( $id ) ), "\n";
}
update_option( 'erdo_upg', array( 'ids' => $ids, 'posts' => $posts ), false );
echo 'version ', ERDO_VERSION, "\n";
