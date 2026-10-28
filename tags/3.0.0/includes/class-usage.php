<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * ERDO_Usage — görsel kullanım indeksi
 *
 * "Bu görsel sitede nerede kullanılıyor?" sorusunun tek kaynağı. Her satır
 * bir (görsel ↔ kaynak) ilişkisidir; kaynak bir yazı (içerik / meta / öne
 * çıkan görsel / [gallery] çocuğu), bir seçenek (theme_mods, widget'lar,
 * site ikonu/logosu…) ya da bir terim meta'sıdır (ör. WooCommerce kategori
 * görseli). Görsel kimliği (ID) saklandığı için dosya yeniden adlandırılsa
 * da satırlar geçerli kalır.
 *
 * Güncelleme: içerik/meta/seçenek/terim değişince kaynak kuyruğa alınır,
 * istek sonunda (ya da sorgudan hemen önce) yeniden indekslenir ve yalnızca
 * etkilenen görsellerin audit cache'i temizlenir. Tam kurulum WP-Cron / AJAX /
 * WP-CLI ile partiler hâlinde yapılır; hazır olana kadar eski LIKE taraması
 * kullanılır ve toplu karantina kapalıdır.
 */
class ERDO_Usage {

    const STATE_OPTION = 'erdo_usage_index';
    const CRON_HOOK    = 'erdo_usage_build';
    const BATCH        = 200;

    /** Yapılandırılmış verideki (blok attr, JSON, serileştirilmiş) görsel ID taşıyan anahtarlar. */
    const ID_KEYS = array( 'id', 'ids', 'image', 'imageid', 'image_id', 'images', 'attachment', 'attachmentid', 'attachment_id', 'mediaid', 'media_id', 'customlogo', 'custom_logo', 'site_logo', 'sitelogo', 'site_icon', 'logo', 'thumbnail_id', 'thumbnailid', 'background_image_id', 'backgroundimageid', 'gallery', 'featuredimage', 'featured_image' );

    /** İndekslenmeyen meta anahtarları (WordPress iç verisi). */
    const IGNORED_META = array( '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_wp_trash_meta_status', '_wp_trash_meta_time', '_wp_desired_post_slug', '_encloseme', '_pingme', '_wp_page_template' );

    private static $pending = array( 'post' => array(), 'option' => array(), 'term' => array() );
    private static $map     = null;   // tam kurulum sırasında: göreli yol → attachment ID
    private static $resolved = array();

    /* ------------------------------------------------------------------
       KURULUM
    ------------------------------------------------------------------ */

    /**
     * Tablo adı $wpdb üzerinde kayıtlıdır ($wpdb->erdo_usage): sorgularda
     * çekirdek tablolar gibi güvenli bir özellik olarak kullanılır.
     */
    public static function table() {
        global $wpdb;
        if ( empty( $wpdb->erdo_usage ) ) {
            $wpdb->erdo_usage = $wpdb->prefix . 'erdo_usage';
        }
        return $wpdb->erdo_usage;
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table   = self::table();
        $charset = $wpdb->get_charset_collate();
        dbDelta( "CREATE TABLE $table (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  attachment_id bigint(20) unsigned NOT NULL,
  source_type varchar(20) NOT NULL,
  source_id bigint(20) unsigned NOT NULL DEFAULT 0,
  source_key varchar(191) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY attachment_id (attachment_id),
  KEY source (source_type,source_id)
) $charset;" );
    }

    public static function init() {
        self::table();
        add_action( 'wp_after_insert_post', array( __CLASS__, 'on_post_saved' ), 10, 1 );
        add_action( 'added_post_meta',      array( __CLASS__, 'on_post_meta' ), 10, 3 );
        add_action( 'updated_post_meta',    array( __CLASS__, 'on_post_meta' ), 10, 3 );
        add_action( 'deleted_post_meta',    array( __CLASS__, 'on_post_meta' ), 10, 3 );
        add_action( 'deleted_post',         array( __CLASS__, 'on_post_deleted' ), 10, 2 );
        add_action( 'added_option',         array( __CLASS__, 'on_option' ), 10, 1 );
        add_action( 'updated_option',       array( __CLASS__, 'on_option' ), 10, 1 );
        add_action( 'deleted_option',       array( __CLASS__, 'on_option_deleted' ), 10, 1 );
        add_action( 'added_term_meta',      array( __CLASS__, 'on_term_meta' ), 10, 2 );
        add_action( 'updated_term_meta',    array( __CLASS__, 'on_term_meta' ), 10, 2 );
        add_action( 'deleted_term_meta',    array( __CLASS__, 'on_term_meta' ), 10, 2 );
        add_action( 'delete_term',          array( __CLASS__, 'on_term_deleted' ), 10, 1 );
        add_action( 'shutdown',             array( __CLASS__, 'flush' ) );
        add_action( self::CRON_HOOK,        array( __CLASS__, 'cron_build' ) );
    }

