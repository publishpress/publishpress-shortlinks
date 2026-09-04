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
        private $native_rest_sync_post_types = array();


        /**
         * TINYPRESS_Meta_boxes constructor.
         */
        public function __construct()
        {
            $this->tinypress_default_slug = tinypress_create_url_slug();
            $this->generate_tinypress_meta_box();
            add_action('init', array( $this, 'register_native_shortlink_meta' ), 20);
            add_action('enqueue_block_editor_assets', array( $this, 'enqueue_block_editor_metabox' ));

            foreach (get_post_types(array( 'public' => true )) as $post_type) {
                if (! in_array($post_type, array( 'attachment' ))) {
                    add_action('add_meta_boxes_' . $post_type, array( $this, 'add_shortlinks_metabox' ));

                    if ($post_type === 'tinypress_link') {
                        add_action('save_post_tinypress_link', array( $this, 'save_tinypress_link_metabox' ), 15, 2);
                    } elseif (function_exists('tinypress_is_post_type_enabled') && tinypress_is_post_type_enabled($post_type)) {
                        add_action('save_post_' . $post_type, array( $this, 'save_native_shortlinks_metabox' ), 10, 2);
                        $this->register_native_shortlink_rest_sync($post_type);
                    }
                }
            }

            add_filter('pb_settings_tinypress_meta_main_save', array($this, 'ensure_autolink_keywords_saved'), 10, 3);

            add_action('add_meta_boxes', array( $this, 'add_side_meta_box' ), 0);
            add_action('WPDK_Settings/meta_section/analytics', array( $this, 'render_analytics' ));
            add_action('WPDK_Settings/meta_section/categories', array( $this, 'render_categories_section' ));
            add_action('WPDK_Settings/meta_section/qr_code', array( $this, 'render_qr_code_section' ));
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
         * Render the Categories tab content.
         *
         * @return void
         */
        public function render_categories_section()
        {
            $this->render_link_categories_panel();
        }

        /**
         * Render the QR Code tab content.
         *
         * @return void
         */
        public function render_qr_code_section()
        {
            include TINYPRESS_PLUGIN_DIR . 'templates/admin/qr-code.php';
        }

        /**
         * Render the native categories UI inside the tabbed side metabox.
         *
         * @return void
         */
        private function render_link_categories_panel()
        {
            global $post;

            $taxonomy = get_taxonomy('tinypress_link_cat');
            if (! $post || ! $taxonomy || ! current_user_can($taxonomy->cap->assign_terms)) {
                return;
            }

            echo '<div class="tinypress-side-category-panel">';
            post_categories_meta_box($post, array(
                'args' => array(
                    'taxonomy' => 'tinypress_link_cat',
                ),
            ));
            echo '</div>';
        }


        /**
         * Add Side Meta Box
         *
         * @return void
         */
        public function add_side_meta_box()
        {
            remove_meta_box('tinypress_link_catdiv', 'tinypress_link', 'side');
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

            if ('tinypress_link' !== $post->post_type && function_exists('tinypress_is_post_type_enabled') && ! tinypress_is_post_type_enabled($post->post_type)) {
                return;
            }

            if ($this->should_use_block_editor_panel($post->post_type)) {
                return;
            }

            add_meta_box('tinypress_shortlinks_' . $post->post_type, esc_html__('Shortlinks', 'tinypress'), array( $this, 'render_native_shortlinks_metabox' ), $post->post_type, 'side', 'high');
        }

        /**
         * Register native shortlink meta for the REST API so the block editor can save it.
         *
         * @return void
         */
        public function register_native_shortlink_meta()
        {
            if (! function_exists('register_post_meta')) {
                return;
            }

            foreach ($this->get_enabled_native_post_types() as $post_type) {
                register_post_meta($post_type, 'tiny_slug', array(
                    'auth_callback'     => array( $this, 'can_edit_shortlink_meta' ),
                    'description'       => __('PublishPress Shortlinks slug.', 'tinypress'),
                    'sanitize_callback' => 'sanitize_text_field',
                    'show_in_rest'      => true,
                    'single'            => true,
                    'type'              => 'string',
                ));

                $this->register_native_shortlink_rest_sync($post_type);
            }
        }

        /**
         * Check if the current user can edit shortlink meta for a post.
         *
         * @param bool   $allowed   Existing auth result.
         * @param string $meta_key  Meta key.
         * @param int    $object_id Post ID.
         * @param int    $user_id   User ID.
         * @return bool
         */
        public function can_edit_shortlink_meta($allowed, $meta_key, $object_id, $user_id = 0)
        {
            unset($allowed, $meta_key);

            $user_id = $user_id ? absint($user_id) : get_current_user_id();
            $post_id = absint($object_id);

            return $post_id && user_can($user_id, 'edit_post', $post_id);
        }

        /**
         * Enqueue the block editor replacement for the native Shortlinks metabox.
         *
         * @return void
         */
        public function enqueue_block_editor_metabox()
        {
            if (! function_exists('get_current_screen')) {
                return;
            }

            $screen = get_current_screen();

            if (! $screen || empty($screen->post_type) || ! $this->should_use_block_editor_panel($screen->post_type)) {
                return;
            }

            $post_type_object = get_post_type_object($screen->post_type);
            $edit_posts_cap   = ($post_type_object && ! empty($post_type_object->cap->edit_posts)) ? $post_type_object->cap->edit_posts : 'edit_posts';

            if (! current_user_can($edit_posts_cap)) {
                return;
            }

            $style_handle = 'tinypress-shortlink-editor-style';
            if (! wp_style_is($style_handle, 'registered')) {
                wp_register_style(
                    $style_handle,
                    TINYPRESS_PLUGIN_URL . 'assets/admin/css/gutenberg-shortlink.css',
                    array( 'dashicons' ),
                    tinypress_asset_version('assets/admin/css/gutenberg-shortlink.css')
                );
            }

            wp_enqueue_style($style_handle);

            $script_handle = 'tinypress-gutenberg-metabox';
            wp_register_script(
                $script_handle,
                TINYPRESS_PLUGIN_URL . 'assets/admin/js/gutenberg-shortlink-panel.js',
                array(
                    'wp-components',
                    'wp-data',
                    'wp-edit-post',
                    'wp-element',
                    'wp-i18n',
                    'wp-plugins',
                ),
                tinypress_asset_version('assets/admin/js/gutenberg-shortlink-panel.js'),
                true
            );

            wp_enqueue_script($script_handle);
            wp_localize_script($script_handle, 'tinypressShortlinksMetabox', $this->get_block_editor_metabox_data($screen->post_type));
        }

        /**
         * Keep legacy nested metabox meta aligned after REST editor saves.
         *
         * @param WP_Post         $post     Inserted or updated post object.
         * @param WP_REST_Request $request  REST request.
         * @param bool            $creating Whether the post is being created.
         * @return void
         */
        public function sync_native_shortlinks_rest_meta($post, $request, $creating)
        {
            unset($creating);

            if (! $post || ! is_a($post, 'WP_Post')) {
                return;
            }

            if (! is_object($request) || ! method_exists($request, 'get_param')) {
                return;
            }

            $meta = $request->get_param('meta');

            if (! is_array($meta) || ! array_key_exists('tiny_slug', $meta)) {
                return;
            }

            $this->sync_native_shortlinks_legacy_meta($post->ID, $post);
        }

        /**
         * Sync the legacy nested native metabox meta key from the direct tiny_slug key.
         *
         * @param int     $post_id Post ID.
         * @param WP_Post $post    Post object.
         * @return void
         */
        private function sync_native_shortlinks_legacy_meta($post_id, $post)
        {
            if (! $post || ! is_a($post, 'WP_Post') || ! $this->is_native_shortlinks_post_type($post->post_type)) {
                return;
            }

            if (! metadata_exists('post', $post_id, 'tiny_slug')) {
                return;
            }

            if (! current_user_can('edit_post', $post_id)) {
                return;
            }

            $tiny_slug = sanitize_text_field((string) get_post_meta($post_id, 'tiny_slug', true));
            $meta_key  = 'tinypress_meta_side_' . $post->post_type;
            $meta_data = get_post_meta($post_id, $meta_key, true);

            if (! is_array($meta_data)) {
                $meta_data = array();
            }

            if (! array_key_exists('tiny_slug', $meta_data) || $tiny_slug !== (string) $meta_data['tiny_slug']) {
                $meta_data['tiny_slug'] = $tiny_slug;
                update_post_meta($post_id, $meta_key, $meta_data);
            }
        }

        /**
         * Get data for the block editor Shortlinks panel.
         *
         * @param string $post_type Post type.
         * @return array
         */
        private function get_block_editor_metabox_data($post_type)
        {
            $post_id       = $this->get_current_post_id();
            $prefix        = '';
            $prefix_config = function_exists('tinypress_get_link_prefix_settings') ? tinypress_get_link_prefix_settings() : array();

            if (! empty($prefix_config['enabled']) && '1' === (string) $prefix_config['enabled']) {
                $prefix = trailingslashit(sanitize_title((string) $prefix_config['slug']));
            }

            return array(
                'defaultSlug'            => $this->tinypress_default_slug,
                'enabled'                => true,
                'linkedShortlinkEditUrl' => $post_id ? $this->get_linked_shortlink_edit_url($post_id) : '',
                'metaKey'                => 'tiny_slug',
                'postType'               => sanitize_key($post_type),
                'shortlinkBaseUrl'       => esc_url_raw(trailingslashit(site_url('/' . $prefix))),
                'i18n'                   => array(
                    'copied'       => __('Copied', 'tinypress'),
                    'copy'         => __('Copy', 'tinypress'),
                    'editSettings' => __('Edit shortlink settings', 'tinypress'),
                    'emptySlug'    => __('Enter a shortlink slug to create a shortlink URL.', 'tinypress'),
                    'panelTitle'   => __('Shortlinks', 'tinypress'),
                    'slugLabel'    => __('Shortlink Slug', 'tinypress'),
                    'urlLabel'     => __('Shortlink URL', 'tinypress'),
                ),
            );
        }

        /**
         * Get the linked tinypress_link edit URL for a native source post.
         *
         * @param int $post_id Source post ID.
         * @return string
         */
        private function get_linked_shortlink_edit_url($post_id)
        {
            $link_posts = get_posts(array(
                'post_type'      => 'tinypress_link',
                'posts_per_page' => 1,
                'post_status'    => 'any',
                'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required to find linked tinypress_link entry by source_post_id.
                    array(
                        'key'     => 'source_post_id',
                        'value'   => absint($post_id),
                        'compare' => '=',
                    ),
                ),
                'fields'         => 'ids',
            ));

            if (empty($link_posts)) {
                return '';
            }

            $edit_url = get_edit_post_link($link_posts[0], 'raw');

            return $edit_url ? esc_url_raw($edit_url) : '';
        }

        /**
         * Get enabled native post types for shortlink metadata.
         *
         * @return array
         */
        private function get_enabled_native_post_types()
        {
            if (function_exists('tinypress_get_enabled_post_types')) {
                $post_types = tinypress_get_enabled_post_types();
            } else {
                $post_types = array('post', 'page');
            }

            $post_types = array_map('sanitize_key', (array) $post_types);
            $post_types = array_filter($post_types, array( $this, 'is_native_shortlinks_post_type' ));

            return array_values(array_unique($post_types));
        }

        /**
         * Check if a post type should use native shortlinks UI.
         *
         * @param string $post_type Post type.
         * @return bool
         */
        private function is_native_shortlinks_post_type($post_type)
        {
            $post_type = sanitize_key($post_type);

            if ('' === $post_type || in_array($post_type, array( 'attachment', 'tinypress_link' ), true)) {
                return false;
            }

            if (! post_type_exists($post_type)) {
                return false;
            }

            if (function_exists('tinypress_is_post_type_enabled')) {
                return tinypress_is_post_type_enabled($post_type);
            }

            return in_array($post_type, array( 'post', 'page' ), true);
        }

        /**
         * Check if a post type should use the block editor Shortlinks panel.
         *
         * @param string $post_type Post type.
         * @return bool
         */
        private function should_use_block_editor_panel($post_type)
        {
            if (! $this->is_native_shortlinks_post_type($post_type)) {
                return false;
            }

            if (function_exists('get_current_screen')) {
                $screen = get_current_screen();

                if ($screen && method_exists($screen, 'is_block_editor')) {
                    return (bool) $screen->is_block_editor();
                }
            }

            return function_exists('use_block_editor_for_post_type') && use_block_editor_for_post_type($post_type);
        }

        /**
         * Register the REST save sync hook once per post type.
         *
         * @param string $post_type Post type.
         * @return void
         */
        private function register_native_shortlink_rest_sync($post_type)
        {
            $post_type = sanitize_key($post_type);

            if ('' === $post_type || isset($this->native_rest_sync_post_types[ $post_type ])) {
                return;
            }

            $this->native_rest_sync_post_types[ $post_type ] = true;
            add_action('rest_after_insert_' . $post_type, array( $this, 'sync_native_shortlinks_rest_meta' ), 10, 3);
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
                $tiny_slug = sanitize_text_field(wp_unslash((string) $_POST[ $meta_key ]['tiny_slug']));

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
                            'subtitle'   => esc_html__('Enable or disable the shortlink.', 'tinypress'),
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
                    'dependency' => array('autolink_alt_text', '==', 'custom'),
                    'class'    => 'tinypress-dependent-child',
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
                            'type'         => 'text',
                            'title'        => '',
                            'default'      => false,
                            'class'        => 'tinypress-global-controlled tinypress-global-toggle-source hidden',
                            'dependency'   => array('redirection_sponsored_use_global', '==', 'enabled'),
                            'attributes'   => array(
                                'type' => 'hidden',
                            ),
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
                            'type'         => 'text',
                            'title'        => '',
                            'default'      => true,
                            'class'        => 'tinypress-global-controlled tinypress-global-toggle-source hidden',
                            'dependency'   => array('redirection_no_follow_use_global', '==', 'enabled'),
                            'attributes'   => array(
                                'type' => 'hidden',
                            ),
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
                            'type'         => 'text',
                            'title'        => '',
                            'default'      => false,
                            'class'        => 'tinypress-global-controlled tinypress-global-toggle-source hidden',
                            'dependency'   => array('redirection_parameter_forwarding_use_global', '==', 'enabled'),
                            'attributes'   => array(
                                'type' => 'hidden',
                            ),
                        ),
                    ),
                )
            );

            $dynamic_redirect_fields = apply_filters('tinypress_dynamic_redirect_metabox_fields', array());

            if (! empty($dynamic_redirect_fields)) {
                WPDK_Settings::createSection(
                    $this->tinypress_metabox_main,
                    array(
                        'title'  => esc_html__('Dynamic Redirects', 'tinypress'),
                        'fields' => $dynamic_redirect_fields,
                    )
                );
            }

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
                    'type'         => 'text',
                    'title'        => '',
                    'default'      => false,
                    'class'        => 'tinypress-global-controlled tinypress-global-toggle-source hidden',
                    'dependency'   => array('password_protection_use_global', '==', 'enabled'),
                    'attributes'   => array(
                        'type' => 'hidden',
                    ),
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
            );
            $security_fields = apply_filters('tinypress_security_metabox_fields', $security_fields);

            WPDK_Settings::createSection(
                $this->tinypress_metabox_main,
                array(
                    'title'  => esc_html__('Security', 'tinypress'),
                    'fields' => $security_fields,
                )
            );

            // Scheduling Settings section.
            $scheduling_fields = array(
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
                    'type'         => 'text',
                    'title'        => '',
                    'default'      => false,
                    'class'        => 'tinypress-global-controlled tinypress-global-toggle-source hidden',
                    'dependency'   => array('enable_expiration_use_global', '==', 'enabled'),
                    'attributes'   => array(
                        'type' => 'hidden',
                    ),
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
                    'class'        => 'tinypress-global-controlled-child tinypress-scheduled-expiration-field tinypress-scheduled-expiration-expiration-date',
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
                    'class'        => 'tinypress-global-controlled-child tinypress-scheduled-expiration-field tinypress-scheduled-expiration-expiration-time',
                ),
            );
            $scheduling_fields = apply_filters('tinypress_scheduling_metabox_fields', $scheduling_fields);

            WPDK_Settings::createSection(
                $this->tinypress_metabox_main,
                array(
                    'title'  => esc_html__('Scheduling', 'tinypress'),
                    'fields' => $scheduling_fields,
                )
            );

            WPDK_Settings::createSection(
                $this->tinypress_metabox_main,
                array(
                    'id'       => 'categories',
                    'external' => true,
                    'title'    => esc_html__('Categories', 'tinypress'),
                )
            );

            WPDK_Settings::createSection(
                $this->tinypress_metabox_main,
                array(
                    'id'       => 'qr_code',
                    'external' => true,
                    'title'    => esc_html__('QR Code', 'tinypress'),
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
