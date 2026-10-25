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

/* --- Saving other details must not rename -------------------------- */
et_test( 'saving alt text in the detail panel leaves an untouched filename alone', function () use ( $m ) {
    $id = $m['img']['badname'];
    et_save_details( $id, array( 'alt_text' => 'A test image', 'filename' => 'Fx_Bad_Name', 'decorative' => '0' ) );
    et_eq( 'Fx_Bad_Name.jpg', basename( get_attached_file( $id ) ) );
    et_eq( 'A test image', get_post_meta( $id, '_wp_attachment_image_alt', true ) );
} );

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

/* --- WebP/AVIF siblings move with the image ------------------------ */
et_options( array( 'erdo_convert_format' => 'webp' ) );
et_convert( $m['img']['block'], 'webp' );
$block_siblings = et_modern_files( $m['img']['block'] );
$block_sib_url  = wp_get_attachment_url( $m['img']['block'] ) . '.webp';
et_rename( $m['img']['block'], 'renamed-block' );

et_test( 'rename: WebP siblings are moved, not left under the old name', function () use ( $m, $block_siblings ) {
    et_assert( count( $block_siblings ) > 0, 'fixture has no siblings' );
    foreach ( $block_siblings as $f ) {
        et_assert( ! file_exists( $f ), 'old sibling still on disk: ' . basename( $f ) );
    }
    $now = et_modern_files( $m['img']['block'] );
    et_eq( count( $block_siblings ), count( $now ), 'sibling count' );
    foreach ( $now as $f ) {
        et_assert( 0 === strpos( basename( $f ), 'renamed-block' ), 'unexpected sibling ' . basename( $f ) );
    }
    et_eq( 'webp', et_audit( $m['img']['block'] )['raw']['served_format'], 'record no longer finds the sibling' );
    et_assert_page_ok( $m['post']['block'], '', array( 'broken' ) );
} );

et_test( 'rename: old sibling URL (x.jpg.webp) redirects to the new sibling', function () use ( $block_sib_url ) {
    $s = et_status( $block_sib_url );
    et_eq( 301, $s ? $s['code'] : 0 );
    et_assert( false !== strpos( $s['location'], 'renamed-block.jpg.webp' ), 'redirect target: ' . $s['location'] );
} );

/* --- "-scaled" image: main, original and sizes all move ------------- */
$over_before = et_attachment_files( $m['img']['oversized'] );
et_rename( $m['img']['oversized'], 'renamed-oversized' );

et_test( 'rename: -scaled image moves main, original and every size', function () use ( $m, $over_before ) {
    $meta = wp_get_attachment_metadata( $m['img']['oversized'] );
    et_eq( 'renamed-oversized.jpg', $meta['original_image'] ?? '' );
    et_eq( 'renamed-oversized-scaled.jpg', basename( get_attached_file( $m['img']['oversized'] ) ) );
    foreach ( $over_before as $f ) {
        et_assert( ! file_exists( $f ), 'still on disk: ' . basename( $f ) );
    }
    foreach ( et_attachment_files( $m['img']['oversized'] ) as $f ) {
        et_assert( file_exists( $f ), 'missing: ' . basename( $f ) );
    }
    et_assert_page_ok( $m['post']['misc'], '', array( 'broken' ) );
} );

/* --- Redirect chains ------------------------------------------------ */
$header_url = wp_get_attachment_url( $m['img']['header'] );
et_rename( $m['img']['header'], 'chain-b' );
et_rename( $m['img']['header'], 'chain-c' );

et_test( 'redirects: renaming twice sends the first URL straight to the latest name', function () use ( $header_url ) {
    $s = et_status( $header_url );
    et_eq( 301, $s ? $s['code'] : 0 );
    et_assert( false !== strpos( $s['location'], 'chain-c.jpg' ), 'redirect target: ' . $s['location'] );
} );

