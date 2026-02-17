<?php

namespace PublishPress\Shortlinks;

/**
 * Class ShortlinksCoreAdmin
 *
 * Free-only admin features: upgrade prompts, sidebar banners, menu links.
 * This class is loaded ONLY when Pro is NOT active (checked via TINYPRESS_PRO_VERSION).
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
        }
    }

    function tinypress_load_admin_core_assets()
    {
        wp_register_style('tinypress-admin-core', TINYPRESS_PLUGIN_URL . 'includes-core/assets/css/core.css', array(), TINYPRESS_PLUGIN_VERSION, 'all');
    }

    function tinypress_load_admin_core_styles()
    {
        wp_enqueue_style('tinypress-admin-core');

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

}
