<?php

/**
 * Settings class
 *
 * @author Pluginbazar
 */

use WPDK\Utils;

defined('ABSPATH') || exit;

if (! class_exists('TINYPRESS_Settings')) {
    /**
     * Class TINYPRESS_Settings
     * Note: Uses WordPress naming conventions (TINYPRESS_ prefix, snake_case methods)
     * for backwards compatibility and WordPress plugin ecosystem standards.
     */
    // phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace, Squiz.Classes.ValidClassName.NotCamelCaps, PSR1.Methods.CamelCapsMethodName.NotCamelCaps, PSR2.Classes.PropertyDeclaration.Underscore
    class TINYPRESS_Settings
    {
        protected static $_instance = null;

        /**
         * TINYPRESS_Settings constructor.
         */
        public function __construct()
        {
            add_action('plugins_loaded', array( $this, 'register_custom_statuses_filters' ), 1);
            
            add_action('init', array( $this, 'create_settings_page' ), 5);
            add_filter('pb_settings_tinypress_settings_save', array( $this, 'sanitize_autolist_settings' ), 10, 2);
            add_action('pb_settings_options_before', array( $this, 'add_settings_wrapper_start' ));
            add_action('pb_settings_options_after', array( $this, 'add_settings_wrapper_end' ));
            add_action('admin_notices', array( $this, 'shortlinks_elementor_prefix_notice' ));
        }

        public function register_custom_statuses_filters()
        {
            add_filter('pb_settings_tinypress_settings_sections', array( $this, 'inject_custom_statuses' ), 999, 1);
        }

        public function inject_custom_statuses($sections)
        {
            if (!class_exists('TINYPRESS_Statuses')) {
                return $sections;
            }
            
            $statuses_instance = TINYPRESS_Statuses::instance();
            if (!$statuses_instance || !method_exists($statuses_instance, 'get_custom_statuses')) {
                return $sections;
            }
            
            $custom_statuses = $statuses_instance->get_custom_statuses();
            
            if (empty($custom_statuses)) {
                return $sections;
            }

            foreach ($sections as $tab_key => $tab) {
                if (isset($tab['sections']) && is_array($tab['sections'])) {
                    foreach ($tab['sections'] as $section_key => $section) {
                        if (isset($section['fields']) && is_array($section['fields'])) {
                            foreach ($section['fields'] as $field_key => $field) {
                                if (isset($field['id']) && $field['id'] === 'tinypress_allowed_post_statuses') {
                                    foreach ($custom_statuses as $status_name => $status_obj) {
                                        $label = '';
                                        if (!empty($status_obj->label)) {
                                            $label = $status_obj->label;
                                        } elseif (!empty($status_obj->labels) && is_object($status_obj->labels) && !empty($status_obj->labels->name)) {
                                            $label = $status_obj->labels->name;
                                        } else {
                                            $label = ucfirst(str_replace(array('-', '_'), ' ', $status_name));
                                        }
                                        
                                        $sections[$tab_key]['sections'][$section_key]['fields'][$field_key]['options'][$status_name] = $label;
                                    }
                                    return $sections;
                                }
                            }
                        }
                    }
                }
                
                if (isset($tab['fields']) && is_array($tab['fields'])) {
                    foreach ($tab['fields'] as $field_key => $field) {
                        if (isset($field['id']) && $field['id'] === 'tinypress_allowed_post_statuses') {
                            foreach ($custom_statuses as $status_name => $status_obj) {
                                $label = '';
                                if (!empty($status_obj->label)) {
                                    $label = $status_obj->label;
                                } elseif (!empty($status_obj->labels) && is_object($status_obj->labels) && !empty($status_obj->labels->name)) {
                                    $label = $status_obj->labels->name;
                                } else {
                                    $label = ucfirst(str_replace(array('-', '_'), ' ', $status_name));
                                }
                                
                                $sections[$tab_key]['fields'][$field_key]['options'][$status_name] = $label;
                            }
                            return $sections;
                        }
                    }
                }
            }
            
            return $sections;
        }

        /**
         * Sanitize autolist settings to prevent duplicates and invalid post types
         *
         * @param array $request
         * @param array $args
         * @return array
         */
        public function sanitize_autolist_settings($request, $args)
        {
            if (isset($request['tinypress_allowed_post_statuses'])) {
                $allowed_statuses = $request['tinypress_allowed_post_statuses'];

                if (! is_array($allowed_statuses)) {
                    $allowed_statuses = array();
                }

                $allowed_statuses = array_values(array_filter(array_map('sanitize_key', $allowed_statuses)));

                $request['tinypress_allowed_post_statuses'] = $allowed_statuses;
            }

            if (! isset($request['tinypress_autolist_post_types'])) {
                return $request;
            }

            $post_types = $request['tinypress_autolist_post_types'];
            
            if (! is_array($post_types)) {
                return $request;
            }

            $all_post_types = get_post_types(array( 'public' => true ), 'names');
            $valid_post_types = array_diff($all_post_types, array( 'attachment', 'tinypress_link' ));
            
            // Track seen post types to prevent duplicates
            $seen = array();
            $sanitized = array();

            foreach ($post_types as $config) {
                if (! isset($config['post_type']) || empty($config['post_type']) || trim($config['post_type']) === '') {
                    continue;
                }

                $post_type = trim($config['post_type']);

                if (! in_array($post_type, $valid_post_types)) {
                    continue;
                }

                if (isset($seen[ $post_type ])) {
                    continue;
                }

                if (! isset($config['behavior']) || empty($config['behavior'])) {
                    $config['behavior'] = 'never';
                }

                $seen[ $post_type ] = true;
                $sanitized[] = array(
                    'post_type' => $post_type,
                    'behavior'  => $config['behavior'],
                );
            }

            $request['tinypress_autolist_post_types'] = $sanitized;

            return $request;
        }

        /**
         * Create settings page on init to ensure text domain is loaded
         */
        public function create_settings_page()
        {
            global $tinypress_wpdk;

            // Generate settings page
            $settings_args = array(
                'menu_title'      => esc_html__('Settings', 'tinypress'),
                'menu_slug'       => 'settings',
                'menu_type'       => 'submenu',
                'menu_parent'     => 'edit.php?post_type=tinypress_link',
                'menu_capability' => 'edit_posts',
                'database'        => 'option',
                'theme'           => 'light',
                'show_search'     => false,
                'pro_url'         => TINYPRESS_LINK_PRO_MENU,
            );

            WPDK_Settings::createSettingsPage('tinypress_settings', $settings_args, $this->get_settings_pages());
        }

        public function add_settings_wrapper_start()
        {
            echo '<div class="tinypress-settings-layout">';
        }

        public function add_settings_wrapper_end()
        {
            if (! class_exists('PublishPress_Shortlinks_Pro_Init')) {
                include TINYPRESS_PLUGIN_DIR . 'templates/admin/settings/supports.php';
            }
            echo '</div>';
        }

        /**
         * Check if Elementor is active
         *
         * @return bool
         */
        private function is_elementor_active()
        {
            return defined('ELEMENTOR_VERSION');
        }

        /**
         * Check if PublishPress Statuses is active
         *
         * @return bool
         */
        private function is_publishpress_statuses_active()
        {
            return defined('PUBLISHPRESS_STATUSES_VERSION') && class_exists('PublishPress_Statuses');
        }

        private function is_publishpress_statuses_enabled_for_shortlinks()
        {
            if (! $this->is_publishpress_statuses_active()) {
                return false;
            }

            $shortlinks_post_types = array('tinypress_link', 'shortlinks');

            if (! method_exists('PublishPress_Statuses', 'getEnabledPostTypes')) {
                $pp_options = get_option('publishpress_custom_status_options');
                if (is_object($pp_options) && ! empty($pp_options->post_types) && is_array($pp_options->post_types)) {
                    foreach ($shortlinks_post_types as $post_type) {
                        if (! empty($pp_options->post_types[$post_type])) {
                            return true;
                        }
                    }
                }

                return false;
            }

            $enabled_post_types = PublishPress_Statuses::getEnabledPostTypes();
            if (! is_array($enabled_post_types)) {
                return false;
            }

            foreach ($shortlinks_post_types as $post_type) {
                if (in_array($post_type, $enabled_post_types, true)) {
                    return true;
                }
            }

            return false;
        }

        public function get_allowed_post_statuses_field_description()
        {
            $desc = esc_html__('Select which post statuses are accessible when visiting a PublishPress shortlink.', 'tinypress');

            if (! $this->is_publishpress_statuses_active()) {
                return $desc;
            }

            if ($this->is_publishpress_statuses_enabled_for_shortlinks()) {
                return $desc;
            }

            $pp_notice = sprintf(
                '<div class="notice notice-warning is-dismissible tinypress-pp-statuses-notice">
                    <p>
                        <strong>%s</strong> %s
                    </p>
                </div>',
                esc_html__('PublishPress Statuses Plugin Detected:', 'tinypress'),
                esc_html__('To use core PublishPress statuses with Shortlinks, Please enable the Shortlinks post type in PublishPress Statuses workflow settings.', 'tinypress')
            );

            return $desc . $pp_notice;
        }

        /**
         * Get field description for prefix setting (includes warning if Elementor is active)
         *
         * @return string
         */
        public function get_prefix_field_description()
        {

            if ($this->is_elementor_active()) {
                $elementor_notice = sprintf(
                    '<div style="background-color: #fff3cd; border-left: 4px solid #ffc107; padding: 8px 12px; margin-top: 8px; border-radius: 3px;">
                        <strong>%s</strong> %s
                    </div>',
                    esc_html__('Elementor Detected:', 'tinypress'),
                    esc_html__('Shortlink prefix is required for proper rendering of Elementor revisions. It is advisable to keep it enabled.', 'tinypress')
                );
                return $elementor_notice;
            }

            return '';
        }

        /**
         * Display admin notice if Elementor is active and user tries to disable prefix
         *
         * @return void
         */
        public function shortlinks_elementor_prefix_notice()
        {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin page detection; no state is changed.
            if (!is_admin() || empty($_GET['page']) || 'tinypress-settings' !== $_GET['page']) {
                return;
            }

            if (!$this->is_elementor_active()) {
                return;
            }

            $prefix_enabled = Utils::get_option('tinypress_link_prefix');
            if ('1' === $prefix_enabled) {
                return;
            }

            ?>
            <div class="notice notice-warning is-dismissible">
                <p>
                    <strong><?php esc_html_e('TinyPress Shortlinks Notice:', 'tinypress'); ?></strong>
                    <?php esc_html_e('Elementor is active. Shortlink prefix has been automatically disabled, it is required for optimal compatibility with Elementor revisions.', 'tinypress'); ?>
                </p>
            </div>
            <?php
        }

        /**
         * Get all public post types for auto-list settings
         *
         * @return array
         */
        public function get_public_post_types_for_autolist()
        {
            $post_types = get_post_types(array( 'public' => true ), 'objects');
            $default_settings = array();

            foreach ($post_types as $post_type) {
                if (! in_array($post_type->name, array( 'attachment', 'tinypress_link' ))) {
                    $default_settings[] = array(
                        'post_type' => $post_type->name,
                        'behavior'  => 'never',
                    );
                }
            }

            return $default_settings;
        }

        /**
         * Get post type options for dropdown
         *
         * @return array
         */
        public function get_post_type_options()
        {
            $post_types = get_post_types(array( 'public' => true ), 'objects');
            $options = array();

            foreach ($post_types as $post_type) {
                if (! in_array($post_type->name, array( 'attachment', 'tinypress_link' ))) {
                    $options[ $post_type->name ] = $post_type->labels->singular_name . ' (' . $post_type->name . ')';
                }
            }

            return $options;
        }

        /**
         * Return settings pages
         *
         * @return mixed|void
         */
        public function get_settings_pages()
        {

            $user_roles = tinypress_get_roles();

            $post_type_options = $this->get_post_type_options();

            $field_sections['settings'] = array(
                'title'    => esc_html__('General', 'tinypress'),
                'sections' => array(
                    array(
                        'title'  => esc_html__('Options', 'tinypress'),
                        'fields' => array(
                            array(
                                'id'       => 'tinypress_link_prefix',
                                'type'     => 'switcher',
                                'title'    => esc_html__('Shortlink Prefix', 'tinypress'),
                                'label'    => esc_html__('Add a prefix between your domain name and shortlink.', 'tinypress'),
                                'default'  => true,
                                'desc'     => $this->get_prefix_field_description(),
                            ),
                            array(
                                'id'          => 'tinypress_link_prefix_slug',
                                'type'        => 'text',
                                'title'       => esc_html__('Prefix Slug', 'tinypress'),
                                'subtitle'    => esc_html__('Custom prefix slug.', 'tinypress'),
                                'desc'        => esc_html__('This text will be added between your domain name and shortlink.', 'tinypress'),
                                'placeholder' => esc_html__('go', 'tinypress'),
                                'default'     => esc_html__('go', 'tinypress'),
                                'dependency'  => array( 'tinypress_link_prefix', '==', '1' ),
                            ),
                            array(
                                'id'          => 'tinypress_kb_shortcut',
                                'type'        => 'text',
                                'title'       => esc_html__('Keyboard Shortcut', 'tinypress'),
                                'desc'        => esc_html__('Create shortlinks from anywhere inside your WordPress dashboard.', 'tinypress'),
                                'placeholder' => esc_html__('Ctrl or Cmd + /', 'tinypress'),
                                'default'     => esc_html__('Ctrl or Cmd + /', 'tinypress'),
                                'attributes'  => array(
                                    'disabled' => true,
                                ),
                                'dependency'  => array( 'tinypress_link_prefix', '==', '1' ),
                            ),
                            array(
                                'id'       => 'tinypress_hide_modal_opener',
                                'type'     => 'switcher',
                                'title'    => esc_html__('Remove from Admin Bar', 'tinypress'),
                                'label'    => esc_html__('Hide the "Shorten" button from the WordPress admin bar.', 'tinypress'),
                                'default'  => false,
                            ),
                        ),
                    ),
                    array(
                        'title'  => esc_html__('Role Management', 'tinypress'),
                        'fields' => apply_filters('tinypress_role_management_fields', array(
                            array(
                                'id'         => 'tinypress_role_view',
                                'type'       => 'checkbox',
                                'title'      => esc_html__('Who Can View Shortlinks', 'tinypress'),
                                'desc'       => esc_html__('Only selected user roles can view links.', 'tinypress'),
                                'inline'     => true,
                                'options'    => $user_roles,
                                'default'    => array( 'administrator', 'editor', 'author', 'contributor', 'subscriber', 'revisor' ),
                            ),
                            array(
                                'id'           => 'tinypress_role_create',
                                'type'         => 'checkbox',
                                'title'        => esc_html__('Who Can Create/Edit Shortlinks', 'tinypress'),
                                'desc'         => esc_html__('Only selected user roles can create or edit links.', 'tinypress'),
                                'inline'       => true,
                                'options'      => $user_roles,
                                'default'      => array( 'administrator', 'editor' ),
                                'attributes'   => array( 'disabled' => true ),
                            ),
                            array(
                                'id'           => 'tinypress_role_analytics',
                                'type'         => 'checkbox',
                                'title'        => esc_html__('Who Can See Analytics', 'tinypress'),
                                'desc'         => esc_html__('Only selected user roles can see analytics.', 'tinypress'),
                                'inline'       => true,
                                'options'      => $user_roles,
                                'default'      => array( 'administrator', 'editor' ),
                                'attributes'   => array( 'disabled' => true ),
                            ),
                            array(
                                'id'           => 'tinypress_role_edit',
                                'type'         => 'checkbox',
                                'title'        => esc_html__('Who Can Control Settings', 'tinypress'),
                                'desc'         => esc_html__('Only selected user roles can control settings.', 'tinypress'),
                                'inline'       => true,
                                'options'      => $user_roles,
                                'default'      => array( 'administrator', 'editor' ),
                                'attributes'   => array( 'disabled' => true ),
                            ),
                        ), $user_roles),
                    ),
                    array(
                        'title'  => esc_html__('Auto-Linking', 'tinypress'),
                        'fields' => array(
                            array(
                                'id'       => 'tinypress_autolink_enabled',
                                'type'     => 'switcher',
                                'title'    => esc_html__('Enable Auto-Linking', 'tinypress'),
                                'label'    => esc_html__('Automatically convert keywords to shortlinks in post content.', 'tinypress'),
                                'desc'     => esc_html__('When enabled, keywords configured in shortlink settings will be automatically linked in your content.', 'tinypress'),
                                'default'  => true,
                            ),
                            array(
                                'id'         => 'tinypress_autolink_target',
                                'type'       => 'select',
                                'title'      => esc_html__('Open Auto-Links In', 'tinypress'),
                                'subtitle'   => esc_html__('Choose how auto-linked keywords should open.', 'tinypress'),
                                'options'    => array(
                                    'same_tab' => esc_html__('Same tab', 'tinypress'),
                                    'new_tab'  => esc_html__('New tab', 'tinypress'),
                                ),
                                'default'    => 'same_tab',
                                'dependency' => array( 'tinypress_autolink_enabled', '==', '1' ),
                            ),
                            array(
                                'id'         => 'tinypress_autolink_color',
                                'type'       => 'color',
                                'title'      => esc_html__('Auto-Link Color', 'tinypress'),
                                'subtitle'   => esc_html__('Choose the color for auto-linked keywords.', 'tinypress'),
                                'default'    => '#3b11e4',
                                'dependency' => array( 'tinypress_autolink_enabled', '==', '1' ),
                            ),
                        ),
                    ),
                    array(
                        'title'  => esc_html__('Auto-List Links', 'tinypress'),
                        'fields' => array(
                            array(
                                'id'       => 'tinypress_autolist_enabled',
                                'type'     => 'switcher',
                                'title'    => esc_html__('Auto-List Shortlinks', 'tinypress'),
                                'label'    => esc_html__('When enabled, shortlinks will appear in the "All Links" table based on the behavior you configure below.', 'tinypress'),
                                'default'  => true,
                            ),
                            array(
                                'id'         => 'tinypress_autolist_post_types',
                                'type'       => 'callback',
                                'title'      => esc_html__('Configure Post Types', 'tinypress'),
                                'subtitle'   => esc_html__('Set when links should be auto-listed for each post type', 'tinypress'),
                                'desc'       => esc_html__('Add post types and configure when their shortlinks should appear in the "All Links" table. Changes are saved automatically.', 'tinypress'),
                                'dependency' => array( 'tinypress_autolist_enabled', '==', '1' ),
                                'function'   => array( $this, 'render_autolist_ajax_field' ),
                            ),
                        ),
                    ),
                    array(
                        'title'  => esc_html__('Post Status Visibility', 'tinypress'),
                        'fields' => array(
                            array(
                                'id'       => 'tinypress_allowed_post_statuses',
                                'type'     => 'checkbox',
                                'title'    => esc_html__('Allowed Post Statuses', 'tinypress'),
                                'subtitle' => esc_html__('Choose which post statuses can be accessed via shortlinks', 'tinypress'),
                                'desc'     => $this->get_allowed_post_statuses_field_description(),
                                'inline'   => true,
                                'options'  => array(
                                    'publish' => esc_html__('Published', 'tinypress'),
                                    'draft'   => esc_html__('Draft', 'tinypress'),
                                    'pending' => esc_html__('Pending Review', 'tinypress'),
                                    'private' => esc_html__('Private', 'tinypress'),
                                    'future'  => esc_html__('Scheduled', 'tinypress'),
                                ),
                                'default'  => array( 'publish', 'draft', 'pending', 'private', 'future' ),
                            ),
                        ),
                    ),
                    array(
                        'title'  => esc_html__('Expired Links', 'tinypress'),
                        'fields' => apply_filters('tinypress_expired_links_fields', array(
                            array(
                                'id'          => 'tinypress_expired_redirect_url',
                                'type'        => 'text',
                                'title'       => esc_html__('Default Expired Redirect URL', 'tinypress'),
                                'subtitle'    => esc_html__('Where should expired shortlinks redirect?', 'tinypress'),
                                'desc'        => esc_html__('When a shortlink expires, visitors will be redirected to this URL instead of seeing an error. Leave empty to show the default expiration message.', 'tinypress'),
                                'placeholder' => esc_html(home_url('/')),
                                'default'     => '',
                                'attributes'  => array( 'disabled' => true ),
                            ),
                        )),
                    ),
                ),
            );

            if (function_exists('rvy_in_revision_workflow')) {
                $field_sections['settings']['sections'][] = array(
                    'title'  => esc_html__('Revisions', 'tinypress'),
                    'fields' => array(
                        array(
                            'id'         => 'tinypress_revision_autolist',
                            'type'       => 'select',
                            'title'      => esc_html__('Revision Link Visibility', 'tinypress'),
                            'desc'       => esc_html__('When enabled, revision shortlinks will appear in the "All Shortlinks" table based on the behavior you configure below.', 'tinypress'),
                            'options'    => array(
                                'on_revision_creation'              => esc_html__('When Revision is Created', 'tinypress'),
                                'on_first_use'                      => esc_html__('When Link is First Used', 'tinypress'),
                                'on_revision_creation_or_first_use' => esc_html__('When Revision is Created or Link is First Used', 'tinypress'),
                                'never'                             => esc_html__('Never', 'tinypress'),
                            ),
                            'default'    => 'on_revision_creation_or_first_use',
                        ),
                        array(
                            'id'       => 'tinypress_revision_column_enabled',
                            'type'     => 'switcher',
                            'title'    => esc_html__('Show Shortlink in Revision Table', 'tinypress'),
                            'label'    => esc_html__('Display shortlink column when viewing revisions in PublishPress Revisions.', 'tinypress'),
                            'default'  => true,
                        ),
                        array(
                            'id'       => 'tinypress_revision_visitor_access',
                            'type'     => 'switcher',
                            'title'    => esc_html__('Revision Visibility for Visitors', 'tinypress'),
                            'label'    => esc_html__('Allow logged-out visitors to view revision content via shortlinks.', 'tinypress'),
                            'desc'     => esc_html__('By default, PublishPress Revisions blocks visitors from viewing revision previews. When enabled, revision shortlinks will render the revision content directly for logged-out visitors instead of redirecting to the preview URL.', 'tinypress'),
                            'default'  => true,
                        ),
                    ),
                );
            }

            // Hook for Pro to add additional settings sections (only if non-empty)
            $extra_general = apply_filters('tinypress_settings_after_general', array());
            if (! empty($extra_general) && ! empty($extra_general['title'])) {
                $field_sections['settings']['sections'][] = $extra_general;
            }

            $extra_post_status = apply_filters('tinypress_settings_after_post_status', array());
            if (! empty($extra_post_status) && ! empty($extra_post_status['title'])) {
                $field_sections['settings']['sections'][] = $extra_post_status;
            }

            // Dummy tab used to force WPDK to render the left sidebar navigation.
            if (count($field_sections) === 1 && isset($field_sections['settings'])) {
                $field_sections['dummy'] = array(
                    'title'    => esc_html__('Dummy', 'tinypress'),
                    'sections' => array(),
                );
            }

            return apply_filters('TINYPRESS/Filters/settings_pages', $field_sections);
        }

        /**
         * Render custom AJAX-powered autolist field
         */
        public function render_autolist_ajax_field()
        {
            $all_settings = get_option('tinypress_settings', array());
            $config = isset($all_settings['tinypress_autolist_post_types']) ? $all_settings['tinypress_autolist_post_types'] : array();
            
            if (empty($config)) {
                $config = array(
                    array(
                        'post_type' => 'post',
                        'behavior' => 'on_first_use_or_on_create'
                    ),
                    array(
                        'post_type' => 'page',
                        'behavior' => 'on_first_use_or_on_create'
                    )
                );
            }
            
            // Enqueue CSS
            wp_enqueue_style(
                'tinypress-autolist-ajax',
                TINYPRESS_PLUGIN_URL . 'assets/admin/css/autolist-ajax.css',
                array(),
                TINYPRESS_PLUGIN_VERSION
            );
            
            ?>
            <div class="tinypress-save-indicator"></div>
            <div class="tinypress-autolist-wrapper">
                <div class="tinypress-autolist-container">
                    <?php if (empty($config)) : ?>
                        <div class="tinypress-autolist-empty">
                            <span class="dashicons dashicons-admin-post"></span>
                            <p><?php esc_html_e('No post types configured yet. Click "Add Post Type" to get started.', 'tinypress'); ?></p>
                        </div>
                    <?php else : ?>
                        <?php foreach ($config as $index => $item) : ?>
                            <div class="tinypress-autolist-row" data-index="<?php echo esc_attr($index); ?>">
                                <div class="tinypress-autolist-handle">
                                    <span class="dashicons dashicons-menu"></span>
                                </div>
                                <div class="tinypress-autolist-field">
                                    <select class="tinypress-autolist-post-type" data-selected="<?php echo esc_attr($item['post_type']); ?>">
                                        <option value="<?php echo esc_attr($item['post_type']); ?>" selected><?php echo esc_html($item['post_type']); ?></option>
                                    </select>
                                </div>
                                <div class="tinypress-autolist-field">
                                    <select class="tinypress-autolist-behavior">
                                        <option value="never" <?php selected($item['behavior'], 'never'); ?>><?php esc_html_e('Never', 'tinypress'); ?></option>
                                        <option value="on_first_use_or_on_create" <?php selected($item['behavior'], 'on_first_use_or_on_create'); ?>><?php esc_html_e('When Link is First Used or Post Created', 'tinypress'); ?></option>
                                        <option value="on_first_use" <?php selected($item['behavior'], 'on_first_use'); ?>><?php esc_html_e('When Link is First Used', 'tinypress'); ?></option>
                                        <option value="on_create" <?php selected($item['behavior'], 'on_create'); ?>><?php esc_html_e('When Post is Created', 'tinypress'); ?></option>
                                        <option value="on_publish" <?php selected($item['behavior'], 'on_publish'); ?>><?php esc_html_e('When Post is Published', 'tinypress'); ?></option>
                                    </select>
                                </div>
                                <div class="tinypress-autolist-actions">
                                    <button type="button" class="button tinypress-autolist-remove">
                                        <span class="dashicons dashicons-trash"></span>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <button type="button" class="button button-primary tinypress-autolist-add">
                    <span class="dashicons dashicons-plus-alt"></span> <?php esc_html_e('Add Post Type', 'tinypress'); ?>
                </button>
            </div>
            <?php
        }

        /**
         * @return TINYPRESS_Settings
         */
        public static function instance()
        {
            if (is_null(self::$_instance)) {
                self::$_instance = new self();
            }

            return self::$_instance;
        }
    }
}
