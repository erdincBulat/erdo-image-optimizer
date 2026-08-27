<?php
/**
 * Plugin Name:       Erdo Image Optimizer
 * Description:       WebP/AVIF conversion, SEO audit, lazy loading, SVG support, background queue, WP-CLI and more.
 * Version:           2.2.2
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Erdinc Bulat
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       erdo-image-optimizer
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'ERDO_PATH',    plugin_dir_path( __FILE__ ) );
define( 'ERDO_URL',     plugin_dir_url( __FILE__ ) );
define( 'ERDO_VERSION', '2.2.2' );

require_once ERDO_PATH . 'includes/class-auditor.php';
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

    new ERDO_Admin_Page();
    ERDO_Queue::register_interval();
    ERDO_WebP_Server::init();
}
add_action( 'plugins_loaded', 'erdo_init' );

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
   UPLOAD'DA OTOMATİK MAKSİMUM GENİŞLİK KÜÇÜLTME  (Özellik 2)
   Priority 5: WebP dönüşümünden (priority 10) ÖNCE çalışır.
------------------------------------------------------------------ */

function erdo_auto_resize_on_upload( $metadata, $attachment_id ) {
    $max = (int) get_option( 'erdo_max_width', 0 );
    if ( $max <= 0 ) {
        return $metadata;
    }

    $width = $metadata['width'] ?? 0;
    if ( $width <= $max ) {
        return $metadata;
    }

    // Her iki hook'u da kaldır: kendi kendini ve WebP dönüşümünü tetiklememek için
    remove_filter( 'wp_generate_attachment_metadata', 'erdo_auto_resize_on_upload', 5 );
    remove_filter( 'wp_generate_attachment_metadata', 'erdo_auto_webp_convert', 10 );
    ERDO_Auditor::manual_resize( $attachment_id, $max, 0 );
    add_filter( 'wp_generate_attachment_metadata', 'erdo_auto_resize_on_upload', 5, 2 );
    add_filter( 'wp_generate_attachment_metadata', 'erdo_auto_webp_convert', 10, 2 );

    // Taze metadata döndür
    $fresh = wp_get_attachment_metadata( $attachment_id );
    return is_array( $fresh ) ? $fresh : $metadata;
}
add_filter( 'wp_generate_attachment_metadata', 'erdo_auto_resize_on_upload', 5, 2 );

/* ------------------------------------------------------------------
   OTOMATİK WEBP DÖNÜŞÜMÜ + EXIF TEMİZLEME  (upload hook)
------------------------------------------------------------------ */

function erdo_auto_webp_convert( $metadata, $attachment_id ) {
    if ( ! class_exists( 'ERDO_Auditor' ) ) {
        return $metadata;
    }

    $did_convert = false;

    // A. Format dönüşümü (önce yap — GD zaten EXIF'i atar)
    if ( get_option( 'erdo_auto_webp' ) ) {
        $result = ERDO_Auditor::convert_to_webp( $attachment_id, false );

        if ( is_array( $result ) ) {
            $did_convert = true;

            update_attached_file( $attachment_id, $result['path'] );
            wp_update_post( array(
                'ID'             => $attachment_id,
                'post_mime_type' => $result['mime'],
            ) );

            $metadata['filesize'] = $result['filesize'];

            if ( isset( $metadata['file'] ) ) {
                $dir              = dirname( $metadata['file'] );
                $fname            = basename( $result['path'] );
                $metadata['file'] = ( '.' === $dir ) ? $fname : $dir . '/' . $fname;
            }
        }
    }

    // B. EXIF temizliği — dönüşüm yoksa yap (dönüşümde GD zaten temizler)
    if ( get_option( 'erdo_remove_exif' ) && ! $did_convert ) {
        ERDO_Auditor::clean_metadata( $attachment_id );
    }

    return $metadata;
}
add_filter( 'wp_generate_attachment_metadata', 'erdo_auto_webp_convert', 10, 2 );

/* ------------------------------------------------------------------
   LAZY LOADING  (Özellik 6)
   WordPress 5.5+ zaten loading="lazy" ekliyor ama bazı eklentiler
   bypass ediyor. Biz the_content'te de uyguluyoruz.
------------------------------------------------------------------ */

