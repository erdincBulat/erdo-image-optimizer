<?php
/**
 * Usage detection — the safety net under "delete unused". A false "unused"
 * means a user can delete an image their site still shows (data loss);
 * a false "used" only means a missed cleanup. Target: zero false "unused".
 */

$m        = et_fixtures();
$expected = et_expected_usage();

// The two directions get different phase labels: false "unused" is the
// dangerous one and is fixed by the usage index (F3).
foreach ( $expected as $name => $used ) {
    et_test( sprintf( 'usage: %s is %s', $name, $used ? 'used' : 'unused' ), function () use ( $m, $name, $used ) {
        et_eq( $used, et_is_used( $m['img'][ $name ] ) );
    }, 'F3' );
}

et_test( 'bulk path agrees with single path', function () use ( $m ) {
    $ids  = array_values( $m['img'] );
    $bulk = et_bulk_used( $ids );
    $diff = array();
    foreach ( $m['img'] as $name => $id ) {
        if ( et_is_used( $id ) !== ( $bulk[ $id ] ?? null ) ) {
            $diff[] = $name;
        }
    }
    et_assert( empty( $diff ), 'bulk vs single disagree for: ' . implode( ', ', $diff ) );
} );

et_test( 'cache: image inserted into a post flips to used without manual refresh', function () use ( $m ) {
    $id = $m['img']['unused'];
    et_is_used( $id );
    ERDO_Auditor::analyze( $id ); // warm the grid cache as "unused"
    et_add_post( 'FX Late use', et_block_image( $id ) );
    et_eq( true, et_cached_used( $id ), 'grid still shows stale "unused"' );
}, 'F3' );

et_test( 'cache: setting a featured image flips to used immediately', function () use ( $m ) {
    $id = $m['img']['attached_only'];
    ERDO_Auditor::analyze( $id );
    set_post_thumbnail( $m['post']['good'], $id );
    et_eq( true, et_cached_used( $id ) );
} );

et_test( 'cache: removing the only reference flips to unused', function () use ( $m ) {
    $id = $m['img']['featured'];
    et_assert( et_cached_used( $id ), 'precondition: featured is used' );
    delete_post_thumbnail( $m['post']['featured'] );
    et_eq( false, et_cached_used( $id ) );
}, 'F3' );

et_test( 'cache: logo set via Customizer flips to used', function () use ( $m ) {
    $id = $m['img']['dup_b'];
    remove_theme_mod( 'custom_logo' );
    ERDO_Auditor::analyze( $id );
    set_theme_mod( 'custom_logo', $id );
    et_eq( true, et_cached_used( $id ) );
}, 'F3' );
