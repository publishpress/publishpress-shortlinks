<?php
/**
 * New link Modal
 */

$default_string = tinypress_create_url_slug();
$tiny_slug_args = array(
	'default'       => $default_string,
	'wrapper_class' => 'tinypress-modal-link',
	'preview'       => true,
	'preview_text'  => esc_html__( 'Short url will come here', 'tinypress' ),
);

?>

<div class="tinypress-popup">
    <form class="tinypress-popup-box" action="" method="post">
        <div class="popup-content">
            <div class="tinypress-logo">
                <a target="_blank" href="<?php echo TINYPRESS_LINK_PRO . '?ref=' . site_url(); ?>"><img src="<?php echo esc_url( TINYPRESS_PLUGIN_URL . 'assets/images/publishpress-shortlinks.svg' ); ?>" alt="<?php esc_attr_e( 'tinyPress logo', 'tinypress' ); ?>"></a>
            </div>
            <label for="tinypress-modal-url"><?php esc_html_e( 'Enter a long url and make into a tiny version', 'tinypress' ) ?>
                <input autocomplete="off" id="tinypress-modal-url" name="long_url" type="url" required class="tinypress-modal-url" placeholder="<?php echo esc_url( 'https://example.com/my-long-url/' ); ?>">
            </label>
            <div class="response-area">
                <div class="response-item">
                    <div class="item-label"><?php esc_html_e( 'Long URL', 'tinypress' ) ?></div>
                    <div class="item-val long-url"></div>
                </div>
                <div class="response-item">
                    <div class="item-label"><?php esc_html_e( 'Short URL', 'tinypress' ) ?></div>
                    <div class="item-val"><?php echo tinypress_get_tiny_slug_copier( 0, false, $tiny_slug_args ); ?></div>
                </div>
            </div>
        </div>
        <div class="popup-actions">
            <input type="hidden" name="tiny_slug" value="<?php echo esc_attr( $default_string ); ?>">
            <div class="popup-action popup-action-cancel"><?php esc_html_e( 'Close', 'tinypress' ) ?></div>
            <button type="submit" class="popup-action popup-action-create"><?php esc_html_e( 'Create Short URL', 'tinypress' ) ?></button>
        </div>
    </form>
</div>
