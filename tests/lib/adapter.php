<?php
/**
 * Adapter between the suites and the plugin's API.
 *
 * Suites describe BEHAVIOUR ("is this image used?", "convert it", "what files
 * does it have now?") and only call the functions below. When the internals
 * change, only this file changes — the suites keep guarding the same
 * behaviour. Mapped to the 3.0 API (ERDO_Converter / ERDO_Migration) since Faz 2.
 */

/** Fresh usage verdict (all caches dropped first). */
function et_is_used( $id ) {
    ERDO_Auditor::clear_all_cache();
    return (bool) ERDO_Auditor::check_usage( $id );
}

/** Fresh usage verdicts for many ids, through the bulk path the UI uses. */
function et_bulk_used( array $ids ) {
    ERDO_Auditor::clear_all_cache();
    $out = array();
    foreach ( ERDO_Auditor::bulk_analyze( $ids ) as $id => $r ) {
        $out[ $id ] = (bool) $r['is_used'];
    }
    return $out;
}

/** Cached usage verdict, as the dashboard grid would show it right now. */
function et_cached_used( $id ) {
    $r = ERDO_Auditor::analyze( $id );
    return (bool) ( $r['is_used'] ?? false );
}

/**
 * SEO audit normalised to array( 'score' => 0-100, 'checks' => key => bool ).
 * Canonical keys: alt, filename, title, dimensions, format, filesize.
 * 2.2.2 scores 0-6; that is scaled so suites can assert on one scale.
 */
function et_audit( $id ) {
    $keys = array(
        'Alt Text'    => 'alt',
        'Filename'    => 'filename',
        'Image Title' => 'title',
        'Dimensions'  => 'dimensions',
        'Format'      => 'format',
        'File Size'   => 'filesize',
    );
    ERDO_Auditor::clear_cache( $id );
    $r      = ERDO_Auditor::analyze( $id );
    $checks = array();
    foreach ( (array) ( $r['checklist'] ?? array() ) as $c ) {
        $checks[ $keys[ $c['label'] ] ?? $c['label'] ] = (bool) $c['pass'];
    }
    $max = max( 1, count( $checks ) );
    return array(
        'score'  => (int) round( ( (int) ( $r['score'] ?? 0 ) / $max ) * 100 ),
        'checks' => $checks,
        'raw'    => $r,
    );
}

/**
 * Converts one attachment. Returns 'ok' | 'larger' (every copy would be bigger
 * than its source, so none kept) | 'skipped' | 'error'.
 */
function et_convert( $id, $format = 'webp' ) {
    update_option( 'erdo_convert_format', $format );
    $r = ERDO_Converter::convert( $id );
    return is_wp_error( $r ) ? 'error' : $r['status'];
}

/** Encodes one file directly (encoder behaviour, bypassing the keep-if-smaller policy). */
function et_encode( $src, $dest, $format, array $opts = array() ) {
    return ERDO_Encoder::encode( $src, $dest, $format, $opts );
}

/** Basenames whose copy was dropped because it was not smaller than the source. */
function et_larger_skips( $id, $format = 'webp' ) {
    return ERDO_Converter::record( $id )['larger'][ $format ] ?? array();
}

/** Undoes conversion. Returns bool. */
function et_restore( $id ) {
    return (bool) ERDO_Converter::revert( $id );
}

/** Re-detects images damaged by 2.x (turns the rescue filter on/off). Returns count. */
function et_detect_legacy() {
    return ERDO_Migration::refresh_legacy_flag();
}

/** IDs of images damaged by 2.x that still await repair. */
function et_legacy_ids() {
    return ERDO_Migration::find_legacy();
}

/** Dry-run plan for one damaged image: array( mode => restore|repoint, ... ). */
function et_migration_plan( $id ) {
    return ERDO_Migration::plan( $id );
}

/** Repairs every damaged image. Returns totals. */
function et_migrate_all() {
    $t = array( 'restore' => 0, 'repoint' => 0, 'errors' => array() );
    do {
        $r = ERDO_Migration::run_batch( 25 );
        $t['restore'] += $r['restore'];
        $t['repoint'] += $r['repoint'];
        $t['errors']  += $r['errors'];
    } while ( $r['remaining'] > 0 && ( $r['restore'] + $r['repoint'] + count( $r['errors'] ) ) > 0 );
    return $t;
}

/** Renames an attachment's files. Returns array|WP_Error. */
function et_rename( $id, $name = null ) {
    return ERDO_Auditor::rename_file( $id, $name );
}

/** Generates alt text from the filename. Returns the alt that was saved, or false. */
function et_alt_from_filename( $id ) {
    delete_post_meta( $id, '_wp_attachment_image_alt' );
    ERDO_Auditor::set_alt_from_filename( $id );
    $alt = get_post_meta( $id, '_wp_attachment_image_alt', true );
    return '' === $alt ? false : $alt;
}

/**
 * Every WebP/AVIF file on disk that belongs to the attachment, whether it is
 * the main file (2.2.2 replace model) or a sibling (3.0 model).
 */
function et_modern_files( $id ) {
    $out = array();
    foreach ( et_attachment_files( $id ) as $f ) {
        if ( preg_match( '/\.(webp|avif)$/i', $f ) && file_exists( $f ) ) {
            $out[] = $f;
        }
        foreach ( array( '.webp', '.avif' ) as $ext ) {
            if ( file_exists( $f . $ext ) ) { // sibling: x.jpg.webp
                $out[] = $f . $ext;
            }
        }
    }
    return array_values( array_unique( $out ) );
}

/**
 * Bulk rename with a pattern, e.g. '{keyword}-{n}' (3.0, PLAN E/F4).
 * Returns array( id => new basename ) | WP_Error.
 */
function et_rename_pattern( array $ids, $pattern, array $vars = array() ) {
    return new WP_Error( 'not_implemented', 'pattern rename arrives in 3.0 (F4)' );
}

/** The .htaccess block the plugin writes for Apache WebP/AVIF delivery. */
function et_htaccess_rules() {
    $file = trailingslashit( wp_upload_dir()['basedir'] ) . '.htaccess';
    $had  = file_exists( $file ) ? file_get_contents( $file ) : null; // phpcs:ignore
    ERDO_WebP_Server::write_htaccess_rules();
    $now = file_get_contents( $file ); // phpcs:ignore
    null === $had ? wp_delete_file( $file ) : file_put_contents( $file, $had ); // phpcs:ignore
    return $now;
}

/** Runs the upload pipeline (all hooks active) on a copy of $src. Returns attachment id. */
function et_upload( $src, $name ) {
    $tmp = wp_tempnam( $name );
    copy( $src, $tmp );
    $id = media_handle_sideload( array( 'name' => $name, 'tmp_name' => $tmp ), 0 );
    if ( ! is_wp_error( $id ) ) {
        update_post_meta( $id, ET_FIXTURE_META, 1 );
    }
    return $id;
}
