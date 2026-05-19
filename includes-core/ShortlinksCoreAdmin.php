<?php

namespace PublishPress\Shortlinks;

/**
 * Class ShortlinksCoreAdmin
 *
 * @package publishpress-shortlinks
 */
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WordPress naming conventions for hook callbacks
class ShortlinksCoreAdmin
{
    public function __construct()
    {
        if (is_admin()) {
            add_action('in_admin_header', [$this, 'tinypress_render_upgrade_notice']);

            add_action('admin_menu', [$this, 'tinypress_add_upgrade_menu_link'], 999);

            add_filter('tinypress_security_metabox_fields', [$this, 'add_security_expired_teaser_fields']);
            add_filter('tinypress_global_security_fields', [$this, 'add_global_security_expired_teaser_fields']);

            add_action('tinypress_admin_class_before_assets_register', [$this, 'tinypress_load_admin_core_assets']);
            add_action('tinypress_admin_class_after_styles_enqueue', [$this, 'tinypress_load_admin_core_styles']);

            add_action('WPDK_Settings/after_field/field_tinypress_role_create', [$this, 'render_pro_nudge_create']);
            add_action('WPDK_Settings/after_field/field_tinypress_role_analytics', [$this, 'render_pro_nudge_analytics']);
            add_action('WPDK_Settings/after_field/field_tinypress_role_edit', [$this, 'render_pro_nudge_settings']);
        }
    }

    public function tinypress_load_admin_core_assets()
    {
        wp_register_style('tinypress-tooltip', TINYPRESS_PLUGIN_URL . 'assets/lib/tooltip/css/tooltip.min.css', array(), TINYPRESS_PLUGIN_VERSION, 'all');
        wp_register_script('tinypress-tooltip', TINYPRESS_PLUGIN_URL . 'assets/lib/tooltip/js/tooltip.min.js', array(), TINYPRESS_PLUGIN_VERSION, true);
        wp_register_style('tinypress-admin-core', TINYPRESS_PLUGIN_URL . 'includes-core/assets/css/core.css', array('tinypress-tooltip'), TINYPRESS_PLUGIN_VERSION, 'all');
    }

