<?php

/**
 * Admin: QR Code
 */

// Enqueue QR code script and pass data
wp_enqueue_script('tinypress-qr-code');
$tinypress_qr_url = tinypress_get_tinyurl();
wp_localize_script('tinypress-qr-code', 'tinypressQRCode', array(
    'url' => esc_url($tinypress_qr_url)
));

?>
<div class="side-qr-code" data-qr-url="<?php echo esc_url($tinypress_qr_url); ?>">
    <div class="qr-code" role="img" aria-label="<?php esc_attr_e('QR code for this shortlink', 'tinypress'); ?>" style="margin-bottom: 5px"></div>
    <a class="qr-download" href="#" aria-disabled="true" tabindex="-1"><?php esc_html_e('Download QR Code', 'tinypress') ?></a>
</div>
