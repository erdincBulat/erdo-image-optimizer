<?php
// Baseline: reproduces review findings against current trunk. Destructive to fixtures (re-run fixtures.php after).
$m = json_decode( file_get_contents( __DIR__ . '/manifest.json' ), true );
ERDO_Auditor::clear_all_cache();
$names = array_flip( $m );

echo "== USAGE DETECTION (expected: A,B,E,F,H used; C,D,G unused) ==\n";
$r = ERDO_Auditor::bulk_analyze( array( $m['A_badname_fullurl'], $m['B_sized_url_only'], $m['C_animated_gif'], $m['D_oversized'], $m['E_featured'], $m['F_site_logo_option_only'], $m['G_truly_unused'], $m['H_block_used'] ) );
foreach ( $r as $id => $x ) {
    printf( "%-26s id=%d used=%s score=%d/6 status=%s\n", $names[ $id ], $id, $x['is_used'] ? 'YES' : 'no', $x['score'], $x['status'] );
}

echo "\n== STALE CACHE: G cached unused, then inserted into a post ==\n";
$g = $m['G_truly_unused'];
$p = wp_insert_post( array( 'post_title' => 'FX stale', 'post_status' => 'publish', 'post_content' => '<img src="' . wp_get_attachment_url( $g ) . '">' ) );
update_post_meta( $p, '_erdo_fixture', 1 );
$a = ERDO_Auditor::analyze( $g );
echo "after insert into post $p -> is_used=" . var_export( $a['is_used'], true ) . " (cached, should be true)\n";

echo "\n== LOSSLESS FLAG (imagewebp quality -1 vs 80 vs IMG_WEBP_LOSSLESS) ==\n";
$im = imagecreatefromjpeg( get_attached_file( $m['E_featured'] ) );
$sz = function ( $q ) use ( $im ) { ob_start(); imagewebp( $im, null, $q ); return strlen( ob_get_clean() ); };
echo 'q=-1: ' . $sz( -1 ) . ' | q=80: ' . $sz( 80 ) . ' | q=100: ' . $sz( 100 ) . ' | IMG_WEBP_LOSSLESS(' . ( defined( 'IMG_WEBP_LOSSLESS' ) ? IMG_WEBP_LOSSLESS : 'undef' ) . '): ' . ( defined( 'IMG_WEBP_LOSSLESS' ) ? $sz( IMG_WEBP_LOSSLESS ) : 'n/a' ) . "\n";
$av = function ( $q ) use ( $im ) { ob_start(); imageavif( $im, null, $q ); return strlen( ob_get_clean() ); };
echo 'AVIF q=0: ' . $av( 0 ) . ' | q=80: ' . $av( 80 ) . ' | q=100: ' . $av( 100 ) . "\n";

echo "\n== ANIMATED GIF CONVERSION ==\n";
$gif = get_attached_file( $m['C_animated_gif'] );
$before = substr_count( file_get_contents( $gif ), "\x00\x21\xF9\x04" ) + 1;
update_option( 'erdo_convert_format', 'webp' );
$res = ERDO_Auditor::convert_to_webp( $m['C_animated_gif'] );
$out = get_attached_file( $m['C_animated_gif'] );
$webp = file_get_contents( $out );
echo "frames before=$before; result=" . var_export( $res, true ) . "; new file=" . basename( $out ) . '; animated flag(ANIM chunk)=' . (int) ( false !== strpos( $webp, 'ANIM' ) ) . '; orig exists=' . (int) file_exists( $gif ) . "\n";

echo "\n== HTACCESS RULE vs ACTUAL OUTPUT NAME ==\n";
$conv = ERDO_Auditor::convert_to_webp( $m['A_badname_fullurl'] );
$new  = get_attached_file( $m['A_badname_fullurl'] );
echo 'converted -> ' . basename( $new ) . ' ; rule expects ' . basename( $new, '.webp' ) . ".jpg.webp\n";
$post = get_post( $m['post_classic'] );
echo 'classic post still references .jpg: ' . (int) ( false !== strpos( $post->post_content, 'Fx_Bad_Name.jpg' ) ) . ' ; file Fx_Bad_Name.jpg on disk: ' . (int) file_exists( dirname( $new ) . '/Fx_Bad_Name.jpg' ) . "\n";

echo "\n== RENAME: sized URL in content ==\n";
$b  = $m['B_sized_url_only'];
$rn = ERDO_Auditor::rename_file( $b, 'renamed-sized' );
$post = get_post( $m['post_classic'] );
echo is_wp_error( $rn ) ? 'rename error: ' . $rn->get_error_message() : 'renamed to ' . $rn['new_name'], "\n";
echo 'content still has old sized url (fx-sized-only-300x210): ' . (int) ( false !== strpos( $post->post_content, 'fx-sized-only-300x210' ) ) . ' ; old thumb on disk: ' . (int) file_exists( dirname( get_attached_file( $b ) ) . '/fx-sized-only-300x210.png' ) . "\n";

echo "\n== LAZY FILTER vs core (single img, direct call) ==\n";
echo erdo_add_lazy_loading( '<img src="a.jpg" fetchpriority="high">' ) . "\n";
