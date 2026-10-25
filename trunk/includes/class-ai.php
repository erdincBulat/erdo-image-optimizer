<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * ERDO_AI — görsel için alt metin / dosya adı / başlık / altyazı önerisi
 *
 * WordPress 7.0'ın çekirdek AI Client'ını (wp_ai_client_prompt) kullanır.
 * API anahtarı eklentide saklanmaz: kullanıcı sağlayıcıyı Settings > Connectors
 * ekranında bağlar. Öneriler hiçbir zaman doğrudan yazılmaz; `_erdo_ai_suggestion`
 * meta'sına kaydedilir, kullanıcı inceleyip kabul eder (accept) ya da reddeder.
 *
 * Maliyet: görsel en fazla 1024 px'e küçültülmüş JPEG/PNG/WebP olarak gider
 * (AVIF/HEIC önce JPEG'e çevrilir — Claude AVIF kabul etmez), yanıt yapılandırılmış
 * JSON'dur, önce ucuz görsel modeller tercih edilir.
 */
class ERDO_AI {

    const META       = '_erdo_ai_suggestion';
    const CRON_HOOK  = 'erdo_ai_suggest';
    const MAX_SIDE   = 1024;
    const ALT_MAX    = 125;
    const STATUS_KEY = 'erdo_ai_status';
    /** Yanıt token sınırı; ikincisi yalnızca ilk yanıt kesilirse (finish = length) denenir. */
    const MAX_TOKENS = array( 2048, 4096 );

    /** Görsel girdiyi destekleyen ucuz modeller, sırayla (sağlayıcı bağlı değilse atlanır). */
    const MODEL_PREFERENCE = array( 'claude-haiku-4-5', 'gemini-2.5-flash', 'gpt-5-mini', 'gpt-4.1-mini' );

    public static function init() {
        add_action( self::CRON_HOOK, array( __CLASS__, 'cron_suggest' ) );
        add_action( 'add_attachment', array( __CLASS__, 'on_upload' ) );
    }

    /* ------------------------------------------------------------------
       KULLANILABİLİRLİK
    ------------------------------------------------------------------ */

    /**
     * array( 'ready' => bool, 'reason' => 'wp'|'disabled'|'provider'|'', 'message' => string, 'setup_url' => string )
     * Sağlayıcı kontrolü maliyetsizdir (istek atılmaz) ama yine de 10 dk önbelleğe alınır.
     */
    public static function status( $fresh = false ) {
        $setup = admin_url( 'options-connectors.php' );
        if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
            $s = array( 'ready' => false, 'reason' => 'wp', 'message' => __( 'AI suggestions need WordPress 7.0 or newer.', 'erdo-image-optimizer' ) );
        } elseif ( function_exists( 'wp_supports_ai' ) && ! wp_supports_ai() ) {
            $s = array( 'ready' => false, 'reason' => 'disabled', 'message' => __( 'AI features are turned off on this site.', 'erdo-image-optimizer' ) );
        } else {
            $cached = $fresh ? false : get_transient( self::STATUS_KEY );
            if ( is_array( $cached ) ) {
                $s = $cached;
            } else {
                $ok = false;
                try {
                    $probe = self::builder( 'Describe this image.', array( 'data' => self::PROBE_PNG, 'mime' => 'image/png' ) );
                    $ok    = true === $probe->is_supported_for_text_generation();
                } catch ( Throwable $e ) {
                    $ok = false;
                }
                $s = $ok
                    ? array( 'ready' => true, 'reason' => '', 'message' => '' )
                    : array( 'ready' => false, 'reason' => 'provider', 'message' => __( 'Connect an AI provider that can read images (Anthropic, Google or OpenAI) under Settings > Connectors.', 'erdo-image-optimizer' ) );
                set_transient( self::STATUS_KEY, $s, 10 * MINUTE_IN_SECONDS );
            }
        }
        $s['setup_url'] = $setup;
        /**
         * Filters whether AI suggestions are available (tests and custom integrations).
         *
         * @param array $s Status array.
         */
        return apply_filters( 'erdo_ai_status', $s );
    }

    public static function is_ready() {
        return ! empty( self::status()['ready'] );
    }

    /** 1x1 şeffaf PNG — yalnızca sağlayıcının görsel girdi desteğini sormak için (istek atılmaz). */
    const PROBE_PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    /* ------------------------------------------------------------------
       ÖNERİ ÜRET
    ------------------------------------------------------------------ */

    /**
     * Görsele bakıp öneri üretir ve kaydeder (uygulamaz).
     * Döner: öneri dizisi | WP_Error
     */
    public static function suggest( $id ) {
        $id   = (int) $id;
        $post = get_post( $id );
        if ( ! $post || 'attachment' !== $post->post_type || ! wp_attachment_is_image( $id ) ) {
            return new WP_Error( 'not_image', __( 'Not an image.', 'erdo-image-optimizer' ) );
        }
        $status = self::status();
        if ( empty( $status['ready'] ) ) {
            return new WP_Error( 'ai_unavailable', $status['message'] );
        }

        $image = self::image_payload( $id );
        if ( is_wp_error( $image ) ) {
            return $image;
        }
        $ctx    = self::context( $id );
        $prompt = self::prompt_text( $ctx );

        /**
         * Short-circuits the provider call. Return the raw JSON string the model
         * would have produced (tests, or a custom AI backend).
         *
         * @param string|null $raw    Null to call the provider.
         * @param array       $request array( 'system', 'prompt', 'image', 'context', 'schema' ).
         */
        $raw = apply_filters( 'erdo_ai_pre_generate', null, array(
            'system'  => self::system_instruction( $ctx ),
            'prompt'  => $prompt,
            'image'   => $image,
            'context' => $ctx,
            'schema'  => self::schema(),
        ) );

        $model = '';
        if ( null === $raw ) {
            // Düşünen modeller (Gemini 2.5, o-serisi) düşünme tokenlarını da bu sınırdan harcar;
            // 600'de JSON yarıda kesiliyordu. Yalnızca kullanılan token ücretlenir. Yine de
            // kesilirse (finish = length) bir kez daha geniş sınırla denenir.
            foreach ( self::MAX_TOKENS as $max_tokens ) {
                try {
                    $builder = self::builder( $prompt, $image )
                        ->using_system_instruction( self::system_instruction( $ctx ) )
                        ->using_temperature( 0.2 )
                        ->using_max_tokens( $max_tokens )
                        ->as_json_response( self::schema() );
                    $result = $builder->generate_text_result();
                } catch ( Throwable $e ) {
                    return new WP_Error( 'ai_failed', $e->getMessage() );
                }
                if ( is_wp_error( $result ) ) {
                    return self::friendly_error( $result );
                }
                $candidates = $result->getCandidates();
                if ( ! $candidates || ! $candidates[0]->getFinishReason()->isLength() ) {
                    break;
                }
            }
            $raw   = $result->toText();
            $model = $result->getModelMetadata()->getId();
        }

        $data = self::parse( (string) $raw, $ctx );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        $suggestion = array_merge( $data, array(
            'time'        => time(),
            'model'       => $model,
            'current_alt' => $ctx['current_alt'],
        ) );
        update_post_meta( $id, self::META, $suggestion );
        return $suggestion;
    }

    private static function builder( $prompt, array $image ) {
        /**
         * Filters the preferred models (cheapest capable first).
         *
         * @param string[] $models Model IDs.
         */
        $models  = (array) apply_filters( 'erdo_ai_model_preference', self::MODEL_PREFERENCE );
        $builder = wp_ai_client_prompt( $prompt )->with_file( $image['data'], $image['mime'] );
        if ( $models ) {
            $builder = $builder->using_model_preference( ...array_values( $models ) );
        }
        return $builder;
    }

    /** Sağlayıcı hatalarını kullanıcıya anlaşılır biçimde döndürür (429 → yeniden dene). */
    private static function friendly_error( WP_Error $e ) {
        $msg = $e->get_error_message();
        if ( preg_match( '/\b429\b|rate.?limit|overloaded|529/i', $msg ) ) {
            return new WP_Error( 'ai_rate_limited', __( 'The AI provider is busy (rate limit). Waiting and retrying…', 'erdo-image-optimizer' ), array( 'retry' => true ) );
        }
        return new WP_Error( 'ai_failed', $msg );
    }

    /** JSON şeması — tüm sağlayıcıların yapılandırılmış çıktısıyla uyumlu, düz nesne. */
    public static function schema() {
        $str = array( 'type' => 'string' );
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'decorative'        => array( 'type' => 'boolean' ),
                'alt'               => $str,
                'filename'          => $str,
                'title'             => $str,
                'caption'           => $str,
                'current_alt_ok'    => array( 'type' => 'boolean' ),
                'current_alt_issue' => $str,
            ),
            'required'             => array( 'decorative', 'alt', 'filename', 'title', 'caption', 'current_alt_ok', 'current_alt_issue' ),
            'additionalProperties' => false,
        );
    }

    /** W3C alt metin karar ağacı + Google Görseller rehberi, kısaltılmış. */
    public static function system_instruction( array $ctx ) {
        return implode( "\n", array(
            'You write accessible, search-friendly image metadata for a WordPress site.',
            'Write every text field in this language: ' . $ctx['language'] . '.',
            'alt: one natural sentence or phrase describing what the image shows and why it matters on the page, at most ' . self::ALT_MAX . ' characters. Do not start with "image of", "picture of", "photo of". No keyword lists.',
            'Use the focus keyword only if it truly describes the image. Never guess or name real people; describe them generically (for example "a woman in a red coat").',
            'If text in the image carries the meaning (logo, sign, chart title), include that text.',
            'decorative: true only if the image adds no information (spacer, background texture, purely ornamental divider). Then alt must be an empty string.',
            'filename: 2-6 lowercase descriptive words joined by hyphens, ASCII only, no extension.',
            'title: a short human-readable title (2-8 words). caption: one short sentence suitable under the image, or an empty string if a caption would add nothing.',
            'current_alt_ok: false if the existing alt text is missing, wrong for this image, a filename, or keyword stuffing; current_alt_issue: one short sentence explaining why (empty if ok).',
            'Answer with JSON only.',
        ) );
    }

    private static function prompt_text( array $ctx ) {
        $lines = array( 'Describe this image for the page it appears on.' );
        foreach ( array(
            'page_title'    => 'Page title',
            'focus_keyword' => 'Focus keyword',
            'product'       => 'Product',
            'categories'    => 'Categories',
            'nearby_text'   => 'Text around the image',
            'current_alt'   => 'Current alt text',
            'current_title' => 'Current image title',
        ) as $key => $label ) {
            if ( '' !== (string) ( $ctx[ $key ] ?? '' ) ) {
                $lines[] = $label . ': ' . $ctx[ $key ];
            }
        }
        return implode( "\n", $lines );
    }

    /** Model çıktısını doğrular ve temizler. */
    public static function parse( $raw, array $ctx = array() ) {
        $raw  = trim( preg_replace( '/^```(?:json)?\s*|\s*```$/', '', trim( $raw ) ) );
        $data = json_decode( $raw, true );
        if ( ! is_array( $data ) || ! isset( $data['alt'] ) ) {
            return new WP_Error( 'ai_bad_response', __( 'The AI answer could not be read. Please try again.', 'erdo-image-optimizer' ) );
        }
        $decorative = ! empty( $data['decorative'] );
        $alt        = $decorative ? '' : self::clean_text( $data['alt'] ?? '', self::ALT_MAX + 25 );
        if ( ! $decorative && '' === $alt ) {
            return new WP_Error( 'ai_bad_response', __( 'The AI answer had no alt text. Please try again.', 'erdo-image-optimizer' ) );
        }
        $has_alt = '' !== (string) ( $ctx['current_alt'] ?? '' );
        return array(
            'decorative'        => $decorative,
            'alt'               => $alt,
            'filename'          => ERDO_Renamer::sanitize_stem( (string) ( $data['filename'] ?? '' ) ),
            'title'             => self::clean_text( $data['title'] ?? '', 120 ),
            'caption'           => self::clean_text( $data['caption'] ?? '', 300 ),
            'current_alt_ok'    => $has_alt ? ! empty( $data['current_alt_ok'] ) : null,
            'current_alt_issue' => $has_alt && empty( $data['current_alt_ok'] ) ? self::clean_text( $data['current_alt_issue'] ?? '', 200 ) : '',
        );
    }

    private static function clean_text( $text, $max ) {
        $text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $text ) ) );
        $text = trim( $text, " \t\"'" );
        return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max ) : substr( $text, 0, $max );
    }

    /* ------------------------------------------------------------------
       GÖRSEL VE BAĞLAM
    ------------------------------------------------------------------ */

    /**
     * Gönderilecek görsel: ≤1024 px. Uygun bir alt boyut varsa o, yoksa geçici
     * JPEG'e küçültülür. array( 'data' => data URI, 'mime', 'width', 'height', 'bytes' ) | WP_Error
     */
    public static function image_payload( $id ) {
        $file = get_attached_file( $id );
        if ( ! $file || ! file_exists( $file ) ) {
            return new WP_Error( 'not_found', __( 'File not found.', 'erdo-image-optimizer' ) );
        }
        $ok_mimes = array( 'image/jpeg', 'image/png', 'image/webp' );
        $meta     = wp_get_attachment_metadata( $id );
        $dir      = dirname( $file );

        // Önce ana dosya (zaten küçükse), sonra uzun kenarı 512–1024 px arasındaki en büyük alt boyut.
        // 150 px küçük resim modele yetmez; öyleyse aşağıda yeniden küçültülür.
        $full = array( 'path' => $file, 'w' => (int) ( $meta['width'] ?? 0 ), 'h' => (int) ( $meta['height'] ?? 0 ), 'mime' => get_post_mime_type( $id ) );
        $best = null;
        if ( in_array( $full['mime'], $ok_mimes, true ) && $full['w'] && max( $full['w'], $full['h'] ) <= self::MAX_SIDE ) {
            $best = $full;
        } else {
            foreach ( (array) ( $meta['sizes'] ?? array() ) as $s ) {
                $c    = array( 'path' => "$dir/" . ( $s['file'] ?? '' ), 'w' => (int) ( $s['width'] ?? 0 ), 'h' => (int) ( $s['height'] ?? 0 ), 'mime' => $s['mime-type'] ?? '' );
                $long = max( $c['w'], $c['h'] );
                if ( in_array( $c['mime'], $ok_mimes, true ) && $long >= 512 && $long <= self::MAX_SIDE && file_exists( $c['path'] )
                    && ( ! $best || $long > max( $best['w'], $best['h'] ) ) ) {
                    $best = $c;
                }
            }
        }
        if ( $best ) {
            $bytes = (string) file_get_contents( $best['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            return array( 'data' => 'data:' . $best['mime'] . ';base64,' . base64_encode( $bytes ), 'mime' => $best['mime'], 'width' => $best['w'], 'height' => $best['h'], 'bytes' => strlen( $bytes ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
        }

        // Küçült ve JPEG'e çevir (AVIF, HEIC, GIF, büyük PNG …).
        $editor = wp_get_image_editor( $file );
        if ( is_wp_error( $editor ) ) {
            return new WP_Error( 'unsupported', __( 'This image format cannot be read on this server.', 'erdo-image-optimizer' ) );
        }
        $editor->resize( self::MAX_SIDE, self::MAX_SIDE, false );
        $editor->set_quality( 82 );
        $tmp   = wp_tempnam( 'erdo-ai' ) . '.jpg';
        $saved = $editor->save( $tmp, 'image/jpeg' );
        if ( is_wp_error( $saved ) ) {
            return $saved;
        }
        $bytes = (string) file_get_contents( $saved['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        wp_delete_file( $saved['path'] );
        return array( 'data' => 'data:image/jpeg;base64,' . base64_encode( $bytes ), 'mime' => 'image/jpeg', 'width' => (int) $saved['width'], 'height' => (int) $saved['height'], 'bytes' => strlen( $bytes ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
    }

    /**
     * Modele gidecek bağlam: görselin kullanıldığı sayfa (önce üst yazı, yoksa
     * indeksteki ilk yayımlanmış yazı), odak anahtar kelime, çevresindeki metin,
     * dil, Woo ürün adı ve kategorileri. Kişisel veri (yazar, e-posta) gönderilmez.
     */
    public static function context( $id ) {
        $post   = get_post( $id );
        $page   = $post && $post->post_parent ? get_post( $post->post_parent ) : null;
        if ( ( ! $page || 'publish' !== $page->post_status ) && ERDO_Usage::is_ready() ) {
            // Görselin yazı içinde göründüğü sayfa en iyi bağlamdır; sonra öne çıkan görsel, sonra meta (galeri, builder).
            $rank    = array( 'content' => 0, 'featured' => 1 );
            $sources = ERDO_Usage::sources( $id );
            usort( $sources, function ( $a, $b ) use ( $rank ) {
                return ( $rank[ $a['key'] ] ?? 2 ) <=> ( $rank[ $b['key'] ] ?? 2 );
            } );
            foreach ( $sources as $src ) {
                $p = 'post' === $src['type'] ? get_post( $src['id'] ) : null;
                if ( $p && 'publish' === $p->post_status && is_post_type_viewable( $p->post_type ) ) {
                    $page = $p;
                    break;
                }
            }
        }

        $ctx = array(
            'page_title'    => $page ? wp_strip_all_tags( $page->post_title ) : '',
            'focus_keyword' => $page ? ERDO_SEO::focus_keyword( $page->ID ) : '',
            'nearby_text'   => $page ? self::nearby_text( $page->post_content, $id ) : '',
            'current_alt'   => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
            'current_title' => $post ? $post->post_title : '',
            'language'      => self::language( $page ? $page->ID : 0 ),
            'product'       => '',
            'categories'    => '',
        );
        if ( $page && 'product' === $page->post_type ) {
            $ctx['product'] = $ctx['page_title'];
            $terms          = get_the_terms( $page->ID, 'product_cat' );
            $ctx['categories'] = is_array( $terms ) ? implode( ', ', wp_list_pluck( $terms, 'name' ) ) : '';
        }
        return $ctx;
    }

    /** Görselin içerikteki yerinin çevresindeki ~500 karakter düz metin. */
    private static function nearby_text( $content, $id ) {
        $stem = pathinfo( (string) get_attached_file( $id ), PATHINFO_FILENAME );
        $pos  = false;
        foreach ( array( 'wp-image-' . $id . '"', 'wp-image-' . $id . ' ', '"id":' . $id . ',', '"id":' . $id . '}', $stem ) as $needle ) {
            if ( '' !== $needle && false !== ( $pos = strpos( $content, $needle ) ) ) {
                break;
            }
        }
        if ( false === $pos ) {
            $text = wp_strip_all_tags( strip_shortcodes( $content ) );
        } else {
            // İğne etiketin ortasında (class="wp-image-N"); kesim etiketin başından yapılmazsa
            // `wp-image-N"/>` gibi parçalar metne sızar.
            $tag = strrpos( substr( $content, 0, $pos ), '<' );
            $pos = false === $tag ? $pos : $tag;
            $before = wp_strip_all_tags( substr( $content, max( 0, $pos - 1500 ), min( $pos, 1500 ) ) );
            $after  = wp_strip_all_tags( substr( $content, $pos, 1500 ) );
            $text   = substr( $before, -250 ) . ' ' . substr( $after, 0, 250 );
        }
        $text = trim( preg_replace( '/\s+/u', ' ', $text ) );
        return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 500 ) : substr( $text, 0, 500 );
    }

    /** İçeriğin dili: WPML / Polylang varsa yazının dili, yoksa sitenin dili. */
    private static function language( $post_id ) {
        $locale = '';
        if ( $post_id && function_exists( 'pll_get_post_language' ) ) {
            $locale = (string) pll_get_post_language( $post_id, 'locale' );
        }
        if ( '' === $locale && $post_id ) {
            $details = apply_filters( 'wpml_post_language_details', null, $post_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter
            $locale  = is_array( $details ) ? (string) ( $details['locale'] ?? '' ) : '';
        }
        $locale = '' !== $locale ? $locale : get_locale();
        if ( class_exists( 'Locale' ) ) {
            $name = Locale::getDisplayLanguage( $locale, 'en' );
            if ( $name && $name !== $locale ) {
                return $name . ' (' . $locale . ')';
            }
        }
        return $locale;
    }

    /* ------------------------------------------------------------------
       İNCELE: KABUL / RED
    ------------------------------------------------------------------ */

    public static function get( $id ) {
        $s = get_post_meta( (int) $id, self::META, true );
        return is_array( $s ) ? $s : null;
    }

    /**
     * Öneriyi uygular. $fields: alt, title, caption, filename (decorative alt ile gelir).
     * $edits: kullanıcının düzenlediği değerler (alan → metin).
     * Döner: array( 'applied' => alanlar, 'filename' => yeni ad|null ) | WP_Error
     */
    public static function accept( $id, array $fields = array( 'alt' ), array $edits = array() ) {
        $id = (int) $id;
        $s  = self::get( $id );
        if ( ! $s ) {
            return new WP_Error( 'no_suggestion', __( 'There is no suggestion for this image.', 'erdo-image-optimizer' ) );
        }
        foreach ( $edits as $k => $v ) {
            if ( in_array( $k, array( 'alt', 'title', 'caption', 'filename' ), true ) ) {
                $s[ $k ] = 'filename' === $k ? ERDO_Renamer::sanitize_stem( $v ) : sanitize_text_field( $v );
            }
        }

        $applied = array();
        if ( in_array( 'alt', $fields, true ) ) {
            if ( ! empty( $s['decorative'] ) && '' === $s['alt'] ) {
                update_post_meta( $id, ERDO_SEO::DECORATIVE_META, 1 );
                update_post_meta( $id, '_wp_attachment_image_alt', '' );
            } elseif ( '' !== $s['alt'] ) {
                delete_post_meta( $id, ERDO_SEO::DECORATIVE_META );
                update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $s['alt'] ) );
            }
            $applied[] = 'alt';
        }
        $post_update = array( 'ID' => $id );
        if ( in_array( 'title', $fields, true ) && '' !== $s['title'] ) {
            $post_update['post_title'] = sanitize_text_field( $s['title'] );
            $applied[]                 = 'title';
        }
        if ( in_array( 'caption', $fields, true ) && '' !== $s['caption'] ) {
            $post_update['post_excerpt'] = sanitize_text_field( $s['caption'] );
            $applied[]                   = 'caption';
        }
        if ( count( $post_update ) > 1 ) {
            wp_update_post( $post_update );
        }
        $filename = null;
        if ( in_array( 'filename', $fields, true ) && '' !== $s['filename'] ) {
            $r = ERDO_Renamer::rename( $id, $s['filename'] );
            if ( is_wp_error( $r ) && 'clean' !== $r->get_error_code() ) {
                return $r;
            }
            $filename  = wp_basename( (string) get_attached_file( $id ) );
            $applied[] = 'filename';
        }

        delete_post_meta( $id, self::META );
        ERDO_Auditor::clear_cache( $id );
        ERDO_Activity::log( 'ai_accept' );
        return array( 'applied' => $applied, 'filename' => $filename );
    }

    public static function reject( $id ) {
        delete_post_meta( (int) $id, self::META );
        return true;
    }

    /** İnceleme bekleyen öneriler (en yeni önce). */
    public static function pending_ids( $limit = 200 ) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
            "SELECT m.post_id FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
             WHERE m.meta_key = %s AND p.post_type = 'attachment' AND p.post_status = 'inherit'
             ORDER BY m.meta_id DESC LIMIT %d",
            self::META,
            $limit
        ) ) );
    }

    /** Alt metni eksik ya da zayıf (puanı tam olmayan), önerisi olmayan görseller — toplu işlem için. */
    public static function candidate_ids( $scope = 'missing' ) {
        global $wpdb;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} a ON a.post_id = p.ID AND a.meta_key = '_wp_attachment_image_alt'
             LEFT JOIN {$wpdb->postmeta} d ON d.post_id = p.ID AND d.meta_key = %s
             LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s
             WHERE p.post_type = 'attachment' AND p.post_status = 'inherit' AND p.post_mime_type LIKE %s
             AND s.meta_id IS NULL AND d.meta_id IS NULL AND ( a.meta_value IS NULL OR a.meta_value = '' )
             ORDER BY p.ID DESC",
            ERDO_SEO::DECORATIVE_META,
            self::META,
            $wpdb->esc_like( 'image/' ) . '%'
        ) );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $ids = array_map( 'intval', $ids );
        if ( 'weak' === $scope ) {
            // Alt metni olup kalite kontrolünden geçemeyenler de dahil.
            $all = get_posts( array(
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'post_mime_type' => 'image',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
            ) );
            foreach ( ERDO_Auditor::bulk_analyze( $all ) as $id => $r ) {
                foreach ( (array) ( $r['checklist'] ?? array() ) as $c ) {
                    if ( 'alt' === ( $c['key'] ?? '' ) && $c['points'] < $c['weight'] && ! self::get( $id ) ) {
                        $ids[] = (int) $id;
                    }
                }
            }
            $ids = array_values( array_unique( $ids ) );
        }
        return $ids;
    }

    /* ------------------------------------------------------------------
       YÜKLEMEDE (isteğe bağlı, varsayılan kapalı) — yüklemeyi yavaşlatmaz
    ------------------------------------------------------------------ */

    public static function on_upload( $id ) {
        if ( get_option( 'erdo_ai_on_upload' ) && wp_attachment_is_image( $id ) && self::is_ready() ) {
            wp_schedule_single_event( time() + 30, self::CRON_HOOK, array( (int) $id ) );
        }
    }

    public static function cron_suggest( $id ) {
        if ( '' === (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) && ! self::get( $id ) ) {
            self::suggest( $id );
        }
    }
}
