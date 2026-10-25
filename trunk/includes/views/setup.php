<?php
/**
 * İlk açılış sihirbazı: 1) sunucu kontrolü (gerçek deneme kodlaması) →
 * 2) önerilen ayarlar → 3) ilk tarama. Yalnızca erdo_setup_done yokken ya da
 * "Run setup again" ile açılır; atlanabilir. Adımların içeriğini JS doldurur.
 *
 * @var ERDO_Admin_Page $this
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div class="wrap erdo-app" id="erdo-app" data-tab="setup">
    <div class="erdo-head">
        <h1 class="erdo-title">
            <span class="erdo-logo" aria-hidden="true">e</span>
            <?php esc_html_e( 'Erdo Image Optimizer', 'erdo-image-optimizer' ); ?>
            <span class="erdo-ver">v<?php echo esc_html( ERDO_VERSION ); ?></span>
        </h1>
    </div>
    <hr class="wp-header-end">

    <div class="erdo-wiz" id="erdo-setup">
        <ol class="erdo-wiz-steps">
            <li data-step="1" class="is-active"><span>1</span><?php esc_html_e( 'Check this server', 'erdo-image-optimizer' ); ?></li>
            <li data-step="2"><span>2</span><?php esc_html_e( 'Recommended settings', 'erdo-image-optimizer' ); ?></li>
            <li data-step="3"><span>3</span><?php esc_html_e( 'First scan', 'erdo-image-optimizer' ); ?></li>
        </ol>

        <div class="erdo-card">
            <div class="erdo-wiz-pane" data-step="1">
                <div class="erdo-card-h"><h2><?php esc_html_e( 'Testing what this server can do', 'erdo-image-optimizer' ); ?></h2></div>
                <div class="erdo-card-b">
                    <ul class="erdo-chk-list" id="erdo-setup-checks">
                        <li><span class="dashicons dashicons-update erdo-spin" aria-hidden="true"></span><div><b><?php esc_html_e( 'Encoding a test image…', 'erdo-image-optimizer' ); ?></b></div></li>
                    </ul>
                </div>
            </div>

            <div class="erdo-wiz-pane" data-step="2" hidden>
                <div class="erdo-card-h"><h2><?php esc_html_e( 'Recommended for this site', 'erdo-image-optimizer' ); ?></h2></div>
                <div class="erdo-card-b">
                    <p class="erdo-muted"><?php esc_html_e( 'You can change any of these later under Settings.', 'erdo-image-optimizer' ); ?></p>
                    <ul class="erdo-rec" id="erdo-setup-rec"></ul>
                </div>
            </div>

            <div class="erdo-wiz-pane" data-step="3" hidden>
                <div class="erdo-card-h"><h2><?php esc_html_e( 'Scanning your library', 'erdo-image-optimizer' ); ?></h2></div>
                <div class="erdo-card-b">
                    <p id="erdo-setup-scan-text"><?php esc_html_e( 'Finding where each image is used…', 'erdo-image-optimizer' ); ?></p>
                    <div class="erdo-prog"><i id="erdo-setup-prog"></i></div>
                    <p class="erdo-muted erdo-small"><?php esc_html_e( 'Nothing is changed during the scan. You can leave this page; it continues in the background.', 'erdo-image-optimizer' ); ?></p>
                </div>
            </div>

            <div class="erdo-dialog-f">
                <button type="button" class="button-link" id="erdo-setup-skip"><?php esc_html_e( 'Skip, I’ll configure it myself', 'erdo-image-optimizer' ); ?></button>
                <span class="erdo-sp"></span>
                <button type="button" class="button" id="erdo-setup-back" hidden><?php esc_html_e( 'Back', 'erdo-image-optimizer' ); ?></button>
                <button type="button" class="button button-primary" id="erdo-setup-next" disabled><?php esc_html_e( 'Continue', 'erdo-image-optimizer' ); ?></button>
            </div>
        </div>
    </div>
</div>
