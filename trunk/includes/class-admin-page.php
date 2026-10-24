<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class ERDO_Admin_Page {

    public function __construct() {
        add_action( 'admin_menu',             array( $this, 'create_menu' ) );
        add_action( 'admin_enqueue_scripts',  array( $this, 'assets' ) );

        add_action( 'wp_ajax_erdo_get_detail',       array( $this, 'ajax_get_detail' ) );
        add_action( 'wp_ajax_erdo_save_data',        array( $this, 'ajax_save_data' ) );
        add_action( 'wp_ajax_erdo_convert_webp',     array( $this, 'ajax_convert_webp' ) );
        add_action( 'wp_ajax_erdo_refresh_all',      array( $this, 'ajax_refresh_all' ) );
        add_action( 'wp_ajax_erdo_delete_image',     array( $this, 'ajax_delete_image' ) );
        add_action( 'wp_ajax_erdo_get_bulk_list',    array( $this, 'ajax_get_bulk_list' ) );
        add_action( 'wp_ajax_erdo_bulk_step',        array( $this, 'ajax_bulk_step' ) );
        add_action( 'wp_ajax_erdo_get_unused_list',  array( $this, 'ajax_get_unused_list' ) );
        add_action( 'wp_ajax_erdo_get_settings',     array( $this, 'ajax_get_settings' ) );
        add_action( 'wp_ajax_erdo_save_settings',    array( $this, 'ajax_save_settings' ) );
        add_action( 'wp_ajax_erdo_manual_resize',    array( $this, 'ajax_manual_resize' ) );
        add_action( 'wp_ajax_erdo_load_images',      array( $this, 'ajax_load_images' ) );
        add_action( 'wp_ajax_erdo_get_stats',        array( $this, 'ajax_get_stats' ) );
        add_action( 'wp_ajax_erdo_scan_thumbnails',  array( $this, 'ajax_scan_thumbnails' ) );
        add_action( 'wp_ajax_erdo_restore_original',  array( $this, 'ajax_restore_original' ) );
        add_action( 'wp_ajax_erdo_start_queue',       array( $this, 'ajax_start_queue' ) );
        add_action( 'wp_ajax_erdo_cancel_queue',      array( $this, 'ajax_cancel_queue' ) );
        add_action( 'wp_ajax_erdo_queue_status',      array( $this, 'ajax_queue_status' ) );
        add_action( 'wp_ajax_erdo_write_htaccess',    array( $this, 'ajax_write_htaccess' ) );
        add_action( 'wp_ajax_erdo_remove_htaccess',   array( $this, 'ajax_remove_htaccess' ) );
        add_action( 'wp_ajax_erdo_rename_file',       array( $this, 'ajax_rename_file' ) );
        add_action( 'wp_ajax_erdo_bulk_rename',       array( $this, 'ajax_bulk_rename' ) );
        add_action( 'wp_ajax_erdo_export_csv',        array( $this, 'ajax_export_csv' ) );
        add_action( 'wp_ajax_erdo_bulk_alt_text',     array( $this, 'ajax_bulk_alt_text' ) );
        add_action( 'wp_ajax_erdo_cdn_upload',        array( $this, 'ajax_cdn_upload' ) );
        add_action( 'wp_ajax_erdo_migrate_scan',      array( $this, 'ajax_migrate_scan' ) );
        add_action( 'wp_ajax_erdo_migrate_step',      array( $this, 'ajax_migrate_step' ) );
        add_action( 'wp_ajax_erdo_quarantine_unused', array( $this, 'ajax_quarantine_unused' ) );
        add_action( 'wp_ajax_erdo_quarantine_list',   array( $this, 'ajax_quarantine_list' ) );
        add_action( 'wp_ajax_erdo_quarantine_restore', array( $this, 'ajax_quarantine_restore' ) );
        add_action( 'wp_ajax_erdo_quarantine_purge',  array( $this, 'ajax_quarantine_purge' ) );
        add_action( 'wp_ajax_erdo_usage_build_step',  array( $this, 'ajax_usage_build_step' ) );
        add_action( 'admin_notices',                  array( $this, 'migration_notice' ) );

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
            wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
        }
    }

    /* ------------------------------------------------------------------
       ADMIN MENÜ & ASSETS
    ------------------------------------------------------------------ */
    public function add_settings_link( $links ) {
        $settings_link = '<a href="admin.php?page=erdo-image-optimizer">Settings</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }

    public function create_menu() {
        add_menu_page(
            'Erdo Image Optimizer',
            'Image Optimizer',
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
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'erdo_nonce' ),
        ) );
    }

    /* ------------------------------------------------------------------
       ANA SAYFA HTML
    ------------------------------------------------------------------ */
    public function render_page() {
        $format = strtoupper( get_option( 'erdo_convert_format', 'webp' ) );
        ?>
        <div class="wrap erdo-wrapper">

            <div class="erdo-page-header">
                <h1>
                    <span class="dashicons dashicons-performance"></span>
                    <?php esc_html_e( 'Erdo Image Optimizer', 'erdo-image-optimizer' ); ?>
                    <span class="erdo-version-badge">v<?php echo esc_html( ERDO_VERSION ); ?></span>
                </h1>
            </div>

            <div class="erdo-stats-container">
                <div class="erdo-stat-card">
                    <div class="stat-info">
                        <h3><?php esc_html_e( 'Optimization', 'erdo-image-optimizer' ); ?></h3>
                        <div class="stat-value" id="stat-opt-score">—</div>
                        <div class="stat-sub"><?php esc_html_e( 'WebP/AVIF Format', 'erdo-image-optimizer' ); ?></div>
                    </div>
                    <div class="erdo-chart-ring" id="chart-opt-ring"><span>0%</span></div>
                </div>
                <div class="erdo-stat-card">
                    <div class="stat-info">
                        <h3><?php esc_html_e( 'SEO Health', 'erdo-image-optimizer' ); ?></h3>
                        <div class="stat-value" id="stat-seo-score">—</div>
                        <div class="stat-sub"><?php esc_html_e( 'Alt Tags Present', 'erdo-image-optimizer' ); ?></div>
                    </div>
                    <div class="erdo-chart-ring" id="chart-seo-ring" style="--color:#00a32a;"><span>0%</span></div>
                </div>
                <div class="erdo-stat-card">
                    <div class="stat-info">
                        <h3><?php esc_html_e( 'Total Library', 'erdo-image-optimizer' ); ?></h3>
                        <div class="stat-value" id="stat-total-count">—</div>
                        <div class="stat-sub" id="stat-total-size"><?php esc_html_e( 'Scanning...', 'erdo-image-optimizer' ); ?></div>
                    </div>
                    <div class="stat-icon"><span class="dashicons dashicons-images-alt2"></span></div>
                </div>
                <div class="erdo-stat-card">
                    <div class="stat-info">
                        <h3><?php esc_html_e( 'Unused Images', 'erdo-image-optimizer' ); ?></h3>
                        <div class="stat-value" id="stat-unused-count">—</div>
                        <div class="stat-sub"><?php esc_html_e( 'Potential Cleanup', 'erdo-image-optimizer' ); ?></div>
                    </div>
                    <div class="stat-icon red"><span class="dashicons dashicons-trash"></span></div>
                </div>
                <div class="erdo-stat-card">
                    <div class="stat-info">
                        <h3><?php esc_html_e( 'Total Saved', 'erdo-image-optimizer' ); ?></h3>
                        <div class="stat-value" id="stat-total-saved">—</div>
                        <div class="stat-sub"><?php esc_html_e( 'Lighter for Visitors', 'erdo-image-optimizer' ); ?></div>
                    </div>
                    <div class="stat-icon green"><span class="dashicons dashicons-cloud-saved"></span></div>
                </div>
            </div>

            <div class="erdo-header">
                <div class="erdo-search-box">
                    <span class="dashicons dashicons-search"></span>
                    <input type="text" id="erdo-search-input"
                        placeholder="<?php esc_attr_e( 'Search images by filename or title...', 'erdo-image-optimizer' ); ?>">
                </div>
                <div class="header-actions">
                    <button id="erdo-settings-btn" class="button button-secondary">
                        <span class="dashicons dashicons-admin-settings"></span>
                        <?php esc_html_e( 'Settings', 'erdo-image-optimizer' ); ?>
                    </button>
                    <div class="erdo-dropdown-wrap">
                        <button id="erdo-tools-btn" class="button button-secondary">
                            <span class="dashicons dashicons-admin-tools"></span>
                            <?php esc_html_e( 'Tools', 'erdo-image-optimizer' ); ?>
                            <span class="dashicons dashicons-arrow-down-alt2 erdo-chevron"></span>
                        </button>
                        <div class="erdo-dropdown" id="erdo-tools-dropdown">
                            <button class="erdo-dropdown-item" id="erdo-bulk-rename-btn">
                                <span class="dashicons dashicons-tag"></span>
                                <?php esc_html_e( 'Fix All Filenames', 'erdo-image-optimizer' ); ?>
                            </button>
                            <button class="erdo-dropdown-item" id="erdo-bulk-alt-btn">
                                <span class="dashicons dashicons-format-image"></span>
                                <?php esc_html_e( 'Auto Alt Text', 'erdo-image-optimizer' ); ?>
                            </button>
                            <button class="erdo-dropdown-item" id="erdo-export-csv-btn">
                                <span class="dashicons dashicons-download"></span>
                                <?php esc_html_e( 'Export SEO CSV', 'erdo-image-optimizer' ); ?>
                            </button>
                            <div class="erdo-dropdown-sep"></div>
                            <button class="erdo-dropdown-item" id="erdo-scan-thumbnails-btn">
                                <span class="dashicons dashicons-search"></span>
                                <?php esc_html_e( 'Missing Thumbnails', 'erdo-image-optimizer' ); ?>
                            </button>
                        </div>
                    </div>
                    <button id="erdo-bulk-delete-btn" class="button button-link-delete hidden">
                        <span class="dashicons dashicons-archive"></span>
                        <?php esc_html_e( 'Quarantine All Unused', 'erdo-image-optimizer' ); ?>
                    </button>
                    <button id="erdo-bulk-webp-btn" class="button button-secondary">
                        <span class="dashicons dashicons-update"></span>
                        <?php
                        printf(
                            /* translators: %s: image format name (WebP or AVIF) */
                            esc_html__( 'Bulk Convert (%s)', 'erdo-image-optimizer' ),
                            esc_html( $format )
                        );
                        ?>
                    </button>
                    <button id="erdo-upload-btn" class="button button-primary">
                        <span class="dashicons dashicons-plus-alt"></span>
                        <?php esc_html_e( 'Add New', 'erdo-image-optimizer' ); ?>
                    </button>
                </div>
            </div>

            <div id="erdo-bulk-progress-wrapper" class="hidden">
                <div class="erdo-progress-info">
                    <span><?php esc_html_e( 'Processing...', 'erdo-image-optimizer' ); ?></span>
                    <span><span id="erdo-progress-current">0</span> / <span id="erdo-progress-total">0</span></span>
                </div>
                <div class="erdo-progress-bar"><div id="erdo-progress-fill"></div></div>
            </div>

            <?php $usage_state = ERDO_Usage::state(); ?>
            <div class="erdo-batch-notice<?php echo 'ready' === $usage_state['status'] ? ' hidden' : ''; ?>" id="erdo-index-notice"
                 data-status="<?php echo esc_attr( $usage_state['status'] ); ?>">
                <span class="dashicons dashicons-update erdo-spin"></span>
                <span id="erdo-index-text">
                    <?php esc_html_e( 'Building the usage index (where each image is used). Unused-image cleanup becomes available when it finishes.', 'erdo-image-optimizer' ); ?>
                </span>
            </div>

            <div class="erdo-tabs-container">
                <div class="erdo-tabs">
                    <button class="tab-btn btn-red active" data-target="tab-red">
                        <?php esc_html_e( 'Poor', 'erdo-image-optimizer' ); ?>
                        <span class="count-badge" id="count-red">0</span>
                    </button>
                    <button class="tab-btn btn-orange" data-target="tab-orange">
                        <?php esc_html_e( 'Warning', 'erdo-image-optimizer' ); ?>
                        <span class="count-badge" id="count-orange">0</span>
                    </button>
                    <button class="tab-btn btn-green" data-target="tab-green">
                        <?php esc_html_e( 'Optimized', 'erdo-image-optimizer' ); ?>
                        <span class="count-badge" id="count-green">0</span>
                    </button>
                    <button class="tab-btn btn-gray" data-target="tab-gray">
                        <?php esc_html_e( 'Unused', 'erdo-image-optimizer' ); ?>
                        <span class="count-badge" id="count-gray">0</span>
                    </button>
                    <button class="tab-btn btn-gray" data-target="tab-quarantine" id="erdo-quarantine-tab">
                        <?php esc_html_e( 'Quarantine', 'erdo-image-optimizer' ); ?>
                        <span class="count-badge" id="count-quarantine"><?php echo esc_html( count( ERDO_Quarantine::ids() ) ); ?></span>
                    </button>
                </div>
                <div class="erdo-tab-actions">
                    <button id="erdo-alt-filter-btn" class="button button-secondary"
                            title="<?php esc_attr_e( 'Show only images missing alt text', 'erdo-image-optimizer' ); ?>">
                        <span class="dashicons dashicons-editor-help"></span>
                        <?php esc_html_e( 'Missing Alt', 'erdo-image-optimizer' ); ?>
                    </button>
                    <div class="erdo-grid-size-btns" title="<?php esc_attr_e( 'Card size', 'erdo-image-optimizer' ); ?>">
                        <button class="erdo-grid-size-btn" data-size="small"  title="<?php esc_attr_e( 'Small',  'erdo-image-optimizer' ); ?>">S</button>
                        <button class="erdo-grid-size-btn active" data-size="medium" title="<?php esc_attr_e( 'Medium', 'erdo-image-optimizer' ); ?>">M</button>
                        <button class="erdo-grid-size-btn" data-size="large"  title="<?php esc_attr_e( 'Large',  'erdo-image-optimizer' ); ?>">L</button>
                    </div>
                    <button id="erdo-refresh-all-btn" class="button button-secondary erdo-icon-only"
                            title="<?php esc_attr_e( 'Refresh Audits', 'erdo-image-optimizer' ); ?>">
                        <span class="dashicons dashicons-update"></span>
                    </button>
                </div>
            </div>

            <div class="erdo-batch-notice" id="erdo-loading-notice">
                <span class="dashicons dashicons-update erdo-spin"></span>
                <span id="erdo-loading-text">
                    <?php esc_html_e( 'Loading library...', 'erdo-image-optimizer' ); ?>
                </span>
            </div>

            <div class="erdo-main-content">
                <div id="tab-red"    class="erdo-content active"><div class="erdo-grid"></div></div>
                <div id="tab-orange" class="erdo-content"><div class="erdo-grid"></div></div>
                <div id="tab-green"  class="erdo-content"><div class="erdo-grid"></div></div>
                <div id="tab-gray"   class="erdo-content"><div class="erdo-grid"></div></div>
                <div id="tab-quarantine" class="erdo-content">
                    <p class="erdo-quarantine-help">
                        <?php esc_html_e( 'Images moved here are hidden from your site and media library, but nothing is deleted yet. Restore puts the files back exactly as they were.', 'erdo-image-optimizer' ); ?>
                    </p>
                    <div class="erdo-grid" id="erdo-quarantine-grid"></div>
                </div>
            </div>

            <div class="erdo-load-more-wrap hidden" id="erdo-load-more-wrap">
                <button id="erdo-load-more-btn" class="button button-secondary">
                    <?php esc_html_e( 'Load More Images', 'erdo-image-optimizer' ); ?>
                </button>
                <p style="font-size:12px; color:#64748b; margin-top:6px;">
                    <?php esc_html_e( 'Auto-loading was interrupted. Click to continue.', 'erdo-image-optimizer' ); ?>
                </p>
            </div>

        </div>

        <div id="erdo-modal-overlay" class="hidden">
            <div id="erdo-modal">
                <button id="erdo-close-btn" aria-label="<?php esc_attr_e( 'Close', 'erdo-image-optimizer' ); ?>">&times;</button>
                <div id="erdo-modal-content"></div>
            </div>
        </div>

        <div id="erdo-safety-modal-overlay" class="hidden">
            <div class="erdo-safety-box">
                <span class="dashicons dashicons-warning erdo-safety-icon"></span>
                <h2 class="erdo-safety-title"><?php esc_html_e( 'Move unused images to Quarantine', 'erdo-image-optimizer' ); ?></h2>
                <div class="erdo-safety-desc">
                    <p><?php esc_html_e( 'Every image that is not referenced anywhere on your site (posts, pages, page builders, custom fields, theme settings, widgets, categories) will be moved to Quarantine. Images whose file name still appears anywhere are skipped.', 'erdo-image-optimizer' ); ?></p>
                    <p><strong><?php esc_html_e( 'Nothing is deleted.', 'erdo-image-optimizer' ); ?></strong> <?php esc_html_e( 'If something looks wrong afterwards, restore the image from the Quarantine tab. Images used only by hard-coded theme files or external sites cannot be detected — check those before deleting permanently.', 'erdo-image-optimizer' ); ?></p>
                </div>
                <button id="erdo-safety-confirm-btn">
                    <?php esc_html_e( 'Move to Quarantine', 'erdo-image-optimizer' ); ?>
                </button>
                <button id="erdo-safety-cancel-btn">
                    <?php esc_html_e( 'Cancel', 'erdo-image-optimizer' ); ?>
                </button>
            </div>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------
       AJAX: Görsel listesi yükle (50'lik batch)
    ------------------------------------------------------------------ */
    public function ajax_load_images() {
        check_ajax_referer( 'erdo_nonce', 'nonce' );
        $this->verify_capability();

        $paged  = isset( $_POST['page'] )   ? intval( $_POST['page'] ) : 1;
        $search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';

        $query = new WP_Query( array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => 'image',
            'posts_per_page' => 100,
            'paged'          => $paged,
            's'              => $search,
            'fields'         => 'ids',
        ) );

        $buckets = array( 'red' => '', 'orange' => '', 'green' => '', 'gray' => '' );
        $counts  = array( 'red' => 0,  'orange' => 0,  'green' => 0,  'gray' => 0 );

        if ( ! empty( $query->posts ) ) {
            $results = ERDO_Auditor::bulk_analyze( $query->posts );

            foreach ( $results as $id => $res ) {
                $cat = ( isset( $res['is_used'] ) && false === $res['is_used'] ) ? 'gray' : $res['status'];
                $counts[ $cat ]++;

                $format_label = strtoupper( $res['served_format'] ?? str_replace( 'image/', '', $res['mime'] ?? '' ) );
                $score_label  = isset( $res['score'] ) ? intval( $res['score'] ) . '/6' : '';

                // Alt text durumu bulk_analyze() checklist'inde zaten hesaplanmış —
                // "Missing Alt" filtresi bunu kart üzerinden okur, kart başına
                // ayrı bir AJAX isteği atmasına gerek kalmaz.
                $has_alt = true;
                foreach ( $res['checklist'] ?? array() as $item ) {
                    if ( 'Alt Text' === $item['label'] ) {
                        $has_alt = ! empty( $item['pass'] );
                        break;
                    }
                }

                $html  = '<div class="erdo-card border-' . esc_attr( $cat ) . '" data-id="' . esc_attr( $id ) . '" data-has-alt="' . ( $has_alt ? '1' : '0' ) . '">';
                $html .= '<div class="card-img-holder">';
                $html .= '<img src="' . esc_url( $res['thumbnail'] ) . '" loading="lazy">';
                if ( $format_label ) {
                    $html .= '<span class="erdo-format-badge">' . esc_html( $format_label ) . '</span>';
                }
                $html .= '</div>';
                $html .= '<div class="erdo-card-footer">';
                $html .= '<span class="erdo-card-info">' . esc_html( $res['filename'] ) . '</span>';
                if ( $score_label ) {
                    $html .= '<span class="erdo-score-badge erdo-score-' . esc_attr( $cat ) . '">' . esc_html( $score_label ) . '</span>';
                }
                $html .= '</div>';
                $html .= '</div>';

                $buckets[ $cat ] .= $html;
            }
        }

        wp_send_json_success( array(
            'html'      => $buckets,
            'counts'    => $counts,
            'max_pages' => $query->max_num_pages,
            'found'     => $query->found_posts,
        ) );
    }

    /* ------------------------------------------------------------------
       AJAX: Dashboard istatistikleri
    ------------------------------------------------------------------ */
    public function ajax_get_stats() {
        check_ajax_referer( 'erdo_nonce', 'nonce' );
        $this->verify_capability();

        $stats_cache_key = 'erdo_stats_cache';
        $cache_group     = 'erdo_image_optimizer';

        $cached = wp_cache_get( $stats_cache_key, $cache_group );
        if ( false !== $cached ) {
            wp_send_json_success( $cached );
            return;
        }

        global $wpdb;

        $lib             = ERDO_Converter::library_stats();
        $total           = $lib['total'];
        $count_optimized = $lib['optimized'];

        $seo_cache_key = 'erdo_count_seo';
        $count_seo     = wp_cache_get( $seo_cache_key, $cache_group );

        if ( false === $count_seo ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $count_seo = (int) $wpdb->get_var(
                "SELECT COUNT(p.ID)
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                 WHERE p.post_type = 'attachment'
                 AND pm.meta_key = '_wp_attachment_image_alt'
                 AND pm.meta_value != ''"
            );
            wp_cache_set( $seo_cache_key, $count_seo, $cache_group, 300 );
        }

        $opt_score = ( $total > 0 ) ? round( ( $count_optimized / $total ) * 100 ) : 0;
        $seo_score = ( $total > 0 ) ? round( ( $count_seo / $total ) * 100 ) : 0;
        $saved     = $lib['saved'];

        $result = array(
            'total'       => $total,
            'opt_score'   => $opt_score,
            'seo_score'   => $seo_score,
            'total_saved' => size_format( $saved, 2 ),
        );

        wp_cache_set( $stats_cache_key, $result, $cache_group, 300 );

        // library_version cache'e dahil edilmez — her istekte taze okunur
        $result['library_version'] = (int) get_option( 'erdo_library_version', 0 );
        wp_send_json_success( $result );
    }

    /* ------------------------------------------------------------------
       AJAX: Görsel detay modali (HTML döndürür)
    ------------------------------------------------------------------ */
    public function ajax_get_detail() {
        check_ajax_referer( 'erdo_nonce', 'nonce' );
        $this->verify_capability();

        if ( ! isset( $_POST['id'] ) ) {
            wp_send_json_error( array( 'message' => 'Missing image ID.' ) );
        }

        $id   = intval( $_POST['id'] );
        $data = ERDO_Auditor::analyze( $id );

        ob_start();

        $help = array(
            'Filename'    => __( 'Search engines prefer descriptive file names with hyphens (-) as separators. Avoid underscores or spaces.', 'erdo-image-optimizer' ),
            'Alt Text'    => __( 'Alt text helps search engines understand the image and is read by screen readers for accessibility.', 'erdo-image-optimizer' ),
            'Image Title' => __( 'A clean, readable title helps users and search engines. Avoid slug-style formatting.', 'erdo-image-optimizer' ),
            'Dimensions'  => __( 'Oversized images slow down your site. Resize to the maximum width your theme needs (usually 1920px or less).', 'erdo-image-optimizer' ),
            'Format'      => __( 'WebP and AVIF offer better compression than JPEG/PNG without quality loss, making your site faster.', 'erdo-image-optimizer' ),
            'File Size'   => __( 'Large images are the #1 cause of slow websites. Aim to keep images under 250 KB.', 'erdo-image-optimizer' ),
        );

        $usage_msg = ( isset( $data['is_used'] ) && ! $data['is_used'] )
            ? '<div class="erdo-unused-notice">&#9888; Unused Image</div>'
            : '';

        $format          = strtoupper( get_option( 'erdo_convert_format', 'webp' ) );
        $show_comparison = false;

        if ( ! empty( $data['saved_bytes'] ) && ! empty( $data['original_size'] ) ) {
            $show_comparison = true;
            $orig_size_fmt   = size_format( $data['original_size'], 1 );
            $curr_size_fmt   = size_format( $data['current_size'], 1 );
            $saved_fmt       = size_format( $data['saved_bytes'], 1 );
            $saved_percent   = ( $data['original_size'] > 0 )
                ? round( ( $data['saved_bytes'] / $data['original_size'] ) * 100 )
                : 0;
            $bar_curr        = ( $data['original_size'] > 0 )
                ? round( ( $data['current_size'] / $data['original_size'] ) * 100 ) . '%'
                : '100%';
        }

        $score      = $data['score'] ?? 0;
        $score_class = $score >= 5 ? 'score-high' : ( $score >= 3 ? 'score-mid' : 'score-low' );
        ?>
        <div class="modal-header">
            <h2>
                <?php esc_html_e( 'Audit Report', 'erdo-image-optimizer' ); ?>
                <span class="score-pill <?php echo esc_attr( $score_class ); ?>">
                    <?php echo esc_html( $score ); ?>/6
                </span>
            </h2>
        </div>

        <form id="erdo-audit-form">
            <input type="hidden" name="post_id"              value="<?php echo esc_attr( $id ); ?>">
            <input type="hidden" id="erdo-parent-title"      value="<?php echo esc_attr( $data['parent_title'] ?? '' ); ?>">
            <input type="hidden" id="erdo-current-filename"  value="<?php echo esc_attr( $data['filename'] ?? '' ); ?>">

            <div class="erdo-modal-flex">
                <div class="erdo-modal-left">
                    <div class="modal-preview">
                        <img src="<?php echo esc_url( $data['thumbnail'] ?? '' ); ?>"
                             alt="<?php echo esc_attr( $data['filename'] ?? '' ); ?>">
                    </div>

                    <?php if ( $show_comparison ) : ?>
                    <div class="erdo-comparison-box">
                        <h4><?php esc_html_e( 'Optimization Result', 'erdo-image-optimizer' ); ?></h4>
                        <div class="erdo-comparison-row">
                            <span><?php esc_html_e( 'Original', 'erdo-image-optimizer' ); ?></span>
                            <span><?php echo esc_html( $orig_size_fmt ); ?></span>
                        </div>
                        <div class="erdo-comparison-bar original"><div style="width:100%;"></div></div>
                        <div class="erdo-comparison-row">
                            <span><?php esc_html_e( 'Optimized', 'erdo-image-optimizer' ); ?></span>
                            <span><?php echo esc_html( $curr_size_fmt ); ?></span>
                        </div>
                        <div class="erdo-comparison-bar optimized">
                            <div style="width:<?php echo esc_attr( $bar_curr ); ?>;"></div>
                        </div>
                        <div class="erdo-savings-result">
                            <?php
                            printf(
                                /* translators: 1: percentage saved, 2: size amount saved */
                                esc_html__( 'You saved %1$s%% (%2$s)', 'erdo-image-optimizer' ),
                                esc_html( $saved_percent ),
                                esc_html( $saved_fmt )
                            );
                            ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="modal-sidebar-actions">
                        <?php echo wp_kses_post( $usage_msg ); ?>

                        <?php if ( ERDO_Usage::is_ready() ) : ?>
                        <?php $sources = ERDO_Usage::sources( $id ); ?>
                        <div class="erdo-usage-map">
                            <h4><?php esc_html_e( 'Where it is used', 'erdo-image-optimizer' ); ?></h4>
                            <?php if ( empty( $sources ) ) : ?>
                                <p class="erdo-usage-none"><?php esc_html_e( 'Not referenced anywhere on the site.', 'erdo-image-optimizer' ); ?></p>
                            <?php else : ?>
                                <ul>
                                <?php foreach ( array_slice( $sources, 0, 12 ) as $src ) : ?>
                                    <li>
                                        <?php if ( $src['url'] ) : ?>
                                            <a href="<?php echo esc_url( $src['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $src['label'] ); ?></a>
                                        <?php else : ?>
                                            <?php echo esc_html( $src['label'] ); ?>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                                <?php if ( count( $sources ) > 12 ) : ?>
                                    <li>
                                        <?php
                                        /* translators: %d: number of further places */
                                        echo esc_html( sprintf( __( '…and %d more', 'erdo-image-optimizer' ), count( $sources ) - 12 ) );
                                        ?>
                                    </li>
                                <?php endif; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <button type="button" id="erdo-save-btn" class="button button-primary erdo-sb-btn">
                            <span class="dashicons dashicons-saved"></span>
                            <?php esc_html_e( 'Save Changes', 'erdo-image-optimizer' ); ?>
                        </button>

                        <?php
                        $current_mime = get_post_mime_type( $id );
                        $is_optimized = ERDO_Converter::is_optimized( $id, $current_mime );
                        if ( ! $is_optimized && in_array( $current_mime, ERDO_Converter::SOURCE_MIMES, true ) ) :
                        ?>
                        <button type="button" class="webp-convert-btn button erdo-sb-btn"
                                data-id="<?php echo esc_attr( $id ); ?>">
                            <span class="dashicons dashicons-update"></span>
                            <?php
                            printf(
                                /* translators: %s: target format (WebP or AVIF) */
                                esc_html__( 'Convert to %s', 'erdo-image-optimizer' ),
                                esc_html( $format )
                            );
                            ?>
                        </button>
                        <?php endif; ?>

                        <?php
                        // Orijinal hep yerinde: "Restore" = WebP/AVIF kopyalarını sil,
                        // "Compare" = orijinal ↔ ziyaretçiye giden en küçük kopya.
                        $best_sibling = ERDO_Converter::best_main_sibling( $id );
                        if ( $best_sibling ) :
                            $original_url = wp_get_attachment_url( $id );
                            $sibling_url  = $original_url . '.' . $best_sibling['format'];
                        ?>
                        <div class="erdo-sb-sep"></div>
                        <div class="erdo-sb-pair">
                            <button type="button" class="erdo-restore-btn button erdo-sb-btn-sm"
                                    data-id="<?php echo esc_attr( $id ); ?>">
                                <span class="dashicons dashicons-backup"></span>
                                <?php esc_html_e( 'Restore', 'erdo-image-optimizer' ); ?>
                            </button>
                            <button type="button" class="erdo-before-after-btn button erdo-sb-btn-sm"
                                    data-current="<?php echo esc_url( $sibling_url ); ?>"
                                    data-original="<?php echo esc_url( $original_url ); ?>">
                                <span class="dashicons dashicons-visibility"></span>
                                <?php esc_html_e( 'Compare', 'erdo-image-optimizer' ); ?>
                            </button>
                        </div>
                        <?php endif; ?>

                        <?php
                        $filename_raw  = get_attached_file( $id );
                        $filename_base = $filename_raw ? pathinfo( $filename_raw, PATHINFO_FILENAME ) : '';
                        $sanitized     = sanitize_title( $filename_base );
                        $bunny_on      = get_option( 'erdo_bunny_enabled' ) && get_option( 'erdo_bunny_api_key' );
                        $has_tools     = ( $filename_base && $filename_base !== $sanitized ) || $bunny_on;
                        if ( $has_tools ) :
                        ?>
                        <div class="erdo-sb-sep"></div>
                        <?php endif; ?>

                        <?php if ( $filename_base && $filename_base !== $sanitized ) : ?>
                        <button type="button" class="erdo-rename-btn button erdo-sb-btn erdo-sb-btn-tool"
                                data-id="<?php echo esc_attr( $id ); ?>"
                                title="<?php echo esc_attr( $sanitized ); ?>">
                            <span class="dashicons dashicons-tag"></span>
                            <span class="erdo-sb-btn-content">
                                <span><?php esc_html_e( 'Fix Filename', 'erdo-image-optimizer' ); ?></span>
                                <small>→ <?php echo esc_html( $sanitized ); ?></small>
                            </span>
                        </button>
                        <?php endif; ?>

                        <?php if ( $bunny_on ) :
                            $cdn_url = get_post_meta( $id, '_erdo_cdn_url', true );
                        ?>
                        <button type="button" class="erdo-cdn-upload-btn button erdo-sb-btn erdo-sb-btn-tool"
                                data-id="<?php echo esc_attr( $id ); ?>">
                            <span class="dashicons dashicons-cloud-upload"></span>
                            <span class="erdo-sb-btn-content">
                                <span><?php esc_html_e( 'Upload to CDN', 'erdo-image-optimizer' ); ?></span>
                                <?php if ( $cdn_url ) : ?>
                                <small style="color:#059669;">&#10003; <?php esc_html_e( 'On CDN', 'erdo-image-optimizer' ); ?></small>
                                <?php endif; ?>
                            </span>
                        </button>
                        <?php endif; ?>

                        <div class="erdo-sb-sep erdo-sb-sep-danger"></div>
                        <button type="button" class="erdo-delete-btn button button-link-delete erdo-sb-btn"
                                data-id="<?php echo esc_attr( $id ); ?>">
                            <span class="dashicons dashicons-trash"></span>
                            <?php esc_html_e( 'Move to Quarantine', 'erdo-image-optimizer' ); ?>
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
                                <input type="number" id="erdo-w-<?php echo esc_attr( $id ); ?>"
                                       value="<?php echo esc_attr( $item['width'] ); ?>"
                                       class="erdo-input" style="width:70px;" placeholder="W">
                                <span>x</span>
                                <input type="number" id="erdo-h-<?php echo esc_attr( $id ); ?>"
                                       value="<?php echo esc_attr( $item['height'] ); ?>"
                                       class="erdo-input" style="width:70px;" placeholder="H">
                                <button type="button" class="button button-small erdo-resize-btn"
                                        data-id="<?php echo esc_attr( $id ); ?>">
                                    <?php esc_html_e( 'Resize', 'erdo-image-optimizer' ); ?>
                                </button>
                            </div>

                            <?php elseif ( in_array( $item['label'], array( 'Filename', 'Alt Text', 'Image Title' ), true ) ) : ?>
                            <?php
                            $field = ( 'Filename' === $item['label'] ) ? 'filename'
                                : ( ( 'Alt Text' === $item['label'] ) ? 'alt_text' : 'title' );
                            ?>
                            <?php if ( 'filename' === $field ) : ?>
                            <div id="erdo-filename-preview" style="font-size:11px; color:#64748b; margin-bottom:3px; display:none;">
                                <?php esc_html_e( 'Preview:', 'erdo-image-optimizer' ); ?> <code id="erdo-filename-preview-val"></code>
                            </div>
                            <?php endif; ?>
                            <div style="display:flex; gap:5px; align-items:center;">
                                <input type="text"
                                       id="erdo-input-<?php echo esc_attr( $field ); ?>"
                                       name="<?php echo esc_attr( $field ); ?>"
                                       value="<?php echo esc_attr( $item['value'] ?? '' ); ?>"
                                       class="erdo-input">

                                <?php if ( 'Alt Text' === $item['label'] ) : ?>
                                    <?php if ( ! empty( $data['parent_title'] ) ) : ?>
                                    <button type="button" class="button button-small erdo-magic-btn"
                                            data-target="alt_text" data-source="parent"
                                            title="<?php esc_attr_e( 'Use Post Title', 'erdo-image-optimizer' ); ?>">&#128196;</button>
                                    <?php endif; ?>
                                    <button type="button" class="button button-small erdo-magic-btn"
                                            data-target="alt_text" data-source="filename"
                                            title="<?php esc_attr_e( 'Generate from Filename', 'erdo-image-optimizer' ); ?>">&#10024;</button>
                                <?php endif; ?>

                                <?php if ( 'Image Title' === $item['label'] ) : ?>
                                    <button type="button" class="button button-small erdo-magic-btn"
                                            data-target="title" data-source="filename"
                                            title="<?php esc_attr_e( 'Generate from Filename', 'erdo-image-optimizer' ); ?>">&#10024;</button>
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
        echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Admin-only AJAX; all values escaped individually within the template.
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
            wp_send_json_error( array( 'message' => 'Invalid ID.' ) );
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
            wp_send_json_error( array( 'message' => 'Missing ID.' ) );
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
       AJAX: Toplu dönüşüm için ID listesi
    ------------------------------------------------------------------ */
    public function ajax_get_bulk_list() {
        check_ajax_referer( 'erdo_nonce', 'nonce' );
        $this->verify_capability();

        wp_send_json_success( ERDO_Converter::pending_ids() );
    }

    /* ------------------------------------------------------------------
       AJAX: Toplu dönüşüm - tek adım
    ------------------------------------------------------------------ */
    public function ajax_bulk_step() {
        check_ajax_referer( 'erdo_nonce', 'nonce' );
        $this->verify_capability();

        if ( ! isset( $_POST['id'] ) ) {
            wp_send_json_error();
        }

        $id  = intval( wp_unslash( $_POST['id'] ) );
        $res = ERDO_Converter::convert( $id );

        if ( is_wp_error( $res ) ) {
            wp_send_json_error( array( 'message' => $res->get_error_message() ) );
        } else {
            wp_send_json_success( $res );
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

    /* ------------------------------------------------------------------
       AJAX: Orijinal görseli yedeğinden geri yükle
    ------------------------------------------------------------------ */
    public function ajax_restore_original() {
        check_ajax_referer( 'erdo_nonce', 'nonce' );
        $this->verify_capability();

        if ( ! isset( $_POST['id'] ) ) {
            wp_send_json_error( array( 'message' => 'Missing ID.' ) );
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
        update_option( 'erdo_allow_svg',           isset( $_POST['erdo_allow_svg'] ) ? 1 : 0 );
        update_option( 'erdo_append_original_filename', isset( $_POST['erdo_append_original_filename'] ) ? 1 : 0 );

        if ( isset( $_POST['erdo_webp_serve_method'] ) ) {
            $meth = sanitize_text_field( wp_unslash( $_POST['erdo_webp_serve_method'] ) );
            update_option( 'erdo_webp_serve_method', in_array( $meth, array( 'picture', 'htaccess' ), true ) ? $meth : 'picture' );
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

        // BunnyCDN
        update_option( 'erdo_bunny_enabled',      isset( $_POST['erdo_bunny_enabled'] ) ? 1 : 0 );
        update_option( 'erdo_bunny_api_key',      sanitize_text_field( wp_unslash( $_POST['erdo_bunny_api_key'] ?? '' ) ) );
        update_option( 'erdo_bunny_storage_zone', sanitize_text_field( wp_unslash( $_POST['erdo_bunny_storage_zone'] ?? '' ) ) );
        update_option( 'erdo_bunny_hostname',     sanitize_text_field( wp_unslash( $_POST['erdo_bunny_hostname'] ?? '' ) ) );

        // .htaccess kurallarını ayarla eşitle: yalnızca Apache + .htaccess yöntemi seçiliyken var olmalı.
        if ( get_option( 'erdo_serve_webp' ) && 'htaccess' === get_option( 'erdo_webp_serve_method' ) && ERDO_WebP_Server::is_apache() ) {
            ERDO_WebP_Server::write_htaccess_rules();
        } elseif ( ERDO_WebP_Server::htaccess_has_rules() ) {
            ERDO_WebP_Server::remove_htaccess_rules();
        }
        ERDO_Auditor::clear_all_cache();

        wp_send_json_success();
    }

    /* ------------------------------------------------------------------
       AJAX: Ayarlar modali (HTML döndürür)
    ------------------------------------------------------------------ */
    public function ajax_get_settings() {
        check_ajax_referer( 'erdo_nonce', 'nonce' );
        $this->verify_capability();

        $aw   = get_option( 'erdo_auto_webp' );
        $as   = get_option( 'erdo_auto_sanitize' );
        $re   = get_option( 'erdo_remove_exif' );
        $fmt  = get_option( 'erdo_convert_format', 'webp' );
        $q    = get_option( 'erdo_quality', 80 );
        $ll   = get_option( 'erdo_lossless', 0 );
        $aof  = get_option( 'erdo_append_original_filename', 0 );
        $avif = ERDO_Encoder::supports( 'avif' );
        $webp = ERDO_Encoder::supports( 'webp' );
        ?>
        <div class="modal-header">
            <h2><?php esc_html_e( 'Settings', 'erdo-image-optimizer' ); ?></h2>
        </div>

        <form id="erdo-settings-form" style="padding:20px 0;">

            <div class="erdo-settings-section">
                <label><strong><?php esc_html_e( 'Conversion Format', 'erdo-image-optimizer' ); ?></strong></label>
                <select name="erdo_convert_format" style="width:100%; height:36px; border:1.5px solid #e2e8f0; border-radius:6px; padding:0 10px; font-size:13px; background:#fff;">
                    <option value="webp" <?php selected( $fmt, 'webp' ); ?>>
                        <?php esc_html_e( 'WebP — Recommended (all modern browsers)', 'erdo-image-optimizer' ); ?>
                    </option>
                    <option value="avif" <?php selected( $fmt, 'avif' ); ?> <?php echo $avif ? '' : 'disabled'; ?>>
                        <?php esc_html_e( 'AVIF — Smallest files, slower to encode', 'erdo-image-optimizer' ); ?>
                    </option>
                    <option value="both" <?php selected( $fmt, 'both' ); ?> <?php echo $avif ? '' : 'disabled'; ?>>
                        <?php esc_html_e( 'AVIF + WebP — Browsers get the best format they support', 'erdo-image-optimizer' ); ?>
                    </option>
                </select>
                <p>
                    <?php esc_html_e( 'Your original files are never changed — WebP/AVIF copies are created next to them and served to browsers that support them.', 'erdo-image-optimizer' ); ?>
                    <?php if ( ! $avif ) : ?>
                        <?php esc_html_e( 'AVIF is not available on this server.', 'erdo-image-optimizer' ); ?>
                    <?php endif; ?>
                    <?php if ( ! $webp ) : ?>
                        <strong><?php esc_html_e( 'This server cannot create WebP files (GD or Imagick with WebP support is required).', 'erdo-image-optimizer' ); ?></strong>
                    <?php endif; ?>
                </p>
            </div>

            <div class="erdo-settings-section">
                <label style="justify-content:space-between; margin-bottom:8px;">
                    <strong><?php esc_html_e( 'Compression Quality', 'erdo-image-optimizer' ); ?></strong>
                    <span id="erdo-quality-val" style="font-weight:800; color:#2563eb;"><?php echo esc_html( $q ); ?>%</span>
                </label>
                <input type="range" name="erdo_quality"
                       value="<?php echo esc_attr( $q ); ?>" min="10" max="100"
                       oninput="document.getElementById('erdo-quality-val').innerText=this.value+'%'"
                       style="width:100%; accent-color:#2563eb;">
                <p><?php esc_html_e( '80% is the recommended sweet spot. Lossless mode overrides this setting.', 'erdo-image-optimizer' ); ?></p>
            </div>

            <div class="erdo-settings-section">
                <label>
                    <input type="checkbox" name="erdo_auto_webp" value="1" <?php checked( $aw, 1 ); ?>>
                    <span>
                        <strong><?php esc_html_e( 'Auto-Convert on Upload', 'erdo-image-optimizer' ); ?></strong>
                        <p><?php esc_html_e( 'Automatically convert JPEG/PNG/GIF to WebP/AVIF when a new image is uploaded.', 'erdo-image-optimizer' ); ?></p>
                    </span>
                </label>
                <label>
                    <input type="checkbox" name="erdo_lossless" value="1" <?php checked( $ll, 1 ); ?>>
                    <span>
                        <strong><?php esc_html_e( 'Lossless Mode', 'erdo-image-optimizer' ); ?></strong>
                        <p><?php esc_html_e( 'WebP only: pixel-identical copies. Larger than lossy; copies that end up bigger than the original are not kept.', 'erdo-image-optimizer' ); ?></p>
                    </span>
                </label>
                <label>
                    <input type="checkbox" name="erdo_auto_sanitize" value="1" <?php checked( $as, 1 ); ?>>
                    <span>
                        <strong><?php esc_html_e( 'Sanitize Filenames on Upload', 'erdo-image-optimizer' ); ?></strong>
                        <p><?php esc_html_e( 'Replace spaces and underscores with hyphens for better SEO.', 'erdo-image-optimizer' ); ?></p>
                    </span>
                </label>
                <label>
                    <input type="checkbox" name="erdo_remove_exif" value="1" <?php checked( $re, 1 ); ?>>
                    <span>
                        <strong><?php esc_html_e( 'Strip EXIF Metadata', 'erdo-image-optimizer' ); ?></strong>
                        <p><?php esc_html_e( 'Remove camera data and GPS location from new uploads. This rewrites the uploaded original once.', 'erdo-image-optimizer' ); ?></p>
                    </span>
                </label>
                <label>
                    <input type="checkbox" name="erdo_append_original_filename" value="1" <?php checked( $aof, 1 ); ?>>
                    <span>
                        <strong><?php esc_html_e( 'Append Original Filename When Renaming', 'erdo-image-optimizer' ); ?></strong>
                        <p><?php esc_html_e( 'When you set a custom filename in the image detail panel, keep the original name as a suffix (e.g. "istanbul" becomes "istanbul-img-1234") to avoid collisions and preserve a reference to the source file.', 'erdo-image-optimizer' ); ?></p>
                    </span>
                </label>
            </div>

        <?php
        // --- Yeni özellikler bölümü --- (form burada KAPANMAZ; aşağıdaki
        // tüm bölümler de erdo-settings-form içinde kalmalı, aksi hâlde
        // JS serialize() bu alanları dışarıda bırakır ve kayıt sırasında
        // checkbox'lar sıfırlanır.)
        $max_w    = get_option( 'erdo_max_width', 0 );
        $srv_webp = get_option( 'erdo_serve_webp', 0 );
        $srv_meth = get_option( 'erdo_webp_serve_method', 'picture' );
        $allow_svg= get_option( 'erdo_allow_svg', 0 );
        $smart_q  = get_option( 'erdo_smart_quality', 0 );
        $is_apache = ERDO_WebP_Server::is_apache();
        $has_htaccess = ERDO_WebP_Server::htaccess_has_rules();
        ?>

        <div class="erdo-settings-section" style="margin-top:4px;">
            <label style="margin-bottom:14px; font-weight:700; display:block; color:#0f172a;">
                <?php esc_html_e( 'Performance & Serving', 'erdo-image-optimizer' ); ?>
            </label>

            <label>
                <input type="checkbox" name="erdo_serve_webp" value="1" <?php checked( $srv_webp, 1 ); ?>>
                <span>
                    <strong><?php esc_html_e( 'Serve WebP/AVIF to Browsers', 'erdo-image-optimizer' ); ?></strong>
                    <p><?php esc_html_e( 'Deliver the WebP/AVIF copies to browsers that support them; everyone else gets the original.', 'erdo-image-optimizer' ); ?></p>
                </span>
            </label>

            <div style="margin-left:26px; margin-top:6px;">
                <label style="display:inline-flex; align-items:center; gap:6px; margin-right:16px;">
                    <input type="radio" name="erdo_webp_serve_method" value="picture" <?php checked( $srv_meth, 'picture' ); ?>>
                    <?php esc_html_e( 'PHP &lt;picture&gt; (all servers)', 'erdo-image-optimizer' ); ?>
                </label>
                <label style="display:inline-flex; align-items:center; gap:6px;">
                    <input type="radio" name="erdo_webp_serve_method" value="htaccess" <?php checked( $srv_meth, 'htaccess' ); ?> <?php echo $is_apache ? '' : 'disabled'; ?>>
                    <?php esc_html_e( '.htaccess rewrite (Apache only)', 'erdo-image-optimizer' ); ?>
                </label>
                <?php if ( $is_apache ) : ?>
                    <div style="margin-top:8px;">
                        <button type="button" id="erdo-htaccess-write-btn" class="button button-small"
                            style="<?php echo $has_htaccess ? 'display:none;' : ''; ?>">
                            <?php esc_html_e( 'Write .htaccess Rules', 'erdo-image-optimizer' ); ?>
                        </button>
                        <button type="button" id="erdo-htaccess-remove-btn" class="button button-small button-link-delete"
                            style="<?php echo $has_htaccess ? '' : 'display:none;'; ?>">
                            <?php esc_html_e( 'Remove .htaccess Rules', 'erdo-image-optimizer' ); ?>
                        </button>
                        <span id="erdo-htaccess-status" style="margin-left:8px; font-size:12px; color:#059669;">
                            <?php echo $has_htaccess ? esc_html__( 'Active', 'erdo-image-optimizer' ) : ''; ?>
                        </span>
                    </div>
                <?php elseif ( ERDO_WebP_Server::is_nginx() ) : ?>
                    <details style="margin-top:8px;">
                        <summary style="cursor:pointer; font-size:12px; color:#2563eb;">
                            <?php esc_html_e( 'View Nginx configuration snippet', 'erdo-image-optimizer' ); ?>
                        </summary>
                        <pre style="background:#0f172a; color:#e2e8f0; padding:12px; border-radius:4px; font-size:11px; margin-top:6px; overflow:auto;"><?php echo esc_html( ERDO_WebP_Server::get_nginx_config() ); ?></pre>
                    </details>
                <?php endif; ?>
            </div>

            <label style="margin-top:12px;">
                <input type="checkbox" name="erdo_smart_quality" value="1" <?php checked( $smart_q, 1 ); ?>>
                <span>
                    <strong><?php esc_html_e( 'Smart Quality (Auto)', 'erdo-image-optimizer' ); ?></strong>
                    <p><?php esc_html_e( 'Automatically find the lowest quality that still looks good. Overrides the manual quality slider.', 'erdo-image-optimizer' ); ?></p>
                </span>
            </label>

            <label style="margin-top:12px; align-items:flex-start;">
                <span style="padding-top:4px;">
                    <strong><?php esc_html_e( 'Max Upload Size', 'erdo-image-optimizer' ); ?></strong>
                    <br>
                    <input type="number" name="erdo_max_width" value="<?php echo esc_attr( $max_w ); ?>"
                        min="0" max="8000" style="width:90px; margin-top:4px; height:32px;">
                    <span style="font-size:12px; color:#64748b; margin-left:4px;">px &nbsp;(0 = disabled)</span>
                    <p><?php esc_html_e( 'New uploads wider or taller than this get a scaled-down copy that WordPress uses everywhere (the full-size original is kept). WordPress default: 2560.', 'erdo-image-optimizer' ); ?></p>
                </span>
            </label>
        </div>

        <div class="erdo-settings-section">
            <label style="margin-bottom:4px; font-weight:700; display:block; color:#0f172a;">
                <?php esc_html_e( 'Quarantine', 'erdo-image-optimizer' ); ?>
            </label>
            <label style="align-items:flex-start;">
                <span>
                    <strong><?php esc_html_e( 'Delete quarantined images permanently after', 'erdo-image-optimizer' ); ?></strong>
                    <br>
                    <input type="number" name="erdo_quarantine_days" value="<?php echo esc_attr( (int) get_option( 'erdo_quarantine_days', 0 ) ); ?>"
                        min="0" max="365" style="width:90px; margin-top:4px; height:32px;">
                    <span style="font-size:12px; color:#64748b; margin-left:4px;"><?php esc_html_e( 'days (0 = never, delete manually)', 'erdo-image-optimizer' ); ?></span>
                </span>
            </label>
        </div>

        <div class="erdo-settings-section">
            <label style="margin-bottom:4px; font-weight:700; display:block; color:#0f172a;">
                <?php esc_html_e( 'SVG Support', 'erdo-image-optimizer' ); ?>
            </label>
            <label>
                <input type="checkbox" name="erdo_allow_svg" value="1" <?php checked( $allow_svg, 1 ); ?>>
                <span>
                    <strong><?php esc_html_e( 'Allow SVG Uploads (admin only)', 'erdo-image-optimizer' ); ?></strong>
                    <p><?php esc_html_e( 'Enables SVG file uploads for administrators. All SVGs are sanitized on upload to prevent XSS attacks.', 'erdo-image-optimizer' ); ?></p>
                </span>
            </label>
        </div>

        <div class="erdo-settings-section">
            <label style="margin-bottom:8px; font-weight:700; display:block; color:#0f172a;">
                <?php esc_html_e( 'Background Queue', 'erdo-image-optimizer' ); ?>
            </label>
            <p style="font-size:12px; color:#64748b; margin:0 0 10px;">
                <?php esc_html_e( 'Start a bulk conversion in the background via WP-Cron. The browser tab can be closed while it runs.', 'erdo-image-optimizer' ); ?>
            </p>
            <div id="erdo-queue-status-box" style="display:none; padding:10px; background:#f0fdf4; border:1px solid #a7f3d0; border-radius:4px; margin-bottom:10px; font-size:13px;"></div>
            <button type="button" id="erdo-start-queue-btn" class="button button-secondary" style="margin-right:6px;">
                <?php esc_html_e( 'Start Background Convert', 'erdo-image-optimizer' ); ?>
            </button>
            <button type="button" id="erdo-cancel-queue-btn" class="button button-link-delete" style="display:none;">
                <?php esc_html_e( 'Cancel', 'erdo-image-optimizer' ); ?>
            </button>
        </div>

        <?php
        $bunny_key  = get_option( 'erdo_bunny_api_key', '' );
        $bunny_zone = get_option( 'erdo_bunny_storage_zone', '' );
        $bunny_host = get_option( 'erdo_bunny_hostname', '' );
        $bunny_on   = get_option( 'erdo_bunny_enabled', 0 );
        ?>
        <div class="erdo-settings-section">
            <label style="margin-bottom:8px; font-weight:700; display:block; color:#0f172a;">
                <?php esc_html_e( 'BunnyCDN Integration', 'erdo-image-optimizer' ); ?>
            </label>
            <label>
                <input type="checkbox" name="erdo_bunny_enabled" value="1" <?php checked( $bunny_on, 1 ); ?>>
                <span>
                    <strong><?php esc_html_e( 'Upload to BunnyCDN after conversion', 'erdo-image-optimizer' ); ?></strong>
                    <p><?php esc_html_e( 'Automatically upload converted images to BunnyCDN storage after WebP/AVIF conversion.', 'erdo-image-optimizer' ); ?></p>
                </span>
            </label>
            <div style="margin-top:10px; display:grid; gap:8px;">
                <label style="display:block; font-size:12px; font-weight:600; color:#374151;">
                    <?php esc_html_e( 'API Key', 'erdo-image-optimizer' ); ?>
                    <input type="password" name="erdo_bunny_api_key" value="<?php echo esc_attr( $bunny_key ); ?>"
                           class="regular-text" style="margin-top:2px; width:100%;">
                </label>
                <label style="display:block; font-size:12px; font-weight:600; color:#374151;">
                    <?php esc_html_e( 'Storage Zone Name', 'erdo-image-optimizer' ); ?>
                    <input type="text" name="erdo_bunny_storage_zone" value="<?php echo esc_attr( $bunny_zone ); ?>"
                           class="regular-text" style="margin-top:2px; width:100%;" placeholder="my-storage-zone">
                </label>
                <label style="display:block; font-size:12px; font-weight:600; color:#374151;">
                    <?php esc_html_e( 'CDN Hostname (optional)', 'erdo-image-optimizer' ); ?>
                    <input type="text" name="erdo_bunny_hostname" value="<?php echo esc_attr( $bunny_host ); ?>"
                           class="regular-text" style="margin-top:2px; width:100%;" placeholder="cdn.mysite.com">
                    <span style="font-size:11px; color:#64748b;"><?php esc_html_e( 'Leave empty to use [zone].b-cdn.net', 'erdo-image-optimizer' ); ?></span>
                </label>
            </div>
        </div>

        </form>

        <button type="button" id="erdo-save-settings-btn"
                class="button button-primary button-large"
                style="width:100%; height:44px; font-size:14px; font-weight:800; border-radius:6px; letter-spacing:.3px;">
            <?php esc_html_e( 'Save Settings', 'erdo-image-optimizer' ); ?>
        </button>

        <?php
        wp_die();
    }

    /* ------------------------------------------------------------------
       AJAX: Arka plan kuyruğunu başlat
    ------------------------------------------------------------------ */
    public function ajax_start_queue() {
        check_ajax_referer( 'erdo_nonce', 'nonce' );
        $this->verify_capability();

        $ids = ERDO_Converter::pending_ids();

        if ( empty( $ids ) ) {
            wp_send_json_error( array( 'message' => __( 'All images already have WebP/AVIF copies.', 'erdo-image-optimizer' ) ) );
        }

        ERDO_Queue::start( $ids, 'convert' );
        wp_send_json_success( ERDO_Queue::get_status() );
    }

    /* ------------------------------------------------------------------
       AJAX: Kuyruğu iptal et
    ------------------------------------------------------------------ */
    public function ajax_cancel_queue() {
        check_ajax_referer( 'erdo_nonce', 'nonce' );
        $this->verify_capability();
        ERDO_Queue::cancel();
        wp_send_json_success( array( 'status' => 'cancelled' ) );
    }

    /* ------------------------------------------------------------------
       AJAX: Kuyruk durumunu sorgula
    ------------------------------------------------------------------ */
    public function ajax_queue_status() {
        check_ajax_referer( 'erdo_nonce', 'nonce' );
        $this->verify_capability();
        wp_send_json_success( ERDO_Queue::get_status() );
    }

    /* ------------------------------------------------------------------
       AJAX: .htaccess kurallarını yaz
    ------------------------------------------------------------------ */
    public function ajax_write_htaccess() {
        check_ajax_referer( 'erdo_nonce', 'nonce' );
        $this->verify_capability();
        ERDO_WebP_Server::write_htaccess_rules();
        wp_send_json_success( array( 'active' => ERDO_WebP_Server::htaccess_has_rules() ) );
    }

    /* ------------------------------------------------------------------
       AJAX: .htaccess kurallarını kaldır
    ------------------------------------------------------------------ */
    public function ajax_remove_htaccess() {
        check_ajax_referer( 'erdo_nonce', 'nonce' );
        $this->verify_capability();
        ERDO_WebP_Server::remove_htaccess_rules();
        wp_send_json_success( array( 'active' => false ) );
    }

    /* ------------------------------------------------------------------
       AJAX: Tek görsel — dosya adını yeniden adlandır
    ------------------------------------------------------------------ */
    public function ajax_rename_file() {
        check_ajax_referer( 'erdo_nonce', 'nonce' );
        $this->verify_capability();

        $id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
        if ( ! $id ) {
            wp_send_json_error( array( 'message' => 'Invalid ID.' ) );
        }

        $result = ERDO_Auditor::rename_file( $id );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        $new_data = ERDO_Auditor::analyze( $id );
        wp_send_json_success( array_merge( $result, array( 'audit' => $new_data ) ) );
    }

    /* ------------------------------------------------------------------
       AJAX: Toplu dosya adı yeniden adlandırma — ID listesi işle
    ------------------------------------------------------------------ */
    public function ajax_bulk_rename() {
        check_ajax_referer( 'erdo_nonce', 'nonce' );
        $this->verify_capability();

        // ID yoksa tüm kütüphaneyi tara
        $ids = isset( $_POST['ids'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['ids'] ) ) : array();

        if ( empty( $ids ) ) {
            $ids = get_posts( array(
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'post_mime_type' => 'image',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
            ) );
        }

        $renamed = 0;
        $skipped = 0;

        foreach ( $ids as $id ) {
            $result = ERDO_Auditor::rename_file( $id );
            if ( is_wp_error( $result ) ) {
                $skipped++;
            } else {
                $renamed++;
            }
        }

        wp_send_json_success( array( 'renamed' => $renamed, 'skipped' => $skipped ) );
    }

    /* ------------------------------------------------------------------
       AJAX: SEO audit sonuçlarını CSV olarak indir
    ------------------------------------------------------------------ */
    public function ajax_export_csv() {
        check_ajax_referer( 'erdo_nonce', 'nonce' );
        $this->verify_capability();

        $status_filter = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';
        $rows          = ERDO_Auditor::get_csv_rows( $status_filter );

        $csv  = "ID,Filename,Score,Status,Format,Alt Text,Title,Edit URL\n";
        foreach ( $rows as $r ) {
            $csv .= implode( ',', array_map( function( $v ) {
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

        $updated = 0;
        foreach ( $ids as $id ) {
            $alt = ERDO_Auditor::set_alt_from_filename( $id );
            if ( $alt ) {
                $updated++;
            }
        }

        wp_send_json_success( array( 'updated' => $updated ) );
    }

    /* ------------------------------------------------------------------
       AJAX: BunnyCDN'e görsel yükle
    ------------------------------------------------------------------ */
    public function ajax_cdn_upload() {
        check_ajax_referer( 'erdo_nonce', 'nonce' );
        $this->verify_capability();

        $id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
        if ( ! $id ) {
            wp_send_json_error( array( 'message' => 'Invalid ID.' ) );
        }

        $result = $this->upload_to_bunny( $id );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array( 'cdn_url' => $result ) );
    }

    /* ------------------------------------------------------------------
       BunnyCDN: Tek görsel yükle — CDN URL'sini döndürür
    ------------------------------------------------------------------ */
    private function upload_to_bunny( $post_id ) {
        $api_key      = get_option( 'erdo_bunny_api_key', '' );
        $storage_zone = get_option( 'erdo_bunny_storage_zone', '' );
        $cdn_hostname = get_option( 'erdo_bunny_hostname', '' );

        if ( ! $api_key || ! $storage_zone ) {
            return new WP_Error( 'no_config', 'BunnyCDN API key or storage zone not configured.' );
        }

        $file_path = get_attached_file( $post_id );
        if ( ! $file_path || ! file_exists( $file_path ) ) {
            return new WP_Error( 'not_found', 'File not found.' );
        }

        $upload_dir  = wp_upload_dir();
        $rel_path    = ltrim( str_replace( $upload_dir['basedir'], '', $file_path ), '/' );
        $cdn_path    = $storage_zone . '/' . $rel_path;
        $endpoint    = 'https://storage.bunnycdn.com/' . $cdn_path;

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $file_data = file_get_contents( $file_path );
        if ( false === $file_data ) {
            return new WP_Error( 'read_failed', 'Could not read file.' );
        }

        $response = wp_remote_request( $endpoint, array(
            'method'  => 'PUT',
            'headers' => array(
                'AccessKey'    => $api_key,
                'Content-Type' => 'application/octet-stream',
            ),
            'body'    => $file_data,
            'timeout' => 60,
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'upload_failed', 'BunnyCDN upload failed. HTTP ' . $code );
        }

        $hostname = $cdn_hostname ?: ( $storage_zone . '.b-cdn.net' );
        $cdn_url  = 'https://' . trailingslashit( $hostname ) . $rel_path;

        update_post_meta( $post_id, '_erdo_cdn_url', $cdn_url );

        return $cdn_url;
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

        $cache_key = 'erdo_stats_cache';
        $stats     = wp_cache_get( $cache_key, 'erdo_image_optimizer' );

        if ( false === $stats ) {
            $lib   = ERDO_Converter::library_stats();
            $stats = array(
                'total'       => $lib['total'],
                'opt_score'   => $lib['rate'],
                'total_saved' => size_format( $lib['saved'], 1 ),
            );
        }

        $url = admin_url( 'admin.php?page=erdo-image-optimizer' );
        ?>
        <div style="display:flex; gap:12px; flex-wrap:wrap; margin-bottom:10px;">
            <div style="flex:1; min-width:80px; text-align:center; background:#eff6ff; border-radius:6px; padding:10px;">
                <div style="font-size:22px; font-weight:800; color:#1e40af;"><?php echo esc_html( $stats['total'] ); ?></div>
                <div style="font-size:11px; color:#64748b;"><?php esc_html_e( 'Total Images', 'erdo-image-optimizer' ); ?></div>
            </div>
            <div style="flex:1; min-width:80px; text-align:center; background:#f0fdf4; border-radius:6px; padding:10px;">
                <div style="font-size:22px; font-weight:800; color:#15803d;"><?php echo esc_html( $stats['opt_score'] ); ?>%</div>
                <div style="font-size:11px; color:#64748b;"><?php esc_html_e( 'Optimized', 'erdo-image-optimizer' ); ?></div>
            </div>
            <div style="flex:1; min-width:80px; text-align:center; background:#fff7ed; border-radius:6px; padding:10px;">
                <div style="font-size:22px; font-weight:800; color:#c2410c;"><?php echo esc_html( $stats['total_saved'] ); ?></div>
                <div style="font-size:11px; color:#64748b;"><?php esc_html_e( 'Saved', 'erdo-image-optimizer' ); ?></div>
            </div>
        </div>
        <a href="<?php echo esc_url( $url ); ?>" class="button button-primary" style="width:100%; text-align:center;">
            <?php esc_html_e( 'Open Image Optimizer', 'erdo-image-optimizer' ); ?>
        </a>
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
        $html = '';
        foreach ( ERDO_Quarantine::ids() as $id ) {
            $data = get_post_meta( $id, ERDO_Quarantine::META, true );
            $file = basename( (string) get_post_meta( $id, '_wp_attached_file', true ) );
            $html .= '<div class="erdo-card erdo-q-card" data-qid="' . esc_attr( $id ) . '">'
                . '<div class="card-img-holder erdo-q-thumb"><span class="dashicons dashicons-archive"></span></div>'
                . '<div class="erdo-card-footer"><span class="erdo-card-info">' . esc_html( $file ) . '</span></div>'
                . '<div class="erdo-q-meta">' . esc_html( sprintf(
                    /* translators: %s: human-readable time difference, e.g. "3 days" */
                    __( 'Quarantined %s ago', 'erdo-image-optimizer' ),
                    human_time_diff( (int) ( $data['time'] ?? time() ) )
                ) ) . ( ! empty( $data['in_use'] ) ? ' — <strong>' . esc_html__( 'was in use', 'erdo-image-optimizer' ) . '</strong>' : '' ) . '</div>'
                . '<div class="erdo-q-actions">'
                . '<button type="button" class="button button-small erdo-q-restore" data-id="' . esc_attr( $id ) . '">' . esc_html__( 'Restore', 'erdo-image-optimizer' ) . '</button> '
                . '<button type="button" class="button button-small button-link-delete erdo-q-purge" data-id="' . esc_attr( $id ) . '">' . esc_html__( 'Delete permanently', 'erdo-image-optimizer' ) . '</button>'
                . '</div></div>';
        }
        wp_send_json_success( array( 'html' => $html, 'count' => count( ERDO_Quarantine::ids() ) ) );
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
                            if ( ! res.success ) { out.textContent = ( res.data && res.data.message ) || 'Error'; btn.disabled = false; return; }
                            fixed += res.data.restore + res.data.repoint;
                            out.textContent = <?php echo wp_json_encode( __( 'Repaired:', 'erdo-image-optimizer' ) ); ?> + ' ' + fixed + ' — ' + <?php echo wp_json_encode( __( 'remaining:', 'erdo-image-optimizer' ) ); ?> + ' ' + res.data.remaining;
                            if ( res.data.remaining > 0 ) { step(); } else { out.textContent += ' ✓'; }
                        } )
                        .catch( function () { out.textContent = 'Error'; btn.disabled = false; } );
                };
                step();
            } );
        } )();
        </script>
        <?php
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
