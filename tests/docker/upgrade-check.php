<?php
/**
 * Runs after 3.0 replaced 2.2.2 (see upgrade.ps1). stage=before: the upgrade
 * routine ran and the rescue filter keeps pages working before any repair.
 * stage=after: `wp erdo migrate` restored or repointed every damaged image.
 * Prints the harness protocol, so upgrade.ps1 output reads like a suite.
 */
require_once '/erdo-tests/lib/harness.php';
require_once '/erdo-tests/lib/http.php';

$stage     = isset( $args[0] ) ? $args[0] : 'before';
ET::$suite = 'upgrade-' . $stage;
$u         = get_option( 'erdo_upg' );
$ids       = $u['ids'];
$posts     = $u['posts'];

$broken = function () use ( $posts ) {
    $all = array();
    foreach ( $posts as $key => $pid ) {
        foreach ( et_page_image_problems( get_permalink( $pid ) ) as $p ) {
            if ( 0 === strpos( $p, 'broken' ) ) {
                $all[] = $key . ': ' . $p;
            }
        }
    }
    return $all;
};

if ( 'before' === $stage ) {
    et_test( '2.2.2 really converted destructively (attachments switched to .webp)', function () use ( $ids ) {
        foreach ( array( 'nobackup', 'classic' ) as $k ) {
            $file = get_attached_file( $ids[ $k ] );
            et_assert( '.webp' === substr( $file, -5 ), $k . ' is not on .webp: ' . basename( $file ) );
        }
    } );
    et_test( 'upgrade routine ran on first load (DB version, damaged images counted)', function () {
        et_eq( ERDO_Migration::DB_VERSION, (int) get_option( 'erdo_db_version' ) );
        et_assert( (int) get_option( ERDO_Migration::LEGACY_OPTION ) >= 3, 'erdo_legacy_present = ' . get_option( ERDO_Migration::LEGACY_OPTION ) );
    } );
    et_test( 'before repair: the rescue filter keeps every page free of broken images', function () use ( $broken ) {
        $b = $broken();
        et_assert( ! $b, implode( '; ', $b ) );
    } );
} else {
    et_test( 'after migrate: no damaged images left', function () {
        et_eq( 0, (int) get_option( ERDO_Migration::LEGACY_OPTION ) );
        et_eq( array(), ERDO_Migration::find_legacy() );
    } );
    et_test( 'after migrate: pages have no broken images', function () use ( $broken ) {
        $b = $broken();
        et_assert( ! $b, implode( '; ', $b ) );
    } );
    et_test( 'after migrate: the backed-up image is a JPEG again, with WebP siblings and no backup folder left', function () use ( $ids ) {
        $file = get_attached_file( $ids['backup'] );
        et_assert( '.jpg' === substr( $file, -4 ) && file_exists( $file ), 'attached file ' . basename( $file ) );
        et_assert( file_exists( $file . '.webp' ), 'no ' . basename( $file ) . '.webp sibling' );
        et_assert( ! file_exists( dirname( $file ) . '/_erdo_backup/' . basename( $file ) ), 'backup still there' );
    } );
    et_test( 'after migrate: content of the no-backup image points at existing files', function () use ( $ids, $posts ) {
        $content = get_post_field( 'post_content', $posts['block'] );
        et_assert( false === strpos( $content, 'upgrade-nobackup.jpg' ) && false === strpos( $content, 'upgrade-nobackup-' . '300x200.jpg' ), 'content still links the deleted JPEG' );
        et_assert( file_exists( get_attached_file( $ids['nobackup'] ) ), 'attached file missing' );
    } );
    et_test( 'after migrate: the usage index and the SEO audit see every image', function () use ( $ids ) {
        foreach ( $ids as $k => $id ) {
            et_assert( ERDO_Usage::is_used( $id ), $k . ' not used' );
        }
        $a = ERDO_Auditor::bulk_analyze( array_values( $ids ) );
        et_eq( count( $ids ), count( $a ), 'audit rows' );
    } );
}
ET::$finished = true;
echo 'DONE|' . ET::$suite . '|' . ET::$counts['PASS'] . '|' . ET::$counts['FAIL'] . '|' . ET::$counts['SKIP'] . "|1\n";
