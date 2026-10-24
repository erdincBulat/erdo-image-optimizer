<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * ERDO_SEO — görsel SEO kuralları (0–100 puan)
 *
 * Ağırlıklar (PLAN-3.0 §Faz 4): alt metin 40, dosya adı 20, performans 25
 * (format 10 + dosya boyutu 10 + boyutlar 5), bağlam 15. Görselin `title`
 * niteliğinin arama motoru karşılığı yok: bilgi olarak gösterilir, puana katılmaz.
 *
 * Kurallar Google Görseller rehberi ve W3C alt metin karar ağacına dayanır:
 * alt metin görseli tarif etmeli, dosya adını tekrarlamamalı, "image of"
 * gibi gereksiz kalıplarla başlamamalı ve anahtar kelime listesine dönmemeli.
 */
class ERDO_SEO {

    const WEIGHTS = array(
        'alt'        => 40,
        'filename'   => 20,
        'format'     => 10,
        'filesize'   => 10,
        'dimensions' => 5,
        'context'    => 15,
    );

    const MAX_DIMENSION  = 2560;   // core'un big_image_size_threshold varsayılanı
    const MAX_BYTES      = 256000;
    const DECORATIVE_META = '_erdo_decorative';
    const SCORE_META      = '_erdo_seo_score';

    /** Puandan durum rengi. Kullanılmayan görseller her zaman gri. */
    public static function status( $score, $is_used ) {
        if ( ! $is_used ) {
            return 'gray';
        }
        if ( $score >= 80 ) {
            return 'green';
        }
        return $score >= 50 ? 'orange' : 'red';
    }

    /* ------------------------------------------------------------------
       DOSYA ADI
    ------------------------------------------------------------------ */

    /**
     * WordPress'in eklediği sonekleri atar ve karşılaştırılabilir biçime getirir:
     * "IMG_1234-scaled" → "img-1234", "photo-e1712345678901-300x200" → "photo".
     */
    public static function clean_stem( $stem ) {
        $stem = preg_replace( '/-\d+x\d+$/', '', (string) $stem );
        $stem = preg_replace( '/-e\d{13}$/', '', $stem );
        $stem = preg_replace( '/-(scaled|rotated)$/i', '', $stem );
        $stem = strtolower( str_replace( array( '_', ' ', '.' ), '-', $stem ) );
        return trim( preg_replace( '/-+/', '-', $stem ), '-' );
    }

    /**
     * Kamera, ekran görüntüsü, mesajlaşma uygulaması ya da rastgele karakterlerden
     * oluşan, görsel hakkında hiçbir şey söylemeyen ad mı?
     */
    public static function is_meaningless_name( $stem ) {
        $s = self::clean_stem( remove_accents( (string) $stem ) );
        if ( '' === $s ) {
            return true;
        }

        // Kamera / telefon önekleri + numara: IMG_1234, DSC-0042, PXL_20240101, P1010123, GOPR0001
        if ( preg_match( '/^(img|image|dsc[nf]?|dcim|pxl|mvimg|gopr|dji|sam|imag|pic|photo|picture|cimg|sdc|wa|vid|p)-?\d{3,}/', $s ) ) {
            return true;
        }

        // Ekran görüntüsü, WhatsApp, "adsız" vb. jenerik adlar
        if ( preg_match( '/(^|-)(screenshot|screen-shot|ekran-goruntusu|ekran-resmi|whatsapp-image|whatsapp|unnamed|untitled|adsiz|image-asset|fb-img|photo-output|received)(-|$)/', $s ) ) {
            return true;
        }

        // Neredeyse tamamen rakam ya da hash/UUID
        $compact = str_replace( '-', '', $s );
        if ( strlen( $compact ) >= 6 && ( ctype_digit( $compact ) || ( ctype_xdigit( $compact ) && preg_match_all( '/\d/', $compact ) >= strlen( $compact ) * 0.3 ) ) ) {
            return true;
        }

        // En az bir gerçek kelime (3+ harf) olmalı
        return ! preg_match( '/[a-z]{3,}/', preg_replace( '/\d+/', '-', $s ) );
    }

