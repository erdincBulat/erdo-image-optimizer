<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { return; }

/**
 * Erdo Image Optimizer WP-CLI commands.
 *
 * ## EXAMPLES
 *
 *   wp erdo convert
 *   wp erdo convert --id=42
 *   wp erdo convert --year=2024 --limit=100
 *   wp erdo audit --format=csv > audit.csv
 *   wp erdo restore --id=42
 *   wp erdo stats
 *   wp erdo migrate --dry-run
 *   wp erdo clean-cache
 */
class ERDO_CLI {

    /* ------------------------------------------------------------------
       wp erdo convert
    ------------------------------------------------------------------ */

    /**
     * Create WebP/AVIF copies next to the original images (originals are not changed).
     *
     * ## OPTIONS
     *
     * [--id=<id>]
     * : Convert a single attachment by ID.
     *
     * [--year=<year>]
     * : Only convert images uploaded in this year.
     *
     * [--limit=<limit>]
     * : Maximum number of images to process (default: all).
     *
     * [--dry-run]
     * : List images that would be converted without converting.
     *
     * ## EXAMPLES
     *
     *   wp erdo convert
     *   wp erdo convert --id=55
     *   wp erdo convert --year=2024 --limit=50
     *
     * @subcommand convert
     */
    public function convert( $args, $assoc ) {
        $dry_run = isset( $assoc['dry-run'] );
        $format  = strtoupper( get_option( 'erdo_convert_format', 'webp' ) );

        if ( isset( $assoc['id'] ) ) {
            $id   = (int) $assoc['id'];
            $mime = get_post_mime_type( $id );
            if ( ! $id || ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/gif' ), true ) ) {
                WP_CLI::error( "ID {$id} is not a convertible image." );
            }
            if ( $dry_run ) {
                WP_CLI::log( "Would convert ID {$id}: " . get_attached_file( $id ) );
                return;
            }
            $result = ERDO_Converter::convert( $id );
            if ( is_wp_error( $result ) ) {
                WP_CLI::warning( "ID {$id} could not be converted: " . $result->get_error_message() );
            } elseif ( 'ok' !== $result['status'] ) {
                WP_CLI::warning( "ID {$id} skipped ({$result['status']}" . ( $result['reason'] ? ": {$result['reason']}" : '' ) . ').' );
            } else {
                WP_CLI::success( sprintf( 'ID %d: %d copies created, %d up to date, %s saved.', $id, $result['created'], $result['reused'], size_format( $result['before'] - $result['after'] ) ) );
            }
            return;
        }

        // Toplu dönüşüm — yalnızca henüz kopyası olmayanlar
        $ids = ERDO_Converter::pending_ids(
            isset( $assoc['limit'] ) ? (int) $assoc['limit'] : -1,
            isset( $assoc['year'] ) ? array( array( 'year' => (int) $assoc['year'] ) ) : null
        );

        if ( empty( $ids ) ) {
            WP_CLI::success( 'No convertible images found.' );
            return;
        }

        if ( $dry_run ) {
            WP_CLI::log( count( $ids ) . ' images would be converted.' );
            foreach ( $ids as $id ) {
                WP_CLI::log( "  ID {$id}: " . basename( get_attached_file( $id ) ) );
            }
            return;
        }

        $progress = WP_CLI\Utils\make_progress_bar( "Converting to {$format}", count( $ids ) );
        $ok      = 0;
        $skipped = 0;
        $fail    = 0;

        foreach ( $ids as $id ) {
            $result = ERDO_Converter::convert( $id );
            if ( is_wp_error( $result ) ) {
                $fail++;
            } elseif ( 'ok' !== $result['status'] ) {
                $skipped++;
            } else {
                $ok++;
            }
            $progress->tick();
        }

        $progress->finish();
        WP_CLI::success( "Done. Converted: {$ok} | Skipped (modern format, animated or no gain): {$skipped} | Failed: {$fail}" );
    }

    /* ------------------------------------------------------------------
       wp erdo audit
    ------------------------------------------------------------------ */

    /**
     * Run SEO audit on the media library.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format: table, csv, json (default: table).
     *
     * [--limit=<limit>]
     * : Limit results (default: 100).
     *
     * [--status=<status>]
     * : Filter by status: red, orange, green, gray.
     *
     * ## EXAMPLES
     *
     *   wp erdo audit
     *   wp erdo audit --format=csv > audit.csv
     *   wp erdo audit --status=red
     *
     * @subcommand audit
     */
    public function audit( $args, $assoc ) {
        $format      = $assoc['format'] ?? 'table';
        $limit       = (int) ( $assoc['limit'] ?? 100 );
        $filter_stat = $assoc['status'] ?? null;

        $ids = get_posts( array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => 'image',
            'posts_per_page' => $limit,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ) );

        if ( empty( $ids ) ) {
            WP_CLI::success( 'No images found.' );
            return;
        }

        $results = ERDO_Auditor::bulk_analyze( $ids );
        $rows    = array();

        foreach ( $results as $id => $data ) {
            if ( $filter_stat && $data['status'] !== $filter_stat ) {
                continue;
            }
            $rows[] = array(
                'id'       => $id,
                'filename' => $data['filename'],
                'score'    => $data['score'] . '/6',
                'status'   => $data['status'],
                'is_used'  => $data['is_used'] ? 'yes' : 'no',
            );
        }