    public function tinypress_load_admin_core_styles()
    {
        wp_enqueue_style('tinypress-admin-core');
        wp_enqueue_script('tinypress-tooltip');

        wp_add_inline_script('jquery', "
            jQuery(document).ready(function($) {
                $('#adminmenu a[href*=\"publishpress.com/links/shortlinks-menu\"]').addClass('tinypress-upgrade-link');
            });
        ");
    }

    public function tinypress_render_upgrade_notice()
    {
        $screen = get_current_screen();

        if (! $screen) {
            return;
        }

        $our_screens = [
            'edit-tinypress_link',
            'tinypress_link',
            'tinypress_link_page_settings',
            'tinypress_link_page_tinypress-logs',
            'edit-tinypress_link_category',
            'tinypress_link_page_tinypress-import-export',
        ];

        $show = false;
        foreach ($our_screens as $our_screen) {
            if ($screen->id === $our_screen || $screen->base === $our_screen) {
                $show = true;
                break;
            }
        }

        if (! $show) {
            return;
        }

        $upgrade_url = defined('TINYPRESS_LINK_PRO_BANNER') ? TINYPRESS_LINK_PRO_BANNER : 'https://publishpress.com/links/shortlinks-banner';
        $message = esc_html__("You're using PublishPress Shortlinks Free. The Pro version has more features and support. ", 'tinypress');
        $button_text = esc_html__('Upgrade to Pro', 'tinypress');
        ?>
        <div class="tinypress-version-notice-bold-purple">
            <div class="tinypress-version-notice-bold-purple-message"><?php echo wp_kses_post($message); ?></div>
            <div class="tinypress-version-notice-bold-purple-button">
                <a href="<?php echo esc_url($upgrade_url); ?>" target="_blank"><?php echo esc_html($button_text); ?></a>
            </div>
        </div>
        <?php
    }

    public function tinypress_add_upgrade_menu_link()
    {
        $upgrade_url = defined('TINYPRESS_LINK_PRO_MENU') ? TINYPRESS_LINK_PRO_MENU : 'https://publishpress.com/links/shortlinks-menu';

        add_submenu_page(
            'edit.php?post_type=tinypress_link',
            esc_html__('Upgrade to Pro', 'tinypress'),
            esc_html__('Upgrade to Pro', 'tinypress'),
            'manage_options',
            $upgrade_url
        );
    }

    private function get_pro_nudge_html()
    {
        return '<div class="tinypress-pro-nudge-wrapper" style="margin-top:10px;">'
            . '<span class="pp-tooltips-library" data-toggle="tooltip">'
            . '<button type="button" class="tinypress-pro-nudge-btn" tabindex="-1">'
            . '<span class="dashicons dashicons-lock tinypress-pro-nudge-lock"></span>'
            . esc_html__('Pro Feature', 'tinypress')
            . '</button>'
            . '<span class="tinypress tooltip-text">'
            . esc_html__('This feature is available in PublishPress Shortlinks Pro.', 'tinypress')
            . '</span></span></div>';
    }

    private function render_pro_nudge()
    {
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML built with esc_html__ calls in get_pro_nudge_html
        echo $this->get_pro_nudge_html();
    }

    public function add_security_expired_teaser_fields($fields)
    {
        $nudge = $this->get_pro_nudge_html();

        $fields[] = array(
            'id'         => 'expired_redirect_pro_teaser',
            'type'       => 'content',
            'title'      => esc_html__('Expired Redirect Settings', 'tinypress'),
            'dependency' => array( 'enable_expiration', '==', '1' ),
            'content'    => '<div style="opacity:0.5;pointer-events:none;">'
                . '<p style="margin:0 0 8px;"><strong>' . esc_html__('Expired Redirect URL', 'tinypress') . '</strong></p>'
                . '<input type="text" disabled placeholder="' . esc_attr(home_url('/')) . '" style="width:100%;max-width:400px;" />'
                . '<p style="margin:12px 0 8px;"><strong>' . esc_html__('Show Expiration Notice', 'tinypress') . '</strong></p>'
                . '<label style="display:inline-flex;align-items:center;gap:8px;">'
                . '<input type="checkbox" disabled />'
                . esc_html__('Display a brief notice before redirecting.', 'tinypress')
                . '</label></div>' . $nudge,
        );

        return $fields;
    }

    public function add_global_security_expired_teaser_fields($fields)
    {
        $nudge = $this->get_pro_nudge_html();

        $fields[] = array(
            'id'         => 'tinypress_global_expired_redirect_pro_teaser',
            'type'       => 'content',
            'title'      => esc_html__('Expired Redirect Settings', 'tinypress'),
            'dependency' => array( 'tinypress_global_enable_expiration', '==', '1' ),
            'content'    => '<div style="opacity:0.5;pointer-events:none;">'
                . '<p style="margin:0 0 8px;"><strong>' . esc_html__('Expired Redirect URL', 'tinypress') . '</strong></p>'
                . '<input type="text" disabled placeholder="' . esc_attr(home_url('/')) . '" style="width:100%;max-width:400px;" />'
                . '<p style="margin:12px 0 8px;"><strong>' . esc_html__('Show Expiration Notice', 'tinypress') . '</strong></p>'
                . '<label style="display:inline-flex;align-items:center;gap:8px;">'
                . '<input type="checkbox" disabled />'
                . esc_html__('Display a brief notice before redirecting.', 'tinypress')
                . '</label></div>' . $nudge,
        );

        return $fields;
    }

    public function render_pro_nudge_create()
    {
        $this->render_pro_nudge();
    }

    public function render_pro_nudge_analytics()
    {
        $this->render_pro_nudge();
    }

    public function render_pro_nudge_settings()
    {
        $this->render_pro_nudge();
    }
}
