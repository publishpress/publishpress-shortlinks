<?php

/*
* @Author        pluginbazar
* Copyright:    2022 pluginbazar
*/

use WPDK\Utils;

defined('ABSPATH') || exit;

if (! class_exists('TINYPRESS_Meta_boxes')) {
    /**
     * Class TINYPRESS_Meta_boxes
     *
     * Note: This class uses WordPress naming conventions instead of strict PSR-1/PSR-2 standards.
     */
    // phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace, Squiz.Classes.ValidClassName.NotCamelCaps, PSR1.Methods.CamelCapsMethodName.NotCamelCaps, PSR2.Classes.PropertyDeclaration.Underscore
    class TINYPRESS_Meta_boxes
    {
        private $tinypress_metabox_main = 'tinypress_meta_main';
        private $tinypress_metabox_side = 'tinypress_meta_side';
        private $tinypress_default_slug;


        /**
         * TINYPRESS_Meta_boxes constructor.
         */
        public function __construct()
        {
            $this->tinypress_default_slug = tinypress_create_url_slug();
            $this->generate_tinypress_meta_box();
            foreach (get_post_types(array( 'public' => true )) as $post_type) {
                if (! in_array($post_type, array( 'attachment' ))) {
                    add_action('add_meta_boxes_' . $post_type, array( $this, 'add_shortlinks_metabox' ));

                    if ($post_type === 'tinypress_link') {
                        add_action('save_post_tinypress_link', array( $this, 'save_tinypress_link_metabox' ), 15, 2);
                    } else {
                        add_action('save_post_' . $post_type, array( $this, 'save_native_shortlinks_metabox' ), 10, 2);
                    }
                }
            }

            add_filter('pb_settings_tinypress_meta_main_save', array($this, 'ensure_autolink_keywords_saved'), 10, 3);

            add_action('add_meta_boxes', array( $this, 'add_side_meta_box' ), 0);
            add_action('WPDK_Settings/meta_section/analytics', array( $this, 'render_analytics' ));
        }

        /**
         * Render analytics section
         *
         * @return void
         */
        public function render_analytics()
        {
            if (! current_user_can('tinypress_view_shortlink_analytics')) {
                return;
            }

            include TINYPRESS_PLUGIN_DIR . 'templates/admin/analytics.php';
        }

        /**
         * WPDK filter hook to ensure autolink_keywords are synced to direct meta key.
         * This is a safety net in case the save hook doesn't catch it.
         *
         * @param array $value The meta data array from WPDK
         * @param int $object_id The post ID
         * @param string $meta_key The meta key being saved
         * @return array Modified meta data
         */
        public function ensure_autolink_keywords_saved($value, $object_id, $meta_key)
        {
            if ($meta_key !== 'tinypress_meta_side_tinypress_link') {
                return $value;
            }

            if (is_array($value) && isset($value['autolink_keywords'])) {
                $keywords = $value['autolink_keywords'];

                if (is_array($keywords)) {
                    $keywords = implode("\n", array_map('trim', array_filter($keywords)));
                }

                update_post_meta($object_id, 'autolink_keywords', $keywords);
            }

            return $value;
        }

        /**
         * Render Side Meta Box
         *
         * @return void
         */
        public function render_side_box()
        {
            echo '<div class="tinypress-meta-side">';
            include TINYPRESS_PLUGIN_DIR . 'templates/admin/qr-code.php';
            echo '</div>';
        }


        /**
         * Add Side Meta Box
         *
         * @return void
         */
        public function add_side_meta_box()
        {
            add_meta_box('tinypress-meta-side', esc_html__('Side', 'tinypress'), array( $this, 'render_side_box' ), 'tinypress_link', 'side', 'core');
        }


        /**
         * Add shortlinks metabox to post edit screen
         *
         * @return void
         */
        public function add_shortlinks_metabox()
        {
            global $post;

            if (! $post) {
                return;
            }

            add_meta_box('tinypress_shortlinks_' . $post->post_type, esc_html__('Shortlinks', 'tinypress'), array( $this, 'render_native_shortlinks_metabox' ), $post->post_type, 'side', 'high');
        }


        /**
         * Render native shortlinks metabox content
         *
         * @param $post
         * @return void
         */
        public function render_native_shortlinks_metabox($post)
        {
            wp_nonce_field('tinypress_shortlinks_nonce', 'tinypress_shortlinks_nonce_' . $post->post_type);

            $args = array(
                'default' => $this->tinypress_default_slug,
            );

            do_action('tinypress_metabox_before_shortlink_field', $post);

            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- tinypress_get_tiny_slug_copier() returns properly escaped HTML
            echo tinypress_get_tiny_slug_copier($post->ID, true, $args);

            // Hook for Pro to add content after shortlink field
            do_action('tinypress_metabox_after_shortlink_field', $post);
        }

        /**
         * Save native shortlinks metabox data (for non-tinypress_link post types only)
         *
         * @param $post_id
         * @param $post
         * @return void
         */
        public function save_native_shortlinks_metabox($post_id, $post)
        {
            if (
                ! isset($_POST['tinypress_shortlinks_nonce_' . $post->post_type]) ||
                 ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['tinypress_shortlinks_nonce_' . $post->post_type])), 'tinypress_shortlinks_nonce')
            ) {
                return;
            }

            if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
                return;
            }

            if (! current_user_can('edit_post', $post_id)) {
                return;
            }

            $meta_key = 'tinypress_meta_side_' . $post->post_type;
            if (isset($_POST[ $meta_key ]['tiny_slug'])) {
                $tiny_slug = sanitize_text_field($_POST[ $meta_key ]['tiny_slug']);

                update_post_meta($post_id, 'tiny_slug', $tiny_slug);

                $meta_data = get_post_meta($post_id, $meta_key, true);
                if (! is_array($meta_data)) {
                    $meta_data = array();
                }
                $meta_data['tiny_slug'] = $tiny_slug;
                update_post_meta($post_id, $meta_key, $meta_data);
            }
        }

        /**
         * Save tinypress_link post type metabox fields.
         *
         * @param int $post_id Post ID.
         * @param WP_Post $post Post object.
         * @return void
         */
        public function save_tinypress_link_metabox($post_id, $post)
        {
            if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
                return;
            }

            if (! current_user_can('edit_post', $post_id)) {
                return;
            }

            $meta_key = 'tinypress_meta_side_tinypress_link';
            $nested_data = get_post_meta($post_id, $meta_key, true);

            if (is_array($nested_data) && isset($nested_data['autolink_keywords'])) {
                $keywords = $nested_data['autolink_keywords'];

                if (is_array($keywords)) {
                    $keywords = implode("\n", array_map('trim', array_filter($keywords)));
                }

                update_post_meta($post_id, 'autolink_keywords', $keywords);
            }
        }



        /** Sanitize autolink keywords from textarea
         *
         * @param $value mixed The value from textarea
         * @return string Formatted keywords string
         */
        public function sanitize_autolink_keywords($value)
        {
            if (is_array($value)) {
                return implode("\n", array_map('trim', array_filter($value)));
            }
            return (string) $value;
        }

        /**
         * Format autolink keywords for display in textarea
         * Handles retrieval when meta value is stored as array from imports
         *
         * @param $value mixed The meta value
         * @param $post_id int The post ID
         * @return string Formatted keywords string
         */
        public function format_autolink_keywords_for_display($value, $post_id = null)
        {
            if (is_array($value)) {
                return implode("\n", array_map('trim', array_filter($value)));
            }
            return (string) $value;
        }

        /**
         * Render short URL field
         *
         * @param $args
         *
         * @return void
         */
        public function render_field_tinypress_link($args)
        {
            global $post;
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- tinypress_get_tiny_slug_copier() returns escaped HTML
            echo tinypress_get_tiny_slug_copier($post->ID, true, $args);
        }


        /**
         * Get global setting value
         *
         * @param string $key Setting key
         * @param mixed $default Default value
         * @return mixed
         */
        private function get_global_setting($key, $default = null)
        {
            $settings = get_option('tinypress_settings', array());
            if (is_array($settings) && array_key_exists($key, $settings)) {
                return $settings[$key];
            }
            return $default;
        }

        /**
         * Get current post ID being edited
         *
         * @return int|null
         */
        private function get_current_post_id()
        {
            global $post;

            if ($post && $post->ID) {
                return $post->ID;
            }

            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only check for post ID
            if (isset($_GET['post'])) {
                return absint($_GET['post']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            }
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read-only check for post ID
            if (isset($_POST['post_ID'])) {
                return absint($_POST['post_ID']); // phpcs:ignore WordPress.Security.NonceVerification.Missing
            }

            return null;
        }

        /**
         * Check if a post has an existing saved value for a meta key
         *
         * @param string $meta_key The meta key to check
         * @return bool True if the post has an existing saved value
         */
        private function post_has_saved_value($meta_key)
        {
            $post_id = $this->get_current_post_id();

            if (!$post_id) {
                return false;
            }

            $meta_exists = metadata_exists('post', $post_id, $meta_key);

            return $meta_exists;
        }

        private function get_use_global_default($setting_key)
        {
            if ($this->post_has_saved_value($setting_key)) {
                return array();
            }

            return array('1');
        }

        private function is_use_global_value($value)
        {
            if (is_array($value)) {
                return in_array('1', $value, true);
            }

            return $value === '1' || $value === 1 || $value === true || null === $value;
        }

        private function get_global_mode_options()
        {
            return array(
                '1'        => esc_html__('Use global settings', 'tinypress'),
                'enabled'  => esc_html__('Enabled', 'tinypress'),
                'disabled' => esc_html__('Disabled', 'tinypress'),
            );
        }

        private function get_global_mode_default($setting_key, $use_global_key)
        {
            $post_id = $this->get_current_post_id();

            if (! $post_id) {
                return '1';
            }

            $use_global = get_post_meta($post_id, $use_global_key, true);

            if (! metadata_exists('post', $post_id, $use_global_key) && ! $this->post_has_saved_value($setting_key)) {
                return '1';
            }

            if ('enabled' === $use_global || 'disabled' === $use_global) {
                return $use_global;
            }

            if ($this->is_use_global_value($use_global) && ! $this->post_has_saved_value($setting_key)) {
                return '1';
            }

            if ($this->is_use_global_value($use_global) && metadata_exists('post', $post_id, $use_global_key)) {
                return '1';
            }

            $setting_value = get_post_meta($post_id, $setting_key, true);

            return empty($setting_value) ? 'disabled' : 'enabled';
        }

        /**
         * Get default value for redirection method dropdown
         *
         * - If post has existing saved value: return that value
         * - If post is new or has no saved value: return empty string (use global)
         *
         * @return string Default value for the dropdown
         */
        private function get_redirection_method_default()
        {
            $post_id = $this->get_current_post_id();

            if ($post_id && metadata_exists('post', $post_id, 'redirection_method')) {
                $saved_value = get_post_meta($post_id, 'redirection_method', true);
                if (in_array($saved_value, array('301', '302', '307', 301, 302, 307), true)) {
                    return $saved_value;
                }
            }

            return '';
        }

        private function get_use_global_label($global_key, $default_value = false)
        {
            $global_value = $this->get_global_setting($global_key, $default_value);
            $current_state = $global_value ? esc_html__('ON', 'tinypress') : esc_html__('OFF', 'tinypress');

            return sprintf(
                /* translators: %s: current global setting value */
                esc_html__('Use global settings', 'tinypress'),
                $current_state
            );
        }

        /**
         * Get redirection method options with "Use global" as first option
         *
         * @return array
         */
        private function get_redirection_method_options()
        {
            $global_value = $this->get_global_setting('tinypress_global_redirection_method', 302);

            $method_labels = array(
                307 => '307',
                302 => '302',
                301 => '301',
            );

            $current_label = isset($method_labels[$global_value]) ? $method_labels[$global_value] : '302';

            return array(
                ''  => sprintf(
                    /* translators: %s: current global setting value */
                    esc_html__('Use global settings', 'tinypress'),
                    $current_label
                ),
                307 => esc_html__('307 (Temporary)', 'tinypress'),
                302 => esc_html__('302 (Temporary)', 'tinypress'),
                301 => esc_html__('301 (Permanent)', 'tinypress'),
            );
        }

        /**
         * Generate meta box for slider data
         */
        private function generate_tinypress_meta_box()
        {
            // Create a metabox for tinypress.
            WPDK_Settings::createMetabox(
                $this->tinypress_metabox_main,
                array(
                    'title'     => esc_html__('PublishPress Shortlinks', 'tinypress'),
                    'post_type' => 'tinypress_link',
                    'data_type' => 'unserialize',
                    'context'   => 'normal',
                    'nav'       => 'normal',
                    'preview'   => true,
                )
            );

            // General Settings section.
            WPDK_Settings::createSection(
                $this->tinypress_metabox_main,
                array(
                    'title'  => esc_html__('General', 'tinypress'),
                    'fields' => array(
                        array(
                            'id'         => 'post_title',
                            'type'       => 'text',
                            'title'      => esc_html__('Label *', 'tinypress'),
                            'wp_type'    => 'post_title',
                            'subtitle'   => esc_html__('For admin purpose only.', 'tinypress'),
                            'attributes' => array(
                                'autocomplete' => 'off',
                                'class'        => 'tinypress_tiny_label',
                            ),
                        ),
                        array(
                            'id'         => 'target_url',
                            'type'       => 'text',
                            'title'      => esc_html__('Target URL *', 'tinypress'),
                            'sanitize'   => 'esc_url_raw',
                            'attributes' => array(
                                'class' => 'tinypress_tiny_url',
                            ),
                        ),
                        array(
                            'id'       => 'tiny_slug',
                            'type'     => 'callback',
                            'function' => array( $this, 'render_field_tinypress_link' ),
                            'title'    => esc_html__('Short String *', 'tinypress'),
                            'subtitle' => esc_html__('Short string of this URL.', 'tinypress'),
                            'default'  => $this->tinypress_default_slug,
                        ),
                        array(
                            'id'         => 'link_status',
                            'type'       => 'switcher',
                            'title'      => esc_html__('Status', 'tinypress'),
                            'subtitle'   => esc_html__('Disable the shortlink instantly.', 'tinypress'),
                            'label'      => esc_html__('After disabling the link will not active but the settings will be reserved.', 'tinypress'),
                            'text_on'    => esc_html__('Enable', 'tinypress'),
                            'text_off'   => esc_html__('Disable', 'tinypress'),
                            'default'    => true,
                            'text_width' => 100,
                        ),
                        array(
                            'id'    => 'tiny_notes',
                            'type'  => 'textarea',
                            'title' => esc_html__('Notes', 'tinypress'),
                        ),
                    ),
                )
            );

            $current_post_id = isset($_GET['post']) ? intval($_GET['post']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $is_internal_link = false;

            if ($current_post_id > 0) {
                $target_url = Utils::get_meta('target_url', $current_post_id);
                if (! empty($target_url)) {
                    $site_url = get_site_url();
                    $site_host = wp_parse_url($site_url, PHP_URL_HOST);
                    $target_host = wp_parse_url($target_url, PHP_URL_HOST);

                    $site_host = preg_replace('/^www\./', '', $site_host);
                    $target_host = preg_replace('/^www\./', '', $target_host);

                    $is_internal_link = ($site_host === $target_host);
                }
            }

            $alt_text_options = array(
                'shortlink_label' => esc_html__('Shortlink Label', 'tinypress'),
                'custom'          => esc_html__('Custom Text', 'tinypress'),
            );
            if ($is_internal_link) {
                $alt_text_options = array(
                    'post_title'      => esc_html__('Post Title', 'tinypress'),
                    'shortlink_label' => esc_html__('Shortlink Label', 'tinypress'),
                    'custom'          => esc_html__('Custom Text', 'tinypress'),
                );
            }

            $alt_text_default = $is_internal_link ? 'post_title' : 'shortlink_label';

            $autolink_fields = array(
                array(
                    'id'       => 'autolink_keywords',
                    'type'     => 'textarea',
                    'title'    => esc_html__('Keywords', 'tinypress'),
                    'subtitle' => esc_html__('Add keywords separated by commas or on separate lines. Each keyword will link to this shortlink.', 'tinypress'),
                ),
                array(
                    'id'       => 'autolink_alt_text',
                    'type'     => 'select',
                    'title'    => esc_html__('Link Alt Text', 'tinypress'),
                    'subtitle' => esc_html__('Set the alt text for the linked keywords in the frontend of the site.', 'tinypress'),
                    'options'  => $alt_text_options,
                    'default'  => $alt_text_default,
                ),
                array(
                    'id'       => 'autolink_alt_text_custom',
                    'type'     => 'text',
                    'title'    => esc_html__('Custom Alt Text', 'tinypress'),
                    'subtitle' => esc_html__('Enter custom alt text for the linked keywords. This is only used if "Custom Text" is selected above.', 'tinypress'),
                    'dependency' => array(
                        array('autolink_alt_text', '==', 'custom'),
                    ),
                ),
            );
            $autolink_fields = apply_filters('tinypress_autolink_metabox_fields', $autolink_fields);

            WPDK_Settings::createSection(
                $this->tinypress_metabox_main,
                array(
                    'title'  => esc_html__('Auto-linking', 'tinypress'),
                    'fields' => $autolink_fields,
                )
            );

            // Redirection Settings section.
            WPDK_Settings::createSection(
                $this->tinypress_metabox_main,
                array(
                    'title'  => esc_html__('Redirection', 'tinypress'),
                    'fields' => array(
                        array(
                            'id'          => 'redirection_method',
                            'type'        => 'select',
                            'title'       => esc_html__('Redirection Method', 'tinypress'),
                            'subtitle'    => esc_html__('Select redirection method', 'tinypress'),
                            'placeholder' => esc_html__('Select a method', 'tinypress'),
                            'options'     => $this->get_redirection_method_options(),
                            'default'     => $this->get_redirection_method_default(),
                        ),
                        array(
                            'id'       => 'redirection_sponsored_use_global',
                            'type'     => 'select',
                            'title'    => esc_html__('Sponsored', 'tinypress'),
                            'subtitle' => sprintf(
                                "%1\$s\n%2\$s",
                                esc_html__('Mark links as sponsored content.', 'tinypress'),
                                esc_html__('Adds rel="sponsored" attribute. Recommended for affiliate links and paid promotions.', 'tinypress')
                            ),
                            'options'  => $this->get_global_mode_options(),
                            'default'  => $this->get_global_mode_default('redirection_sponsored', 'redirection_sponsored_use_global'),
                            'class'    => 'tinypress-global-mode-select',
                        ),
                        array(
                            'id'           => 'redirection_sponsored',
                            'type'         => 'switcher',
                            'title'        => '',
                            'label'        => esc_html__('Adds rel="sponsored" attribute. Recommended for affiliate links and paid promotions.', 'tinypress'),
                            'default'      => false,
                            'class'        => 'tinypress-global-controlled tinypress-global-toggle-source',
                            'dependency'   => array('redirection_sponsored_use_global', '==', 'enabled'),
                        ),
                        array(
                            'id'       => 'redirection_no_follow_use_global',
                            'type'     => 'select',
                            'title'    => esc_html__('NoFollow', 'tinypress'),
                            'subtitle' => sprintf(
                                "%1\$s\n%2\$s",
                                esc_html__('Prevent search engines from following this link.', 'tinypress'),
                                esc_html__('Adds rel="nofollow" attribute. Recommended for external links and untrusted sources.', 'tinypress')
                            ),
                            'options'  => $this->get_global_mode_options(),
                            'default'  => $this->get_global_mode_default('redirection_no_follow', 'redirection_no_follow_use_global'),
                            'class'    => 'tinypress-global-mode-select',
                        ),
                        array(
                            'id'           => 'redirection_no_follow',
                            'type'         => 'switcher',
                            'title'        => '',
                            'label'        => esc_html__('Adds rel="nofollow" attribute. Recommended for external links and untrusted sources.', 'tinypress'),
                            'default'      => true,
                            'class'        => 'tinypress-global-controlled tinypress-global-toggle-source',
                            'dependency'   => array('redirection_no_follow_use_global', '==', 'enabled'),
                        ),
                        array(
                            'id'       => 'redirection_parameter_forwarding_use_global',
                            'type'     => 'select',
                            'title'    => esc_html__('Parameter Forwarding', 'tinypress'),
                            'subtitle' => sprintf(
                                "%1\$s\n%2\$s",
                                esc_html__('Pass URL parameters to the target link.', 'tinypress'),
                                esc_html__('Any parameters added to the short URL (e.g., ?utm_source=email) will be forwarded to the target URL.', 'tinypress')
                            ),
                            'options'  => $this->get_global_mode_options(),
                            'default'  => $this->get_global_mode_default('redirection_parameter_forwarding', 'redirection_parameter_forwarding_use_global'),
                            'class'    => 'tinypress-global-mode-select',
                        ),
                        array(
                            'id'           => 'redirection_parameter_forwarding',
                            'type'         => 'switcher',
                            'title'        => '',
                            'label'        => esc_html__('Any parameters added to the short URL (e.g., ?utm_source=email) will be forwarded to the target URL.', 'tinypress'),
                            'default'      => false,
                            'class'        => 'tinypress-global-controlled tinypress-global-toggle-source',
                            'dependency'   => array('redirection_parameter_forwarding_use_global', '==', 'enabled'),
                        ),
                    ),
                )
            );
            // Security Settings section.
            $security_fields = array(
                array(
                    'id'       => 'password_protection_use_global',
                    'type'     => 'select',
                    'title'    => esc_html__('Password Protection', 'tinypress'),
                    'subtitle' => sprintf(
                        "%1\$s\n%2\$s",
                        esc_html__('Secure your shortlink.', 'tinypress'),
                        esc_html__('Users must enter the password to redirect to the target link.', 'tinypress')
                    ),
                    'options'  => $this->get_global_mode_options(),
                    'default'  => $this->get_global_mode_default('password_protection', 'password_protection_use_global'),
                    'class'    => 'tinypress-global-mode-select',
                ),
                array(
                    'id'           => 'password_protection',
                    'type'         => 'switcher',
                    'title'        => '',
                    'label'        => esc_html__('Users must enter the password to redirect to the target link.', 'tinypress'),
                    'default'      => false,
                    'class'        => 'tinypress-global-controlled tinypress-global-toggle-source',
                    'dependency'   => array('password_protection_use_global', '==', 'enabled'),
                ),
                array(
                    'id'           => 'link_password',
                    'type'         => 'text',
                    'title'        => esc_html__('Password', 'tinypress'),
                    'subtitle'     => esc_html__('Share this with users.', 'tinypress'),
                    'desc'         => esc_html__('Passwords are case sensitive.', 'tinypress'),
                    'placeholder'  => esc_html__('********', 'tinypress'),
                    'attributes'   => array(
                        'minlength' => 6,
                    ),
                    'dependency'   => array( 'password_protection', '==', '1' ),
                    'class'        => 'tinypress-global-controlled-child',
                ),
                array(
                    'id'       => 'enable_expiration_use_global',
                    'type'     => 'select',
                    'title'    => esc_html__('Enable Expiration', 'tinypress'),
                    'subtitle' => sprintf(
                        "%1\$s\n%2\$s",
                        esc_html__('Set an expiration date and time for shortlinks.', 'tinypress'),
                        esc_html__('After the expiration date and time pass, visitors will no longer be able to access the shortlink.', 'tinypress')
                    ),
                    'options'  => $this->get_global_mode_options(),
                    'default'  => $this->get_global_mode_default('enable_expiration', 'enable_expiration_use_global'),
                    'class'    => 'tinypress-global-mode-select',
                ),
                array(
                    'id'           => 'enable_expiration',
                    'type'         => 'switcher',
                    'title'        => '',
                    'label'        => esc_html__('After the expiration date and time pass, visitors will no longer be able to access the shortlink.', 'tinypress'),
                    'default'      => false,
                    'class'        => 'tinypress-global-controlled tinypress-global-toggle-source',
                    'dependency'   => array('enable_expiration_use_global', '==', 'enabled'),
                ),
                array(
                    'id'           => 'expiration_date',
                    'type'         => 'datetime',
                    'title'        => esc_html__('Expiration Date', 'tinypress'),
                    'subtitle'     => esc_html__('Select the date when this shortlink should stop working.', 'tinypress'),
                    'settings'     => array(
                        'dateFormat'      => 'd-m-Y',
                        'enableTime'      => false,
                        'allowInput'      => false,
                        'minDate'         => 'today',
                    ),
                    'dependency'   => array( 'enable_expiration', '==', '1' ),
                    'class'        => 'tinypress-global-controlled-child',
                ),
                array(
                    'id'           => 'expiration_time',
                    'type'         => 'datetime',
                    'title'        => esc_html__('Expiration Time', 'tinypress'),
                    'subtitle'     => esc_html__('Select the time when the shortlink should expire.', 'tinypress'),
                    'desc'         => esc_html__('Must be at least 1 minute in the future. Combined with the date above to set the exact expiration moment.', 'tinypress'),
                    'settings'     => array(
                        'noCalendar'      => true,
                        'enableTime'      => true,
                        'time_24hr'       => false,
                        'dateFormat'      => 'h:i K',
                        'allowInput'      => false,
                        'minuteIncrement' => 1,
                    ),
                    'dependency'   => array( 'enable_expiration', '==', '1' ),
                    'class'        => 'tinypress-global-controlled-child',
                ),
            );
            $security_fields = apply_filters('tinypress_security_metabox_fields', $security_fields);

            WPDK_Settings::createSection(
                $this->tinypress_metabox_main,
                array(
                    'title'  => esc_html__('Security', 'tinypress'),
                    'fields' => $security_fields,
                )
            );

            if (current_user_can('tinypress_view_shortlink_analytics')) {
                WPDK_Settings::createSection(
                    $this->tinypress_metabox_main,
                    array(
                        'id'       => 'analytics',
                        'external' => true,
                        'title'    => esc_html__('Analytics', 'tinypress'),
                    )
                );
            }
        }
    }

}
