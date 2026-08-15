<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class ERDO_Admin_Page {
// includes/class-admin-page.php içindeki __construct metodunu şu şekilde güncelle:

public function __construct() {
    add_action( 'admin_menu', array( $this, 'create_menu' ) );
    add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
    
    // Prefix her zaman en başta olmalı: wp_ajax_erdo_...
    add_action( 'wp_ajax_erdo_get_detail', array( $this, 'ajax_get_detail' ) );
    add_action( 'wp_ajax_erdo_save_data', array( $this, 'ajax_save_data' ) );
    add_action( 'wp_ajax_erdo_convert_webp', array( $this, 'ajax_convert_webp' ) );
    add_action( 'wp_ajax_erdo_refresh_all', array( $this, 'ajax_refresh_all' ) );
    add_action( 'wp_ajax_erdo_delete_image', array( $this, 'ajax_delete_image' ) );
    add_action( 'wp_ajax_erdo_get_bulk_list', array( $this, 'ajax_get_bulk_list' ) );
    add_action( 'wp_ajax_erdo_bulk_step', array( $this, 'ajax_bulk_step' ) );
    add_action( 'wp_ajax_erdo_get_unused_list', array( $this, 'ajax_get_unused_list' ) );
    add_action( 'wp_ajax_erdo_get_settings', array( $this, 'ajax_get_settings' ) );
    add_action( 'wp_ajax_erdo_save_settings', array( $this, 'ajax_save_settings' ) );
    add_action( 'wp_ajax_erdo_manual_resize', array( $this, 'ajax_manual_resize' ) );
    add_action( 'wp_ajax_erdo_load_images', array( $this, 'ajax_load_images' ) );
    add_action( 'wp_ajax_erdo_get_stats', array( $this, 'ajax_get_stats' ) );
    add_action( 'wp_ajax_erdo_scan_thumbnails', array( $this, 'ajax_scan_thumbnails' ) );

    // Ayrıca şu filtre ismini de eklenti slug'ına göre güncelle:
    add_filter( 'plugin_action_links_erdo-image-optimizer/erdo-image-optimizer.php', array( $this, 'add_settings_link' ) );
}

    public function add_settings_link( $links ) {
        $settings_link = '<a href="admin.php?page=erdo-image-optimizer">Settings</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }

    public function create_menu() {
        add_menu_page( 'Erdo Image Optimizer', 'Image Optimizer', 'manage_options', 'erdo-image-optimizer', array( $this, 'render_page' ), 'dashicons-performance', 11 );
    }

    public function assets( $hook ) {
        if ( 'toplevel_page_erdo-image-optimizer' !== $hook ) return;
        wp_enqueue_media();
        $ver = time(); 
        wp_enqueue_style( 'erdo-css', ERDO_URL . 'assets/css/admin.css', array(), $ver );
        wp_enqueue_script( 'erdo-js', ERDO_URL . 'assets/js/admin.js', array( 'jquery' ), $ver, true );
        wp_localize_script( 'erdo-js', 'erdo_vars', array( 
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'erdo_nonce' )
        ));
    }

    public function render_page() {
        $format = strtoupper( get_option( 'erdo_convert_format', 'webp' ) );
        ?>
        <div class="wrap erdo-wrapper">
            <h1 style="margin-bottom: 20px;">Erdo Image Optimizer</h1>

            <div class="erdo-stats-container">
                <div class="erdo-stat-card">
                    <div class="stat-info">
                        <h3>Optimization</h3>
                        <div class="stat-value" id="stat-opt-score">0%</div>
                        <div class="stat-sub">WebP/AVIF Format</div>
                    </div>
                    <div class="erdo-chart-ring" id="chart-opt-ring"><span>0%</span></div>
                </div>

                <div class="erdo-stat-card">
                    <div class="stat-info">
                        <h3>SEO Health</h3>
                        <div class="stat-value" id="stat-seo-score">0%</div>
                        <div class="stat-sub">Alt Tags Present</div>
                    </div>
                    <div class="erdo-chart-ring" id="chart-seo-ring" style="--color:#46b450;"><span>0%</span></div>
                </div>

                <div class="erdo-stat-card">
                    <div class="stat-info">
                        <h3>Total Library</h3>
                        <div class="stat-value" id="stat-total-count">0</div>
                        <div class="stat-sub" id="stat-total-size">Scanning...</div>
                    </div>
                    <div class="stat-icon"><span class="dashicons dashicons-images-alt2"></span></div>
                </div>

                <div class="erdo-stat-card">
                    <div class="stat-info">
                        <h3>Cleanup</h3>
                        <div class="stat-value" id="stat-unused-count">0</div>
                        <div class="stat-sub">Unused Images</div>
                    </div>
                    <div class="stat-icon red"><span class="dashicons dashicons-trash"></span></div>
                </div>

                <div class="erdo-stat-card">
                    <div class="stat-info">
                        <h3>Total Saved</h3>
                        <div class="stat-value" id="stat-total-saved">0 MB</div>
                        <div class="stat-sub">Disk Space Saved</div>
                    </div>
                    <div class="stat-icon green"><span class="dashicons dashicons-cloud-saved"></span></div>
                </div>
            </div>

            <div class="erdo-header">
                <div class="erdo-search-box" style="flex-grow:1; position:relative; min-width: 250px;">
                    <span class="dashicons dashicons-search" style="position:absolute; left:10px; top:8px; color:#aaa;"></span>
                    <input type="text" id="erdo-search-input" placeholder="Search images by filename or title..." style="width:100%; padding-left:35px;">
                </div>
                <div class="header-actions">
                    <button id="erdo-scan-thumbnails-btn" class="button button-secondary">🕵️ Find Missing Thumbnails</button>
                    <button id="erdo-settings-btn" class="button button-secondary"><span class="dashicons dashicons-admin-settings" style="line-height: 28px;"></span> Settings</button>
                    <button id="erdo-bulk-delete-btn" class="button button-link-delete hidden">Delete All Unused</button>
                    <button id="erdo-bulk-webp-btn" class="button button-secondary">Bulk Convert (<?php echo esc_html($format); ?>)</button>
                    <button id="erdo-upload-btn" class="button button-primary">Add New Image</button>
                </div>
            </div>

            <div id="erdo-bulk-progress-wrapper" class="hidden">
                <div class="erdo-progress-info">Processing: <span id="erdo-progress-current">0</span> / <span id="erdo-progress-total">0</span> images</div>
                <div class="erdo-progress-bar"><div id="erdo-progress-fill"></div></div>
            </div>
            
            <div class="erdo-tabs-container">
                <div class="erdo-tabs">
                    <button class="tab-btn btn-red active" data-target="tab-red">Poor <span class="count-badge" id="count-red">0</span></button>
                    <button class="tab-btn btn-orange" data-target="tab-orange">Warning <span class="count-badge" id="count-orange">0</span></button>
                    <button class="tab-btn btn-green" data-target="tab-green">Optimized <span class="count-badge" id="count-green">0</span></button>
                    <button class="tab-btn btn-gray" data-target="tab-gray">Unused <span class="count-badge" id="count-gray">0</span></button>
                </div>
                <div class="erdo-tab-actions">
                    <button id="erdo-refresh-all-btn" class="button button-secondary">Refresh All Audits</button>
                </div>
            </div>

            <div class="erdo-batch-notice">
                <span class="dashicons dashicons-info"></span>
                <span>
                    <strong>System Info:</strong> To maintain site performance, we scan your library in <strong>batches of 50 images</strong>. 
                    If you see <strong>"0"</strong>, it means none were found <em>in this batch</em>. 
                    Please continue clicking <strong>"Load More Images"</strong> to scan your entire library until you see <strong>"End of results"</strong>.
                </span>
            </div>

            <div class="erdo-main-content">
                <div id="tab-red" class="erdo-content active"><div class="erdo-grid"></div></div>
                <div id="tab-orange" class="erdo-content"><div class="erdo-grid"></div></div>
                <div id="tab-green" class="erdo-content"><div class="erdo-grid"></div></div>
                <div id="tab-gray" class="erdo-content"><div class="erdo-grid"></div></div>
            </div>

            <div style="text-align:center; margin: 20px 0;">
                <button id="erdo-load-more-btn" class="button button-primary button-large">Load More Images</button>
                <p id="erdo-no-more-msg" class="hidden" style="color:#666; margin-top:10px;">End of results.</p>
            </div>
        </div>
        
        <div id="erdo-modal-overlay" class="hidden"><div id="erdo-modal"><button id="erdo-close-btn">&times;</button><div id="erdo-modal-content"></div></div></div>

        <div id="erdo-safety-modal-overlay" class="hidden">
            <div class="erdo-safety-box">
                <span class="dashicons dashicons-warning erdo-safety-icon"></span>
                <h2 class="erdo-safety-title">⚠️ DANGER ZONE</h2>
                <div class="erdo-safety-desc">
                    <p><strong>This action is irreversible!</strong></p>
                    <p>You are about to delete ALL images detected as "Unused". While we scan page builders and posts, some images might still be in use by custom code or hardcoded links.</p>
                    <p>We strongly recommend <strong>backing up your site</strong> before proceeding.</p>
                </div>
                <input type="text" id="erdo-safety-input" class="erdo-safety-input" placeholder="Type DELETE to confirm">
                <button id="erdo-safety-confirm-btn" disabled>CONFIRM DELETE</button>
                <button id="erdo-safety-cancel-btn">Cancel</button>
            </div>
        </div>
        <?php
    }

    // --- [DEĞİŞİKLİK] SAVE DATA ARTIK YENİ DURUMU DÖNDÜRÜYOR ---
    public function ajax_save_data() { 
        check_ajax_referer( 'erdo_nonce', 'nonce' ); 
$id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
        ERDO_Auditor::save_image_data( $id, $_POST ); 
        
        // Tekrar analiz et ve yeni datayı gönder
        $new_data = ERDO_Auditor::analyze( $id );
        
        wp_send_json_success( $new_data ); 
    }

public function ajax_scan_thumbnails() {
    check_ajax_referer( 'erdo_nonce', 'nonce' );

    global $wpdb;

global $wpdb;

$cache_key   = 'erdo_missing_thumbnails';
$cache_group = 'erdo_image_optimizer';

$results = wp_cache_get( $cache_key, $cache_group );

if ( false === $results ) {

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$results = $wpdb->get_results(        "
        SELECT p.ID, p.post_title
        FROM {$wpdb->posts} p
        LEFT JOIN {$wpdb->postmeta} pm
            ON p.ID = pm.post_id
            AND pm.meta_key = '_thumbnail_id'
        WHERE p.post_type = 'post'
          AND p.post_status = 'publish'
          AND pm.post_id IS NULL
        LIMIT 50
        ",
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
                'link'  => get_edit_post_link( $row['ID'] )
            );
        }
    }

    wp_send_json_success( $missing );
}
public function ajax_load_images() {
    check_ajax_referer( 'erdo_nonce', 'nonce' );

    $paged = isset( $_POST['page'] ) ? intval( $_POST['page'] ) : 1;

    $search = isset( $_POST['search'] )
        ? sanitize_text_field( wp_unslash( $_POST['search'] ) )
        : '';

    $args = array(
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'post_mime_type' => 'image',
        'posts_per_page' => 50,
        'paged'          => $paged,
        's'              => $search,
        'fields'         => 'ids', // Sadece ID'leri çek, daha hızlı!
    );

    $query = new WP_Query( $args );
    
    $buckets = array( 'red' => '', 'orange' => '', 'green' => '', 'gray' => '' );
    $counts  = array( 'red' => 0, 'orange' => 0, 'green' => 0, 'gray' => 0 );

    if ( ! empty( $query->posts ) ) {
        // ⚡ KRİTİK: Tüm görselleri toplu analiz et!
        $results = ERDO_Auditor::bulk_analyze( $query->posts );
        
        foreach ( $results as $id => $res ) {
            $cat = $res['status'];

            if ( isset( $res['is_used'] ) && false === $res['is_used'] ) {
                $cat = 'gray';
            }

            $counts[ $cat ]++;

            $html  = '<div class="erdo-card border-' . esc_attr( $cat ) . '" data-id="' . esc_attr( $id ) . '">';
            $html .= '<div class="card-img-holder"><img src="' . esc_url( $res['thumbnail'] ) . '"></div>';
            $html .= '<div class="erdo-card-info">' . esc_html( $res['filename'] ) . '</div>';
            $html .= '</div>';

            $buckets[ $cat ] .= $html;
        }
    }

    $max_pages = $query->max_num_pages;
    $found     = $query->found_posts;

    wp_send_json_success(
        array(
            'html'      => $buckets,
            'counts'    => $counts,
            'max_pages' => $max_pages,
            'found'     => $found,
        )
    );
}
public function ajax_get_stats() {
    check_ajax_referer( 'erdo_nonce', 'nonce' );

    $cache_key = 'erdo_stats_cache';
    $cache_group = 'erdo_image_optimizer';

    $cached = wp_cache_get( $cache_key, $cache_group );
    if ( $cached !== false ) {
        wp_send_json_success( $cached );
    }

    global $wpdb;

    // Toplam görsel sayısı
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$total = (int) $wpdb->get_var(        "SELECT COUNT(ID)
         FROM {$wpdb->posts}
         WHERE post_type = 'attachment'
         AND post_mime_type LIKE 'image/%'"
    );

    // WebP / AVIF sayısı
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$count_optimized = (int) $wpdb->get_var(        "SELECT COUNT(ID)
         FROM {$wpdb->posts}
         WHERE post_type = 'attachment'
         AND post_mime_type IN ('image/webp','image/avif')"
    );

    // Alt etiketi olan görseller
global $wpdb;

$cache_key = 'erdo_count_seo';

$count_seo = wp_cache_get( $cache_key );

if ( false === $count_seo ) {
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$count_seo = (int) $wpdb->get_var(        "SELECT COUNT(p.ID)
         FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm
            ON p.ID = pm.post_id
         WHERE p.post_type = 'attachment'
         AND pm.meta_key = '_wp_attachment_image_alt'
         AND pm.meta_value != ''"
    );

    wp_cache_set( $cache_key, $count_seo, '', 300 );
}



    $opt_score = ( $total > 0 ) ? round( ( $count_optimized / $total ) * 100 ) : 0;
    $seo_score = ( $total > 0 ) ? round( ( $count_seo / $total ) * 100 ) : 0;

    $saved = (int) get_option( 'erdo_total_saved_bytes', 0 );

    $result = array(
        'total'        => $total,
        'opt_score'    => $opt_score,
        'seo_score'    => $seo_score,
        'total_saved'  => size_format( $saved, 2 )
    );

    // 5 dakika cache
    wp_cache_set( $cache_key, $result, $cache_group, 300 );

    wp_send_json_success( $result );
}
    
    public function ajax_get_detail() { 
check_ajax_referer( 'erdo_nonce', 'nonce' );

if ( ! isset( $_POST['id'] ) ) {
    wp_send_json_error( 'Missing image ID' );
}

$id = intval( $_POST['id'] );
$data = ERDO_Auditor::analyze( $id );
        $help = array( 'Filename' => 'Search engines prefer file names that are descriptive and use hyphens (-) as separators. Avoid spaces, underscores, or strange characters.', 'Alt Text' => 'The Alternative Text helps search engines understand what is in the image. It is also read by screen readers for accessibility.', 'Image Title' => 'The title attribute provides additional information and appears when a user hovers over the image.', 'Dimensions' => 'Large images slow down your site. Resize images to the maximum width required by your theme (usually 1920px or less).', 'Format' => 'Modern formats like WebP and AVIF provide better Compression than JPEG/PNG without losing quality, making your site faster.', 'File Size' => 'Heavy images are the #1 cause of slow websites. Aim to keep your images under 250KB.' );
        $usage_msg = (isset($data['is_used']) && !$data['is_used']) ? '<div style="color:red; margin-bottom:10px; font-weight:bold;">⚠️ Unused Image (Gray List)</div>' : ''; $format = strtoupper( get_option( 'erdo_convert_format', 'webp' ) ); $show_comparison = false; if ( !empty($data['saved_bytes']) && !empty($data['original_size']) ) { $show_comparison = true; $orig_size_fmt = size_format($data['original_size'], 1); $curr_size_fmt = size_format($data['current_size'], 1); $saved_fmt = size_format($data['saved_bytes'], 1); if ( $data['original_size'] > 0 ) { $saved_percent = round( ($data['saved_bytes'] / $data['original_size']) * 100 ); $bar_curr = round( ($data['current_size'] / $data['original_size']) * 100 ) . '%'; } else { $saved_percent = 0; $bar_curr = '100%'; } $bar_orig = '100%'; } ?>
<div class="modal-header">
    <h2>Audit Report (Score: <?php echo esc_html( $data['score'] ); ?>/6)</h2>
</div>

<form id="erdo-audit-form">
    <input type="hidden" name="post_id" value="<?php echo esc_attr( $id ); ?>">
    <input type="hidden" id="erdo-parent-title" value="<?php echo esc_attr( $data['parent_title'] ); ?>">
    <input type="hidden" id="erdo-current-filename" value="<?php echo esc_attr( $data['filename'] ); ?>">

    <div class="erdo-modal-flex">
        <div class="erdo-modal-left">
            <div class="modal-preview">
                <img src="<?php echo esc_url( $data['thumbnail'] ); ?>">
            </div>

            <?php if ( $show_comparison ) : ?>
                <div class="erdo-comparison-box" style="margin-top:15px; padding:15px; background:#f0f0f1; border-radius:8px; border:1px solid #ddd;">
                    <h4 style="margin:0 0 10px 0; text-align:center;">🚀 Optimization Result</h4>

                    <div style="margin-bottom:8px; font-size:12px; display:flex; justify-content:space-between;">
                        <span>Original</span>
                        <span><?php echo esc_html( $orig_size_fmt ); ?></span>
                    </div>

                    <div style="height:12px; background:#ffcfcf; border-radius:6px; overflow:hidden; margin-bottom:10px;">
                        <div style="height:100%; width:<?php echo esc_attr( $bar_orig ); ?>; background:#d63638;"></div>
                    </div>

                    <div style="margin-bottom:8px; font-size:12px; display:flex; justify-content:space-between;">
                        <span>Optimized</span>
                        <span><?php echo esc_html( $curr_size_fmt ); ?></span>
                    </div>

                    <div style="height:12px; background:#cfffd3; border-radius:6px; overflow:hidden;">
                        <div style="height:100%; width:<?php echo esc_attr( $bar_curr ); ?>; background:#46b450;"></div>
                    </div>

                    <div style="text-align:center; margin-top:10px; font-weight:bold; color:#46b450;">
                        ✨ You saved <?php echo esc_html( $saved_percent ); ?>% (<?php echo esc_html( $saved_fmt ); ?>)
                    </div>
                </div>
            <?php endif; ?>

            <div class="modal-sidebar-actions" style="margin-top:15px;">
                <?php echo wp_kses_post( $usage_msg ); ?>

                <button type="button" id="erdo-save-btn" class="button button-primary full-width">Save Changes</button>

                <?php if ( strpos( get_post_mime_type( $id ), 'webp' ) === false && strpos( get_post_mime_type( $id ), 'avif' ) === false ) : ?>
                    <button type="button"
                            class="webp-convert-btn button full-width"
                            data-id="<?php echo esc_attr( $id ); ?>">
                        Convert to <?php echo esc_html( $format ); ?>
                    </button>
                <?php endif; ?>

                <button type="button"
                        class="erdo-delete-btn button button-link-delete full-width"
                        data-id="<?php echo esc_attr( $id ); ?>">
                    Delete Image
                </button>
            </div>
        </div>

        <div class="erdo-modal-right">
            <ul class="erdo-checklist">
                <?php foreach ( $data['checklist'] as $item ) : ?>
                    <li class="<?php echo esc_attr( $item['pass'] ? 'pass' : 'fail' ); ?>">
                        <div class="label-row">
                            <span class="audit-label">
                                <strong><?php echo esc_html( $item['label'] ); ?></strong>
                            </span>

                            <?php if ( isset( $help[ $item['label'] ] ) ) : ?>
                                <div class="hotspot-container">
                                    <span class="erdo-hotspot">i</span>
                                    <div class="erdo-help-content">
                                        <?php echo esc_html( $help[ $item['label'] ] ); ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if ( 'Dimensions' === $item['label'] ) : ?>
                            <div style="display:flex; gap:5px; align-items:center;">
                                <input type="number"
                                       id="erdo-w-<?php echo esc_attr( $id ); ?>"
                                       value="<?php echo esc_attr( $item['width'] ); ?>"
                                       class="erdo-input"
                                       style="width:70px;"
                                       placeholder="W">
                                x
                                <input type="number"
                                       id="erdo-h-<?php echo esc_attr( $id ); ?>"
                                       value="<?php echo esc_attr( $item['height'] ); ?>"
                                       class="erdo-input"
                                       style="width:70px;"
                                       placeholder="H">
                                <button type="button"
                                        class="button button-small erdo-resize-btn"
                                        data-id="<?php echo esc_attr( $id ); ?>">
                                    Resize
                                </button>
                            </div>

                        <?php elseif ( in_array( $item['label'], array( 'Filename', 'Alt Text', 'Image Title' ), true ) ) : ?>

                            <?php
                            $field = ( 'Filename' === $item['label'] )
                                ? 'filename'
                                : ( 'Alt Text' === $item['label'] ? 'alt_text' : 'title' );
                            ?>

                            <div style="display:flex; gap:5px; align-items:center;">
                                <input type="text"
                                       id="erdo-input-<?php echo esc_attr( $field ); ?>"
                                       name="<?php echo esc_attr( $field ); ?>"
                                       value="<?php echo esc_attr( $item['value'] ); ?>"
                                       class="erdo-input">

                                <?php if ( 'Alt Text' === $item['label'] ) : ?>
                                    <?php if ( ! empty( $data['parent_title'] ) ) : ?>
                                        <button type="button"
                                                class="button button-small erdo-magic-btn"
                                                data-target="alt_text"
                                                data-source="parent"
                                                title="Use Post Title">📄</button>
                                    <?php endif; ?>

                                    <button type="button"
                                            class="button button-small erdo-magic-btn"
                                            data-target="alt_text"
                                            data-source="filename"
                                            title="Generate from Filename">✨</button>
                                <?php endif; ?>

                                <?php if ( 'Image Title' === $item['label'] ) : ?>
                                    <button type="button"
                                            class="button button-small erdo-magic-btn"
                                            data-target="title"
                                            data-source="filename"
                                            title="Generate from Filename">✨</button>
                                <?php endif; ?>
                            </div>

                        <?php else : ?>
                            <div class="desc"><?php echo esc_html( $item['msg'] ); ?></div>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</form>

    <?php
$output = ob_get_clean();
echo wp_kses_post( $output );
    wp_die();
}