    /* ------------------------------------------------------------------
       DURUM
    ------------------------------------------------------------------ */

    public static function state() {
        return wp_parse_args( (array) get_option( self::STATE_OPTION, array() ), array( 'status' => 'empty', 'stage' => 'posts', 'cursor' => 0, 'built' => 0 ) );
    }

    public static function is_ready() {
        return 'ready' === self::state()['status'];
    }

    /* ------------------------------------------------------------------
       ARTIMLI GÜNCELLEME
    ------------------------------------------------------------------ */

    public static function on_post_saved( $post_id ) {
        self::$pending['post'][ (int) $post_id ] = true;
    }

    public static function on_post_meta( $meta_ids, $object_id, $meta_key ) {
        if ( in_array( $meta_key, self::IGNORED_META, true ) || 0 === strpos( (string) $meta_key, '_erdo_' ) || 0 === strpos( (string) $meta_key, '_wp_attach' ) ) {
            return;
        }
        self::$pending['post'][ (int) $object_id ] = true;
    }

    public static function on_post_deleted( $post_id, $post = null ) {
        global $wpdb;
        $post_id = (int) $post_id;
        unset( self::$pending['post'][ $post_id ] );
        $type = $post ? $post->post_type : '';
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ( 'attachment' === $type ) {
            $wpdb->delete( self::table(), array( 'attachment_id' => $post_id ), array( '%d' ) );
            return;
        }
        $old = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT attachment_id FROM {$wpdb->erdo_usage} WHERE source_type = 'post' AND source_id = %d", $post_id ) );
        $wpdb->delete( self::table(), array( 'source_type' => 'post', 'source_id' => $post_id ), array( '%s', '%d' ) );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        self::invalidate( array_map( 'intval', $old ) );
    }

    public static function on_option( $option ) {
        if ( self::is_relevant_option( $option ) ) {
            self::$pending['option'][ $option ] = true;
        }
    }

    /** Silinen seçenek: değeri artık okunamaz, satırları her durumda temizlenmeli. */
    public static function on_option_deleted( $option ) {
        if ( 0 !== strpos( $option, '_transient' ) && 0 !== strpos( $option, '_site_transient' ) && 0 !== strpos( $option, 'erdo_' ) ) {
            self::$pending['option'][ $option ] = true;
        }
    }

    public static function on_term_meta( $meta_ids, $term_id ) {
        self::$pending['term'][ (int) $term_id ] = true;
    }

    public static function on_term_deleted( $term_id ) {
        unset( self::$pending['term'][ (int) $term_id ] );
        self::write_rows( 'term', (int) $term_id, null, array() );
    }

    private static function is_relevant_option( $name ) {
        if ( 0 === strpos( $name, '_transient' ) || 0 === strpos( $name, '_site_transient' ) || 0 === strpos( $name, 'erdo_' ) || 'cron' === $name || 'rewrite_rules' === $name ) {
            return false;
        }
        if ( in_array( $name, array( 'site_icon', 'site_logo', 'sidebars_widgets' ), true ) || 0 === strpos( $name, 'theme_mods_' ) || 0 === strpos( $name, 'widget_' ) ) {
            return true;
        }
        // Diğer seçenekler: değer uploads yolu içeriyorsa (sayfa oluşturucu global ayarları vb.).
        $value = get_option( $name );
        if ( null === $value || false === $value || is_scalar( $value ) && ! is_string( $value ) ) {
            return false;
        }
        $flat = is_string( $value ) ? $value : maybe_serialize( $value );
        return false !== strpos( str_replace( '\\/', '/', $flat ), ERDO_References::uploads_path() );
    }

    /** Kuyruktaki kaynakları indeksler. Sorgulardan önce ve istek sonunda çağrılır. */
    public static function flush() {
        if ( empty( self::$pending['post'] ) && empty( self::$pending['option'] ) && empty( self::$pending['term'] ) ) {
            return;
        }
        if ( 'empty' === self::state()['status'] ) {
            self::$pending = array( 'post' => array(), 'option' => array(), 'term' => array() );
            return; // Henüz kurulmadı — tam kurulum hepsini toplayacak.
        }
        $pending       = self::$pending;
        self::$pending = array( 'post' => array(), 'option' => array(), 'term' => array() );

        $changed = array();
        foreach ( array_keys( $pending['post'] ) as $id ) {
            $changed = array_merge( $changed, self::index_post( $id ) );
        }
        foreach ( array_keys( $pending['option'] ) as $name ) {
            $changed = array_merge( $changed, self::index_option( $name ) );
        }
        foreach ( array_keys( $pending['term'] ) as $id ) {
            $changed = array_merge( $changed, self::index_term( $id ) );
        }
        self::invalidate( $changed );
    }

