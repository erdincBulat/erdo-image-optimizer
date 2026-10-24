<?php
/**
 * Image SEO scoring. Rules follow Google Images guidance + W3C alt-text
 * guidance (see PLAN-3.0.md §4). Scores are compared on a 0–100 scale.
 */

$m = et_fixtures();

et_test( 'a well-named, described image outscores a camera-named one', function () use ( $m ) {
    $good   = et_audit( $m['img']['good'] );
    $camera = et_audit( $m['img']['camera'] );
    et_assert( $good['score'] > $camera['score'], "good={$good['score']} camera={$camera['score']}" );
} );

et_test( 'filename: camera pattern "dsc-0042" fails', function () use ( $m ) {
    et_eq( false, et_audit( $m['img']['dscalt'] )['checks']['filename'] ?? null );
}, 'F4' );

et_test( 'filename: "IMG_1234" fails', function () use ( $m ) {
    et_eq( false, et_audit( $m['img']['camera'] )['checks']['filename'] ?? null );
} );

et_test( 'filename: descriptive hyphenated name passes', function () use ( $m ) {
    et_eq( true, et_audit( $m['img']['good'] )['checks']['filename'] ?? null );
} );

et_test( 'alt: missing alt fails', function () use ( $m ) {
    et_eq( false, et_audit( $m['img']['badname'] )['checks']['alt'] ?? null );
} );

et_test( 'alt: alt that just repeats a camera filename ("Dsc 0042") fails', function () use ( $m ) {
    et_eq( false, et_audit( $m['img']['dscalt'] )['checks']['alt'] ?? null );
}, 'F4' );

et_test( 'alt: "image of ..." phrasing fails', function () use ( $m ) {
    update_post_meta( $m['img']['unused'], '_wp_attachment_image_alt', 'Image of a red car' );
    et_eq( false, et_audit( $m['img']['unused'] )['checks']['alt'] ?? null );
}, 'F4' );

et_test( 'alt: keyword-stuffed alt fails', function () use ( $m ) {
    update_post_meta( $m['img']['dup_b'], '_wp_attachment_image_alt', 'shoes, running shoes, best shoes, cheap shoes, shoes sale, buy shoes online' );
    et_eq( false, et_audit( $m['img']['dup_b'] )['checks']['alt'] ?? null );
}, 'F4' );

et_test( 'alt: a good descriptive alt passes', function () use ( $m ) {
    et_eq( true, et_audit( $m['img']['good'] )['checks']['alt'] ?? null );
} );

et_test( 'title attribute does not affect the score (no SEO weight)', function () use ( $m ) {
    $id     = $m['img']['good'];
    $before = et_audit( $id )['score'];
    wp_update_post( array( 'ID' => $id, 'post_title' => 'Blue_running_shoes' ) );
    $after = et_audit( $id )['score'];
    wp_update_post( array( 'ID' => $id, 'post_title' => 'Blue running shoes' ) );
    et_eq( $before, $after );
}, 'F4' );

et_test( 'format: image with a modern sibling counts as optimised', function () use ( $m ) {
    et_options( array( 'erdo_keep_original' => 0 ) );
    et_convert( $m['img']['acf_id'], 'webp' );
    et_eq( true, et_audit( $m['img']['acf_id'] )['checks']['format'] ?? null );
} );

et_test( 'alt from filename: descriptive name gives usable alt', function () use ( $m ) {
    et_eq( 'Blue Running Shoes', et_alt_from_filename( $m['img']['good'] ) );
} );

et_test( 'alt from filename: camera names are skipped, not turned into "Img 1234"', function () use ( $m ) {
    et_eq( false, et_alt_from_filename( $m['img']['camera'] ) );
}, 'F4' );

update_post_meta( $m['img']['good'], '_wp_attachment_image_alt', 'Blue running shoes on a wooden floor' );

et_test( 'score is on a 0-100 scale and a well-described image scores high', function () use ( $m ) {
    $r = et_audit( $m['img']['good'] );
    et_assert( $r['score'] >= 70 && $r['score'] <= 100, 'score ' . $r['score'] );
} );

et_test( 'alt: a single-word alt (logo) passes', function () use ( $m ) {
    et_eq( true, et_audit( $m['img']['logo'] )['checks']['alt'] ?? null );
} );

et_test( 'alt: empty alt passes once the image is marked decorative', function () use ( $m ) {
    $id = $m['img']['siteicon'];
    et_eq( false, et_audit( $id )['checks']['alt'] ?? null, 'before marking' );
    et_set_decorative( $id );
    et_eq( true, et_audit( $id )['checks']['alt'] ?? null, 'after marking' );
    et_set_decorative( $id, false );
} );

et_test( 'filename: screenshot, WhatsApp, phone and hash names fail', function () use ( $m ) {
    foreach ( array( 'Screenshot-2024-05-01-at-10.32.11', 'WhatsApp-Image-2024-01-01-at-12.00.00', 'PXL_20240101_123456789', '8f14e45fceea167a5a36dedd4bea2543' ) as $name ) {
        et_eq( false, ERDO_SEO::check_filename( $name )['pass'], $name );
    }
} );

et_test( 'context: an image only used in a draft scores lower than a published one', function () use ( $m ) {
    et_index_rebuild();
    $points = function ( $id ) {
        foreach ( et_audit( $id )['raw']['checklist'] as $c ) {
            if ( 'context' === $c['key'] ) {
                return $c;
            }
        }
        return null;
    };
    $draft = $points( $m['img']['draft_only'] );
    $pub   = $points( $m['img']['block'] );
    et_assert( $draft['pass'], 'draft usage still counts as used' );
    et_assert( $draft['points'] < $pub['points'], "draft={$draft['points']} published={$pub['points']}" );
} );

/* --- Image sitemap (core wp-sitemap.xml) --------------------------- */
et_test( 'image sitemap: listed in wp-sitemap.xml with image:loc for published pages only', function () use ( $m ) {
    if ( ! get_option( 'blog_public' ) ) {
        et_skip( 'site is not public, core sitemaps are off' );
    }
    et_index_rebuild();
    $index = et_fetch( home_url( '/wp-sitemap.xml' ) );
    et_assert( false !== strpos( $index, 'wp-sitemap-erdoimages-1.xml' ), 'not in the sitemap index' );
    $xml = et_fetch( home_url( '/wp-sitemap-erdoimages-1.xml' ) );
    et_assert( false !== strpos( $xml, 'xmlns:image=' ), 'no image namespace' );
    et_assert( false !== strpos( $xml, esc_xml( wp_get_attachment_url( $m['img']['block'] ) ) ), 'published block image missing' );
    et_assert( false === strpos( $xml, basename( get_attached_file( $m['img']['draft_only'] ) ) ), 'draft-only image listed' );
    et_assert( false === strpos( $xml, basename( get_attached_file( $m['img']['unused'] ) ) ), 'unused image listed' );
} );
