<?php
/**
 * Plugin Name:       Erdo Image Optimizer
 * Description:       Image SEO audit, safe renaming, AI alt text (your own key), unused-image cleanup and non-destructive WebP/AVIF.
 * Version:           3.0.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            Erdinc Bulat
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       erdo-image-optimizer
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'ERDO_PATH',    plugin_dir_path( __FILE__ ) );
define( 'ERDO_URL',     plugin_dir_url( __FILE__ ) );
define( 'ERDO_VERSION', '3.0.0' );

require_once ERDO_PATH . 'includes/class-activity.php';
require_once ERDO_PATH . 'includes/class-auditor.php';
require_once ERDO_PATH . 'includes/class-encoder.php';
require_once ERDO_PATH . 'includes/class-converter.php';
require_once ERDO_PATH . 'includes/class-references.php';
require_once ERDO_PATH . 'includes/class-migration.php';
require_once ERDO_PATH . 'includes/class-usage.php';
require_once ERDO_PATH . 'includes/class-quarantine.php';
require_once ERDO_PATH . 'includes/class-seo.php';
require_once ERDO_PATH . 'includes/class-renamer.php';
require_once ERDO_PATH . 'includes/class-sitemap.php';
require_once ERDO_PATH . 'includes/class-ai.php';
require_once ERDO_PATH . 'includes/class-admin-page.php';
require_once ERDO_PATH . 'includes/class-webp-server.php';
require_once ERDO_PATH . 'includes/class-queue.php';
require_once ERDO_PATH . 'includes/class-cli.php';

/* ------------------------------------------------------------------
   BAŞLATMA
------------------------------------------------------------------ */

function erdo_init() {
    // Site dili tr_TR ise languages/ klasöründen Türkçe yüklenir, diğerlerinde İngilizce kalır.
    $locale = determine_locale();
    $mofile = ERDO_PATH . 'languages/erdo-image-optimizer-' . $locale . '.mo';
    if ( file_exists( $mofile ) ) {
        load_textdomain( 'erdo-image-optimizer', $mofile );
    }

    ERDO_Migration::maybe_upgrade();
    ERDO_Converter::init();
    ERDO_Usage::init();
    ERDO_Renamer::init();
    ERDO_Sitemap::init();
    ERDO_AI::init();
    new ERDO_Admin_Page();
    ERDO_Queue::register_interval();
    ERDO_WebP_Server::init();
}
add_action( 'plugins_loaded', 'erdo_init' );

// Post durumu kaydı ve çeviri gerektiren etiketler 'init'te yapılmalı.
add_action( 'init', array( 'ERDO_Quarantine', 'init' ) );

/* ------------------------------------------------------------------
   ETKİNLEŞTİRME / DEVRE DIŞI BIRAKMA
   .htaccess kuralları eklenti kapalıyken kalmamalı.
------------------------------------------------------------------ */

function erdo_activate() {
    if ( get_option( 'erdo_serve_webp' ) && 'htaccess' === get_option( 'erdo_webp_serve_method' ) ) {
        ERDO_WebP_Server::write_htaccess_rules();
    }
}
register_activation_hook( __FILE__, 'erdo_activate' );

function erdo_deactivate() {
    ERDO_WebP_Server::remove_htaccess_rules();
    wp_clear_scheduled_hook( ERDO_Queue::HOOK );
    wp_clear_scheduled_hook( ERDO_Usage::CRON_HOOK );
    wp_clear_scheduled_hook( ERDO_Quarantine::PURGE_HOOK );
    wp_unschedule_hook( ERDO_AI::CRON_HOOK );
}
register_deactivation_hook( __FILE__, 'erdo_deactivate' );

/* ------------------------------------------------------------------
   CRON HOOK
------------------------------------------------------------------ */

add_action( ERDO_Queue::HOOK, array( 'ERDO_Queue', 'process_batch' ) );

/* ------------------------------------------------------------------
   AUDIT CACHE YENİLE (attachment değişince)
------------------------------------------------------------------ */

