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
    '_erdo_savings_bytes',
    '_erdo_original_size',
    '_erdo_backup_path',
    '_erdo_cdn_url',
);

foreach ( $erdo_meta_keys as $erdo_meta_key ) {
    delete_metadata( 'post', 0, $erdo_meta_key, '', true );
}
