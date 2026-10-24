<?php
/**
 * Fixture catalogue: a small media library that references images from
 * every place real sites keep them (classic/block content, featured image,
 * theme mods, site icon, widgets, page-builder JSON, ACF, WooCommerce,
 * serialized meta, drafts, trash, [gallery] children).
 *
 * Everything created is tagged with the `_erdo_fixture` post meta and lives in
 * a unique per-run uploads folder (Windows keeps deleted files "delete-pending"
 * while the Studio server holds handles, so names are never reused).
 */

const ET_FIXTURE_META = '_erdo_fixture';
const ET_FIXTURE_DIRS = 'et_fixture_dirs';

/**
 * Expected usage of each fixture image. true = referenced somewhere on the
 * site and must never be offered for deletion. Decisions encoded here:
 *  - draft / trash references count as used (restorable content, safe side);
 *  - "attached to a post but not in its content" is NOT usage, unless the
 *    parent renders attached children through an id-less [gallery].
 */
function et_expected_usage() {
    return array(
        'badname'       => true,  // classic content, full-size URL
        'sized'         => true,  // classic content, only the -300xN URL
        'block'         => true,  // Gutenberg image block
        'anim'          => true,  // Gutenberg image block (animated GIF)
        'featured'      => true,  // _thumbnail_id
        'logo'          => true,  // theme_mods custom_logo
        'siteicon'      => true,  // site_icon option
        'header'        => true,  // theme_mods header_image(_data)
        'elementor'     => true,  // _elementor_data JSON (escaped slashes)
        'acf_id'        => true,  // ACF image field storing the ID
        'acf_url'       => true,  // ACF field storing the URL
        'woo_gallery'   => true,  // _product_image_gallery CSV
        'widget_media'  => true,  // widget_media_image option
        'widget_block'  => true,  // widget_block option
        'serialized'    => true,  // URL nested in serialized post meta
        'draft_only'    => true,  // only in a draft
        'trash_only'    => true,  // only in a trashed post
        'gallery_child' => true,  // attached child of a post using [gallery]
        'good'          => true,  // well-optimised control image
        'dup_a'         => true,  // byte-identical to dup_b, used
        'camera'        => true,  // IMG_1234.JPG
        'dscalt'        => true,  // alt text derived from a camera name
        'alpha'         => true,  // transparent PNG
        'gif_static'    => true,
        'oversized'     => true,  // 3000x2000 → WordPress makes a -scaled copy
        'term_thumb'    => true,  // term meta thumbnail_id (WooCommerce category image)
        'css_bg'        => true,  // url() in Additional CSS (custom_css post)
        'unused'        => false,
        'attached_only' => false, // attached to a post that doesn't show it
        'dup_b'         => false,
    );
}

function et_noise( $w, $h, $seed, $alpha = false ) {
    mt_srand( $seed );
    $im = imagecreatetruecolor( $w, $h );
    if ( $alpha ) {
        imagealphablending( $im, false );
        imagesavealpha( $im, true );
        imagefill( $im, 0, 0, imagecolorallocatealpha( $im, 0, 0, 0, 127 ) );
        imagealphablending( $im, true );
    }
    for ( $i = 0; $i < 250; $i++ ) {
        $c = imagecolorallocate( $im, mt_rand( 0, 255 ), mt_rand( 0, 255 ), mt_rand( 0, 255 ) );
        $x = mt_rand( 0, $w );
        $y = mt_rand( 0, $h );
        imagefilledrectangle( $im, $x, $y, min( $w, $x + mt_rand( 1, max( 1, intdiv( $w, 3 ) ) ) ), min( $h, $y + mt_rand( 1, max( 1, intdiv( $h, 3 ) ) ) ), $c );
    }
    return $im;
}

