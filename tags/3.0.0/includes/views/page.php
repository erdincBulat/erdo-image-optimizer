<?php
/**
 * Ana sayfa: başlık, sekmeler ve beş panel. ERDO_Admin_Page::render_page()
 * içinden yüklenir ($this = ERDO_Admin_Page). Kütüphane verisi JS ile gelir.
 *
 * @var ERDO_Admin_Page $this
 * @var string          $tab
 * @var array           $env
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$erdo_usage    = ERDO_Usage::state();
$erdo_ai_count = count( ERDO_AI::pending_ids() );
$erdo_q_count  = count( ERDO_Quarantine::ids() );
$erdo_tabs     = array(
    'overview' => __( 'Overview', 'erdo-image-optimizer' ),
    'library'  => __( 'Library', 'erdo-image-optimizer' ),
    'cleanup'  => __( 'Cleanup', 'erdo-image-optimizer' ),
    'ai'       => __( 'AI Suggestions', 'erdo-image-optimizer' ),
    'settings' => __( 'Settings', 'erdo-image-optimizer' ),
);
?>
<div class="wrap erdo-app" id="erdo-app" data-tab="<?php echo esc_attr( $tab ); ?>">

    <div class="erdo-head">
        <h1 class="erdo-title">
            <?php include __DIR__ . '/logo.php'; ?>
            <?php esc_html_e( 'Erdo Image Optimizer', 'erdo-image-optimizer' ); ?>
            <span class="erdo-ver">v<?php echo esc_html( ERDO_VERSION ); ?></span>
        </h1>
        <span class="erdo-sp"></span>
        <button type="button" id="erdo-upload-btn" class="button button-primary">
            <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
            <?php esc_html_e( 'Upload images', 'erdo-image-optimizer' ); ?>
        </button>
    </div>
    <hr class="wp-header-end">

    <div class="notice notice-info inline erdo-notice<?php echo 'ready' === $erdo_usage['status'] ? ' hidden' : ''; ?>" id="erdo-index-notice" data-status="<?php echo esc_attr( $erdo_usage['status'] ); ?>">
        <span class="dashicons dashicons-update erdo-spin" aria-hidden="true"></span>
        <p id="erdo-index-text"><?php esc_html_e( 'Building the usage index (where each image is used). Unused-image cleanup becomes available when it finishes. You can keep working.', 'erdo-image-optimizer' ); ?></p>
    </div>

    <div class="notice notice-info inline erdo-notice hidden" id="erdo-queue-notice">
        <span class="dashicons dashicons-update erdo-spin" aria-hidden="true"></span>
        <p id="erdo-queue-text"></p>
        <button type="button" class="button button-small" id="erdo-queue-details"><?php esc_html_e( 'Details', 'erdo-image-optimizer' ); ?></button>
    </div>

    <nav class="erdo-nav" aria-label="<?php esc_attr_e( 'Erdo Image Optimizer sections', 'erdo-image-optimizer' ); ?>">
        <?php foreach ( $erdo_tabs as $erdo_key => $erdo_label ) : ?>
            <a href="<?php echo esc_url( add_query_arg( 'tab', $erdo_key, admin_url( 'admin.php?page=erdo-image-optimizer' ) ) ); ?>"
               class="erdo-nav-link<?php echo $tab === $erdo_key ? ' is-active' : ''; ?>"
               data-tab="<?php echo esc_attr( $erdo_key ); ?>"
               <?php echo $tab === $erdo_key ? 'aria-current="page"' : ''; ?>>
                <?php echo esc_html( $erdo_label ); ?>
                <?php if ( 'library' === $erdo_key ) : ?>
                    <span class="erdo-count" id="erdo-count-library">…</span>
                <?php elseif ( 'cleanup' === $erdo_key ) : ?>
                    <span class="erdo-count" id="erdo-count-cleanup">…</span>
                <?php elseif ( 'ai' === $erdo_key ) : ?>
                    <span class="erdo-count" id="erdo-count-ai"><?php echo esc_html( number_format_i18n( $erdo_ai_count ) ); ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <!-- ============================================== OVERVIEW -->
    <section class="erdo-panel" data-panel="overview"<?php echo 'overview' === $tab ? '' : ' hidden'; ?>>
        <div class="erdo-ov-top">
            <div class="erdo-card erdo-health">
                <div class="erdo-ring" id="erdo-health-ring" style="--v:0"><div><b id="erdo-health-score">—</b><span><?php esc_html_e( 'of 100', 'erdo-image-optimizer' ); ?></span></div></div>
                <div>
                    <h2 id="erdo-health-label"><?php esc_html_e( 'Image health', 'erdo-image-optimizer' ); ?></h2>
                    <p><?php esc_html_e( 'Average of the SEO score and the share of images with a WebP/AVIF copy.', 'erdo-image-optimizer' ); ?></p>
                </div>
            </div>
            <div class="erdo-card erdo-metrics">
                <div class="erdo-metric">
                    <span class="erdo-metric-label"><?php esc_html_e( 'Average SEO score', 'erdo-image-optimizer' ); ?></span>
                    <span class="erdo-metric-value" id="erdo-m-seo">—</span>
                    <span class="erdo-metric-sub" id="erdo-m-seo-sub">&nbsp;</span>
                    <div class="erdo-bar"><i id="erdo-m-seo-bar"></i></div>
                </div>
                <div class="erdo-metric">
                    <span class="erdo-metric-label"><?php esc_html_e( 'WebP / AVIF coverage', 'erdo-image-optimizer' ); ?></span>
                    <span class="erdo-metric-value" id="erdo-m-cov">—</span>
                    <span class="erdo-metric-sub" id="erdo-m-cov-sub">&nbsp;</span>
                    <div class="erdo-bar"><i id="erdo-m-cov-bar"></i></div>
                </div>
                <div class="erdo-metric">
                    <span class="erdo-metric-label"><?php esc_html_e( 'Saved for visitors', 'erdo-image-optimizer' ); ?></span>
                    <span class="erdo-metric-value" id="erdo-m-saved">—</span>
                    <span class="erdo-metric-sub"><?php esc_html_e( 'Originals stay untouched', 'erdo-image-optimizer' ); ?></span>
                </div>
                <div class="erdo-metric">
                    <span class="erdo-metric-label"><?php esc_html_e( 'Unused images', 'erdo-image-optimizer' ); ?></span>
                    <span class="erdo-metric-value" id="erdo-m-unused">—</span>
                    <span class="erdo-metric-sub" id="erdo-m-unused-sub">
                        <?php
                        /* translators: %s: number of images in quarantine */
                        echo esc_html( sprintf( __( '%s in quarantine', 'erdo-image-optimizer' ), number_format_i18n( $erdo_q_count ) ) );
                        ?>
                    </span>
                </div>
            </div>
        </div>

        <div class="erdo-ov-grid">
            <div class="erdo-card">
                <div class="erdo-card-h">
                    <h2><?php esc_html_e( 'Fix next', 'erdo-image-optimizer' ); ?></h2>
                    <span class="erdo-muted erdo-small"><?php esc_html_e( 'Sorted by SEO impact', 'erdo-image-optimizer' ); ?></span>
                </div>
                <div id="erdo-tasks" aria-live="polite">
                    <p class="erdo-loading-row"><span class="dashicons dashicons-update erdo-spin" aria-hidden="true"></span> <?php esc_html_e( 'Checking your library…', 'erdo-image-optimizer' ); ?></p>
                </div>
            </div>

            <div class="erdo-ov-side">
                <div class="erdo-card">
                    <div class="erdo-card-h">
                        <h2><?php esc_html_e( 'This server', 'erdo-image-optimizer' ); ?></h2>
                        <button type="button" class="button-link" id="erdo-run-setup"><?php esc_html_e( 'Run setup again', 'erdo-image-optimizer' ); ?></button>
                    </div>
                    <ul class="erdo-env">
                        <?php foreach ( $env as $erdo_row ) : ?>
                            <li>
                                <span><?php echo esc_html( $erdo_row['label'] ); ?></span>
                                <span class="erdo-state-<?php echo esc_attr( $erdo_row['state'] ); ?>"><?php echo esc_html( $erdo_row['value'] ); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div class="erdo-card">
                    <div class="erdo-card-h"><h2><?php esc_html_e( 'Recent activity', 'erdo-image-optimizer' ); ?></h2></div>
                    <ul class="erdo-activity" id="erdo-activity"></ul>
                </div>
            </div>
        </div>
    </section>

    <!-- ============================================== LIBRARY -->
    <section class="erdo-panel" data-panel="library"<?php echo 'library' === $tab ? '' : ' hidden'; ?>>
        <div class="erdo-toolbar">
            <label class="erdo-search">
                <span class="dashicons dashicons-search" aria-hidden="true"></span>
                <span class="screen-reader-text"><?php esc_html_e( 'Search images', 'erdo-image-optimizer' ); ?></span>
                <input type="search" id="erdo-search" placeholder="<?php esc_attr_e( 'Search file name, title or alt text', 'erdo-image-optimizer' ); ?>">
            </label>
            <label>
                <span class="screen-reader-text"><?php esc_html_e( 'Issue', 'erdo-image-optimizer' ); ?></span>
                <select id="erdo-issue">
                    <option value=""><?php esc_html_e( 'All issues', 'erdo-image-optimizer' ); ?></option>
                    <option value="alt"><?php esc_html_e( 'Missing alt text', 'erdo-image-optimizer' ); ?></option>
                    <option value="weakalt"><?php esc_html_e( 'Weak alt text', 'erdo-image-optimizer' ); ?></option>
                    <option value="filename"><?php esc_html_e( 'Weak file name', 'erdo-image-optimizer' ); ?></option>
                    <option value="format"><?php esc_html_e( 'No WebP/AVIF copy', 'erdo-image-optimizer' ); ?></option>
                    <option value="oversized"><?php esc_html_e( 'Oversized', 'erdo-image-optimizer' ); ?></option>
                    <option value="context"><?php esc_html_e( 'Not on an indexable page', 'erdo-image-optimizer' ); ?></option>
                </select>
            </label>
            <label>
                <span class="screen-reader-text"><?php esc_html_e( 'Sort', 'erdo-image-optimizer' ); ?></span>
                <select id="erdo-sort">
                    <option value="score"><?php esc_html_e( 'Lowest score first', 'erdo-image-optimizer' ); ?></option>
                    <option value="new"><?php esc_html_e( 'Newest first', 'erdo-image-optimizer' ); ?></option>
                    <option value="size"><?php esc_html_e( 'Largest file first', 'erdo-image-optimizer' ); ?></option>
                    <option value="name"><?php esc_html_e( 'File name', 'erdo-image-optimizer' ); ?></option>
                </select>
            </label>
            <span class="erdo-sp"></span>
            <div class="erdo-seg" role="group" aria-label="<?php esc_attr_e( 'View', 'erdo-image-optimizer' ); ?>">
                <button type="button" data-view="grid" aria-pressed="true"><span class="dashicons dashicons-grid-view" aria-hidden="true"></span><?php esc_html_e( 'Grid', 'erdo-image-optimizer' ); ?></button>
                <button type="button" data-view="list" aria-pressed="false"><span class="dashicons dashicons-list-view" aria-hidden="true"></span><?php esc_html_e( 'List', 'erdo-image-optimizer' ); ?></button>
            </div>
            <div class="erdo-seg erdo-size-seg" role="group" aria-label="<?php esc_attr_e( 'Card size', 'erdo-image-optimizer' ); ?>">
                <button type="button" data-size="small" aria-pressed="false" title="<?php esc_attr_e( 'Small', 'erdo-image-optimizer' ); ?>">S</button>
                <button type="button" data-size="medium" aria-pressed="true" title="<?php esc_attr_e( 'Medium', 'erdo-image-optimizer' ); ?>">M</button>
                <button type="button" data-size="large" aria-pressed="false" title="<?php esc_attr_e( 'Large', 'erdo-image-optimizer' ); ?>">L</button>
            </div>
            <div class="erdo-dropdown-wrap">
                <button type="button" id="erdo-tools-btn" class="button" aria-haspopup="true" aria-expanded="false">
                    <?php esc_html_e( 'More', 'erdo-image-optimizer' ); ?>
                    <span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
                </button>
                <div class="erdo-dropdown" id="erdo-tools-dropdown" role="menu">
                    <button type="button" role="menuitem" class="erdo-dropdown-item" id="erdo-bulk-alt-btn"><span class="dashicons dashicons-format-image" aria-hidden="true"></span><?php esc_html_e( 'Alt text from file names…', 'erdo-image-optimizer' ); ?></button>
                    <button type="button" role="menuitem" class="erdo-dropdown-item" id="erdo-export-csv-btn"><span class="dashicons dashicons-download" aria-hidden="true"></span><?php esc_html_e( 'Export SEO report (CSV)', 'erdo-image-optimizer' ); ?></button>
                    <button type="button" role="menuitem" class="erdo-dropdown-item" id="erdo-scan-thumbnails-btn"><span class="dashicons dashicons-format-aside" aria-hidden="true"></span><?php esc_html_e( 'Posts without a featured image', 'erdo-image-optimizer' ); ?></button>
                    <div class="erdo-dropdown-sep"></div>
                    <button type="button" role="menuitem" class="erdo-dropdown-item" id="erdo-refresh-all-btn"><span class="dashicons dashicons-update" aria-hidden="true"></span><?php esc_html_e( 'Recalculate all scores', 'erdo-image-optimizer' ); ?></button>
                </div>
            </div>
        </div>

        <div class="erdo-chips" role="group" aria-label="<?php esc_attr_e( 'Score filter', 'erdo-image-optimizer' ); ?>">
            <button type="button" class="erdo-chip" data-cat="" aria-pressed="true"><?php esc_html_e( 'All', 'erdo-image-optimizer' ); ?> <span class="erdo-chip-n" data-n=""></span></button>
            <button type="button" class="erdo-chip" data-cat="red" aria-pressed="false"><i class="erdo-dot is-red" aria-hidden="true"></i><?php esc_html_e( 'Poor', 'erdo-image-optimizer' ); ?> <span class="erdo-chip-n" data-n="red"></span></button>
            <button type="button" class="erdo-chip" data-cat="orange" aria-pressed="false"><i class="erdo-dot is-orange" aria-hidden="true"></i><?php esc_html_e( 'Needs work', 'erdo-image-optimizer' ); ?> <span class="erdo-chip-n" data-n="orange"></span></button>
            <button type="button" class="erdo-chip" data-cat="green" aria-pressed="false"><i class="erdo-dot is-green" aria-hidden="true"></i><?php esc_html_e( 'Good', 'erdo-image-optimizer' ); ?> <span class="erdo-chip-n" data-n="green"></span></button>
            <button type="button" class="erdo-chip" data-cat="gray" aria-pressed="false"><i class="erdo-dot is-gray" aria-hidden="true"></i><?php esc_html_e( 'Unused', 'erdo-image-optimizer' ); ?> <span class="erdo-chip-n" data-n="gray"></span></button>
        </div>

        <p class="erdo-loading-row" id="erdo-lib-loading"><span class="dashicons dashicons-update erdo-spin" aria-hidden="true"></span> <span id="erdo-lib-loading-text"><?php esc_html_e( 'Loading library...', 'erdo-image-optimizer' ); ?></span></p>
        <div class="erdo-grid" id="erdo-grid" data-size="medium"></div>
        <table class="erdo-table hidden" id="erdo-list">
            <thead>
                <tr>
                    <th class="erdo-col-check"><span class="screen-reader-text"><?php esc_html_e( 'Select', 'erdo-image-optimizer' ); ?></span></th>
                    <th class="erdo-col-thumb"><span class="screen-reader-text"><?php esc_html_e( 'Image', 'erdo-image-optimizer' ); ?></span></th>
                    <th><?php esc_html_e( 'File', 'erdo-image-optimizer' ); ?></th>
                    <th><?php esc_html_e( 'Alt text', 'erdo-image-optimizer' ); ?></th>
                    <th><?php esc_html_e( 'Score', 'erdo-image-optimizer' ); ?></th>
                    <th><?php esc_html_e( 'Served as', 'erdo-image-optimizer' ); ?></th>
                    <th><?php esc_html_e( 'Size', 'erdo-image-optimizer' ); ?></th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
        <p class="erdo-empty hidden" id="erdo-empty"><?php esc_html_e( 'No images match these filters.', 'erdo-image-optimizer' ); ?></p>
        <div class="erdo-more-wrap hidden" id="erdo-more-wrap">
            <button type="button" class="button" id="erdo-more-btn"><?php esc_html_e( 'Show more', 'erdo-image-optimizer' ); ?></button>
        </div>
        <div class="erdo-more-wrap hidden" id="erdo-retry-wrap">
            <p><?php esc_html_e( 'Loading was interrupted.', 'erdo-image-optimizer' ); ?></p>
            <button type="button" class="button" id="erdo-retry-btn"><?php esc_html_e( 'Continue loading', 'erdo-image-optimizer' ); ?></button>
        </div>
    </section>

    <!-- ============================================== CLEANUP -->
    <section class="erdo-panel" data-panel="cleanup"<?php echo 'cleanup' === $tab ? '' : ' hidden'; ?>>
        <div class="erdo-seg erdo-subnav" role="tablist">
            <button type="button" role="tab" id="erdo-cl-tab-unused" aria-controls="erdo-cl-unused" aria-selected="true"><?php esc_html_e( 'Unused', 'erdo-image-optimizer' ); ?> <span class="erdo-count" id="erdo-count-unused">…</span></button>
            <button type="button" role="tab" id="erdo-cl-tab-q" aria-controls="erdo-cl-q" aria-selected="false"><?php esc_html_e( 'Quarantine', 'erdo-image-optimizer' ); ?> <span class="erdo-count" id="erdo-count-q"><?php echo esc_html( number_format_i18n( $erdo_q_count ) ); ?></span></button>
        </div>

        <div id="erdo-cl-unused" role="tabpanel" aria-labelledby="erdo-cl-tab-unused">
            <div class="erdo-explain">
                <div class="erdo-card">
                    <span class="erdo-muted erdo-small"><?php esc_html_e( 'Unused', 'erdo-image-optimizer' ); ?></span>
                    <b id="erdo-unused-summary">—</b>
                    <span class="erdo-muted erdo-small"><?php esc_html_e( 'Not referenced in posts, pages, page builders, custom fields, theme settings, widgets or terms.', 'erdo-image-optimizer' ); ?></span>
                </div>
                <div class="erdo-card">
                    <span class="erdo-muted erdo-small"><?php esc_html_e( 'Safety check', 'erdo-image-optimizer' ); ?></span>
                    <b><?php esc_html_e( 'Checked again before moving', 'erdo-image-optimizer' ); ?></b>
                    <span class="erdo-muted erdo-small"><?php esc_html_e( 'If an image’s file name still appears anywhere (for example in CSS or a builder setting), it is skipped.', 'erdo-image-optimizer' ); ?></span>
                </div>
                <div class="erdo-card">
                    <span class="erdo-muted erdo-small"><?php esc_html_e( 'What cannot be checked', 'erdo-image-optimizer' ); ?></span>
                    <b><?php esc_html_e( 'Theme files and other sites', 'erdo-image-optimizer' ); ?></b>
                    <span class="erdo-muted erdo-small"><?php esc_html_e( 'Images hard-coded in PHP templates or linked from other domains. Check those before deleting anything permanently.', 'erdo-image-optimizer' ); ?></span>
                </div>
            </div>
            <div class="erdo-toolbar">
                <h2 class="erdo-section-title"><?php esc_html_e( 'Unused images', 'erdo-image-optimizer' ); ?></h2>
                <span class="erdo-sp"></span>
                <button type="button" class="button button-primary" id="erdo-quarantine-all" disabled>
                    <span class="dashicons dashicons-archive" aria-hidden="true"></span>
                    <span id="erdo-quarantine-all-text"><?php esc_html_e( 'Move to quarantine…', 'erdo-image-optimizer' ); ?></span>
                </button>
            </div>
            <div class="erdo-grid" id="erdo-unused-grid" data-size="medium"></div>
            <p class="erdo-empty hidden" id="erdo-unused-empty"><?php esc_html_e( 'No unused images found in your library.', 'erdo-image-optimizer' ); ?></p>
        </div>

        <div id="erdo-cl-q" role="tabpanel" aria-labelledby="erdo-cl-tab-q" hidden>
            <div class="erdo-card">
                <div class="erdo-card-h">
                    <h2><?php esc_html_e( 'Quarantine', 'erdo-image-optimizer' ); ?></h2>
                    <span class="erdo-muted erdo-small">
                        <?php
                        $erdo_days = (int) get_option( 'erdo_quarantine_days', 0 );
                        echo esc_html( $erdo_days
                            /* translators: %d: number of days */
                            ? sprintf( _n( 'Hidden from the site and media library. Deleted permanently after %d day.', 'Hidden from the site and media library. Deleted permanently after %d days.', $erdo_days, 'erdo-image-optimizer' ), $erdo_days )
                            : __( 'Hidden from the site and media library. Nothing is deleted until you do it here.', 'erdo-image-optimizer' ) );
                        ?>
                    </span>
                    <button type="button" class="button" id="erdo-q-restore-all" hidden><span class="dashicons dashicons-undo" aria-hidden="true"></span><?php esc_html_e( 'Restore all', 'erdo-image-optimizer' ); ?></button>
                </div>
                <table class="erdo-table erdo-table-flat" id="erdo-q-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'File', 'erdo-image-optimizer' ); ?></th>
                            <th><?php esc_html_e( 'Moved', 'erdo-image-optimizer' ); ?></th>
                            <th class="erdo-right"><?php esc_html_e( 'Actions', 'erdo-image-optimizer' ); ?></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
                <p class="erdo-empty hidden" id="erdo-q-empty"><?php esc_html_e( 'Quarantine is empty.', 'erdo-image-optimizer' ); ?></p>
            </div>
        </div>
    </section>

    <!-- ============================================== AI -->
    <section class="erdo-panel" data-panel="ai"<?php echo 'ai' === $tab ? '' : ' hidden'; ?>>
        <?php $this->render_ai_setup_notice(); ?>
        <div class="erdo-toolbar">
            <div>
                <h2 class="erdo-section-title" id="erdo-ai-title"><?php esc_html_e( 'Suggestions waiting for review', 'erdo-image-optimizer' ); ?></h2>
                <p class="erdo-muted erdo-small erdo-flush"><?php esc_html_e( 'Nothing is saved until you accept it. Check each suggestion against the image and edit it if needed.', 'erdo-image-optimizer' ); ?></p>
            </div>
            <span class="erdo-sp"></span>
            <button type="button" class="button" id="erdo-ai-bulk-btn"<?php disabled( ! ERDO_AI::is_ready() ); ?>>
                <span class="dashicons dashicons-lightbulb" aria-hidden="true"></span>
                <?php esc_html_e( 'Suggest for more images…', 'erdo-image-optimizer' ); ?>
            </button>
            <button type="button" class="button button-primary" id="erdo-ai-accept-all" disabled>
                <span class="dashicons dashicons-yes" aria-hidden="true"></span>
                <span><?php esc_html_e( 'Accept checked', 'erdo-image-optimizer' ); ?></span>
            </button>
        </div>
        <div class="erdo-card" id="erdo-ai-list"></div>
    </section>

    <!-- ============================================== SETTINGS -->
    <section class="erdo-panel" data-panel="settings"<?php echo 'settings' === $tab ? '' : ' hidden'; ?>>
        <?php $this->render_settings(); ?>
    </section>

    <div class="erdo-selbar hidden" id="erdo-selbar" role="region" aria-label="<?php esc_attr_e( 'Selected images', 'erdo-image-optimizer' ); ?>">
        <b id="erdo-sel-count"></b>
        <button type="button" class="button button-primary" id="erdo-sel-ai"<?php disabled( ! ERDO_AI::is_ready() ); ?>><span class="dashicons dashicons-lightbulb" aria-hidden="true"></span><?php esc_html_e( 'AI suggestions', 'erdo-image-optimizer' ); ?></button>
        <button type="button" class="button" id="erdo-sel-rename"><span class="dashicons dashicons-tag" aria-hidden="true"></span><?php esc_html_e( 'Rename…', 'erdo-image-optimizer' ); ?></button>
        <button type="button" class="button" id="erdo-sel-convert"><span class="dashicons dashicons-update" aria-hidden="true"></span><?php esc_html_e( 'Convert', 'erdo-image-optimizer' ); ?></button>
        <button type="button" class="button" id="erdo-sel-quarantine"><span class="dashicons dashicons-archive" aria-hidden="true"></span><?php esc_html_e( 'Quarantine', 'erdo-image-optimizer' ); ?></button>
        <span class="erdo-sp"></span>
        <button type="button" class="button-link" id="erdo-sel-clear"><?php esc_html_e( 'Clear', 'erdo-image-optimizer' ); ?></button>
    </div>
</div>

<div class="erdo-overlay" id="erdo-drawer-overlay" hidden>
    <aside class="erdo-drawer" id="erdo-drawer" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Image details', 'erdo-image-optimizer' ); ?>" tabindex="-1"></aside>
</div>

<div class="erdo-overlay erdo-dialog-wrap" id="erdo-dialog-wrap" hidden>
    <div class="erdo-dialog" id="erdo-dialog" role="dialog" aria-modal="true" aria-labelledby="erdo-dialog-title" tabindex="-1"></div>
</div>