public function ajax_save_settings() {

    check_ajax_referer( 'erdo_nonce', 'nonce' );

    update_option(
        'erdo_auto_webp',
        isset( $_POST['erdo_auto_webp'] ) ? 1 : 0
    );

    update_option(
        'erdo_auto_sanitize',
        isset( $_POST['erdo_auto_sanitize'] ) ? 1 : 0
    );

    update_option(
        'erdo_remove_exif',
        isset( $_POST['erdo_remove_exif'] ) ? 1 : 0
    );

    if ( isset( $_POST['erdo_convert_format'] ) ) {
        $format = sanitize_text_field(
            wp_unslash( $_POST['erdo_convert_format'] )
        );
        update_option( 'erdo_convert_format', $format );
    }

    if ( isset( $_POST['erdo_quality'] ) ) {
        $q = intval(
            wp_unslash( $_POST['erdo_quality'] )
        );

        if ( $q < 10 ) {
            $q = 10;
        }

        if ( $q > 100 ) {
            $q = 100;
        }

        update_option( 'erdo_quality', $q );
    }

    wp_send_json_success();
}
public function ajax_delete_image() {

    check_ajax_referer( 'erdo_nonce', 'nonce' );

    if ( ! isset( $_POST['id'] ) ) {
        wp_send_json_error();
    }

    $id = intval(
        wp_unslash( $_POST['id'] )
    );

    ERDO_Auditor::delete_image( $id );

    wp_send_json_success();
}
public function ajax_convert_webp() {

    check_ajax_referer( 'erdo_nonce', 'nonce' );

    if ( ! isset( $_POST['id'] ) ) {
        wp_send_json_error();
    }

    $id = intval(
        wp_unslash( $_POST['id'] )
    );

    ERDO_Auditor::convert_to_webp( $id );

    $this->ajax_get_detail();
}
    public function ajax_refresh_all() { check_ajax_referer('erdo_nonce','nonce'); ERDO_Auditor::clear_all_cache(); wp_send_json_success(); }
    public function ajax_get_bulk_list() { check_ajax_referer('erdo_nonce','nonce'); $args=array('post_type'=>'attachment','post_status'=>'inherit','post_mime_type'=>array('image/jpeg','image/png'),'posts_per_page'=>-1,'fields'=>'ids'); $query=new WP_Query($args); wp_send_json_success($query->posts); }
