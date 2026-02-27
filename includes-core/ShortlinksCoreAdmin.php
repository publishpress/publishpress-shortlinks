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

            add_action('admin_menu', [$this, 'tinypress_add_import_export_teaser'], 21);
            add_action('admin_enqueue_scripts', [$this, 'enqueue_teaser_assets']);

            add_action('WPDK_Settings/after_field/field_tinypress_expired_show_notice', [$this, 'render_pro_nudge_expired_links']);

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
            'tinypress_link_page_tinypress-import-export',
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

    function render_pro_nudge_expired_links()
    {
        ?>
        <script>
            jQuery(function($){
                // Dim the expired redirect URL input field
                $('input[name="tinypress_settings[tinypress_expired_redirect_url]"]').closest('.wpdk_settings-field').css('opacity','0.5');
            });
        </script>
        <div class="tinypress-pro-nudge-wrapper" style="margin-top: 10px;">
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

    /**
     * Enqueue teaser CSS on our pages.
     */
    function enqueue_teaser_assets()
    {
        $screen = get_current_screen();
        if (!$screen) {
            return;
        }

        if ($screen->id === 'tinypress_link_page_tinypress-import-export') {
            wp_enqueue_style(
                'tinypress-import-export-core',
                TINYPRESS_PLUGIN_URL . 'includes-core/assets/css/import-export-core.css',
                array(),
                TINYPRESS_PLUGIN_VERSION
            );
        }
    }

    /**
     * Add Import/Export teaser submenu for Free users.
     */
    function tinypress_add_import_export_teaser()
    {
        add_submenu_page(
            'edit.php?post_type=tinypress_link',
            esc_html__('Import / Export', 'tinypress'),
            esc_html__('Import / Export', 'tinypress'),
            'manage_options',
            'tinypress-import-export',
            [$this, 'render_import_export_teaser']
        );
    }

    /**
     * Render the Import/Export teaser page.
     */
    function render_import_export_teaser()
    {
        $upgrade_url = defined('TINYPRESS_LINK_PRO') ? TINYPRESS_LINK_PRO : 'https://publishpress.com/shortlinks/';
        ?>
        <div class="wrap tinypress-pro-teaser-wrap">
            <h1><?php esc_html_e('Import / Export Shortlinks', 'tinypress'); ?></h1>
            <div class="tinypress-teaser-layout">
                <div class="tinypress-teaser-main">
                    <div class="tinypress-pro-teaser">
                        <span class="dashicons dashicons-database-import"></span>
                        <h2><?php esc_html_e('Bulk Import & Export', 'tinypress'); ?></h2>
                        <p><?php esc_html_e('Import and export your shortlinks in bulk using CSV files. Migrate from other plugins or manage links across multiple sites.', 'tinypress'); ?></p>
                        <div class="tinypress-pro-features">
                            <div class="tinypress-pro-feature-item">
                                <span class="dashicons dashicons-yes"></span>
                                <?php esc_html_e('CSV Export', 'tinypress'); ?>
                            </div>
                            <div class="tinypress-pro-feature-item">
                                <span class="dashicons dashicons-yes"></span>
                                <?php esc_html_e('CSV Import', 'tinypress'); ?>
                            </div>
                            <div class="tinypress-pro-feature-item">
                                <span class="dashicons dashicons-yes"></span>
                                <?php esc_html_e('Bulk Management', 'tinypress'); ?>
                            </div>
                        </div>
                        <a href="<?php echo esc_url($upgrade_url); ?>" class="tinypress-teaser-upgrade-btn" target="_blank">
                            <?php esc_html_e('Upgrade to Pro', 'tinypress'); ?>
                        </a>
                    </div>
                </div>
                <div class="tinypress-teaser-sidebar">
                    <?php include TINYPRESS_PLUGIN_DIR . 'templates/admin/settings/supports.php'; ?>
                </div>
            </div>
        </div>
        <?php
    }

}
