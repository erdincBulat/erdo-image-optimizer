<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ERDO_Auditor {

    /**
     * convert_to_webp() bu değeri döndürdüğünde görsel zaten hedef formatta
     * demektir — bu bir hata değil, atlanan (skip) bir durumdur. Çağıranlar
     * bunu `false` (gerçek hata) ile karıştırmamalı.
     */
    const ALREADY_OPTIMIZED = 'already_optimized';

    /* ------------------------------------------------------------------
       CACHE YÖNETİMİ
    ------------------------------------------------------------------ */

    public static function clear_cache( $post_id ) {
        delete_post_meta( $post_id, '_erdo_audit_cache' );
        wp_cache_delete( $post_id, 'post_meta' );
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

        $ids_str      = implode( ',', array_map( 'intval', $attachment_ids ) );
        $count        = count( $attachment_ids );
        $placeholders = implode( ',', array_fill( 0, $count, '%d' ) );

        // 1. Post meta toplu çek
        $cache_key     = 'erdo_bulk_metadata_' . md5( $ids_str );
        $metadata_rows = wp_cache_get( $cache_key, 'erdo_image_optimizer' );

        if ( false === $metadata_rows ) {
                // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $metadata_rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT post_id, meta_key, meta_value FROM $wpdb->postmeta WHERE post_id IN ($placeholders) AND meta_key IN ('_wp_attached_file','_wp_attachment_metadata','_wp_attachment_image_alt','_erdo_audit_cache','_erdo_savings_bytes','_erdo_original_size')",
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

        // 5. Sadece cache'siz görseller için usage kontrolü çalıştır
        $usage_results = self::bulk_check_usage( $uncached_ids );

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
            $width        = $meta['width'] ?? 0;
            $height       = $meta['height'] ?? 0;
            $is_used      = $usage_results[ $post_id ] ?? false;

            $checklist = array();

            // --- Filename: sadece underscore ve boşluk kötü, rakam dizisi normal ---
            $checklist[] = array(
                'label' => 'Filename',
                'pass'  => ! preg_match( '/[_\s]/', $name ),
                'value' => $name,
                'msg'   => __( 'Filename contains underscores or spaces. Use hyphens for better SEO.', 'erdo-image-optimizer' ),
            );

            // --- Alt Text ---
            $checklist[] = array(
                'label' => 'Alt Text',
                'pass'  => ! empty( $alt ),
                'value' => $alt,
                'msg'   => __( 'Alt text is missing. Search engines use this to understand your image.', 'erdo-image-optimizer' ),
            );

            // --- Image Title: sadece underscore kötü, tire tamamen normal ---
            $title_pass = ! empty( $title ) && false === strpos( $title, '_' );
            $checklist[] = array(
                'label' => 'Image Title',
                'pass'  => $title_pass,
                'value' => $title,
                'msg'   => __( 'Title is empty or contains underscores. Keep it clean and readable.', 'erdo-image-optimizer' ),
            );

            // --- Dimensions: genişlik VE yükseklik kontrol edilir (0 = bilinmiyor, FAİL olmaz) ---
            $limit      = 2560;
            $width_ok   = ( 0 === $width ) || ( $width <= $limit );
            $height_ok  = ( 0 === $height ) || ( $height <= $limit );
            $dim_pass   = $width_ok && $height_ok;
            $oversized  = ( $width > $limit ) || ( $height > $limit );
            $checklist[] = array(
                'label'  => 'Dimensions',
                'pass'   => $dim_pass,
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
                    : __( 'Image dimensions could not be detected. This may resolve after re-saving.', 'erdo-image-optimizer' ),
            );

            // --- Format ---
            $is_optimized = ( false !== strpos( $mime, 'webp' ) || false !== strpos( $mime, 'avif' ) );
            $checklist[]  = array(
                'label' => 'Format',
                'pass'  => $is_optimized,
                'msg'   => sprintf(
                    // translators: %s: mevcut format (JPG, PNG vb.).
                    __( 'Current format is %s. WebP or AVIF is recommended for better performance.', 'erdo-image-optimizer' ),
                    strtoupper( str_replace( 'image/', '', $mime ) )
                ),
            );

            // --- File Size: filesize meta yoksa (WP < 6.0) geçer, sıfır göstermez ---
            $has_filesize = isset( $meta['filesize'] ) && $meta['filesize'] > 0;
            $checklist[]  = array(
                'label' => 'File Size',
                'pass'  => ! $has_filesize || $meta['filesize'] < 256000,
                'msg'   => $has_filesize
                    ? sprintf(
                        // translators: %s: dosya boyutu KB cinsinden.
                        __( 'File size is %s KB. Aim for under 250 KB.', 'erdo-image-optimizer' ),
                        round( $meta['filesize'] / 1024 )
                    )
                    : __( 'File size unknown. Re-save or regenerate thumbnails to detect it.', 'erdo-image-optimizer' ),
            );

            // --- Skor ve Durum ---
            $score = 0;
            foreach ( $checklist as $c ) {
                if ( $c['pass'] ) {
                    $score++;
                }
            }

            if ( ! $is_used ) {
                $status = 'gray';
            } elseif ( 6 === $score ) {
                $status = 'green';
            } elseif ( $score >= 3 ) {
                $status = 'orange';
            } else {
                $status = 'red';
            }

            $saved_bytes   = (int) ( $meta_by_post[ $post_id ]['_erdo_savings_bytes'] ?? 0 );
            $original_size = (int) ( $meta_by_post[ $post_id ]['_erdo_original_size'] ?? 0 );
            $current_size  = $meta['filesize'] ?? 0;

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
            );

            update_post_meta( $post_id, '_erdo_audit_cache', $result );
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

    public static function manual_resize( $post_id, $target_width, $target_height ) {
        $file_path = get_attached_file( $post_id );

        if ( ! $file_path || ! file_exists( $file_path ) ) {
            return false;
        }

        $editor = wp_get_image_editor( $file_path );
        if ( is_wp_error( $editor ) ) {
            return false;
        }

        $resized = $editor->resize( $target_width, $target_height, false );
        if ( is_wp_error( $resized ) ) {
            return false;
        }

        $quality = (int) get_option( 'erdo_quality', 80 );
        $editor->set_quality( $quality );

        $saved = $editor->save( $file_path );
        if ( is_wp_error( $saved ) ) {
            return false;
        }

        clearstatcache();

        require_once ABSPATH . 'wp-admin/includes/image.php';
        // Auto-convert hook'unu geçici kaldır: yeniden boyutlandırılan JPEG/PNG
        // meta üretimi sırasında WebP'ye dönüşmesin.
        remove_filter( 'wp_generate_attachment_metadata', 'erdo_auto_webp_convert', 10 );
        $attach_data = wp_generate_attachment_metadata( $post_id, $file_path );
        add_filter( 'wp_generate_attachment_metadata', 'erdo_auto_webp_convert', 10, 2 );

        clearstatcache();
        $attach_data['filesize'] = file_exists( $file_path ) ? filesize( $file_path ) : 0;
        wp_update_attachment_metadata( $post_id, $attach_data );
        self::clear_cache( $post_id );

        return true;
    }

    /* ------------------------------------------------------------------
       ORİJİNAL YEDEKLEME
    ------------------------------------------------------------------ */

    /**
     * Orijinal dosyayı _erdo_backup/ klasörüne yedekler.
     * Yedek zaten varsa tekrar oluşturmaz.
     */
    public static function backup_original( $post_id ) {
        $file_path = get_attached_file( $post_id );
        if ( ! $file_path || ! file_exists( $file_path ) ) {
            return false;
        }

        $backup_dir = dirname( $file_path ) . '/_erdo_backup/';
        if ( ! file_exists( $backup_dir ) ) {
            wp_mkdir_p( $backup_dir );
            // Klasörü web'den gizle
            file_put_contents( $backup_dir . '.htaccess', 'deny from all' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        }

        $backup_path = $backup_dir . basename( $file_path );
        if ( ! file_exists( $backup_path ) ) {
            copy( $file_path, $backup_path );
        }

        update_post_meta( $post_id, '_erdo_backup_path', $backup_path );
        return $backup_path;
    }

    /**
     * Orijinal dosyayı yedeğinden geri yükler.
     */
    public static function restore_original( $post_id ) {
        $backup_path = get_post_meta( $post_id, '_erdo_backup_path', true );
        if ( ! $backup_path || ! file_exists( $backup_path ) ) {
            return false;
        }

        $current_path = get_attached_file( $post_id );
        $original_ext = strtolower( pathinfo( $backup_path, PATHINFO_EXTENSION ) );
        $restore_path = pathinfo( $current_path, PATHINFO_DIRNAME )
            . '/' . pathinfo( $current_path, PATHINFO_FILENAME )
            . '.' . $original_ext;

        // Yeni boyutları üretmeden önce eski (optimize edilmiş formattaki) metadata'yı
        // sakla — aşağıda alt boyut dosyalarını (thumbnail'ları) temizlemek için gerekli.
        $old_meta = wp_get_attachment_metadata( $post_id );

        // Yedekten kopyala
        if ( ! copy( $backup_path, $restore_path ) ) {
            return false;
        }

        // Eski (optimize edilmiş) ana dosyayı sil
        if ( $current_path !== $restore_path && file_exists( $current_path ) ) {
            wp_delete_file( $current_path );
        }

        // Eski (optimize edilmiş formattaki) alt boyut dosyalarını da sil — aksi hâlde
        // WebP/AVIF sunucusu diskte kalan bu dosyaları restore sonrasında da servis etmeye devam eder.
        if ( ! empty( $old_meta['sizes'] ) ) {
            $thumb_dir = trailingslashit( dirname( $current_path ) );
            foreach ( $old_meta['sizes'] as $size_data ) {
                if ( ! empty( $size_data['file'] ) ) {
                    $old_thumb = $thumb_dir . $size_data['file'];
                    if ( file_exists( $old_thumb ) ) {
                        wp_delete_file( $old_thumb );
                    }
                }
            }
        }

        // DB'yi orijinal mime type ile güncelle
        $mime_map = array( 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif' );
        $mime     = $mime_map[ $original_ext ] ?? 'image/jpeg';

        update_attached_file( $post_id, $restore_path );
        wp_update_post( array( 'ID' => $post_id, 'post_mime_type' => $mime ) );

        require_once ABSPATH . 'wp-admin/includes/image.php';

        // Auto-convert hook'unu geçici kaldır: aksi hâlde restore edilen orijinal
        // dosya wp_generate_attachment_metadata tetiklenirken yeniden WebP'ye dönüşür.
        remove_filter( 'wp_generate_attachment_metadata', 'erdo_auto_webp_convert', 10 );
        $attach_data = wp_generate_attachment_metadata( $post_id, $restore_path );
        add_filter( 'wp_generate_attachment_metadata', 'erdo_auto_webp_convert', 10, 2 );

        clearstatcache();
        $attach_data['filesize'] = file_exists( $restore_path ) ? filesize( $restore_path ) : 0;
        wp_update_attachment_metadata( $post_id, $attach_data );

        delete_post_meta( $post_id, '_erdo_backup_path' );
        self::clear_cache( $post_id );

        return true;
    }

    /* ------------------------------------------------------------------
       WEBP / AVIF DÖNÜŞÜMÜ — thumbnail alt boyutları da dönüştürülür
    ------------------------------------------------------------------ */

    public static function convert_to_webp( $post_id, $update_db = true ) {
        $file_path = get_attached_file( $post_id );

        if ( ! $file_path || ! file_exists( $file_path ) ) {
            return false;
        }

        $original_size = filesize( $file_path );
        $info          = pathinfo( $file_path );
        $ext           = strtolower( $info['extension'] );

        if ( 'webp' === $ext || 'avif' === $ext ) {
            return self::ALREADY_OPTIMIZED;
        }

        $target_format = get_option( 'erdo_convert_format', 'webp' );
        if ( 'avif' === $target_format && ! function_exists( 'imageavif' ) ) {
            $target_format = 'webp';
        }

        $quality     = (int) get_option( 'erdo_quality', 80 );
        $lossless    = (bool) get_option( 'erdo_lossless', false );
        $keep_orig   = (bool) get_option( 'erdo_keep_original', false );
        $smart_q     = (bool) get_option( 'erdo_smart_quality', false );
        $new_path    = $info['dirname'] . '/' . $info['filename'] . '.' . $target_format;

        // Orijinali yedekle (ayar aktifse) — $update_db durumundan bağımsız,
        // aksi hâlde upload-time dönüşümde ($update_db=false) hiç yedek alınmaz.
        if ( $keep_orig ) {
            self::backup_original( $post_id );
        }

        $heic_tmp = null;

        // GD ile dönüştür — JPEG, PNG, GIF ve HEIC/HEIF desteklenir
        if ( 'png' === $ext ) {
            $image = @imagecreatefrompng( $file_path );
            if ( $image ) {
                imagepalettetotruecolor( $image );
                imagealphablending( $image, true );
                imagesavealpha( $image, true );
            }
        } elseif ( in_array( $ext, array( 'jpeg', 'jpg' ), true ) ) {
            $image = @imagecreatefromjpeg( $file_path );
        } elseif ( 'gif' === $ext ) {
            $image = @imagecreatefromgif( $file_path );
            if ( $image ) {
                // GIF'in şeffaflığını koru
                $new_image = imagecreatetruecolor( imagesx( $image ), imagesy( $image ) );
                imagefill( $new_image, 0, 0, imagecolorallocatealpha( $new_image, 255, 255, 255, 127 ) );
                imagealphablending( $new_image, true );
                imagesavealpha( $new_image, true );
                imagecopy( $new_image, $image, 0, 0, 0, 0, imagesx( $image ), imagesy( $image ) );
                imagedestroy( $image );
                $image = $new_image;
            }
        } elseif ( in_array( $ext, array( 'heic', 'heif' ), true ) && class_exists( 'Imagick' ) ) {
            // HEIC/HEIF → önce Imagick ile JPEG'e çevir, sonra GD ile WebP/AVIF yap
            try {
                $imagick = new Imagick( $file_path );
                $imagick->setImageFormat( 'jpeg' );
                $heic_tmp = $info['dirname'] . '/' . $info['filename'] . '_heic_tmp.jpg';
                $imagick->writeImage( $heic_tmp );
                $imagick->clear();
                $image = @imagecreatefromjpeg( $heic_tmp );
            } catch ( Exception $e ) {
                return false;
            }
        } else {
            return false;
        }

        if ( ! $image ) {
            return false;
        }

        // Smart quality — lossless devre dışıysa adaptif kalite hesapla
        if ( ! $lossless && $smart_q ) {
            $quality = self::find_best_quality( $image, $target_format );
        }

        // Lossless mod
        if ( $lossless ) {
            $quality = -1; // imagewebp: -1 = lossless
        }

        $success = ( 'avif' === $target_format )
            ? imageavif( $image, $new_path, $lossless ? 0 : $quality )
            : imagewebp( $image, $new_path, $quality );

        imagedestroy( $image );

        // HEIC geçici dosyasını temizle
        if ( $heic_tmp && file_exists( $heic_tmp ) ) {
            wp_delete_file( $heic_tmp );
        }

        if ( ! $success ) {
            return false;
        }

        // Orijinal dosyayı sil — yedek isteniyorsa zaten backup_original() ile
        // _erdo_backup/ klasörüne kopyalandı, o yüzden burada koşulsuz silinir
        // (aksi hâlde orijinal + yedek + dönüştürülmüş dosya olmak üzere 3 kopya birikir).
        wp_delete_file( $file_path );

        clearstatcache();
        $new_size = file_exists( $new_path ) ? filesize( $new_path ) : 0;

        // Tasarruf istatistiklerini kaydet
        if ( $new_size > 0 ) {
            $saved_bytes = $original_size - $new_size;
            if ( $saved_bytes > 0 ) {
                $total_saved = (int) get_option( 'erdo_total_saved_bytes', 0 );
                update_option( 'erdo_total_saved_bytes', $total_saved + $saved_bytes );
                update_post_meta( $post_id, '_erdo_savings_bytes', $saved_bytes );
                update_post_meta( $post_id, '_erdo_original_size', $original_size );
            }
        }

        if ( $update_db ) {
            update_attached_file( $post_id, $new_path );
            wp_update_post( array(
                'ID'             => $post_id,
                'post_mime_type' => 'image/' . $target_format,
            ) );

            // Eski thumbnail dosyalarını sil (eski formattaki alt boyutlar)
            $old_meta = wp_get_attachment_metadata( $post_id );
            if ( ! empty( $old_meta['sizes'] ) ) {
                $upload_dir = wp_upload_dir();
                $thumb_dir  = trailingslashit( $upload_dir['basedir'] )
                    . ( ! empty( $old_meta['file'] ) ? trailingslashit( dirname( $old_meta['file'] ) ) : '' );

                foreach ( $old_meta['sizes'] as $size_data ) {
                    if ( ! empty( $size_data['file'] ) ) {
                        $old_thumb = $thumb_dir . $size_data['file'];
                        if ( file_exists( $old_thumb ) ) {
                            wp_delete_file( $old_thumb );
                        }
                    }
                }
            }

            // Yeni formatta tüm boyutları (150x150, 300x300 vb.) yeniden oluştur
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $attach_data             = wp_generate_attachment_metadata( $post_id, $new_path );
            clearstatcache();
            $attach_data['filesize'] = file_exists( $new_path ) ? filesize( $new_path ) : 0;
            wp_update_attachment_metadata( $post_id, $attach_data );

            self::clear_cache( $post_id );
            return true;
        }

        // $update_db = false → sadece veriyi döndür (upload hook için)
        return array(
            'path'     => $new_path,
            'mime'     => 'image/' . $target_format,
            'filesize' => $new_size,
        );
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

        if ( isset( $new_data['title'] ) ) {
            wp_update_post( array( 'ID' => $post_id, 'post_title' => sanitize_text_field( $new_data['title'] ) ) );
        }

        if ( ! empty( $new_data['filename'] ) ) {
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
       SMART / ADAPTİF KALİTE  (Özellik 5)
       Her görsel için en küçük kabul edilebilir kalite değerini bulur.
       Yaklaşım: 85→70→55 arasında 3 probe; boyuttaki azalma eşiğinin
       altına düşünce bir önceki değeri döndürür.
    ------------------------------------------------------------------ */

    public static function find_best_quality( $image_resource, string $format ): int {
        $probes         = array( 85, 72, 58 );
        $prev_size      = PHP_INT_MAX;
        $best_quality   = 80;
        $threshold_pct  = 0.08; // %8'den az kazanç varsa daha düşük kaliteye gitme

        foreach ( $probes as $q ) {
            ob_start();
            if ( 'avif' === $format && function_exists( 'imageavif' ) ) {
                imageavif( $image_resource, null, $q );
            } else {
                imagewebp( $image_resource, null, $q );
            }
            $size = strlen( ob_get_clean() );

            if ( $size <= 0 ) {
                break;
            }

            $saving = ( $prev_size - $size ) / $prev_size;

            if ( $saving < $threshold_pct ) {
                // Bu kalite artık anlamlı kazanç sağlamıyor; bir üst değeri kullan
                break;
            }

            $best_quality = $q;
            $prev_size    = $size;
        }

        return $best_quality;
    }

    /* ------------------------------------------------------------------
       DOSYA ADI YENİDEN ADLANDIRMA + TÜM DB REFERANSLARI GÜNCELLEME
    ------------------------------------------------------------------ */

    public static function rename_file( $post_id, $new_name = null ) {
        global $wpdb;

        $old_path = get_attached_file( $post_id );
        if ( ! $old_path || ! file_exists( $old_path ) ) {
            return new WP_Error( 'not_found', 'File not found.' );
        }

        $path_info      = pathinfo( $old_path );
        $ext            = strtolower( $path_info['extension'] );
        $old_name       = $path_info['filename'];
        $is_custom_name = ( null !== $new_name );
        // $new_name verilmezse (Fix Filename butonu) mevcut adı sanitize eder;
        // verilirse (modal'daki Filename alanı) kullanıcının girdiği adı sanitize eder.
        $new_name  = sanitize_title( null === $new_name ? $old_name : $new_name );
        // sanitize_title() alt çizgiyi olduğu gibi bırakır (yalnızca küçük harfe çevirir) —
        // "photo_001" öz adla birebir aynı kalıp yanlışlıkla "already clean" sayılmasın diye
        // tireye çeviriyoruz (upload-time sanitizer'la aynı davranış).
        $new_name  = str_replace( '_', '-', $new_name );

        if ( '' === $new_name ) {
            return new WP_Error( 'invalid_name', 'Filename cannot be empty.' );
        }

        if ( $old_name === $new_name ) {
            return new WP_Error( 'clean', 'Filename already clean.' );
        }

        // Kullanıcı modal'dan özel bir isim girdiyse (Fix Filename butonu değil) ve
        // ayar açıksa, orijinal dosya adını sona ekle — "istanbul" → "istanbul-img-1234".
        // Bu, yeniden adlandırılan görseller arasında çakışmayı önler ve kırık bağlantı
        // durumunda orijinal ada referans bırakır.
        if ( $is_custom_name && get_option( 'erdo_append_original_filename' ) ) {
            $new_name = $new_name . '-' . str_replace( '_', '-', sanitize_title( $old_name ) );
        }

        $new_path = $path_info['dirname'] . '/' . $new_name . '.' . $ext;

        // Hedef dosya zaten varsa benzersiz isim üret
        if ( file_exists( $new_path ) ) {
            $new_name = $new_name . '-' . $post_id;
            $new_path = $path_info['dirname'] . '/' . $new_name . '.' . $ext;
        }

        // Thumbnail dosyalarını da yeniden adlandır (önce, ana dosya taşınmadan)
        $old_meta = wp_get_attachment_metadata( $post_id );
        $thumb_renames = array();
        if ( ! empty( $old_meta['sizes'] ) ) {
            foreach ( $old_meta['sizes'] as $size_data ) {
                if ( empty( $size_data['file'] ) ) {
                    continue;
                }
                $old_thumb     = $path_info['dirname'] . '/' . $size_data['file'];
                $thumb_info    = pathinfo( $old_thumb );
                $suffix        = substr( $thumb_info['filename'], strlen( $old_name ) );
                $new_thumb     = $path_info['dirname'] . '/' . $new_name . $suffix . '.' . $thumb_info['extension'];
                if ( file_exists( $old_thumb ) ) {
                    $thumb_renames[] = array( 'old' => $old_thumb, 'new' => $new_thumb );
                }
            }
        }

        // Ana dosyayı taşı
        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
        global $wp_filesystem;

        if ( ! $wp_filesystem || ! $wp_filesystem->move( $old_path, $new_path ) ) {
            return new WP_Error( 'move_failed', 'Could not rename file.' );
        }

        // Thumbnail'leri taşı
        foreach ( $thumb_renames as $tr ) {
            $wp_filesystem->move( $tr['old'], $tr['new'] );
        }

        // Upload base URL hesapla
        $upload_dir = wp_upload_dir();
        $base_dir   = trailingslashit( $upload_dir['basedir'] );
        $base_url   = trailingslashit( $upload_dir['baseurl'] );
        $rel_dir    = ltrim( str_replace( $base_dir, '', trailingslashit( $path_info['dirname'] ) ), '/' );
        $old_url    = $base_url . $rel_dir . $old_name . '.' . $ext;
        $new_url    = $base_url . $rel_dir . $new_name . '.' . $ext;

        // DB: _wp_attached_file
        update_attached_file( $post_id, $new_path );

        // DB: guid
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update( $wpdb->posts, array( 'guid' => $new_url ), array( 'ID' => $post_id ) );

        // DB: post_title (sadece eski dosya adıyla aynıysa güncelle)
        $current_post = get_post( $post_id );
        if ( $current_post && $current_post->post_title === $old_name ) {
            wp_update_post( array( 'ID' => $post_id, 'post_title' => $new_name ) );
        }

        // DB: Metadata yeniden oluştur
        require_once ABSPATH . 'wp-admin/includes/image.php';
        remove_filter( 'wp_generate_attachment_metadata', 'erdo_auto_webp_convert', 10 );
        $attach_data             = wp_generate_attachment_metadata( $post_id, $new_path );
        add_filter( 'wp_generate_attachment_metadata', 'erdo_auto_webp_convert', 10, 2 );
        clearstatcache();
        $attach_data['filesize'] = file_exists( $new_path ) ? filesize( $new_path ) : 0;
        wp_update_attachment_metadata( $post_id, $attach_data );

        // DB: post_content URL referansları
        $like_old = '%' . $wpdb->esc_like( $old_name . '.' . $ext ) . '%';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s) WHERE post_content LIKE %s",
            $old_url, $new_url, $like_old
        ) );

        // DB: postmeta referansları (page builder meta, gallery IDs vb.)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->postmeta} SET meta_value = REPLACE(meta_value, %s, %s) WHERE meta_value LIKE %s",
            $old_url, $new_url, $like_old
        ) );

        self::clear_cache( $post_id );

        return array(
            'old_name' => $old_name,
            'new_name' => $new_name,
            'new_url'  => $new_url,
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
            $rows[] = array(
                'id'       => $id,
                'filename' => $r['filename'],
                'score'    => $r['score'] . '/6',
                'status'   => $cat,
                'format'   => strtoupper( str_replace( 'image/', '', $r['mime'] ?? '' ) ),
                'alt_text' => get_post_meta( $id, '_wp_attachment_image_alt', true ),
                'title'    => get_the_title( $id ),
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
        $name    = pathinfo( $file, PATHINFO_FILENAME );
        $alt     = ucwords( str_replace( array( '-', '_' ), ' ', sanitize_title( $name ) ) );
        update_post_meta( $post_id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
        self::clear_cache( $post_id );
        return $alt;
    }

    /* ------------------------------------------------------------------
       SVG SANİTİZASYON  (Özellik 7)
       Potansiyel olarak zararlı içerikleri (script, olay handler,
       javascript: URI, foreignObject) SVG'den temizler.
    ------------------------------------------------------------------ */

    public static function sanitize_svg( string $svg_content ): string {
        $doc = new DOMDocument();
        $doc->formatOutput = false;

        // libxml hata uyarılarını bastır; LIBXML_NONET ayrıştırma sırasında
        // hiçbir ağ isteğine (dış DTD/entity vb.) izin vermez.
        $prev = libxml_use_internal_errors( true );
        $doc->loadXML( $svg_content, LIBXML_NONET );
        libxml_clear_errors();
        libxml_use_internal_errors( $prev );

        $xpath = new DOMXPath( $doc );

        // <script> etiketlerini kaldır. local-name() şart: SVG kök elemanı
        // genelde xmlns="http://www.w3.org/2000/svg" ile varsayılan bir ad
        // alanı tanımlar, bu da tüm alt elemanları (script dahil) o ad
        // alanına sokar — prefix'siz "//script" bu durumda HİÇBİR ZAMAN
        // eşleşmez ve script hiç silinmeden kalırdı.
        foreach ( $xpath->query( '//*[local-name()="script"]' ) as $node ) {
            $node->parentNode->removeChild( $node );
        }

        // <foreignObject> etiketlerini kaldır (HTML içerebilir)
        foreach ( $xpath->query( '//*[local-name()="foreignObject"]' ) as $node ) {
            $node->parentNode->removeChild( $node );
        }

        // on* olay handler niteliklerini kaldır
        foreach ( $xpath->query( '//@*[starts-with(local-name(), "on")]' ) as $attr ) {
            $attr->ownerElement->removeAttributeNode( $attr );
        }

        // javascript: içeren href / xlink:href niteliklerini kaldır; ayrıca
        // <image>/<use> üzerindeki MUTLAK harici (http/https) referansları da
        // kaldırır — aksi hâlde SVG görüntülendiğinde sessizce dış bir URL'e
        // istek atılabilir (izleme / bilgi sızıntısı riski). Aynı belge içi
        // (#id) ve data: URI referansları etkilenmez.
        foreach ( $xpath->query( '//@href | //@*[local-name()="href"]' ) as $attr ) {
            $value = trim( $attr->value );

            if ( stripos( $value, 'javascript:' ) !== false ) {
                $attr->ownerElement->removeAttributeNode( $attr );
                continue;
            }

            $tag = strtolower( $attr->ownerElement->localName );
            if ( in_array( $tag, array( 'image', 'use' ), true )
                && '' !== $value
                && '#' !== $value[0]
                && 0 !== stripos( $value, 'data:' )
            ) {
                $attr->ownerElement->removeAttributeNode( $attr );
            }
        }

        return $doc->saveXML() ?: $svg_content;
    }
}
