<?php
/**
 * Ayarlar sekmesi. Tek form; soldaki bölüm listesi yalnızca görünen bölümü
 * değiştirir, kayıt hepsini birlikte gönderir (ERDO_Admin_Page::ajax_save_settings).
 *
 * @var ERDO_Admin_Page $this
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$erdo_avif      = ERDO_Encoder::supports( 'avif' );
$erdo_webp      = ERDO_Encoder::supports( 'webp' );
$erdo_fmt       = get_option( 'erdo_convert_format', 'webp' );
$erdo_q         = (int) get_option( 'erdo_quality', 80 );
$erdo_ai        = ERDO_AI::status();
$erdo_seo_owner = ERDO_SEO::seo_plugin_handles_image_sitemap();
$erdo_method    = get_option( 'erdo_webp_serve_method', 'picture' );
$erdo_apache    = ERDO_WebP_Server::is_apache();
$erdo_nginx     = ERDO_WebP_Server::is_nginx();

$erdo_sections = array(
    'conversion' => __( 'Conversion', 'erdo-image-optimizer' ),
    'delivery'   => __( 'Delivery', 'erdo-image-optimizer' ),
    'uploads'    => __( 'Uploads', 'erdo-image-optimizer' ),
    'seo'        => __( 'SEO', 'erdo-image-optimizer' ),
    'ai'         => __( 'AI', 'erdo-image-optimizer' ),
    'cleanup'    => __( 'Cleanup', 'erdo-image-optimizer' ),
    'uninstall'  => __( 'Uninstall', 'erdo-image-optimizer' ),
);

/** Açma/kapama satırı: başlık + tek cümle açıklama + anahtar. */
$erdo_switch = function ( $name, $title, $desc, $checked, $disabled = false ) {
    $id = 'erdo-set-' . $name;
    ?>
    <div class="erdo-srow">
        <div>
            <label for="<?php echo esc_attr( $id ); ?>"><b><?php echo esc_html( $title ); ?></b></label>
            <p id="<?php echo esc_attr( $id ); ?>-desc"><?php echo wp_kses( $desc, array( 'a' => array( 'href' => true ) ) ); ?></p>
        </div>
        <div class="erdo-ctl">
            <input type="checkbox" class="erdo-switch" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="1"
                aria-describedby="<?php echo esc_attr( $id ); ?>-desc" <?php checked( (bool) $checked ); ?> <?php disabled( $disabled ); ?>>
        </div>
    </div>
    <?php
};
?>
<form id="erdo-settings-form" class="erdo-settings" autocomplete="off">
    <nav class="erdo-set-nav" aria-label="<?php esc_attr_e( 'Settings sections', 'erdo-image-optimizer' ); ?>">
        <?php $erdo_first = true; foreach ( $erdo_sections as $erdo_key => $erdo_label ) : ?>
            <button type="button" data-section="<?php echo esc_attr( $erdo_key ); ?>" aria-pressed="<?php echo $erdo_first ? 'true' : 'false'; ?>"><?php echo esc_html( $erdo_label ); ?></button>
        <?php $erdo_first = false; endforeach; ?>
    </nav>

    <div class="erdo-card erdo-set-body">

        <fieldset class="erdo-set-section" data-section="conversion">
            <div class="erdo-card-h">
                <legend><h2><?php esc_html_e( 'Conversion', 'erdo-image-optimizer' ); ?></h2></legend>
                <span class="erdo-muted erdo-small"><?php esc_html_e( 'Originals are never changed — WebP/AVIF copies are saved next to them.', 'erdo-image-optimizer' ); ?></span>
            </div>
            <?php if ( ! $erdo_webp ) : ?>
                <div class="notice notice-warning inline"><p><?php esc_html_e( 'This server cannot create WebP files (GD or Imagick with WebP support is required).', 'erdo-image-optimizer' ); ?></p></div>
            <?php endif; ?>
            <div class="erdo-srow erdo-srow-block">
                <div>
                    <b><?php esc_html_e( 'Format', 'erdo-image-optimizer' ); ?></b>
                    <p><?php esc_html_e( 'Which modern copies to create.', 'erdo-image-optimizer' ); ?></p>
                </div>
                <div class="erdo-radios">
                    <label>
                        <input type="radio" name="erdo_convert_format" value="webp" <?php checked( $erdo_fmt, 'webp' ); ?>>
                        <span><b>WebP</b><?php if ( ! $erdo_avif ) : ?> <span class="erdo-pill is-green"><?php esc_html_e( 'Recommended', 'erdo-image-optimizer' ); ?></span><?php endif; ?>
                        <small><?php esc_html_e( 'Supported by every current browser. Fast to create.', 'erdo-image-optimizer' ); ?></small></span>
                    </label>
                    <label class="<?php echo $erdo_avif ? '' : 'is-disabled'; ?>">
                        <input type="radio" name="erdo_convert_format" value="avif" <?php checked( $erdo_fmt, 'avif' ); ?> <?php disabled( ! $erdo_avif ); ?>>
                        <span><b>AVIF</b>
                        <small><?php echo esc_html( $erdo_avif ? __( 'Smallest files, slower to create.', 'erdo-image-optimizer' ) : __( 'Smallest files. Not available on this server.', 'erdo-image-optimizer' ) ); ?></small></span>
                    </label>
                    <label class="<?php echo $erdo_avif ? '' : 'is-disabled'; ?>">
                        <input type="radio" name="erdo_convert_format" value="both" <?php checked( $erdo_fmt, 'both' ); ?> <?php disabled( ! $erdo_avif ); ?>>
                        <span><b>AVIF + WebP</b><?php if ( $erdo_avif ) : ?> <span class="erdo-pill is-green"><?php esc_html_e( 'Recommended', 'erdo-image-optimizer' ); ?></span><?php endif; ?>
                        <small><?php esc_html_e( 'Browsers get the best format they support. Needs AVIF.', 'erdo-image-optimizer' ); ?></small></span>
                    </label>
                </div>
            </div>
            <div class="erdo-srow">
                <div>
                    <label for="erdo-set-quality"><b><?php esc_html_e( 'Quality', 'erdo-image-optimizer' ); ?></b></label>
                    <p><?php esc_html_e( '80 is a good balance between file size and sharpness. Ignored in lossless and smart quality mode.', 'erdo-image-optimizer' ); ?></p>
                </div>
                <div class="erdo-ctl">
                    <input type="range" id="erdo-set-quality" name="erdo_quality" min="10" max="100" value="<?php echo esc_attr( $erdo_q ); ?>">
                    <output for="erdo-set-quality" id="erdo-quality-out"><?php echo esc_html( $erdo_q ); ?></output>
                </div>
            </div>
            <?php
            $erdo_switch( 'erdo_smart_quality', __( 'Smart quality', 'erdo-image-optimizer' ), __( 'Find the lowest quality that still looks the same, per image. Replaces the quality slider.', 'erdo-image-optimizer' ), get_option( 'erdo_smart_quality', 0 ) );
            $erdo_switch( 'erdo_lossless', __( 'Lossless WebP', 'erdo-image-optimizer' ), __( 'Pixel-identical copies. Usually larger; copies bigger than the original are not kept.', 'erdo-image-optimizer' ), get_option( 'erdo_lossless', 0 ) );
            $erdo_switch( 'erdo_auto_webp', __( 'Convert new uploads', 'erdo-image-optimizer' ), __( 'Create the copies automatically right after an image is uploaded.', 'erdo-image-optimizer' ), get_option( 'erdo_auto_webp', 0 ) );
            ?>
        </fieldset>

        <fieldset class="erdo-set-section" data-section="delivery" hidden>
            <div class="erdo-card-h"><legend><h2><?php esc_html_e( 'Delivery', 'erdo-image-optimizer' ); ?></h2></legend></div>
            <?php $erdo_switch( 'erdo_serve_webp', __( 'Serve WebP/AVIF to browsers', 'erdo-image-optimizer' ), __( 'Browsers that support them get the copies; everyone else gets the original.', 'erdo-image-optimizer' ), get_option( 'erdo_serve_webp', 0 ) ); ?>
            <div class="erdo-srow erdo-srow-block">
                <div>
                    <b><?php esc_html_e( 'Method', 'erdo-image-optimizer' ); ?></b>
                    <p><?php esc_html_e( 'How the copies reach the browser.', 'erdo-image-optimizer' ); ?></p>
                </div>
                <div class="erdo-radios">
                    <label>
                        <input type="radio" name="erdo_webp_serve_method" value="picture" <?php checked( $erdo_method, 'picture' ); ?>>
                        <span><b><?php esc_html_e( '&lt;picture&gt; tags', 'erdo-image-optimizer' ); ?></b> <span class="erdo-pill is-green"><?php esc_html_e( 'Recommended', 'erdo-image-optimizer' ); ?></span>
                        <small><?php esc_html_e( 'Works on every server and behind any CDN or page cache.', 'erdo-image-optimizer' ); ?></small></span>
                    </label>
                    <label class="<?php echo $erdo_apache ? '' : 'is-disabled'; ?>">
                        <input type="radio" name="erdo_webp_serve_method" value="htaccess" <?php checked( $erdo_method, 'htaccess' ); ?> <?php disabled( ! $erdo_apache ); ?>>
                        <span><b><?php esc_html_e( '.htaccess rewrite', 'erdo-image-optimizer' ); ?></b>
                        <small>
                            <?php
                            if ( ! $erdo_apache ) {
                                esc_html_e( 'Apache only — not available on this server.', 'erdo-image-optimizer' );
                            } elseif ( ERDO_WebP_Server::htaccess_has_rules() ) {
                                esc_html_e( 'Apache only. The rules are active in the uploads folder.', 'erdo-image-optimizer' );
                            } else {
                                esc_html_e( 'Apache only. Same image URLs; a CDN must respect "Vary: Accept". The rules are written when you save.', 'erdo-image-optimizer' );
                            }
                            ?>
                        </small></span>
                    </label>
                </div>
            </div>
            <?php if ( $erdo_nginx ) : ?>
            <div class="erdo-srow erdo-srow-block">
                <div>
                    <b><?php esc_html_e( 'nginx', 'erdo-image-optimizer' ); ?></b>
                    <p><?php esc_html_e( 'Optional: with this snippet nginx serves the copies itself, so you can turn the &lt;picture&gt; tags off.', 'erdo-image-optimizer' ); ?></p>
                </div>
                <details class="erdo-details">
                    <summary><?php esc_html_e( 'Show the nginx configuration', 'erdo-image-optimizer' ); ?></summary>
                    <pre class="erdo-code"><?php echo esc_html( ERDO_WebP_Server::get_nginx_config() ); ?></pre>
                </details>
            </div>
            <?php endif; ?>
        </fieldset>

        <fieldset class="erdo-set-section" data-section="uploads" hidden>
            <div class="erdo-card-h"><legend><h2><?php esc_html_e( 'Uploads', 'erdo-image-optimizer' ); ?></h2></legend></div>
            <?php
            $erdo_switch( 'erdo_auto_sanitize', __( 'Clean file names', 'erdo-image-optimizer' ), __( 'Replace spaces, underscores and special characters with hyphens when an image is uploaded.', 'erdo-image-optimizer' ), get_option( 'erdo_auto_sanitize', 0 ) );
            $erdo_switch( 'erdo_remove_exif', __( 'Remove camera data (EXIF)', 'erdo-image-optimizer' ), __( 'Strip camera details and GPS location from new uploads. This rewrites the uploaded original once.', 'erdo-image-optimizer' ), get_option( 'erdo_remove_exif', 0 ) );
            ?>
            <div class="erdo-srow">
                <div>
                    <label for="erdo-set-max"><b><?php esc_html_e( 'Maximum image size', 'erdo-image-optimizer' ); ?></b></label>
                    <p><?php esc_html_e( 'Larger uploads get a scaled-down copy that WordPress uses everywhere; the full-size original is kept. WordPress default: 2560. 0 = WordPress default.', 'erdo-image-optimizer' ); ?></p>
                </div>
                <div class="erdo-ctl">
                    <input type="number" id="erdo-set-max" name="erdo_max_width" min="0" max="8000" class="small-text" value="<?php echo esc_attr( (int) get_option( 'erdo_max_width', 0 ) ); ?>"> <span class="erdo-muted">px</span>
                </div>
            </div>
        </fieldset>

        <fieldset class="erdo-set-section" data-section="seo" hidden>
            <div class="erdo-card-h"><legend><h2><?php esc_html_e( 'SEO', 'erdo-image-optimizer' ); ?></h2></legend></div>
            <?php
            $erdo_switch(
                'erdo_image_sitemap',
                __( 'Image sitemap', 'erdo-image-optimizer' ),
                $erdo_seo_owner
                    ? __( 'Your SEO plugin already lists images in its sitemap, so this stays off.', 'erdo-image-optimizer' )
                    : __( 'List the images of every published page in the WordPress sitemap (wp-sitemap.xml) so Google Images can find them.', 'erdo-image-optimizer' ),
                get_option( 'erdo_image_sitemap', 1 ) && ! $erdo_seo_owner,
                $erdo_seo_owner
            );
            $erdo_switch( 'erdo_append_original_filename', __( 'Keep the old name when renaming', 'erdo-image-optimizer' ), __( 'In the image panel, add the original file name as a suffix (e.g. "istanbul-img-1234") to avoid collisions.', 'erdo-image-optimizer' ), get_option( 'erdo_append_original_filename', 0 ) );
            ?>
        </fieldset>

        <fieldset class="erdo-set-section" data-section="ai" hidden>
            <div class="erdo-card-h"><legend><h2><?php esc_html_e( 'AI', 'erdo-image-optimizer' ); ?></h2></legend></div>
            <div class="erdo-srow">
                <div>
                    <b><?php esc_html_e( 'Provider', 'erdo-image-optimizer' ); ?></b>
                    <p>
                        <?php
                        echo esc_html( ! empty( $erdo_ai['ready'] )
                            ? __( 'Connected. Your API key is kept by WordPress under Settings > Connectors, never by this plugin.', 'erdo-image-optimizer' )
                            : $erdo_ai['message'] );
                        ?>
                    </p>
                </div>
                <div class="erdo-ctl">
                    <?php if ( 'wp' !== $erdo_ai['reason'] ) : ?>
                        <a class="button" href="<?php echo esc_url( $erdo_ai['setup_url'] ); ?>"><?php esc_html_e( 'Open Connectors', 'erdo-image-optimizer' ); ?></a>
                    <?php endif; ?>
                </div>
            </div>
            <?php $erdo_switch( 'erdo_ai_on_upload', __( 'Suggest on upload', 'erdo-image-optimizer' ), __( 'After each upload, ask your AI provider for an alt text suggestion in the background. Nothing is saved without your approval; each image costs a few provider tokens.', 'erdo-image-optimizer' ), get_option( 'erdo_ai_on_upload', 0 ), empty( $erdo_ai['ready'] ) ); ?>
        </fieldset>

        <fieldset class="erdo-set-section" data-section="cleanup" hidden>
            <div class="erdo-card-h"><legend><h2><?php esc_html_e( 'Cleanup', 'erdo-image-optimizer' ); ?></h2></legend></div>
            <div class="erdo-srow">
                <div>
                    <label for="erdo-set-qdays"><b><?php esc_html_e( 'Empty quarantine automatically', 'erdo-image-optimizer' ); ?></b></label>
                    <p><?php esc_html_e( 'Delete quarantined images permanently after this many days. 0 = never; you delete them yourself.', 'erdo-image-optimizer' ); ?></p>
                </div>
                <div class="erdo-ctl">
                    <input type="number" id="erdo-set-qdays" name="erdo_quarantine_days" min="0" max="365" class="small-text" value="<?php echo esc_attr( (int) get_option( 'erdo_quarantine_days', 0 ) ); ?>">
                    <span class="erdo-muted"><?php esc_html_e( 'days', 'erdo-image-optimizer' ); ?></span>
                </div>
            </div>
        </fieldset>

        <fieldset class="erdo-set-section" data-section="uninstall" hidden>
            <div class="erdo-card-h">
                <legend><h2><?php esc_html_e( 'When the plugin is deleted', 'erdo-image-optimizer' ); ?></h2></legend>
                <span class="erdo-muted erdo-small"><?php esc_html_e( 'Your original images always stay. Settings and plugin data are removed.', 'erdo-image-optimizer' ); ?></span>
            </div>
            <div class="erdo-srow">
                <div>
                    <label for="erdo-set-unsib"><b><?php esc_html_e( 'WebP/AVIF copies', 'erdo-image-optimizer' ); ?></b></label>
                    <p><?php esc_html_e( 'Keep them only if your server or CDN serves them on its own.', 'erdo-image-optimizer' ); ?></p>
                </div>
                <div class="erdo-ctl">
                    <select id="erdo-set-unsib" name="erdo_uninstall_siblings">
                        <option value="delete" <?php selected( get_option( 'erdo_uninstall_siblings', 'delete' ), 'delete' ); ?>><?php esc_html_e( 'Delete them', 'erdo-image-optimizer' ); ?></option>
                        <option value="keep" <?php selected( get_option( 'erdo_uninstall_siblings', 'delete' ), 'keep' ); ?>><?php esc_html_e( 'Keep them', 'erdo-image-optimizer' ); ?></option>
                    </select>
                </div>
            </div>
            <div class="erdo-srow">
                <div>
                    <label for="erdo-set-unq"><b><?php esc_html_e( 'Images in quarantine', 'erdo-image-optimizer' ); ?></b></label>
                    <p><?php esc_html_e( 'By default they go back into the media library.', 'erdo-image-optimizer' ); ?></p>
                </div>
                <div class="erdo-ctl">
                    <select id="erdo-set-unq" name="erdo_uninstall_quarantine">
                        <option value="restore" <?php selected( get_option( 'erdo_uninstall_quarantine', 'restore' ), 'restore' ); ?>><?php esc_html_e( 'Put them back', 'erdo-image-optimizer' ); ?></option>
                        <option value="delete" <?php selected( get_option( 'erdo_uninstall_quarantine', 'restore' ), 'delete' ); ?>><?php esc_html_e( 'Delete them permanently', 'erdo-image-optimizer' ); ?></option>
                    </select>
                </div>
            </div>
        </fieldset>

        <div class="erdo-savebar" id="erdo-savebar" hidden>
            <span class="erdo-muted" id="erdo-save-status" role="status"><?php esc_html_e( 'Unsaved changes', 'erdo-image-optimizer' ); ?></span>
            <button type="button" class="button" id="erdo-settings-discard"><?php esc_html_e( 'Discard', 'erdo-image-optimizer' ); ?></button>
            <button type="submit" class="button button-primary" id="erdo-settings-save"><?php esc_html_e( 'Save changes', 'erdo-image-optimizer' ); ?></button>
        </div>
    </div>
</form>