    private static function invalidate( array $attachment_ids ) {
        $attachment_ids = array_unique( array_filter( $attachment_ids ) );
        if ( ! $attachment_ids ) {
            return;
        }
        foreach ( $attachment_ids as $id ) {
            ERDO_Auditor::clear_cache( $id );
        }
        // bulk_analyze() ham meta satırlarını (audit cache dahil) object cache'te
        // 300 sn tutar; yalnızca post meta'yı silmek grid'i bayat bırakırdı.
        ERDO_Auditor::flush_usage_cache();
        if ( function_exists( 'erdo_bump_library_version' ) ) {
            erdo_bump_library_version();
        }
    }

    /* ------------------------------------------------------------------
       KAYNAK İNDEKSLEME — her biri değişen attachment ID'lerini döndürür
    ------------------------------------------------------------------ */

    public static function index_post( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post || in_array( $post->post_type, array( 'attachment', 'revision' ), true ) || 'auto-draft' === $post->post_status ) {
            return self::write_rows( 'post', (int) $post_id, null, array() );
        }

        $refs = array();
        foreach ( self::ids_in_text( $post->post_content ) as $id ) {
            $refs[] = array( $id, 'content' );
        }

        // [gallery] (ids/include olmadan) yazıya ekli görselleri gösterir.
        if ( preg_match_all( '/\[gallery\b([^\]]*)\]/i', $post->post_content, $g ) ) {
            foreach ( $g[1] as $attrs ) {
                if ( ! preg_match( '/\b(ids|include)\s*=/i', $attrs ) ) {
                    foreach ( get_children( array( 'post_parent' => $post->ID, 'post_type' => 'attachment', 'post_mime_type' => 'image', 'fields' => 'ids' ) ) as $child ) {
                        $refs[] = array( (int) $child, 'gallery' );
                    }
                }
            }
        }

        $meta = get_post_meta( $post->ID );
        foreach ( (array) $meta as $key => $values ) {
            if ( in_array( $key, self::IGNORED_META, true ) || 0 === strpos( $key, '_erdo_' ) ) {
                continue;
            }
            foreach ( $values as $raw ) {
                foreach ( self::ids_in_meta( $key, $raw, $meta ) as $id ) {
                    $refs[] = array( $id, '_thumbnail_id' === $key ? 'featured' : $key );
                }
            }
        }

