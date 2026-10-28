<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * ERDO_Renamer — görsel dosyalarını güvenle yeniden adlandırır
 *
 * 2.x'in yeniden adlandırması küçük resimleri yeniden üretiyor, içeriği ham
 * SQL REPLACE() ile güncelliyordu: boyutlu URL'ler, büyük harfli uzantılar,
 * kaçışlı JSON ve serileştirilmiş meta bozuluyor ya da atlanıyordu. Burada:
 *   - ana dosya, tüm alt boyutlar, "-scaled" öncesi orijinal, düzenleme
 *     yedekleri ve WebP/AVIF kardeşleri yalnızca TAŞINIR (yeniden kodlama yok);
 *   - metadata ve _erdo_formats kaydı yeni adlarla güncellenir;
 *   - her referans ERDO_References ile (serileştirme güvenli) değiştirilir;
 *   - eski URL'ler 301 ile yenisine yönlenir (backlink ve Google Görseller korunur);
 *   - her işlem geri alınabilir (görsel başına günlük + toplu işlem kaydı).
 *
 * Dosya adı "kök" üzerinden eşlenir: "foto-scaled.jpg", "foto-300x200.jpg",
 * "foto.jpg.webp" hepsi "foto" köküyle başlar; kök değişir, sonek korunur.
 */
class ERDO_Renamer {

    const REDIRECTS_META  = '_erdo_redirects';   // eski göreli yol → yeni göreli yol
    const OLD_REL_META    = '_erdo_old_rel';     // eski göreli yol (küçük harf), 404'te tek sorguyla bulmak için
    const LOG_META        = '_erdo_rename_log';
    const BATCHES_OPTION  = 'erdo_rename_batches';
    const MAX_STEM        = 100;
    const MAX_BATCHES     = 10;
    const TOKENS          = array( '{keyword}', '{n}', '{parent}', '{title}', '{original}', '{alt}' );

    public static function init() {
        add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect' ), 1 );
    }

    /* ------------------------------------------------------------------
       AD ÜRETİMİ
    ------------------------------------------------------------------ */

    /** Kullanıcı girdisinden güvenli dosya adı kökü: küçük harf, tire, aksansız, uzantısız. */
    public static function sanitize_stem( $name ) {
        $name = preg_replace( '/\.(jpe?g|jpe|png|gif|webp|avif)$/i', '', trim( wp_basename( (string) $name ) ) );
        $name = sanitize_title( remove_accents( $name ) );
        $name = preg_replace( '/%[0-9a-f]{2}/i', '', $name ); // Latin dışı karakterlerin URL kodları
        $name = preg_replace( '/[^a-z0-9-]+/', '-', str_replace( '_', '-', $name ) );
        $name = trim( preg_replace( '/-+/', '-', $name ), '-' );
        return trim( substr( $name, 0, self::MAX_STEM ), '-' );
    }