    /** array( 'pass' => bool, 'points' => int, 'msg' => string ) */
    public static function check_filename( $stem ) {
        $w = self::WEIGHTS['filename'];

        if ( self::is_meaningless_name( $stem ) ) {
            return array( 'pass' => false, 'points' => 0, 'msg' => __( 'This is a camera-style or generic name (IMG_1234, Screenshot, WhatsApp Image…). Rename it to describe what the image shows.', 'erdo-image-optimizer' ) );
        }
        if ( preg_match( '/[_\s]/', $stem ) ) {
            return array( 'pass' => false, 'points' => (int) round( $w / 2 ), 'msg' => __( 'Filename contains underscores or spaces. Use hyphens between words.', 'erdo-image-optimizer' ) );
        }
        if ( strlen( self::clean_stem( $stem ) ) > 80 ) {
            return array( 'pass' => true, 'points' => $w - 5, 'msg' => __( 'Filename is very long. A few descriptive words are enough.', 'erdo-image-optimizer' ) );
        }
        return array( 'pass' => true, 'points' => $w, 'msg' => __( 'Descriptive, hyphenated filename.', 'erdo-image-optimizer' ) );
    }

    /* ------------------------------------------------------------------
       ALT METİN
    ------------------------------------------------------------------ */

    /** array( 'pass' => bool, 'points' => int, 'msg' => string ) */
    public static function check_alt( $alt, $stem, $decorative = false ) {
        $w   = self::WEIGHTS['alt'];
        $alt = trim( (string) $alt );

        if ( '' === $alt ) {
            if ( $decorative ) {
                return array( 'pass' => true, 'points' => $w, 'msg' => __( 'Marked as decorative: an empty alt is correct, screen readers skip it.', 'erdo-image-optimizer' ) );
            }
            return array( 'pass' => false, 'points' => 0, 'msg' => __( 'Alt text is missing. Describe the image for search engines and screen readers, or mark it as decorative.', 'erdo-image-optimizer' ) );
        }

        $slug = sanitize_title( $alt );

        if ( self::is_meaningless_name( $slug ) ) {
            return array( 'pass' => false, 'points' => 5, 'msg' => __( 'Alt text just repeats a camera-style filename. Describe what the image shows.', 'erdo-image-optimizer' ) );
        }
        if ( preg_match( '/\.(jpe?g|png|gif|webp|avif)\b/i', $alt ) || preg_match( '/^[\p{L}\p{N}]+([-_][\p{L}\p{N}]+)+$/u', $alt ) ) {
            return array( 'pass' => false, 'points' => 10, 'msg' => __( 'Alt text looks like a filename. Write it as a short sentence.', 'erdo-image-optimizer' ) );
        }
        if ( preg_match( '/^(an?\s+|the\s+)?(image|picture|photo|photograph|pic|graphic|screenshot)\s+(of|showing|shows)\b/iu', $alt )
            || preg_match( '/^(image|picture|photo|resim|görsel|fotoğraf|foto)\s*[:\-–]/iu', $alt )
            || preg_match( '/\s(resmi|görseli|fotoğrafı)$/iu', $alt ) ) {
            return array( 'pass' => false, 'points' => 25, 'msg' => __( 'Skip phrases like "image of" or "picture of": screen readers already announce it is an image.', 'erdo-image-optimizer' ) );
        }
        if ( self::is_stuffed( $alt ) ) {
            return array( 'pass' => false, 'points' => 15, 'msg' => __( 'Alt text looks like a keyword list. Google treats this as spam; describe the image in one natural sentence.', 'erdo-image-optimizer' ) );
        }
        if ( function_exists( 'mb_strlen' ) ? mb_strlen( $alt ) > 150 : strlen( $alt ) > 150 ) {
            return array( 'pass' => false, 'points' => 25, 'msg' => __( 'Alt text is very long. Keep it under about 125 characters; put longer descriptions in the caption.', 'erdo-image-optimizer' ) );
        }
        if ( $slug === self::clean_stem( $stem ) ) {
            return array( 'pass' => true, 'points' => $w - 10, 'msg' => __( 'Alt text only repeats the filename. A short description of what is shown works better.', 'erdo-image-optimizer' ) );
        }
        if ( ! preg_match( '/\s/u', $alt ) ) {
            return array( 'pass' => true, 'points' => $w - 10, 'msg' => __( 'Alt text is a single word. Fine for logos and icons; photos usually need a short description.', 'erdo-image-optimizer' ) );
        }
        return array( 'pass' => true, 'points' => $w, 'msg' => __( 'Descriptive alt text.', 'erdo-image-optimizer' ) );
    }

