<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * ERDO_Converter — orijinale dokunmayan ("kardeş dosya") dönüşüm
 *
 * Orijinal dosya hiçbir zaman silinmez veya değiştirilmez. Ana dosya ve her
 * alt boyut için yanına "foto.jpg.webp" / "foto.jpg.avif" yazılır; kardeş
 * dosya yalnızca kaynaktan küçükse tutulur. Geri alma = kardeşleri silmek.
 *
 * "x.jpg.webp" adlandırması: foto.jpg ile foto.png çakışmaz, nginx
 * "try_files $uri.webp" ve .htaccess "%{REQUEST_FILENAME}.webp" doğrudan
 * çalışır (EWWW / WebP Express ile aynı konvansiyon).
 *
 * Attachment meta:
 *   _erdo_formats     — array( 'formats' => fmt => basename => bytes,
 *                              'larger'  => fmt => basename[],
 *                              'time'    => int )
 *   _erdo_saved_bytes — tüm boyutlarda kaynak − en küçük kardeş toplamı
 */
class ERDO_Converter {

    const META         = '_erdo_formats';
    const SAVED_META   = '_erdo_saved_bytes';
    const SOURCE_MIMES = array( 'image/jpeg', 'image/png', 'image/gif' );
    const SOURCE_EXTS  = array( 'jpg', 'jpeg', 'jpe', 'png', 'gif' );

    public static function init() {
        add_filter( 'wp_generate_attachment_metadata', array( __CLASS__, 'on_generate_metadata' ), 20, 3 );
        // WordPress yalnızca kendi bildiği dosyaları siler; kardeşler sahipsiz kalmasın.
        add_action( 'delete_attachment', array( __CLASS__, 'on_delete_attachment' ) );
    }

    /* ------------------------------------------------------------------
       UPLOAD HATTI
       WP 7.1'de tarayıcıda işlenen yüklemelerde bu filtre iki kez çalışır
       ('create' ve /finalize sonrası 'update'). convert() idempotent:
       güncel kardeşleri yeniden kodlamaz, yalnızca eksikleri tamamlar.
    ------------------------------------------------------------------ */

    public static function on_generate_metadata( $metadata, $attachment_id, $context = 'create' ) {
        if ( ! is_array( $metadata ) ) {
            return $metadata;
        }

        // EXIF temizliği orijinali yeniden yazar — aynı görselde bir kez.
        if ( get_option( 'erdo_remove_exif' ) && ! get_post_meta( $attachment_id, '_erdo_exif_stripped', true ) ) {
            if ( ERDO_Auditor::clean_metadata( $attachment_id ) ) {
                update_post_meta( $attachment_id, '_erdo_exif_stripped', 1 );
            }
        }

        if ( get_option( 'erdo_auto_webp' ) ) {
            self::convert( $attachment_id, $metadata );
        }

        return $metadata;
    }

    /** Dosya işlemi yapan iç akışlar (resize, rename, restore) sırasında yükleme hook'unu durdurur. */
    public static function suspend_upload_hook() {
        remove_filter( 'wp_generate_attachment_metadata', array( __CLASS__, 'on_generate_metadata' ), 20 );
    }

    public static function resume_upload_hook() {
        add_filter( 'wp_generate_attachment_metadata', array( __CLASS__, 'on_generate_metadata' ), 20, 3 );
    }

    public static function on_delete_attachment( $post_id ) {
        self::revert( $post_id );
        // 2.x'ten kalan eski .webp dosyaları (geçişte yerinde bırakılmıştı)
        foreach ( (array) get_post_meta( $post_id, '_erdo_legacy_files', true ) as $f ) {
            if ( $f && file_exists( $f ) ) {
                wp_delete_file( $f );
            }
        }
    }

    /* ------------------------------------------------------------------
       HEDEF FORMATLAR
    ------------------------------------------------------------------ */

    /** Ayara göre ve sunucunun gerçekten kodlayabildiği formatlar (AVIF önce). */
    public static function target_formats() {
        $setting = get_option( 'erdo_convert_format', 'webp' );
        $wanted  = 'both' === $setting ? array( 'avif', 'webp' ) : array( $setting );
        $formats = array_values( array_filter( $wanted, array( 'ERDO_Encoder', 'supports' ) ) );

        // AVIF istendi ama sunucu desteklemiyor → WebP'ye düş.
        if ( empty( $formats ) && ERDO_Encoder::supports( 'webp' ) ) {
            $formats = array( 'webp' );
        }
        return $formats;
    }

    /* ------------------------------------------------------------------
       DÖNÜŞTÜR
    ------------------------------------------------------------------ */

    /**
     * Ana dosya + alt boyutların mutlak yolları (orijinal "-scaled" öncesi
     * dosya hariç: içerikte kullanılmaz, kodlaması pahalıdır).
     * $meta verilirse onu kullanır — upload sırasında metadata henüz DB'de değildir.
     */
    public static function renditions( $attachment_id, $meta = null ) {
        $file = get_attached_file( $attachment_id );
        if ( ! $file ) {
            return array();
        }
        $meta  = is_array( $meta ) ? $meta : wp_get_attachment_metadata( $attachment_id );
        $dir   = dirname( $file );
        $files = array( $file );
        foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
            if ( ! empty( $size['file'] ) ) {
                $files[] = $dir . '/' . $size['file'];
            }
        }

