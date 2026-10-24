<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Core site haritası dizininde "erdoimages" sağlayıcısı. XML'i ERDO_Sitemap::render()
 * üretir; get_url_list() yalnızca core'un kendi oluşturucusuna düşülürse kullanılır
 * (sayfa adresleri, görselsiz).
 */
class ERDO_Sitemap_Provider extends WP_Sitemaps_Provider {

    public function __construct() {
        $this->name        = ERDO_Sitemap::NAME;
        $this->object_type = ERDO_Sitemap::NAME;
    }

    public function get_url_list( $page_num, $object_subtype = '' ) {
        $list = array();
        foreach ( array_keys( ERDO_Sitemap::entries( $page_num ) ) as $post_id ) {
            $loc = get_permalink( $post_id );
            if ( $loc ) {
                $list[] = array( 'loc' => $loc );
            }
        }
        return $list;
    }

    public function get_max_num_pages( $object_subtype = '' ) {
        return ERDO_Sitemap::max_pages();
    }
}
