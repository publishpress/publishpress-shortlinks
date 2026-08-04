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
            add_filter('tinypress_dynamic_redirect_metabox_fields', [$this, 'add_dynamic_redirect_teaser_fields']);

            add_filter('tinypress_autolink_metabox_fields', [$this, 'add_autolink_metabox_teaser_fields']);
            add_filter('tinypress_global_autolink_fields', [$this, 'add_global_autolink_teaser_fields']);
            add_filter('tinypress_autolink_exceptions_fields', [$this, 'add_autolink_exceptions_teaser_fields']);

            add_action('admin_menu', [$this, 'tinypress_add_link_checker_teaser_menu'], 25);
            add_action('admin_menu', [$this, 'tinypress_add_reports_teaser_menu'], 20);

            add_action('tinypress_admin_class_before_assets_register', [$this, 'tinypress_load_admin_core_assets']);
            add_action('tinypress_admin_class_after_styles_enqueue', [$this, 'tinypress_load_admin_core_styles']);

            add_action('WPDK_Settings/after_field/field_tinypress_role_create', [$this, 'render_pro_nudge_create']);
            add_action('WPDK_Settings/after_field/field_tinypress_role_analytics', [$this, 'render_pro_nudge_analytics']);
            add_action('WPDK_Settings/after_field/field_tinypress_role_edit', [$this, 'render_pro_nudge_settings']);
        }
    }

    public function tinypress_load_admin_core_assets()
    {
        wp_register_style('tinypress-tooltip', TINYPRESS_PLUGIN_URL . 'assets/lib/tooltip/css/tooltip.min.css', array(), tinypress_asset_version('assets/lib/tooltip/css/tooltip.min.css'), 'all');
        wp_register_script('tinypress-tooltip', TINYPRESS_PLUGIN_URL . 'assets/lib/tooltip/js/tooltip.min.js', array(), tinypress_asset_version('assets/lib/tooltip/js/tooltip.min.js'), true);
        wp_register_style('tinypress-admin-core', TINYPRESS_PLUGIN_URL . 'includes-core/assets/css/core.css', array('tinypress-tooltip'), tinypress_asset_version('includes-core/assets/css/core.css'), 'all');
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
            'tinypress_link_page_tinypress-reports',
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
            'tinypress_view_shortlink_analytics',
            'tinypress-link-checker',
            [$this, 'render_link_checker_teaser_page']
        );
    }

    public function tinypress_add_reports_teaser_menu()
    {
        add_submenu_page(
            'edit.php?post_type=tinypress_link',
            esc_html__('Reports', 'tinypress'),
            esc_html__('Reports', 'tinypress'),
            'tinypress_view_shortlink_analytics',
            'tinypress-reports',
            [$this, 'render_reports_teaser_page']
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

    public function render_link_checker_teaser_page()
    {
        ?>
        <div class="wrap tinypress-link-checker-teaser-wrap">
            <h1><?php esc_html_e('Link Health', 'tinypress'); ?></h1>
            <p class="description">
                <?php esc_html_e('Check whether your shortlinks redirect visitors to working destination pages.', 'tinypress'); ?>
            </p>

            <div style="position:relative;max-width:1100px;margin-top:16px;">
                <div style="opacity:0.55;pointer-events:none;">
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

                    <table class="widefat striped">
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
                <div style="position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);z-index:2;">
                    <?php $this->render_pro_nudge(); ?>
                </div>
            </div>
        </div>
        <?php
    }

    public function render_reports_teaser_page()
    {
        ?>
        <div class="wrap tinypress-reports-teaser-wrap">
            <h1><?php esc_html_e('Reports', 'tinypress'); ?></h1>
            <p class="description">
                <?php esc_html_e('Get aggregated insights into your shortlink performance with detailed analytics reports.', 'tinypress'); ?>
            </p>

            <div class="tinypress-reports-teaser-wrapper">
                <div class="tinypress-reports-teaser-content">
                    <div class="tinypress-reports-teaser-filter">
                        <label><?php esc_html_e('Date Range:', 'tinypress'); ?></label>
                        <select disabled>
                            <option><?php esc_html_e('Last 7 Days', 'tinypress'); ?></option>
                        </select>
                        <button type="button" class="button" disabled><?php esc_html_e('Apply', 'tinypress'); ?></button>
                    </div>

                    <div class="tinypress-reports-teaser-cards">
                        <div class="tinypress-reports-teaser-card">
                            <span class="dashicons dashicons-chart-bar"></span>
                            <div>
                                <div class="tinypress-reports-teaser-card-value">1,247</div>
                                <div class="tinypress-reports-teaser-card-label"><?php esc_html_e('Total Clicks', 'tinypress'); ?></div>
                            </div>
                        </div>
                        <div class="tinypress-reports-teaser-card">
                            <span class="dashicons dashicons-admin-users"></span>
                            <div>
                                <div class="tinypress-reports-teaser-card-value">892</div>
                                <div class="tinypress-reports-teaser-card-label"><?php esc_html_e('Unique Visitors', 'tinypress'); ?></div>
                            </div>
                        </div>
                        <div class="tinypress-reports-teaser-card">
                            <span class="dashicons dashicons-admin-links"></span>
                            <div>
                                <div class="tinypress-reports-teaser-card-value">34</div>
                                <div class="tinypress-reports-teaser-card-label"><?php esc_html_e('Active Links', 'tinypress'); ?></div>
                            </div>
                        </div>
                        <div class="tinypress-reports-teaser-card">
                            <span class="dashicons dashicons-performance"></span>
                            <div>
                                <div class="tinypress-reports-teaser-card-value">36.7</div>
                                <div class="tinypress-reports-teaser-card-label"><?php esc_html_e('Avg. Clicks/Link', 'tinypress'); ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="tinypress-reports-teaser-sections">
                        <div class="tinypress-reports-teaser-section">
                            <h2><?php esc_html_e('Top Performing Links', 'tinypress'); ?></h2>
                            <table class="widefat striped">
                                <thead>
                                    <tr>
                                        <th><?php esc_html_e('Link', 'tinypress'); ?></th>
                                        <th class="tinypress-col-clicks"><?php esc_html_e('Clicks', 'tinypress'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr><td>Summer Sale Campaign<br><small>summer-sale</small></td><td class="tinypress-col-clicks"><strong>342</strong></td></tr>
                                    <tr><td>Product Launch<br><small>new-product</small></td><td class="tinypress-col-clicks"><strong>287</strong></td></tr>
                                    <tr><td>Newsletter Signup<br><small>newsletter</small></td><td class="tinypress-col-clicks"><strong>198</strong></td></tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="tinypress-reports-teaser-section">
                            <h2><?php esc_html_e('Top Locations', 'tinypress'); ?></h2>
                            <ul class="tinypress-reports-teaser-locations">
                                <li><span>United States</span><span class="location-count">423</span></li>
                                <li><span>United Kingdom</span><span class="location-count">187</span></li>
                                <li><span>Germany</span><span class="location-count">134</span></li>
                            </ul>
                        </div>
                    </div>
                </div>
                <div style="position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);z-index:2;">
                    <?php $this->render_pro_nudge(); ?>
                </div>
            </div>
        </div>
        <?php
    }

    public function add_security_expired_teaser_fields($fields)
    {
        $nudge = $this->get_pro_nudge_html();

        $fields[] = array(
            'id'         => 'expired_redirect_pro_teaser',
            'type'       => 'content',
            'title'      => esc_html__('Scheduled Expiration Settings', 'tinypress'),
            'dependency' => array( 'enable_expiration', '==', '1' ),
            'content'    => '<div style="opacity:0.5;pointer-events:none;">'
                . '<p style="margin:0 0 8px;"><strong>' . esc_html__('Activation Date', 'tinypress') . '</strong></p>'
                . '<p style="margin:0 0 8px; font-style:italic; font-size:0.9em;">' . esc_html__('Choose when this shortlink should start working.', 'tinypress') . '</p>'
                . '<input type="text" disabled placeholder="' . esc_attr__('dd-mm-yyyy', 'tinypress') . '" style="width:100%;max-width:180px;" />'
                . '<p style="margin:12px 0 8px;"><strong>' . esc_html__('Activation Time', 'tinypress') . '</strong></p>'
                . '<p style="margin:0 0 8px; font-style:italic; font-size:0.9em;">' . esc_html__('Choose the time this shortlink should start working.', 'tinypress') . '</p>'
                . '<input type="text" disabled placeholder="' . esc_attr__('12:00 PM', 'tinypress') . '" style="width:100%;max-width:120px;" />'
                . '<p style="margin:12px 0 8px;"><strong>' . esc_html__('Expiration Click Limit', 'tinypress') . '</strong></p>'
                . '<p style="margin:0 0 8px; font-style:italic; font-size:0.9em;">' . esc_html__('Expire this shortlink after a number of clicks.', 'tinypress') . '</p>'
                . '<input type="number" disabled value="10" style="width:80px;" />'
                . '<p style="margin:12px 0 8px;"><strong>' . esc_html__('Expired Redirect URL', 'tinypress') . '</strong></p>'
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

    public function add_dynamic_redirect_teaser_fields($fields)
    {
        $nudge = $this->get_pro_nudge_html();

        $fields[] = array(
            'id'      => 'dynamic_redirect_pro_teaser',
            'type'    => 'content',
            'title'   => esc_html__('Conditional Redirect Rules', 'tinypress'),
            'content' => '<div style="opacity:0.5;pointer-events:none;">'
                . '<p style="margin:0 0 8px;"><strong>' . esc_html__('Enable Dynamic Redirects', 'tinypress') . '</strong></p>'
                . '<label style="display:inline-flex;align-items:center;gap:8px;margin-bottom:14px;">'
                . '<input type="checkbox" disabled />'
                . esc_html__('Send visitors to different destinations when they match a rule.', 'tinypress')
                . '</label>'
                . '<p style="margin:0 0 8px;"><strong>' . esc_html__('Redirect Rules', 'tinypress') . '</strong></p>'
                . '<div style="border:1px solid #c3c4c7;padding:12px;max-width:520px;background:#fff;">'
                . '<input type="text" disabled value="' . esc_attr__('Nigeria mobile visitors', 'tinypress') . '" style="width:100%;margin-bottom:8px;" />'
                . '<input type="url" disabled value="https://example.com/ng-mobile" style="width:100%;margin-bottom:8px;" />'
                . '<select disabled style="width:100%;"><option>' . esc_html__('Country and device conditions', 'tinypress') . '</option></select>'
                . '</div></div>' . $nudge,
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