/** Two-frame animated GIF assembled by hand (GD can't write animations). */
function et_animated_gif_bytes() {
    $frame = function ( $color ) {
        $im = imagecreate( 60, 60 );
        imagecolorallocate( $im, 255, 255, 255 );
        imagecolorallocate( $im, 255, 0, 0 );
        imagecolorallocate( $im, 0, 0, 255 );
        imagefilledrectangle( $im, 8, 8, 52, 52, $color );
        ob_start();
        imagegif( $im );
        return ob_get_clean();
    };
    $g1    = $frame( 1 );
    $g2    = $frame( 2 );
    $gct   = 3 * ( 1 << ( ( ord( $g1[10] ) & 7 ) + 1 ) );
    $head  = substr( $g1, 0, 13 + $gct );
    $strip = function ( $g ) use ( $gct ) {
        $r = substr( $g, 13 + $gct, -1 );
        return "\x21\xF9" === substr( $r, 0, 2 ) ? substr( $r, 8 ) : $r;
    };
    $gce = "\x21\xF9\x04\x00\x32\x00\x00\x00";
    return $head . "\x21\xFF\x0BNETSCAPE2.0\x03\x01\x00\x00\x00" . $gce . $strip( $g1 ) . $gce . $strip( $g2 ) . "\x3B";
}

function et_add_attachment( $file, $title, $alt = '', $parent = 0 ) {
    $mime = wp_check_filetype( $file )['type'];
    $id   = wp_insert_attachment( array(
        'post_mime_type' => $mime,
        'post_title'     => $title,
        'post_status'    => 'inherit',
        'post_parent'    => $parent,
    ), $file, $parent );
    update_post_meta( $id, ET_FIXTURE_META, 1 );
    if ( '' !== $alt ) {
        update_post_meta( $id, '_wp_attachment_image_alt', $alt );
    }
    // Bypass the plugin's upload pipeline: fixtures model an existing library.
    $hooks = et_suspend_upload_hooks();
    wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $file ) );
    et_resume_upload_hooks( $hooks );
    return $id;
}

function et_suspend_upload_hooks() {
    global $wp_filter;
    $saved = isset( $wp_filter['wp_generate_attachment_metadata'] ) ? clone $wp_filter['wp_generate_attachment_metadata'] : null;
    remove_all_filters( 'wp_generate_attachment_metadata' );
    return $saved;
}

function et_resume_upload_hooks( $saved ) {
    global $wp_filter;
    if ( $saved ) {
        $wp_filter['wp_generate_attachment_metadata'] = $saved;
    }
}

function et_add_post( $title, $content, $status = 'publish', $type = 'post' ) {
    $id = wp_insert_post( array(
        'post_title'   => $title,
        'post_content' => $content,
        'post_status'  => $status,
        'post_type'    => $type,
    ) );
    update_post_meta( $id, ET_FIXTURE_META, 1 );
    return $id;
}

function et_block_image( $id, $size = 'large' ) {
    $src = wp_get_attachment_image_url( $id, $size );
    return '<!-- wp:image {"id":' . $id . ',"sizeSlug":"' . $size . '","linkDestination":"none"} -->'
        . '<figure class="wp-block-image size-' . $size . '"><img src="' . esc_url( $src ) . '" alt="" class="wp-image-' . $id . '"/></figure>'
        . '<!-- /wp:image -->';
}

/**
 * Builds the full catalogue. Returns array( 'img' => name=>id, 'post' => name=>id,
 * 'dir' => path ). Takes a few seconds (GD generates every sub-size).
 */
