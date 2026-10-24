<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * ERDO_Migration — 2.x → 3.0 yükseltme ve onarım
 *
 * 2.x dönüşümü orijinali silip attachment'ı .webp'ye çeviriyordu; içerikteki
 * .jpg/.png URL'leri güncellenmediği için bu görseller sitede kırık görünür.
 * Bu sınıf her etkilenen görseli iki yoldan biriyle onarır:
 *
 *   'restore' — _erdo_backup/ altında yedek varsa orijinal yerine geri konur,
 *               alt boyutlar yeniden üretilir (eski .jpg URL'leri tekrar çalışır),
 *               WebP kardeşleri 3.0 modeliyle yeniden oluşturulur.
 *   'repoint' — yedek yoksa WebP ana dosya olarak kalır; içerik, meta ve
 *               ayarlardaki eski .jpg/.png URL'leri WebP karşılıklarına çevrilir.
 *
 * Her iki yolda da eski .webp dosyaları silinmez (2.x sonrası içeriğe
 * eklenmiş olabilirler); attachment silinince temizlenmek üzere kaydedilir.
 * Onarım tamamlanana kadar ERDO_WebP_Server'ın kurtarma filtresi açık kalır.
 */
class ERDO_Migration {

    const DB_VERSION     = 4;
    const LEGACY_OPTION  = 'erdo_legacy_present';
    const LEGACY_MARKERS = array( '_erdo_savings_bytes', '_erdo_original_size', '_erdo_backup_path' );

    /* ------------------------------------------------------------------
       YÜKSELTME RUTİNİ — her yüklemede ucuz sürüm kontrolü
    ------------------------------------------------------------------ */

    public static function maybe_upgrade() {
        $from = (int) get_option( 'erdo_db_version', 0 );
        if ( $from >= self::DB_VERSION ) {
            return;
        }

        if ( $from < 3 ) {
            // 3.0'da kaldırılan ayarlar
            delete_option( 'erdo_keep_original' );
            delete_option( 'erdo_lazy_loading' );

            self::refresh_legacy_flag();

            // 2.x'in .htaccess kuralı yanlış dosya adını arıyordu — doğrusuyla değiştir.
            if ( get_option( 'erdo_serve_webp' ) && 'htaccess' === get_option( 'erdo_webp_serve_method' ) ) {
                ERDO_WebP_Server::write_htaccess_rules();
            }
        }

        if ( $from < 4 ) {
            // Kullanım indeksi tablosu + arka planda ilk kurulum
            ERDO_Usage::start_build();
        }

        update_option( 'erdo_db_version', self::DB_VERSION );
    }

    /** Onarım bekleyen görsel sayısını yeniden hesaplar ve kurtarma filtresinin bayrağını günceller. */
    public static function refresh_legacy_flag() {
        $count = count( self::find_legacy() );
        if ( $count > 0 ) {
            update_option( self::LEGACY_OPTION, $count );
        } else {
            delete_option( self::LEGACY_OPTION );
        }
        return $count;
    }

    /* ------------------------------------------------------------------
       TESPİT
    ------------------------------------------------------------------ */

    /** 2.x'in yıkıcı biçimde dönüştürdüğü ve henüz onarılmamış attachment ID'leri. */
    public static function find_legacy( $limit = 0 ) {
        global $wpdb;
        $markers = implode( ',', array_fill( 0, count( self::LEGACY_MARKERS ), '%s' ) );
        $limit   = $limit > 0 ? (int) $limit : PHP_INT_MAX;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
             WHERE p.post_type = 'attachment'
             AND p.post_mime_type IN ('image/webp','image/avif')
             AND m.meta_key IN ($markers)
             ORDER BY p.ID LIMIT %d",
            ...array_merge( self::LEGACY_MARKERS, array( $limit ) )
        ) );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        return array_map( 'intval', $ids );
    }

    /** Tek görsel için ne yapılacağı (dry-run raporu). */
    public static function plan( $attachment_id ) {
        $backup = get_post_meta( $attachment_id, '_erdo_backup_path', true );
        $has    = $backup && file_exists( $backup );
        return array(
            'id'       => $attachment_id,
            'file'     => basename( (string) get_attached_file( $attachment_id ) ),
            'mode'     => $has ? 'restore' : 'repoint',
            'backup'   => $has ? basename( $backup ) : '',
            'refs'     => self::count_references( $attachment_id ),
        );
    }

    /* ------------------------------------------------------------------
       ONARIM
    ------------------------------------------------------------------ */

    /**
     * Bir görseli onarır. Döner: array( 'mode' => ..., 'refs' => değişen satır sayısı ) | WP_Error
     */
    public static function migrate( $attachment_id ) {
        $plan = self::plan( $attachment_id );
        $old  = self::legacy_files( $attachment_id );

        if ( 'restore' === $plan['mode'] ) {
            $res = self::restore_from_backup( $attachment_id );
        } else {
            $res = self::repoint_references( $attachment_id );
        }

        if ( is_wp_error( $res ) ) {
            return $res;
        }

        // Eski .webp dosyalarını kaydet (silinmez, attachment silinince temizlenir).
        $keep = array_values( array_filter( $old, 'file_exists' ) );
        if ( 'restore' === $plan['mode'] && $keep ) {
            update_post_meta( $attachment_id, '_erdo_legacy_files', $keep );
        }

        foreach ( self::LEGACY_MARKERS as $key ) {
            delete_post_meta( $attachment_id, $key );
        }
        update_post_meta( $attachment_id, '_erdo_migrated', $plan['mode'] );
        ERDO_Auditor::clear_cache( $attachment_id );

        return array( 'mode' => $plan['mode'], 'refs' => $res );
    }

    /** $limit kadar görseli onarır (AJAX adımı / CLI). */
    public static function run_batch( $limit = 10 ) {
        $done   = array( 'restore' => 0, 'repoint' => 0, 'errors' => array() );
        foreach ( self::find_legacy( $limit ) as $id ) {
            $r = self::migrate( $id );
            if ( is_wp_error( $r ) ) {
                // Tekrar tekrar denenmesin: işaretleri kaldırıp hatayı kaydet.
                foreach ( self::LEGACY_MARKERS as $key ) {
                    delete_post_meta( $id, $key );
                }
                update_post_meta( $id, '_erdo_migrated', 'error: ' . $r->get_error_message() );
                $done['errors'][ $id ] = $r->get_error_message();
            } else {
                $done[ $r['mode'] ]++;
            }
        }
        $done['remaining'] = self::refresh_legacy_flag();
        ERDO_Auditor::flush_usage_cache();
        return $done;
    }

    private static function restore_from_backup( $attachment_id ) {
        $backup  = get_post_meta( $attachment_id, '_erdo_backup_path', true );
        $current = get_attached_file( $attachment_id );
        $target  = trailingslashit( dirname( $current ) ) . basename( $backup );

        if ( file_exists( $target ) && filesize( $target ) !== filesize( $backup ) ) {
            $target = trailingslashit( dirname( $current ) ) . wp_unique_filename( dirname( $current ), basename( $backup ) );
        }
        if ( ! file_exists( $target ) && ! copy( $backup, $target ) ) {
            return new WP_Error( 'copy_failed', 'Could not copy the backup back into place.' );
        }

        $type = wp_check_filetype( $target );
        update_attached_file( $attachment_id, $target );
        wp_update_post( array( 'ID' => $attachment_id, 'post_mime_type' => $type['type'] ) );

        require_once ABSPATH . 'wp-admin/includes/image.php';
        ERDO_Converter::suspend_upload_hook();
        $meta = wp_generate_attachment_metadata( $attachment_id, $target );
        ERDO_Converter::resume_upload_hook();
        wp_update_attachment_metadata( $attachment_id, $meta );

        // Orijinal artık uploads'ta — yedek kopyaya gerek yok.
        if ( filesize( $target ) === filesize( $backup ) ) {
            wp_delete_file( $backup );
        }
        delete_post_meta( $attachment_id, '_erdo_backup_path' );

        // 2.x ile dönüştürülmüştü → kullanıcı modern format istiyor: kardeşleri üret.
        ERDO_Converter::convert( $attachment_id );

        // İçerikte 2.x sonrası eklenmiş .webp URL'leri olabilir; eski dosyalar yerinde kaldığı için çalışmaya devam ederler.
        return 0;
    }

    /**
     * Yedek yok: içerik/meta/ayarlardaki "foto(-300x200).jpg|png|gif" URL'lerini,
     * diskte var olan "foto(-300x200).webp" karşılıklarına çevirir.
     */
    private static function repoint_references( $attachment_id ) {
        $file = get_attached_file( $attachment_id );
        if ( ! $file || ! file_exists( $file ) ) {
            return new WP_Error( 'not_found', 'Converted file is missing.' );
        }

        $stem    = pathinfo( $file, PATHINFO_FILENAME );
        $new_ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
        $rel_dir = trailingslashit( dirname( _wp_relative_upload_path( $file ) ) );
        $rel_dir = './' === $rel_dir ? '' : $rel_dir;
        $regex   = '#^' . preg_quote( $rel_dir . $stem, '#' ) . '(-\d+x\d+)?\.(?:jpe?g|png|gif)$#i';

        $counts = ERDO_References::replace( function ( $rel ) use ( $regex, $new_ext ) {
            if ( ! preg_match( $regex, $rel, $m ) ) {
                return null;
            }
            $candidate = preg_replace( '#\.[^.]+$#', '.' . $new_ext, $rel );
            return file_exists( ERDO_References::rel_to_path( $candidate ) ) ? $candidate : null;
        }, $stem );

        return array_sum( $counts );
    }

    /** İçerikte bu görselin eski uzantılı URL'lerini içeren satır sayısı (rapor için, değişiklik yapmaz). */
    private static function count_references( $attachment_id ) {
        $file = get_attached_file( $attachment_id );
        if ( ! $file ) {
            return 0;
        }
        $stem   = pathinfo( $file, PATHINFO_FILENAME );
        $counts = ERDO_References::replace( function ( $rel ) use ( $stem ) {
            return preg_match( '#/' . preg_quote( $stem, '#' ) . '(-\d+x\d+)?\.(?:jpe?g|png|gif)$#i', '/' . $rel ) ? $rel . '#' : null;
        }, $stem, true );
        return array_sum( $counts );
    }

    /** 2.x'in bıraktığı .webp/.avif ana dosya + alt boyut yolları. */
    private static function legacy_files( $attachment_id ) {
        $file = get_attached_file( $attachment_id );
        if ( ! $file ) {
            return array();
        }
        $meta  = wp_get_attachment_metadata( $attachment_id );
        $files = array( $file );
        foreach ( (array) ( $meta['sizes'] ?? array() ) as $s ) {
            if ( ! empty( $s['file'] ) ) {
                $files[] = dirname( $file ) . '/' . $s['file'];
            }
        }
        return $files;
    }
}