        WP_CLI\Utils\format_items( $format, $rows, array( 'id', 'filename', 'score', 'status', 'is_used' ) );
        WP_CLI::log( count( $rows ) . ' images listed.' );
    }

    /* ------------------------------------------------------------------
       wp erdo restore
    ------------------------------------------------------------------ */

    /**
     * Remove the WebP/AVIF copies of an image (the original is always kept).
     *
     * ## OPTIONS
     *
     * --id=<id>
     * : Attachment ID to restore.
     *
     * ## EXAMPLES
     *
     *   wp erdo restore --id=42
     *
     * @subcommand restore
     */
    public function restore( $args, $assoc ) {
        if ( empty( $assoc['id'] ) ) {
            WP_CLI::error( '--id is required.' );
        }

        $id = (int) $assoc['id'];
        ERDO_Converter::revert( $id );
        WP_CLI::success( "ID {$id}: WebP/AVIF copies removed, original is served again." );
    }

    /* ------------------------------------------------------------------
       wp erdo migrate
    ------------------------------------------------------------------ */

    /**
     * Repair images converted by Erdo 2.x (which replaced the original file).
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Only report what would be done.
     *
     * [--batch=<n>]
     * : Images per batch (default: 25).
     *
     * ## EXAMPLES
     *
     *   wp erdo migrate --dry-run
     *   wp erdo migrate
     *
     * @subcommand migrate
     */
    public function migrate( $args, $assoc ) {
        $ids = ERDO_Migration::find_legacy();
        if ( empty( $ids ) ) {
            ERDO_Migration::refresh_legacy_flag();
            WP_CLI::success( 'Nothing to repair.' );
            return;
        }

        if ( isset( $assoc['dry-run'] ) ) {
            WP_CLI\Utils\format_items( 'table', array_map( array( 'ERDO_Migration', 'plan' ), $ids ), array( 'id', 'file', 'mode', 'backup', 'refs' ) );
            WP_CLI::log( count( $ids ) . ' images need repair. restore = original comes back from backup; repoint = links are updated to the WebP file.' );
            return;
        }

        $batch    = max( 1, (int) ( $assoc['batch'] ?? 25 ) );
        $progress = WP_CLI\Utils\make_progress_bar( 'Repairing', count( $ids ) );
        $totals   = array( 'restore' => 0, 'repoint' => 0, 'errors' => 0 );
        do {
            $r = ERDO_Migration::run_batch( $batch );
            $totals['restore'] += $r['restore'];
            $totals['repoint'] += $r['repoint'];
            $totals['errors']  += count( $r['errors'] );
            foreach ( $r['errors'] as $id => $msg ) {
                WP_CLI::warning( "ID {$id}: {$msg}" );
            }
            for ( $i = 0; $i < $r['restore'] + $r['repoint'] + count( $r['errors'] ); $i++ ) {
                $progress->tick();
            }
        } while ( $r['remaining'] > 0 && ( $r['restore'] + $r['repoint'] + count( $r['errors'] ) ) > 0 );
        $progress->finish();

        WP_CLI::success( "Restored from backup: {$totals['restore']} | Links updated: {$totals['repoint']} | Errors: {$totals['errors']}" );
    }

    /* ------------------------------------------------------------------
       wp erdo stats
    ------------------------------------------------------------------ */

    /**
     * Show media library statistics.
     *
     * ## EXAMPLES
     *
     *   wp erdo stats
     *
     * @subcommand stats
     */
    public function stats( $args, $assoc ) {

        $lib    = ERDO_Converter::library_stats();
        $format = get_option( 'erdo_convert_format', 'webp' );
        $caps   = ERDO_Encoder::capabilities();

        WP_CLI\Utils\format_items( 'table',
            array(
                array( 'Key' => 'Total images',            'Value' => $lib['total'] ),
                array( 'Key' => 'With WebP/AVIF copy',     'Value' => $lib['optimized'] ),
                array( 'Key' => 'Opt. rate',               'Value' => $lib['rate'] . '%' ),
                array( 'Key' => 'Bytes saved per full set', 'Value' => size_format( $lib['saved'], 2 ) ),
                array( 'Key' => 'Target format',           'Value' => strtoupper( $format ) ),
                array( 'Key' => 'Encoders',                'Value' => sprintf( 'WebP: %s | AVIF: %s', implode( '+', array_keys( array_filter( array( 'GD' => $caps['webp']['gd'], 'Imagick' => $caps['webp']['imagick'] ) ) ) ) ?: 'none', implode( '+', array_keys( array_filter( array( 'GD' => $caps['avif']['gd'], 'Imagick' => $caps['avif']['imagick'] ) ) ) ) ?: 'none' ) ),
                array( 'Key' => 'Images awaiting repair',  'Value' => (int) get_option( ERDO_Migration::LEGACY_OPTION, 0 ) ),
            ),
            array( 'Key', 'Value' )
        );
    }

    /* ------------------------------------------------------------------
       wp erdo clean-cache
    ------------------------------------------------------------------ */

    /**
     * Clear all audit caches.
     *
     * @subcommand clean-cache
     */
    public function clean_cache( $args, $assoc ) {
        ERDO_Auditor::clear_all_cache();
        WP_CLI::success( 'All audit caches cleared.' );
    }
}

WP_CLI::add_command( 'erdo', 'ERDO_CLI' );