function et_fixtures() {
    $dir = wp_upload_dir()['path'] . '/et' . gmdate( 'His' ) . wp_rand( 100, 999 );
    wp_mkdir_p( $dir );
    $dirs   = (array) get_option( ET_FIXTURE_DIRS, array() );
    $dirs[] = $dir;
    update_option( ET_FIXTURE_DIRS, $dirs, false );

    $img = array();
    $jpg = function ( $name, $file, $w, $h, $seed, $title, $alt = '', $parent = 0 ) use ( &$img, $dir ) {
        imagejpeg( et_noise( $w, $h, $seed ), "$dir/$file", 88 );
        $img[ $name ] = et_add_attachment( "$dir/$file", $title, $alt, $parent );
    };

    $jpg( 'badname', 'Fx_Bad_Name.jpg', 1200, 800, 1, 'Fx_Bad_Name' );
    imagepng( et_noise( 1000, 700, 2 ), "$dir/fx-sized-only.png" );
    $img['sized'] = et_add_attachment( "$dir/fx-sized-only.png", 'fx sized only', 'Sized only image' );
    $jpg( 'block', 'fx-block-used.jpg', 900, 600, 3, 'fx block used', 'Block image' );
    file_put_contents( "$dir/fx-animated.gif", et_animated_gif_bytes() ); // phpcs:ignore
    $img['anim'] = et_add_attachment( "$dir/fx-animated.gif", 'fx animated', 'Animated' );
    $jpg( 'featured', 'fx-featured.jpg', 900, 600, 5, 'fx featured', 'Featured' );
    imagepng( et_noise( 400, 120, 6 ), "$dir/fx-site-logo.png" );
    $img['logo'] = et_add_attachment( "$dir/fx-site-logo.png", 'fx site logo', 'Logo' );
    imagepng( et_noise( 512, 512, 7 ), "$dir/fx-site-icon.png" );
    $img['siteicon'] = et_add_attachment( "$dir/fx-site-icon.png", 'fx site icon' );
    $jpg( 'header', 'fx-header.jpg', 1200, 400, 8, 'fx header' );
    $jpg( 'elementor', 'fx-elementor.jpg', 800, 500, 9, 'fx elementor', 'Elementor' );
    $jpg( 'acf_id', 'fx-acf-id.jpg', 800, 500, 10, 'fx acf id', 'ACF id' );
    $jpg( 'acf_url', 'fx-acf-url.jpg', 800, 500, 11, 'fx acf url', 'ACF url' );
    $jpg( 'woo_gallery', 'fx-woo-gallery.jpg', 800, 800, 12, 'fx woo gallery', 'Woo' );
    $jpg( 'widget_media', 'fx-widget-media.jpg', 600, 400, 13, 'fx widget media', 'Widget' );
    $jpg( 'widget_block', 'fx-widget-block.jpg', 600, 400, 14, 'fx widget block', 'Widget block' );
    $jpg( 'serialized', 'fx-serialized.jpg', 800, 500, 15, 'fx serialized', 'Serialized' );
    $jpg( 'draft_only', 'fx-draft-only.jpg', 600, 400, 16, 'fx draft only', 'Draft' );
    $jpg( 'trash_only', 'fx-trash-only.jpg', 600, 400, 17, 'fx trash only', 'Trash' );
    $jpg( 'good', 'blue-running-shoes.jpg', 1200, 800, 18, 'Blue running shoes', 'Blue running shoes on a wooden floor' );
    $jpg( 'dup_a', 'fx-duplicate-a.jpg', 700, 500, 19, 'fx duplicate a', 'Duplicate' );
    copy( "$dir/fx-duplicate-a.jpg", "$dir/fx-duplicate-b.jpg" );
    $img['dup_b'] = et_add_attachment( "$dir/fx-duplicate-b.jpg", 'fx duplicate b', 'Duplicate' );
    $jpg( 'camera', 'IMG_1234.JPG', 1000, 750, 20, 'IMG_1234' );
    $jpg( 'dscalt', 'dsc-0042.jpg', 1000, 750, 21, 'dsc-0042', 'Dsc 0042' );
    imagepng( et_noise( 600, 400, 22, true ), "$dir/fx-alpha.png" );
    $img['alpha'] = et_add_attachment( "$dir/fx-alpha.png", 'fx alpha', 'Transparent' );
    imagegif( et_noise( 300, 200, 23 ), "$dir/fx-static.gif" );
    $img['gif_static'] = et_add_attachment( "$dir/fx-static.gif", 'fx static gif', 'Static gif' );
    $jpg( 'oversized', 'fx-oversized.jpg', 3000, 2000, 24, 'fx oversized', 'Oversized' );
    $jpg( 'unused', 'fx-truly-unused.jpg', 800, 500, 25, 'fx truly unused', 'Unused' );
    $jpg( 'term_thumb', 'fx-term-thumb.jpg', 600, 600, 28, 'fx term thumb', 'Category' );
    $jpg( 'css_bg', 'fx-css-background.jpg', 1200, 400, 30, 'fx css background', '' );

    $post = array();

    $post['classic'] = et_add_post( 'FX Classic', '<p>Classic.</p><img src="' . esc_url( wp_get_attachment_url( $img['badname'] ) ) . '" alt="">'
        . '<p><img class="wp-image-' . $img['sized'] . ' size-medium" src="' . esc_url( wp_get_attachment_image_url( $img['sized'], 'medium' ) ) . '" width="300" height="210" alt=""></p>' );
    $post['block'] = et_add_post( 'FX Block', et_block_image( $img['block'] ) . et_block_image( $img['anim'], 'full' ) );
    $post['featured'] = et_add_post( 'FX Featured', '<p>Featured image test.</p>' );
    set_post_thumbnail( $post['featured'], $img['featured'] );

    $post['elementor'] = et_add_post( 'FX Elementor', '' );
    $el = array( array( 'elType' => 'widget', 'widgetType' => 'image', 'settings' => array( 'image' => array( 'url' => wp_get_attachment_url( $img['elementor'] ), 'id' => $img['elementor'] ) ) ) );
    update_post_meta( $post['elementor'], '_elementor_data', wp_slash( wp_json_encode( $el ) ) ); // keeps the "http:\/\/" escaping Elementor stores

    $post['acf'] = et_add_post( 'FX ACF', '<p>ACF.</p>' );
    update_post_meta( $post['acf'], 'hero_image', $img['acf_id'] );
    update_post_meta( $post['acf'], '_hero_image', 'field_6512a1b2c3d4e' );
    update_post_meta( $post['acf'], 'hero_bg', wp_get_attachment_url( $img['acf_url'] ) );
    update_post_meta( $post['acf'], '_hero_bg', 'field_6512a1b2c3d4f' );

    $post['woo'] = et_add_post( 'FX Woo', '<p>Product.</p>' );
    update_post_meta( $post['woo'], '_product_image_gallery', $img['woo_gallery'] . ',' . $img['good'] );

    $post['serialized'] = et_add_post( 'FX Serialized', '<p>Slider.</p>' );
    update_post_meta( $post['serialized'], 'fx_slider', array( 'slides' => array( array( 'img' => wp_get_attachment_url( $img['serialized'] ), 'caption' => 'Çağrı ünïcode' ) ) ) );

    $post['draft'] = et_add_post( 'FX Draft', et_block_image( $img['draft_only'] ), 'draft' );
    $post['trash'] = et_add_post( 'FX Trash', et_block_image( $img['trash_only'] ) );
    wp_trash_post( $post['trash'] );

    $post['gallery'] = et_add_post( 'FX Gallery', '<p>Gallery.</p>[gallery]' );
    $jpg( 'gallery_child', 'fx-gallery-child.jpg', 600, 400, 26, 'fx gallery child', 'Gallery child', $post['gallery'] );
    $post['parent'] = et_add_post( 'FX Parent', '<p>Image was removed from this post.</p>' );
    $jpg( 'attached_only', 'fx-attached-only.jpg', 600, 400, 27, 'fx attached only', 'Attached only', $post['parent'] );

    $post['good'] = et_add_post( 'FX Good', et_block_image( $img['good'] ) . '<p>Shoes.</p>' . et_block_image( $img['dup_a'] ) );
    $post['misc'] = et_add_post( 'FX Misc', et_block_image( $img['camera'] ) . et_block_image( $img['dscalt'] ) . et_block_image( $img['alpha'] ) . et_block_image( $img['gif_static'], 'full' ) . et_block_image( $img['oversized'] ) );

    $term = wp_insert_term( 'FX Category ' . wp_rand(), 'category' );
    if ( ! is_wp_error( $term ) ) {
        add_term_meta( $term['term_id'], ET_FIXTURE_META, 1 );
        add_term_meta( $term['term_id'], 'thumbnail_id', $img['term_thumb'] );
    }
    $post['css'] = et_add_post( 'fx-css', 'body{background:url(' . wp_get_attachment_url( $img['css_bg'] ) . ') no-repeat}', 'publish', 'custom_css' );

    // Site-level references.
    set_theme_mod( 'custom_logo', $img['logo'] );
    set_theme_mod( 'header_image', wp_get_attachment_url( $img['header'] ) );
    set_theme_mod( 'header_image_data', (object) array( 'attachment_id' => $img['header'], 'url' => wp_get_attachment_url( $img['header'] ), 'width' => 1200, 'height' => 400 ) );
    update_option( 'site_icon', $img['siteicon'] );
    update_option( 'widget_media_image', array( 3 => array( 'attachment_id' => $img['widget_media'], 'url' => wp_get_attachment_url( $img['widget_media'] ), 'size' => 'full' ), '_multiwidget' => 1 ) );
    update_option( 'widget_block', array( 4 => array( 'content' => et_block_image( $img['widget_block'] ) ), '_multiwidget' => 1 ) );

    $m = array( 'img' => $img, 'post' => $post, 'dir' => $dir );
    file_put_contents( dirname( __DIR__ ) . '/.last-manifest.json', wp_json_encode( $m, JSON_PRETTY_PRINT ) ); // phpcs:ignore
    return $m;
}