        return self::write_rows( 'post', (int) $post->ID, null, $refs );
    }

    public static function index_option( $name ) {
        $value = get_option( $name, null );
        $refs  = array();
        if ( null !== $value ) {
            if ( in_array( $name, array( 'site_icon', 'site_logo' ), true ) && is_numeric( $value ) ) {
                $refs[] = array( (int) $value, $name );
            } else {
                foreach ( self::ids_in_data( $value ) as $id ) {
                    $refs[] = array( $id, $name );
                }
            }
        }
        return self::write_rows( 'option', 0, $name, $refs );
    }

    public static function index_term( $term_id ) {
        $refs = array();
        $meta = get_term_meta( $term_id );
        foreach ( (array) $meta as $key => $values ) {
            foreach ( $values as $raw ) {
                foreach ( self::ids_in_meta( $key, $raw, $meta ) as $id ) {
                    $refs[] = array( $id, $key );
                }
            }
        }
        return self::write_rows( 'term', (int) $term_id, null, $refs );
    }

    /**
     * Bir kaynağın satırlarını yenisiyle değiştirir; yalnızca geçerli görsel
     * attachment'larını yazar. Döner: eklenen ya da kaldırılan attachment ID'leri.
     */
    private static function write_rows( $type, $source_id, $key, array $refs ) {
        global $wpdb;
        self::table();
        // $where yalnızca prepare() çıktılarından oluşur.
        $where = $wpdb->prepare( 'source_type = %s AND source_id = %d', $type, $source_id ) . ( null !== $key ? $wpdb->prepare( ' AND source_key = %s', $key ) : '' );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
        $old = array_map( 'intval', $wpdb->get_col( "SELECT DISTINCT attachment_id FROM {$wpdb->erdo_usage} WHERE $where" ) );
        $wpdb->query( "DELETE FROM {$wpdb->erdo_usage} WHERE $where" );

        $valid = self::valid_images( array_map( function ( $r ) {
            return $r[0];
        }, $refs ) );

        $rows = array();
        $seen = array();
        foreach ( $refs as $r ) {
            list( $id, $k ) = $r;
            $k = substr( (string) ( null !== $key ? $key : $k ), 0, 191 );
            if ( isset( $valid[ $id ] ) && ! isset( $seen[ "$id|$k" ] ) ) {
                $seen[ "$id|$k" ] = true;
                $rows[]           = $wpdb->prepare( '(%d,%s,%d,%s)', $id, $type, $source_id, $k );
            }
        }
        foreach ( array_chunk( $rows, 200 ) as $chunk ) {
            // Her satır yukarıda $wpdb->prepare() ile üretildi.
            $wpdb->query( "INSERT INTO {$wpdb->erdo_usage} (attachment_id,source_type,source_id,source_key) VALUES " . implode( ',', $chunk ) ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
        }
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

        $new = array();
        foreach ( $refs as $r ) {
            if ( isset( $valid[ $r[0] ] ) ) {
                $new[ (int) $r[0] ] = true;
            }
        }
        $new = array_keys( $new );
        return array_values( array_unique( array_merge( array_diff( $old, $new ), array_diff( $new, $old ) ) ) );
    }

    /** Verilen ID'lerden görsel attachment olanlar (karantinadakiler dahil): id => true */
    private static function valid_images( array $ids ) {
        global $wpdb;
        $ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
        if ( ! $ids ) {
            return array();
        }
        $ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $found = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE %s AND ID IN ($ph)", $wpdb->esc_like( 'image/' ) . '%', ...$ids ) );
        return array_fill_keys( array_map( 'intval', $found ), true );
    }

    /* ------------------------------------------------------------------
       REFERANS ÇIKARMA
    ------------------------------------------------------------------ */

    /** HTML / blok işaretlemesi / CSS / JSON metninden görsel ID'leri. */
    public static function ids_in_text( $text ) {
        if ( ! is_string( $text ) || '' === $text ) {
            return array();
        }
        $ids = array();

        if ( preg_match_all( '/\bwp-image-(\d+)\b/', $text, $m ) ) {
            $ids = array_merge( $ids, $m[1] );
        }

        // Blok yorumlarındaki nitelikler: <!-- wp:image {"id":12} -->
        if ( false !== strpos( $text, '<!-- wp:' ) && preg_match_all( '/<!--\s+wp:[a-z0-9\/_-]+\s+(\{.*?\})\s+\/?-->/s', $text, $m ) ) {
            foreach ( $m[1] as $json ) {
                $attrs = json_decode( $json, true );
                if ( is_array( $attrs ) ) {
                    $ids = array_merge( $ids, self::ids_in_structure( $attrs ) );
                }
            }
        }

        // Kısa kodlar: [gallery ids="1,2"], [foo image_id="3"]
        if ( preg_match_all( '/\b(?:ids|include|image_id|attachment_id|image)\s*=\s*["\']?([\d,\s]+)/i', $text, $m ) ) {
            foreach ( $m[1] as $list ) {
                $ids = array_merge( $ids, preg_split( '/[\s,]+/', trim( $list ) ) );
            }
        }

        // Metin bir JSON belgesi ise (ör. Elementor) yapı içindeki ID anahtarları.
        $trim = ltrim( $text );
        if ( '' !== $trim && ( '[' === $trim[0] || '{' === $trim[0] ) ) {
            $data = json_decode( $text, true );
            if ( is_array( $data ) ) {
                $ids = array_merge( $ids, self::ids_in_structure( $data ) );
            }
        }

        foreach ( ERDO_References::find_rel_paths( $text ) as $rel ) {
            $id = self::resolve_rel( $rel );
            if ( $id ) {
                $ids[] = $id;
            }
        }

        return array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
    }

    /** Ham meta değerinden ID'ler (serileştirilmiş / JSON / düz metin / sayısal). */
    private static function ids_in_meta( $key, $raw, array $all_meta ) {
        if ( in_array( $key, array( '_thumbnail_id', 'thumbnail_id' ), true ) ) {
            return is_numeric( $raw ) ? array( (int) $raw ) : array();
        }
        if ( '_product_image_gallery' === $key ) {
            return array_map( 'intval', array_filter( explode( ',', (string) $raw ) ) );
        }

        // ACF: değer bir ID (ya da ID listesi) ve "_{alan}" anahtarı "field_" ile başlıyor.
        $acf = isset( $all_meta[ '_' . $key ][0] ) && 0 === strpos( (string) $all_meta[ '_' . $key ][0], 'field_' );
        if ( $acf ) {
            if ( is_numeric( $raw ) ) {
                return array( (int) $raw );
            }
            $list = maybe_unserialize( $raw );
            if ( is_array( $list ) && $list === array_filter( $list, 'is_numeric' ) ) {
                return array_map( 'intval', $list );
            }
        }

        return self::ids_in_data( is_serialized( $raw ) ? self::safe_unserialize( $raw ) : $raw );
    }

    /** Herhangi bir PHP değerinden (dizi, nesne, metin) ID'ler. */
    private static function ids_in_data( $value ) {
        if ( is_string( $value ) ) {
            if ( is_serialized( $value ) ) {
                return self::ids_in_data( self::safe_unserialize( $value ) );
            }
            return self::ids_in_text( $value );
        }
        if ( is_array( $value ) || is_object( $value ) ) {
            $ids = array_merge( self::ids_in_structure( $value ), self::ids_in_strings( $value ) );
            return array_values( array_unique( array_map( 'intval', $ids ) ) );
        }
        return array();
    }

    /** İç içe dizi/nesnelerdeki metinlerden ID'ler (stdClass içindekiler dahil — ör. header_image_data). */
    private static function ids_in_strings( $value ) {
        if ( is_string( $value ) ) {
            return strlen( $value ) > 8 ? self::ids_in_data( $value ) : array();
        }
        if ( is_object( $value ) ) {
            $value = get_object_vars( $value );
        }
        if ( ! is_array( $value ) ) {
            return array();
        }
        $ids = array();
        foreach ( $value as $v ) {
            $ids = array_merge( $ids, self::ids_in_strings( $v ) );
        }
        return $ids;
    }

    /** Yapıdaki ID_KEYS anahtarlarının sayısal değerleri (iç içe, nesneler dahil). */
    private static function ids_in_structure( $data ) {
        $ids = array();
        if ( is_object( $data ) ) {
            $data = get_object_vars( $data );
        }
        foreach ( (array) $data as $k => $v ) {
            $norm = is_string( $k ) ? strtolower( $k ) : '';
            if ( '' !== $norm && in_array( $norm, self::ID_KEYS, true ) ) {
                if ( is_numeric( $v ) ) {
                    $ids[] = (int) $v;
                } elseif ( is_string( $v ) && preg_match( '/^\d+(,\s*\d+)*$/', $v ) ) {
                    $ids = array_merge( $ids, array_map( 'intval', explode( ',', $v ) ) );
                } elseif ( is_array( $v ) && $v && array_filter( $v, 'is_numeric' ) === $v ) {
                    $ids = array_merge( $ids, array_map( 'intval', $v ) );
                }
            }
            if ( is_array( $v ) || is_object( $v ) ) {
                $ids = array_merge( $ids, self::ids_in_structure( $v ) );
            }
        }
        return $ids;
    }

    private static function safe_unserialize( $raw ) {
        $v = @unserialize( $raw, array( 'allowed_classes' => array( 'stdClass' ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions, WordPress.PHP.NoSilencedErrors
        return false === $v ? null : $v;
    }

    /* ------------------------------------------------------------------
       URL → ATTACHMENT
       "2026/10/foto-300x200.jpg.webp" → foto.jpg'nin attachment ID'si.
    ------------------------------------------------------------------ */

    public static function resolve_rel( $rel ) {
        if ( isset( self::$resolved[ $rel ] ) ) {
            return self::$resolved[ $rel ];
        }
        $cands = self::candidates( $rel );
        $id    = 0;

        if ( is_array( self::$map ) ) {
            foreach ( $cands as $c ) {
                if ( isset( self::$map[ $c ] ) ) {
                    $id = self::$map[ $c ];
                    break;
                }
            }
        } else {
            global $wpdb;
            $ph = implode( ',', array_fill( 0, count( $cands ), '%s' ) );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
            $id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value IN ($ph) LIMIT 1", ...$cands ) );
            if ( ! $id ) {
                // Aynı gövde, farklı uzantı (ör. 2.x'ten kalan .webp ↔ geri yüklenen .jpg)
                $stem = preg_replace( '/\.[^.\/]+$/', '', $cands[1] ?? $cands[0] );
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s LIMIT 1", $wpdb->esc_like( $stem . '.' ) . '%' ) );
            }
        }

        self::$resolved[ $rel ] = $id;
        return $id;
    }

    /** Göreli yolun ana dosyaya giden olası adları (kardeş uzantısı, -WxH, -scaled, -eNNN düzenleme soneki). */
    private static function candidates( $rel ) {
        $r     = preg_replace( '/\.(jpe?g|png|gif|webp)\.(webp|avif)$/i', '.$1', $rel );
        $out   = array( $r );
        $nosz  = preg_replace( '/-\d+x\d+(\.[a-z0-9]+)$/i', '$1', $r );
        $out[] = $nosz;
        $out[] = preg_replace( '/(\.[a-z0-9]+)$/i', '-scaled$1', $nosz );
        $out[] = preg_replace( '/-(scaled|rotated)(\.[a-z0-9]+)$/i', '$2', $nosz );
        $out[] = preg_replace( '/-e\d{13}(\.[a-z0-9]+)$/i', '$1', $nosz );
        return array_values( array_unique( $out ) );
    }

    /** Tam kurulumda tek sorguyla bellek içi eşleme: her dosya adı (ana, alt boyutlar, orijinal, 2.x kalıntıları) → ID. */
    private static function load_map() {
        global $wpdb;
        self::$map      = array();
        self::$resolved = array();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            "SELECT m.post_id, m.meta_key, m.meta_value FROM {$wpdb->postmeta} m
             INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_type = 'attachment'
             WHERE m.meta_key IN ('_wp_attached_file','_wp_attachment_metadata','_erdo_legacy_files')"
        );
        $files = array();
        foreach ( $rows as $row ) {
            $id = (int) $row->post_id;
            if ( '_wp_attached_file' === $row->meta_key ) {
                $files[ $id ] = $row->meta_value;
                self::$map[ $row->meta_value ] = $id;
            }
        }
        $base = trailingslashit( wp_upload_dir()['basedir'] );
        foreach ( $rows as $row ) {
            $id = (int) $row->post_id;
            if ( empty( $files[ $id ] ) ) {
                continue;
            }
            $dir = trim( dirname( $files[ $id ] ), './' );
            $dir = '' === $dir ? '' : $dir . '/';
            if ( '_wp_attachment_metadata' === $row->meta_key ) {
                $meta = self::safe_unserialize( $row->meta_value );
                foreach ( (array) ( $meta['sizes'] ?? array() ) as $s ) {
                    if ( ! empty( $s['file'] ) ) {
                        self::$map[ $dir . $s['file'] ] = $id;
                    }
                }
                if ( ! empty( $meta['original_image'] ) ) {
                    self::$map[ $dir . $meta['original_image'] ] = $id;
                }
            } elseif ( '_erdo_legacy_files' === $row->meta_key ) {
                foreach ( (array) self::safe_unserialize( $row->meta_value ) as $abs ) {
                    self::$map[ ltrim( str_replace( wp_normalize_path( $base ), '', wp_normalize_path( (string) $abs ) ), '/' ) ] = $id;
                }
            }
        }
    }

    /* ------------------------------------------------------------------
       TAM KURULUM (partiler hâlinde)
    ------------------------------------------------------------------ */

    public static function start_build() {
        global $wpdb;
        self::install();
        $wpdb->query( "DELETE FROM {$wpdb->erdo_usage}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        update_option( self::STATE_OPTION, array( 'status' => 'building', 'stage' => 'posts', 'cursor' => 0, 'built' => 0 ), false );
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_single_event( time(), self::CRON_HOOK );
        }
    }

    public static function cron_build() {
        $progress = self::build_step( 20 );
        if ( 'ready' !== $progress['status'] && ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_single_event( time() + 30, self::CRON_HOOK );
        }
    }

    /**
     * $seconds bütçesi bitene kadar ilerler. Döner: array( status, stage, done, total ).
     */
    public static function build_step( $seconds = 10 ) {
        global $wpdb;
        $state = self::state();
        if ( 'ready' === $state['status'] ) {
            return self::progress( $state );
        }
        if ( 'empty' === $state['status'] ) {
            self::start_build();
            $state = self::state();
        }

        $start = microtime( true );
        self::load_map();

        while ( microtime( true ) - $start < $seconds ) {
            if ( 'posts' === $state['stage'] ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $ids = $wpdb->get_col( $wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_type NOT IN ('attachment','revision') AND post_status <> 'auto-draft' ORDER BY ID LIMIT %d",
                    $state['cursor'],
                    self::BATCH
                ) );
                if ( ! $ids ) {
                    $state['stage']  = 'options';
                    $state['cursor'] = 0;
                    continue;
                }
                update_meta_cache( 'post', $ids );
                foreach ( $ids as $id ) {
                    self::index_post( (int) $id );
                    $state['cursor'] = (int) $id;
                }
            } elseif ( 'options' === $state['stage'] ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $names = $wpdb->get_col( $wpdb->prepare(
                    "SELECT option_name FROM {$wpdb->options}
                     WHERE option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s
                     AND ( option_name IN ('site_icon','site_logo') OR option_name LIKE %s OR option_name LIKE %s OR option_value LIKE %s OR option_value LIKE %s )",
                    $wpdb->esc_like( '_transient' ) . '%',
                    $wpdb->esc_like( '_site_transient' ) . '%',
                    $wpdb->esc_like( 'erdo_' ) . '%',
                    $wpdb->esc_like( 'theme_mods_' ) . '%',
                    $wpdb->esc_like( 'widget_' ) . '%',
                    '%' . $wpdb->esc_like( ERDO_References::uploads_path() ) . '%',
                    '%' . $wpdb->esc_like( str_replace( '/', '\\/', ERDO_References::uploads_path() ) ) . '%'
                ) );
                foreach ( $names as $name ) {
                    self::index_option( $name );
                }
                $state['stage'] = 'terms';
            } elseif ( 'terms' === $state['stage'] ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $terms = $wpdb->get_col( $wpdb->prepare(
                    "SELECT DISTINCT term_id FROM {$wpdb->termmeta} WHERE meta_key IN ('thumbnail_id','image','image_id') OR meta_value LIKE %s",
                    '%' . $wpdb->esc_like( ERDO_References::uploads_path() ) . '%'
                ) );
                foreach ( $terms as $tid ) {
                    self::index_term( (int) $tid );
                }
                $state = array( 'status' => 'ready', 'stage' => 'done', 'cursor' => 0, 'built' => time() );
                break;
            }
        }

        update_option( self::STATE_OPTION, $state, false );
        self::$map = null;
        if ( 'ready' === $state['status'] ) {
            wp_clear_scheduled_hook( self::CRON_HOOK );
            ERDO_Auditor::clear_all_cache();
        }
        return self::progress( $state );
    }

    /** Senkron tam kurulum (CLI / testler). */
    public static function rebuild() {
        self::start_build();
        do {
            $p = self::build_step( 60 );
        } while ( 'ready' !== $p['status'] );
        return $p;
    }

    private static function progress( $state ) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $total = (int) $wpdb->get_var( "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type NOT IN ('attachment','revision') AND post_status <> 'auto-draft'" );
        $done  = 'posts' === $state['stage']
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE ID <= %d AND post_type NOT IN ('attachment','revision') AND post_status <> 'auto-draft'", $state['cursor'] ) )
            : $total;
        return array( 'status' => $state['status'], 'stage' => $state['stage'], 'done' => $done, 'total' => $total );
    }

    /* ------------------------------------------------------------------
       SORGULAR
    ------------------------------------------------------------------ */

    public static function is_used( $attachment_id ) {
        $r = self::bulk_used( array( $attachment_id ) );
        return $r[ $attachment_id ] ?? false;
    }

    /** array( id => bool ) — tek sorgu. */
    public static function bulk_used( array $ids ) {
        global $wpdb;
        self::flush();
        $ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
        if ( ! $ids ) {
            return array();
        }
        $ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $used = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT attachment_id FROM {$wpdb->erdo_usage} WHERE attachment_id IN ($ph)", ...$ids ) );
        $out  = array_fill_keys( $ids, false );
        foreach ( $used as $id ) {
            $out[ (int) $id ] = true;
        }
        return $out;
    }

    /**
     * array( id => bool ) — arama motorlarının görebileceği bir yerde mi:
     * yayımlanmış, herkese açık, noindex olmayan bir yazı ya da site geneli
     * bir ayar/terim (logo, site ikonu, kategori görseli). Taslak, çöp,
     * özel CSS (arka plan görselleri dizinlenmez) sayılmaz.
     */
    public static function bulk_indexable( array $ids ) {
        global $wpdb;
        $ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
        if ( ! $ids ) {
            return array();
        }
        $types = array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) );
        $types = $types ? $types : array( 'post' );
        $ph    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $tph   = implode( ',', array_fill( 0, count( $types ), '%s' ) );
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $hits = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT u.attachment_id FROM {$wpdb->erdo_usage} u
             LEFT JOIN {$wpdb->posts} p ON u.source_type = 'post' AND p.ID = u.source_id
             WHERE u.attachment_id IN ($ph)
             AND ( u.source_type IN ('option','term')
                OR ( p.post_status = 'publish' AND p.post_password = '' AND p.post_type IN ($tph)
                     AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} n WHERE n.post_id = p.ID
                        AND ( ( n.meta_key = '_yoast_wpseo_meta-robots-noindex' AND n.meta_value = '1' )
                           OR ( n.meta_key = 'rank_math_robots' AND n.meta_value LIKE %s ) ) ) ) )",
            ...array_merge( $ids, $types, array( '%noindex%' ) )
        ) );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $out = array_fill_keys( $ids, false );
        foreach ( $hits as $id ) {
            $out[ (int) $id ] = true;
        }
        return $out;
    }

    /** Hiçbir kaynakta geçmeyen, karantinada olmayan görseller. */
    public static function unused_ids() {
        global $wpdb;
        self::flush();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->erdo_usage} u ON u.attachment_id = p.ID
             WHERE p.post_type = 'attachment' AND p.post_status = 'inherit' AND p.post_mime_type LIKE %s
             AND u.id IS NULL ORDER BY p.ID",
            $wpdb->esc_like( 'image/' ) . '%'
        ) ) );
    }

    /**
     * Görseli en iyi anlatan sayfa: yayımlanmış üst yazı, yoksa indeksteki ilk yayımlanmış
     * ve görüntülenebilir yazı (önce içerik, sonra öne çıkan görsel, sonra meta). İndeks
     * hazır değilse üst yazı (yayımlanmamış olabilir) ya da null. AI bağlamı ve ad önerisi kullanır.
     */
    public static function primary_page( $attachment_id ) {
        $post = get_post( $attachment_id );
        $page = $post && $post->post_parent ? get_post( $post->post_parent ) : null;
        if ( ( ! $page || 'publish' !== $page->post_status ) && self::is_ready() ) {
            $rank    = array( 'content' => 0, 'featured' => 1 );
            $sources = self::sources( $attachment_id );
            usort( $sources, function ( $a, $b ) use ( $rank ) {
                return ( $rank[ $a['key'] ] ?? 2 ) <=> ( $rank[ $b['key'] ] ?? 2 );
            } );
            foreach ( $sources as $src ) {
                $p = 'post' === $src['type'] ? get_post( $src['id'] ) : null;
                if ( $p && 'publish' === $p->post_status && is_post_type_viewable( $p->post_type ) ) {
                    return $p;
                }
            }
        }
        return $page ?: null;
    }

    /**
     * Kullanım haritası: array( array( 'type', 'id', 'key', 'label', 'url' ) )
     * Detay panelinde "nerede kullanılıyor" listesi için.
     */
    public static function sources( $attachment_id ) {
        global $wpdb;
        self::flush();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT source_type, source_id, source_key FROM {$wpdb->erdo_usage} WHERE attachment_id = %d ORDER BY source_type, source_id", $attachment_id ) );
        $out  = array();
        foreach ( $rows as $r ) {
            $out[] = self::describe( $r->source_type, (int) $r->source_id, $r->source_key );
        }
        return $out;
    }

    private static function describe( $type, $id, $key ) {
        if ( 'post' === $type ) {
            $post  = get_post( $id );
            $title = $post ? ( '' !== $post->post_title ? $post->post_title : '#' . $id ) : '#' . $id;
            $where = array(
                'content'  => __( 'content', 'erdo-image-optimizer' ),
                'featured' => __( 'featured image', 'erdo-image-optimizer' ),
                'gallery'  => __( 'gallery', 'erdo-image-optimizer' ),
            );
            $type_obj = $post ? get_post_type_object( $post->post_type ) : null;
            $status   = $post && 'publish' !== $post->post_status ? ' (' . $post->post_status . ')' : '';
            return array(
                'type'  => 'post',
                'id'    => $id,
                'key'   => $key,
                'label' => sprintf( '%s: %s%s — %s', $type_obj ? $type_obj->labels->singular_name : 'Post', $title, $status, $where[ $key ] ?? $key ),
                'url'   => $post ? (string) get_edit_post_link( $id, 'raw' ) : '',
            );
        }
        if ( 'term' === $type ) {
            $term = get_term( $id );
            return array(
                'type'  => 'term',
                'id'    => $id,
                'key'   => $key,
                'label' => sprintf( '%s: %s', __( 'Term', 'erdo-image-optimizer' ), $term && ! is_wp_error( $term ) ? $term->name : '#' . $id ),
                'url'   => $term && ! is_wp_error( $term ) ? (string) get_edit_term_link( $term ) : '',
            );
        }
        $labels = array(
            'site_icon' => __( 'Site icon', 'erdo-image-optimizer' ),
            'site_logo' => __( 'Site logo', 'erdo-image-optimizer' ),
        );
        $url = 0 === strpos( $key, 'theme_mods_' ) || in_array( $key, array( 'site_icon', 'site_logo' ), true ) ? admin_url( 'customize.php' )
            : ( 0 === strpos( $key, 'widget_' ) ? admin_url( 'widgets.php' ) : '' );
        return array(
            'type'  => 'option',
            'id'    => 0,
            'key'   => $key,
            'label' => $labels[ $key ] ?? ( 0 === strpos( $key, 'theme_mods_' ) ? __( 'Theme settings (logo, header, background)', 'erdo-image-optimizer' ) : sprintf( '%s: %s', __( 'Setting', 'erdo-image-optimizer' ), $key ) ),
            'url'   => $url,
        );
    }

    /**
     * İkinci emniyet: indeksin çözemediği biçimlerde (ör. uploads yolu olmadan
     * yalnızca dosya adı) görselin dosya adı gövdesi sitede geçiyor mu?
     * Kısa/jenerik adlarda yanlış pozitif verebilir — bu yalnızca silmeyi
     * engeller, yani güvenli taraftadır.
     */
    public static function name_appears_anywhere( $attachment_id ) {
        global $wpdb;
        $file = get_attached_file( $attachment_id );
        if ( ! $file ) {
            return false;
        }
        $stem = preg_replace( '/-scaled$/', '', pathinfo( $file, PATHINFO_FILENAME ) );
        if ( strlen( $stem ) < 4 ) {
            return false;
        }
        $like = '%' . $wpdb->esc_like( $stem ) . '%';
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $hit = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type NOT IN ('attachment','revision') AND post_status <> 'auto-draft' AND post_content LIKE %s LIMIT 1", $like ) )
            || $wpdb->get_var( $wpdb->prepare( "SELECT m.meta_id FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_type NOT IN ('attachment','revision') WHERE m.meta_value LIKE %s LIMIT 1", $like ) )
            || self::name_in_options( $like );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return (bool) $hit;
    }

    /**
     * Seçeneklerde dosya adı araması — günlük/önbellek/geçmiş tutan seçenekler
     * hariç: hata ve etkinlik kaydı eklentileri dosya yollarını oraya yazar, bu
     * da gerçekte kullanılmayan görselleri "kullanılıyor olabilir" diye kilitler.
     */
    private static function name_in_options( $like ) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $names = $wpdb->get_col( $wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_value LIKE %s",
            $wpdb->esc_like( '_transient' ) . '%',
            $wpdb->esc_like( '_site_transient' ) . '%',
            $like
        ) );
        foreach ( $names as $name ) {
            if ( ! preg_match( '/(^|_)(logs?|errors?|debug|cache|history|stats?|activity|audit)(_|$)/i', $name ) && 0 !== strpos( $name, 'erdo_' ) ) {
                return true;
            }
        }
        return false;
    }
}