    /**
     * Desenden ad üretir. {n} seçimdeki sıra (1'den başlar). {keyword} boşsa
     * üst yazının odak anahtar kelimesi, o da yoksa yazı başlığı kullanılır.
     */
    public static function pattern_stem( $id, $pattern, $n, array $vars = array() ) {
        $post   = get_post( $id );
        $parent = $post && $post->post_parent ? get_post( $post->post_parent ) : null;
        $kw     = trim( (string) ( $vars['keyword'] ?? '' ) );
        if ( '' === $kw && $parent ) {
            $kw = ERDO_SEO::focus_keyword( $parent->ID );
            $kw = '' !== $kw ? $kw : $parent->post_title;
        }
        $file = (string) get_attached_file( $id );
        $out  = str_replace( self::TOKENS, array(
            $kw,
            (string) (int) $n,
            $parent ? $parent->post_title : '',
            $post ? $post->post_title : '',
            ERDO_SEO::clean_stem( pathinfo( $file, PATHINFO_FILENAME ) ),
            (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
        ), (string) $pattern );
        return self::sanitize_stem( $out );
    }

    /**
     * "Fix Filename" önerisi. Anlamsız bir ad (IMG_1234) temizlenince yine anlamsız kalır
     * (img-1234); bu yüzden sırayla bekleyen AI önerisi, geçerli alt metin, başlık ve
     * görselin kullanıldığı sayfanın odak kelimesi / başlığı denenir. Ad zaten anlamlıysa
     * ya da hiçbir aday yoksa null döner (mevcut ad yalnızca temizlenir).
     */
    public static function suggest_stem( $id ) {
        $id   = (int) $id;
        $file = (string) get_attached_file( $id );
        $root = self::root_stem( $file );
        if ( '' === $file || ! ERDO_SEO::is_meaningless_name( $root ) ) {
            return null;
        }

        $candidates = array();
        $ai         = class_exists( 'ERDO_AI' ) ? ERDO_AI::get( $id ) : null;
        if ( $ai ) {
            $candidates[] = (string) ( $ai['filename'] ?? '' );
        }
        $alt = trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) );
        if ( '' !== $alt && ERDO_SEO::check_alt( $alt, $root )['pass'] ) {
            $candidates[] = wp_trim_words( $alt, 6, '' );
        }
        $post = get_post( $id );
        if ( $post ) {
            $candidates[] = $post->post_title;
        }
        $page = ERDO_Usage::primary_page( $id );
        if ( $page ) {
            $candidates[] = ERDO_SEO::focus_keyword( $page->ID );
            $candidates[] = $page->post_title;
        }

