<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * ERDO_Sitemap — core site haritasına (wp-sitemap.xml) görsel site haritası ekler
 *
 * Core'un oluşturucusu yalnızca loc/lastmod/changefreq/priority alanlarını
 * kabul eder, image:loc yazamaz. Bu yüzden "erdoimages" sağlayıcısı core'a
 * kaydedilir (dizinde ve robots.txt'te görünsün diye), ama o haritanın XML'i
 * core'dan önce burada üretilir. Veri kullanım indeksinden gelir: yayımlanmış,
 * herkese açık, noindex olmayan her sayfa ve içinde kullanılan görseller.
 *
 * Görsel site haritası üreten bir SEO eklentisi (Yoast, Rank Math, AIOSEO,
 * SEOPress) etkinse ya da ayar kapalıysa hiçbir şey yapmaz.
 */
class ERDO_Sitemap {

    const NAME     = 'erdoimages'; // core'un yeniden yazma kuralı yalnızca [a-z] kabul eder
    const PER_PAGE = 1000;
    const MAX_IMAGES_PER_URL = 1000; // Google sınırı

    public static function init() {
        if ( ! self::enabled() ) {
            return;
        }
        add_action( 'init', array( __CLASS__, 'register' ) );
        add_action( 'template_redirect', array( __CLASS__, 'render' ), 5 );
    }

    public static function enabled() {
        return (bool) get_option( 'erdo_image_sitemap', 1 ) && ! ERDO_SEO::seo_plugin_handles_image_sitemap();
    }

    public static function register() {
        if ( ! function_exists( 'wp_register_sitemap_provider' ) || ! class_exists( 'WP_Sitemaps_Provider' ) ) {
            return;
        }
        require_once __DIR__ . '/class-sitemap-provider.php';
        wp_register_sitemap_provider( self::NAME, new ERDO_Sitemap_Provider() );
    }

    /** Kaç sayfa var (indeks hazır değilse 0: harita listelenmez). */
    public static function max_pages() {
        if ( ! ERDO_Usage::is_ready() ) {
            return 0;
        }
        global $wpdb;
        list( $where, $args ) = self::where();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where is a fixed string with placeholders, see where()
        $count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->erdo_usage} u INNER JOIN {$wpdb->posts} p ON p.ID = u.source_id INNER JOIN {$wpdb->posts} a ON a.ID = u.attachment_id WHERE $where", ...$args ) );
        return (int) ceil( $count / self::PER_PAGE );
    }

    /** array( post_id => array( attachment_id, … ) ) — bir harita sayfası. */
    public static function entries( $page ) {
        if ( ! ERDO_Usage::is_ready() ) {
            return array();
        }
        global $wpdb;
        list( $where, $args ) = self::where();
        $offset = ( max( 1, (int) $page ) - 1 ) * self::PER_PAGE;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where is a fixed string with placeholders, see where()
        $posts = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT p.ID FROM {$wpdb->erdo_usage} u INNER JOIN {$wpdb->posts} p ON p.ID = u.source_id INNER JOIN {$wpdb->posts} a ON a.ID = u.attachment_id WHERE $where ORDER BY p.ID LIMIT %d OFFSET %d",
            ...array_merge( $args, array( self::PER_PAGE, $offset ) )
        ) );
        if ( ! $posts ) {
            return array();
        }
        $ph   = implode( ',', array_fill( 0, count( $posts ), '%d' ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT u.source_id, u.attachment_id FROM {$wpdb->erdo_usage} u INNER JOIN {$wpdb->posts} a ON a.ID = u.attachment_id
             WHERE u.source_type = 'post' AND u.source_id IN ($ph) AND a.post_status = 'inherit' ORDER BY u.source_id, u.attachment_id",
            ...array_map( 'intval', $posts )
        ) );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $out = array_fill_keys( array_map( 'intval', $posts ), array() );
        foreach ( $rows as $r ) {
            $out[ (int) $r->source_id ][] = (int) $r->attachment_id;
        }
        return $out;
    }

    /** Ortak koşul: yayımlanmış, şifresiz, herkese açık, noindex olmayan yazılar; karantinada olmayan görseller. */
    private static function where() {
        global $wpdb;
        $types = array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) );
        $types = $types ? $types : array( 'post' );
        $tph   = implode( ',', array_fill( 0, count( $types ), '%s' ) );
        $where = "u.source_type = 'post' AND a.post_status = 'inherit' AND p.post_status = 'publish' AND p.post_password = '' AND p.post_type IN ($tph)
            AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} n WHERE n.post_id = p.ID
                AND ( ( n.meta_key = '_yoast_wpseo_meta-robots-noindex' AND n.meta_value = '1' )
                   OR ( n.meta_key = 'rank_math_robots' AND n.meta_value LIKE %s ) ) )";
        return array( $where, array_merge( $types, array( '%noindex%' ) ) );
    }

    /** "erdoimages" haritası istenirse XML'i üret ve çık (core'un oluşturucusundan önce). */
    public static function render() {
        if ( self::NAME !== get_query_var( 'sitemap' ) ) {
            return;
        }
        $server = function_exists( 'wp_sitemaps_get_server' ) ? wp_sitemaps_get_server() : null;
        if ( ! $server || ! $server->sitemaps_enabled() ) {
            return;
        }
        $page    = max( 1, absint( get_query_var( 'paged' ) ) );
        $entries = self::entries( $page );
        if ( ! $entries ) {
            return; // core 404 versin
        }

        header( 'Content-Type: application/xml; charset=UTF-8' );
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";
        foreach ( $entries as $post_id => $images ) {
            $loc = get_permalink( $post_id );
            if ( ! $loc ) {
                continue;
            }
            echo "\t<url>\n\t\t<loc>" . esc_xml( $loc ) . "</loc>\n";
            foreach ( array_slice( $images, 0, self::MAX_IMAGES_PER_URL ) as $att ) {
                $src = wp_get_attachment_url( $att );
                if ( $src ) {
                    echo "\t\t<image:image><image:loc>" . esc_xml( $src ) . "</image:loc></image:image>\n";
                }
            }
            echo "\t</url>\n";
        }
        echo '</urlset>';
        exit;
    }
}
