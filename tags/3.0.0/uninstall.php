<?php
/**
 * Uninstall — eklenti silindiğinde çalışır.
 * Ayarlar, meta verisi, kullanım tablosu ve .htaccess kuralları temizlenir.
 * Orijinal görseller olduğu gibi kalır. Ayarlardaki "kaldırırken" tercihleri:
 *   erdo_uninstall_siblings   — 'delete' (varsayılan) üretilen WebP/AVIF kopyalarını siler, 'keep' bırakır;
 *   erdo_uninstall_quarantine — 'restore' (varsayılan) karantinadakileri geri koyar, 'delete' kalıcı siler.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

wp_clear_scheduled_hook( 'erdo_bg_process' );

// WebP .htaccess kurallarını kaldır
require_once __DIR__ . '/includes/class-webp-server.php';
ERDO_WebP_Server::remove_htaccess_rules();

global $wpdb;

$erdo_siblings_choice   = get_option( 'erdo_uninstall_siblings', 'delete' );
$erdo_quarantine_choice = get_option( 'erdo_uninstall_quarantine', 'restore' );
$erdo_uploads           = realpath( wp_upload_dir()['basedir'] );

/** Yalnızca uploads içindeki yollar (meta değiştirilmiş olsa bile dışarıya dokunulmaz). */
$erdo_in_uploads = function ( $path ) use ( $erdo_uploads ) {
    $dir = $path ? realpath( dirname( (string) $path ) ) : false;
    return $erdo_uploads && $dir && 0 === strpos( trailingslashit( wp_normalize_path( $dir ) ), trailingslashit( wp_normalize_path( $erdo_uploads ) ) );
};

/** Taşı; rename başarısızsa (Windows'ta kilitli dosya, farklı birim) kopyala + sil. */
$erdo_move = function ( $from, $to ) {
    if ( @rename( $from, $to ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors
        return true;
    }
    if ( @copy( $from, $to ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
        wp_delete_file( $from );
        return true;
    }
    return false;
};

/**
 * Kayıttaki karantina yolu; 3.0 öncesi Windows kayıtlarında ters eğik çizgiler silinmiş
 * olabilir ("C:Users…"), o zaman dosya görselin karantina klasöründe adıyla aranır.
 */
$erdo_qdir   = wp_normalize_path( trailingslashit( wp_upload_dir()['basedir'] ) . '_erdo_quarantine/' );
$erdo_locate = function ( $path, $id ) use ( $erdo_qdir ) {
    if ( file_exists( $path ) ) {
        return $path;
    }
    $found = glob( $erdo_qdir . (int) $id . '-*/' . wp_basename( wp_normalize_path( (string) $path ) ) );
    return $found ? $found[0] : $path;
};

// Karantina: eklenti olmadan özel durum tanınmaz, görseller medya kütüphanesinde
// görünmez kalırdı — ya geri konur ya da (açıkça seçildiyse) kalıcı silinir.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$erdo_quarantined = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_erdo_quarantine'" );
foreach ( $erdo_quarantined as $erdo_q ) {
    $erdo_data = maybe_unserialize( $erdo_q->meta_value );
    $erdo_id   = (int) $erdo_q->post_id;
    if ( 'delete' === $erdo_quarantine_choice ) {
        foreach ( (array) ( $erdo_data['files'] ?? array() ) as $erdo_path ) {
            $erdo_path = $erdo_locate( $erdo_path, $erdo_id );
            if ( file_exists( $erdo_path ) && $erdo_in_uploads( $erdo_path ) ) {
                wp_delete_file( $erdo_path );
            }
        }
        if ( ! empty( $erdo_data['dir'] ) ) {
            @rmdir( $erdo_data['dir'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors
        }
        wp_delete_attachment( $erdo_id, true );
        continue;
    }
    foreach ( (array) ( $erdo_data['files'] ?? array() ) as $erdo_orig => $erdo_path ) {
        $erdo_path = $erdo_locate( $erdo_path, $erdo_id );
        if ( file_exists( $erdo_path ) && ! file_exists( $erdo_orig ) && $erdo_in_uploads( $erdo_path ) && $erdo_in_uploads( $erdo_orig ) ) {
            $erdo_move( $erdo_path, $erdo_orig );
        }
    }
    if ( ! empty( $erdo_data['dir'] ) ) {
        @rmdir( $erdo_data['dir'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors
    }
    // Bir dosya taşınamamış olsa bile görsel kütüphanede görünür kalsın (kırık görünür, kaybolmaz).
    $wpdb->update( $wpdb->posts, array( 'post_status' => 'inherit' ), array( 'ID' => $erdo_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    clean_post_cache( $erdo_id );
}

// Kullanım indeksi tablosu ve zamanlanmış işler
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}erdo_usage" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
wp_clear_scheduled_hook( 'erdo_usage_build' );
wp_clear_scheduled_hook( 'erdo_quarantine_purge' );
wp_unschedule_hook( 'erdo_ai_suggest' );
delete_transient( 'erdo_ai_status' );

// Üretilen kardeş dosyalar ("foto.jpg.webp" vb.). 2.x'ten kalan eski
// .webp dosyalarına (_erdo_legacy_files) dokunulmaz: içerikte kullanılıyor olabilirler.
if ( 'keep' !== $erdo_siblings_choice ) {
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $erdo_rows = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_erdo_formats'" );
    foreach ( $erdo_rows as $erdo_row ) {
        $erdo_record = maybe_unserialize( $erdo_row->meta_value );
        $erdo_file   = get_attached_file( (int) $erdo_row->post_id );
        if ( ! $erdo_file || empty( $erdo_record['formats'] ) || ! is_array( $erdo_record['formats'] ) ) {
            continue;
        }
        foreach ( $erdo_record['formats'] as $erdo_fmt => $erdo_list ) {
            foreach ( array_keys( (array) $erdo_list ) as $erdo_base ) {
                $erdo_path = dirname( $erdo_file ) . '/' . basename( $erdo_base ) . '.' . sanitize_key( $erdo_fmt );
                if ( file_exists( $erdo_path ) && $erdo_in_uploads( $erdo_path ) ) {
                    wp_delete_file( $erdo_path );
                }
            }
        }
    }
}

// Tüm eklenti seçenekleri (erdo_ önekli)
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
foreach ( $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'erdo\\_%'" ) as $erdo_option ) {
    delete_option( $erdo_option );
}

$erdo_meta_keys = array(
    '_erdo_audit_cache',
    '_erdo_formats',
    '_erdo_saved_bytes',
    '_erdo_exif_stripped',
    '_erdo_migrated',
    '_erdo_legacy_files',
    '_erdo_quarantine',
    '_erdo_savings_bytes',
    '_erdo_original_size',
    '_erdo_backup_path',
    '_erdo_cdn_url',
    '_erdo_seo_score',
    '_erdo_decorative',
    '_erdo_redirects',
    '_erdo_old_rel',
    '_erdo_rename_log',
    '_erdo_ai_suggestion',
);

foreach ( $erdo_meta_keys as $erdo_meta_key ) {
    delete_metadata( 'post', 0, $erdo_meta_key, '', true );
}
