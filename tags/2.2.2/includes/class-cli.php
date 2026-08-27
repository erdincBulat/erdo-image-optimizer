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
 *   wp erdo clean-cache
 */
class ERDO_CLI {

    /* ------------------------------------------------------------------
       wp erdo convert
    ------------------------------------------------------------------ */

    /**
     * Convert images to WebP/AVIF.
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
            $result = ERDO_Auditor::convert_to_webp( $id, true );
            if ( ERDO_Auditor::ALREADY_OPTIMIZED === $result ) {
                WP_CLI::warning( "ID {$id} is already in {$format} format." );
            } elseif ( $result ) {
                WP_CLI::success( "ID {$id} converted to {$format}." );
            } else {
                WP_CLI::warning( "ID {$id} could not be converted." );
            }
            return;
        }

        // Toplu dönüşüm
        $query_args = array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/gif' ),
            'posts_per_page' => isset( $assoc['limit'] ) ? (int) $assoc['limit'] : -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        );

        if ( isset( $assoc['year'] ) ) {
            $query_args['date_query'] = array(
                array( 'year' => (int) $assoc['year'] ),
            );
        }

        $ids = get_posts( $query_args );

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
            $result = ERDO_Auditor::convert_to_webp( $id, true );
            if ( ERDO_Auditor::ALREADY_OPTIMIZED === $result ) {
                $skipped++;
            } elseif ( $result ) {
                $ok++;
            } else {
                $fail++;
            }
            $progress->tick();
        }

        $progress->finish();
        WP_CLI::success( "Done. Converted: {$ok} | Already optimized: {$skipped} | Failed: {$fail}" );
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
     * Restore original file from backup.
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

        $id     = (int) $assoc['id'];
        $result = ERDO_Auditor::restore_original( $id );

        if ( $result ) {
            WP_CLI::success( "ID {$id} restored to original." );
        } else {
            WP_CLI::error( "No backup found for ID {$id}, or restore failed." );
        }
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
        global $wpdb;

        $total = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT COUNT(ID) FROM {$wpdb->posts}
             WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'"
        );
        $optimized = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT COUNT(ID) FROM {$wpdb->posts}
             WHERE post_type = 'attachment'
             AND post_mime_type IN ('image/webp','image/avif')"
        );
        $saved  = (int) get_option( 'erdo_total_saved_bytes', 0 );
        $format = get_option( 'erdo_convert_format', 'webp' );

        WP_CLI\Utils\format_items( 'table',
            array(
                array( 'Key' => 'Total images',       'Value' => $total ),
                array( 'Key' => 'Optimized images',   'Value' => $optimized ),
                array( 'Key' => 'Opt. rate',          'Value' => ( $total > 0 ? round( $optimized / $total * 100 ) : 0 ) . '%' ),
                array( 'Key' => 'Total disk saved',   'Value' => size_format( $saved, 2 ) ),
                array( 'Key' => 'Target format',      'Value' => strtoupper( $format ) ),
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
