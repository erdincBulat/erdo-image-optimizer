<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * ERDO_Quarantine — kalıcı silme yerine geri alınabilir karantina
 *
 * Görselin tüm dosyaları (ana dosya, alt boyutlar, "-scaled" öncesi orijinal,
 * WebP/AVIF kardeşleri, 2.x kalıntıları) uploads/_erdo_quarantine/{id}-{belirteç}/
 * altına taşınır, attachment kaydı silinmez ve
 * medya kütüphanesinden gizlenir. Geri yükleme dosyaları yerine koyar;
 * kalıcı silme ayrı ve açık bir eylemdir.
 *
 * Erişim: Apache'de klasördeki .htaccess her şeyi reddeder. nginx ve PHP'nin
 * yerleşik sunucusu .htaccess'i okumaz — bu yüzden klasör adı tahmin
 * edilemez bir belirteç içerir; URL bilinmeden dosyaya ulaşılamaz.
 *
 * Toplu karantina iki katmanlı denetimden geçer: kullanım indeksi + dosya
 * adının sitede herhangi bir yerde geçip geçmediği. Biri bile "kullanılıyor
 * olabilir" derse görsel taşınmaz.
 */
class ERDO_Quarantine {

    const STATUS     = 'erdo_quarantine';
    const META       = '_erdo_quarantine';
    const DIR        = '_erdo_quarantine';
    const PURGE_HOOK = 'erdo_quarantine_purge';

    public static function init() {
        register_post_status( self::STATUS, array(
            'label'                     => _x( 'Quarantine', 'post status', 'erdo-image-optimizer' ),
            'public'                    => false,
            'internal'                  => true,
            'exclude_from_search'       => true,
            'show_in_admin_all_list'    => false,
            'show_in_admin_status_list' => false,
        ) );

        add_action( self::PURGE_HOOK, array( __CLASS__, 'purge_expired' ) );
        $days = (int) get_option( 'erdo_quarantine_days', 0 );
        if ( $days > 0 && ! wp_next_scheduled( self::PURGE_HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::PURGE_HOOK );
        } elseif ( $days <= 0 && wp_next_scheduled( self::PURGE_HOOK ) ) {
            wp_clear_scheduled_hook( self::PURGE_HOOK );
        }
    }

    /* ------------------------------------------------------------------
       KARANTİNAYA AL
    ------------------------------------------------------------------ */

