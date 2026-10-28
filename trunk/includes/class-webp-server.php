<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * ERDO_WebP_Server — modern formatların ziyaretçiye sunulması
 *
 * Kardeş dosyalar ("foto.jpg.webp" / "foto.jpg.avif", bkz. ERDO_Converter)
 * iki yöntemden biriyle sunulur:
 *   A) <picture> — HTML'deki <img>, AVIF/WebP <source srcset> ile sarılır.
 *      Varsayılan; CDN ve Cloudflare dahil her sunucuda çalışır.
 *   B) Apache .htaccess — aynı URL, tarayıcı destekliyorsa kardeş dosyayla
 *      yanıtlanır (Vary: Accept). Sıfır HTML değişikliği.
 *
 * Ayrıca: 2.x'in sildiği dosyalara işaret eden içerik URL'lerini diskte
 * mevcut .webp/.avif karşılıklarına çeviren kurtarma filtresi (yalnızca
 * onarım bekleyen görsel varken çalışır, bkz. ERDO_Migration).
 */
class ERDO_WebP_Server {

    const HTACCESS_MARKER = 'Erdo WebP';
    const REDIRECT_MARKER = 'Erdo Redirects';
    const DONE_ATTR       = 'data-erdo-picture';

    public static function init() {
        if ( is_admin() ) {
            return;
        }

        if ( (int) get_option( ERDO_Migration::LEGACY_OPTION, 0 ) > 0 ) {
            add_filter( 'wp_content_img_tag', array( __CLASS__, 'filter_rescue' ), 9 );
        }

        if ( ! get_option( 'erdo_serve_webp' ) ) {
            return;
        }

        if ( 'htaccess' === get_option( 'erdo_webp_serve_method', 'picture' ) && self::is_apache() ) {
            // Sunucu yapıyor; PHP tarafında iş yok.
            return;
        }

        add_filter( 'wp_content_img_tag', array( __CLASS__, 'filter_content_img' ), 20, 3 );
        add_filter( 'wp_get_attachment_image', array( __CLASS__, 'filter_attachment_image' ), 20, 2 );
    }

    /* ------------------------------------------------------------------
       SUNUCU TESPİTİ
    ------------------------------------------------------------------ */

