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

            add_filter('tinypress_scheduling_metabox_fields', [$this, 'add_scheduling_teaser_fields']);
            add_filter('tinypress_global_scheduling_fields', [$this, 'add_global_scheduling_teaser_fields']);
            add_filter('tinypress_dynamic_redirect_metabox_fields', [$this, 'add_dynamic_redirect_teaser_fields']);

            $teaser_nudge_fields = array(
                'enable_activation_pro_teaser',
                'activation_date_pro_teaser',
                'activation_time_pro_teaser',
                'expiration_click_limit_pro_teaser',
                'expired_redirect_url_use_global_pro_teaser',
                'expired_show_notice_use_global_pro_teaser',
                'expired_notice_title_use_global_pro_teaser',
                'expired_notice_message_use_global_pro_teaser',
                'expired_notice_cta_text_use_global_pro_teaser',
                'dynamic_redirect_enabled_pro_teaser',
            );

            foreach ($teaser_nudge_fields as $field_id) {
                add_action('WPDK_Settings/after_field/field_' . $field_id, [$this, 'render_pro_nudge_teaser_setting']);
            }

            add_action('WPDK_Settings/after_field/field_dynamic_redirect_rules_pro_teaser', [$this, 'render_pro_nudges_redirect_rules']);

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

    private function get_pro_nudge_html($additional_class = '')
    {
        $class = 'tinypress-pro-nudge-wrapper';

        if (! empty($additional_class)) {
            $class .= ' ' . sanitize_html_class($additional_class);
        }

        return '<div class="' . esc_attr($class) . '" style="margin-top:10px;">'
            . '<span class="pp-tooltips-library" data-toggle="tooltip">'
            . '<button type="button" class="tinypress-pro-nudge-btn" tabindex="-1">'
            . '<span class="dashicons dashicons-lock tinypress-pro-nudge-lock"></span>'
            . esc_html__('Pro Feature', 'tinypress')
            . '</button>'
            . '<span class="tinypress tooltip-text">'
            . esc_html__('This feature is available in PublishPress Shortlinks Pro.', 'tinypress')
            . '</span></span></div>';
    }

    private function render_pro_nudge($additional_class = '')
    {
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML built with esc_html__ calls in get_pro_nudge_html
        echo $this->get_pro_nudge_html($additional_class);
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
                                <th scope="col"><?php esc_html_e('Shortlink', 'tinypress'); ?></th>
                                <th scope="col"><?php esc_html_e('Target URL', 'tinypress'); ?></th>
                                <th scope="col"><?php esc_html_e('Status', 'tinypress'); ?></th>
                                <th scope="col"><?php esc_html_e('HTTP', 'tinypress'); ?></th>
                                <th scope="col"><?php esc_html_e('Redirects', 'tinypress'); ?></th>
                                <th scope="col"><?php esc_html_e('Final URL', 'tinypress'); ?></th>
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
                                        <th scope="col"><?php esc_html_e('Link', 'tinypress'); ?></th>
                                        <th scope="col" class="tinypress-col-clicks"><?php esc_html_e('Clicks', 'tinypress'); ?></th>
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

    public function add_scheduling_teaser_fields($fields)
    {
        $activation_fields = array(
            array(
                'id'         => 'enable_activation_pro_teaser',
                'type'       => 'switcher',
                'title'      => esc_html__('Enable Activation', 'tinypress'),
                'subtitle'   => esc_html__('Schedule when this shortlink should start working.', 'tinypress'),
                'label'      => esc_html__('When enabled, the shortlink remains inactive until its activation date and time.', 'tinypress'),
                'default'    => true,
                'attributes' => array('disabled' => true),
                'class'      => 'tinypress-pro-teaser-field',
            ),
            array(
                'id'         => 'activation_date_pro_teaser',
                'type'       => 'datetime',
                'title'      => esc_html__('Activation Date', 'tinypress'),
                'subtitle'   => esc_html__('Select the date when this shortlink should start working.', 'tinypress'),
                'desc'       => esc_html__('Leave empty to make the shortlink active immediately.', 'tinypress'),
                'class'      => 'tinypress-scheduled-expiration-field tinypress-pro-teaser-field',
                'attributes' => array('disabled' => true),
                'dependency' => array('enable_activation_pro_teaser', '==', '1'),
                'settings'   => array(
                    'dateFormat' => 'd-m-Y',
                    'enableTime' => false,
                    'allowInput' => false,
                ),
            ),
            array(
                'id'         => 'activation_time_pro_teaser',
                'type'       => 'datetime',
                'title'      => esc_html__('Activation Time', 'tinypress'),
                'subtitle'   => esc_html__('Select the time when this shortlink should start working.', 'tinypress'),
                'desc'       => esc_html__('Only used when an activation date is set. Leave empty to activate at the start of that date.', 'tinypress'),
                'class'      => 'tinypress-scheduled-expiration-field tinypress-scheduled-expiration-activation-time tinypress-pro-teaser-field',
                'attributes' => array('disabled' => true),
                'dependency' => array('enable_activation_pro_teaser', '==', '1'),
                'settings'   => array(
                    'noCalendar'      => true,
                    'enableTime'      => true,
                    'time_24hr'       => false,
                    'dateFormat'      => 'h:i K',
                    'allowInput'      => false,
                    'minuteIncrement' => 1,
                ),
            ),
        );
        $expiration_fields = array(
            array(
                'id'         => 'expiration_click_limit_pro_teaser',
                'type'       => 'number',
                'title'      => esc_html__('Expiration Click Limit', 'tinypress'),
                'subtitle'   => esc_html__('Expire this shortlink after a number of clicks.', 'tinypress'),
                'desc'       => esc_html__('Set to 0 for no click limit.', 'tinypress'),
                'default'    => 0,
                'attributes' => array(
                    'disabled' => true,
                    'min'      => 0,
                    'step'     => 1,
                ),
                'class'      => 'tinypress-scheduled-expiration-field tinypress-expiration-click-limit tinypress-pro-teaser-field',
            ),
            array(
                'id'         => 'expired_redirect_url_use_global_pro_teaser',
                'type'       => 'select',
                'title'      => esc_html__('Expired Redirect URL', 'tinypress'),
                'subtitle'   => esc_html__('Where should visitors be sent when they click an expired link?', 'tinypress'),
                'options'    => array(
                    '1'      => esc_html__('Use global settings', 'tinypress'),
                    'custom' => esc_html__('Custom URL', 'tinypress'),
                ),
                'default'    => 'custom',
                'attributes' => array('disabled' => true),
                'class'      => 'tinypress-global-mode-select tinypress-pro-teaser-field',
            ),
            array(
                'id'          => 'expired_redirect_url_pro_teaser',
                'type'        => 'text',
                'title'       => '',
                'desc'        => esc_html__('When a shortlink expires, visitors are redirected to this URL. If left empty, visitors are redirected to the homepage.', 'tinypress'),
                'placeholder' => esc_url(home_url('/')),
                'attributes'  => array('disabled' => true),
                'class'       => 'tinypress-global-controlled tinypress-pro-teaser-field',
            ),
            array(
                'id'         => 'expired_show_notice_use_global_pro_teaser',
                'type'       => 'select',
                'title'      => esc_html__('Show Expiration Notice', 'tinypress'),
                'subtitle'   => esc_html__('Display a custom notice page before redirecting expired links.', 'tinypress'),
                'options'    => array(
                    '1'       => esc_html__('Use global settings', 'tinypress'),
                    'enabled' => esc_html__('Enabled', 'tinypress'),
                    'disabled' => esc_html__('Disabled', 'tinypress'),
                ),
                'default'    => 'enabled',
                'attributes' => array('disabled' => true),
                'class'      => 'tinypress-global-mode-select tinypress-pro-teaser-field',
            ),
            array(
                'id'         => 'expired_notice_title_use_global_pro_teaser',
                'type'       => 'select',
                'title'      => esc_html__('Expiration Notice Title', 'tinypress'),
                'subtitle'   => esc_html__('The heading of the expiration notice page', 'tinypress'),
                'options'    => array(
                    '1'      => esc_html__('Use global settings', 'tinypress'),
                    'custom' => esc_html__('Custom text', 'tinypress'),
                ),
                'default'    => 'custom',
                'attributes' => array('disabled' => true),
                'class'      => 'tinypress-global-mode-select tinypress-pro-teaser-field',
            ),
            array(
                'id'         => 'expired_notice_title_pro_teaser',
                'type'       => 'text',
                'title'      => '',
                'value'      => esc_html__('This link has expired', 'tinypress'),
                'desc'       => esc_html__('This is the main heading visitors see when they click an expired link.', 'tinypress'),
                'attributes' => array('disabled' => true),
                'class'      => 'tinypress-global-controlled tinypress-pro-teaser-field',
            ),
            array(
                'id'         => 'expired_notice_message_use_global_pro_teaser',
                'type'       => 'select',
                'title'      => esc_html__('Expiration Notice Message', 'tinypress'),
                'subtitle'   => esc_html__('The message shown to visitors on the expiration notice page', 'tinypress'),
                'options'    => array(
                    '1'      => esc_html__('Use global settings', 'tinypress'),
                    'custom' => esc_html__('Custom text', 'tinypress'),
                ),
                'default'    => 'custom',
                'attributes' => array('disabled' => true),
                'class'      => 'tinypress-global-mode-select tinypress-pro-teaser-field',
            ),
            array(
                'id'         => 'expired_notice_message_pro_teaser',
                'type'       => 'textarea',
                'title'      => '',
                'value'      => esc_html__('You will be redirected shortly.', 'tinypress'),
                'desc'       => esc_html__('This is the body text explaining what happened and when visitors will be redirected.', 'tinypress'),
                'attributes' => array(
                    'disabled' => true,
                    'rows'     => 6,
                    'style'    => 'min-height:130px;',
                ),
                'class'      => 'tinypress-global-controlled tinypress-pro-teaser-field',
            ),
            array(
                'id'         => 'expired_notice_cta_text_use_global_pro_teaser',
                'type'       => 'select',
                'title'      => esc_html__('Expiration Notice Link Text', 'tinypress'),
                'subtitle'   => esc_html__('The text for the button visitors click to continue', 'tinypress'),
                'options'    => array(
                    '1'      => esc_html__('Use global settings', 'tinypress'),
                    'custom' => esc_html__('Custom text', 'tinypress'),
                ),
                'default'    => 'custom',
                'attributes' => array('disabled' => true),
                'class'      => 'tinypress-global-mode-select tinypress-pro-teaser-field',
            ),
            array(
                'id'         => 'expired_notice_cta_text_pro_teaser',
                'type'       => 'text',
                'title'      => '',
                'value'      => esc_html__('Click here if you are not redirected', 'tinypress'),
                'desc'       => esc_html__('This is the text displayed on the clickable button at the bottom of the notice page.', 'tinypress'),
                'attributes' => array('disabled' => true),
                'class'      => 'tinypress-global-controlled tinypress-pro-teaser-field',
            ),
        );
        $updated_fields    = array();

        foreach ($fields as $field) {
            $updated_fields[] = $field;

            if (! empty($field['id']) && 'expiration_time' === $field['id']) {
                $updated_fields = array_merge($updated_fields, $expiration_fields);
            }
        }

        return array_merge($updated_fields, $activation_fields);
    }

    public function add_global_scheduling_teaser_fields($fields)
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
        $fields[] = array(
            'id'         => 'dynamic_redirect_enabled_pro_teaser',
            'type'       => 'switcher',
            'title'      => esc_html__('Enable Dynamic Redirects', 'tinypress'),
            'subtitle'   => esc_html__('Send visitors to different destinations when they match an enabled rule.', 'tinypress'),
            'text_on'    => esc_html__('Enable', 'tinypress'),
            'text_off'   => esc_html__('Disable', 'tinypress'),
            'default'    => false,
            'text_width' => 100,
            'attributes' => array('disabled' => true),
            'class'      => 'tinypress-dynamic-redirect-enable tinypress-pro-teaser-field',
        );

        $fields[] = array(
            'id'         => 'dynamic_redirect_fallback_notice_pro_teaser',
            'type'       => 'content',
            'title'      => esc_html__('Fallback Destination', 'tinypress'),
            'content'    => '<p class="description">' . esc_html__('The Target URL in the General tab is used whenever no enabled rule matches.', 'tinypress') . '</p>',
            'class'      => 'tinypress-pro-teaser-field',
        );

        $fields[] = array(
            'id'           => 'dynamic_redirect_rules_pro_teaser',
            'type'         => 'repeater',
            'title'        => esc_html__('Redirect Rules', 'tinypress'),
            'subtitle'     => esc_html__('Rules are checked from top to bottom. The first matching rule is used.', 'tinypress'),
            'button_title' => '<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span> ' . esc_html__('Add Rule', 'tinypress'),
            'max'          => 100,
            'max_notice'   => esc_html__('A shortlink can contain up to 100 redirect rules.', 'tinypress'),
            'default'      => array(
                array(
                    'enabled'          => '1',
                    'name'             => esc_html__('Redirect Rule', 'tinypress'),
                    'destination_url'  => 'https://example.com/ng-mobile',
                    'country_mode'     => 'include',
                    'countries'        => array('NG'),
                    'devices'          => array('mobile'),
                    'referrer_mode'    => 'any',
                    'referrer_domains' => '',
                ),
            ),
            'class'        => 'tinypress-dynamic-redirect-rules tinypress-pro-teaser-field',
            'fields'       => array(
                array(
                    'id'         => 'enabled',
                    'type'       => 'switcher',
                    'title'      => esc_html__('Rule Status', 'tinypress'),
                    'text_on'    => esc_html__('Enable', 'tinypress'),
                    'text_off'   => esc_html__('Disable', 'tinypress'),
                    'default'    => true,
                    'text_width' => 100,
                    'attributes' => array('disabled' => true),
                ),
                array(
                    'id'          => 'name',
                    'type'        => 'text',
                    'title'       => esc_html__('Rule Name', 'tinypress'),
                    'placeholder' => esc_html__('Example: Redirect Rule 1', 'tinypress'),
                    'default'     => esc_html__('Redirect Rule', 'tinypress'),
                    'attributes'  => array('disabled' => true),
                ),
                array(
                    'id'          => 'destination_url',
                    'type'        => 'text',
                    'title'       => esc_html__('Destination URL', 'tinypress'),
                    'placeholder' => 'https://example.com/landing-page',
                    'desc'        => esc_html__('Use an HTTP or HTTPS URL. Empty, invalid, and self-referencing rules are saved as disabled.', 'tinypress'),
                    'default'     => 'https://example.com/ng-mobile',
                    'attributes'  => array('disabled' => true),
                ),
                array(
                    'id'         => 'country_mode',
                    'type'       => 'select',
                    'title'      => esc_html__('Countries', 'tinypress'),
                    'options'    => array(
                        'any'     => esc_html__('Any country', 'tinypress'),
                        'include' => esc_html__('Only selected countries', 'tinypress'),
                        // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Redirect rule option key, not a query parameter.
                        'exclude' => esc_html__('All except selected countries', 'tinypress'),
                    ),
                    'default'    => 'include',
                    'attributes' => array('disabled' => true),
                ),
                array(
                    'id'          => 'countries',
                    'type'        => 'select',
                    'title'       => esc_html__('Selected Countries', 'tinypress'),
                    'options'     => array(
                        'NG' => esc_html__('United Kingdom', 'tinypress'),
                        'US' => esc_html__('Nigeria', 'tinypress'),
                        'GB' => esc_html__('United States', 'tinypress'),
                    ),
                    'chosen'      => true,
                    'multiple'    => true,
                    'placeholder' => esc_html__('Select countries', 'tinypress'),
                    'default'     => array('NG'),
                    'attributes'  => array('disabled' => true),
                ),
                array(
                    'id'         => 'devices',
                    'type'       => 'checkbox',
                    'title'      => esc_html__('Devices', 'tinypress'),
                    'options'    => array(
                        'desktop' => esc_html__('Desktop', 'tinypress'),
                        'tablet'  => esc_html__('Tablet', 'tinypress'),
                        'mobile'  => esc_html__('Mobile', 'tinypress'),
                    ),
                    'inline'     => true,
                    'desc'       => esc_html__('Leave every option unchecked to match any device.', 'tinypress'),
                    'default'    => array('mobile'),
                    'attributes' => array('disabled' => true),
                ),
                array(
                    'id'         => 'referrer_mode',
                    'type'       => 'select',
                    'title'      => esc_html__('Referrer', 'tinypress'),
                    'options'    => array(
                        'any'     => esc_html__('Any referrer', 'tinypress'),
                        'direct'  => esc_html__('Direct or unknown only', 'tinypress'),
                        'include' => esc_html__('Only selected domains', 'tinypress'),
                        // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Redirect rule option key, not a query parameter.
                        'exclude' => esc_html__('All except selected domains', 'tinypress'),
                    ),
                    'default'    => 'any',
                    'attributes' => array('disabled' => true),
                ),
                array(
                    'id'          => 'referrer_domains',
                    'type'        => 'textarea',
                    'title'       => esc_html__('Referrer Domains', 'tinypress'),
                    'placeholder' => 'facebook.com, newsletter.example.com',
                    'desc'        => esc_html__('Enter domains separated by commas or new lines. Subdomains also match their parent domain.', 'tinypress'),
                    'attributes'  => array(
                        'disabled' => true,
                        'rows'     => 3,
                    ),
                ),
            ),
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

    public function render_pro_nudge_teaser_setting()
    {
        $this->render_pro_nudge('tinypress-pro-nudge-setting');
    }

    public function render_pro_nudges_redirect_rules()
    {
        $this->render_pro_nudge('tinypress-pro-nudge-setting');
        $this->render_pro_nudge('tinypress-pro-nudge-rule-heading');
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
