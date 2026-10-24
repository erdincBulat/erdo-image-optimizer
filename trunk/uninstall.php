<?php
/**
 * Uninstall — eklenti silindiğinde çalışır.
 * Tüm eklenti option'ları, post meta verisi, .htaccess kuralları ve eklentinin
 * ürettiği WebP/AVIF kopyaları temizlenir. Orijinal görseller olduğu gibi kalır.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Arka plan kuyruğunu iptal et
if ( wp_next_scheduled( 'erdo_bg_process' ) ) {
    wp_clear_scheduled_hook( 'erdo_bg_process' );
}

// WebP .htaccess kurallarını kaldır
require_once __DIR__ . '/includes/class-webp-server.php';
ERDO_WebP_Server::remove_htaccess_rules();

global $wpdb;

// Karantinadaki görselleri geri koy: eklenti olmadan özel durumları
// tanınmaz ve medya kütüphanesinde görünmez kalırlardı.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$erdo_quarantined = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_erdo_quarantine'" );
foreach ( $erdo_quarantined as $erdo_q ) {
    $erdo_data = maybe_unserialize( $erdo_q->meta_value );
    foreach ( (array) ( $erdo_data['files'] ?? array() ) as $erdo_orig => $erdo_path ) {
        if ( file_exists( $erdo_path ) && ! file_exists( $erdo_orig ) ) {
            @rename( $erdo_path, $erdo_orig ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors
        }
    }
    $wpdb->update( $wpdb->posts, array( 'post_status' => 'inherit' ), array( 'ID' => (int) $erdo_q->post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
}

// Kullanım indeksi tablosu ve zamanlanmış işler
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}erdo_usage" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
wp_clear_scheduled_hook( 'erdo_usage_build' );
wp_clear_scheduled_hook( 'erdo_quarantine_purge' );

// Üretilen kardeş dosyaları sil ("foto.jpg.webp" vb.). 2.x'ten kalan eski
// .webp dosyalarına (_erdo_legacy_files) dokunulmaz: içerikte kullanılıyor olabilirler.
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
            if ( file_exists( $erdo_path ) ) {
                wp_delete_file( $erdo_path );
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
);

foreach ( $erdo_meta_keys as $erdo_meta_key ) {
    delete_metadata( 'post', 0, $erdo_meta_key, '', true );
}