    /**
     * $strict = true (toplu işlem): kullanımda olabilecek görseli reddeder.
     * $strict = false (kullanıcı tek görsel için açıkça istedi): yalnızca uyarı bilgisi döner.
     * Döner: array( 'in_use' => bool ) | WP_Error
     */
    public static function quarantine( $attachment_id, $strict = true ) {
        $post = get_post( $attachment_id );
        if ( ! $post || 'attachment' !== $post->post_type || 0 !== strpos( (string) $post->post_mime_type, 'image/' ) ) {
            return new WP_Error( 'not_image', __( 'Not an image attachment.', 'erdo-image-optimizer' ) );
        }
        if ( self::STATUS === $post->post_status ) {
            return new WP_Error( 'already', __( 'Already in quarantine.', 'erdo-image-optimizer' ) );
        }

        $in_use = false;
        if ( ERDO_Usage::is_ready() ) {
            $in_use = ERDO_Usage::is_used( $attachment_id );
        } elseif ( $strict ) {
            return new WP_Error( 'index_not_ready', __( 'The usage index is still being built. Bulk cleanup is available once it has finished.', 'erdo-image-optimizer' ) );
        }
        if ( $strict && $in_use ) {
            return new WP_Error( 'in_use', __( 'This image is used on the site.', 'erdo-image-optimizer' ) );
        }
        if ( $strict && ERDO_Usage::name_appears_anywhere( $attachment_id ) ) {
            return new WP_Error( 'maybe_in_use', __( 'The file name appears somewhere on the site, so the image may still be in use.', 'erdo-image-optimizer' ) );
        }

        // Yollar ileri eğik çizgiyle saklanır: update_post_meta() ters eğik çizgileri siler
        // (wp_unslash); Windows'ta "C:\Users…" "C:Users…" olur ve geri yükleme dosyayı bulamaz.
        $dest_dir = wp_normalize_path( self::dir() . $attachment_id . '-' . strtolower( wp_generate_password( 16, false ) ) . '/' );
        if ( ! wp_mkdir_p( $dest_dir ) ) {
            return new WP_Error( 'mkdir', __( 'Could not create the quarantine folder.', 'erdo-image-optimizer' ) );
        }

        $moved = array();
        foreach ( self::files_of( $attachment_id ) as $src ) {
            $dest = $dest_dir . basename( $src );
            if ( ! @rename( $src, $dest ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors
                foreach ( $moved as $orig => $q ) { // geri al
                    @rename( $q, $orig ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors
                }
                /* translators: %s: file name */
                return new WP_Error( 'move_failed', sprintf( __( 'Could not move %s.', 'erdo-image-optimizer' ), basename( $src ) ) );
            }
            $moved[ wp_normalize_path( $src ) ] = $dest;
        }

        update_post_meta( $attachment_id, self::META, array(
            'time'   => time(),
            'dir'    => $dest_dir,
            'files'  => $moved,
            'status' => $post->post_status,
            'in_use' => $in_use,
        ) );
        self::set_status( $attachment_id, self::STATUS );
        ERDO_Activity::log( 'quarantine' );

        return array( 'in_use' => $in_use );
    }

    /* ------------------------------------------------------------------
       GERİ YÜKLE / KALICI SİL
    ------------------------------------------------------------------ */

    public static function restore( $attachment_id ) {
        $data = get_post_meta( $attachment_id, self::META, true );
        if ( ! is_array( $data ) ) {
            return new WP_Error( 'not_quarantined', __( 'This image is not in quarantine.', 'erdo-image-optimizer' ) );
        }
        $failed = array();
        $dirs   = array();
        foreach ( (array) $data['files'] as $orig => $q ) {
            $q = self::locate( $q, $attachment_id );
            if ( ! self::in_quarantine( $q ) || ! ERDO_References::in_uploads( $orig ) ) {
                $failed[] = basename( $orig );
                continue;
            }
            if ( file_exists( $q ) ) {
                $dirs[] = dirname( $q );
                wp_mkdir_p( dirname( $orig ) );
                if ( ! @rename( $q, $orig ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors
                    $failed[] = basename( $orig );
                }
            }
        }
        if ( $failed ) {
            /* translators: %s: comma-separated file names */
            return new WP_Error( 'move_failed', sprintf( __( 'Could not restore: %s', 'erdo-image-optimizer' ), implode( ', ', $failed ) ) );
        }
        foreach ( array_unique( $dirs ) as $d ) {
            @rmdir( $d ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors
        }
        delete_post_meta( $attachment_id, self::META );
        self::set_status( $attachment_id, ! empty( $data['status'] ) && self::STATUS !== $data['status'] ? $data['status'] : 'inherit' );
        ERDO_Activity::log( 'restore' );
        return true;
    }

    /**
     * Kalıcı silme: karantinadaki dosyaları doğrudan siler, sonra attachment
     * kaydını WordPress'in kendi akışıyla kaldırır (çekirdeğin dosya silme
     * adımına güvenmeyiz — dosyalar zaten yerlerinde değil).
     */
    public static function purge( $attachment_id ) {
        $data = get_post_meta( $attachment_id, self::META, true );
        if ( ! is_array( $data ) ) {
            return new WP_Error( 'not_quarantined', __( 'This image is not in quarantine.', 'erdo-image-optimizer' ) );
        }
        $dirs = array();
        foreach ( (array) $data['files'] as $q ) {
            $q = self::locate( $q, $attachment_id );
            if ( file_exists( $q ) && self::in_quarantine( $q ) ) {
                $dirs[] = dirname( $q );
                wp_delete_file( $q );
            }
        }
        foreach ( array_unique( $dirs ) as $d ) {
            @rmdir( $d ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors
        }
        delete_post_meta( $attachment_id, self::META );
        if ( ! wp_delete_attachment( $attachment_id, true ) ) {
            return new WP_Error( 'delete_failed', __( 'Delete failed.', 'erdo-image-optimizer' ) );
        }
        ERDO_Activity::log( 'purge' );
        return true;
    }

    /** "N gün sonra kalıcı sil" ayarı (varsayılan kapalı). */
    public static function purge_expired() {
        $days = (int) get_option( 'erdo_quarantine_days', 0 );
        if ( $days <= 0 ) {
            return;
        }
        foreach ( self::ids() as $id ) {
            $data = get_post_meta( $id, self::META, true );
            if ( is_array( $data ) && $data['time'] < time() - $days * DAY_IN_SECONDS ) {
                self::purge( $id );
            }
        }
    }

    /* ------------------------------------------------------------------
       YARDIMCILAR
    ------------------------------------------------------------------ */

    public static function ids() {
        return array_map( 'intval', get_posts( array(
            'post_type'      => 'attachment',
            'post_status'    => self::STATUS,
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'DESC',
        ) ) );
    }

    public static function dir() {
        $dir = trailingslashit( wp_upload_dir()['basedir'] ) . self::DIR . '/';
        if ( ! file_exists( $dir . '.htaccess' ) ) {
            wp_mkdir_p( $dir );
            // Karantinadaki dosyalar web'den sunulmaz.
            file_put_contents( $dir . '.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
            file_put_contents( $dir . 'index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
        }
        return $dir;
    }

    /**
     * Kayıttaki karantina yolu. 3.0 öncesi Windows kayıtlarında ters eğik çizgiler
     * silinmiş olabilir; dosya orada yoksa görselin karantina klasöründe adıyla aranır.
     */
    private static function locate( $path, $attachment_id ) {
        if ( file_exists( $path ) ) {
            return $path;
        }
        $found = glob( wp_normalize_path( self::dir() ) . (int) $attachment_id . '-*/' . wp_basename( wp_normalize_path( (string) $path ) ) );
        return $found ? $found[0] : $path;
    }

    /** Yol karantina klasörünün içinde mi (geri yükleme ve kalıcı silme yalnızca buradan yapılır). */
    private static function in_quarantine( $path ) {
        $q   = realpath( self::dir() );
        $dir = realpath( dirname( (string) $path ) );
        return $q && $dir && 0 === strpos( trailingslashit( wp_normalize_path( $dir ) ), trailingslashit( wp_normalize_path( $q ) ) );
    }

    /** Görsele ait diskteki tüm dosyalar. */
    public static function files_of( $attachment_id ) {
        $file = get_attached_file( $attachment_id );
        if ( ! $file ) {
            return array();
        }
        $dir   = dirname( $file );
        $meta  = wp_get_attachment_metadata( $attachment_id );
        $files = array( $file );
        if ( ! empty( $meta['original_image'] ) ) {
            $files[] = $dir . '/' . $meta['original_image'];
        }
        foreach ( (array) ( $meta['sizes'] ?? array() ) as $s ) {
            if ( ! empty( $s['file'] ) ) {
                $files[] = $dir . '/' . $s['file'];
            }
        }
        // Medya düzenleyicisinin sakladığı orijinaller (döndürme/kırpma öncesi)
        foreach ( (array) get_post_meta( $attachment_id, '_wp_attachment_backup_sizes', true ) as $s ) {
            if ( ! empty( $s['file'] ) ) {
                $files[] = $dir . '/' . wp_basename( $s['file'] );
            }
        }
        foreach ( $files as $f ) {
            foreach ( ERDO_Encoder::FORMATS as $fmt ) {
                $files[] = $f . '.' . $fmt;
            }
        }
        $files = array_merge( $files, (array) get_post_meta( $attachment_id, '_erdo_legacy_files', true ) );
        // Yalnızca uploads içindeki dosyalara dokunulur (meta elle değiştirilmiş olsa bile).
        return array_values( array_filter( array_unique( $files ), function ( $f ) {
            return $f && file_exists( $f ) && ERDO_References::in_uploads( $f );
        } ) );
    }

    /**
     * wp_update_post() attachment durumunu yalnızca inherit/private/trash'e
     * izin verir; özel durum doğrudan yazılır ve cache temizlenir.
     */
    private static function set_status( $attachment_id, $status ) {
        global $wpdb;
        $wpdb->update( $wpdb->posts, array( 'post_status' => $status ), array( 'ID' => $attachment_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        clean_post_cache( $attachment_id );
        ERDO_Auditor::clear_cache( $attachment_id );
        ERDO_Auditor::flush_usage_cache();
        if ( function_exists( 'erdo_bump_library_version' ) ) {
            erdo_bump_library_version();
        }
    }
}
