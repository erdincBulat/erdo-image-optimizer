<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class ERDO_Admin_Page {

    public function __construct() {
        add_action( 'admin_menu',             array( $this, 'create_menu' ) );
        add_action( 'admin_enqueue_scripts',  array( $this, 'assets' ) );

        add_action( 'wp_ajax_erdo_get_detail',        array( $this, 'ajax_get_detail' ) );
        add_action( 'wp_ajax_erdo_save_data',         array( $this, 'ajax_save_data' ) );
        add_action( 'wp_ajax_erdo_convert_webp',      array( $this, 'ajax_convert_webp' ) );
        add_action( 'wp_ajax_erdo_refresh_all',       array( $this, 'ajax_refresh_all' ) );
        add_action( 'wp_ajax_erdo_delete_image',      array( $this, 'ajax_delete_image' ) );
        add_action( 'wp_ajax_erdo_get_unused_list',   array( $this, 'ajax_get_unused_list' ) );
        add_action( 'wp_ajax_erdo_save_settings',     array( $this, 'ajax_save_settings' ) );
        add_action( 'wp_ajax_erdo_manual_resize',     array( $this, 'ajax_manual_resize' ) );
        add_action( 'wp_ajax_erdo_restore_size',      array( $this, 'ajax_restore_size' ) );
        add_action( 'wp_ajax_erdo_load_images',       array( $this, 'ajax_load_images' ) );
        add_action( 'wp_ajax_erdo_get_stats',         array( $this, 'ajax_get_stats' ) );
        add_action( 'wp_ajax_erdo_scan_thumbnails',   array( $this, 'ajax_scan_thumbnails' ) );
        add_action( 'wp_ajax_erdo_restore_original',  array( $this, 'ajax_restore_original' ) );
        add_action( 'wp_ajax_erdo_start_queue',       array( $this, 'ajax_start_queue' ) );
        add_action( 'wp_ajax_erdo_cancel_queue',      array( $this, 'ajax_cancel_queue' ) );
        add_action( 'wp_ajax_erdo_queue_status',      array( $this, 'ajax_queue_status' ) );
        add_action( 'wp_ajax_erdo_queue_undo',        array( $this, 'ajax_queue_undo' ) );
        add_action( 'wp_ajax_erdo_rename_file',       array( $this, 'ajax_rename_file' ) );
        add_action( 'wp_ajax_erdo_export_csv',        array( $this, 'ajax_export_csv' ) );
        add_action( 'wp_ajax_erdo_bulk_alt_text',     array( $this, 'ajax_bulk_alt_text' ) );
        add_action( 'wp_ajax_erdo_migrate_scan',      array( $this, 'ajax_migrate_scan' ) );
        add_action( 'wp_ajax_erdo_migrate_step',      array( $this, 'ajax_migrate_step' ) );
        add_action( 'wp_ajax_erdo_quarantine_unused', array( $this, 'ajax_quarantine_unused' ) );
        add_action( 'wp_ajax_erdo_quarantine_list',   array( $this, 'ajax_quarantine_list' ) );
        add_action( 'wp_ajax_erdo_quarantine_restore', array( $this, 'ajax_quarantine_restore' ) );
        add_action( 'wp_ajax_erdo_quarantine_purge',  array( $this, 'ajax_quarantine_purge' ) );
        add_action( 'wp_ajax_erdo_usage_build_step',  array( $this, 'ajax_usage_build_step' ) );
        add_action( 'wp_ajax_erdo_rename_preview',    array( $this, 'ajax_rename_preview' ) );
        add_action( 'wp_ajax_erdo_rename_apply',      array( $this, 'ajax_rename_apply' ) );
        add_action( 'wp_ajax_erdo_rename_undo',       array( $this, 'ajax_rename_undo' ) );
        add_action( 'wp_ajax_erdo_rename_undo_last',  array( $this, 'ajax_rename_undo_last' ) );
        add_action( 'wp_ajax_erdo_ai_suggest',        array( $this, 'ajax_ai_suggest' ) );
        add_action( 'wp_ajax_erdo_ai_accept',         array( $this, 'ajax_ai_accept' ) );
        add_action( 'wp_ajax_erdo_ai_reject',         array( $this, 'ajax_ai_reject' ) );
        add_action( 'wp_ajax_erdo_ai_list',           array( $this, 'ajax_ai_list' ) );
        add_action( 'wp_ajax_erdo_ai_candidates',     array( $this, 'ajax_ai_candidates' ) );
        add_action( 'wp_ajax_erdo_setup_check',       array( $this, 'ajax_setup_check' ) );
        add_action( 'wp_ajax_erdo_setup_apply',       array( $this, 'ajax_setup_apply' ) );
        add_action( 'admin_notices',                  array( $this, 'migration_notice' ) );
        add_action( 'admin_notices',                  array( $this, 'upgrade_notice' ) );
        add_action( 'wp_ajax_erdo_dismiss_upgrade',   array( $this, 'ajax_dismiss_upgrade' ) );

        add_action( 'wp_dashboard_setup', array( $this, 'register_dashboard_widget' ) );
        add_action( 'rest_api_init',      array( $this, 'register_rest_routes' ) );

        add_filter( 'plugin_action_links_erdo-image-optimizer/erdo-image-optimizer.php', array( $this, 'add_settings_link' ) );
    }

    /* ------------------------------------------------------------------
       GÜVENLIK: Yalnızca capability kontrolü yapar.
       Nonce doğrulaması PHPCS uyumluluğu için her metodun içinde
       check_ajax_referer() ile ayrı ayrı yapılır.
    ------------------------------------------------------------------ */
    private function verify_capability() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'erdo-image-optimizer' ) ), 403 );
        }
    }

    /* ------------------------------------------------------------------
       ADMIN MENÜ & ASSETS
    ------------------------------------------------------------------ */
    public function add_settings_link( $links ) {
        $settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=erdo-image-optimizer' ) ) . '">' . esc_html__( 'Settings', 'erdo-image-optimizer' ) . '</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }

    public function create_menu() {
        add_menu_page(
            __( 'Erdo Image Optimizer', 'erdo-image-optimizer' ),
            __( 'Image Optimizer', 'erdo-image-optimizer' ),
            'manage_options',
            'erdo-image-optimizer',
            array( $this, 'render_page' ),
            'dashicons-performance',
            11
        );
    }

    public function assets( $hook ) {
        if ( 'toplevel_page_erdo-image-optimizer' !== $hook ) {
            return;
        }
        wp_enqueue_media();
        $ver = ERDO_VERSION;
        wp_enqueue_style(  'erdo-css', ERDO_URL . 'assets/css/admin.css', array(), $ver );
        wp_enqueue_script( 'erdo-js',  ERDO_URL . 'assets/js/admin.js',  array( 'jquery' ), $ver, true );
        wp_localize_script( 'erdo-js', 'erdo_vars', array(
            'ajax_url'  => admin_url( 'admin-ajax.php' ),
            'page_url'  => admin_url( 'admin.php?page=erdo-image-optimizer' ),
            'nonce'     => wp_create_nonce( 'erdo_nonce' ),
            'i18n'      => array( 's' => $this->js_strings() ),
            'ai'        => ERDO_AI::status(),
            'format'    => strtoupper( get_option( 'erdo_convert_format', 'webp' ) ),
            'activity'  => ERDO_Activity::for_display(),
            'queue'     => ERDO_Queue::get_status(),
        ) );
    }

    /**
     * admin.js metinleri: İngilizce kaynak metin → çeviri. JS bunları t('…') ile
     * okur; metinler burada __() içinde olduğu için .pot dosyasına da girer.
     */
    private function js_strings() {
        return array(
            /* translators: placeholders are filled in by the script in this order (numbers or names) */
            '%1$d / %2$d images scanned' => __( '%1$d / %2$d images scanned', 'erdo-image-optimizer' ),
            /* translators: placeholders are filled in by the script in this order (numbers or names) */
            '%1$d image · %2$s' => __( '%1$d image · %2$s', 'erdo-image-optimizer' ),
            /* translators: placeholders are filled in by the script in this order (numbers or names) */
            '%1$d images · %2$s' => __( '%1$d images · %2$s', 'erdo-image-optimizer' ),
            /* translators: placeholders are filled in by the script in this order (numbers or names) */
            '%1$d images. Each image is sent once, resized to 1024 px. Estimated provider cost: about $%2$s–$%3$s with a small vision model.' => __( '%1$d images. Each image is sent once, resized to 1024 px. Estimated provider cost: about $%2$s–$%3$s with a small vision model.', 'erdo-image-optimizer' ),
            /* translators: placeholders are filled in by the script in this order (numbers or names) */
            '%1$d of %2$d' => __( '%1$d of %2$d', 'erdo-image-optimizer' ),
            /* translators: placeholders are filled in by the script in this order (numbers or names) */
            '%1$d of %2$d images' => __( '%1$d of %2$d images', 'erdo-image-optimizer' ),
            /* translators: %d: number */
            '%d image' => __( '%d image', 'erdo-image-optimizer' ),
            /* translators: %d: number */
            '%d image below 50' => __( '%d image below 50', 'erdo-image-optimizer' ),
            /* translators: %d: number */
            '%d images' => __( '%d images', 'erdo-image-optimizer' ),
            /* translators: %d: number */
            '%d images below 50' => __( '%d images below 50', 'erdo-image-optimizer' ),
            /* translators: %d: number */
            '%d in quarantine' => __( '%d in quarantine', 'erdo-image-optimizer' ),
            /* translators: %d: number */
            '%d published post has no featured image:' => __( '%d published post has no featured image:', 'erdo-image-optimizer' ),
            /* translators: %d: number */
            '%d published posts have no featured image (first 50 shown):' => __( '%d published posts have no featured image (first 50 shown):', 'erdo-image-optimizer' ),
            /* translators: %d: number */
            '%d selected' => __( '%d selected', 'erdo-image-optimizer' ),
            /* translators: %d: number */
            '%d skipped (copy was larger)' => __( '%d skipped (copy was larger)', 'erdo-image-optimizer' ),
            /* translators: %d: number */
            '%d suggestion waiting for review' => __( '%d suggestion waiting for review', 'erdo-image-optimizer' ),
            /* translators: %d: number */
            '%d suggestions waiting for review' => __( '%d suggestions waiting for review', 'erdo-image-optimizer' ),
            'Accept' => __( 'Accept', 'erdo-image-optimizer' ),
            /* translators: %d: number */
            'Accept checked (%d fields)' => __( 'Accept checked (%d fields)', 'erdo-image-optimizer' ),
            /* translators: %d: number */
            'Add alt text to %d image' => __( 'Add alt text to %d image', 'erdo-image-optimizer' ),
            /* translators: %d: number */
            'Add alt text to %d images' => __( 'Add alt text to %d images', 'erdo-image-optimizer' ),
            'AI suggestions' => __( 'AI suggestions', 'erdo-image-optimizer' ),
            'All file names are already clean.' => __( 'All file names are already clean.', 'erdo-image-optimizer' ),
            'All images already have WebP/AVIF copies.' => __( 'All images already have WebP/AVIF copies.', 'erdo-image-optimizer' ),
            'All sizes and WebP/AVIF copies move with the file, links on the site are updated and old URLs redirect (301). You can undo this afterwards.' => __( 'All sizes and WebP/AVIF copies move with the file, links on the site are updated and old URLs redirect (301). You can undo this afterwards.', 'erdo-image-optimizer' ),
            'Alt text' => __( 'Alt text', 'erdo-image-optimizer' ),
            'Alt text from file names' => __( 'Alt text from file names', 'erdo-image-optimizer' ),
            'Alt text is 40% of the score and what screen readers read aloud.' => __( 'Alt text is 40% of the score and what screen readers read aloud.', 'erdo-image-optimizer' ),
            'Apply' => __( 'Apply', 'erdo-image-optimizer' ),
            /* translators: %s: value filled in by the script (a number or a name) */
            'Apply %s' => __( 'Apply %s', 'erdo-image-optimizer' ),
            'Available when the usage index is ready.' => __( 'Available when the usage index is ready.', 'erdo-image-optimizer' ),
            'Before and after' => __( 'Before and after', 'erdo-image-optimizer' ),
            /* translators: placeholders are filled in by the script in this order (numbers or names) */
            'Building the usage index: %1$d / %2$d posts checked. Unused-image cleanup becomes available when it finishes.' => __( 'Building the usage index: %1$d / %2$d posts checked. Unused-image cleanup becomes available when it finishes.', 'erdo-image-optimizer' ),
            'Bulk actions you run here will show up in this list.' => __( 'Bulk actions you run here will show up in this list.', 'erdo-image-optimizer' ),
            'Weak file name' => __( 'Weak file name', 'erdo-image-optimizer' ),
            'File names to improve' => __( 'File names to improve', 'erdo-image-optimizer' ),
            'Can get alt text from the name' => __( 'Can get alt text from the name', 'erdo-image-optimizer' ),
            'Cancel' => __( 'Cancel', 'erdo-image-optimizer' ),
            'Checking…' => __( 'Checking…', 'erdo-image-optimizer' ),
            'Clears the cached results and checks every image again. Nothing on your site changes.' => __( 'Clears the cached results and checks every image again. Nothing on your site changes.', 'erdo-image-optimizer' ),
            'Close' => __( 'Close', 'erdo-image-optimizer' ),
            'Connect an AI provider to get suggestions.' => __( 'Connect an AI provider to get suggestions.', 'erdo-image-optimizer' ),
            'Continue' => __( 'Continue', 'erdo-image-optimizer' ),
            'Convert' => __( 'Convert', 'erdo-image-optimizer' ),
            'Convert in background' => __( 'Convert in background', 'erdo-image-optimizer' ),
            /* translators: %d: number */
            'Converting %d image' => __( 'Converting %d image', 'erdo-image-optimizer' ),
            /* translators: %d: number */
            'Converting %d images' => __( 'Converting %d images', 'erdo-image-optimizer' ),
            'Converting...' => __( 'Converting...', 'erdo-image-optimizer' ),
            'Copies kept' => __( 'Copies kept', 'erdo-image-optimizer' ),
            /* translators: %d: number */
            'Copies removed for %d image.' => __( 'Copies removed for %d image.', 'erdo-image-optimizer' ),
            /* translators: %d: number */
            'Copies removed for %d images.' => __( 'Copies removed for %d images.', 'erdo-image-optimizer' ),
            'Copies removed.' => __( 'Copies removed.', 'erdo-image-optimizer' ),
            'Copy created.' => __( 'Copy created.', 'erdo-image-optimizer' ),
            'Could not delete the image' => __( 'Could not delete the image', 'erdo-image-optimizer' ),
            'Could not move the image' => __( 'Could not move the image', 'erdo-image-optimizer' ),
            'Could not restore the image' => __( 'Could not restore the image', 'erdo-image-optimizer' ),
            'Could not undo' => __( 'Could not undo', 'erdo-image-optimizer' ),
            /* translators: placeholders are filled in by the script in this order (numbers or names) */
            'Create %1$s copies for %2$d image?' => __( 'Create %1$s copies for %2$d image?', 'erdo-image-optimizer' ),
            /* translators: placeholders are filled in by the script in this order (numbers or names) */
            'Create %1$s copies for %2$d images?' => __( 'Create %1$s copies for %2$d images?', 'erdo-image-optimizer' ),
            'Create WebP/AVIF copy' => __( 'Create WebP/AVIF copy', 'erdo-image-optimizer' ),
            /* translators: placeholders are filled in by the script in this order (numbers or names) */
            'Creating WebP/AVIF copies in the background: %1$d of %2$d' => __( 'Creating WebP/AVIF copies in the background: %1$d of %2$d', 'erdo-image-optimizer' ),
            'Current' => __( 'Current', 'erdo-image-optimizer' ),
            'Decorative image: alt stays empty on purpose.' => __( 'Decorative image: alt stays empty on purpose.', 'erdo-image-optimizer' ),
            'Delete permanently' => __( 'Delete permanently', 'erdo-image-optimizer' ),
            'Delete this image permanently?' => __( 'Delete this image permanently?', 'erdo-image-optimizer' ),
            'Dismiss' => __( 'Dismiss', 'erdo-image-optimizer' ),
            'Done' => __( 'Done', 'erdo-image-optimizer' ),
            /* translators: %d: number */
            'Done — %d image converted' => __( 'Done — %d image converted', 'erdo-image-optimizer' ),
            /* translators: %d: number */
            'Done — %d image moved to quarantine' => __( 'Done — %d image moved to quarantine', 'erdo-image-optimizer' ),
            /* translators: %d: number */
            'Done — %d image renamed' => __( 'Done — %d image renamed', 'erdo-image-optimizer' ),
            /* translators: %d: number */
            'Done — %d image updated' => __( 'Done — %d image updated', 'erdo-image-optimizer' ),
            /* translators: %d: number */
            'Done — %d images converted' => __( 'Done — %d images converted', 'erdo-image-optimizer' ),
            /* translators: %d: number */
            'Done — %d images moved to quarantine' => __( 'Done — %d images moved to quarantine', 'erdo-image-optimizer' ),
            /* translators: %d: number */
            'Done — %d images renamed' => __( 'Done — %d images renamed', 'erdo-image-optimizer' ),
            /* translators: %d: number */
            'Done — %d images updated' => __( 'Done — %d images updated', 'erdo-image-optimizer' ),
            /* translators: %d: number */
            'Done — %d suggestion ready for review' => __( 'Done — %d suggestion ready for review', 'erdo-image-optimizer' ),
            /* translators: %d: number */
            'Done — %d suggestions ready for review' => __( 'Done — %d suggestions ready for review', 'erdo-image-optimizer' ),
            'Each image is checked again first: if it is used, or its file name still appears anywhere on the site, it is skipped. Images used only in theme files or on other websites cannot be detected.' => __( 'Each image is checked again first: if it is used, or its file name still appears anywhere on the site, it is skipped. Images used only in theme files or on other websites cannot be detected.', 'erdo-image-optimizer' ),
            'Enter a size smaller than the current one.' => __( 'Enter a size smaller than the current one.', 'erdo-image-optimizer' ),
            'Enter a width and a height.' => __( 'Enter a width and a height.', 'erdo-image-optimizer' ),
            'Every published post has a featured image.' => __( 'Every published post has a featured image.', 'erdo-image-optimizer' ),
            'Failed' => __( 'Failed', 'erdo-image-optimizer' ),
            'Field' => __( 'Field', 'erdo-image-optimizer' ),
            'File name' => __( 'File name', 'erdo-image-optimizer' ),
            /* translators: placeholders are filled in by the script in this order (numbers or names) */
            'Finding where each image is used: %1$d / %2$d posts checked.' => __( 'Finding where each image is used: %1$d / %2$d posts checked.', 'erdo-image-optimizer' ),
            'Fix file names' => __( 'Fix file names', 'erdo-image-optimizer' ),
            'Fix file name…' => __( 'Fix file name…', 'erdo-image-optimizer' ),
            'For better descriptions, use AI suggestions instead: they look at the image itself.' => __( 'For better descriptions, use AI suggestions instead: they look at the image itself.', 'erdo-image-optimizer' ),
            'From file names' => __( 'From file names', 'erdo-image-optimizer' ),
            'Generate suggestions' => __( 'Generate suggestions', 'erdo-image-optimizer' ),
            'Generating suggestions' => __( 'Generating suggestions', 'erdo-image-optimizer' ),
            'Image health: fair' => __( 'Image health: fair', 'erdo-image-optimizer' ),
            'Image health: good' => __( 'Image health: good', 'erdo-image-optimizer' ),
            'Image health: poor' => __( 'Image health: poor', 'erdo-image-optimizer' ),
            'Images' => __( 'Images', 'erdo-image-optimizer' ),
            'Images missing alt text' => __( 'Images missing alt text', 'erdo-image-optimizer' ),
            'Images with missing or weak alt text' => __( 'Images with missing or weak alt text', 'erdo-image-optimizer' ),
            'Images with weak alt text' => __( 'Images with weak alt text', 'erdo-image-optimizer' ),
            'Images without a WebP/AVIF copy' => __( 'Images without a WebP/AVIF copy', 'erdo-image-optimizer' ),
            'Images without alt text' => __( 'Images without alt text', 'erdo-image-optimizer' ),
            'Images without alt text get one made from their file name, for example "red-tulip-field.jpg" becomes "Red tulip field". Camera-style names such as IMG_1234 are skipped.' => __( 'Images without alt text get one made from their file name, for example "red-tulip-field.jpg" becomes "Red tulip field". Camera-style names such as IMG_1234 are skipped.', 'erdo-image-optimizer' ),
            'It disappears from your site and media library, but nothing is deleted. You can restore it in one click from Cleanup → Quarantine.' => __( 'It disappears from your site and media library, but nothing is deleted. You can restore it in one click from Cleanup → Quarantine.', 'erdo-image-optimizer' ),
            'Keyword' => __( 'Keyword', 'erdo-image-optimizer' ),
            'Larger than 2560 px or heavier than 250 KB when served.' => __( 'Larger than 2560 px or heavier than 250 KB when served.', 'erdo-image-optimizer' ),
            'Links are updated and the old URL redirects (301).' => __( 'Links are updated and the old URL redirects (301).', 'erdo-image-optimizer' ),
            'Loading...' => __( 'Loading...', 'erdo-image-optimizer' ),
            'Looking at the image…' => __( 'Looking at the image…', 'erdo-image-optimizer' ),
            'Missing' => __( 'Missing', 'erdo-image-optimizer' ),
            'Missing or weak alt text' => __( 'Missing or weak alt text', 'erdo-image-optimizer' ),
            'Move' => __( 'Move', 'erdo-image-optimizer' ),
            /* translators: %d: number */
            'Move %d image to quarantine?' => __( 'Move %d image to quarantine?', 'erdo-image-optimizer' ),
            /* translators: %d: number */
            'Move %d images to quarantine?' => __( 'Move %d images to quarantine?', 'erdo-image-optimizer' ),
            /* translators: %d: number */
            'Move %d to quarantine…' => __( 'Move %d to quarantine…', 'erdo-image-optimizer' ),
            'Move this image to quarantine?' => __( 'Move this image to quarantine?', 'erdo-image-optimizer' ),
            'Move to quarantine' => __( 'Move to quarantine', 'erdo-image-optimizer' ),
            'Move to quarantine…' => __( 'Move to quarantine…', 'erdo-image-optimizer' ),
            'Move unused images to quarantine' => __( 'Move unused images to quarantine', 'erdo-image-optimizer' ),
            'Moved' => __( 'Moved', 'erdo-image-optimizer' ),
            'Moved to quarantine' => __( 'Moved to quarantine', 'erdo-image-optimizer' ),
            'Moving images to quarantine' => __( 'Moving images to quarantine', 'erdo-image-optimizer' ),
            'Moving…' => __( 'Moving…', 'erdo-image-optimizer' ),
            'New name' => __( 'New name', 'erdo-image-optimizer' ),
            'No alt' => __( 'No alt', 'erdo-image-optimizer' ),
            'No images need suggestions right now.' => __( 'No images need suggestions right now.', 'erdo-image-optimizer' ),
            'No suggestions waiting for review' => __( 'No suggestions waiting for review', 'erdo-image-optimizer' ),
            'No unused images found.' => __( 'No unused images found.', 'erdo-image-optimizer' ),
            'No WebP' => __( 'No WebP', 'erdo-image-optimizer' ),
            'Not found in content, settings, widgets or terms.' => __( 'Not found in content, settings, widgets or terms.', 'erdo-image-optimizer' ),
            'Not indexable' => __( 'Not indexable', 'erdo-image-optimizer' ),
            'Nothing has changed yet.' => __( 'Nothing has changed yet.', 'erdo-image-optimizer' ),
            'Nothing is saved until you review it in the AI Suggestions tab.' => __( 'Nothing is saved until you review it in the AI Suggestions tab.', 'erdo-image-optimizer' ),
            'Nothing to convert' => __( 'Nothing to convert', 'erdo-image-optimizer' ),
            'Nothing to fix right now. New uploads are checked automatically.' => __( 'Nothing to fix right now. New uploads are checked automatically.', 'erdo-image-optimizer' ),
            'Open Connectors' => __( 'Open Connectors', 'erdo-image-optimizer' ),
            'Open details' => __( 'Open details', 'erdo-image-optimizer' ),
            'Open quarantine' => __( 'Open quarantine', 'erdo-image-optimizer' ),
            'Original' => __( 'Original', 'erdo-image-optimizer' ),
            'Original size restored.' => __( 'Original size restored.', 'erdo-image-optimizer' ),
            'Originals stay untouched; copies are kept only when they are smaller.' => __( 'Originals stay untouched; copies are kept only when they are smaller.', 'erdo-image-optimizer' ),
            'Oversized' => __( 'Oversized', 'erdo-image-optimizer' ),
            'Oversized images' => __( 'Oversized images', 'erdo-image-optimizer' ),
            'Page focus keyword' => __( 'Page focus keyword', 'erdo-image-optimizer' ),
            'Pattern' => __( 'Pattern', 'erdo-image-optimizer' ),
            'Posts without a featured image' => __( 'Posts without a featured image', 'erdo-image-optimizer' ),
            'Preview' => __( 'Preview', 'erdo-image-optimizer' ),
            'Preview new names' => __( 'Preview new names', 'erdo-image-optimizer' ),
            'Quarantine…' => __( 'Quarantine…', 'erdo-image-optimizer' ),
            'Ready for review' => __( 'Ready for review', 'erdo-image-optimizer' ),
            'Recalculate' => __( 'Recalculate', 'erdo-image-optimizer' ),
            'Recalculate all scores?' => __( 'Recalculate all scores?', 'erdo-image-optimizer' ),
            'Remove copies' => __( 'Remove copies', 'erdo-image-optimizer' ),
            'Remove the WebP/AVIF copies?' => __( 'Remove the WebP/AVIF copies?', 'erdo-image-optimizer' ),
            'Rename' => __( 'Rename', 'erdo-image-optimizer' ),
            /* translators: %d: number */
            'Rename %d image' => __( 'Rename %d image', 'erdo-image-optimizer' ),
            /* translators: %d: number */
            'Rename %d images' => __( 'Rename %d images', 'erdo-image-optimizer' ),
            'Renamed' => __( 'Renamed', 'erdo-image-optimizer' ),
            'Renaming undone.' => __( 'Renaming undone.', 'erdo-image-optimizer' ),
            /* translators: %d: number */
            'Renaming undone: %d image restored.' => __( 'Renaming undone: %d image restored.', 'erdo-image-optimizer' ),
            /* translators: %d: number */
            'Renaming undone: %d images restored.' => __( 'Renaming undone: %d images restored.', 'erdo-image-optimizer' ),
            'Renaming…' => __( 'Renaming…', 'erdo-image-optimizer' ),
            'Resized. The original is kept.' => __( 'Resized. The original is kept.', 'erdo-image-optimizer' ),
            'Resizing...' => __( 'Resizing...', 'erdo-image-optimizer' ),
            'Restore' => __( 'Restore', 'erdo-image-optimizer' ),
            'Review' => __( 'Review', 'erdo-image-optimizer' ),
            'Review and quarantine' => __( 'Review and quarantine', 'erdo-image-optimizer' ),
            'Review suggestions' => __( 'Review suggestions', 'erdo-image-optimizer' ),
            'Run in background' => __( 'Run in background', 'erdo-image-optimizer' ),
            'Saved.' => __( 'Saved.', 'erdo-image-optimizer' ),
            'Saving...' => __( 'Saving...', 'erdo-image-optimizer' ),
            /* translators: %d: number */
            'score %d' => __( 'score %d', 'erdo-image-optimizer' ),
            'Select image' => __( 'Select image', 'erdo-image-optimizer' ),
            'Served copy' => __( 'Served copy', 'erdo-image-optimizer' ),
            'Set up AI suggestions' => __( 'Set up AI suggestions', 'erdo-image-optimizer' ),
            /* translators: %d: number */
            'Show more (%d left)' => __( 'Show more (%d left)', 'erdo-image-optimizer' ),
            'Skipped' => __( 'Skipped', 'erdo-image-optimizer' ),
            'Skipped (copy was larger)' => __( 'Skipped (copy was larger)', 'erdo-image-optimizer' ),
            'Skipped (still in use)' => __( 'Skipped (still in use)', 'erdo-image-optimizer' ),
            'Something went wrong. Please try again.' => __( 'Something went wrong. Please try again.', 'erdo-image-optimizer' ),
            'Space in the library' => __( 'Space in the library', 'erdo-image-optimizer' ),
            'Start converting' => __( 'Start converting', 'erdo-image-optimizer' ),
            'Starting…' => __( 'Starting…', 'erdo-image-optimizer' ),
            'Stop' => __( 'Stop', 'erdo-image-optimizer' ),
            'Suggest' => __( 'Suggest', 'erdo-image-optimizer' ),
            'Suggest with AI' => __( 'Suggest with AI', 'erdo-image-optimizer' ),
            'Suggested' => __( 'Suggested', 'erdo-image-optimizer' ),
            'Suggested alt text' => __( 'Suggested alt text', 'erdo-image-optimizer' ),
            'Suggested file name' => __( 'Suggested file name', 'erdo-image-optimizer' ),
            'Suggested title' => __( 'Suggested title', 'erdo-image-optimizer' ),
            'Suggestion applied.' => __( 'Suggestion applied.', 'erdo-image-optimizer' ),
            'The conversion runs on the server. You can close this window or leave the page while it runs.' => __( 'The conversion runs on the server. You can close this window or leave the page while it runs.', 'erdo-image-optimizer' ),
            'The image and all its sizes and copies are deleted from the server. This cannot be undone.' => __( 'The image and all its sizes and copies are deleted from the server. This cannot be undone.', 'erdo-image-optimizer' ),
            'The original stays exactly as it is. You can create the copies again at any time.' => __( 'The original stays exactly as it is. You can create the copies again at any time.', 'erdo-image-optimizer' ),
            'They disappear from your site and media library, but nothing is deleted. You can restore any of them in one click from Cleanup → Quarantine.' => __( 'They disappear from your site and media library, but nothing is deleted. You can restore any of them in one click from Cleanup → Quarantine.', 'erdo-image-optimizer' ),
            'This image was still used on your site. Restore it from Cleanup → Quarantine if a page looks broken.' => __( 'This image was still used on your site. Restore it from Cleanup → Quarantine if a page looks broken.', 'erdo-image-optimizer' ),
            'Title' => __( 'Title', 'erdo-image-optimizer' ),
            'Tokens: {keyword} {n} {parent} {title} {original} {alt}. An empty keyword uses the focus keyword (Yoast, Rank Math, AIOSEO, SEOPress) or the title of the page the image is on.' => __( 'Tokens: {keyword} {n} {parent} {title} {original} {alt}. An empty keyword uses the focus keyword (Yoast, Rank Math, AIOSEO, SEOPress) or the title of the page the image is on.', 'erdo-image-optimizer' ),
            'Undo' => __( 'Undo', 'erdo-image-optimizer' ),
            'Undo (remove copies)' => __( 'Undo (remove copies)', 'erdo-image-optimizer' ),
            'Unsaved changes' => __( 'Unsaved changes', 'erdo-image-optimizer' ),
            'Unused' => __( 'Unused', 'erdo-image-optimizer' ),
            'Unused images' => __( 'Unused images', 'erdo-image-optimizer' ),
            'Upload images' => __( 'Upload images', 'erdo-image-optimizer' ),
            'Use these settings and scan' => __( 'Use these settings and scan', 'erdo-image-optimizer' ),
            'Use “Suggest for more images” or the Suggest button in an image’s details to get suggestions.' => __( 'Use “Suggest for more images” or the Suggest button in an image’s details to get suggestions.', 'erdo-image-optimizer' ),
            /* translators: %s: value filled in by the script (a number or a name) */
            'Was attached to "%s"' => __( 'Was attached to "%s"', 'erdo-image-optimizer' ),
            'Was in use' => __( 'Was in use', 'erdo-image-optimizer' ),
            'Weak alt' => __( 'Weak alt', 'erdo-image-optimizer' ),
            'Working…' => __( 'Working…', 'erdo-image-optimizer' ),
            'You can close this window or leave the page; conversion keeps running.' => __( 'You can close this window or leave the page; conversion keeps running.', 'erdo-image-optimizer' ),
            'Your originals are not changed. A copy is kept only when it is smaller than the original, and browsers that cannot show it get the original.' => __( 'Your originals are not changed. A copy is kept only when it is smaller than the original, and browsers that cannot show it get the original.', 'erdo-image-optimizer' ),
        );
    }

    /* ------------------------------------------------------------------
       ANA SAYFA HTML
    ------------------------------------------------------------------ */
    public function render_page() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- yalnızca hangi sekmenin açılacağını seçer
        $tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';
        $tabs = array( 'overview', 'library', 'cleanup', 'ai', 'settings' );

        if ( 'setup' === $tab || ! get_option( 'erdo_setup_done' ) ) {
            include ERDO_PATH . 'includes/views/setup.php';
            return;
        }
        $tab = in_array( $tab, $tabs, true ) ? $tab : 'overview';
        $env = $this->environment();
        include ERDO_PATH . 'includes/views/page.php';
    }

    /** Ayarlar sekmesi (views/settings.php). */
    public function render_settings() {
        include ERDO_PATH . 'includes/views/settings.php';
    }

    /**
     * Genel Bakış > "This server" satırları: array( label, value, state ).
     * state: ok | warn | bad | info — yalnızca renk için.
     */
    private function environment() {
        $caps = ERDO_Encoder::capabilities();
        $eng  = function ( $fmt ) use ( $caps ) {
            $c = $caps[ $fmt ] ?? array();
            if ( ! empty( $c['imagick'] ) ) {
                return array( __( 'Works · Imagick', 'erdo-image-optimizer' ), 'ok' );
            }
            if ( ! empty( $c['gd'] ) ) {
                return array( __( 'Works · GD', 'erdo-image-optimizer' ), 'ok' );
            }
            return array( __( 'Not available', 'erdo-image-optimizer' ), 'webp' === $fmt ? 'bad' : 'info' );
        };
        $webp = $eng( 'webp' );
        $avif = $eng( 'avif' );

        if ( ! get_option( 'erdo_serve_webp' ) ) {
            $delivery = array( __( 'Off', 'erdo-image-optimizer' ), 'warn' );
        } elseif ( 'htaccess' === get_option( 'erdo_webp_serve_method' ) && ERDO_WebP_Server::htaccess_has_rules() ) {
            $delivery = array( __( '.htaccess rewrite', 'erdo-image-optimizer' ), 'ok' );
        } else {
            $delivery = array( __( '<picture> tags', 'erdo-image-optimizer' ), 'ok' );
        }

        $cdn = self::detect_cdn();
        $seo = self::seo_plugin_name();
        $ai  = ERDO_AI::status();

        return array(
            array( 'label' => __( 'WebP encoding', 'erdo-image-optimizer' ), 'value' => $webp[0], 'state' => $webp[1] ),
            array( 'label' => __( 'AVIF encoding', 'erdo-image-optimizer' ), 'value' => $avif[0], 'state' => $avif[1] ),
            array( 'label' => __( 'Delivery', 'erdo-image-optimizer' ), 'value' => $delivery[0], 'state' => $delivery[1] ),
            array( 'label' => __( 'Web server', 'erdo-image-optimizer' ), 'value' => self::server_name(), 'state' => 'info' ),
            array( 'label' => __( 'CDN', 'erdo-image-optimizer' ), 'value' => $cdn ? $cdn : __( 'None detected', 'erdo-image-optimizer' ), 'state' => 'info' ),
            array( 'label' => __( 'SEO plugin', 'erdo-image-optimizer' ), 'value' => $seo ? $seo : __( 'None', 'erdo-image-optimizer' ), 'state' => 'info' ),
            array( 'label' => __( 'AI provider', 'erdo-image-optimizer' ), 'value' => ! empty( $ai['ready'] ) ? __( 'Connected', 'erdo-image-optimizer' ) : __( 'Not connected', 'erdo-image-optimizer' ), 'state' => ! empty( $ai['ready'] ) ? 'ok' : 'info' ),
        );
    }

    private static function server_name() {
        if ( ERDO_WebP_Server::is_nginx() ) {
            return 'nginx';
        }
        $soft = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';
        if ( false !== stripos( $soft, 'litespeed' ) ) {
            return 'LiteSpeed';
        }
        if ( ERDO_WebP_Server::is_apache() ) {
            return 'Apache';
        }
        $first = strtok( $soft, '/ ' );
        return $first ? $first : __( 'Unknown', 'erdo-image-optimizer' );
    }

    /** Sitenin önünde bilinen bir CDN var mı? (İstek başlıklarından.) */
    public static function detect_cdn() {
        if ( ! empty( $_SERVER['HTTP_CF_RAY'] ) || ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
            return 'Cloudflare';
        }
        $loop = isset( $_SERVER['HTTP_CDN_LOOP'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_CDN_LOOP'] ) ) : '';
        if ( false !== stripos( $loop, 'cloudflare' ) ) {
            return 'Cloudflare';
        }
        if ( ! empty( $_SERVER['HTTP_X_SUCURI_ID'] ) ) {
            return 'Sucuri';
        }
        return '';
    }

    public static function seo_plugin_name() {
        if ( defined( 'WPSEO_VERSION' ) ) {
            return 'Yoast SEO';
        }
        if ( defined( 'RANK_MATH_VERSION' ) ) {
            return 'Rank Math';
        }
        if ( defined( 'AIOSEO_VERSION' ) ) {
            return 'All in One SEO';
        }
        if ( defined( 'SEOPRESS_VERSION' ) ) {
            return 'SEOPress';
        }
        return '';
    }

    /* ------------------------------------------------------------------
       AJAX: Görsel listesi yükle (50'lik batch)
    ------------------------------------------------------------------ */
    public function ajax_load_images() {
        check_ajax_referer( 'erdo_nonce', 'nonce' );
        $this->verify_capability();

        // ids verilirse yalnızca o görseller (bir değişiklikten sonra kartı tazelemek için).
        $ids = isset( $_POST['ids'] ) ? array_filter( array_map( 'intval', (array) wp_unslash( $_POST['ids'] ) ) ) : array();
        if ( $ids ) {
            $ids = array_slice( $ids, 0, 100 );
            foreach ( $ids as $id ) {
                ERDO_Auditor::clear_cache( $id );
            }
            $live = get_posts( array(
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'post__in'       => $ids,
                'posts_per_page' => count( $ids ),
                'fields'         => 'ids',
                'no_found_rows'  => true,
            ) );
            $out  = array();
            if ( $live ) {
                foreach ( ERDO_Auditor::bulk_analyze( array_map( 'intval', $live ) ) as $id => $res ) {
                    $out[] = self::item( $id, $res );
                }
            }
            // Karantinaya alınan / silinen görseller listeden düşsün diye.
            wp_send_json_success( array( 'items' => $out, 'gone' => array_values( array_diff( $ids, array_map( 'intval', $live ) ) ) ) );
        }

        $paged = isset( $_POST['page'] ) ? max( 1, intval( $_POST['page'] ) ) : 1;
        $query = new WP_Query( array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => 'image',
            'posts_per_page' => 100,
            'paged'          => $paged,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'DESC',
        ) );

        $items = array();
        if ( ! empty( $query->posts ) ) {
            foreach ( ERDO_Auditor::bulk_analyze( $query->posts ) as $id => $res ) {
                $items[] = self::item( $id, $res );
            }
        }

        wp_send_json_success( array(
            'items'     => $items,
            'max_pages' => (int) $query->max_num_pages,
            'found'     => (int) $query->found_posts,
        ) );
    }

    /**
     * Kütüphane ızgarası / listesi için tek görselin özeti. Sorunlar, denetim
     * satırlarının anahtarlarından türetilir (etiketler çevrilir, anahtarlar değil).
     */
    private static function item( $id, array $res ) {
        $issues = array();
        $alt    = '';
        $title  = '';
        foreach ( (array) ( $res['checklist'] ?? array() ) as $c ) {
            $pass = ! empty( $c['pass'] );
            switch ( $c['key'] ?? '' ) {
                case 'alt':
                    $alt = (string) ( $c['value'] ?? '' );
                    if ( ! $pass ) {
                        // Boş alt = "eksik"; dolu ama kurala takılan (anahtar kelime listesi vb.) = "zayıf".
                        $issues[] = '' === trim( $alt ) ? 'alt' : 'weakalt';
                    } elseif ( $c['points'] < $c['weight'] ) {
                        $issues[] = 'weakalt';
                    }
                    break;
                case 'filename':
                case 'format':
                    if ( ! $pass ) {
                        $issues[] = $c['key'];
                    }
                    break;
                case 'filesize':
                case 'dimensions':
                    if ( ! $pass ) {
                        $issues[] = 'oversized';
                    }
                    break;
                case 'context':
                    if ( $pass && $c['points'] < $c['weight'] ) {
                        $issues[] = 'context';
                    }
                    break;
                case 'title':
                    $title = (string) ( $c['value'] ?? '' );
                    break;
            }
        }
        $fmt = strtolower( (string) ( $res['served_format'] ?? '' ) );
        return array(
            'id'     => (int) $id,
            'file'   => (string) $res['filename'],
            'title'  => $title,
            'alt'    => $alt,
            'thumb'  => (string) $res['thumbnail'],
            'score'  => (int) $res['score'],
            'cat'    => ( isset( $res['is_used'] ) && false === $res['is_used'] ) ? 'gray' : (string) $res['status'],
            'fmt'    => strtoupper( 'jpeg' === $fmt ? 'jpg' : $fmt ),
            'modern' => in_array( $fmt, array( 'webp', 'avif' ), true ),
            'bytes'  => (int) $res['current_size'],
            'saved'  => (int) $res['saved_bytes'],
            'issues' => array_values( array_unique( $issues ) ),
        );
    }

    /* ------------------------------------------------------------------
       AJAX: Dashboard istatistikleri
    ------------------------------------------------------------------ */
    public function ajax_get_stats() {
        check_ajax_referer( 'erdo_nonce', 'nonce' );
        $this->verify_capability();

        $cache_group = 'erdo_image_optimizer';
        $result      = wp_cache_get( 'erdo_stats_cache', $cache_group );

        if ( false === $result ) {
            $lib    = ERDO_Converter::library_stats();
            $result = array(
                'total'       => $lib['total'],
                'optimized'   => $lib['optimized'],
                'opt_score'   => $lib['rate'],
                'seo_score'   => self::average_seo_score(),
                'total_saved' => size_format( $lib['saved'], 1 ),
            );
            wp_cache_set( 'erdo_stats_cache', $result, $cache_group, 300 );
        }

        // Önbelleğe girmeyenler: her istekte taze.
        $result['library_version'] = (int) get_option( 'erdo_library_version', 0 );
        $result['quarantine']      = count( ERDO_Quarantine::ids() );
        $result['ai_pending']      = count( ERDO_AI::pending_ids() );
        $result['activity']        = ERDO_Activity::for_display();
        $result['index']           = ERDO_Usage::state()['status'];
        wp_send_json_success( $result );
    }

    /**
     * Kütüphanenin ortalama SEO puanı (0–100). Puanı henüz hesaplanmamış
     * görseller her çağrıda en fazla 300'lük partiyle hesaplanır, böylece
     * büyük kütüphanelerde de ortalama birkaç yüklemede tamamlanır.
     */
    public static function average_seo_score() {
        global $wpdb;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $missing = $wpdb->get_col( $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
             WHERE p.post_type = 'attachment' AND p.post_status = 'inherit' AND p.post_mime_type LIKE %s AND m.meta_id IS NULL
             LIMIT 300",
            ERDO_SEO::SCORE_META,
            $wpdb->esc_like( 'image/' ) . '%'
        ) );
        if ( $missing ) {
            ERDO_Auditor::bulk_analyze( array_map( 'intval', $missing ) );
        }
        $avg = $wpdb->get_var( $wpdb->prepare(
            "SELECT AVG(CAST(m.meta_value AS UNSIGNED)) FROM {$wpdb->postmeta} m
             INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
             WHERE m.meta_key = %s AND p.post_type = 'attachment' AND p.post_status = 'inherit'",
            ERDO_SEO::SCORE_META
        ) );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return null === $avg ? 0 : (int) round( (float) $avg );
    }

    /* ------------------------------------------------------------------
       AJAX: Görsel detay modali (HTML döndürür)
    ------------------------------------------------------------------ */
    public function ajax_get_detail() {
        check_ajax_referer( 'erdo_nonce', 'nonce' );
        $this->verify_capability();

        if ( ! isset( $_POST['id'] ) ) {
            wp_send_json_error( array( 'message' => __( 'Missing image ID.', 'erdo-image-optimizer' ) ) );
        }

        $id   = intval( $_POST['id'] );
        $data = ERDO_Auditor::analyze( $id );
        if ( empty( $data ) ) {
            wp_send_json_error( array( 'message' => __( 'Image not found.', 'erdo-image-optimizer' ) ) );
        }

        ob_start();
        include ERDO_PATH . 'includes/views/detail.php';
        echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Admin-only AJAX; every value is escaped in the view.
        wp_die();
    }

    /* ------------------------------------------------------------------
       AJAX: Görsel verisi kaydet
    ------------------------------------------------------------------ */
    public function ajax_save_data() {
        check_ajax_referer( 'erdo_nonce', 'nonce' );
        $this->verify_capability();

        $id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
        if ( ! $id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid ID.', 'erdo-image-optimizer' ) ) );
        }

        ERDO_Auditor::save_image_data( $id, wp_unslash( $_POST ) );
        $new_data = ERDO_Auditor::analyze( $id );
        wp_send_json_success( $new_data );
    }

    /* ------------------------------------------------------------------
       AJAX: WebP/AVIF dönüşümü — artık bağımsız JSON yanıt döndürüyor.
       JS tarafı dönüşüm sonrası ayrı bir erdo_get_detail çağrısı yapar.
    ------------------------------------------------------------------ */
    public function ajax_convert_webp() {
        check_ajax_referer( 'erdo_nonce', 'nonce' );
        $this->verify_capability();

        if ( ! isset( $_POST['id'] ) ) {
            wp_send_json_error( array( 'message' => __( 'Missing ID.', 'erdo-image-optimizer' ) ) );
        }

        $id     = intval( wp_unslash( $_POST['id'] ) );
        $result = ERDO_Converter::convert( $id );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        } elseif ( 'skipped' === $result['status'] ) {
            wp_send_json_error( array(
                'message'           => 'animated' === $result['reason']
                    ? __( 'Animated GIF skipped: this server cannot keep the animation.', 'erdo-image-optimizer' )
                    : __( 'Image is already in a modern format.', 'erdo-image-optimizer' ),
                'already_optimized' => true,
            ) );
        } elseif ( 'larger' === $result['status'] ) {
            wp_send_json_error( array(
                'message'           => __( 'The original is already smaller than a WebP/AVIF copy would be — nothing to gain.', 'erdo-image-optimizer' ),
                'already_optimized' => true,
            ) );
        } else {
            wp_send_json_success( array( 'id' => $id, 'result' => $result ) );
        }
    }

    /* ------------------------------------------------------------------
       AJAX: Kullanılmayan görsel listesi
       bulk_analyze() ile tüm ID'ler TEK sorguda analiz edilir.
       Eski kod: 500 görsel × 6 sorgu = 3000 sorgu → timeout
       Yeni kod: 500 görsel → 4-5 sorgu → ~saniyeler içinde
    ------------------------------------------------------------------ */
    public function ajax_get_unused_list() {
        check_ajax_referer( 'erdo_nonce', 'nonce' );
        $this->verify_capability();

        // Kullanım indeksi hazır değilse toplu temizlik yapılmaz — eski LIKE
        // taraması logo, ACF, WooCommerce vb. kullanımları kaçırıyordu.
        if ( ! ERDO_Usage::is_ready() ) {
            wp_send_json_error( array( 'message' => __( 'The usage index is still being built. Please try again in a moment.', 'erdo-image-optimizer' ) ) );
        }

        wp_send_json_success( ERDO_Usage::unused_ids() );
    }

    /* ------------------------------------------------------------------
       AJAX: Görsel sil
    ------------------------------------------------------------------ */
    public function ajax_delete_image() {
        check_ajax_referer( 'erdo_nonce', 'nonce' );
        $this->verify_capability();

        if ( ! isset( $_POST['id'] ) ) {
            wp_send_json_error();
        }

        // Tek görsel için kullanıcının açık isteği: kalıcı silme yerine karantina
        // (geri alınabilir). Kullanımdaysa yine taşınır ama yanıt bunu bildirir.
        $id     = intval( wp_unslash( $_POST['id'] ) );
        $result = ERDO_Quarantine::quarantine( $id, false );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }
        wp_send_json_success( $result );
    }

    /* ------------------------------------------------------------------
       AJAX: Manuel yeniden boyutlandır
    ------------------------------------------------------------------ */
    public function ajax_manual_resize() {
        check_ajax_referer( 'erdo_nonce', 'nonce' );
        $this->verify_capability();

        if ( ! isset( $_POST['id'], $_POST['w'], $_POST['h'] ) ) {
            wp_send_json_error();
        }

        $id  = intval( wp_unslash( $_POST['id'] ) );
        $w   = intval( wp_unslash( $_POST['w'] ) );
        $h   = intval( wp_unslash( $_POST['h'] ) );
        $res = ERDO_Auditor::manual_resize( $id, $w, $h );

        if ( $res ) {
            wp_send_json_success();
        } else {
            wp_send_json_error();
        }
    }

    /** Elle boyutlandırmayı geri al: orijinal yedekten geri gelir. */
    public function ajax_restore_size() {
        check_ajax_referer( 'erdo_nonce', 'nonce' );
        $this->verify_capability();

        $id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
        $r  = ERDO_Auditor::restore_original_size( $id );
        if ( is_wp_error( $r ) ) {
            wp_send_json_error( array( 'message' => $r->get_error_message() ) );
        }
        wp_send_json_success();
    }

    /* ------------------------------------------------------------------
       AJAX: Orijinal görseli yedeğinden geri yükle
    ------------------------------------------------------------------ */
    public function ajax_restore_original() {
        check_ajax_referer( 'erdo_nonce', 'nonce' );
        $this->verify_capability();

        if ( ! isset( $_POST['id'] ) ) {
            wp_send_json_error( array( 'message' => __( 'Missing ID.', 'erdo-image-optimizer' ) ) );
        }

        $id = intval( wp_unslash( $_POST['id'] ) );
        ERDO_Converter::revert( $id );
        wp_send_json_success( array( 'id' => $id ) );
    }

    /* ------------------------------------------------------------------
       AJAX: Tüm audit cache'i temizle ve yenile
    ------------------------------------------------------------------ */
    public function ajax_refresh_all() {
        check_ajax_referer( 'erdo_nonce', 'nonce' );
        $this->verify_capability();
        ERDO_Auditor::clear_all_cache();
        wp_send_json_success();
    }

    /* ------------------------------------------------------------------
       AJAX: Eksik featured image olan yazıları tara
    ------------------------------------------------------------------ */
    public function ajax_scan_thumbnails() {
        check_ajax_referer( 'erdo_nonce', 'nonce' );
        $this->verify_capability();

        global $wpdb;

        $cache_key   = 'erdo_missing_thumbnails';
        $cache_group = 'erdo_image_optimizer';
        $results     = wp_cache_get( $cache_key, $cache_group );

        if ( false === $results ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $results = $wpdb->get_results(
                "SELECT p.ID, p.post_title
                 FROM {$wpdb->posts} p
                 LEFT JOIN {$wpdb->postmeta} pm
                     ON p.ID = pm.post_id AND pm.meta_key = '_thumbnail_id'
                 WHERE p.post_type = 'post'
                   AND p.post_status = 'publish'
                   AND pm.post_id IS NULL
                 LIMIT 50",
                ARRAY_A
            );
            wp_cache_set( $cache_key, $results, $cache_group, 300 );
        }

        $missing = array();
        if ( ! empty( $results ) ) {
            foreach ( $results as $row ) {
                $missing[] = array(
                    'id'    => (int) $row['ID'],
                    'title' => $row['post_title'],
                    'link'  => get_edit_post_link( $row['ID'] ),
                );
            }
        }

        wp_send_json_success( $missing );
    }

    /* ------------------------------------------------------------------
       AJAX: Ayarları kaydet
    ------------------------------------------------------------------ */
    public function ajax_save_settings() {
        check_ajax_referer( 'erdo_nonce', 'nonce' );
        $this->verify_capability();

        update_option( 'erdo_auto_webp',           isset( $_POST['erdo_auto_webp'] ) ? 1 : 0 );
        update_option( 'erdo_auto_sanitize',       isset( $_POST['erdo_auto_sanitize'] ) ? 1 : 0 );
        update_option( 'erdo_remove_exif',         isset( $_POST['erdo_remove_exif'] ) ? 1 : 0 );
        update_option( 'erdo_lossless',            isset( $_POST['erdo_lossless'] ) ? 1 : 0 );
        update_option( 'erdo_serve_webp',          isset( $_POST['erdo_serve_webp'] ) ? 1 : 0 );
        update_option( 'erdo_smart_quality',       isset( $_POST['erdo_smart_quality'] ) ? 1 : 0 );
        update_option( 'erdo_append_original_filename', isset( $_POST['erdo_append_original_filename'] ) ? 1 : 0 );
        if ( ! ERDO_SEO::seo_plugin_handles_image_sitemap() ) {
            update_option( 'erdo_image_sitemap', isset( $_POST['erdo_image_sitemap'] ) ? 1 : 0 );
        }
        update_option( 'erdo_ai_on_upload', isset( $_POST['erdo_ai_on_upload'] ) && ERDO_AI::is_ready() ? 1 : 0 );

        if ( isset( $_POST['erdo_webp_serve_method'] ) ) {
            $meth = sanitize_text_field( wp_unslash( $_POST['erdo_webp_serve_method'] ) );
            update_option( 'erdo_webp_serve_method', in_array( $meth, array( 'picture', 'htaccess' ), true ) ? $meth : 'picture' );
        }

        if ( isset( $_POST['erdo_uninstall_siblings'] ) ) {
            update_option( 'erdo_uninstall_siblings', 'keep' === $_POST['erdo_uninstall_siblings'] ? 'keep' : 'delete' );
        }
        if ( isset( $_POST['erdo_uninstall_quarantine'] ) ) {
            update_option( 'erdo_uninstall_quarantine', 'delete' === $_POST['erdo_uninstall_quarantine'] ? 'delete' : 'restore' );
        }

        if ( isset( $_POST['erdo_quarantine_days'] ) ) {
            update_option( 'erdo_quarantine_days', max( 0, min( 365, intval( wp_unslash( $_POST['erdo_quarantine_days'] ) ) ) ) );
        }

        if ( isset( $_POST['erdo_max_width'] ) ) {
            update_option( 'erdo_max_width', max( 0, intval( wp_unslash( $_POST['erdo_max_width'] ) ) ) );
        }

        if ( isset( $_POST['erdo_convert_format'] ) ) {
            $fmt = sanitize_text_field( wp_unslash( $_POST['erdo_convert_format'] ) );
            update_option( 'erdo_convert_format', in_array( $fmt, array( 'webp', 'avif', 'both' ), true ) ? $fmt : 'webp' );
        }

        if ( isset( $_POST['erdo_quality'] ) ) {
            $q = intval( wp_unslash( $_POST['erdo_quality'] ) );
            $q = max( 10, min( 100, $q ) );
            update_option( 'erdo_quality', $q );
        }

        // .htaccess kurallarını ayarla eşitle: yalnızca Apache + .htaccess yöntemi seçiliyken var olmalı.
        if ( get_option( 'erdo_serve_webp' ) && 'htaccess' === get_option( 'erdo_webp_serve_method' ) && ERDO_WebP_Server::is_apache() ) {
            ERDO_WebP_Server::write_htaccess_rules();
        } elseif ( ERDO_WebP_Server::htaccess_has_rules() ) {
            ERDO_WebP_Server::remove_webp_rules();
        }
        ERDO_Auditor::clear_all_cache();

        wp_send_json_success();
    }

    /* ------------------------------------------------------------------
       AJAX: Arka plan kuyruğunu başlat
    ------------------------------------------------------------------ */
    public function ajax_start_queue() {
        check_ajax_referer( 'erdo_nonce', 'nonce' );
        $this->verify_capability();

        // Seçili görseller ya da (boşsa) kopyası olmayan tüm görseller.
        $ids = isset( $_POST['ids'] ) ? array_filter( array_map( 'intval', (array) wp_unslash( $_POST['ids'] ) ) ) : array();
        $ids = $ids ? $ids : ERDO_Converter::pending_ids();

        if ( empty( $ids ) ) {
            wp_send_json_error( array( 'message' => __( 'All images already have WebP/AVIF copies.', 'erdo-image-optimizer' ) ) );
        }

        ERDO_Queue::start( array_values( $ids ), 'convert' );
        // Küçük kuyruklar ilk partide biter: sonuç penceresi "geri al"ı hemen gösterebilsin.
        wp_send_json_success( array_merge( ERDO_Queue::get_status(), array( 'can_undo' => (bool) ERDO_Queue::can_undo() ) ) );
    }

    public function ajax_cancel_queue() {
        check_ajax_referer( 'erdo_nonce', 'nonce' );
        $this->verify_capability();
        ERDO_Queue::cancel();
        wp_send_json_success( array( 'status' => 'cancelled' ) );
    }

    /**
     * Kuyruk durumu. assist=1 iken tarayıcı da küçük bir parti işler: düşük
     * trafikli sitelerde WP-Cron dakikalarca tetiklenmeyebilir (kilit sayesinde
     * cron ile aynı anda çalışmaz). pending=1 kopyası olmayan görsel sayısını ekler.
     */
    public function ajax_queue_status() {
        check_ajax_referer( 'erdo_nonce', 'nonce' );
        $this->verify_capability();
        if ( ERDO_Queue::is_running() && ! empty( $_POST['assist'] ) ) {
            ERDO_Queue::process_batch( 5 );
        }
        $status             = ERDO_Queue::get_status();
        $status['can_undo'] = (bool) ERDO_Queue::can_undo();
        if ( ! empty( $_POST['pending'] ) ) {
            $status['pending'] = count( ERDO_Converter::pending_ids() );
        }
        wp_send_json_success( $status );
    }

    /** Son toplu dönüşümü geri al: o kuyruktaki görsellerin kopyaları silinir. */
    public function ajax_queue_undo() {
        check_ajax_referer( 'erdo_nonce', 'nonce' );
        $this->verify_capability();
        wp_send_json_success( array( 'reverted' => ERDO_Queue::undo() ) );
    }

    /* ------------------------------------------------------------------
       AJAX: Tek görsel — dosya adını yeniden adlandır
    ------------------------------------------------------------------ */
    public function ajax_rename_file() {
        check_ajax_referer( 'erdo_nonce', 'nonce' );
        $this->verify_capability();

        $id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
        if ( ! $id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid ID.', 'erdo-image-optimizer' ) ) );
        }

        $result = ERDO_Auditor::rename_file( $id );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        $new_data = ERDO_Auditor::analyze( $id );
        wp_send_json_success( array_merge( $result, array( 'audit' => $new_data ) ) );
    }

    /* ------------------------------------------------------------------
       AJAX: Yeniden adlandırma önizlemesi (hiçbir şeyi değiştirmez)
       ids boş → tüm kütüphane; pattern boş → "Fix Filename" (yalnızca değişecekler).
    ------------------------------------------------------------------ */
    public function ajax_rename_preview() {
        check_ajax_referer( 'erdo_nonce', 'nonce' );
        $this->verify_capability();

        $ids     = isset( $_POST['ids'] ) ? array_filter( array_map( 'intval', (array) wp_unslash( $_POST['ids'] ) ) ) : array();
        $pattern = isset( $_POST['pattern'] ) ? sanitize_text_field( wp_unslash( $_POST['pattern'] ) ) : '';
        $keyword = isset( $_POST['keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) : '';

        if ( empty( $ids ) ) {
            $ids = get_posts( array(
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'post_mime_type' => 'image',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
                'orderby'        => 'ID',
                'order'          => 'ASC',
            ) );
        }

        $rows = ERDO_Renamer::preview( $ids, '' === $pattern ? null : $pattern, array( 'keyword' => $keyword ), '' === $pattern );
        wp_send_json_success( array( 'rows' => $rows ) );
    }

    /* ------------------------------------------------------------------
       AJAX: Önizlemedeki adları uygula — parça parça, aynı batch kimliğiyle
       items[id] = yeni ad kökü ('' = mevcut adı temizle).
    ------------------------------------------------------------------ */
    public function ajax_rename_apply() {
        check_ajax_referer( 'erdo_nonce', 'nonce' );
        $this->verify_capability();

        $raw   = isset( $_POST['items'] ) && is_array( $_POST['items'] ) ? wp_unslash( $_POST['items'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per item below
        $batch = isset( $_POST['batch'] ) ? sanitize_key( wp_unslash( $_POST['batch'] ) ) : '';
        $batch = '' === $batch ? ERDO_Renamer::new_batch_id() : $batch;
        $items = array();
        foreach ( array_slice( $raw, 0, 50, true ) as $id => $stem ) {
            $items[ (int) $id ] = '' === (string) $stem ? null : ERDO_Renamer::sanitize_stem( sanitize_text_field( (string) $stem ) );
        }

        $out = array();
        foreach ( ERDO_Renamer::apply_items( $items, $batch ) as $id => $r ) {
            $out[] = is_wp_error( $r )
                ? array( 'id' => $id, 'error' => $r->get_error_message() )
                : array( 'id' => $id, 'filename' => wp_basename( (string) get_attached_file( $id ) ) );
        }
        wp_send_json_success( array( 'batch' => $batch, 'results' => $out ) );
    }

    /* ------------------------------------------------------------------
       AJAX: Toplu yeniden adlandırmayı geri al
    ------------------------------------------------------------------ */
    public function ajax_rename_undo() {
        check_ajax_referer( 'erdo_nonce', 'nonce' );
        $this->verify_capability();

        $batch = isset( $_POST['batch'] ) ? sanitize_key( wp_unslash( $_POST['batch'] ) ) : '';
        $r     = ERDO_Renamer::undo_batch( $batch );
        if ( is_wp_error( $r ) ) {
            wp_send_json_error( array( 'message' => $r->get_error_message() ) );
        }
        wp_send_json_success( $r );
    }

    /* ------------------------------------------------------------------
       AJAX: Tek görselin son yeniden adlandırmasını geri al
    ------------------------------------------------------------------ */
    public function ajax_rename_undo_last() {
        check_ajax_referer( 'erdo_nonce', 'nonce' );
        $this->verify_capability();

        $id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
        $r  = ERDO_Renamer::undo_last( $id );
        if ( is_wp_error( $r ) ) {
            wp_send_json_error( array( 'message' => $r->get_error_message() ) );
        }
        wp_send_json_success( array( 'filename' => wp_basename( (string) get_attached_file( $id ) ) ) );
    }

    /* ------------------------------------------------------------------
       AJAX: SEO audit sonuçlarını CSV olarak indir
    ------------------------------------------------------------------ */
    public function ajax_export_csv() {
        check_ajax_referer( 'erdo_nonce', 'nonce' );
        $this->verify_capability();

        $status_filter = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';
        $rows          = ERDO_Auditor::get_csv_rows( $status_filter );

        $csv  = "ID,Filename,Score (0-100),Status,Format,Alt Text,Issues,Edit URL\n";
        foreach ( $rows as $r ) {
            $csv .= implode( ',', array_map( function( $v ) {
                $v = (string) $v;
                // Formül enjeksiyonu: =, +, -, @ ile başlayan hücreler Excel'de çalıştırılmasın.
                if ( '' !== $v && in_array( $v[0], array( '=', '+', '-', '@' ), true ) ) {
                    $v = "'" . $v;
                }
                return '"' . str_replace( '"', '""', $v ) . '"';
            }, $r ) ) . "\n";
        }

        wp_send_json_success( array( 'csv' => $csv, 'count' => count( $rows ) ) );
    }

    /* ------------------------------------------------------------------
       AJAX: Seçili görseller için dosya adından alt text üret
    ------------------------------------------------------------------ */
    public function ajax_bulk_alt_text() {
        check_ajax_referer( 'erdo_nonce', 'nonce' );
        $this->verify_capability();

        $ids = isset( $_POST['ids'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['ids'] ) ) : array();

        // ID verilmezse sadece alt text eksik olanları işle
        if ( empty( $ids ) ) {
            $ids = get_posts( array(
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'post_mime_type' => 'image',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
                'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                    array(
                        'key'     => '_wp_attachment_image_alt',
                        'compare' => 'NOT EXISTS',
                    ),
                ),
            ) );
        }

        // dry=1: yalnızca kaç görselin alt metni dosya adından üretilebileceğini say.
        if ( ! empty( $_POST['dry'] ) ) {
            $can = 0;
            foreach ( $ids as $id ) {
                $can += false !== ERDO_SEO::alt_from_filename( pathinfo( (string) get_attached_file( $id ), PATHINFO_FILENAME ) ) ? 1 : 0;
            }
            wp_send_json_success( array( 'total' => count( $ids ), 'can' => $can ) );
        }

        $updated = 0;
        foreach ( $ids as $id ) {
            $alt = ERDO_Auditor::set_alt_from_filename( $id );
            if ( $alt ) {
                $updated++;
            }
        }
        ERDO_Activity::log( 'alt', $updated );

        wp_send_json_success( array( 'updated' => $updated, 'skipped' => count( $ids ) - $updated ) );
    }

    /* ------------------------------------------------------------------
       DASHBOARD WIDGET
    ------------------------------------------------------------------ */
    public function register_dashboard_widget() {
        wp_add_dashboard_widget(
            'erdo_image_optimizer_widget',
            __( 'Image Optimizer', 'erdo-image-optimizer' ),
            array( $this, 'render_dashboard_widget' )
        );
    }

    public function render_dashboard_widget() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $lib  = ERDO_Converter::library_stats();
        $seo  = (int) round( (float) self::average_seo_score() );
        $url  = admin_url( 'admin.php?page=erdo-image-optimizer' );
        $cell = 'flex:1;min-width:80px;padding:8px 0;';
        ?>
        <div style="display:flex;gap:12px;text-align:center;margin-bottom:12px;border:1px solid #dcdcde;border-radius:6px;">
            <div style="<?php echo esc_attr( $cell ); ?>">
                <div style="font-size:20px;font-weight:600;"><?php echo esc_html( $seo ); ?></div>
                <div class="description"><?php esc_html_e( 'Average SEO score', 'erdo-image-optimizer' ); ?></div>
            </div>
            <div style="<?php echo esc_attr( $cell ); ?>border-left:1px solid #dcdcde;">
                <div style="font-size:20px;font-weight:600;"><?php echo esc_html( $lib['rate'] ); ?>%</div>
                <div class="description"><?php esc_html_e( 'WebP / AVIF coverage', 'erdo-image-optimizer' ); ?></div>
            </div>
            <div style="<?php echo esc_attr( $cell ); ?>border-left:1px solid #dcdcde;">
                <div style="font-size:20px;font-weight:600;"><?php echo esc_html( size_format( $lib['saved'], 1 ) ); ?></div>
                <div class="description"><?php esc_html_e( 'Saved for visitors', 'erdo-image-optimizer' ); ?></div>
            </div>
        </div>
        <a href="<?php echo esc_url( $url ); ?>" class="button button-primary"><?php esc_html_e( 'Open Image Optimizer', 'erdo-image-optimizer' ); ?></a>
        <?php
    }

    /* ------------------------------------------------------------------
       KARANTİNA + KULLANIM İNDEKSİ
    ------------------------------------------------------------------ */
    public function ajax_quarantine_unused() {
        check_ajax_referer( 'erdo_nonce', 'nonce' );
        $this->verify_capability();
        $id     = isset( $_POST['id'] ) ? intval( wp_unslash( $_POST['id'] ) ) : 0;
        $result = ERDO_Quarantine::quarantine( $id, true );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'code' => $result->get_error_code(), 'message' => $result->get_error_message() ) );
        }
        wp_send_json_success( $result );
    }

    public function ajax_quarantine_list() {
        check_ajax_referer( 'erdo_nonce', 'nonce' );
        $this->verify_capability();
        $rows = array();
        foreach ( ERDO_Quarantine::ids() as $id ) {
            $data   = get_post_meta( $id, ERDO_Quarantine::META, true );
            $parent = (int) wp_get_post_parent_id( $id );
            $rows[] = array(
                'id'     => (int) $id,
                'file'   => wp_basename( (string) get_post_meta( $id, '_wp_attached_file', true ) ),
                /* translators: %s: human-readable time difference, e.g. "2 hours" */
                'when'   => sprintf( __( '%s ago', 'erdo-image-optimizer' ), human_time_diff( (int) ( $data['time'] ?? time() ) ) ),
                'in_use' => ! empty( $data['in_use'] ),
                'parent' => $parent ? get_the_title( $parent ) : '',
            );
        }
        wp_send_json_success( array( 'rows' => $rows, 'count' => count( $rows ) ) );
    }

    public function ajax_quarantine_restore() {
        check_ajax_referer( 'erdo_nonce', 'nonce' );
        $this->verify_capability();
        $id     = isset( $_POST['id'] ) ? intval( wp_unslash( $_POST['id'] ) ) : 0;
        $result = ERDO_Quarantine::restore( $id );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }
        wp_send_json_success();
    }

    public function ajax_quarantine_purge() {
        check_ajax_referer( 'erdo_nonce', 'nonce' );
        $this->verify_capability();
        $id     = isset( $_POST['id'] ) ? intval( wp_unslash( $_POST['id'] ) ) : 0;
        $result = ERDO_Quarantine::purge( $id );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }
        wp_send_json_success();
    }

    /* ------------------------------------------------------------------
       AI ÖNERİLERİ (Faz 5) — öneri üret, incele, kabul et / reddet
    ------------------------------------------------------------------ */

    /** AI kullanılamıyorsa nedenini ve Connectors bağlantısını gösterir. */
    private function render_ai_setup_notice() {
        $ai = ERDO_AI::status();
        if ( ! empty( $ai['ready'] ) ) {
            return;
        }
        ?>
        <div class="erdo-card erdo-ai-setup">
            <span class="dashicons dashicons-lightbulb" aria-hidden="true"></span>
            <div>
                <h2><?php esc_html_e( 'Set up AI suggestions', 'erdo-image-optimizer' ); ?></h2>
                <p><?php echo esc_html( $ai['message'] ); ?></p>
                <?php if ( 'provider' === $ai['reason'] ) : ?>
                <ol>
                    <li><?php esc_html_e( 'Open Settings > Connectors and install the provider (Anthropic, Google or OpenAI).', 'erdo-image-optimizer' ); ?></li>
                    <li><?php esc_html_e( 'Paste your own API key there. Erdo never stores it.', 'erdo-image-optimizer' ); ?></li>
                    <li><?php esc_html_e( 'Come back here: suggestions become available right away.', 'erdo-image-optimizer' ); ?></li>
                </ol>
                <a class="button button-primary" href="<?php echo esc_url( $ai['setup_url'] ); ?>"><?php esc_html_e( 'Open Connectors', 'erdo-image-optimizer' ); ?></a>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /** Detay paneli: öneri varsa inceleme kutusu, yoksa "AI ile öner" butonu. */
    private function render_ai_box( $id ) {
        $ai = ERDO_AI::status();
        $s  = ERDO_AI::get( $id );
        if ( ! $s ) {
            if ( empty( $ai['ready'] ) ) {
                if ( 'wp' !== $ai['reason'] ) {
                    echo '<p class="erdo-ai-hint">' . esc_html( $ai['message'] ) . ' <a href="' . esc_url( $ai['setup_url'] ) . '">' . esc_html__( 'Set up', 'erdo-image-optimizer' ) . '</a></p>';
                }
                return;
            }
            ?>
            <div class="erdo-ai-box erdo-ai-box-empty">
                <button type="button" class="button erdo-ai-suggest-btn" data-id="<?php echo esc_attr( $id ); ?>">
                    <span class="dashicons dashicons-lightbulb" aria-hidden="true"></span>
                    <?php esc_html_e( 'Suggest alt text, file name and title with AI', 'erdo-image-optimizer' ); ?>
                </button>
            </div>
            <?php
            return;
        }
        $audit = ERDO_Auditor::analyze( $id );
        $weak  = array();
        foreach ( (array) ( $audit['checklist'] ?? array() ) as $c ) {
            $weak[ $c['key'] ?? '' ] = empty( $c['pass'] ) || ( ! empty( $c['weight'] ) && $c['points'] < $c['weight'] );
        }
        $rows = array(
            'alt'      => array( __( 'Alt text', 'erdo-image-optimizer' ), true ),
            'filename' => array( __( 'File name', 'erdo-image-optimizer' ), ! empty( $weak['filename'] ) ),
            'title'    => array( __( 'Title', 'erdo-image-optimizer' ), ! empty( $weak['title'] ) ),
            'caption'  => array( __( 'Caption', 'erdo-image-optimizer' ), '' === (string) get_post_field( 'post_excerpt', $id ) ),
        );
        ?>
        <div class="erdo-ai-box" data-id="<?php echo esc_attr( $id ); ?>">
            <h3><span class="dashicons dashicons-lightbulb" aria-hidden="true"></span> <?php esc_html_e( 'AI suggestion — review before saving', 'erdo-image-optimizer' ); ?></h3>
            <?php if ( false === $s['current_alt_ok'] && '' !== $s['current_alt_issue'] ) : ?>
                <p class="erdo-ai-issue"><?php echo esc_html( $s['current_alt_issue'] ); ?></p>
            <?php endif; ?>
            <?php foreach ( $rows as $field => $row ) : ?>
                <?php
                $value = (string) $s[ $field ];
                if ( 'alt' !== $field && '' === $value ) {
                    continue;
                }
                ?>
                <label class="erdo-ai-row">
                    <input type="checkbox" class="erdo-ai-field" value="<?php echo esc_attr( $field ); ?>" <?php checked( $row[1] ); ?>>
                    <span class="erdo-ai-label"><?php echo esc_html( $row[0] ); ?></span>
                    <?php if ( 'alt' === $field && ! empty( $s['decorative'] ) ) : ?>
                        <em class="erdo-ai-decorative"><?php esc_html_e( 'Decorative image: alt will be left empty on purpose.', 'erdo-image-optimizer' ); ?></em>
                    <?php else : ?>
                        <input type="text" class="erdo-input erdo-ai-value" data-field="<?php echo esc_attr( $field ); ?>" value="<?php echo esc_attr( $value ); ?>">
                    <?php endif; ?>
                </label>
            <?php endforeach; ?>
            <div class="erdo-ai-actions">
                <button type="button" class="button button-primary erdo-ai-accept-btn"><?php esc_html_e( 'Accept selected', 'erdo-image-optimizer' ); ?></button>
                <button type="button" class="button erdo-ai-reject-btn"><?php esc_html_e( 'Dismiss', 'erdo-image-optimizer' ); ?></button>
                <button type="button" class="button-link erdo-ai-suggest-btn" data-id="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Try again', 'erdo-image-optimizer' ); ?></button>
            </div>
        </div>
        <?php
    }

    public function ajax_ai_suggest() {
        check_ajax_referer( 'erdo_nonce', 'nonce' );
        $this->verify_capability();

        $id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
        $r  = ERDO_AI::suggest( $id );
        if ( is_wp_error( $r ) ) {
            $data = $r->get_error_data();
            wp_send_json_error( array( 'message' => $r->get_error_message(), 'code' => $r->get_error_code(), 'retry' => ! empty( $data['retry'] ) ) );
        }
        wp_send_json_success( $r );
    }

    public function ajax_ai_accept() {
        check_ajax_referer( 'erdo_nonce', 'nonce' );
        $this->verify_capability();

        $id     = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
        $fields = isset( $_POST['fields'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['fields'] ) ) : array( 'alt' );
        $edits  = array();
        if ( isset( $_POST['edits'] ) && is_array( $_POST['edits'] ) ) {
            foreach ( wp_unslash( $_POST['edits'] ) as $k => $v ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field below and in ERDO_AI::accept()
                $edits[ sanitize_key( $k ) ] = sanitize_text_field( (string) $v );
            }
        }
        $r = ERDO_AI::accept( $id, $fields, $edits );
        if ( is_wp_error( $r ) ) {
            wp_send_json_error( array( 'message' => $r->get_error_message() ) );
        }
        wp_send_json_success( array_merge( $r, array( 'audit' => ERDO_Auditor::analyze( $id ) ) ) );
    }

    public function ajax_ai_reject() {
        check_ajax_referer( 'erdo_nonce', 'nonce' );
        $this->verify_capability();

        $id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
        ERDO_AI::reject( $id );
        wp_send_json_success();
    }

    /** İnceleme listesi (AI Suggestions sekmesi). */
    public function ajax_ai_list() {
        check_ajax_referer( 'erdo_nonce', 'nonce' );
        $this->verify_capability();

        $ids   = ERDO_AI::pending_ids();
        $audit = $ids ? ERDO_Auditor::bulk_analyze( $ids ) : array();
        $rows  = array();
        foreach ( $ids as $id ) {
            $s    = ERDO_AI::get( $id );
            $file = (string) get_attached_file( $id );
            $weak = array();
            foreach ( (array) ( $audit[ $id ]['checklist'] ?? array() ) as $c ) {
                $weak[ $c['key'] ?? '' ] = empty( $c['pass'] ) || ( ! empty( $c['weight'] ) && $c['points'] < $c['weight'] );
            }
            $sources = ERDO_Usage::is_ready() ? ERDO_Usage::sources( $id ) : array();
            $rows[]  = array(
                'id'            => (int) $id,
                'thumb'         => (string) wp_get_attachment_image_url( $id, 'thumbnail' ),
                'filename'      => wp_basename( $file ),
                'ext'           => strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ),
                'used_on'       => $sources ? $sources[0]['label'] : '',
                'current_alt'   => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
                'current_title' => (string) get_the_title( $id ),
                'current_stem'  => pathinfo( $file, PATHINFO_FILENAME ),
                'alt'           => (string) $s['alt'],
                'title'         => (string) $s['title'],
                'stem'          => (string) $s['filename'],
                'decorative'    => ! empty( $s['decorative'] ),
                'issue'         => (string) $s['current_alt_issue'],
                'weak_filename' => ! empty( $weak['filename'] ),
                'weak_title'    => ! empty( $weak['title'] ),
            );
        }
        wp_send_json_success( array( 'rows' => $rows ) );
    }

    /** Toplu öneri için aday görseller + maliyet tahmini. */
    public function ajax_ai_candidates() {
        check_ajax_referer( 'erdo_nonce', 'nonce' );
        $this->verify_capability();

        $scope = isset( $_POST['scope'] ) && 'weak' === $_POST['scope'] ? 'weak' : 'missing';
        $ids   = ERDO_AI::candidate_ids( $scope );
        $n     = count( $ids );
        // Görsel başına ~1.500 girdi + ~150 çıktı token; küçük görsel modellerde 1.000 görsel ≈ $0,15–0,40.
        wp_send_json_success( array(
            'ids'  => $ids,
            'low'  => number_format( max( 0.01, $n * 0.00015 ), 2 ),
            'high' => number_format( max( 0.02, $n * 0.0004 ), 2 ),
        ) );
    }

    /* ------------------------------------------------------------------
       İLK AÇILIŞ SİHİRBAZI
    ------------------------------------------------------------------ */

    /** 1. adım: gerçek deneme kodlaması + ortam tespiti, 2. adım için öneriler. */
    public function ajax_setup_check() {
        check_ajax_referer( 'erdo_nonce', 'nonce' );
        $this->verify_capability();

        $t0   = microtime( true );
        $caps = ERDO_Encoder::capabilities( true );
        $ms   = (int) round( ( microtime( true ) - $t0 ) * 1000 );
        $eng  = function ( $fmt ) use ( $caps ) {
            return ! empty( $caps[ $fmt ]['imagick'] ) ? 'Imagick' : ( ! empty( $caps[ $fmt ]['gd'] ) ? 'GD' : '' );
        };
        $webp = $eng( 'webp' );
        $avif = $eng( 'avif' );
        $cdn  = self::detect_cdn();
        $seo  = self::seo_plugin_name();
        $ai   = ERDO_AI::status();

        $works    = __( 'Works', 'erdo-image-optimizer' );
        $na       = __( 'Not available', 'erdo-image-optimizer' );
        $checks   = array();
        $checks[] = $webp
            /* translators: 1: GD or Imagick, 2: milliseconds */
            ? array( 'state' => 'ok', 'title' => 'WebP', 'text' => sprintf( __( 'Test images encoded with %1$s (%2$d ms in total).', 'erdo-image-optimizer' ), $webp, $ms ), 'badge' => $works )
            : array( 'state' => 'bad', 'title' => 'WebP', 'text' => __( 'This server cannot create WebP files. Ask your host for GD or Imagick with WebP support. SEO features still work.', 'erdo-image-optimizer' ), 'badge' => $na );
        $checks[] = $avif
            /* translators: %s: GD or Imagick */
            ? array( 'state' => 'ok', 'title' => 'AVIF', 'text' => sprintf( __( 'AVIF works with %s. Browsers that support it get even smaller files.', 'erdo-image-optimizer' ), $avif ), 'badge' => $works )
            : array( 'state' => 'info', 'title' => 'AVIF', 'text' => __( 'Not available on this server. WebP alone already saves most of the weight.', 'erdo-image-optimizer' ), 'badge' => $na );
        if ( $cdn ) {
            /* translators: %s: CDN name, e.g. Cloudflare */
            $checks[] = array( 'state' => 'warn', 'title' => sprintf( __( '%s in front of the site', 'erdo-image-optimizer' ), $cdn ), 'text' => __( 'Server rewrites would be cached for every browser, so &lt;picture&gt; tags are used (CDN-safe).', 'erdo-image-optimizer' ), 'badge' => __( 'Handled', 'erdo-image-optimizer' ) );
        }
        if ( $seo ) {
            /* translators: %s: SEO plugin name */
            $checks[] = array( 'state' => 'ok', 'title' => sprintf( __( '%s is active', 'erdo-image-optimizer' ), $seo ), 'text' => __( 'It already lists images in its sitemap, so ours stays off. Its focus keywords are used for scoring and renaming.', 'erdo-image-optimizer' ), 'badge' => __( 'Detected', 'erdo-image-optimizer' ) );
        }
        $checks[] = ! empty( $ai['ready'] )
            ? array( 'state' => 'ok', 'title' => __( 'AI provider', 'erdo-image-optimizer' ), 'text' => __( 'Connected through Settings > Connectors. Suggestions are only made when you ask for them.', 'erdo-image-optimizer' ), 'badge' => __( 'Connected', 'erdo-image-optimizer' ) )
            : array( 'state' => 'info', 'title' => __( 'AI provider', 'erdo-image-optimizer' ), 'text' => $ai['message'], 'badge' => __( 'Optional', 'erdo-image-optimizer' ) );

        $settings = array(
            'erdo_convert_format'    => $avif ? 'both' : 'webp',
            'erdo_quality'           => 80,
            'erdo_auto_webp'         => $webp ? 1 : 0,
            'erdo_serve_webp'        => $webp ? 1 : 0,
            'erdo_webp_serve_method' => 'picture',
            'erdo_auto_sanitize'     => 1,
            'erdo_image_sitemap'     => $seo ? 0 : 1,
        );
        $rec = array();
        if ( $webp ) {
            $rec[] = $avif
                ? __( 'Create AVIF and WebP copies at quality 80, for new uploads too', 'erdo-image-optimizer' )
                : __( 'Create WebP copies at quality 80, for new uploads too', 'erdo-image-optimizer' );
            $rec[] = __( 'Serve them with &lt;picture&gt; tags (works with every CDN and cache)', 'erdo-image-optimizer' );
        }
        $rec[] = __( 'Clean file names on upload; camera data (EXIF) is kept', 'erdo-image-optimizer' );
        $rec[] = $seo
            /* translators: %s: SEO plugin name */
            ? sprintf( __( 'Leave the image sitemap to %s', 'erdo-image-optimizer' ), $seo )
            : __( 'Add images to the WordPress sitemap', 'erdo-image-optimizer' );
        $rec[] = __( 'Quarantined images are kept until you delete them', 'erdo-image-optimizer' );

        set_transient( 'erdo_setup_rec', $settings, HOUR_IN_SECONDS );
        wp_send_json_success( array( 'checks' => $checks, 'rec' => $rec ) );
    }

    /** 2. adım: apply=1 önerilen ayarları uygular; her durumda sihirbaz tamamlanmış sayılır. */
    public function ajax_setup_apply() {
        check_ajax_referer( 'erdo_nonce', 'nonce' );
        $this->verify_capability();

        if ( ! empty( $_POST['apply'] ) ) {
            $settings = get_transient( 'erdo_setup_rec' );
            foreach ( is_array( $settings ) ? $settings : array() as $key => $value ) {
                update_option( $key, $value );
            }
            if ( ERDO_WebP_Server::htaccess_has_rules() && 'htaccess' !== get_option( 'erdo_webp_serve_method' ) ) {
                ERDO_WebP_Server::remove_webp_rules();
            }
            delete_transient( 'erdo_setup_rec' );
            ERDO_Auditor::clear_all_cache();
        }
        update_option( 'erdo_setup_done', 1, false );
        wp_send_json_success( array( 'index' => ERDO_Usage::state()['status'] ) );
    }

    public function ajax_usage_build_step() {
        check_ajax_referer( 'erdo_nonce', 'nonce' );
        $this->verify_capability();
        wp_send_json_success( ERDO_Usage::build_step( 8 ) );
    }

    /* ------------------------------------------------------------------
       2.x → 3.0 ONARIM
       Bildirim + iki AJAX ucu: tarama (dry-run raporu) ve adım adım onarım.
       Tam arayüz Faz 7'de; burada kullanılabilir asgari akış.
    ------------------------------------------------------------------ */
    public function migration_notice() {
        $count = (int) get_option( ERDO_Migration::LEGACY_OPTION, 0 );
        if ( $count < 1 || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $screen = get_current_screen();
        if ( $screen && ! in_array( $screen->id, array( 'dashboard', 'plugins', 'upload', 'toplevel_page_erdo-image-optimizer' ), true ) ) {
            return;
        }
        ?>
        <div class="notice notice-warning" id="erdo-migrate-notice">
            <p>
                <strong><?php esc_html_e( 'Erdo Image Optimizer 3.0 needs to repair some images.', 'erdo-image-optimizer' ); ?></strong>
                <?php
                printf(
                    /* translators: %d: number of images */
                    esc_html( _n(
                        '%d image was converted by an older version that replaced the original file. Pages that still link to the old file may show a broken image.',
                        '%d images were converted by an older version that replaced the original file. Pages that still link to the old files may show broken images.',
                        $count,
                        'erdo-image-optimizer'
                    ) ),
                    (int) $count
                );
                ?>
                <?php esc_html_e( 'Until the repair runs, the plugin redirects those links on the fly so visitors see the image.', 'erdo-image-optimizer' ); ?>
            </p>
            <p>
                <button type="button" class="button button-primary" id="erdo-migrate-btn" data-nonce="<?php echo esc_attr( wp_create_nonce( 'erdo_nonce' ) ); ?>">
                    <?php esc_html_e( 'Repair now', 'erdo-image-optimizer' ); ?>
                </button>
                <span id="erdo-migrate-status" style="margin-left:8px;"></span>
            </p>
        </div>
        <script>
        ( function () {
            var btn = document.getElementById( 'erdo-migrate-btn' );
            var out = document.getElementById( 'erdo-migrate-status' );
            if ( ! btn ) { return; }
            btn.addEventListener( 'click', function () {
                btn.disabled = true;
                var fixed = 0;
                var step = function () {
                    var body = new URLSearchParams( { action: 'erdo_migrate_step', nonce: btn.dataset.nonce } );
                    fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body: body } )
                        .then( function ( r ) { return r.json(); } )
                        .then( function ( res ) {
                            if ( ! res.success ) { out.textContent = ( res.data && res.data.message ) || <?php echo wp_json_encode( __( 'Error', 'erdo-image-optimizer' ) ); ?>; btn.disabled = false; return; }
                            fixed += res.data.restore + res.data.repoint;
                            out.textContent = <?php echo wp_json_encode( __( 'Repaired:', 'erdo-image-optimizer' ) ); ?> + ' ' + fixed + ' — ' + <?php echo wp_json_encode( __( 'remaining:', 'erdo-image-optimizer' ) ); ?> + ' ' + res.data.remaining;
                            if ( res.data.remaining > 0 ) { step(); } else { out.textContent += ' ✓'; }
                        } )
                        .catch( function () { out.textContent = <?php echo wp_json_encode( __( 'Error', 'erdo-image-optimizer' ) ); ?>; btn.disabled = false; } );
                };
                step();
            } );
        } )();
        </script>
        <?php
    }

    /**
     * 2.x'ten yükseltenlere bir kerelik: neler değişti, kaldırılan özellikler
     * ve yerine ne kullanılmalı. Kapatılana kadar yalnızca Pano, Eklentiler ve
     * eklenti sayfasında görünür.
     */
    public function upgrade_notice() {
        $flags = get_option( 'erdo_upgrade_notice' );
        if ( ! is_array( $flags ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $screen = get_current_screen();
        if ( $screen && ! in_array( $screen->id, array( 'dashboard', 'plugins', 'toplevel_page_erdo-image-optimizer' ), true ) ) {
            return;
        }
        ?>
        <div class="notice notice-info is-dismissible" id="erdo-upgrade-notice" data-nonce="<?php echo esc_attr( wp_create_nonce( 'erdo_nonce' ) ); ?>">
            <p><strong><?php esc_html_e( 'Erdo Image Optimizer 3.0: what changed', 'erdo-image-optimizer' ); ?></strong></p>
            <ul style="list-style:disc; margin-left:20px;">
                <li><?php esc_html_e( 'Your original images are never modified or deleted any more. WebP/AVIF copies are saved next to them and served automatically.', 'erdo-image-optimizer' ); ?></li>
                <li><?php esc_html_e( '"Delete" now moves images to a Quarantine you can restore from, and unused-image detection uses a full index of where every image is used.', 'erdo-image-optimizer' ); ?></li>
                <li><?php esc_html_e( 'New: 0–100 image SEO score, safe renaming with redirects, image sitemap and optional AI suggestions with your own provider key.', 'erdo-image-optimizer' ); ?></li>
                <?php if ( ! empty( $flags['svg'] ) ) : ?>
                <li><?php esc_html_e( 'SVG uploads were removed. If you still need them, install a dedicated plugin such as Safe SVG.', 'erdo-image-optimizer' ); ?></li>
                <?php endif; ?>
                <?php if ( ! empty( $flags['bunny'] ) ) : ?>
                <li><?php esc_html_e( 'The BunnyCDN upload was removed. Use a BunnyCDN pull zone (or your CDN plugin) instead: it picks up the WebP/AVIF copies on its own.', 'erdo-image-optimizer' ); ?></li>
                <?php endif; ?>
                <li><?php esc_html_e( 'Lazy loading and resizing on upload are now left to WordPress itself, which handles them better (including the largest image above the fold).', 'erdo-image-optimizer' ); ?></li>
            </ul>
        </div>
        <script>
        ( function () {
            var box = document.getElementById( 'erdo-upgrade-notice' );
            if ( ! box ) { return; }
            box.addEventListener( 'click', function ( e ) {
                if ( ! e.target.classList.contains( 'notice-dismiss' ) ) { return; }
                fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body: new URLSearchParams( { action: 'erdo_dismiss_upgrade', nonce: box.dataset.nonce } ) } );
            } );
        } )();
        </script>
        <?php
    }

    public function ajax_dismiss_upgrade() {
        check_ajax_referer( 'erdo_nonce', 'nonce' );
        $this->verify_capability();
        delete_option( 'erdo_upgrade_notice' );
        wp_send_json_success();
    }

    public function ajax_migrate_scan() {
        check_ajax_referer( 'erdo_nonce', 'nonce' );
        $this->verify_capability();
        $plans = array_map( array( 'ERDO_Migration', 'plan' ), ERDO_Migration::find_legacy() );
        wp_send_json_success( $plans );
    }

    public function ajax_migrate_step() {
        check_ajax_referer( 'erdo_nonce', 'nonce' );
        $this->verify_capability();
        wp_send_json_success( ERDO_Migration::run_batch( 10 ) );
    }

    /* ------------------------------------------------------------------
       REST API: /wp-json/erdo/v1/audit ve /wp-json/erdo/v1/stats
    ------------------------------------------------------------------ */
    public function register_rest_routes() {
        register_rest_route( 'erdo/v1', '/stats', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'rest_stats' ),
            'permission_callback' => array( $this, 'rest_permission' ),
        ) );

        register_rest_route( 'erdo/v1', '/ai/(?P<id>\\d+)/(?P<action>suggest|accept|reject)', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'rest_ai' ),
            'permission_callback' => array( $this, 'rest_ai_permission' ),
            'args'                => array(
                'id'     => array( 'sanitize_callback' => 'absint' ),
                'fields' => array( 'default' => array( 'alt' ) ),
                'edits'  => array( 'default' => array() ),
            ),
        ) );

        register_rest_route( 'erdo/v1', '/audit', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'rest_audit' ),
            'permission_callback' => array( $this, 'rest_permission' ),
            'args'                => array(
                'page'   => array( 'default' => 1, 'sanitize_callback' => 'absint' ),
                'status' => array( 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
            ),
        ) );
    }

    /** Tekil AI işlemleri: o görseli düzenleyebilen herkes (yazarlar dahil). */
    public function rest_ai_permission( WP_REST_Request $request ) {
        $id = (int) $request['id'];
        return 'attachment' === get_post_type( $id ) && current_user_can( 'upload_files' ) && current_user_can( 'edit_post', $id );
    }

    public function rest_ai( WP_REST_Request $request ) {
        $id     = (int) $request['id'];
        $action = $request['action'];
        if ( 'suggest' === $action ) {
            $r = ERDO_AI::suggest( $id );
        } elseif ( 'accept' === $action ) {
            $r = ERDO_AI::accept( $id, array_map( 'sanitize_key', (array) $request->get_param( 'fields' ) ), (array) $request->get_param( 'edits' ) );
        } else {
            $r = ERDO_AI::reject( $id );
        }
        return is_wp_error( $r ) ? $r : rest_ensure_response( $r );
    }

    public function rest_permission() {
        return current_user_can( 'manage_options' );
    }

    public function rest_stats( WP_REST_Request $request ) {
        $lib = ERDO_Converter::library_stats();

        return rest_ensure_response( array(
            'total'       => $lib['total'],
            'optimized'   => $lib['optimized'],
            'opt_rate'    => $lib['rate'],
            'total_saved' => $lib['saved'],
            'format'      => get_option( 'erdo_convert_format', 'webp' ),
        ) );
    }

    public function rest_audit( WP_REST_Request $request ) {
        $page          = $request->get_param( 'page' );
        $status_filter = $request->get_param( 'status' );
        $per_page      = 50;

        $query = new WP_Query( array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => 'image',
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'fields'         => 'ids',
        ) );

        $results = ERDO_Auditor::bulk_analyze( $query->posts );
        $items   = array();

        foreach ( $results as $id => $r ) {
            $cat = ( isset( $r['is_used'] ) && false === $r['is_used'] ) ? 'unused' : $r['status'];
            if ( $status_filter && $status_filter !== $cat ) {
                continue;
            }
            $items[] = array(
                'id'       => $id,
                'filename' => $r['filename'],
                'score'    => $r['score'],
                'status'   => $cat,
                'mime'     => $r['mime'] ?? '',
                'is_used'  => $r['is_used'],
                'edit_url' => get_edit_post_link( $id, 'raw' ),
            );
        }

        return rest_ensure_response( array(
            'items'      => $items,
            'page'       => $page,
            'max_pages'  => $query->max_num_pages,
            'total'      => $query->found_posts,
        ) );
    }
}
