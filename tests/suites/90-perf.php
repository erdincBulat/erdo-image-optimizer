<?php
/**
 * Performance on a large synthetic library (opt-in: run.ps1 -Perf).
 * Builds N tiny attachments and N/2 posts referencing half of them, then
 * times a cold full-library usage scan and a warm 100-image grid page.
 * Override size: run.ps1 -Perf -SuiteArgs n=5000
 */

$n = isset( $et_opts['n'] ) ? max( 100, (int) $et_opts['n'] ) : 2000;

$dir = wp_upload_dir()['path'] . '/etperf' . gmdate( 'His' );
wp_mkdir_p( $dir );
update_option( ET_FIXTURE_DIRS, array_merge( (array) get_option( ET_FIXTURE_DIRS, array() ), array( $dir ) ), false );
$tiny = "$dir/seed.jpg";
imagejpeg( et_noise( 16, 16, 1 ), $tiny, 70 );

wp_defer_term_counting( true );
$t0  = microtime( true );
$ids = array();
for ( $i = 0; $i < $n; $i++ ) {
    $f = "$dir/perf-image-$i.jpg";
    copy( $tiny, $f );
    $id = wp_insert_attachment( array( 'post_mime_type' => 'image/jpeg', 'post_title' => "perf $i", 'post_status' => 'inherit' ), $f );
    update_post_meta( $id, ET_FIXTURE_META, 1 );
    update_post_meta( $id, '_wp_attachment_metadata', array( 'width' => 16, 'height' => 16, 'file' => _wp_relative_upload_path( $f ), 'sizes' => array(), 'filesize' => filesize( $f ) ) );
    $ids[] = $id;
}
for ( $i = 0; $i < $n; $i += 2 ) {
    et_add_post( "perf post $i", et_block_image( $ids[ $i ], 'full' ) . '<p>' . str_repeat( 'lorem ipsum ', 200 ) . '</p>' );
}
wp_defer_term_counting( false );
ET::info( sprintf( 'built %d images + %d posts in %.1fs', $n, (int) ceil( $n / 2 ), microtime( true ) - $t0 ) );

$t0   = microtime( true );
$used = et_bulk_used( $ids );
$cold = microtime( true ) - $t0;
ET::info( sprintf( 'cold full-library usage scan: %.2fs (%.1f ms/image)', $cold, 1000 * $cold / $n ) );

$page = array_slice( $ids, 0, 100 );
ERDO_Auditor::bulk_analyze( $page );
$t0 = microtime( true );
ERDO_Auditor::bulk_analyze( $page );
$warm = microtime( true ) - $t0;
ET::info( sprintf( 'warm 100-image grid page: %.0f ms', 1000 * $warm ) );

et_test( 'usage verdicts are correct at scale (even = used, odd = unused)', function () use ( $ids, $used ) {
    $wrong = 0;
    foreach ( $ids as $i => $id ) {
        if ( ( 0 === $i % 2 ) !== $used[ $id ] ) {
            $wrong++;
        }
    }
    et_eq( 0, $wrong, 'wrong verdicts' );
} );

et_test( 'cold full-library scan under 10 ms/image', function () use ( $cold, $n ) {
    et_assert( 1000 * $cold / $n < 10, sprintf( '%.1f ms/image', 1000 * $cold / $n ) );
}, 'F3' );

et_test( 'warm 100-image grid page under 300 ms', function () use ( $warm ) {
    et_assert( $warm < 0.3, sprintf( '%.0f ms', 1000 * $warm ) );
} );
