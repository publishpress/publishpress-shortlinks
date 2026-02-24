<?php
/**
 * Admin: QR Code
 */

// Enqueue QR code script and pass data
wp_enqueue_script( 'tinypress-qr-code' );
wp_localize_script( 'tinypress-qr-code', 'tinypressQRCode', array(
	'url' => esc_url( tinypress_get_tinyurl() )
) );

?>
<div id="side-qr-code" class="side-qr-code">
    <div id="qr-code" class="qr-code"></div>
    <a class="qr-download" href=""><?php esc_html_e( 'Download QR Code', 'tinypress' ) ?></a>
</div>