<?php
/**
 * Plugin Name: Erdo Image Optimizer
 * Description: Optimize images to WebP/AVIF, clean metadata, audit SEO, and improve site speed.
 * Version: 1.8.5
 * Author: Erdinç Bulat
 * License: GPLv2 or later
 * Text Domain: erdo-image-optimizer
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'ERDO_PATH', plugin_dir_path( __FILE__ ) );
define( 'ERDO_URL', plugin_dir_url( __FILE__ ) );

require_once ERDO_PATH . 'includes/class-auditor.php';
require_once ERDO_PATH . 'includes/class-admin-page.php';

function erdo_init() {
    new ERDO_Admin_Page();
}
add_action( 'plugins_loaded', 'erdo_init' );

function erdo_clear_attachment_cache( $post_id ) {
    if ( class_exists('ERDO_Auditor') ) {
        ERDO_Auditor::clear_cache( $post_id );
    }
}
add_action( 'edit_attachment', 'erdo_clear_attachment_cache' );
add_action( 'add_attachment', 'erdo_clear_attachment_cache' );

// 1. Dosya İsmi Temizleme (Sanitize)
function erdo_auto_sanitize_filename( $filename ) {
    if ( ! get_option( 'erdo_auto_sanitize' ) ) return $filename;
    
    $info = pathinfo( $filename );
    $ext  = empty( $info['extension'] ) ? '' : '.' . $info['extension'];
    $name = basename( $filename, $ext );
    $name = sanitize_title( $name ); 
    $name = str_replace( '_', '-', $name );
    
    return $name . $ext;
}
add_filter( 'sanitize_file_name', 'erdo_auto_sanitize_filename', 10 );

// 2. Otomatik İşlemler (DÜZELTİLDİ: Metadata Overwrite)
function erdo_auto_webp_convert( $metadata, $attachment_id ) {
    
    if ( class_exists( 'ERDO_Auditor' ) ) {
        
        // A. EXIF Temizliği
        if ( get_option( 'erdo_remove_exif' ) ) {
            ERDO_Auditor::clean_metadata( $attachment_id );
        }

        // B. Format Dönüşümü
        if ( get_option( 'erdo_auto_webp' ) ) {
            // İkinci parametre FALSE (Veritabanını güncelleme, sadece veri dön)
            $result = ERDO_Auditor::convert_to_webp( $attachment_id, false );
            
            // Eğer dönüşüm başarılıysa ve bize yeni bilgiler döndüyse:
            if ( is_array( $result ) ) {
                
                // 1. Yeni dosya yolunu güncelle (veritabanına)
                update_attached_file( $attachment_id, $result['path'] );
                
                // 2. Mime Type güncelle
                wp_update_post( array(
                    'ID'             => $attachment_id,
                    'post_mime_type' => $result['mime']
                ) );

                // 3. KRİTİK NOKTA: WordPress'in elindeki $metadata dizisini güncelle
                // Bunu yapmazsak WP eski PNG boyutunu kaydeder!
                
                $metadata['filesize'] = $result['filesize'];
                
                // Dosya ismini de metadata içindeki 'file' anahtarında güncellemeliyiz
                if ( isset( $metadata['file'] ) ) {
                     // Sadece dosya ismini değiştir, klasör yolunu koru
                     $dir = dirname( $metadata['file'] );
                     $filename = basename( $result['path'] );
                     $metadata['file'] = ( $dir === '.' ) ? $filename : $dir . '/' . $filename;
                }
            }
        }
    }
    
    return $metadata;
}
add_filter( 'wp_generate_attachment_metadata', 'erdo_auto_webp_convert', 10, 2 );