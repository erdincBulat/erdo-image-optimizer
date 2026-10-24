<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * ERDO_Encoder — WebP/AVIF kodlama motoru
 *
 * Tek bir dosyayı hedef formata kodlar; GD veya Imagick kullanır.
 * Sunucunun gerçekte neyi destekleyip desteklemediği function_exists()
 * ile değil, küçük bir deneme kodlamasıyla ölçülür — bazı sunucularda
 * imageavif() mevcut ama boş dosya üretiyor (core #60628, php #81217).
 */
class ERDO_Encoder {

    const CAPS_OPTION = 'erdo_encoder_caps';
    const FORMATS     = array( 'webp', 'avif' );

    /* ------------------------------------------------------------------
       YETENEK TESPİTİ
    ------------------------------------------------------------------ */

    /**
     * array( 'webp' => array( 'gd' => bool, 'imagick' => bool, 'lossless' => bool, 'animated' => bool ), 'avif' => ... )
     * Sonuç PHP / eklenti sürümüne bağlı bir anahtarla saklanır; sunucu
     * güncellenince kendiliğinden yeniden ölçülür.
     */
    public static function capabilities( $refresh = false ) {
        $fingerprint = PHP_VERSION . '|' . ( function_exists( 'gd_info' ) ? ( gd_info()['GD Version'] ?? 'gd' ) : '-' ) . '|' . ( phpversion( 'imagick' ) ?: '-' );
        $stored      = get_option( self::CAPS_OPTION );

        if ( ! $refresh && is_array( $stored ) && ( $stored['fingerprint'] ?? '' ) === $fingerprint ) {
            return $stored['caps'];
        }

        $caps = array();
        foreach ( self::FORMATS as $fmt ) {
            $gd      = self::probe_gd( $fmt );
            $imagick = self::probe_imagick( $fmt );
            $caps[ $fmt ] = array(
                'gd'       => $gd,
                'imagick'  => $imagick,
                // GD'de gerçek lossless için IMG_WEBP_LOSSLESS (PHP 8.1+) gerekir.
                'lossless' => 'webp' === $fmt && ( $imagick || ( $gd && defined( 'IMG_WEBP_LOSSLESS' ) ) ),
                // GD animasyon yazamaz; animasyonlu GIF yalnızca Imagick ile korunur.
                'animated' => 'webp' === $fmt && $imagick,
            );
        }

        update_option( self::CAPS_OPTION, array( 'fingerprint' => $fingerprint, 'caps' => $caps ), false );
        return $caps;
    }

    public static function supports( $format ) {
        $caps = self::capabilities();
        return ! empty( $caps[ $format ]['gd'] ) || ! empty( $caps[ $format ]['imagick'] );
    }

    public static function supports_animated( $format ) {
        $caps = self::capabilities();
        return ! empty( $caps[ $format ]['animated'] );
    }

    private static function probe_gd( $format ) {
        $fn = 'image' . $format;
        if ( ! function_exists( $fn ) || ! function_exists( 'imagecreatetruecolor' ) ) {
            return false;
        }
        $im = imagecreatetruecolor( 8, 8 );
        imagefilledrectangle( $im, 0, 0, 7, 7, imagecolorallocate( $im, 200, 30, 30 ) );
        ob_start();
        $ok   = @$fn( $im, null, 80 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        $data = ob_get_clean();
        imagedestroy( $im );
        return $ok && self::sniff( $data ) === $format;
    }

    private static function probe_imagick( $format ) {
        if ( ! class_exists( 'Imagick' ) ) {
            return false;
        }
        try {
            if ( ! in_array( strtoupper( $format ), Imagick::queryFormats( strtoupper( $format ) ), true ) ) {
                return false;
            }
            $im = new Imagick();
            $im->newImage( 8, 8, 'red' );
            $im->setImageFormat( $format );
            $data = $im->getImageBlob();
            $im->clear();
            return self::sniff( $data ) === $format;
        } catch ( Exception $e ) {
            return false;
        }
    }

    /** Dosya başlığından gerçek formatı okur: 'webp' | 'avif' | ''. */
    public static function sniff( $bytes ) {
        if ( strlen( $bytes ) < 16 ) {
            return '';
        }
        if ( 'RIFF' === substr( $bytes, 0, 4 ) && 'WEBP' === substr( $bytes, 8, 4 ) ) {
            return 'webp';
        }
        $brand = substr( $bytes, 4, 8 );
        if ( 'ftypavif' === $brand || 'ftypavis' === $brand ) {
            return 'avif';
        }
        return '';
    }

    /** Birden fazla kare içeren GIF mi? (Grafik kontrol uzantısı + görüntü tanımlayıcı sayısı) */
    public static function is_animated_gif( $path ) {
        $data = @file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors
        if ( ! $data ) {
            return false;
        }
        return preg_match_all( '#\x00\x21\xF9\x04.{4}\x00[\x2C\x21]#s', $data ) > 1;
    }

    /* ------------------------------------------------------------------
       KODLAMA
    ------------------------------------------------------------------ */

    /**
     * $src dosyasını $format olarak $dest'e yazar.
     *
     * $opts: quality (int), lossless (bool, yalnızca WebP), smart (bool)
     * Döner: yazılan bayt sayısı | WP_Error ('unsupported', 'animated', 'encode_failed', 'read_failed')
     *
     * Çıktı önce geçici dosyaya yazılır ve doğrulandıktan sonra yerine
     * taşınır — ziyaretçiye hiçbir zaman yarım dosya sunulmaz.
     */
    public static function encode( $src, $dest, $format, array $opts = array() ) {
        $caps = self::capabilities();
        if ( empty( $caps[ $format ]['gd'] ) && empty( $caps[ $format ]['imagick'] ) ) {
            return new WP_Error( 'unsupported', sprintf( 'This server cannot encode %s.', strtoupper( $format ) ) );
        }

        $info = @getimagesize( $src ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        if ( ! $info ) {
            return new WP_Error( 'read_failed', 'Source image could not be read.' );
        }
        $mime     = $info['mime'];
        $animated = 'image/gif' === $mime && self::is_animated_gif( $src );

        if ( $animated && empty( $caps[ $format ]['animated'] ) ) {
            return new WP_Error( 'animated', 'Animated GIF skipped: this server cannot write animated ' . strtoupper( $format ) . '.' );
        }

        $opts = wp_parse_args( $opts, array( 'quality' => 80, 'lossless' => false, 'smart' => false ) );
        $opts['lossless'] = $opts['lossless'] && ! empty( $caps[ $format ]['lossless'] );

        $use_imagick = ! empty( $caps[ $format ]['imagick'] ) && ( $animated || empty( $caps[ $format ]['gd'] ) || apply_filters( 'erdo_prefer_imagick', true ) );
        $tmp         = $dest . '.erdotmp';

        $result = $use_imagick
            ? self::encode_imagick( $src, $tmp, $format, $opts, $animated )
            : self::encode_gd( $src, $tmp, $format, $mime, $opts );

        clearstatcache( true, $tmp );
        if ( is_wp_error( $result ) || ! file_exists( $tmp ) || filesize( $tmp ) < 16 ) {
            if ( file_exists( $tmp ) ) {
                wp_delete_file( $tmp );
            }
            return is_wp_error( $result ) ? $result : new WP_Error( 'encode_failed', 'Encoder produced no output.' );
        }

        $head = file_get_contents( $tmp, false, null, 0, 32 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
        if ( self::sniff( $head ) !== $format ) {
            wp_delete_file( $tmp );
            return new WP_Error( 'encode_failed', 'Encoder output is not valid ' . strtoupper( $format ) . '.' );
        }

        if ( ! @rename( $tmp, $dest ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors
            wp_delete_file( $tmp );
            return new WP_Error( 'encode_failed', 'Could not write ' . basename( $dest ) );
        }

        clearstatcache( true, $dest );
        return (int) filesize( $dest );
    }

    private static function encode_gd( $src, $tmp, $format, $mime, array $opts ) {
        switch ( $mime ) {
            case 'image/jpeg':
                $image = @imagecreatefromjpeg( $src ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
                break;
            case 'image/png':
                $image = @imagecreatefrompng( $src ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
                if ( $image ) {
                    imagepalettetotruecolor( $image );
                    imagealphablending( $image, false );
                    imagesavealpha( $image, true );
                }
                break;
            case 'image/gif':
                $gif = @imagecreatefromgif( $src ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
                $image = false;
                if ( $gif ) {
                    // GIF'in şeffaflığını koru
                    $image = imagecreatetruecolor( imagesx( $gif ), imagesy( $gif ) );
                    imagealphablending( $image, false );
                    imagesavealpha( $image, true );
                    imagefill( $image, 0, 0, imagecolorallocatealpha( $image, 255, 255, 255, 127 ) );
                    imagealphablending( $image, true );
                    imagecopy( $image, $gif, 0, 0, 0, 0, imagesx( $gif ), imagesy( $gif ) );
                    imagealphablending( $image, false );
                    imagedestroy( $gif );
                }
                break;
            default:
                return new WP_Error( 'unsupported', 'Unsupported source type ' . $mime );
        }

        if ( ! $image ) {
            return new WP_Error( 'read_failed', 'Source image could not be decoded.' );
        }

        $quality = (int) $opts['quality'];
        if ( $opts['lossless'] ) {
            $quality = IMG_WEBP_LOSSLESS;
        } elseif ( $opts['smart'] ) {
            $quality = self::find_best_quality( $image, $format );
        }

        $ok = ( 'avif' === $format )
            ? imageavif( $image, $tmp, $quality )
            : imagewebp( $image, $tmp, $quality );

        imagedestroy( $image );
        return $ok ? true : new WP_Error( 'encode_failed', 'GD could not encode the image.' );
    }

    private static function encode_imagick( $src, $tmp, $format, array $opts, $animated ) {
        try {
            $im = new Imagick( $src );
            if ( $animated ) {
                $im = $im->coalesceImages();
            }

            // Renk profilini (ICC) koru, diğer metadata'yı (EXIF/GPS) at.
            $icc = null;
            try {
                $profiles = $im->getImageProfiles( 'icc', true );
                $icc      = $profiles['icc'] ?? null;
            } catch ( Exception $e ) {
                $icc = null;
            }

            foreach ( $im as $frame ) {
                $frame->setImageFormat( $format );
                $frame->stripImage();
                if ( $icc ) {
                    $frame->profileImage( 'icc', $icc );
                }
                if ( $opts['lossless'] ) {
                    $frame->setOption( 'webp:lossless', 'true' );
                } else {
                    $frame->setImageCompressionQuality( (int) $opts['quality'] );
                }
            }

            $ok = $animated ? $im->writeImages( $format . ':' . $tmp, true ) : $im->writeImage( $format . ':' . $tmp );
            $im->clear();
            return $ok ? true : new WP_Error( 'encode_failed', 'Imagick could not encode the image.' );
        } catch ( Exception $e ) {
            return new WP_Error( 'encode_failed', 'Imagick: ' . $e->getMessage() );
        }
    }

    /* ------------------------------------------------------------------
       SMART / ADAPTİF KALİTE
       Her görsel için en küçük kabul edilebilir kalite değerini bulur.
       Yaklaşım: 85→72→58 arasında 3 probe; boyuttaki azalma eşiğinin
       altına düşünce bir önceki değeri döndürür.
    ------------------------------------------------------------------ */

    public static function find_best_quality( $image_resource, $format ) {
        $probes        = array( 85, 72, 58 );
        $prev_size     = PHP_INT_MAX;
        $best_quality  = 80;
        $threshold_pct = 0.08; // %8'den az kazanç varsa daha düşük kaliteye gitme

        foreach ( $probes as $q ) {
            ob_start();
            if ( 'avif' === $format ) {
                imageavif( $image_resource, null, $q );
            } else {
                imagewebp( $image_resource, null, $q );
            }
            $size = strlen( ob_get_clean() );

            if ( $size <= 0 ) {
                break;
            }

            if ( PHP_INT_MAX !== $prev_size && ( $prev_size - $size ) / $prev_size < $threshold_pct ) {
                // Bu kalite artık anlamlı kazanç sağlamıyor; bir üst değeri kullan
                break;
            }

            $best_quality = $q;
            $prev_size    = $size;
        }

        return $best_quality;
    }
}
