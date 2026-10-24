<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * ERDO_References — veritabanındaki görsel URL'lerini güvenle değiştirir
 *
 * Ham SQL REPLACE() serileştirilmiş veride uzunluk önekini bozar (s:42:"…"),
 * JSON'da kaçışlı "http:\/\/" biçimini kaçırır ve object cache'i bayat
 * bırakır. Bu sınıf:
 *   - post_content, postmeta ve options satırlarını PHP tarafında işler;
 *   - serileştirilmiş değeri açıp içteki string'leri değiştirip yeniden serileştirir
 *     (iç içe serileştirme dahil);
 *   - "/" ve "\/" ayraçlı URL'lerin ikisini de bulur, kaçış biçimini korur;
 *   - her değişiklikten sonra ilgili cache'i temizler.
 *
 * Eşleme, uploads altındaki göreli yol üzerinden yapılır ("2026/10/foto.jpg");
 * alan adı / şema kısmına dokunulmaz, böylece http/https ve CDN farkları sorun olmaz.
 */
class ERDO_References {

    /** Görsel uzantıları — yalnızca bunlarla biten yollar callback'e gider. */
    const EXT = 'jpe?g|png|gif|webp|avif';

    /**
     * $map_fn( string $rel_path ): ?string — yeni göreli yol ya da değişmeyecekse null.
     * $needle — aday satırları daraltmak için LIKE araması (ör. dosya adı gövdesi).
     * Döner: array( 'posts' => n, 'meta' => n, 'options' => n ) değişen satır sayısı.
     */
    public static function replace( callable $map_fn, $needle, $dry_run = false ) {
        global $wpdb;
        $count = array( 'posts' => 0, 'meta' => 0, 'options' => 0 );
        $like  = '%' . $wpdb->esc_like( $needle ) . '%';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        // 1. İçerik — revizyonlar geçmiş kaydıdır, dokunulmaz.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_type <> 'revision' AND post_content LIKE %s",
            $like
        ) );
        foreach ( $rows as $row ) {
            $new = self::replace_in_value( $row->post_content, $map_fn );
            if ( $new !== $row->post_content ) {
                $count['posts']++;
                if ( ! $dry_run ) {
                    $wpdb->update( $wpdb->posts, array( 'post_content' => $new ), array( 'ID' => $row->ID ) );
                    clean_post_cache( (int) $row->ID );
                }
            }
        }

        // 2. Post meta (sayfa oluşturucular, ACF, slider'lar…)
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT meta_id, post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_value LIKE %s",
            $like
        ) );
        foreach ( $rows as $row ) {
            $new = self::replace_in_value( $row->meta_value, $map_fn );
            if ( $new !== $row->meta_value ) {
                $count['meta']++;
                if ( ! $dry_run ) {
                    $wpdb->update( $wpdb->postmeta, array( 'meta_value' => $new ), array( 'meta_id' => $row->meta_id ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
                    wp_cache_delete( (int) $row->post_id, 'post_meta' );
                }
            }
        }

        // 3. Seçenekler (theme_mods, widget'lar, builder global ayarları…) — geçici veriler ve kendi ayarlarımız hariç.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT option_id, option_name, option_value FROM {$wpdb->options}
             WHERE option_value LIKE %s
             AND option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s",
            $like,
            $wpdb->esc_like( '_transient' ) . '%',
            $wpdb->esc_like( '_site_transient' ) . '%',
            $wpdb->esc_like( 'erdo_' ) . '%'
        ) );
        foreach ( $rows as $row ) {
            $new = self::replace_in_value( $row->option_value, $map_fn );
            if ( $new !== $row->option_value ) {
                $count['options']++;
                if ( ! $dry_run ) {
                    $wpdb->update( $wpdb->options, array( 'option_value' => $new ), array( 'option_id' => $row->option_id ) );
                    wp_cache_delete( $row->option_name, 'options' );
                    wp_cache_delete( 'alloptions', 'options' );
                    wp_cache_delete( 'notoptions', 'options' );
                }
            }
        }

        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        return $count;
    }

    /**
     * Ham DB değerinde (serileştirilmiş olabilir) URL değiştirir ve yine ham
     * değer döndürür. Değişiklik yoksa girdiyi aynen döndürür.
     */
    public static function replace_in_value( $raw, callable $map_fn ) {
        if ( ! is_string( $raw ) || '' === $raw ) {
            return $raw;
        }

        if ( is_serialized( $raw ) ) {
            $data = @unserialize( $raw, array( 'allowed_classes' => array( 'stdClass' ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions, WordPress.PHP.NoSilencedErrors
            if ( false === $data && 'b:0;' !== $raw ) {
                return $raw; // Zaten bozuk ya da bilinmeyen sınıf içeriyor — dokunma.
            }
            $changed = false;
            $data    = self::walk( $data, $map_fn, $changed );
            return $changed ? serialize( $data ) : $raw; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
        }

        return self::replace_in_string( $raw, $map_fn );
    }

    private static function walk( $value, callable $map_fn, &$changed ) {
        if ( is_string( $value ) ) {
            $new = self::replace_in_value( $value, $map_fn ); // iç içe serileştirme
            if ( $new !== $value ) {
                $changed = true;
            }
            return $new;
        }
        if ( is_array( $value ) ) {
            foreach ( $value as $k => $v ) {
                $value[ $k ] = self::walk( $v, $map_fn, $changed );
            }
            return $value;
        }
        if ( $value instanceof stdClass ) {
            foreach ( get_object_vars( $value ) as $k => $v ) {
                $value->$k = self::walk( $v, $map_fn, $changed );
            }
        }
        return $value;
    }

    /**
     * Düz metindeki (HTML, JSON, CSS) uploads URL'lerini değiştirir.
     * Hem "/wp-content/uploads/2026/10/a.jpg" hem "\/wp-content\/uploads\/2026\/10\/a.jpg".
     */
    public static function replace_in_string( $text, callable $map_fn ) {
        $pattern = self::pattern();
        if ( ! $pattern || false === strpos( str_replace( '\\/', '/', $text ), self::uploads_path() ) ) {
            return $text;
        }

        return preg_replace_callback( $pattern, function ( $m ) use ( $map_fn ) {
            $escaped = false !== strpos( $m[0], '\\/' );
            $rel     = str_replace( '\\/', '/', $m['rel'] );
            $new     = $map_fn( $rel );
            if ( null === $new || $new === $rel ) {
                return $m[0];
            }
            $new = $escaped ? str_replace( '/', '\\/', $new ) : $new;
            return $m['prefix'] . $new;
        }, $text );
    }

    /** Uploads URL yolu, ör. "/wp-content/uploads/" */
    public static function uploads_path() {
        $path = wp_parse_url( wp_upload_dir()['baseurl'], PHP_URL_PATH );
        return trailingslashit( $path ? $path : '/' );
    }

    private static function pattern() {
        static $cache = array();
        $upath = self::uploads_path();
        if ( isset( $cache[ $upath ] ) ) {
            return $cache[ $upath ];
        }
        $sep   = '(?:\\\\/|/)';
        $parts = array_map( function ( $p ) {
            return preg_quote( $p, '~' );
        }, array_filter( explode( '/', $upath ), 'strlen' ) );
        $prefix = $sep . implode( $sep, $parts ) . $sep;
        // Dosya yolu: ayraçlar dışında boşluk, tırnak, <>, () ve ters bölü içermez.
        $seg = '[^\s"\'<>()\\\\/?#]+';
        // Ayraç "~": karakter sınıfında "#" geçtiği için "#" ayraç olarak kullanılamaz.
        $cache[ $upath ] = '~(?P<prefix>' . $prefix . ')(?P<rel>(?:' . $seg . $sep . ')*' . $seg . '\.(?:' . self::EXT . '))(?![\w.])~i';
        return $cache[ $upath ];
    }

    /** "2026/10/foto.jpg" → mutlak yol */
    public static function rel_to_path( $rel ) {
        return trailingslashit( wp_upload_dir()['basedir'] ) . rawurldecode( $rel );
    }
}
