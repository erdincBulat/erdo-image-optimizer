<?php
/**
 * Uninstall — eklenti silindiğinde çalışır.
 * Tüm eklenti option'ları ve post meta verisi temizlenir.
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

$erdo_options = array(
    'erdo_auto_webp',
    'erdo_auto_sanitize',
    'erdo_remove_exif',
    'erdo_convert_format',
    'erdo_quality',
    'erdo_lossless',
    'erdo_keep_original',
    'erdo_total_saved_bytes',
    'erdo_serve_webp',
    'erdo_webp_serve_method',
    'erdo_lazy_loading',
    'erdo_smart_quality',
    'erdo_max_width',
    'erdo_allow_svg',
    'erdo_queue_ids',
    'erdo_queue_action',
    'erdo_queue_total',
    'erdo_queue_done',
    'erdo_queue_errors',
    'erdo_queue_status',
    'erdo_queue_started',
    'erdo_library_version',
    'erdo_bunny_enabled',
    'erdo_bunny_api_key',
    'erdo_bunny_storage_zone',
    'erdo_bunny_hostname',
);

foreach ( $erdo_options as $erdo_option ) {
    delete_option( $erdo_option );
}

$erdo_meta_keys = array(
    '_erdo_audit_cache',
    '_erdo_savings_bytes',
    '_erdo_original_size',
    '_erdo_backup_path',
    '_erdo_cdn_url',
);

foreach ( $erdo_meta_keys as $erdo_meta_key ) {
    delete_metadata( 'post', 0, $erdo_meta_key, '', true );
}
