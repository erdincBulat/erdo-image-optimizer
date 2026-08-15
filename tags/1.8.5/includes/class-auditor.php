<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ERDO_Auditor {

    public static function clear_cache( $post_id ) {
        delete_post_meta( $post_id, '_erdo_audit_cache' );
        wp_cache_delete( $post_id, 'post_meta' );
    }

    public static function clear_all_cache() {
        delete_metadata( 'post', 0, '_erdo_audit_cache', '', true );
        wp_cache_flush();
    }

    // ========== YENİ: TOPLU USAGE KONTROLÜ ==========
    public static function bulk_check_usage( $attachment_ids ) {
        global $wpdb;
        
        $results = array();
        foreach ( $attachment_ids as $id ) {
            $results[ $id ] = false;
        }
        
        if ( empty( $attachment_ids ) ) {
            return $results;
        }
        
        $ids_str = implode( ',', array_map( 'intval', $attachment_ids ) );
        
// 1. Parent kontrolü (tek sorguda)
$cache_key = 'erdo_bulk_parents_' . md5($ids_str);
$parents = wp_cache_get($cache_key, 'erdo_image_optimizer');

if (false === $parents) {
    $count = count($attachment_ids);
    $placeholders = implode(',', array_fill(0, $count, '%d'));

    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $parents = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE post_type = 'attachment' AND post_parent > 0 AND ID IN ($placeholders)", ...$attachment_ids ) );
    
    wp_cache_set($cache_key, $parents, 'erdo_image_optimizer', 300);
}

if ( ! empty( $parents ) ) {
    foreach ( $parents as $id ) {
        $results[ $id ] = true;
    }
}
        
// 2. Featured image kontrolü (tek sorguda)
$cache_key = 'erdo_featured_imgs_' . md5( $ids_str );
$featured  = wp_cache_get( $cache_key, 'erdo_image_optimizer' );

if ( false === $featured ) {
    $placeholders = implode(',', array_fill(0, count($attachment_ids), '%d'));

    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $featured = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT meta_value FROM $wpdb->postmeta WHERE meta_key = '_thumbnail_id' AND meta_value IN ($placeholders)", ...$attachment_ids ) );
    
    wp_cache_set( $cache_key, $featured, 'erdo_image_optimizer', 300 );
}
        
        foreach ( $featured as $id ) {
            $results[ $id ] = true;
        }
        
        // 3. Kullanılmayan görselleri bul
        $unused_ids = array();
        foreach ( $results as $id => $is_used ) {
            if ( ! $is_used ) {
                $unused_ids[] = $id;
            }
        }
        
        // 4. Content/Meta kontrolü (sadece kullanılmayan görseller için)
        if ( ! empty( $unused_ids ) ) {
            foreach ( $unused_ids as $id ) {
                $file_path = get_attached_file( $id );
                if ( ! $file_path ) continue;
                
                $file_name = basename( $file_path );
                $like_name = '%' . $wpdb->esc_like( $file_name ) . '%';
                
                // ID bazlı kontroller
                $sc_dbl = '%="' . $id . '"%';
                $sc_sng = "%='" . $id . "'%";
                $json_int = '%:' . $id . ',%';
                $json_end = '%:' . $id . '}';
                $json_str = '%:"' . $id . '"%';
                
// Content kontrolü
$cache_key = 'erdo_in_content_' . $id;
$in_content = wp_cache_get( $cache_key, 'erdo_image_optimizer' );

if ( false === $in_content ) {
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $in_content = $wpdb->get_var( $wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} 
         WHERE post_status NOT IN ('inherit', 'trash', 'auto-draft') 
         AND ID != %d 
         AND (
            post_content LIKE %s OR 
            post_content LIKE %s OR 
            post_content LIKE %s OR 
            post_content LIKE %s OR 
            post_content LIKE %s OR 
            post_content LIKE %s
         ) LIMIT 1",
        $id, $like_name, $sc_dbl, $sc_sng, $json_int, $json_end, $json_str
    ) );
    wp_cache_set( $cache_key, $in_content, 'erdo_image_optimizer', 300 );
}
                
                if ( $in_content ) {
                    $results[ $id ] = true;
                    continue;
                }
                
// Meta kontrolü
$cache_key = 'erdo_in_meta_' . $id;
$in_meta = wp_cache_get( $cache_key, 'erdo_image_optimizer' );

if ( false === $in_meta ) {
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $in_meta = $wpdb->get_var( $wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} 
         WHERE (
            meta_value LIKE %s OR 
            meta_value LIKE %s OR 
            meta_value LIKE %s OR 
            meta_value LIKE %s OR 
            meta_value LIKE %s OR 
            meta_value LIKE %s
         )
         AND post_id != %d 
         LIMIT 1",
        $like_name, $sc_dbl, $sc_sng, $json_int, $json_end, $json_str, $id
    ) );
    wp_cache_set( $cache_key, $in_meta, 'erdo_image_optimizer', 300 );
}
                
                if ( $in_meta ) {
                    $results[ $id ] = true;
                }
            }
        }
        
        return $results;
    }

    // ========== YENİ: TOPLU ANALİZ ==========
    public static function bulk_analyze( $attachment_ids ) {
        global $wpdb;
        
        if ( empty( $attachment_ids ) ) {
            return array();
        }
        
        $results = array();
        
// 1. Tüm meta dataları tek seferde çek
$ids_str = implode( ',', array_map( 'intval', $attachment_ids ) );

// Önbellek kontrolü
$cache_key = 'erdo_bulk_metadata_' . md5( $ids_str );
$metadata_rows = wp_cache_get( $cache_key, 'erdo_image_optimizer' );

if ( false === $metadata_rows ) {
    $count = count( $attachment_ids );
    $placeholders = implode( ',', array_fill( 0, $count, '%d' ) );

    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $metadata_rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_key, meta_value FROM $wpdb->postmeta WHERE post_id IN ($placeholders) AND meta_key IN ('_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_image_alt', '_erdo_audit_cache', '_erdo_savings_bytes', '_erdo_original_size')", ...$attachment_ids ), ARRAY_A );

    wp_cache_set( $cache_key, $metadata_rows, 'erdo_image_optimizer', 300 );
}
        
        // Veriyi düzenle
        $meta_by_post = array();
        foreach ( $metadata_rows as $row ) {
            $post_id = $row['post_id'];
            if ( ! isset( $meta_by_post[ $post_id ] ) ) {
                $meta_by_post[ $post_id ] = array();
            }
            $meta_by_post[ $post_id ][ $row['meta_key'] ] = maybe_unserialize( $row['meta_value'] );
        }
        
// 2. Post bilgilerini çek
$cache_key = 'erdo_bulk_posts_' . md5($ids_str);
$posts = wp_cache_get($cache_key, 'erdo_image_optimizer');

if (false === $posts) {
    $count = count( $attachment_ids );
    $placeholders = implode( ',', array_fill( 0, $count, '%d' ) );

    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $posts = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_title, post_mime_type, post_parent FROM $wpdb->posts WHERE ID IN ($placeholders)", ...$attachment_ids ), ARRAY_A );

    wp_cache_set($cache_key, $posts, 'erdo_image_optimizer', 300);
}
        
        $posts_by_id = array();
        foreach ( $posts as $post ) {
            $posts_by_id[ $post['ID'] ] = $post;
        }
        
// 3. Parent başlıkları çek
$parent_ids = array_filter( array_column( $posts, 'post_parent' ) );
$parent_titles = array();
if ( ! empty( $parent_ids ) ) {
    $parent_ids_str = implode( ',', array_map( 'intval', $parent_ids ) );

    // Önbellek kontrolü
    $cache_key = 'erdo_parent_titles_' . md5( $parent_ids_str );
    $parent_rows = wp_cache_get( $cache_key, 'erdo_image_optimizer' );

    if ( false === $parent_rows ) {
        $count = count( $parent_ids );
        $placeholders = implode( ',', array_fill( 0, $count, '%d' ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $parent_rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_title FROM $wpdb->posts WHERE ID IN ($placeholders)", ...$parent_ids ), ARRAY_A );
        
        wp_cache_set( $cache_key, $parent_rows, 'erdo_image_optimizer', 300 );
    }

    if ( ! empty( $parent_rows ) ) {
        foreach ( $parent_rows as $p ) {
            $parent_titles[ $p['ID'] ] = $p['post_title'];
        }
    }
}
        
        // 4. Usage kontrolü (toplu)
        $usage_results = self::bulk_check_usage( $attachment_ids );
        
        // 5. Her görsel için analiz yap
        foreach ( $attachment_ids as $post_id ) {
            // Cache kontrolü
            if ( isset( $meta_by_post[ $post_id ]['_erdo_audit_cache'] ) ) {
                $cached = $meta_by_post[ $post_id ]['_erdo_audit_cache'];
                if ( is_array( $cached ) ) {
                    $results[ $post_id ] = $cached;
                    continue;
                }
            }
            
            // Yeni analiz
            $post = $posts_by_id[ $post_id ] ?? null;
            if ( ! $post ) continue;
            
            $meta = $meta_by_post[ $post_id ]['_wp_attachment_metadata'] ?? array();
            $file = $meta_by_post[ $post_id ]['_wp_attached_file'] ?? '';
            $alt = $meta_by_post[ $post_id ]['_wp_attachment_image_alt'] ?? '';
            
            $name = pathinfo( $file, PATHINFO_FILENAME );
            $title = $post['post_title'];
            $mime = $post['post_mime_type'];
            
            $parent_title = '';
            if ( $post['post_parent'] > 0 && isset( $parent_titles[ $post['post_parent'] ] ) ) {
                $parent_title = $parent_titles[ $post['post_parent'] ];
            }
            
            $width = $meta['width'] ?? 0;
            $height = $meta['height'] ?? 0;
            
            $is_used = $usage_results[ $post_id ] ?? false;
            
            $checklist = array();
            
            $checklist[] = array(
                'label' => 'Filename',
                'pass' => ! preg_match( '/[_ ]|\d{4,}/', $name ),
                'value' => $name,
                'msg' => __( 'Filename contains spaces or underscores. Use hyphens for better SEO.', 'erdo-image-optimizer' )
            );
            
            $checklist[] = array(
                'label' => 'Alt Text',
                'pass' => ! empty( $alt ),
                'value' => $alt,
                'msg' => __( 'Alt text is missing. Search engines use this to understand your image.', 'erdo-image-optimizer' )
            );
            
            $checklist[] = array(
                'label' => 'Image Title',
                'pass' => ( ! empty( $title ) && false === strpos( $title, '-' ) && false === strpos( $title, '_' ) ),
                'value' => $title,
                'msg' => __( 'Title contains symbols. Keep it clean and readable for users.', 'erdo-image-optimizer' )
            );
            
            $limit = 2560;
            $dim_pass = ( $width > 0 && $width <= $limit );
            
            $checklist[] = array(
                'label' => 'Dimensions',
                'pass' => $dim_pass,
                'width' => $width,
                'height' => $height,
'msg' => ( $width > 0 )
    ? sprintf(
        // translators: %d: Maximum allowed image width in pixels.
        __( 'Image is too large (>%dpx wide).', 'erdo-image-optimizer' ),
        $limit
    )
    : __( 'Image dimensions could not be detected.', 'erdo-image-optimizer' ),
);

            
            $is_optimized = ( false !== strpos( $mime, 'webp' ) || false !== strpos( $mime, 'avif' ) );
            
$checklist[] = array(
    'label' => 'Format',
    'pass'  => $is_optimized,
    'msg'   => sprintf(
        // translators: %s: Current image MIME subtype (e.g. JPG, PNG).
        __( 'Current format is %s. WebP or AVIF is recommended.', 'erdo-image-optimizer' ),
        strtoupper( str_replace( 'image/', '', $mime ) )
    ),
);

            
$checklist[] = array(
    'label' => 'File Size',
    'pass'  => ( isset( $meta['filesize'] ) && $meta['filesize'] < 256000 ),
    'msg'   => sprintf(
        // translators: %s: Image file size in kilobytes.
        __( 'File size is %s KB. Aim for under 250 KB.', 'erdo-image-optimizer' ),
        round( ( $meta['filesize'] ?? 0 ) / 1024 )
    ),
);

            
            $score = 0;
            foreach ( $checklist as $c ) {
                if ( $c['pass'] ) $score++;
            }
            
            if ( ! $is_used ) {
                $status = 'gray';
            } else {
                if ( $score === 6 ) {
                    $status = 'green';
                } elseif ( $score >= 3 ) {
                    $status = 'orange';
                } else {
                    $status = 'red';
                }
            }
            
            $saved_bytes = (int) ( $meta_by_post[ $post_id ]['_erdo_savings_bytes'] ?? 0 );
            $original_size = (int) ( $meta_by_post[ $post_id ]['_erdo_original_size'] ?? 0 );
            $current_size = $meta['filesize'] ?? 0;
            
            $result = array(
                'status' => $status,
                'is_used' => $is_used,
                'score' => $score,
                'checklist' => $checklist,
                'thumbnail' => wp_get_attachment_image_url( $post_id, 'thumbnail' ),
                'filename' => basename( $file ),
                'parent_title' => $parent_title,
                'id' => $post_id,
                'saved_bytes' => $saved_bytes,
                'original_size' => $original_size,
                'current_size' => $current_size
            );
            
            // Cache'e kaydet
            update_post_meta( $post_id, '_erdo_audit_cache', $result );
            
            $results[ $post_id ] = $result;
        }
        
        return $results;
    }

    public static function check_usage( $post_id ) {
        $result = self::bulk_check_usage( array( $post_id ) );
        return $result[ $post_id ] ?? false;
    }

    public static function analyze( $post_id ) {
        $results = self::bulk_analyze( array( $post_id ) );
        return $results[ $post_id ] ?? array();
    }

    // Diğer metodlar aynı kalacak...
    
    public static function clean_metadata( $post_id ) {
        $file_path = get_attached_file( $post_id );
        
        if ( ! file_exists( $file_path ) ) {
            return false;
        }

        if ( extension_loaded( 'imagick' ) && class_exists( 'Imagick' ) ) {
            try {
                $img = new Imagick( $file_path );
                $img->stripImage();
                $img->writeImage( $file_path );
                $img->clear();
                $img->destroy();
                self::clear_cache( $post_id );
                return true;
            } catch ( Exception $e ) {
                // Silently fail to GD
            }
        }

        $editor = wp_get_image_editor( $file_path );
        if ( ! is_wp_error( $editor ) ) {
            $saved = $editor->save( $file_path );
            if ( ! is_wp_error( $saved ) ) {
                self::clear_cache( $post_id );
                return true;
            }
        }
        return false;
    }

    public static function manual_resize( $post_id, $target_width, $target_height ) {
        $file_path = get_attached_file( $post_id );
        
        if ( ! file_exists( $file_path ) ) {
            return false;
        }
        
        $editor = wp_get_image_editor( $file_path );
        if ( is_wp_error( $editor ) ) {
            return false;
        }

        $resized = $editor->resize( $target_width, $target_height, false );
        if ( is_wp_error( $resized ) ) {
            return false;
        }

        $quality = (int) get_option( 'erdo_quality', 80 );
        $editor->set_quality( $quality );
        
        $saved = $editor->save( $file_path );

        if ( is_wp_error( $saved ) ) {
            return false;
        }

        $new_size = $editor->get_size();
        clearstatcache();
        
        $meta = wp_get_attachment_metadata( $post_id );
        if ( ! is_array( $meta ) ) {
            $meta = array();
        }
        
        $meta['width'] = $new_size['width'];
        $meta['height'] = $new_size['height'];
        $meta['filesize'] = filesize( $file_path );
        
        wp_update_attachment_metadata( $post_id, $meta );
        self::clear_cache( $post_id );

        return true;
    }

    public static function convert_to_webp( $post_id, $update_db = true ) {
        $file_path = get_attached_file( $post_id );
        
        if ( ! file_exists( $file_path ) ) {
            return false;
        }

        $original_size = filesize( $file_path );
        $info = pathinfo( $file_path );
        $ext = strtolower( $info['extension'] );
        
        if ( 'webp' === $ext || 'avif' === $ext ) {
            return false;
        }

        $target_format = get_option( 'erdo_convert_format', 'webp' );
        if ( 'avif' === $target_format && ! function_exists( 'imageavif' ) ) {
            $target_format = 'webp';
        }

        $quality = (int) get_option( 'erdo_quality', 80 );
        $new_path = $info['dirname'] . '/' . $info['filename'] . '.' . $target_format;
        
        if ( 'png' === $ext ) {
            $image = @imagecreatefrompng( $file_path );
            if ( $image ) {
                imagepalettetotruecolor( $image );
                imagealphablending( $image, true );
                imagesavealpha( $image, true );
            }
        } elseif ( 'jpeg' === $ext || 'jpg' === $ext ) {
            $image = @imagecreatefromjpeg( $file_path );
        } else {
            return false;
        }
        
        if ( ! $image ) {
            return false;
        }

        $success = false;
        if ( 'avif' === $target_format ) {
            $success = imageavif( $image, $new_path, $quality );
        } else {
            $success = imagewebp( $image, $new_path, $quality );
        }
        
        imagedestroy( $image );
        
        if ( $success ) {
            wp_delete_file( $file_path );
            
            clearstatcache();
            if ( file_exists( $new_path ) ) {
                $new_size = filesize( $new_path );
                $saved_bytes = $original_size - $new_size;

                if ( $saved_bytes > 0 ) {
                    $total_saved = (int) get_option( 'erdo_total_saved_bytes', 0 );
                    update_option( 'erdo_total_saved_bytes', $total_saved + $saved_bytes );
                    
                    update_post_meta( $post_id, '_erdo_savings_bytes', $saved_bytes );
                    update_post_meta( $post_id, '_erdo_original_size', $original_size );
                }
            }

            if ( $update_db ) {
                update_attached_file( $post_id, $new_path );
                wp_update_post( array( 'ID' => $post_id, 'post_mime_type' => 'image/' . $target_format ) );
                
                require_once ABSPATH . 'wp-admin/includes/image.php';
                $attach_data = wp_generate_attachment_metadata( $post_id, $new_path );
                clearstatcache();
                $attach_data['filesize'] = filesize( $new_path );
                wp_update_attachment_metadata( $post_id, $attach_data );
                
                self::clear_cache( $post_id );
                return true;
            } else {
                clearstatcache();
                return array(
                    'path' => $new_path,
                    'mime' => 'image/' . $target_format,
                    'filesize' => filesize( $new_path )
                );
            }
        }
        return false;
    }

    public static function save_image_data( $post_id, $new_data ) {
        if ( ! isset( $post_id ) ) {
            return false;
        }

        if ( isset( $new_data['alt_text'] ) ) {
            update_post_meta( $post_id, '_wp_attachment_image_alt', sanitize_text_field( $new_data['alt_text'] ) );
        }

        if ( isset( $new_data['title'] ) ) {
            wp_update_post( array( 'ID' => $post_id, 'post_title' => sanitize_text_field( $new_data['title'] ) ) );
        }
        
        if ( isset( $new_data['filename'] ) && ! empty( $new_data['filename'] ) ) {
            $old_path = get_attached_file( $post_id );
            $path_info = pathinfo( $old_path );
            $new_name_slug = sanitize_title( $new_data['filename'] );
            $new_path = $path_info['dirname'] . '/' . $new_name_slug . '.' . $path_info['extension'];

            if ( $old_path !== $new_path && ! file_exists( $new_path ) ) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
                WP_Filesystem();
                global $wp_filesystem;
                
                if ( $wp_filesystem && $wp_filesystem->move( $old_path, $new_path ) ) {
                    update_attached_file( $post_id, $new_path );
                    require_once ABSPATH . 'wp-admin/includes/image.php';
                    wp_update_attachment_metadata( $post_id, wp_generate_attachment_metadata( $post_id, $new_path ) );
                }
            }
        }
        self::clear_cache( $post_id );
        return true;
    }

    public static function delete_image( $post_id ) {
        return wp_delete_attachment( $post_id, true );
    }
}