et_test( 'redirects: a missing upload that was never renamed stays 404', function () use ( $m ) {
    $s = et_status( dirname( wp_get_attachment_url( $m['img']['good'] ) ) . '/never-existed.jpg' );
    et_eq( 404, $s ? $s['code'] : 0 );
} );

/* --- Preview, batch and undo --------------------------------------- */
et_test( 'preview: shows the new name and changes nothing', function () use ( $m ) {
    $id     = $m['img']['gallery_child'];
    $before = get_attached_file( $id );
    $rows   = et_rename_preview( array( $id ), '{parent}-photo' );
    et_eq( 'fx-gallery-photo.jpg', $rows[0]['new'] ?? '' );
    et_eq( $before, get_attached_file( $id ) );
    et_assert( file_exists( $before ), 'file moved by a preview' );
} );

et_test( 'pattern: empty {keyword} falls back to the parent post SEO focus keyword', function () use ( $m ) {
    update_post_meta( $m['post']['gallery'], '_yoast_wpseo_focuskw', 'Kapadokya Balon' );
    $rows = et_rename_preview( array( $m['img']['gallery_child'] ), '{keyword}-{n}' );
    et_eq( 'kapadokya-balon-1.jpg', $rows[0]['new'] ?? '' );
} );

$alpha_old = wp_get_attachment_url( $m['img']['alpha'] );
list( $batch, $batch_res ) = et_rename_batch( array( $m['img']['featured'] => 'batch-featured', $m['img']['alpha'] => 'batch-alpha' ) );
$alpha_new = wp_get_attachment_url( $m['img']['alpha'] );
$undo      = et_rename_undo( $batch );

et_test( 'undo batch: every image gets its old name and the content follows', function () use ( $m, $batch_res, $undo ) {
    foreach ( $batch_res as $r ) {
        et_not_error( $r );
    }
    et_not_error( $undo );
    et_eq( 'fx-featured.jpg', basename( get_attached_file( $m['img']['featured'] ) ) );
    et_eq( 'fx-alpha.png', basename( get_attached_file( $m['img']['alpha'] ) ) );
    et_assert( false !== strpos( et_post_content( $m['post']['misc'] ), 'fx-alpha' ), 'content not restored' );
    et_assert_page_ok( $m['post']['misc'], '', array( 'broken' ) );
} );

et_test( 'undo batch: restored URL is served again, the interim URL redirects back', function () use ( $alpha_old, $alpha_new ) {
    $old = et_status( $alpha_old );
    et_eq( 200, $old ? $old['code'] : 0, 'restored URL' );
    $new = et_status( $alpha_new );
    et_eq( 301, $new ? $new['code'] : 0, 'interim URL' );
    et_assert( false !== strpos( $new['location'], 'fx-alpha.png' ), 'redirect target: ' . $new['location'] );
} );

et_test( 'undo last: single rename can be reverted from the image', function () use ( $m ) {
    et_not_error( et_rename( $m['img']['widget_block'], 'temp-name' ) );
    et_not_error( et_rename_undo_last( $m['img']['widget_block'] ) );
    et_eq( 'fx-widget-block.jpg', basename( get_attached_file( $m['img']['widget_block'] ) ) );
} );

et_test( 'a quarantined image cannot be renamed', function () use ( $m ) {
    $id = $m['img']['attached_only'];
    et_not_error( et_quarantine( $id, false ) );
    et_assert( is_wp_error( et_rename( $id, 'should-not-happen' ) ), 'rename accepted' );
    et_not_error( et_unquarantine( $id ) );
} );

et_test( 'undo restores the exact old name, even one that is not "clean" (IMG_1234)', function () use ( $m ) {
    // The camera fixture was renamed IMG_1234.JPG → img-1234.jpg by "fix filename" above.
    et_not_error( et_rename_undo_last( $m['img']['camera'] ) );
    et_eq( 'IMG_1234', pathinfo( get_attached_file( $m['img']['camera'] ), PATHINFO_FILENAME ) );
    et_assert_page_ok( $m['post']['misc'], '', array( 'broken' ) );
} );