    /** Aynı kelime 3+ kez geçiyor ya da virgülle ayrılmış uzun bir liste. */
    private static function is_stuffed( $alt ) {
        if ( substr_count( $alt, ',' ) >= 4 ) {
            return true;
        }
        $words = preg_split( '/[^\p{L}\p{N}]+/u', function_exists( 'mb_strtolower' ) ? mb_strtolower( $alt ) : strtolower( $alt ), -1, PREG_SPLIT_NO_EMPTY );
        $count = array();
        foreach ( $words as $word ) {
            if ( strlen( $word ) >= 3 ) {
                $count[ $word ] = ( $count[ $word ] ?? 0 ) + 1;
            }
        }
        return $count && max( $count ) >= 3;
    }

    /**
     * Dosya adından okunabilir alt metin; anlamsız (kamera, ekran görüntüsü) adlarda
     * false döner — "Img 1234" bir alt metin değildir.
     */
    public static function alt_from_filename( $stem ) {
        if ( self::is_meaningless_name( $stem ) ) {
            return false;
        }
        $alt = trim( str_replace( '-', ' ', self::clean_stem( $stem ) ) );
        return '' === $alt ? false : ucwords( $alt );
    }

    /* ------------------------------------------------------------------
       SEO EKLENTİSİ ENTEGRASYONU
    ------------------------------------------------------------------ */

    /** Yazının odak anahtar kelimesi (Yoast, Rank Math, AIOSEO, SEOPress) ya da ''. */
    public static function focus_keyword( $post_id ) {
        $post_id = (int) $post_id;
        if ( ! $post_id ) {
            return '';
        }

        foreach ( array( '_yoast_wpseo_focuskw', '_seopress_analysis_target_kw' ) as $key ) {
            $kw = (string) get_post_meta( $post_id, $key, true );
            if ( '' !== $kw ) {
                return trim( explode( ',', $kw )[0] );
            }
        }

        // Rank Math birden çok kelimeyi virgülle tutar; ilki birincil olandır.
        $kw = (string) get_post_meta( $post_id, 'rank_math_focus_keyword', true );
        if ( '' !== $kw ) {
            return trim( explode( ',', $kw )[0] );
        }

        // AIOSEO kendi tablosunda JSON olarak tutar.
        global $wpdb;
        if ( defined( 'AIOSEO_VERSION' ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $json = $wpdb->get_var( $wpdb->prepare( "SELECT keyphrases FROM {$wpdb->prefix}aioseo_posts WHERE post_id = %d", $post_id ) );
            $data = $json ? json_decode( $json, true ) : null;
            if ( ! empty( $data['focus']['keyphrase'] ) ) {
                return trim( (string) $data['focus']['keyphrase'] );
            }
        }
        return '';
    }

    /** Görsel site haritası üreten bir SEO eklentisi etkin mi? */
    public static function seo_plugin_handles_image_sitemap() {
        return defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEO_VERSION' ) || defined( 'SEOPRESS_VERSION' );
    }
}