/** Removes every fixture post/attachment and the per-run upload folders. */
function et_cleanup_fixtures() {
    $ids = get_posts( array(
        'post_type'      => 'any',
        'post_status'    => array_keys( get_post_stati() ),
        'posts_per_page' => -1,
        'meta_key'       => ET_FIXTURE_META, // phpcs:ignore WordPress.DB.SlowDBQuery
        'fields'         => 'ids',
    ) );
    // 'any' skips attachments' "inherit" in some versions — query them explicitly too.
    $ids = array_merge( $ids, get_posts( array(
        'post_type'      => 'attachment',
        'post_status'    => array( 'inherit', 'private', 'trash', 'erdo_quarantine' ),
        'posts_per_page' => -1,
        'meta_key'       => ET_FIXTURE_META, // phpcs:ignore WordPress.DB.SlowDBQuery
        'fields'         => 'ids',
    ) ) );
    foreach ( array_unique( $ids ) as $id ) {
        if ( function_exists( 'et_unquarantine' ) && 'erdo_quarantine' === get_post_status( $id ) ) {
            et_unquarantine( $id ); // put files back so WordPress can delete them
        }
        wp_delete_post( $id, true );
    }
    foreach ( get_terms( array( 'taxonomy' => 'category', 'hide_empty' => false, 'meta_key' => ET_FIXTURE_META, 'fields' => 'ids' ) ) as $tid ) { // phpcs:ignore WordPress.DB.SlowDBQuery
        wp_delete_term( $tid, 'category' );
    }
    foreach ( (array) get_option( ET_FIXTURE_DIRS, array() ) as $dir ) {
        et_rmdir( $dir );
    }
    delete_option( ET_FIXTURE_DIRS );
}

