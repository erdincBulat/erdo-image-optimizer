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
