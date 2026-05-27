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

            add_filter('tinypress_autolink_metabox_fields', [$this, 'add_autolink_metabox_teaser_fields']);
            add_filter('tinypress_global_autolink_fields', [$this, 'add_global_autolink_teaser_fields']);
            add_filter('tinypress_autolink_exceptions_fields', [$this, 'add_autolink_exceptions_teaser_fields']);

            add_action('admin_menu', [$this, 'tinypress_add_link_checker_teaser_menu'], 25);
            add_filter('TINYPRESS/Filters/link_columns', [$this, 'add_link_checker_teaser_column'], 20, 2);
            add_action('manage_tinypress_link_posts_custom_column', [$this, 'render_link_checker_teaser_column'], 10, 2);

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
            'tinypress_link_page_tinypress-link-checker',
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

    public function tinypress_add_link_checker_teaser_menu()
    {
        add_submenu_page(
            'edit.php?post_type=tinypress_link',
            esc_html__('Link Health', 'tinypress'),
            esc_html__('Link Health', 'tinypress'),
            'edit_posts',
            'tinypress-link-checker',
            [$this, 'render_link_checker_teaser_page']
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

    public function add_link_checker_teaser_column($columns)
    {
        $new_columns = array();

        foreach ($columns as $key => $label) {
            $new_columns[$key] = $label;

            if ('link-type' === $key) {
                $new_columns['link-health'] = esc_html__('Health', 'tinypress');
            }
        }

        if (! isset($new_columns['link-health'])) {
            $new_columns['link-health'] = esc_html__('Health', 'tinypress');
        }

        return $new_columns;
    }

    public function render_link_checker_teaser_column($column_id, $post_id)
    {
        if ('link-health' !== $column_id) {
            return;
        }

        echo '<div class="tinypress-link-checker-teaser-column">';
        echo '<span class="tinypress-link-checker-teaser-badge" style="display:inline-flex;align-items:center;gap:4px;background:#f0f0f1;color:#646970;padding:2px 8px;border-radius:3px;font-size:12px;font-weight:600;">';
        echo '<span class="dashicons dashicons-lock" style="font-size:14px;width:14px;height:14px;"></span>';
        echo esc_html__('Pro', 'tinypress');
        echo '</span>';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML built with esc_html__ calls in get_pro_nudge_html
        echo $this->get_pro_nudge_html();
        echo '</div>';
    }

    public function render_link_checker_teaser_page()
    {
        $upgrade_url = defined('TINYPRESS_LINK_PRO_MENU') ? TINYPRESS_LINK_PRO_MENU : 'https://publishpress.com/links/shortlinks-menu';
        ?>
        <div class="wrap tinypress-link-checker-teaser-wrap">
            <h1><?php esc_html_e('Link Health', 'tinypress'); ?></h1>
            <p class="description">
                <?php esc_html_e('Check whether your shortlinks redirect visitors to working destination pages.', 'tinypress'); ?>
            </p>

            <div style="opacity:0.55;pointer-events:none;margin-top:16px;">
                <p>
                    <button type="button" class="button button-primary">
                        <span class="dashicons dashicons-update"></span>
                        <?php esc_html_e('Check Visible Links', 'tinypress'); ?>
                    </button>
                    <button type="button" class="button">
                        <span class="dashicons dashicons-admin-site-alt3"></span>
                        <?php esc_html_e('Check All Links', 'tinypress'); ?>
                    </button>
                </p>

                <table class="widefat striped" style="max-width:1100px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Shortlink', 'tinypress'); ?></th>
                            <th><?php esc_html_e('Target URL', 'tinypress'); ?></th>
                            <th><?php esc_html_e('Status', 'tinypress'); ?></th>
                            <th><?php esc_html_e('HTTP', 'tinypress'); ?></th>
                            <th><?php esc_html_e('Redirects', 'tinypress'); ?></th>
                            <th><?php esc_html_e('Final URL', 'tinypress'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><?php echo esc_html(home_url('/go/example')); ?></td>
                            <td><?php echo esc_html('https://example.com/landing-page'); ?></td>
                            <td><span style="display:inline-block;background:#e7f5ec;color:#116329;padding:2px 8px;border-radius:3px;font-weight:600;"><?php esc_html_e('Working', 'tinypress'); ?></span></td>
                            <td>200</td>
                            <td>1</td>
                            <td><?php echo esc_html('https://example.com/landing-page'); ?></td>
                        </tr>
                        <tr>
                            <td><?php echo esc_html(home_url('/go/deleted-offer')); ?></td>
                            <td><?php echo esc_html('https://example.com/deleted-offer'); ?></td>
                            <td><span style="display:inline-block;background:#fcf0f1;color:#8a2424;padding:2px 8px;border-radius:3px;font-weight:600;"><?php esc_html_e('Broken', 'tinypress'); ?></span></td>
                            <td>404</td>
                            <td>1</td>
                            <td><?php echo esc_html('https://example.com/deleted-offer'); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <?php $this->render_pro_nudge(); ?>
            <p>
                <a href="<?php echo esc_url($upgrade_url); ?>" class="button button-primary" target="_blank" rel="noopener noreferrer">
                    <?php esc_html_e('Upgrade to Pro', 'tinypress'); ?>
                </a>
            </p>
        </div>
        <?php
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
                . '<p style="margin:0 0 8px; font-style:italic; font-size:0.9em;">' . esc_html__('Choose where visitors should go when they click an expired link.', 'tinypress') . '</p>'
                . '<input type="text" disabled placeholder="' . esc_attr(home_url('/')) . '" style="width:100%;max-width:400px;" />'
                . '<p style="margin:12px 0 8px;"><strong>' . esc_html__('Show Expiration Notice', 'tinypress') . '</strong></p>'
                . '<p style="margin:0 0 8px; font-style:italic; font-size:0.9em;">' . esc_html__('Display a custom notice page before automatically redirecting visitors.', 'tinypress') . '</p>'
                . '<label style="display:inline-flex;align-items:center;gap:8px;">'
                . '<input type="checkbox" disabled />'
                . esc_html__('Show a notice page for expired shortlinks briefly before redirecting.', 'tinypress')
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
                . '<p style="margin:0 0 8px; font-style:italic; font-size:0.9em;">' . esc_html__('Set the default destination for all expired shortlinks.', 'tinypress') . '</p>'
                . '<input type="text" disabled placeholder="' . esc_attr(home_url('/')) . '" style="width:100%;max-width:400px;" />'
                . '<p style="margin:12px 0 8px;"><strong>' . esc_html__('Show Expiration Notice', 'tinypress') . '</strong></p>'
                . '<p style="margin:0 0 8px; font-style:italic; font-size:0.9em;">' . esc_html__('Display a custom notice page before automatically redirecting visitors.', 'tinypress') . '</p>'
                . '<label style="display:inline-flex;align-items:center;gap:8px;">'
                . '<input type="checkbox" disabled />'
                . esc_html__('Show a notice page for expired shortlinks briefly before redirecting. You can customize the content of this message.', 'tinypress')
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

    /**
     * Add autolink teaser fields to per-link metabox for free version
     *
     * @param array $fields Existing autolink metabox fields.
     * @return array
     */
    public function add_autolink_metabox_teaser_fields($fields)
    {
        $nudge = $this->get_pro_nudge_html();

        $fields[] = array(
            'id'         => 'autolink_pro_teaser',
            'type'       => 'content',
            'title'      => esc_html__('Advanced Auto-Link Settings', 'tinypress'),
            'content'    => '<div style="opacity:0.5;pointer-events:none;">'
                . '<p style="margin:0 0 8px;"><strong>' . esc_html__('Minimum Keyword Usage', 'tinypress') . '</strong></p>'
                . '<p style="margin:0 0 8px; font-style:italic; font-size:0.9em;">' . esc_html__('Keyword must appear this many times before being autolinked.', 'tinypress') . '</p>'
                . '<input type="number" disabled value="1" style="width:80px;" />'
                . '<p style="margin:12px 0 8px;"><strong>' . esc_html__('Maximum Keywords Linked', 'tinypress') . '</strong></p>'
                . '<p style="margin:0 0 8px; font-style:italic; font-size:0.9em;">' . esc_html__('Maximum number of times this keyword should be autolinked per post.', 'tinypress') . '</p>'
                . '<input type="number" disabled value="0" style="width:80px;" />'
                . '</div>' . $nudge,
        );

        return $fields;
    }

    /**
     * Add autolink teaser fields to global settings for free version
     *
     * @param array $fields Existing global autolink fields.
     * @return array
     */
    public function add_global_autolink_teaser_fields($fields)
    {
        $nudge = $this->get_pro_nudge_html();

        $fields[] = array(
            'id'         => 'tinypress_global_autolink_pro_teaser',
            'type'       => 'content',
            'title'      => esc_html__('Advanced Auto-Link Settings', 'tinypress'),
            'dependency' => array('tinypress_autolink_enabled', '==', '1'),
            'content'    => '<div style="opacity:0.5;pointer-events:none;">'
                . '<p style="margin:0 0 8px;"><strong>' . esc_html__('Minimum Keyword Usage', 'tinypress') . '</strong></p>'
                . '<p style="margin:0 0 8px; font-style:italic; font-size:0.9em;">' . esc_html__('Default minimum times a keyword must appear before being autolinked.', 'tinypress') . '</p>'
                . '<input type="number" disabled value="1" style="width:80px;" />'
                . '<p style="margin:12px 0 8px;"><strong>' . esc_html__('Maximum Keywords Linked', 'tinypress') . '</strong></p>'
                . '<p style="margin:0 0 8px; font-style:italic; font-size:0.9em;">' . esc_html__('Default maximum times a keyword should be autolinked per post.', 'tinypress') . '</p>'
                . '<input type="number" disabled value="0" style="width:80px;" />'
                . '<p style="margin:12px 0 8px;"><strong>' . esc_html__('Maximum Links Per Post', 'tinypress') . '</strong></p>'
                . '<p style="margin:0 0 8px; font-style:italic; font-size:0.9em;">' . esc_html__('Total maximum autolinks allowed per post/page.', 'tinypress') . '</p>'
                . '<input type="number" disabled value="0" style="width:80px;" />'
                . '<p style="margin:12px 0 8px;"><strong>' . esc_html__('Minimum Character Length', 'tinypress') . '</strong></p>'
                . '<p style="margin:0 0 8px; font-style:italic; font-size:0.9em;">' . esc_html__('Keywords shorter than this will not be autolinked.', 'tinypress') . '</p>'
                . '<input type="number" disabled value="0" style="width:80px;" />'
                . '<p style="margin:12px 0 8px;"><strong>' . esc_html__('Maximum Character Length', 'tinypress') . '</strong></p>'
                . '<p style="margin:0 0 8px; font-style:italic; font-size:0.9em;">' . esc_html__('Keywords longer than this will not be autolinked.', 'tinypress') . '</p>'
                . '<input type="number" disabled value="0" style="width:80px;" />'
                . '</div>' . $nudge,
        );

        return $fields;
    }

    /**
     * Add autolink exceptions teaser fields for free version
     *
     * @param array $fields Existing fields.
     * @return array
     */
    public function add_autolink_exceptions_teaser_fields($fields)
    {
        $nudge = $this->get_pro_nudge_html();

        $fields[] = array(
            'id'      => 'tinypress_autolink_exceptions_teaser',
            'type'    => 'content',
            'title'   => esc_html__('Auto-Link Exceptions', 'tinypress'),
            'content' => '<div style="opacity:0.5;pointer-events:none;">'
                . '<p style="margin:0 0 12px;"><strong>' . esc_html__('Exclude Terms from Auto Links', 'tinypress') . '</strong></p>'
                . '<p style="margin:0 0 8px; font-style:italic; font-size:0.9em;">' . esc_html__('These terms will never be autolinked.', 'tinypress') . '</p>'
                . '<textarea disabled rows="2" style="width:100%;max-width:400px;" placeholder="WordPress, Website, Click here"></textarea>'
                . '<p style="margin:12px 0 8px;"><strong>' . esc_html__('Prevent Auto Links Inside Classes or IDs', 'tinypress') . '</strong></p>'
                . '<p style="margin:0 0 8px; font-style:italic; font-size:0.9em;">' . esc_html__('Content inside elements with these classes or IDs will not have autolinks applied.', 'tinypress') . '</p>'
                . '<textarea disabled rows="2" style="width:100%;max-width:400px;" placeholder=".notag, #main-header"></textarea>'
                . '<p style="margin:12px 0 8px;"><strong>' . esc_html__('Prevent Auto Links Inside Elements', 'tinypress') . '</strong></p>'
                . '<p style="margin:0 0 8px; font-style:italic; font-size:0.9em;">' . esc_html__('Terms inside these HTML tags will not have autolinks applied.', 'tinypress') . '</p>'
                . '<div style="display:flex;gap:10px;flex-wrap:wrap;">'
                . '<label><input type="checkbox" disabled> H1</label>'
                . '<label><input type="checkbox" disabled> H2</label>'
                . '<label><input type="checkbox" disabled> H3</label>'
                . '<label><input type="checkbox" disabled> H4</label>'
                . '<label><input type="checkbox" disabled> H5</label>'
                . '<label><input type="checkbox" disabled> H6</label>'
                . '<label><input type="checkbox" disabled checked> script</label>'
                . '<label><input type="checkbox" disabled checked> style</label>'
                . '<label><input type="checkbox" disabled checked> pre</label>'
                . '<label><input type="checkbox" disabled checked> code</label>'
                . '</div>'
                . '<p style="margin:12px 0 8px;"><strong>' . esc_html__('Prevent Auto Links on Shortcodes', 'tinypress') . '</strong></p>'
                . '<p style="margin:0 0 8px; font-style:italic; font-size:0.9em;">' . esc_html__('Terms inside these shortcodes will not have autolinks applied.', 'tinypress') . '</p>'
                . '<textarea disabled rows="2" style="width:100%;max-width:400px;" placeholder="read_more, gallery"></textarea>'
                . '<p style="margin:12px 0 8px;"><strong>' . esc_html__('Prevent Auto Links on Blocks', 'tinypress') . '</strong></p>'
                . '<p style="margin:0 0 8px; font-style:italic; font-size:0.9em;">' . esc_html__('Terms inside these Gutenberg blocks will not have autolinks applied.', 'tinypress') . '</p>'
                . '<select disabled style="width:100%;max-width:400px;"><option>' . esc_html__('Search and select blocks...', 'tinypress') . '</option></select>'
                . '</div>' . $nudge,
        );

        return $fields;
    }
}
