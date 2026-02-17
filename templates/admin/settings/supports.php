<?php
/**
 * Admin Template: Supports
 */

defined( 'ABSPATH' ) || exit;

$upgrade_url = defined( 'TINYPRESS_LINK_PRO' ) ? TINYPRESS_LINK_PRO : 'https://publishpress.com/shortlinks/';
?>

<div class="tinypress-settings-sidebar">
    <?php if ( ! defined( 'TINYPRESS_PRO_VERSION' ) ) : ?>
    <div class="tinypress-support-sidebar" style="margin-bottom: 20px;">
        <div class="support-box-content postbox">
            <div class="postbox-header">
                <h3 class="support-box-header hndle is-non-sortable">
                    <span><?php echo esc_html__( 'Upgrade to PublishPress Shortlinks Pro', 'tinypress' ); ?></span>
                </h3>
            </div>

            <div class="inside">
                <p><?php echo esc_html__( 'Enhance the power of PublishPress Shortlinks with the Pro version:', 'tinypress' ); ?></p>
                <ul>
                    <li><?php echo esc_html__( 'REST API for creating shortlinks', 'tinypress' ); ?></li>
                    <li><?php echo esc_html__( 'Advanced click analytics', 'tinypress' ); ?></li>
                    <li><?php echo esc_html__( 'Role-based access control', 'tinypress' ); ?></li>
                    <li><?php echo esc_html__( 'Priority support', 'tinypress' ); ?></li>
                    <li><?php echo esc_html__( 'No ads inside the plugin', 'tinypress' ); ?></li>
                </ul>
                <a class="tinypress-support-link" href="<?php echo esc_url( $upgrade_url ); ?>" target="_blank">
                    <?php echo esc_html__( 'Upgrade to Pro', 'tinypress' ); ?>
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="tinypress-support-sidebar">
        <div class="support-box-content postbox">
            <div class="postbox-header">
                <h3 class="support-box-header hndle is-non-sortable">
                    <span><?php echo esc_html__( 'Need PublishPress Shortlinks Support?', 'tinypress' ); ?></span>
                </h3>
            </div>

            <div class="inside">
                <p><?php echo esc_html__( 'If you need help or have a new feature request, let us know.', 'tinypress' ); ?></p>
                <a class="support-link" href="https://wordpress.org/support/plugin/tinypress/" target="_blank">
                    <?php echo esc_html__( 'Request Support', 'tinypress' ); ?>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" class="linkIcon">
                        <path d="M18.2 17c0 .7-.6 1.2-1.2 1.2H7c-.7 0-1.2-.6-1.2-1.2V7c0-.7.6-1.2 1.2-1.2h3.2V4.2H7C5.5 4.2 4.2 5.5 4.2 7v10c0 1.5 1.2 2.8 2.8 2.8h10c1.5 0 2.8-1.2 2.8-2.8v-3.6h-1.5V17zM14.9 3v1.5h3.7l-6.4 6.4 1.1 1.1 6.4-6.4v3.7h1.5V3h-6.3z"></path>
                    </svg>
                </a>
                <p><?php echo esc_html__( 'Detailed documentation is also available on the plugin website.', 'tinypress' ); ?></p>
                <a class="support-link" href="https://publishpress.com/knowledge-base/introduction-shortlinks/" target="_blank">
                    <?php echo esc_html__( 'View Knowledge Base', 'tinypress' ); ?>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" class="linkIcon">
                        <path d="M18.2 17c0 .7-.6 1.2-1.2 1.2H7c-.7 0-1.2-.6-1.2-1.2V7c0-.7.6-1.2 1.2-1.2h3.2V4.2H7C5.5 4.2 4.2 5.5 4.2 7v10c0 1.5 1.2 2.8 2.8 2.8h10c1.5 0 2.8-1.2 2.8-2.8v-3.6h-1.5V17zM14.9 3v1.5h3.7l-6.4 6.4 1.1 1.1 6.4-6.4v3.7h1.5V3h-6.3z"></path>
                    </svg>
                </a>
            </div>
        </div>
    </div>
</div>
