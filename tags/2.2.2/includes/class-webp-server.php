<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * ERDO_WebP_Server
 *
 * İki yöntemle WebP servis eder:
 *   A) Apache .htaccess rewrite  — sunucu taraflı, sıfır PHP yükü
 *   B) PHP <picture> rewriter    — tüm sunucularda çalışır (fallback)
 *
 * Mantık: görseller .jpg/.png olarak HTML'de durur, sunucu veya PHP
 * WebP destekleyen tarayıcılara şeffaf olarak .webp gönderir.
 * Böylece hem orijinal hem WebP korunabilir, eski tarayıcılar kırılmaz.
 */
class ERDO_WebP_Server {

    const HTACCESS_MARKER = 'Erdo WebP';

    /* ------------------------------------------------------------------
       KURULUM / KALDIRMA
    ------------------------------------------------------------------ */

    public static function init() {
        if ( ! get_option( 'erdo_serve_webp' ) ) {
            return;
        }

        $method = get_option( 'erdo_webp_serve_method', 'picture' );

        if ( 'htaccess' === $method && self::is_apache() ) {
            // .htaccess zaten yazılmış, ek PHP işlemi yok.
            return;
        }

        // PHP picture rewriter — front-end filtreler
        add_filter( 'the_content',            array( __CLASS__, 'rewrite_html' ), 99 );
        add_filter( 'post_thumbnail_html',    array( __CLASS__, 'rewrite_html' ), 99 );
        add_filter( 'wp_get_attachment_image',array( __CLASS__, 'rewrite_html' ), 99 );
    }

    /* ------------------------------------------------------------------
       SUNUCU TESPİTİ
    ------------------------------------------------------------------ */