function et_rmdir( $dir ) {
    if ( ! is_dir( $dir ) ) {
        return;
    }
    $it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
    foreach ( $it as $f ) {
        @( $f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() ) ); // phpcs:ignore
    }
    @rmdir( $dir ); // phpcs:ignore
}

/**
 * Puts an attachment into the state Erdo 2.x's destructive conversion left
 * behind — independent of plugin code, so it keeps working after 3.0 removes
 * that path: every rendition re-encoded to WebP, the JPEG/PNG files deleted,
 * the attachment pointing at the .webp, while post content still holds the
 * old URLs. Used by the rescue-filter and 2.x → 3.0 migration tests.
 */
function et_simulate_legacy_conversion( $id, $with_backup = false ) {
    $files = et_attachment_files( $id );
    if ( $with_backup ) { // 2.x "Keep Original Backup" setting
        $backup_dir = dirname( $files[0] ) . '/_erdo_backup';
        wp_mkdir_p( $backup_dir );
        copy( $files[0], $backup_dir . '/' . basename( $files[0] ) );
        update_post_meta( $id, '_erdo_backup_path', $backup_dir . '/' . basename( $files[0] ) );
    }
    foreach ( $files as $f ) {
        $editor = wp_get_image_editor( $f );
        if ( is_wp_error( $editor ) ) {
            continue;
        }
        $editor->save( preg_replace( '/\.[^.]+$/', '.webp', $f ), 'image/webp' );
        wp_delete_file( $f );
    }
    $main = preg_replace( '/\.[^.]+$/', '.webp', get_attached_file( $id ) );
    update_attached_file( $id, $main );
    wp_update_post( array( 'ID' => $id, 'post_mime_type' => 'image/webp' ) );
    $meta         = wp_get_attachment_metadata( $id );
    $meta['file'] = preg_replace( '/\.[^.]+$/', '.webp', $meta['file'] );
    foreach ( (array) ( $meta['sizes'] ?? array() ) as $k => $s ) {
        $meta['sizes'][ $k ]['file']      = preg_replace( '/\.[^.]+$/', '.webp', $s['file'] );
        $meta['sizes'][ $k ]['mime-type'] = 'image/webp';
    }
    unset( $meta['original_image'] );
    wp_update_attachment_metadata( $id, $meta );
    update_post_meta( $id, '_erdo_savings_bytes', 1 ); // 2.x marker
}