        foreach ( $candidates as $c ) {
            $stem = self::sanitize_stem( (string) $c );
            if ( '' !== $stem && ! ERDO_SEO::is_meaningless_name( $stem ) ) {
                return $stem;
            }
        }
        return null;
    }

    /* ------------------------------------------------------------------
       PLAN (dry-run) — hiçbir şeyi değiştirmez
    ------------------------------------------------------------------ */

    /**
     * $new_name null → mevcut adı temizle ("Fix Filename").
     * $opts: append_original (bool), reserved (array, aynı partide ayrılmış yollar — referansla güncellenir).
     * Döner: array( id, old_stem, new_stem, files => old_abs => new_abs, main_old, main_new ) | WP_Error
     */
    public static function plan( $id, $new_name = null, array $opts = array(), array &$reserved = array() ) {
        $id   = (int) $id;
        $post = get_post( $id );
        if ( ! $post || 'attachment' !== $post->post_type ) {
            return new WP_Error( 'not_found', __( 'Attachment not found.', 'erdo-image-optimizer' ) );
        }
        if ( class_exists( 'ERDO_Quarantine' ) && ERDO_Quarantine::STATUS === $post->post_status ) {
            return new WP_Error( 'quarantined', __( 'Restore the image from quarantine before renaming it.', 'erdo-image-optimizer' ) );
        }
        $file = get_attached_file( $id );
        if ( ! $file || ! file_exists( $file ) ) {
            return new WP_Error( 'not_found', __( 'File not found.', 'erdo-image-optimizer' ) );
        }
        if ( ! ERDO_References::in_uploads( $file ) ) {
            return new WP_Error( 'outside_uploads', __( 'Only files inside the uploads folder can be renamed.', 'erdo-image-optimizer' ) );
        }

        $dir  = dirname( $file );
        $root = self::root_stem( $file );
        $old_ext = pathinfo( $file, PATHINFO_EXTENSION );

        if ( ! empty( $opts['exact'] ) && null !== $new_name ) {
            // Geri alma: kayıttaki eski ad olduğu gibi (ör. "IMG_1234"); yalnızca yol
            // ayırıcıları ve gizli dosya adları reddedilir. Temizlenirse eski ad geri gelmez.
            $stem = trim( (string) $new_name );
            if ( $stem !== wp_basename( $stem ) || false !== strpbrk( $stem, '/\:*?"<>|' ) || '.' === substr( $stem, 0, 1 ) || strlen( $stem ) > 200 ) {
                $stem = '';
            }
        } else {
            $stem = self::sanitize_stem( null === $new_name ? $root : $new_name );
        }
        if ( '' === $stem ) {
            return new WP_Error( 'invalid_name', __( 'The new filename is empty after removing unsupported characters.', 'erdo-image-optimizer' ) );
        }
        if ( null !== $new_name && ! empty( $opts['append_original'] ) ) {
            $orig = self::sanitize_stem( $root );
            $stem = substr( $stem . '-' . $orig, 0, self::MAX_STEM );
        }
        if ( $stem === $root && strtolower( $old_ext ) === $old_ext ) {
            return new WP_Error( 'clean', __( 'Filename already clean.', 'erdo-image-optimizer' ) );
        }

        $old_files = self::files( $id, $file );
        $own       = array_map( 'strtolower', array_map( 'wp_normalize_path', $old_files ) );

        // Çakışma: hedef yollardan biri başka bir dosyaya aitse ya da bu partide ayrıldıysa sonek ekle.
        $candidate = $stem;
        for ( $i = 2; $i <= 100; $i++ ) {
            $map = self::map_files( $old_files, $root, $candidate );
            if ( ! self::collides( $map, $own, $reserved ) ) {
                break;
            }
            $candidate = substr( $stem, 0, self::MAX_STEM - 4 ) . '-' . $i;
            $map       = null;
        }
        if ( ! $map ) {
            return new WP_Error( 'collision', __( 'Could not find a free filename.', 'erdo-image-optimizer' ) );
        }
        foreach ( $map as $new ) {
            $reserved[ strtolower( wp_normalize_path( $new ) ) ] = true;
        }

        return array(
            'id'       => $id,
            'old_stem' => $root,
            'new_stem' => $candidate,
            'files'    => $map,
            'main_old' => $file,
            'main_new' => $map[ $file ] ?? $dir . '/' . $candidate . '.' . strtolower( $old_ext ),
        );
    }

    /** "foto-scaled.jpg" → "foto"; düzenleme soneki (-e123…) kökün parçası sayılır. */
    private static function root_stem( $file ) {
        return preg_replace( '/-(scaled|rotated)$/i', '', pathinfo( $file, PATHINFO_FILENAME ) );
    }

    /** Görsele ait diskteki tüm dosyalar: ana, alt boyutlar, orijinal, düzenleme yedekleri, kardeşler. */
    private static function files( $id, $file ) {
        $dir   = dirname( $file );
        $meta  = wp_get_attachment_metadata( $id );
        $names = array( wp_basename( $file ) );
        foreach ( (array) ( $meta['sizes'] ?? array() ) as $s ) {
            if ( ! empty( $s['file'] ) ) {
                $names[] = wp_basename( $s['file'] );
            }
        }
        if ( ! empty( $meta['original_image'] ) ) {
            $names[] = wp_basename( $meta['original_image'] );
        }
        foreach ( (array) get_post_meta( $id, '_wp_attachment_backup_sizes', true ) as $s ) {
            if ( ! empty( $s['file'] ) ) {
                $names[] = wp_basename( $s['file'] );
            }
        }
        $out = array();
        foreach ( array_unique( $names ) as $name ) {
            foreach ( array( '', '.webp', '.avif' ) as $sib ) {
                if ( file_exists( "$dir/$name$sib" ) ) {
                    $out[] = "$dir/$name$sib";
                }
            }
        }
        return $out;
    }

    /** old_abs → new_abs; kökle başlamayan dosyalar (ör. başka adla kalmış yedek) dokunulmadan bırakılır. */
    private static function map_files( array $files, $root, $stem ) {
        $map = array();
        foreach ( $files as $f ) {
            $base = wp_basename( $f );
            if ( 0 !== stripos( $base, $root ) ) {
                continue;
            }
            // "foto-300x200.JPG.webp" → sonek "-300x200", uzantı "JPG", kardeş ".webp"
            if ( ! preg_match( '/^(.*?)\.([a-z0-9]+)((?:\.(?:webp|avif))?)$/i', substr( $base, strlen( $root ) ), $m ) ) {
                continue;
            }
            $map[ $f ] = dirname( $f ) . '/' . $stem . $m[1] . '.' . strtolower( $m[2] ) . strtolower( $m[3] );
        }
        return $map;
    }

    private static function collides( array $map, array $own, array $reserved ) {
        foreach ( $map as $new ) {
            $key = strtolower( wp_normalize_path( $new ) );
            if ( isset( $reserved[ $key ] ) ) {
                return true;
            }
            if ( file_exists( $new ) && ! in_array( $key, $own, true ) ) {
                return true;
            }
        }
        return false;
    }

    /* ------------------------------------------------------------------
       UYGULA
    ------------------------------------------------------------------ */

    /**
     * Tek görseli yeniden adlandırır.
     * Döner: array( id, old_stem, new_stem, files => n, refs => n ) | WP_Error
     */
    public static function rename( $id, $new_name = null, array $opts = array() ) {
        $reserved = array();
        $plan     = self::plan( $id, $new_name, $opts, $reserved );
        if ( is_wp_error( $plan ) ) {
            return $plan;
        }
        return self::apply( $plan, $opts );
    }

    private static function apply( array $plan, array $opts = array() ) {
        $id = $plan['id'];

        // 1. Dosyaları taşı; biri başarısız olursa taşınanları geri al.
        $moved = array();
        foreach ( $plan['files'] as $old => $new ) {
            if ( $old === $new ) {
                continue;
            }
            if ( ! self::move( $old, $new ) ) {
                foreach ( array_reverse( $moved, true ) as $o => $n ) {
                    self::move( $n, $o );
                }
                return new WP_Error( 'move_failed', __( 'Could not rename the file on disk.', 'erdo-image-optimizer' ) );
            }
            $moved[ $old ] = $new;
        }

        // 2. Attachment kayıtları: dosya yolu, metadata, düzenleme yedekleri, kardeş kaydı.
        $by_base = array();
        foreach ( $plan['files'] as $old => $new ) {
            $by_base[ wp_basename( $old ) ] = wp_basename( $new );
        }
        update_attached_file( $id, $plan['main_new'] );

        $meta = wp_get_attachment_metadata( $id );
        if ( is_array( $meta ) ) {
            if ( ! empty( $meta['file'] ) ) {
                $meta['file'] = _wp_relative_upload_path( $plan['main_new'] );
            }
            foreach ( (array) ( $meta['sizes'] ?? array() ) as $k => $s ) {
                if ( isset( $s['file'], $by_base[ $s['file'] ] ) ) {
                    $meta['sizes'][ $k ]['file'] = $by_base[ $s['file'] ];
                }
            }
            if ( ! empty( $meta['original_image'] ) && isset( $by_base[ $meta['original_image'] ] ) ) {
                $meta['original_image'] = $by_base[ $meta['original_image'] ];
            }
            wp_update_attachment_metadata( $id, $meta );
        }

        $backup = get_post_meta( $id, '_wp_attachment_backup_sizes', true );
        if ( is_array( $backup ) ) {
            foreach ( $backup as $k => $s ) {
                if ( isset( $s['file'], $by_base[ $s['file'] ] ) ) {
                    $backup[ $k ]['file'] = $by_base[ $s['file'] ];
                }
            }
            update_post_meta( $id, '_wp_attachment_backup_sizes', $backup );
        }

        $record = get_post_meta( $id, ERDO_Converter::META, true );
        if ( is_array( $record ) ) {
            foreach ( array( 'formats', 'larger' ) as $part ) {
                foreach ( (array) ( $record[ $part ] ?? array() ) as $fmt => $list ) {
                    $renamed = array();
                    foreach ( (array) $list as $k => $v ) {
                        if ( 'formats' === $part ) {
                            $renamed[ $by_base[ $k ] ?? $k ] = $v;
                        } else {
                            $renamed[] = $by_base[ $v ] ?? $v;
                        }
                    }
                    $record[ $part ][ $fmt ] = $renamed;
                }
            }
            update_post_meta( $id, ERDO_Converter::META, $record );
        }

        // guid ve başlık (başlık yalnızca eski dosya adının aynısıysa)
        global $wpdb;
        $upload = wp_upload_dir();
        $new_url = trailingslashit( $upload['baseurl'] ) . _wp_relative_upload_path( $plan['main_new'] );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update( $wpdb->posts, array( 'guid' => $new_url ), array( 'ID' => $id ) );
        $post = get_post( $id );
        if ( $post && in_array( $post->post_title, array( $plan['old_stem'], pathinfo( $plan['main_old'], PATHINFO_FILENAME ) ), true ) ) {
            wp_update_post( array( 'ID' => $id, 'post_title' => $plan['new_stem'] ) );
        }
        clean_post_cache( $id );

        // 3. Referanslar — göreli yol eşlemesi; tam eşleşme yoksa büyük/küçük harf duyarsız.
        $rel_map = array();
        foreach ( $plan['files'] as $old => $new ) {
            $rel_map[ self::rel( $old ) ] = self::rel( $new );
        }
        $lower = array_change_key_case( $rel_map, CASE_LOWER );
        $refs  = ERDO_References::replace( function ( $rel ) use ( $rel_map, $lower ) {
            $rel = rawurldecode( $rel );
            return $rel_map[ $rel ] ?? ( $lower[ strtolower( $rel ) ] ?? null );
        }, $plan['old_stem'] );

        // 4. 301 haritası ve geri alma günlüğü
        self::update_redirects( $id, $rel_map );
        if ( empty( $opts['no_log'] ) ) {
            $log   = (array) get_post_meta( $id, self::LOG_META, true );
            $log   = array_filter( $log, 'is_array' );
            $log[] = array( 'time' => time(), 'from' => $plan['old_stem'], 'to' => $plan['new_stem'] );
            update_post_meta( $id, self::LOG_META, array_slice( $log, -10 ) );
        }

        ERDO_Auditor::clear_cache( $id );
        update_option( 'erdo_library_version', (int) get_option( 'erdo_library_version', 0 ) + 1 );

        return array(
            'id'       => $id,
            'old_stem' => $plan['old_stem'],
            'new_stem' => $plan['new_stem'],
            'files'    => count( $moved ),
            'refs'     => array_sum( $refs ),
        );
    }

    private static function move( $from, $to ) {
        if ( ! file_exists( $from ) ) {
            return true; // eksik alt boyut: taşınacak bir şey yok
        }
        // Yalnızca büyük/küçük harf değişiyorsa (IMG.JPG → img.jpg) Windows/macOS'ta ara adla taşı.
        if ( 0 === strcasecmp( $from, $to ) ) {
            $tmp = $from . '.erdo-tmp';
            return @rename( $from, $tmp ) && @rename( $tmp, $to ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged
        }
        return @rename( $from, $to ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged
    }

    private static function rel( $abs ) {
        $base = wp_normalize_path( trailingslashit( wp_upload_dir()['basedir'] ) );
        return ltrim( str_replace( $base, '', wp_normalize_path( $abs ) ), '/' );
    }

    /* ------------------------------------------------------------------
       TOPLU İŞLEM
    ------------------------------------------------------------------ */

    /**
     * Önizleme: array( array( id, old, new, error ) ). $pattern null → "Fix Filename".
     * $only_changes: değişmeyecek olanları (zaten temiz) listeden çıkarır.
     */
    public static function preview( array $ids, $pattern = null, array $vars = array(), $only_changes = true ) {
        $rows     = array();
        $reserved = array();
        $n        = 0;
        foreach ( array_map( 'intval', $ids ) as $id ) {
            $n++;
            $name = null === $pattern ? self::suggest_stem( $id ) : self::pattern_stem( $id, $pattern, $n, $vars );
            $plan = self::plan( $id, $name, array(), $reserved );
            $file = (string) get_attached_file( $id );
            if ( is_wp_error( $plan ) ) {
                if ( $only_changes && 'clean' === $plan->get_error_code() ) {
                    continue;
                }
                $rows[] = array( 'id' => $id, 'old' => '' !== $file ? wp_basename( $file ) : '#' . $id, 'new' => '', 'error' => $plan->get_error_message() );
                continue;
            }
            $rows[] = array( 'id' => $id, 'old' => wp_basename( $file ), 'new' => wp_basename( $plan['main_new'] ), 'stem' => $plan['new_stem'], 'error' => '' );
        }
        return $rows;
    }

    /** Desenle toplu yeniden adlandırma. Döner: array( id => yeni dosya adı | WP_Error ) */
    public static function rename_pattern( array $ids, $pattern, array $vars = array() ) {
        $items = array();
        $n     = 0;
        foreach ( array_map( 'intval', $ids ) as $id ) {
            $items[ $id ] = self::pattern_stem( $id, $pattern, ++$n, $vars );
        }
        $out = array();
        foreach ( self::apply_items( $items, self::new_batch_id() ) as $id => $r ) {
            $out[ $id ] = is_wp_error( $r ) ? $r : wp_basename( get_attached_file( $id ) );
        }
        return $out;
    }

    /**
     * array( id => kök | null ) uygular ve toplu işlem kaydına ekler (aynı $batch_id
     * ile parça parça çağrılabilir). null = mevcut adı temizle.
     */
    public static function apply_items( array $items, $batch_id ) {
        $out   = array();
        $undo  = array();
        foreach ( $items as $id => $stem ) {
            $r          = self::rename( (int) $id, null === $stem || '' === $stem ? null : (string) $stem );
            $out[ $id ] = $r;
            if ( ! is_wp_error( $r ) ) {
                $undo[ (int) $id ] = $r['old_stem'];
            }
        }
        if ( $undo ) {
            $batches = (array) get_option( self::BATCHES_OPTION, array() );
            $batch   = $batches[ $batch_id ] ?? array( 'time' => time(), 'items' => array() );
            $batch['items'] = $batch['items'] + $undo;
            unset( $batches[ $batch_id ] );
            $batches[ $batch_id ] = $batch;
            update_option( self::BATCHES_OPTION, array_slice( $batches, -self::MAX_BATCHES, null, true ), false );
            ERDO_Activity::log( 'rename', count( $undo ), array( 'batch' => $batch_id ) );
        }
        return $out;
    }

    public static function batch_exists( $batch_id ) {
        $batches = (array) get_option( self::BATCHES_OPTION, array() );
        return ! empty( $batches[ $batch_id ]['items'] );
    }

    public static function new_batch_id() {
        return 'b' . time() . wp_rand( 100, 999 );
    }

    /** Toplu işlemi geri alır: her görsel eski adına döner. Döner: array( 'restored' => n, 'errors' => n ) */
    public static function undo_batch( $batch_id ) {
        $batches = (array) get_option( self::BATCHES_OPTION, array() );
        if ( empty( $batches[ $batch_id ]['items'] ) ) {
            return new WP_Error( 'no_batch', __( 'Nothing to undo.', 'erdo-image-optimizer' ) );
        }
        $done = 0;
        $errs = 0;
        foreach ( $batches[ $batch_id ]['items'] as $id => $old_stem ) {
            $r = self::rename( (int) $id, $old_stem, array( 'exact' => true ) );
            is_wp_error( $r ) && 'clean' !== $r->get_error_code() ? $errs++ : $done++;
        }
        unset( $batches[ $batch_id ] );
        update_option( self::BATCHES_OPTION, $batches, false );
        return array( 'restored' => $done, 'errors' => $errs );
    }

    /** Görselin son yeniden adlandırmasını geri alır. */
    public static function undo_last( $id ) {
        $log  = array_filter( (array) get_post_meta( $id, self::LOG_META, true ), 'is_array' );
        $last = end( $log );
        if ( ! $last ) {
            return new WP_Error( 'no_log', __( 'Nothing to undo.', 'erdo-image-optimizer' ) );
        }
        $r = self::rename( $id, $last['from'], array( 'no_log' => true, 'exact' => true ) );
        if ( ! is_wp_error( $r ) ) {
            array_pop( $log );
            update_post_meta( $id, self::LOG_META, array_values( $log ) );
        }
        return $r;
    }

    /** Detay paneli için: son yeniden adlandırma ya da null. */
    public static function last_rename( $id ) {
        $log  = array_filter( (array) get_post_meta( $id, self::LOG_META, true ), 'is_array' );
        $last = end( $log );
        return $last ? $last : null;
    }

    /* ------------------------------------------------------------------
       301 YÖNLENDİRMELERİ
    ------------------------------------------------------------------ */

    private static function update_redirects( $id, array $rel_map ) {
        $lower = array_change_key_case( $rel_map, CASE_LOWER );
        $map   = get_post_meta( $id, self::REDIRECTS_META, true );
        $map   = is_array( $map ) ? $map : array();

        // Zincir: A→B varken B→C olursa A→C.
        foreach ( $map as $old => $target ) {
            $map[ $old ] = $rel_map[ $target ] ?? ( $lower[ strtolower( $target ) ] ?? $target );
        }
        $map = array_merge( $map, $rel_map );

        // Yeniden var olan yollar (geri alma) ve kendine yönlenenler çıkarılır.
        $live = array_flip( array_map( 'strtolower', array_values( $rel_map ) ) );
        foreach ( $map as $old => $target ) {
            if ( 0 === strcasecmp( $old, $target ) || isset( $live[ strtolower( $old ) ] ) ) {
                unset( $map[ $old ] );
            }
        }

        delete_post_meta( $id, self::OLD_REL_META );
        if ( ! $map ) {
            delete_post_meta( $id, self::REDIRECTS_META );
            return;
        }
        update_post_meta( $id, self::REDIRECTS_META, $map );
        foreach ( array_unique( array_map( 'strtolower', array_keys( $map ) ) ) as $old ) {
            add_post_meta( $id, self::OLD_REL_META, $old );
        }
        ERDO_WebP_Server::ensure_redirect_fallback();
    }

    /** Yeniden adlandırılmış bir görselin eski URL'si istenirse 301 ile yenisine gönder. */
    public static function maybe_redirect() {
        if ( ! is_404() || empty( $_SERVER['REQUEST_URI'] ) ) {
            return;
        }
        $path   = rawurldecode( (string) wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) );
        $prefix = ERDO_References::uploads_path();
        if ( 0 !== strpos( $path, $prefix ) || ! preg_match( '/\.(jpe?g|png|gif|webp|avif)$/i', $path ) ) {
            return;
        }
        $target = self::redirect_target( substr( $path, strlen( $prefix ) ) );
        if ( ! $target ) {
            return;
        }
        $url  = trailingslashit( wp_upload_dir()['baseurl'] ) . $target;
        $host = wp_parse_url( $url, PHP_URL_HOST );
        add_filter( 'allowed_redirect_hosts', function ( $hosts ) use ( $host ) {
            $hosts[] = $host;
            return $hosts;
        } );
        wp_safe_redirect( $url, 301, 'Erdo Image Optimizer' );
        exit;
    }

    /** Eski göreli yol için güncel göreli yol (dosya diskte varsa) ya da null. */
    public static function redirect_target( $rel ) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        $id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1", self::OLD_REL_META, strtolower( $rel ) ) );
        if ( ! $id ) {
            return null;
        }
        $map    = (array) get_post_meta( $id, self::REDIRECTS_META, true );
        $target = $map[ $rel ] ?? ( array_change_key_case( $map, CASE_LOWER )[ strtolower( $rel ) ] ?? null );
        return $target && file_exists( ERDO_References::rel_to_path( $target ) ) ? $target : null;
    }
}