    public static function is_apache() {
        $software = sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ?? '' ) );
        return stripos( $software, 'apache' ) !== false;
    }

    public static function is_nginx() {
        $software = sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ?? '' ) );
        return stripos( $software, 'nginx' ) !== false;
    }

    /* ------------------------------------------------------------------
       .HTACCESS KURULUM / KALDIRMA
    ------------------------------------------------------------------ */

    public static function write_htaccess_rules() {
        $upload_dir = wp_upload_dir();
        $htaccess   = trailingslashit( $upload_dir['basedir'] ) . '.htaccess';

        $rules = "\n# BEGIN " . self::HTACCESS_MARKER . "\n"
            . "<IfModule mod_rewrite.c>\n"
            . "  RewriteEngine On\n"
            . "  RewriteCond %{HTTP_ACCEPT} image/webp\n"
            . "  RewriteCond %{REQUEST_FILENAME} \\.(jpe?g|png|gif)$\n"
            . "  RewriteCond %{REQUEST_FILENAME}\\.webp -f\n"
            . "  RewriteRule ^ %{REQUEST_URI}.webp [L,T=image/webp]\n"
            . "</IfModule>\n"
            . "<IfModule mod_headers.c>\n"
            . "  <FilesMatch \"\\.(jpe?g|png|gif)$\">\n"
            . "    Header append Vary Accept\n"
            . "  </FilesMatch>\n"
            . "</IfModule>\n"
            . "# END " . self::HTACCESS_MARKER . "\n";

        $existing = file_exists( $htaccess ) ? file_get_contents( $htaccess ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
        if ( strpos( $existing, '# BEGIN ' . self::HTACCESS_MARKER ) !== false ) {
            // Zaten var — güncelle
            $pattern  = '/# BEGIN ' . preg_quote( self::HTACCESS_MARKER, '/' ) . '.*?# END ' . preg_quote( self::HTACCESS_MARKER, '/' ) . '\n?/s';
            $existing = preg_replace( $pattern, '', $existing );
        }

        file_put_contents( $htaccess, $existing . $rules ); // phpcs:ignore WordPress.WP.AlternativeFunctions
    }

    public static function remove_htaccess_rules() {
        $upload_dir = wp_upload_dir();
        $htaccess   = trailingslashit( $upload_dir['basedir'] ) . '.htaccess';

        if ( ! file_exists( $htaccess ) ) {
            return;
        }

        $content = file_get_contents( $htaccess ); // phpcs:ignore WordPress.WP.AlternativeFunctions
        $pattern = '/# BEGIN ' . preg_quote( self::HTACCESS_MARKER, '/' ) . '.*?# END ' . preg_quote( self::HTACCESS_MARKER, '/' ) . '\n?/s';
        $content = preg_replace( $pattern, '', $content );
        file_put_contents( $htaccess, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions
    }

    /* ------------------------------------------------------------------
       NGINX KURAL METNİ
    ------------------------------------------------------------------ */

    public static function get_nginx_config() {
        $upload_path = str_replace( ABSPATH, '/', wp_upload_dir()['basedir'] );
        return "# Nginx sunucunuzun site konfigürasyonuna ekleyin:\n"
            . "location ~* ^" . $upload_path . ".*\\.(jpe?g|png|gif)$ {\n"
            . "    add_header Vary Accept;\n"
            . "    try_files \$uri.webp \$uri =404;\n"
            . "}";
    }

    /* ------------------------------------------------------------------
       PHP <PICTURE> REWRITER
    ------------------------------------------------------------------ */

    /**
     * HTML içindeki <img> etiketlerini, eğer .webp karşılığı diskte mevcutsa
     * <picture><source type="image/webp" ...><img ...></picture> ile sarar.
     */
    public static function rewrite_html( $html ) {
        if ( empty( $html ) || is_admin() ) {
            return $html;
        }

        return preg_replace_callback(
            '/<img([^>]+)>/i',
            array( __CLASS__, 'replace_img_tag' ),
            $html
        );
    }

    private static function replace_img_tag( $matches ) {
        $attrs    = $matches[1];
        $orig_tag = $matches[0];

        // src özelliğini al
        if ( ! preg_match( '/\bsrc=["\']([^"\']+)["\']/', $attrs, $src_m ) ) {
            return $orig_tag;
        }

        $src = $src_m[1];

        // Sadece uploads dizinindeki görseller
        $upload_dir = wp_upload_dir();
        if ( strpos( $src, $upload_dir['baseurl'] ) === false ) {
            return $orig_tag;
        }

        // .jpg/.png/.gif uzantılı mı?
        if ( ! preg_match( '/\.(jpe?g|png|gif)(\?.*)?$/i', $src, $ext_m ) ) {
            return $orig_tag;
        }

        // WebP karşılığı URL ve yolu hesapla
        $src_clean = strtok( $src, '?' );
        $webp_url  = preg_replace( '/\.(jpe?g|png|gif)$/i', '.webp', $src_clean );
        $webp_path = str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], $webp_url );

        if ( ! file_exists( $webp_path ) ) {
            return $orig_tag;
        }

        // srcset varsa onu da dönüştür
        $srcset_html = '';
        if ( preg_match( '/\bsrcset=["\']([^"\']+)["\']/', $attrs, $ss_m ) ) {
            $webp_srcset = preg_replace( '/\.(jpe?g|png|gif)/i', '.webp', $ss_m[1] );
            $srcset_html = ' srcset="' . esc_attr( $webp_srcset ) . '"';
        }

        return '<picture>'
            . '<source type="image/webp"'
            . ' src="' . esc_url( $webp_url ) . '"'
            . $srcset_html . '>'
            . $orig_tag
            . '</picture>';
    }

    /* ------------------------------------------------------------------
       .HTACCESS DURUMU
    ------------------------------------------------------------------ */

    public static function htaccess_has_rules() {
        $upload_dir = wp_upload_dir();
        $htaccess   = trailingslashit( $upload_dir['basedir'] ) . '.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            return false;
        }
        return strpos( file_get_contents( $htaccess ), '# BEGIN ' . self::HTACCESS_MARKER ) !== false; // phpcs:ignore WordPress.WP.AlternativeFunctions
    }
}