        $out = array();
        foreach ( array_unique( $files ) as $f ) {
            if ( in_array( strtolower( pathinfo( $f, PATHINFO_EXTENSION ) ), self::SOURCE_EXTS, true ) && file_exists( $f ) ) {
                $out[] = $f;
            }
        }
        return $out;
    }

    /**
     * Döner:
     *   array( 'status' => 'ok'|'larger'|'skipped', 'reason' => '', 'created' => int,
     *          'reused' => int, 'larger' => int, 'before' => int, 'after' => int )
     *   veya WP_Error (hiçbir boyut kodlanamadıysa).
     *
     * 'skipped' nedenleri: 'format' (zaten modern format / desteklenmeyen tür),
     *                      'animated' (sunucu animasyonu koruyamıyor).
     */
    public static function convert( $attachment_id, $meta = null, $formats = null ) {
        $result = array( 'status' => 'ok', 'reason' => '', 'created' => 0, 'reused' => 0, 'larger' => 0, 'before' => 0, 'after' => 0 );

        if ( ! in_array( get_post_mime_type( $attachment_id ), self::SOURCE_MIMES, true ) ) {
            return array_merge( $result, array( 'status' => 'skipped', 'reason' => 'format' ) );
        }

        $formats = null === $formats ? self::target_formats() : $formats;
        if ( empty( $formats ) ) {
            return new WP_Error( 'no_encoder', __( 'This server cannot encode WebP or AVIF.', 'erdo-image-optimizer' ) );
        }

        $files = self::renditions( $attachment_id, $meta );
        if ( empty( $files ) ) {
            return new WP_Error( 'not_found', __( 'Image file not found.', 'erdo-image-optimizer' ) );
        }
        // Kardeş dosyalar yalnızca uploads içine yazılır (dışarıya taşınmış/offload edilmiş dosyalar atlanır).
        if ( ! ERDO_References::in_uploads( $files[0] ) ) {
            return array_merge( $result, array( 'status' => 'skipped', 'reason' => 'outside_uploads' ) );
        }

        $opts = array(
            'quality'  => (int) get_option( 'erdo_quality', 80 ),
            'lossless' => (bool) get_option( 'erdo_lossless', false ),
            'smart'    => (bool) get_option( 'erdo_smart_quality', false ),
        );

        $prev   = self::record( $attachment_id );
        $record = array( 'formats' => array(), 'larger' => array(), 'time' => time() );
        $errors = array();

        foreach ( $files as $src ) {
            $base     = basename( $src );
            $src_size = (int) filesize( $src );
            $src_time = (int) filemtime( $src );
            $best     = $src_size;

            foreach ( $formats as $fmt ) {
                $dest = $src . '.' . $fmt;

                // Daha önce denendi ve kaynaktan büyük çıktı; kaynak değişmediyse tekrar deneme.
                if ( in_array( $base, $prev['larger'][ $fmt ] ?? array(), true ) && $src_time <= (int) $prev['time'] ) {
                    $record['larger'][ $fmt ][] = $base;
                    $result['larger']++;
                    continue;
                }

                if ( file_exists( $dest ) && filemtime( $dest ) >= $src_time ) {
                    $bytes = (int) filesize( $dest );
                    $result['reused']++;
                } else {
                    $bytes = ERDO_Encoder::encode( $src, $dest, $fmt, $opts );
                    if ( is_wp_error( $bytes ) ) {
                        if ( 'animated' === $bytes->get_error_code() ) {
                            return array_merge( $result, array( 'status' => 'skipped', 'reason' => 'animated' ) );
                        }
                        $errors[] = $bytes;
                        continue;
                    }
                    $result['created']++;
                }

                if ( $bytes >= $src_size ) {
                    wp_delete_file( $dest );
                    $record['larger'][ $fmt ][] = $base;
                    $result['larger']++;
                    continue;
                }

                $record['formats'][ $fmt ][ $base ] = $bytes;
                $best = min( $best, $bytes );
            }

            // Tarayıcı <picture>'da AVIF'i önce dener: WebP'den büyük bir AVIF
            // kopyası ziyaretçiye daha ağır dosya indirtir — tutma.
            $avif = $record['formats']['avif'][ $base ] ?? null;
            $webp = $record['formats']['webp'][ $base ] ?? null;
            if ( null !== $avif && null !== $webp && $avif >= $webp ) {
                wp_delete_file( $src . '.avif' );
                unset( $record['formats']['avif'][ $base ] );
                $record['larger']['avif'][] = $base;
                $result['larger']++;
            }

            $result['before'] += $src_size;
            $result['after']  += $best;
        }

        $record['formats'] = array_filter( $record['formats'] );

        // Artık bir boyuta karşılık gelmeyen eski kardeşler (ör. thumbnail'ler yeniden üretildi).
        $dir = dirname( $files[0] );
        foreach ( $prev['formats'] as $fmt => $list ) {
            foreach ( array_keys( $list ) as $old_base ) {
                if ( ! isset( $record['formats'][ $fmt ][ $old_base ] ) && file_exists( "$dir/$old_base.$fmt" ) && ! in_array( "$dir/$old_base", $files, true ) ) {
                    wp_delete_file( "$dir/$old_base.$fmt" );
                }
            }
        }

        if ( empty( $record['formats'] ) && ! empty( $errors ) && empty( $record['larger'] ) ) {
            return $errors[0];
        }

        update_post_meta( $attachment_id, self::META, $record );
        if ( ! empty( $record['formats'] ) ) {
            update_post_meta( $attachment_id, self::SAVED_META, max( 0, $result['before'] - $result['after'] ) );
        } else {
            delete_post_meta( $attachment_id, self::SAVED_META );
            $result['status'] = 'larger';
        }

        ERDO_Auditor::clear_cache( $attachment_id );
        ERDO_Auditor::flush_usage_cache();
        return $result;
    }

    /* ------------------------------------------------------------------
       GERİ AL — kardeş dosyaları sil, orijinal zaten yerinde
    ------------------------------------------------------------------ */

    public static function revert( $attachment_id ) {
        $record = self::record( $attachment_id );
        $files  = self::renditions( $attachment_id );
        $file   = get_attached_file( $attachment_id );
        $dir    = $file ? dirname( $file ) : '';

        $bases = array_map( 'basename', $files );
        foreach ( $record['formats'] as $list ) {
            $bases = array_merge( $bases, array_keys( $list ) );
        }

        foreach ( array_unique( $bases ) as $base ) {
            foreach ( ERDO_Encoder::FORMATS as $fmt ) {
                if ( $dir && file_exists( "$dir/$base.$fmt" ) ) {
                    wp_delete_file( "$dir/$base.$fmt" );
                }
            }
        }

        delete_post_meta( $attachment_id, self::META );
        delete_post_meta( $attachment_id, self::SAVED_META );
        ERDO_Auditor::clear_cache( $attachment_id );
        ERDO_Auditor::flush_usage_cache();
        return true;
    }

    /* ------------------------------------------------------------------
       DURUM SORGULARI
    ------------------------------------------------------------------ */

    public static function record( $attachment_id ) {
        $r = get_post_meta( $attachment_id, self::META, true );
        return wp_parse_args( is_array( $r ) ? $r : array(), array( 'formats' => array(), 'larger' => array(), 'time' => 0 ) );
    }

    /** Ziyaretçiye modern format sunulabiliyor mu (kardeş var ya da dosyanın kendisi WebP/AVIF). */
    public static function is_optimized( $attachment_id, $mime = null, $record = null ) {
        $mime = $mime ?? get_post_mime_type( $attachment_id );
        if ( 'image/webp' === $mime || 'image/avif' === $mime ) {
            return true;
        }
        $record = is_array( $record ) ? $record : self::record( $attachment_id );
        return ! empty( $record['formats'] );
    }

    /** Ana dosya için en küçük kardeş: array( 'format' => 'avif', 'bytes' => int ) | null */
    public static function best_main_sibling( $attachment_id, $record = null, $file = null ) {
        $record = is_array( $record ) ? $record : self::record( $attachment_id );
        $base   = basename( $file ?? (string) get_attached_file( $attachment_id ) );
        $best   = null;
        foreach ( $record['formats'] ?? array() as $fmt => $list ) {
            if ( isset( $list[ $base ] ) && ( ! $best || $list[ $base ] < $best['bytes'] ) ) {
                $best = array( 'format' => $fmt, 'bytes' => (int) $list[ $base ] );
            }
        }
        return $best;
    }

    /** Kütüphane özeti — dashboard, widget, REST ve CLI aynı sayıları gösterir. */
    public static function library_stats() {
        global $wpdb;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status <> 'erdo_quarantine' AND post_mime_type LIKE %s",
            $wpdb->esc_like( 'image/' ) . '%'
        ) );
        $optimized = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
             WHERE p.post_type = 'attachment' AND p.post_status <> 'erdo_quarantine' AND p.post_mime_type LIKE %s
             AND ( m.meta_id IS NOT NULL OR p.post_mime_type IN ('image/webp','image/avif') )",
            self::SAVED_META,
            $wpdb->esc_like( 'image/' ) . '%'
        ) );
        $saved = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT SUM(meta_value) FROM {$wpdb->postmeta} WHERE meta_key = %s",
            self::SAVED_META
        ) );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        return array(
            'total'     => $total,
            'optimized' => $optimized,
            'rate'      => $total > 0 ? (int) round( $optimized / $total * 100 ) : 0,
            'saved'     => $saved,
        );
    }

    /** Henüz kardeşi olmayan dönüştürülebilir görseller (toplu işlem listesi). */
    public static function pending_ids( $limit = -1, $date_query = null ) {
        $args = array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => self::SOURCE_MIMES,
            'posts_per_page' => $limit,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
                array( 'key' => self::META, 'compare' => 'NOT EXISTS' ),
            ),
        );
        if ( $date_query ) {
            $args['date_query'] = $date_query;
        }
        return get_posts( $args );
    }
}