public function ajax_bulk_step() {

    check_ajax_referer( 'erdo_nonce', 'nonce' );

    if ( ! isset( $_POST['id'] ) ) {
        wp_send_json_error();
    }

    $id = intval(
        wp_unslash( $_POST['id'] )
    );

    $res = ERDO_Auditor::convert_to_webp( $id );

    if ( $res ) {
        wp_send_json_success();
    } else {
        wp_send_json_error();
    }
}
    public function ajax_get_unused_list() { check_ajax_referer('erdo_nonce','nonce'); $query=new WP_Query(array('post_type'=>'attachment','post_status'=>'inherit','post_mime_type'=>'image','posts_per_page'=>-1,'fields'=>'ids')); $unused=array(); foreach($query->posts as $id){ $d=ERDO_Auditor::analyze($id); if(isset($d['is_used']) && !$d['is_used']) $unused[]=$id; } wp_send_json_success($unused); }
public function ajax_manual_resize() {

    check_ajax_referer( 'erdo_nonce', 'nonce' );

    if (
        ! isset( $_POST['id'], $_POST['w'], $_POST['h'] )
    ) {
        wp_send_json_error();
    }

    $id = intval(
        wp_unslash( $_POST['id'] )
    );

    $w = intval(
        wp_unslash( $_POST['w'] )
    );

    $h = intval(
        wp_unslash( $_POST['h'] )
    );

    $res = ERDO_Auditor::manual_resize( $id, $w, $h );

    if ( $res ) {
        wp_send_json_success();
    } else {
        wp_send_json_error();
    }
}
    
