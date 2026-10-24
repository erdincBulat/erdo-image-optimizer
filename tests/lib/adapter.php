<?php
/**
 * Adapter between the suites and the plugin's API.
 *
 * Suites describe BEHAVIOUR ("is this image used?", "convert it", "what files
 * does it have now?") and only call the functions below. When the internals
 * change, only this file changes — the suites keep guarding the same
 * behaviour. Mapped to the 3.0 API (ERDO_Converter / ERDO_Migration) since Faz 2.
 */

/** Builds the usage index from scratch (synchronously). */
function et_index_rebuild() {
    ERDO_Usage::rebuild();
}

/** Usage verdict straight from the index (built first if needed). */
function et_is_used( $id ) {
    if ( ! ERDO_Usage::is_ready() ) {
        ERDO_Usage::rebuild();
    }
    return (bool) ERDO_Usage::is_used( $id );
}

/** Where the index says an image is used: list of human-readable labels. */
function et_usage_labels( $id ) {
    return wp_list_pluck( ERDO_Usage::sources( $id ), 'label' );
}

/** Fresh usage verdicts for many ids, through the bulk path the UI uses. */
function et_bulk_used( array $ids ) {
    if ( ! ERDO_Usage::is_ready() ) {
        ERDO_Usage::rebuild();
    }
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
 * Canonical keys: alt, filename, title, dimensions, format, filesize, context.
 * Since Faz 4 the plugin scores 0-100 itself and every row carries its key.
 */
function et_audit( $id ) {
    ERDO_Auditor::clear_cache( $id );
    $r      = ERDO_Auditor::analyze( $id );
    $checks = array();
    foreach ( (array) ( $r['checklist'] ?? array() ) as $c ) {
        $checks[ $c['key'] ] = (bool) $c['pass'];
    }
    return array(
        'score'  => (int) ( $r['score'] ?? 0 ),
        'checks' => $checks,
        'raw'    => $r,
    );
}

/** Unused images the bulk cleanup would offer. */
function et_unused_ids() {
    return ERDO_Usage::unused_ids();
}

/** Moves an image to quarantine. $strict = bulk-mode checks. Returns array|WP_Error. */
function et_quarantine( $id, $strict = true ) {
    return ERDO_Quarantine::quarantine( $id, $strict );
}

/** Brings an image back from quarantine. Returns true|WP_Error. */
function et_unquarantine( $id ) {
    return ERDO_Quarantine::restore( $id );
}

/** Permanently deletes a quarantined image. Returns true|WP_Error. */
function et_purge( $id ) {
    return ERDO_Quarantine::purge( $id );
}

/** IDs currently in quarantine. */
function et_quarantined_ids() {
    return ERDO_Quarantine::ids();
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
 * Bulk rename with a pattern, e.g. '{keyword}-{n}' (PLAN F4).
 * Returns array( id => new basename ) | WP_Error.
 */
function et_rename_pattern( array $ids, $pattern, array $vars = array() ) {
    $out = ERDO_Renamer::rename_pattern( $ids, $pattern, $vars );
    foreach ( $out as $r ) {
        if ( is_wp_error( $r ) ) {
            return $r;
        }
    }
    return $out;
}

/** Dry-run of a bulk rename: list of array( id, old, new, error ). Changes nothing. */
function et_rename_preview( array $ids, $pattern = null, array $vars = array() ) {
    return ERDO_Renamer::preview( $ids, $pattern, $vars );
}

/** Applies id => new stem as one undoable batch. Returns array( batch_id, results ). */
function et_rename_batch( array $items ) {
    $batch = ERDO_Renamer::new_batch_id();
    return array( $batch, ERDO_Renamer::apply_items( $items, $batch ) );
}

/** Undoes a whole rename batch. Returns array|WP_Error. */
function et_rename_undo( $batch_id ) {
    return ERDO_Renamer::undo_batch( $batch_id );
}

/** Undoes the last rename of one image. Returns array|WP_Error. */
function et_rename_undo_last( $id ) {
    return ERDO_Renamer::undo_last( $id );
}

/** Saves the detail-panel form (alt_text, title, filename, decorative) as the UI does. */
function et_save_details( $id, array $data ) {
    return ERDO_Auditor::save_image_data( $id, $data );
}

/** Marks an image as decorative (empty alt is then correct). */
function et_set_decorative( $id, $on = true ) {
    $on ? update_post_meta( $id, '_erdo_decorative', 1 ) : delete_post_meta( $id, '_erdo_decorative' );
    ERDO_Auditor::clear_cache( $id );
}

/* --- AI suggestions (Faz 5) ----------------------------------------
 * The Studio site has no AI provider or key, so the provider call is
 * replaced through the plugin's `erdo_ai_pre_generate` filter. Every request
 * the plugin would have sent is captured in $GLOBALS['et_ai_requests'].
 */

/** Real availability (no stub): array( ready, reason, message ). */
function et_ai_status() {
    delete_transient( 'erdo_ai_status' );
    return ERDO_AI::status( true );
}

/** Pretends a provider is connected and answers with $answer (array → JSON, string as is, callable($request)). */
function et_ai_stub( $answer ) {
    et_ai_unstub();
    $GLOBALS['et_ai_requests'] = array();
    $GLOBALS['et_ai_status_cb'] = function ( $s ) {
        return array_merge( $s, array( 'ready' => true, 'reason' => '', 'message' => '' ) );
    };
    $GLOBALS['et_ai_answer_cb'] = function ( $raw, $request ) use ( $answer ) {
        $GLOBALS['et_ai_requests'][] = $request;
        $a = is_callable( $answer ) ? $answer( $request ) : $answer;
        return is_array( $a ) ? wp_json_encode( $a ) : $a;
    };
    add_filter( 'erdo_ai_status', $GLOBALS['et_ai_status_cb'] );
    add_filter( 'erdo_ai_pre_generate', $GLOBALS['et_ai_answer_cb'], 10, 2 );
}

function et_ai_unstub() {
    if ( ! empty( $GLOBALS['et_ai_status_cb'] ) ) {
        remove_filter( 'erdo_ai_status', $GLOBALS['et_ai_status_cb'] );
        remove_filter( 'erdo_ai_pre_generate', $GLOBALS['et_ai_answer_cb'], 10 );
    }
}

/** Last request the plugin would have sent to the provider. */
function et_ai_last_request() {
    $r = $GLOBALS['et_ai_requests'] ?? array();
    return $r ? end( $r ) : null;
}

/** Asks for a suggestion (stored for review, not applied). Returns array|WP_Error. */
function et_ai_suggest( $id ) {
    return ERDO_AI::suggest( $id );
}

function et_ai_suggestion( $id ) {
    return ERDO_AI::get( $id );
}

function et_ai_accept( $id, array $fields = array( 'alt' ), array $edits = array() ) {
    return ERDO_AI::accept( $id, $fields, $edits );
}

function et_ai_reject( $id ) {
    return ERDO_AI::reject( $id );
}

function et_ai_pending() {
    return ERDO_AI::pending_ids();
}

/** Images a bulk run would send ('missing' alt or also 'weak' alt). */
function et_ai_candidates( $scope = 'missing' ) {
    return ERDO_AI::candidate_ids( $scope );
}

/** The image as it would be sent: array( mime, width, height, bytes, data ). */
function et_ai_image( $id ) {
    return ERDO_AI::image_payload( $id );
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
