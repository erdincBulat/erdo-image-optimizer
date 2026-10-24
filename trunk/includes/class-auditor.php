<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ERDO_Auditor {

    /* ------------------------------------------------------------------
       CACHE YÖNETİMİ
    ------------------------------------------------------------------ */

    public static function clear_cache( $post_id ) {
        delete_post_meta( $post_id, '_erdo_audit_cache' );
        wp_cache_delete( $post_id, 'post_meta' );
        // bulk_analyze() ham meta satırlarını bu grupta 300 sn tutar; temizlenmezse
        // kalıcı object cache'li sitelerde az önce kaydedilen alt metin bayat görünür.
        self::flush_usage_cache();
    }

    /**
     * Eklentiye ait 'erdo_image_optimizer' object cache grubunu temizler
     * (featured-image/post-parent usage cache dahil). wp_cache_flush() YOK —
     * tüm site cache'ini silmez, sadece eklenti verisini siler.
     */
    public static function flush_usage_cache() {
        if ( function_exists( 'wp_cache_flush_group' ) ) {
            // WP 6.1+ — grup bazında temizle
            wp_cache_flush_group( 'erdo_image_optimizer' );
        } else {
            wp_cache_delete( 'erdo_stats_cache',        'erdo_image_optimizer' );
            wp_cache_delete( 'erdo_count_seo',          'erdo_image_optimizer' );
            wp_cache_delete( 'erdo_missing_thumbnails', 'erdo_image_optimizer' );
        }
    }

    /**
     * Tüm audit cache'ini temizler.
     */
    public static function clear_all_cache() {
        delete_metadata( 'post', 0, '_erdo_audit_cache', '', true );
        self::flush_usage_cache();
    }

    /* ------------------------------------------------------------------
       TOPLU USAGE KONTROLÜ
    ------------------------------------------------------------------ */

    public static function bulk_check_usage( $attachment_ids ) {
        global $wpdb;

        if ( empty( $attachment_ids ) ) {
            return array();
        }

        $results = array();
        foreach ( $attachment_ids as $id ) {
            $results[ $id ] = false;
        }

        $ids_str      = implode( ',', array_map( 'intval', $attachment_ids ) );
        $count        = count( $attachment_ids );
        $placeholders = implode( ',', array_fill( 0, $count, '%d' ) );

        // 1. Featured image kontrolü
        $cache_key = 'erdo_featured_imgs_' . md5( $ids_str );
        $featured  = wp_cache_get( $cache_key, 'erdo_image_optimizer' );

        if ( false === $featured ) {
                // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $featured = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT meta_value FROM $wpdb->postmeta WHERE meta_key = '_thumbnail_id' AND meta_value IN ($placeholders)", ...$attachment_ids ) );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            wp_cache_set( $cache_key, $featured, 'erdo_image_optimizer', 300 );
        }

        foreach ( $featured as $id ) {
            $results[ $id ] = true;
        }

        // 2. post_parent kontrolü — doğrudan bir posta bağlı görseller "kullanılıyor" sayılır
        $unused_ids = array_keys( array_filter( $results, fn( $v ) => false === $v ) );

        if ( ! empty( $unused_ids ) ) {
            $pu_count        = count( $unused_ids );
            $pu_placeholders = implode( ',', array_fill( 0, $pu_count, '%d' ) );
            $pu_cache_key    = 'erdo_post_parent_' . md5( implode( ',', $unused_ids ) );
            $attached        = wp_cache_get( $pu_cache_key, 'erdo_image_optimizer' );

            if ( false === $attached ) {
                // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $attached = $wpdb->get_col( $wpdb->prepare(
                    "SELECT ID FROM $wpdb->posts WHERE ID IN ($pu_placeholders) AND post_parent != 0",
                    ...$unused_ids
                ) );
                // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                wp_cache_set( $pu_cache_key, $attached, 'erdo_image_optimizer', 300 );
            }

            foreach ( $attached as $id ) {
                $results[ $id ] = true;
            }
        }

        // 3. İçerik + meta LIKE kontrolü — sadece hâlâ "kullanılmayan" görünenler için
        $unused_ids = array_keys( array_filter( $results, fn( $v ) => false === $v ) );

        if ( ! empty( $unused_ids ) ) {
            foreach ( $unused_ids as $id ) {
                $file_path = get_attached_file( $id );
                if ( ! $file_path ) {
                    continue;
                }

                $file_name = basename( $file_path );
                $like_name = '%' . $wpdb->esc_like( $file_name ) . '%';
                $sc_dbl    = '%="' . $id . '"%';
                $sc_sng    = "%='" . $id . "'%";
                $json_int  = '%:' . $id . ',%';
                $json_end  = '%:' . $id . '}';
                $json_str  = '%:"' . $id . '"%';

                // İçerik kontrolü
                $cache_key  = 'erdo_in_content_' . $id;
                $in_content = wp_cache_get( $cache_key, 'erdo_image_optimizer' );

                if ( false === $in_content ) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                    $in_content = $wpdb->get_var( $wpdb->prepare(
                        "SELECT ID FROM {$wpdb->posts}
                         WHERE post_status NOT IN ('inherit','trash','auto-draft')
                         AND ID != %d
                         AND (
                            post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s OR
                            post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s
                         ) LIMIT 1",
                        $id, $like_name, $sc_dbl, $sc_sng, $json_int, $json_end, $json_str
                    ) );
                    wp_cache_set( $cache_key, $in_content, 'erdo_image_optimizer', 300 );
                }

                if ( $in_content ) {
                    $results[ $id ] = true;
                    continue;
                }

                // Meta kontrolü
                $cache_key = 'erdo_in_meta_' . $id;
                $in_meta   = wp_cache_get( $cache_key, 'erdo_image_optimizer' );

                if ( false === $in_meta ) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                    $in_meta = $wpdb->get_var( $wpdb->prepare(
                        "SELECT post_id FROM {$wpdb->postmeta}
                         WHERE (
                            meta_value LIKE %s OR meta_value LIKE %s OR meta_value LIKE %s OR
                            meta_value LIKE %s OR meta_value LIKE %s OR meta_value LIKE %s
                         ) AND post_id != %d LIMIT 1",
                        $like_name, $sc_dbl, $sc_sng, $json_int, $json_end, $json_str, $id
                    ) );
                    wp_cache_set( $cache_key, $in_meta, 'erdo_image_optimizer', 300 );
                }

                if ( $in_meta ) {
                    $results[ $id ] = true;
                }
            }
        }

        return $results;
    }

    /* ------------------------------------------------------------------
       TOPLU ANALİZ
    ------------------------------------------------------------------ */

    public static function bulk_analyze( $attachment_ids ) {
        global $wpdb;

        if ( empty( $attachment_ids ) ) {
            return array();
        }

        // Bekleyen indeks güncellemeleri etkilenen görsellerin audit cache'ini
        // temizler — önce bunlar işlenmeli, yoksa aşağıda bayat cache okunur.
        ERDO_Usage::flush();

        $ids_str      = implode( ',', array_map( 'intval', $attachment_ids ) );
        $count        = count( $attachment_ids );
        $placeholders = implode( ',', array_fill( 0, $count, '%d' ) );

        // 1. Post meta toplu çek
        $cache_key     = 'erdo_bulk_metadata_' . md5( $ids_str );
        $metadata_rows = wp_cache_get( $cache_key, 'erdo_image_optimizer' );

        if ( false === $metadata_rows ) {
                // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $metadata_rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT post_id, meta_key, meta_value FROM $wpdb->postmeta WHERE post_id IN ($placeholders) AND meta_key IN ('_wp_attached_file','_wp_attachment_metadata','_wp_attachment_image_alt','_erdo_audit_cache','_erdo_savings_bytes','_erdo_original_size','_erdo_formats','_erdo_saved_bytes','_erdo_decorative')",
                ...$attachment_ids
            ), ARRAY_A );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            wp_cache_set( $cache_key, $metadata_rows, 'erdo_image_optimizer', 300 );
        }

        $meta_by_post = array();
        foreach ( $metadata_rows as $row ) {
            $meta_by_post[ $row['post_id'] ][ $row['meta_key'] ] = maybe_unserialize( $row['meta_value'] );
        }

        // 2. Post bilgileri
        $cache_key = 'erdo_bulk_posts_' . md5( $ids_str );
        $posts     = wp_cache_get( $cache_key, 'erdo_image_optimizer' );

        if ( false === $posts ) {
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $posts = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_title, post_mime_type, post_parent FROM $wpdb->posts WHERE ID IN ($placeholders)", ...$attachment_ids ), ARRAY_A );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            wp_cache_set( $cache_key, $posts, 'erdo_image_optimizer', 300 );
        }

        $posts_by_id = array();
        foreach ( $posts as $post ) {
            $posts_by_id[ $post['ID'] ] = $post;
        }

        // 3. Parent başlıkları
        $parent_ids    = array_filter( array_unique( array_column( $posts, 'post_parent' ) ) );
        $parent_titles = array();

        if ( ! empty( $parent_ids ) ) {
            $parent_ids_str = implode( ',', array_map( 'intval', $parent_ids ) );
            $cache_key      = 'erdo_parent_titles_' . md5( $parent_ids_str );
            $parent_rows    = wp_cache_get( $cache_key, 'erdo_image_optimizer' );

            if ( false === $parent_rows ) {
                $p_count        = count( $parent_ids );
                $p_placeholders = implode( ',', array_fill( 0, $p_count, '%d' ) );
                // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $parent_rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_title FROM $wpdb->posts WHERE ID IN ($p_placeholders)", ...$parent_ids ), ARRAY_A );
                // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                wp_cache_set( $cache_key, $parent_rows, 'erdo_image_optimizer', 300 );
            }

            foreach ( $parent_rows as $p ) {
                $parent_titles[ $p['ID'] ] = $p['post_title'];
            }
        }

        // 4. Cache'i ÖNCE kontrol et — cache'li görseller için usage check GEREKMİYOR.
        //    Sadece cache'siz görseller için usage check çalıştır.
        //    Bu sayede warm cache durumunda LIKE sorguları tamamen atlanır.
        $results      = array();
        $uncached_ids = array();

        foreach ( $attachment_ids as $post_id ) {
            if ( isset( $meta_by_post[ $post_id ]['_erdo_audit_cache'] ) ) {
                $cached = $meta_by_post[ $post_id ]['_erdo_audit_cache'];
                if ( is_array( $cached ) ) {
                    $results[ $post_id ] = $cached;
                    continue;
                }
            }
            $uncached_ids[] = $post_id;
        }

        // Tümü cache'liyse direk döndür (sıfır LIKE sorgusu)
        if ( empty( $uncached_ids ) ) {
            return $results;
        }

        // 5. Sadece cache'siz görseller için usage kontrolü — indeks hazırsa tek sorgu,
        //    değilse (ilk kurulum sürerken) eski LIKE taraması.
        $usage_results     = ERDO_Usage::is_ready() ? ERDO_Usage::bulk_used( $uncached_ids ) : self::bulk_check_usage( $uncached_ids );
        $indexable_results = ERDO_Usage::is_ready() ? ERDO_Usage::bulk_indexable( $uncached_ids ) : array();

        // 6. Cache'siz görselleri analiz et
        foreach ( $uncached_ids as $post_id ) {

            $post = $posts_by_id[ $post_id ] ?? null;
            if ( ! $post ) {
                continue;
            }

            $meta         = $meta_by_post[ $post_id ]['_wp_attachment_metadata'] ?? array();
            $file         = $meta_by_post[ $post_id ]['_wp_attached_file'] ?? '';
            $alt          = $meta_by_post[ $post_id ]['_wp_attachment_image_alt'] ?? '';
            $name         = pathinfo( $file, PATHINFO_FILENAME );
            $title        = $post['post_title'];
            $mime         = $post['post_mime_type'];
            $parent_title = ( $post['post_parent'] > 0 && isset( $parent_titles[ $post['post_parent'] ] ) )
                ? $parent_titles[ $post['post_parent'] ]
                : '';
            $width        = (int) ( $meta['width'] ?? 0 );
            $height       = (int) ( $meta['height'] ?? 0 );
            $is_used      = $usage_results[ $post_id ] ?? false;
            $w            = ERDO_SEO::WEIGHTS;

            // Puan kuralları ERDO_SEO'da; burada yalnızca veri toplanır ve satırlar kurulur.
            // Her satır: key, label, pass, points, weight, value, msg. weight 0 = bilgi amaçlı.
            $checklist = array();

            // --- Alt metin (40) ---
            $decorative  = ! empty( $meta_by_post[ $post_id ][ ERDO_SEO::DECORATIVE_META ] );
            $c           = ERDO_SEO::check_alt( $alt, $name, $decorative );
            $checklist[] = array_merge( $c, array(
                'key'        => 'alt',
                'label'      => __( 'Alt Text', 'erdo-image-optimizer' ),
                'weight'     => $w['alt'],
                'value'      => $alt,
                'decorative' => $decorative,
            ) );

            // --- Dosya adı (20) ---
            $c           = ERDO_SEO::check_filename( $name );
            $checklist[] = array_merge( $c, array(
                'key'    => 'filename',
                'label'  => __( 'Filename', 'erdo-image-optimizer' ),
                'weight' => $w['filename'],
                'value'  => $name,
            ) );

            // --- Format (10): dosyanın kendisi ya da kardeşi WebP/AVIF ise geçer ---
            $record       = $meta_by_post[ $post_id ][ ERDO_Converter::META ] ?? null;
            $record       = is_array( $record ) ? $record : array();
            $is_optimized = ERDO_Converter::is_optimized( $post_id, $mime, $record );
            $best_sibling = ERDO_Converter::best_main_sibling( $post_id, $record, $file );
            $checklist[]  = array(
                'key'    => 'format',
                'label'  => __( 'Format', 'erdo-image-optimizer' ),
                'pass'   => $is_optimized,
                'points' => $is_optimized ? $w['format'] : 0,
                'weight' => $w['format'],
                'msg'    => $is_optimized
                    ? __( 'A WebP/AVIF version is served to browsers that support it.', 'erdo-image-optimizer' )
                    : sprintf(
                        // translators: %s: mevcut format (JPG, PNG vb.).
                        __( 'Current format is %s. Create a WebP or AVIF version for faster loading.', 'erdo-image-optimizer' ),
                        strtoupper( str_replace( 'image/', '', $mime ) )
                    ),
            );

            // --- Dosya boyutu (10): ziyaretçiye giden boyut (varsa en küçük kardeş). filesize meta yoksa (WP < 6.0) geçer ---
            $served_size  = $best_sibling ? $best_sibling['bytes'] : (int) ( $meta['filesize'] ?? 0 );
            $has_filesize = $served_size > 0;
            $size_pass    = ! $has_filesize || $served_size < ERDO_SEO::MAX_BYTES;
            $checklist[]  = array(
                'key'    => 'filesize',
                'label'  => __( 'File Size', 'erdo-image-optimizer' ),
                'pass'   => $size_pass,
                'points' => $size_pass ? $w['filesize'] : ( $served_size < 2 * ERDO_SEO::MAX_BYTES ? (int) round( $w['filesize'] / 2 ) : 0 ),
                'weight' => $w['filesize'],
                'msg'    => $has_filesize
                    ? sprintf(
                        // translators: %s: dosya boyutu KB cinsinden.
                        __( 'File size is %s KB. Aim for under 250 KB.', 'erdo-image-optimizer' ),
                        round( $served_size / 1024 )
                    )
                    : __( 'File size unknown. Re-save or regenerate thumbnails to detect it.', 'erdo-image-optimizer' ),
            );

            // --- Boyutlar (5): genişlik VE yükseklik (0 = bilinmiyor, kaybettirmez) ---
            $limit       = ERDO_SEO::MAX_DIMENSION;
            $oversized   = $width > $limit || $height > $limit;
            $checklist[] = array(
                'key'    => 'dimensions',
                'label'  => __( 'Dimensions', 'erdo-image-optimizer' ),
                'pass'   => ! $oversized,
                'points' => $oversized ? 0 : $w['dimensions'],
                'weight' => $w['dimensions'],
                'width'  => $width,
                'height' => $height,
                'msg'    => $oversized
                    ? sprintf(
                        // translators: 1: mevcut genişlik, 2: mevcut yükseklik, 3: maksimum izin verilen boyut (piksel).
                        __( 'Image is too large (%1$d×%2$dpx, max recommended %3$dpx). Resize to improve load time.', 'erdo-image-optimizer' ),
                        $width,
                        $height,
                        $limit
                    )
                    : ( $width ? __( 'Dimensions are within the recommended size.', 'erdo-image-optimizer' ) : __( 'Image dimensions could not be detected. This may resolve after re-saving.', 'erdo-image-optimizer' ) ),
            );

            // --- Bağlam (15): kullanılıyor mu, dizinlenebilir bir yerde mi ---
            $indexable = $is_used && ( $indexable_results[ $post_id ] ?? true );
            if ( ! $is_used ) {
                $ctx = array( 'pass' => false, 'points' => 0, 'msg' => __( 'Not used anywhere on the site, so search engines never see it.', 'erdo-image-optimizer' ) );
            } elseif ( ! $indexable ) {
                $ctx = array( 'pass' => true, 'points' => 8, 'msg' => __( 'Only used where search engines do not look (drafts, private or noindex pages, CSS backgrounds).', 'erdo-image-optimizer' ) );
            } else {
                $ctx = array( 'pass' => true, 'points' => $w['context'], 'msg' => __( 'Used on a page search engines can index.', 'erdo-image-optimizer' ) );
            }
            $checklist[] = array_merge( $ctx, array(
                'key'    => 'context',
                'label'  => __( 'Usage', 'erdo-image-optimizer' ),
                'weight' => $w['context'],
            ) );

            // --- Başlık: bilgi amaçlı, puana katılmaz (title niteliğinin SEO karşılığı yok) ---
            $checklist[] = array(
                'key'    => 'title',
                'label'  => __( 'Image Title', 'erdo-image-optimizer' ),
                'pass'   => '' !== trim( (string) $title ) && false === strpos( $title, '_' ),
                'points' => 0,
                'weight' => 0,
                'value'  => $title,
                'msg'    => __( 'Shown in the media library and as a tooltip. Not used for ranking, so it does not affect the score.', 'erdo-image-optimizer' ),
            );

            // --- Skor ve durum ---
            $score  = min( 100, (int) array_sum( wp_list_pluck( $checklist, 'points' ) ) );
            $status = ERDO_SEO::status( $score, $is_used );

            // Ana dosya için kazanç: orijinal ↔ en küçük kardeş (3.0). 2.x'ten kalan
            // (onarılmamış) görsellerde eski meta alanları kullanılır.
            if ( $best_sibling ) {
                $original_size = (int) ( $meta['filesize'] ?? 0 );
                $current_size  = $best_sibling['bytes'];
                $saved_bytes   = max( 0, $original_size - $current_size );
            } else {
                $saved_bytes   = (int) ( $meta_by_post[ $post_id ]['_erdo_savings_bytes'] ?? 0 );
                $original_size = (int) ( $meta_by_post[ $post_id ]['_erdo_original_size'] ?? 0 );
                $current_size  = $meta['filesize'] ?? 0;
            }

            $result = array(
                'status'       => $status,
                'is_used'      => $is_used,
                'score'        => $score,
                'checklist'    => $checklist,
                'thumbnail'    => wp_get_attachment_image_url( $post_id, 'thumbnail' ),
                'filename'     => basename( $file ),
                'parent_title' => $parent_title,
                'id'           => $post_id,
                'saved_bytes'  => $saved_bytes,
                'original_size'=> $original_size,
                'current_size' => $current_size,
                'mime'         => $mime,
                // Kartta gösterilecek format: kardeş varsa onun formatı.
                'served_format'=> $best_sibling ? $best_sibling['format'] : strtolower( str_replace( 'image/', '', $mime ) ),
            );

            update_post_meta( $post_id, '_erdo_audit_cache', $result );
            // Ayrı küçük meta: panel genel ortalamayı tek AVG sorgusuyla hesaplar.
            update_post_meta( $post_id, ERDO_SEO::SCORE_META, $score );
            $results[ $post_id ] = $result;
        }

        return $results;
    }

    public static function check_usage( $post_id ) {
        $result = self::bulk_check_usage( array( $post_id ) );
        return $result[ $post_id ] ?? false;
    }

    public static function analyze( $post_id ) {
        $results = self::bulk_analyze( array( $post_id ) );
        return $results[ $post_id ] ?? array();
    }

    /* ------------------------------------------------------------------
       EXIF TEMİZLEME
    ------------------------------------------------------------------ */

    public static function clean_metadata( $post_id ) {
        $file_path = get_attached_file( $post_id );

        if ( ! $file_path || ! file_exists( $file_path ) ) {
            return false;
        }

        if ( extension_loaded( 'imagick' ) && class_exists( 'Imagick' ) ) {
            try {
                $img = new Imagick( $file_path );
                $img->stripImage();
                $img->writeImage( $file_path );
                $img->clear();
                $img->destroy();
                self::clear_cache( $post_id );
                return true;
            } catch ( Exception $e ) {
                // GD fallback'e düş
            }
        }

        $editor = wp_get_image_editor( $file_path );
        if ( ! is_wp_error( $editor ) ) {
            $saved = $editor->save( $file_path );
            if ( ! is_wp_error( $saved ) ) {
                self::clear_cache( $post_id );
                return true;
            }
        }

        return false;
    }

    /* ------------------------------------------------------------------
       MANUEL YENİDEN BOYUTLANDIRMA — thumbnail'ler de yenilenir
    ------------------------------------------------------------------ */

    const RESIZE_BACKUP_KEY = 'erdo-orig';

    /**
     * Ana dosyayı küçültür (büyütmez). İlk seferde orijinal "{ad}-erdo-original.{uzantı}"
     * olarak yedeklenir ve _wp_attachment_backup_sizes'a yazılır: çekirdek görseli
     * silerken, yeniden adlandırma ve karantina da onu birlikte taşır.
     * restore_original_size() ile geri alınır.
     */
    public static function manual_resize( $post_id, $target_width, $target_height ) {
        $file_path = get_attached_file( $post_id );

        if ( ! $file_path || ! file_exists( $file_path ) || ! ERDO_References::in_uploads( $file_path ) ) {
            return false;
        }

        $meta = wp_get_attachment_metadata( $post_id );
        $w0   = (int) ( $meta['width'] ?? 0 );
        $h0   = (int) ( $meta['height'] ?? 0 );
        $tw   = (int) $target_width;
        $th   = (int) $target_height;
        if ( $tw < 16 || $th < 16 || ( $w0 && $tw > $w0 ) || ( $h0 && $th > $h0 ) || ( $tw === $w0 && $th === $h0 ) ) {
            return false; // büyütme yok, anlamsız/çok küçük boyut yok
        }

        $editor = wp_get_image_editor( $file_path );
        if ( is_wp_error( $editor ) ) {
            return false;
        }

        $resized = $editor->resize( $tw, $th, false );
        if ( is_wp_error( $resized ) ) {
            return false;
        }

        // Orijinali bir kez yedekle
        $backups = get_post_meta( $post_id, '_wp_attachment_backup_sizes', true );
        $backups = is_array( $backups ) ? $backups : array();
        if ( empty( $backups[ self::RESIZE_BACKUP_KEY ] ) ) {
            $info = pathinfo( $file_path );
            $name = wp_unique_filename( $info['dirname'], $info['filename'] . '-erdo-original.' . $info['extension'] );
            if ( ! copy( $file_path, $info['dirname'] . '/' . $name ) ) {
                return false;
            }
            $backups[ self::RESIZE_BACKUP_KEY ] = array(
                'width'     => $w0,
                'height'    => $h0,
                'file'      => $name,
                'mime-type' => get_post_mime_type( $post_id ),
            );
            update_post_meta( $post_id, '_wp_attachment_backup_sizes', $backups );
        }

        $editor->set_quality( (int) get_option( 'erdo_quality', 80 ) );
        $saved = $editor->save( $file_path );
        if ( is_wp_error( $saved ) ) {
            return false;
        }

        self::regenerate( $post_id, $file_path );
        return true;
    }

    /** Elle boyutlandırma öncesi orijinal yedeği (yoksa null). */
    public static function resize_backup( $post_id ) {
        $backups = get_post_meta( $post_id, '_wp_attachment_backup_sizes', true );
        $b       = is_array( $backups ) ? ( $backups[ self::RESIZE_BACKUP_KEY ] ?? null ) : null;
        $file    = get_attached_file( $post_id );
        if ( ! $b || ! $file ) {
            return null;
        }
        $b['path'] = dirname( $file ) . '/' . wp_basename( $b['file'] );
        return file_exists( $b['path'] ) ? $b : null;
    }

    /** Orijinal boyuta geri döner ve yedeği kaldırır. */
    public static function restore_original_size( $post_id ) {
        $b    = self::resize_backup( $post_id );
        $file = get_attached_file( $post_id );
        if ( ! $b || ! ERDO_References::in_uploads( $b['path'] ) || ! ERDO_References::in_uploads( $file ) ) {
            return new WP_Error( 'no_backup', __( 'There is no original to restore.', 'erdo-image-optimizer' ) );
        }
        if ( ! copy( $b['path'], $file ) ) {
            return new WP_Error( 'copy_failed', __( 'Could not restore the original file.', 'erdo-image-optimizer' ) );
        }
        wp_delete_file( $b['path'] );
        $backups = get_post_meta( $post_id, '_wp_attachment_backup_sizes', true );
        unset( $backups[ self::RESIZE_BACKUP_KEY ] );
        $backups ? update_post_meta( $post_id, '_wp_attachment_backup_sizes', $backups ) : delete_post_meta( $post_id, '_wp_attachment_backup_sizes' );
        self::regenerate( $post_id, $file );
        return true;
    }

    /** Ana dosya değişti: alt boyutları ve (varsa) WebP/AVIF kardeşlerini yeniden üret. */
    private static function regenerate( $post_id, $file_path ) {
        clearstatcache();

        require_once ABSPATH . 'wp-admin/includes/image.php';
        $was_optimized = ERDO_Converter::is_optimized( $post_id );
        ERDO_Converter::suspend_upload_hook();
        $attach_data = wp_generate_attachment_metadata( $post_id, $file_path );
        ERDO_Converter::resume_upload_hook();

        clearstatcache();
        $attach_data['filesize'] = file_exists( $file_path ) ? filesize( $file_path ) : 0;
        wp_update_attachment_metadata( $post_id, $attach_data );

        // Kaynak değişti — kardeşleri yeni boyutlara göre yeniden üret (eskiler mtime ile bayat sayılır).
        if ( $was_optimized ) {
            ERDO_Converter::convert( $post_id );
        }
        self::clear_cache( $post_id );
    }

    /* ------------------------------------------------------------------
       GÖRSEL VERİSİ KAYDET (alt text, başlık, dosya adı)
    ------------------------------------------------------------------ */

    public static function save_image_data( $post_id, $new_data ) {
        if ( ! $post_id ) {
            return false;
        }

        if ( isset( $new_data['alt_text'] ) ) {
            update_post_meta( $post_id, '_wp_attachment_image_alt', sanitize_text_field( $new_data['alt_text'] ) );
        }

        if ( isset( $new_data['decorative'] ) ) {
            if ( '1' === (string) $new_data['decorative'] ) {
                update_post_meta( $post_id, ERDO_SEO::DECORATIVE_META, 1 );
            } else {
                delete_post_meta( $post_id, ERDO_SEO::DECORATIVE_META );
            }
        }

        if ( isset( $new_data['title'] ) ) {
            wp_update_post( array( 'ID' => $post_id, 'post_title' => sanitize_text_field( $new_data['title'] ) ) );
        }

        // Alan formda hep dolu gelir; yalnızca kullanıcı adı gerçekten değiştirdiyse
        // yeniden adlandır (yoksa alt metni kaydetmek "IMG_1234"ü sessizce taşırdı).
        $current_stem = pathinfo( (string) get_attached_file( $post_id ), PATHINFO_FILENAME );
        if ( ! empty( $new_data['filename'] ) && trim( (string) $new_data['filename'] ) !== $current_stem ) {
            // rename_file() thumbnail'leri, guid'i ve post_content/postmeta URL
            // referanslarını da günceller — "Fix Filename" butonuyla aynı, tam
            // yeniden adlandırma yolunu kullanır. WP_Error dönüşleri (isim zaten
            // temiz, dosya bulunamadı, taşıma başarısız) sessizce yok sayılır;
            // bu durumda kullanıcı adı değiştirmemiş ya da geçersiz girmiştir.
            self::rename_file( $post_id, $new_data['filename'] );
        }

        self::clear_cache( $post_id );
        return true;
    }

    /* ------------------------------------------------------------------
       GÖRSEL SİL
    ------------------------------------------------------------------ */

    public static function delete_image( $post_id ) {
        return wp_delete_attachment( $post_id, true );
    }

    /* ------------------------------------------------------------------
       DOSYA ADI YENİDEN ADLANDIRMA + TÜM DB REFERANSLARI GÜNCELLEME
    ------------------------------------------------------------------ */

    /**
     * Geriye dönük uyumluluk: tek görseli yeniden adlandırır (ERDO_Renamer).
     * $new_name null ise mevcut ad temizlenir ("Fix Filename"); verilirse ve
     * "orijinal adı ekle" ayarı açıksa eski ad sona eklenir.
     */
    public static function rename_file( $post_id, $new_name = null ) {
        $append = null !== $new_name && get_option( 'erdo_append_original_filename' );
        $r      = ERDO_Renamer::rename( $post_id, $new_name, array( 'append_original' => $append ) );
        if ( is_wp_error( $r ) ) {
            return $r;
        }
        return array(
            'old_name' => $r['old_stem'],
            'new_name' => $r['new_stem'],
            'new_url'  => wp_get_attachment_url( $post_id ),
        );
    }

    /* ------------------------------------------------------------------
       CSV EXPORT: Tüm kütüphanenin audit datasını döndürür
    ------------------------------------------------------------------ */

    public static function get_csv_rows( $status_filter = '' ) {
        $ids = get_posts( array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => 'image',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ) );

        if ( empty( $ids ) ) {
            return array();
        }

        $results = self::bulk_analyze( $ids );
        $rows    = array();

        foreach ( $results as $id => $r ) {
            $cat = ( isset( $r['is_used'] ) && false === $r['is_used'] ) ? 'unused' : $r['status'];
            if ( $status_filter && $status_filter !== $cat ) {
                continue;
            }
            $issues = array();
            foreach ( (array) ( $r['checklist'] ?? array() ) as $c ) {
                if ( ! empty( $c['weight'] ) && $c['points'] < $c['weight'] ) {
                    $issues[] = $c['label'] . ': ' . $c['msg'];
                }
            }
            $rows[] = array(
                'id'       => $id,
                'filename' => $r['filename'],
                'score'    => (int) $r['score'],
                'status'   => $cat,
                'format'   => strtoupper( $r['served_format'] ?? str_replace( 'image/', '', $r['mime'] ?? '' ) ),
                'alt_text' => get_post_meta( $id, '_wp_attachment_image_alt', true ),
                'issues'   => implode( ' | ', $issues ),
                'edit_url' => admin_url( 'post.php?post=' . $id . '&action=edit' ),
            );
        }

        return $rows;
    }

    /* ------------------------------------------------------------------
       BULK ALT TEXT: Dosya adından alt text üret ve kaydet
    ------------------------------------------------------------------ */

    public static function set_alt_from_filename( $post_id ) {
        $file = get_attached_file( $post_id );
        if ( ! $file ) {
            return false;
        }
        // Kamera / ekran görüntüsü adları atlanır: "Img 1234" bir alt metin değildir.
        $alt = ERDO_SEO::alt_from_filename( pathinfo( $file, PATHINFO_FILENAME ) );
        if ( false === $alt ) {
            return false;
        }
        update_post_meta( $post_id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
        self::clear_cache( $post_id );
        return $alt;
    }
}