public function ajax_get_settings() {
    check_ajax_referer( 'erdo_nonce', 'nonce' );

    $aw   = get_option( 'erdo_auto_webp' );
    $as   = get_option( 'erdo_auto_sanitize' );
    $re   = get_option( 'erdo_remove_exif' );
    $fmt  = get_option( 'erdo_convert_format', 'webp' );
    $q    = get_option( 'erdo_quality', 80 );
    $avif = function_exists( 'imageavif' );
    ?>

    <div class="modal-header">
        <h2>Settings</h2>
    </div>

    <form id="erdo-settings-form">
        <div style="padding:20px 0;">

            <div style="margin-bottom:20px; padding:15px; border:1px solid #ddd; background:#fafafa;">
                <label><strong>Conversion Format</strong></label>

                <div style="margin-top:10px;">
                    <select name="erdo_convert_format" style="width:100%;">
                        <option value="webp" <?php selected( $fmt, 'webp' ); ?>>
                            WebP (Recommended)
                        </option>
                        <option value="avif"
                            <?php selected( $fmt, 'avif' ); ?>
                            <?php echo $avif ? '' : 'disabled'; ?>>
                            AVIF (Best Compression, requires server support)
                        </option>
                    </select>
                </div>

                <p style="margin-top:5px; color:#666; font-size:12px;">
                    Choose the modern image format you want to use. WebP is supported by all modern browsers.
                </p>
            </div>

            <div style="margin-bottom:20px; padding:15px; border:1px solid #ddd; background:#fafafa;">
                <label>
                    <strong>Compression Quality:</strong>
                    <span id="erdo-quality-val"><?php echo esc_html( $q ); ?>%</span>
                </label>

                <input type="range"
                       name="erdo_quality"
                       value="<?php echo esc_attr( $q ); ?>"
                       min="10"
                       max="100"
                       oninput="document.getElementById('erdo-quality-val').innerText=this.value+'%'"
                       style="width:100%; margin-top:10px;">

                <p style="margin-top:5px; color:#666; font-size:12px;">
                    Lower quality means smaller file size but potential loss of detail. 80% is the sweet spot.
                </p>
            </div>

            <div style="margin-bottom:20px; padding:15px; border:1px solid #ddd; background:#fafafa;">
                <label style="display:block; margin-bottom:10px;">
                    <input type="checkbox"
                           name="erdo_auto_webp"
                           value="1"
                           <?php checked( $aw, 1 ); ?>>
                    <strong>Auto-Convert on Upload</strong>
                </label>

                <label style="display:block; margin-bottom:10px;">
                    <input type="checkbox"
                           name="erdo_auto_sanitize"
                           value="1"
                           <?php checked( $as, 1 ); ?>>
                    <strong>Sanitize Filenames</strong>
                </label>

                <label style="display:block;">
                    <input type="checkbox"
                           name="erdo_remove_exif"
                           value="1"
                           <?php checked( $re, 1 ); ?>>
                    <strong>Remove EXIF Data</strong>
                </label>
            </div>

            <button type="button"
                    id="erdo-save-settings-btn"
                    class="button button-primary button-large"
                    style="width:100%;">
                Save Settings
            </button>

        </div>
    </form>

    <?php
    wp_die();
}

}