function erdo_clear_attachment_cache( $post_id ) {
    if ( class_exists( 'ERDO_Auditor' ) ) {
        ERDO_Auditor::clear_cache( $post_id );
        // wp_update_post() bir attachment'in post_parent'ını değiştirdiğinde de
        // (örn. medya kütüphanesinde "Attach"/"Detach") bu hook tetiklenir —
        // bulk_check_usage()'ın post_parent cache'i de burada bayatlamış olabilir.
        ERDO_Auditor::flush_usage_cache();
    }
    erdo_bump_library_version();
}
add_action( 'edit_attachment',   'erdo_clear_attachment_cache' );
add_action( 'add_attachment',    'erdo_clear_attachment_cache' );
add_action( 'delete_attachment', 'erdo_bump_library_version' );

/**
 * Bir görsel bir yazının öne çıkan görseli yapıldığında/kaldırıldığında,
 * usage-check sonuçlarını 300 saniye önbelleğe alan 'erdo_image_optimizer'
 * cache grubu bayatlar (bulk_check_usage()'daki featured-image kontrolü).
 * Bu, önbellek yenilenene kadar yeni atanmış bir görselin yanlışlıkla
 * "kullanılmıyor" görünmesine (ve "Kullanılmayanları Sil" ile silinmesine)
 * yol açabilir — bu yüzden değişiklik anında hem cache grubu hem de yeni
 * atanan görselin audit cache'i temizlenir.
 */
function erdo_clear_usage_cache_on_thumbnail_change( $meta_id, $post_id, $meta_key, $meta_value ) {
    if ( '_thumbnail_id' !== $meta_key || ! class_exists( 'ERDO_Auditor' ) ) {
        return;
    }

    ERDO_Auditor::flush_usage_cache();

    if ( $meta_value ) {
        ERDO_Auditor::clear_cache( (int) $meta_value );
    }
}
add_action( 'added_post_meta',   'erdo_clear_usage_cache_on_thumbnail_change', 10, 4 );
add_action( 'updated_post_meta', 'erdo_clear_usage_cache_on_thumbnail_change', 10, 4 );
add_action( 'deleted_post_meta', 'erdo_clear_usage_cache_on_thumbnail_change', 10, 4 );

/**
 * Kütüphane versiyonunu günceller.
 * JS tarafı bu değeri cache doğrulaması için kullanır.
 */
function erdo_bump_library_version() {
    update_option( 'erdo_library_version', time(), false );
}

/* ------------------------------------------------------------------
   DOSYA ADI SANİTİZASYONU  (Özellik: Sanitize Filenames)
------------------------------------------------------------------ */

function erdo_auto_sanitize_filename( $filename ) {
    if ( ! get_option( 'erdo_auto_sanitize' ) ) {
        return $filename;
    }
    $info = pathinfo( $filename );
    $ext  = empty( $info['extension'] ) ? '' : '.' . $info['extension'];
    $name = sanitize_title( basename( $filename, $ext ) );
    $name = str_replace( '_', '-', $name );
    return $name . $ext;
}
add_filter( 'sanitize_file_name', 'erdo_auto_sanitize_filename', 10 );

/* ------------------------------------------------------------------
   UPLOAD'DA MAKSİMUM BOYUT
   Core'un "big image" eşiği kullanılır: büyük yüklemeden "-scaled" kopya
   üretilir, orijinal korunur. Hem sunucu hem WP 7.1 tarayıcı tarafı
   işleme bu filtreye uyar.
------------------------------------------------------------------ */

function erdo_big_image_threshold( $threshold ) {
    $max = (int) get_option( 'erdo_max_width', 0 );
    return $max > 0 ? $max : $threshold;
}
add_filter( 'big_image_size_threshold', 'erdo_big_image_threshold' );

/* WebP/AVIF dönüşümü ve EXIF temizliği: ERDO_Converter::on_generate_metadata()
   Lazy loading: WordPress 5.5+ (ve 6.3+ LCP/fetchpriority mantığı) yönetir. */