function erdo_add_lazy_loading( $html ) {
    if ( ! get_option( 'erdo_lazy_loading', 1 ) ) {
        return $html;
    }
    // loading özelliği yoksa ekle
    return preg_replace(
        '/<img(?![^>]*\bloading=)([^>]*>)/i',
        '<img loading="lazy"$1',
        $html
    );
}
add_filter( 'the_content',             'erdo_add_lazy_loading', 99 );
add_filter( 'post_thumbnail_html',     'erdo_add_lazy_loading', 99 );
add_filter( 'wp_get_attachment_image', 'erdo_add_lazy_loading', 99 );

/* ------------------------------------------------------------------
   SVG YÜKLEME İZNİ + SANİTİZASYON  (Özellik 7)
------------------------------------------------------------------ */

// SVG mime türüne izin ver
function erdo_allow_svg_upload( $mimes ) {
    if ( get_option( 'erdo_allow_svg' ) && current_user_can( 'manage_options' ) ) {
        $mimes['svg']  = 'image/svg+xml';
        $mimes['svgz'] = 'image/svg+xml';
    }
    return $mimes;
}
add_filter( 'upload_mimes', 'erdo_allow_svg_upload' );

// SVG'yi yüklemeden önce sanitize et
function erdo_sanitize_svg_on_upload( $file ) {
    if ( ! get_option( 'erdo_allow_svg' ) ) {
        return $file;
    }
    $ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
    if ( ! in_array( $ext, array( 'svg', 'svgz' ), true ) ) {
        return $file;
    }
    $content = file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
    if ( ! $content ) {
        $file['error'] = __( 'SVG file is empty or unreadable.', 'erdo-image-optimizer' );
        return $file;
    }
    $clean = ERDO_Auditor::sanitize_svg( $content );
    file_put_contents( $file['tmp_name'], $clean ); // phpcs:ignore WordPress.WP.AlternativeFunctions
    return $file;
}
add_filter( 'wp_handle_upload_prefilter', 'erdo_sanitize_svg_on_upload' );

// SVG'yi medya kütüphanesinde göster
function erdo_svg_thumbnail( $response, $attachment ) {
    if ( 'image/svg+xml' === $response['mime'] ) {
        $response['sizes'] = array(
            'full' => array( 'url' => $response['url'] ),
        );
    }
    return $response;
}
add_filter( 'wp_prepare_attachment_for_js', 'erdo_svg_thumbnail', 10, 2 );

/* ------------------------------------------------------------------
   MULTİSİTE TEMEL DESTEĞİ  (Özellik 8)
   Her site kendi ayarlarını kullanır; network admin sayfası için
   erdo_network_settings option'ı eklenir.
------------------------------------------------------------------ */

if ( is_multisite() ) {
    add_action( 'network_admin_menu', function () {
        add_menu_page(
            'Erdo Network Settings',
            'Image Optimizer',
            'manage_network_options',
            'erdo-network',
            'erdo_network_settings_page',
            'dashicons-performance',
            11
        );
    } );
}

function erdo_network_settings_page() {
    if ( ! current_user_can( 'manage_network_options' ) ) {
        return;
    }
    if ( isset( $_POST['erdo_network_save'] ) && check_admin_referer( 'erdo_network_nonce' ) ) {
        $raw_q = isset( $_POST['erdo_network_quality'] ) ? sanitize_text_field( wp_unslash( $_POST['erdo_network_quality'] ) ) : '80';
        $q     = max( 10, min( 100, (int) $raw_q ) );
        update_site_option( 'erdo_network_quality',  $q );
        update_site_option( 'erdo_network_format', sanitize_text_field( wp_unslash( $_POST['erdo_network_format'] ?? 'webp' ) ) );
        echo '<div class="updated"><p>Network settings saved.</p></div>';
    }
    $q   = get_site_option( 'erdo_network_quality', 80 );
    $fmt = get_site_option( 'erdo_network_format', 'webp' );
    echo '<div class="wrap"><h1>Erdo Image Optimizer — Network Settings</h1><form method="post">';
    wp_nonce_field( 'erdo_network_nonce' );
    echo '<table class="form-table">';
    echo '<tr><th>Default Quality</th><td><input type="number" name="erdo_network_quality" value="' . esc_attr( $q ) . '" min="10" max="100"> %</td></tr>';
    echo '<tr><th>Default Format</th><td><select name="erdo_network_format"><option value="webp"' . selected( $fmt, 'webp', false ) . '>WebP</option><option value="avif"' . selected( $fmt, 'avif', false ) . '>AVIF</option></select></td></tr>';
    echo '</table><p><input type="submit" name="erdo_network_save" class="button-primary" value="Save Network Settings"></p></form></div>';
}