/**
 * Post content straight from the database. The plugin updates content with
 * direct SQL, so the cached WP_Post would hide whether the update happened.
 */
function et_post_content( $post_id ) {
    clean_post_cache( $post_id );
    return get_post( $post_id )->post_content;
}

/** Files actually served on the site: main file + generated sizes (not the pre-"-scaled" original). */
function et_served_files( $id ) {
    $meta = wp_get_attachment_metadata( $id );
    $skip = empty( $meta['original_image'] ) ? '' : dirname( get_attached_file( $id ) ) . '/' . $meta['original_image'];
    return array_values( array_filter( et_attachment_files( $id ), function ( $f ) use ( $skip ) {
        return $f !== $skip;
    } ) );
}

/** Absolute paths of every file WordPress knows for an attachment. */
function et_attachment_files( $id ) {
    $file  = get_attached_file( $id );
    $dir   = dirname( $file );
    $meta  = wp_get_attachment_metadata( $id );
    $files = array( $file );
    if ( ! empty( $meta['original_image'] ) ) {
        $files[] = $dir . '/' . $meta['original_image'];
    }
    foreach ( (array) ( $meta['sizes'] ?? array() ) as $s ) {
        $files[] = $dir . '/' . $s['file'];
    }
    return array_values( array_unique( $files ) );
}

/** URLs of every rendition (full + sizes) — used to check pages and redirects. */
function et_attachment_urls( $id ) {
    $base = trailingslashit( dirname( wp_get_attachment_url( $id ) ) );
    $urls = array();
    foreach ( et_attachment_files( $id ) as $f ) {
        $urls[] = $base . rawurlencode( basename( $f ) );
    }
    return $urls;
}
