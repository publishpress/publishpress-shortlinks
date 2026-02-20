<?php

namespace PublishPress\Shortlinks;

/**
 * Class ShortlinksCoreAdmin
 *
 * @package publishpress-shortlinks
 */
class ShortlinksCoreAdmin
{
    function __construct()
    {
        if (is_admin()) {
            add_action('in_admin_header', [$this, 'tinypress_render_upgrade_notice']);

            add_action('admin_menu', [$this, 'tinypress_add_upgrade_menu_link'], 999);

            add_action('tinypress_admin_class_before_assets_register', [$this, 'tinypress_load_admin_core_assets']);
            add_action('tinypress_admin_class_after_styles_enqueue', [$this, 'tinypress_load_admin_core_styles']);

            add_action('WPDK_Settings/after_field/field_tinypress_role_create', [$this, 'render_pro_nudge_create']);
            add_action('WPDK_Settings/after_field/field_tinypress_role_analytics', [$this, 'render_pro_nudge_analytics']);
            add_action('WPDK_Settings/after_field/field_tinypress_role_edit', [$this, 'render_pro_nudge_settings']);
        }
    }

    function tinypress_load_admin_core_assets()
    {
        wp_register_style('tinypress-tooltip', TINYPRESS_PLUGIN_URL . 'assets/lib/tooltip/css/tooltip.min.css', array(), TINYPRESS_PLUGIN_VERSION, 'all');
        wp_register_script('tinypress-tooltip', TINYPRESS_PLUGIN_URL . 'assets/lib/tooltip/js/tooltip.min.js', array(), TINYPRESS_PLUGIN_VERSION, true);
        wp_register_style('tinypress-admin-core', TINYPRESS_PLUGIN_URL . 'includes-core/assets/css/core.css', array('tinypress-tooltip'), TINYPRESS_PLUGIN_VERSION, 'all');
    }

    function tinypress_load_admin_core_styles()
    {
        wp_enqueue_style('tinypress-admin-core');
        wp_enqueue_script('tinypress-tooltip');

        wp_add_inline_script('jquery', "
            jQuery(document).ready(function($) {
                $('#adminmenu a[href*=\"publishpress.com/shortlinks\"]').addClass('tinypress-upgrade-link');
            });
        ");
    }

    function tinypress_render_upgrade_notice()
    {
        $screen = get_current_screen();

        if (!$screen) {
            return;
        }

        $our_screens = [
            'edit-tinypress_link',
            'tinypress_link',
            'tinypress_link_page_settings',
            'tinypress_link_page_tinypress-logs',
            'edit-tinypress_link_category',
        ];

        $show = false;
        foreach ($our_screens as $our_screen) {
            if ($screen->id === $our_screen || $screen->base === $our_screen) {
                $show = true;
                break;
            }
        }

        if (!$show) {
            return;
        }

        $upgrade_url = defined('TINYPRESS_LINK_PRO') ? TINYPRESS_LINK_PRO : 'https://publishpress.com/shortlinks/';
        $message = esc_html__("You're using PublishPress Shortlinks Free. The Pro version has more features and support. ", 'tinypress');
        $button_text = esc_html__('Upgrade to Pro', 'tinypress');
        ?>
        <div class="tinypress-version-notice-bold-purple">
            <div class="tinypress-version-notice-bold-purple-message"><?php echo $message; ?></div>
            <div class="tinypress-version-notice-bold-purple-button">
                <a href="<?php echo esc_url($upgrade_url); ?>" target="_blank"><?php echo $button_text; ?></a>
            </div>
        </div>
        <?php
    }

    function tinypress_add_upgrade_menu_link()
    {
        $upgrade_url = defined('TINYPRESS_LINK_PRO') ? TINYPRESS_LINK_PRO : 'https://publishpress.com/shortlinks/';

        add_submenu_page(
            'edit.php?post_type=tinypress_link',
            esc_html__('Upgrade to Pro', 'tinypress'),
            esc_html__('Upgrade to Pro', 'tinypress'),
            'manage_options',
            $upgrade_url
        );
    }

    private function render_pro_nudge()
    {
        ?>
        <div class="tinypress-pro-nudge-wrapper">
            <span class="pp-tooltips-library" data-toggle="tooltip">
                <button type="button" class="tinypress-pro-nudge-btn" tabindex="-1">
                    <span class="dashicons dashicons-lock tinypress-pro-nudge-lock"></span>
                    <?php echo esc_html__('Pro Feature', 'tinypress'); ?>
                </button>
                <span class="tinypress tooltip-text">
                    <?php echo esc_html('This feature is available in PublishPress Shortlinks Pro.', 'tinypress'); ?>
                </span>
            </span>
        </div>
        <?php
    }

    function render_pro_nudge_create()
    {
        $this->render_pro_nudge();
    }

    function render_pro_nudge_analytics()
    {
        $this->render_pro_nudge();
    }

    function render_pro_nudge_settings()
    {
        $this->render_pro_nudge();
    }

}