    public static function is_apache() {
        $software = sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ?? '' ) );
        return stripos( $software, 'apache' ) !== false || stripos( $software, 'litespeed' ) !== false;
    }

    public static function is_nginx() {
        $software = sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ?? '' ) );
        return stripos( $software, 'nginx' ) !== false;
    }

    /* ------------------------------------------------------------------
       <PICTURE> YAZICI
    ------------------------------------------------------------------ */

    public static function filter_content_img( $html, $context = '', $attachment_id = 0 ) {
        return self::should_rewrite() ? self::picture_markup( $html, (int) $attachment_id ) : $html;
    }

    public static function filter_attachment_image( $html, $attachment_id = 0 ) {
        return self::should_rewrite() ? self::picture_markup( $html, (int) $attachment_id ) : $html;
    }

    private static function should_rewrite() {
        return ! is_feed() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) && ! wp_doing_ajax();
    }

    /**
     * <img> → <picture><source type="image/avif" srcset><source type="image/webp" srcset><img></picture>
     *
     * - img'nin srcset'indeki her aday için kardeş varsa kardeş URL'si,
     *   yoksa orijinal URL kullanılır (tarayıcı türü içerikten anlar);
     *   formatın hiç kardeşi yoksa o <source> eklenmez.
     * - Sarılan img işaretlenir: blok temalarda aynı HTML ikinci kez
     *   wp_filter_content_tags'ten geçtiğinde iç içe <picture> oluşmaz.
     */
    public static function picture_markup( $img, $attachment_id = 0 ) {
        if ( false !== strpos( $img, self::DONE_ATTR ) || false !== stripos( $img, 'data-erdo-skip' ) ) {
            return $img;
        }

        $p = new WP_HTML_Tag_Processor( $img );
        if ( ! $p->next_tag( 'img' ) ) {
            return $img;
        }
        $src    = $p->get_attribute( 'src' );
        $srcset = $p->get_attribute( 'srcset' );
        $sizes  = $p->get_attribute( 'sizes' );
        if ( ! is_string( $src ) || '' === $src ) {
            return $img;
        }

        $candidates = ( is_string( $srcset ) && '' !== trim( $srcset ) )
            ? self::parse_srcset( $srcset )
            : array( array( 'url' => $src, 'desc' => '' ) );

        $record  = $attachment_id ? ERDO_Converter::record( $attachment_id ) : null;
        $sources = '';

        foreach ( array( 'avif', 'webp' ) as $fmt ) {
            $parts = array();
            $hits  = 0;
            foreach ( $candidates as $c ) {
                $sib = self::sibling_url( $c['url'], $fmt, $record );
                if ( $sib ) {
                    $hits++;
                }
                $parts[] = trim( ( $sib ? $sib : $c['url'] ) . ' ' . $c['desc'] );
            }
            if ( ! $hits ) {
                continue;
            }
            $sources .= '<source type="image/' . $fmt . '" srcset="' . esc_attr( implode( ', ', $parts ) ) . '"'
                . ( is_string( $sizes ) && '' !== $sizes ? ' sizes="' . esc_attr( $sizes ) . '"' : '' ) . '>';
        }

        if ( '' === $sources ) {
            return $img;
        }

        $p->set_attribute( self::DONE_ATTR, '1' );
        return '<picture class="erdo-picture">' . $sources . $p->get_updated_html() . '</picture>';
    }

    private static function parse_srcset( $srcset ) {
        $out = array();
        foreach ( explode( ',', $srcset ) as $part ) {
            $part = trim( $part );
            if ( '' === $part ) {
                continue;
            }
            $bits  = preg_split( '/\s+/', $part, 2 );
            $out[] = array( 'url' => $bits[0], 'desc' => $bits[1] ?? '' );
        }
        return $out;
    }

    /**
     * Bir uploads URL'sinin $fmt kardeşinin URL'si, yoksa null.
     * Attachment kaydı varsa diske bakmadan ondan okunur.
     */
    private static function sibling_url( $url, $fmt, $record = null ) {
        $clean = strtok( $url, '?#' );
        if ( ! preg_match( '/\.(jpe?g|png|gif)$/i', $clean ) ) {
            return null;
        }
        $path = self::url_to_path( $clean );
        if ( ! $path ) {
            return null;
        }

        if ( is_array( $record ) && ! empty( $record['formats'] ) ) {
            $ok = isset( $record['formats'][ $fmt ][ basename( $path ) ] );
        } else {
            $ok = self::file_exists_cached( $path . '.' . $fmt );
        }
        return $ok ? $clean . '.' . $fmt : null;
    }

    /** Uploads altındaki URL → mutlak yol (şema/alan adı farkı yok sayılır), değilse null. */
    public static function url_to_path( $url ) {
        $upath = ERDO_References::uploads_path();
        $path  = wp_parse_url( $url, PHP_URL_PATH );
        if ( ! $path || 0 !== strpos( $path, $upath ) ) {
            return null;
        }
        $host = wp_parse_url( $url, PHP_URL_HOST );
        if ( $host && wp_parse_url( home_url(), PHP_URL_HOST ) !== $host && wp_parse_url( wp_upload_dir()['baseurl'], PHP_URL_HOST ) !== $host ) {
            return null; // CDN ya da başka alan adı — dosya bizim diskimizde değil
        }
        return ERDO_References::rel_to_path( substr( $path, strlen( $upath ) ) );
    }

    private static function file_exists_cached( $path ) {
        static $cache = array();
        if ( ! isset( $cache[ $path ] ) ) {
            $cache[ $path ] = file_exists( $path );
        }
        return $cache[ $path ];
    }

    /* ------------------------------------------------------------------
       KURTARMA FİLTRESİ — 2.x'in kırdığı içerik URL'leri
    ------------------------------------------------------------------ */

    public static function filter_rescue( $html ) {
        $p = new WP_HTML_Tag_Processor( $html );
        if ( ! $p->next_tag( 'img' ) ) {
            return $html;
        }
        $changed = false;

        $src = $p->get_attribute( 'src' );
        if ( is_string( $src ) ) {
            $new = self::rescue_url( $src );
            if ( $new !== $src ) {
                $p->set_attribute( 'src', $new );
                $changed = true;
            }
        }

        $srcset = $p->get_attribute( 'srcset' );
        if ( is_string( $srcset ) && '' !== $srcset ) {
            $parts = array();
            foreach ( self::parse_srcset( $srcset ) as $c ) {
                $parts[] = trim( self::rescue_url( $c['url'] ) . ' ' . $c['desc'] );
            }
            $new = implode( ', ', $parts );
            if ( $new !== $srcset ) {
                $p->set_attribute( 'srcset', $new );
                $changed = true;
            }
        }

        return $changed ? $p->get_updated_html() : $html;
    }

    private static function rescue_url( $url ) {
        $clean = strtok( $url, '?#' );
        if ( ! preg_match( '/\.(jpe?g|png|gif)$/i', $clean ) ) {
            return $url;
        }
        $path = self::url_to_path( $clean );
        if ( ! $path || self::file_exists_cached( $path ) ) {
            return $url;
        }
        foreach ( array( 'webp', 'avif' ) as $fmt ) {
            $alt = preg_replace( '/\.(jpe?g|png|gif)$/i', '.' . $fmt, $path );
            if ( self::file_exists_cached( $alt ) ) {
                return preg_replace( '/\.(jpe?g|png|gif)$/i', '.' . $fmt, $clean );
            }
        }
        return $url;
    }

    /* ------------------------------------------------------------------
       .HTACCESS KURULUM / KALDIRMA
    ------------------------------------------------------------------ */

    public static function htaccess_rules() {
        return "# BEGIN " . self::HTACCESS_MARKER . "\n"
            . "<IfModule mod_rewrite.c>\n"
            . "  RewriteEngine On\n"
            // Bu klasörde RewriteEngine açılınca kökteki WordPress kuralları uygulanmaz; eksik
            // dosya index.php'ye düşmez ve yeniden adlandırılmış görselin eski URL'si 404 olur.
            . "  RewriteOptions Inherit\n"
            . "  RewriteCond %{HTTP_ACCEPT} image/avif\n"
            . "  RewriteCond %{REQUEST_FILENAME}.avif -f\n"
            . "  RewriteRule ^(.+)\\.(jpe?g|png|gif)$ $1.$2.avif [NC,T=image/avif,L]\n"
            . "  RewriteCond %{HTTP_ACCEPT} image/webp\n"
            . "  RewriteCond %{REQUEST_FILENAME}.webp -f\n"
            . "  RewriteRule ^(.+)\\.(jpe?g|png|gif)$ $1.$2.webp [NC,T=image/webp,L]\n"
            . "</IfModule>\n"
            // Kardeşler de (x.jpg.webp): yeniden yazmadan sonra eşleşen ad bu. Yoksa LiteSpeed
            // WebP yanıtına Vary eklemez ve önbellekler JPEG ile WebP'yi karıştırır.
            . "<IfModule mod_headers.c>\n"
            . "  <FilesMatch \"(?i)\\.(jpe?g|png|gif)(\\.(webp|avif))?$\">\n"
            . "    Header append Vary Accept\n"
            . "  </FilesMatch>\n"
            . "</IfModule>\n"
            . "<IfModule mod_mime.c>\n"
            . "  AddType image/webp .webp\n"
            . "  AddType image/avif .avif\n"
            . "</IfModule>\n"
            . "# END " . self::HTACCESS_MARKER . "\n";
    }

    private static function htaccess_path() {
        return trailingslashit( wp_upload_dir()['basedir'] ) . '.htaccess';
    }

    private static function strip_block( $content, $marker = self::HTACCESS_MARKER ) {
        $pattern = '/\n?# BEGIN ' . preg_quote( $marker, '/' ) . '.*?# END ' . preg_quote( $marker, '/' ) . '\n?/s';
        return preg_replace( $pattern, "\n", $content );
    }

    private static function write_block( $marker, $rules ) {
        $file     = self::htaccess_path();
        $existing = file_exists( $file ) ? file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
        $content  = rtrim( self::strip_block( $existing, $marker ) );
        $content  = ( '' === $content ? '' : $content . "\n\n" ) . $rules;
        return false !== file_put_contents( $file, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions
    }

    private static function remove_block( $marker ) {
        $file = self::htaccess_path();
        if ( ! file_exists( $file ) ) {
            return;
        }
        $content = trim( self::strip_block( file_get_contents( $file ), $marker ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
        if ( '' === $content ) {
            wp_delete_file( $file );
        } else {
            file_put_contents( $file, $content . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
        }
    }

    public static function write_htaccess_rules() {
        return self::write_block( self::HTACCESS_MARKER, self::htaccess_rules() );
    }

    /** Yalnızca WebP/AVIF bloğu (teslim yöntemi değişince). */
    public static function remove_webp_rules() {
        self::remove_block( self::HTACCESS_MARKER );
    }

    /** WebP bloğu ve (varsa) yönlendirme bloğu: devre dışı bırakma / kaldırma. */
    public static function remove_htaccess_rules() {
        self::remove_block( self::HTACCESS_MARKER );
        self::remove_block( self::REDIRECT_MARKER );
    }

    /* ------------------------------------------------------------------
       ESKİ URL YÖNLENDİRMESİ İÇİN .HTACCESS (multisite)
       Standart multisite kuralları wp-content/... isteklerini dosya yoksa da
       Apache'ye bırakır; yeniden adlandırılmış görselin eski URL'si WordPress'e
       hiç ulaşmaz ve 301 yerine 404 döner. Tek sitenin kuralları zaten
       index.php'ye düşer. nginx .htaccess okumaz (snippet try_files ile düşer).
    ------------------------------------------------------------------ */

    public static function redirect_fallback_rules() {
        $index = trailingslashit( (string) wp_parse_url( network_site_url( '/' ), PHP_URL_PATH ) ) . 'index.php';
        return "# BEGIN " . self::REDIRECT_MARKER . "\n"
            . "<IfModule mod_rewrite.c>\n"
            . "  RewriteEngine On\n"
            . "  RewriteOptions Inherit\n"
            . "  RewriteCond %{REQUEST_FILENAME} !-f\n"
            . "  RewriteRule \\.(jpe?g|png|gif|webp|avif)$ " . $index . " [NC,L]\n"
            . "</IfModule>\n"
            . "# END " . self::REDIRECT_MARKER . "\n";
    }

    /** Yönlendirme kaydedildiğinde çağrılır; yalnızca multisite'ta ve blok yoksa yazar. */
    public static function ensure_redirect_fallback() {
        if ( ! is_multisite() ) {
            return;
        }
        $file = self::htaccess_path();
        if ( file_exists( $file ) && false !== strpos( (string) file_get_contents( $file ), '# BEGIN ' . self::REDIRECT_MARKER ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
            return;
        }
        self::write_block( self::REDIRECT_MARKER, self::redirect_fallback_rules() );
    }

    public static function htaccess_has_rules() {
        $file = self::htaccess_path();
        return file_exists( $file ) && false !== strpos( file_get_contents( $file ), '# BEGIN ' . self::HTACCESS_MARKER ); // phpcs:ignore WordPress.WP.AlternativeFunctions
    }

    /* ------------------------------------------------------------------
       NGINX KURAL METNİ
    ------------------------------------------------------------------ */

    public static function get_nginx_config() {
        $upload_path = untrailingslashit( ERDO_References::uploads_path() );
        // set/if server düzeyinde: location içindeki "if" kendi örtük location'ını açar ve
        // try_files orada çalışmaz (nginx "if is evil") — kardeş hiç servis edilmiyordu.
        // Boş sonek "$uri"yu ilk sıraya alıp kardeşi atlatır; bu yüzden var olmayan bir sonek.
        return "# Add inside your site's server { } block (not inside a location), then reload nginx:\n"
            . "set \$erdo_avif \".no-avif\";\n"
            . "set \$erdo_webp \".no-webp\";\n"
            . "if (\$http_accept ~* \"image/avif\") { set \$erdo_avif \".avif\"; }\n"
            . "if (\$http_accept ~* \"image/webp\") { set \$erdo_webp \".webp\"; }\n"
            . "location ~* ^" . $upload_path . "/.+\\.(jpe?g|png|gif)$ {\n"
            . "    add_header Vary Accept;\n"
            // Eski nginx mime.types dosyalarında avif yok; türler burada açıkça verilir.
            . "    types { image/jpeg jpg jpeg; image/png png; image/gif gif; image/webp webp; image/avif avif; }\n"
            // Dosya yoksa WordPress'e düşer: yeniden adlandırılmış görselin eski URL'si 301 alır.
            . "    try_files \$uri\$erdo_avif \$uri\$erdo_webp \$uri /index.php?\$args;\n"
            . "}";
    }
}
