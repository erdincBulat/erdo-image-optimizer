<?php
/**
 * Görsel detay paneli (sağdan açılır). ERDO_Admin_Page::ajax_get_detail()
 * HTML olarak döndürür; JS #erdo-drawer içine koyar ve önceki/sonraki
 * düğmelerini kütüphanedeki o anki sıraya bağlar.
 *
 * @var ERDO_Admin_Page $this
 * @var int             $id
 * @var array           $data  ERDO_Auditor::analyze() sonucu
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$erdo_rows  = array();
foreach ( $data['checklist'] as $erdo_item ) {
    $erdo_rows[ $erdo_item['key'] ?? '' ] = $erdo_item;
}
$erdo_score     = (int) ( $data['score'] ?? 0 );
$erdo_cat       = ( isset( $data['is_used'] ) && false === $data['is_used'] ) ? 'gray' : $data['status'];
$erdo_file      = (string) get_attached_file( $id );
$erdo_stem      = pathinfo( $erdo_file, PATHINFO_FILENAME );
$erdo_ext       = pathinfo( $erdo_file, PATHINFO_EXTENSION );
$erdo_meta      = wp_get_attachment_metadata( $id );
$erdo_mime      = get_post_mime_type( $id );
$erdo_best      = ERDO_Converter::best_main_sibling( $id );
$erdo_url       = wp_get_attachment_url( $id );
$erdo_preview   = wp_get_attachment_image_url( $id, 'medium_large' ) ?: $erdo_url;
$erdo_orig_size = (int) ( $erdo_meta['filesize'] ?? 0 );
$erdo_sizes_n   = count( (array) ( $erdo_meta['sizes'] ?? array() ) );
$erdo_parent    = (int) wp_get_post_parent_id( $id );
$erdo_focus     = $erdo_parent ? ERDO_SEO::focus_keyword( $erdo_parent ) : '';
$erdo_last      = ERDO_Renamer::last_rename( $id );
$erdo_resize    = ERDO_Auditor::resize_backup( $id );
$erdo_can_conv  = ! ERDO_Converter::is_optimized( $id, $erdo_mime ) && in_array( $erdo_mime, ERDO_Converter::SOURCE_MIMES, true );
$erdo_alt       = $erdo_rows['alt'] ?? array();
$erdo_dims      = $erdo_rows['dimensions'] ?? array();
$erdo_icons     = array( 'pass' => 'yes', 'partial' => 'warning', 'fail' => 'no-alt', 'info' => 'info-outline' );
?>
<div class="erdo-dr-h">
    <span class="erdo-score is-<?php echo esc_attr( $erdo_cat ); ?>" title="<?php esc_attr_e( 'SEO score (0–100)', 'erdo-image-optimizer' ); ?>"><?php echo 'gray' === $erdo_cat ? '—' : esc_html( $erdo_score ); ?></span>
    <h2 id="erdo-dr-title"><?php echo esc_html( wp_basename( $erdo_file ) ); ?></h2>
    <button type="button" class="button erdo-icon-btn" id="erdo-dr-prev" aria-label="<?php esc_attr_e( 'Previous image', 'erdo-image-optimizer' ); ?>"><span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span></button>
    <button type="button" class="button erdo-icon-btn" id="erdo-dr-next" aria-label="<?php esc_attr_e( 'Next image', 'erdo-image-optimizer' ); ?>"><span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span></button>
    <button type="button" class="button-link erdo-icon-btn" id="erdo-dr-close" aria-label="<?php esc_attr_e( 'Close', 'erdo-image-optimizer' ); ?>"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button>
</div>

<form id="erdo-audit-form" class="erdo-dr-form" data-id="<?php echo esc_attr( $id ); ?>">
    <input type="hidden" name="post_id" value="<?php echo esc_attr( $id ); ?>">
    <input type="hidden" id="erdo-parent-title" value="<?php echo esc_attr( $data['parent_title'] ?? '' ); ?>">
    <input type="hidden" id="erdo-current-filename" value="<?php echo esc_attr( wp_basename( $erdo_file ) ); ?>">

    <div class="erdo-dr-b">
        <div class="erdo-dr-left">
            <div class="erdo-preview">
                <img src="<?php echo esc_url( $erdo_preview ); ?>" alt="">
                <?php if ( $erdo_best ) : ?>
                    <button type="button" class="button erdo-before-after-btn"
                        data-current="<?php echo esc_url( $erdo_url . '.' . $erdo_best['format'] ); ?>"
                        data-original="<?php echo esc_url( $erdo_url ); ?>">
                        <span class="dashicons dashicons-image-flip-horizontal" aria-hidden="true"></span><?php esc_html_e( 'Compare', 'erdo-image-optimizer' ); ?>
                    </button>
                <?php endif; ?>
            </div>

            <h3 class="erdo-sec-t"><?php esc_html_e( 'Files', 'erdo-image-optimizer' ); ?></h3>
            <table class="erdo-kv-table">
                <tr>
                    <td>
                        <?php
                        echo esc_html( sprintf(
                            /* translators: 1: file format (JPEG, PNG…), 2: width, 3: height */
                            __( 'Original %1$s · %2$d×%3$d', 'erdo-image-optimizer' ),
                            strtoupper( (string) $erdo_ext ),
                            (int) ( $erdo_meta['width'] ?? 0 ),
                            (int) ( $erdo_meta['height'] ?? 0 )
                        ) );
                        ?>
                    </td>
                    <td><?php echo $erdo_orig_size ? esc_html( size_format( $erdo_orig_size, 1 ) ) : '—'; ?></td>
                </tr>
                <?php if ( $erdo_best ) : ?>
                <tr>
                    <td>
                        <?php
                        /* translators: %s: WEBP or AVIF */
                        echo esc_html( sprintf( __( '%s copy (served)', 'erdo-image-optimizer' ), strtoupper( $erdo_best['format'] ) ) );
                        ?>
                    </td>
                    <td class="erdo-state-ok">
                        <?php
                        echo esc_html( size_format( $erdo_best['bytes'], 1 ) );
                        if ( $erdo_orig_size > 0 ) {
                            echo ' −' . esc_html( round( ( 1 - $erdo_best['bytes'] / $erdo_orig_size ) * 100 ) ) . '%';
                        }
                        ?>
                    </td>
                </tr>
                <?php endif; ?>
                <tr>
                    <td><?php esc_html_e( 'Generated sizes', 'erdo-image-optimizer' ); ?></td>
                    <td><?php echo esc_html( number_format_i18n( $erdo_sizes_n ) ); ?></td>
                </tr>
            </table>

            <?php if ( ERDO_Usage::is_ready() ) : ?>
                <?php $erdo_sources = ERDO_Usage::sources( $id ); ?>
                <h3 class="erdo-sec-t"><?php esc_html_e( 'Used on', 'erdo-image-optimizer' ); ?></h3>
                <?php if ( empty( $erdo_sources ) ) : ?>
                    <p class="erdo-muted erdo-small"><?php esc_html_e( 'Not referenced anywhere on the site.', 'erdo-image-optimizer' ); ?></p>
                <?php else : ?>
                    <ul class="erdo-usage">
                        <?php foreach ( array_slice( $erdo_sources, 0, 12 ) as $erdo_src ) : ?>
                            <li>
                                <?php if ( $erdo_src['url'] ) : ?>
                                    <a href="<?php echo esc_url( $erdo_src['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $erdo_src['label'] ); ?></a>
                                <?php else : ?>
                                    <?php echo esc_html( $erdo_src['label'] ); ?>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                        <?php if ( count( $erdo_sources ) > 12 ) : ?>
                            <li class="erdo-muted">
                                <?php
                                /* translators: %d: number of further places */
                                echo esc_html( sprintf( __( '…and %d more', 'erdo-image-optimizer' ), count( $erdo_sources ) - 12 ) );
                                ?>
                            </li>
                        <?php endif; ?>
                    </ul>
                <?php endif; ?>
            <?php endif; ?>

            <h3 class="erdo-sec-t"><?php esc_html_e( 'Actions', 'erdo-image-optimizer' ); ?></h3>
            <div class="erdo-dr-actions">
                <?php if ( $erdo_can_conv ) : ?>
                    <button type="button" class="button webp-convert-btn" data-id="<?php echo esc_attr( $id ); ?>"><span class="dashicons dashicons-update" aria-hidden="true"></span><?php esc_html_e( 'Create WebP/AVIF copy', 'erdo-image-optimizer' ); ?></button>
                <?php endif; ?>
                <?php if ( $erdo_best ) : ?>
                    <button type="button" class="button erdo-restore-btn" data-id="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Remove copies', 'erdo-image-optimizer' ); ?></button>
                <?php endif; ?>
                <button type="button" class="button erdo-delete-btn" data-id="<?php echo esc_attr( $id ); ?>"><span class="dashicons dashicons-archive" aria-hidden="true"></span><?php esc_html_e( 'Quarantine', 'erdo-image-optimizer' ); ?></button>
            </div>

            <h3 class="erdo-sec-t"><?php esc_html_e( 'Resize', 'erdo-image-optimizer' ); ?></h3>
            <div class="erdo-resize">
                <label class="screen-reader-text" for="erdo-w-<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Width', 'erdo-image-optimizer' ); ?></label>
                <input type="number" id="erdo-w-<?php echo esc_attr( $id ); ?>" value="<?php echo esc_attr( (int) ( $erdo_dims['width'] ?? 0 ) ); ?>" min="1" class="small-text">
                <span aria-hidden="true">×</span>
                <label class="screen-reader-text" for="erdo-h-<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Height', 'erdo-image-optimizer' ); ?></label>
                <input type="number" id="erdo-h-<?php echo esc_attr( $id ); ?>" value="<?php echo esc_attr( (int) ( $erdo_dims['height'] ?? 0 ) ); ?>" min="1" class="small-text">
                <button type="button" class="button erdo-resize-btn" data-id="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Resize', 'erdo-image-optimizer' ); ?></button>
            </div>
            <p class="erdo-muted erdo-small"><?php esc_html_e( 'Only makes the image smaller. The original is kept so you can undo it.', 'erdo-image-optimizer' ); ?></p>
            <?php if ( $erdo_resize ) : ?>
                <button type="button" class="button-link erdo-restore-size-btn" data-id="<?php echo esc_attr( $id ); ?>">
                    <?php
                    /* translators: 1: original width, 2: original height (pixels) */
                    echo esc_html( sprintf( __( 'Restore original size (%1$d×%2$d)', 'erdo-image-optimizer' ), $erdo_resize['width'], $erdo_resize['height'] ) );
                    ?>
                </button>
            <?php endif; ?>
        </div>

        <div class="erdo-dr-right">
            <?php $this->render_ai_box( $id ); ?>

            <div class="erdo-field">
                <label for="erdo-input-alt_text"><?php esc_html_e( 'Alt text', 'erdo-image-optimizer' ); ?></label>
                <textarea id="erdo-input-alt_text" name="alt_text" rows="2" placeholder="<?php esc_attr_e( 'Describe the image for people who can’t see it', 'erdo-image-optimizer' ); ?>"><?php echo esc_textarea( (string) ( $erdo_alt['value'] ?? '' ) ); ?></textarea>
                <div class="erdo-field-tools">
                    <?php if ( ! empty( $data['parent_title'] ) ) : ?>
                        <button type="button" class="button-link erdo-magic-btn" data-target="alt_text" data-source="parent"><?php esc_html_e( 'Use page title', 'erdo-image-optimizer' ); ?></button>
                    <?php endif; ?>
                    <button type="button" class="button-link erdo-magic-btn" data-target="alt_text" data-source="filename"><?php esc_html_e( 'From file name', 'erdo-image-optimizer' ); ?></button>
                </div>
                <?php if ( ! empty( $erdo_alt ) && ( empty( $erdo_alt['pass'] ) || $erdo_alt['points'] < $erdo_alt['weight'] ) ) : ?>
                    <p class="erdo-help"><?php echo esc_html( $erdo_alt['msg'] ); ?></p>
                <?php endif; ?>
                <?php if ( $erdo_focus ) : ?>
                    <p class="erdo-help">
                        <?php
                        /* translators: %s: focus keyword from the SEO plugin */
                        echo esc_html( sprintf( __( 'Page focus keyword: "%s". Use it only if it describes the image.', 'erdo-image-optimizer' ), $erdo_focus ) );
                        ?>
                    </p>
                <?php endif; ?>
                <label class="erdo-checkline">
                    <input type="hidden" name="decorative" value="0">
                    <input type="checkbox" name="decorative" value="1" <?php checked( ! empty( $erdo_alt['decorative'] ) ); ?>>
                    <?php esc_html_e( 'Decorative image (adds nothing to the content, empty alt is correct)', 'erdo-image-optimizer' ); ?>
                </label>
            </div>

            <div class="erdo-field">
                <label for="erdo-input-filename"><?php esc_html_e( 'File name', 'erdo-image-optimizer' ); ?></label>
                <div class="erdo-fn">
                    <input type="text" id="erdo-input-filename" name="filename" value="<?php echo esc_attr( $erdo_stem ); ?>" spellcheck="false">
                    <span>.<?php echo esc_html( strtolower( (string) $erdo_ext ) ); ?></span>
                </div>
                <p class="erdo-help" id="erdo-filename-preview" hidden><?php esc_html_e( 'Will be saved as:', 'erdo-image-optimizer' ); ?> <code id="erdo-filename-preview-val"></code></p>
                <p class="erdo-help"><?php esc_html_e( 'Renaming moves every size and copy, updates links on the site and redirects the old URL (301).', 'erdo-image-optimizer' ); ?></p>
                <?php if ( $erdo_last ) : ?>
                    <p class="erdo-help">
                        <?php
                        /* translators: %s: previous file name (without extension) */
                        echo esc_html( sprintf( __( 'Renamed from "%s".', 'erdo-image-optimizer' ), $erdo_last['from'] ) );
                        ?>
                        <button type="button" class="button-link erdo-undo-rename-btn" data-id="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Undo', 'erdo-image-optimizer' ); ?></button>
                    </p>
                <?php endif; ?>
            </div>

            <div class="erdo-field">
                <label for="erdo-input-title"><?php esc_html_e( 'Title', 'erdo-image-optimizer' ); ?></label>
                <div class="erdo-inline">
                    <input type="text" id="erdo-input-title" name="title" value="<?php echo esc_attr( (string) ( $erdo_rows['title']['value'] ?? '' ) ); ?>">
                    <button type="button" class="button erdo-magic-btn" data-target="title" data-source="filename"><?php esc_html_e( 'From file name', 'erdo-image-optimizer' ); ?></button>
                </div>
                <p class="erdo-help"><?php esc_html_e( 'Shown in the media library and as a tooltip. Not used for ranking.', 'erdo-image-optimizer' ); ?></p>
            </div>

            <h3 class="erdo-sec-t">
                <?php
                /* translators: %d: SEO score 0–100 */
                echo esc_html( sprintf( __( 'SEO score · %d / 100', 'erdo-image-optimizer' ), $erdo_score ) );
                ?>
            </h3>
            <ul class="erdo-checks">
                <?php foreach ( $data['checklist'] as $erdo_item ) : ?>
                    <?php
                    if ( empty( $erdo_item['weight'] ) ) {
                        continue; // başlık bilgi amaçlı, alanında gösteriliyor
                    }
                    $erdo_partial = $erdo_item['pass'] && $erdo_item['points'] < $erdo_item['weight'];
                    $erdo_state   = $erdo_item['pass'] ? ( $erdo_partial ? 'partial' : 'pass' ) : 'fail';
                    ?>
                    <li class="is-<?php echo esc_attr( $erdo_state ); ?>">
                        <span class="dashicons dashicons-<?php echo esc_attr( $erdo_icons[ $erdo_state ] ); ?>" aria-hidden="true"></span>
                        <div>
                            <?php echo esc_html( $erdo_item['label'] ); ?>
                            <small><?php echo esc_html( $erdo_item['msg'] ); ?></small>
                        </div>
                        <span class="erdo-pts"><?php echo esc_html( $erdo_item['points'] . '/' . $erdo_item['weight'] ); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

    <div class="erdo-dr-f">
        <span class="erdo-muted erdo-small" id="erdo-dr-status" role="status"></span>
        <span class="erdo-sp"></span>
        <button type="button" class="button" id="erdo-dr-cancel"><?php esc_html_e( 'Close', 'erdo-image-optimizer' ); ?></button>
        <button type="submit" class="button button-primary" id="erdo-save-btn"><?php esc_html_e( 'Save', 'erdo-image-optimizer' ); ?></button>
    </div>
</form>
