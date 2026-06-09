<?php

/**
 * Block Editor Shortlink Integration
 *
 * Handles Gutenberg / WordPress block editor integration for shortlinks.
 */

if (! defined('ABSPATH')) {
    exit;
}

if (! class_exists('TINYPRESS_Gutenberg_Shortlink')) {
    /**
     * Class TINYPRESS_Gutenberg_Shortlink
     *
     * Note: This class uses WordPress naming conventions instead of strict PSR-1/PSR-2 standards.
     */
    // phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace, Squiz.Classes.ValidClassName.NotCamelCaps, PSR1.Methods.CamelCapsMethodName.NotCamelCaps, PSR2.Classes.PropertyDeclaration.Underscore
    class TINYPRESS_Gutenberg_Shortlink
    {
        protected static $_instance = null;

        public function __construct()
        {
            add_action('enqueue_block_editor_assets', array( $this, 'enqueue_gutenberg_assets' ));
            add_action('admin_enqueue_scripts', array( $this, 'enqueue_classic_editor_assets' ));
            add_filter('mce_external_plugins', array( $this, 'register_classic_editor_plugin' ));
            add_filter('mce_buttons', array( $this, 'register_classic_editor_button' ));
            add_action('wp_ajax_tinypress_search_shortlinks', array( $this, 'search_shortlinks' ));
            add_action('wp_ajax_tinypress_create_shortlink', array( $this, 'create_shortlink' ));
            add_action('wp_ajax_tinypress_get_shortlink', array( $this, 'get_shortlink' ));
        }

        /**
         * Enqueue block editor assets.
         */
        public function enqueue_gutenberg_assets()
        {
            if (! current_user_can('edit_posts')) {
                return;
            }

            $script_handle = 'tinypress-gutenberg-shortlink';

            $this->enqueue_shared_editor_assets();

            wp_register_script(
                $script_handle,
                TINYPRESS_PLUGIN_URL . 'assets/admin/js/gutenberg-shortlink-button.js',
                array(
                    'wp-blocks',
                    'wp-block-editor',
                    'wp-components',
                    'wp-element',
                    'wp-i18n',
                    'wp-rich-text',
                    'jquery',
                    'tinypress-shortlink-ui',
                ),
                TINYPRESS_PLUGIN_VERSION,
                true
            );

            wp_enqueue_script($script_handle);

            wp_localize_script($script_handle, 'tinypressGutenberg', $this->get_localize_data());
        }

        /**
         * Enqueue Classic Editor assets.
         */
        public function enqueue_classic_editor_assets()
        {
            if (! $this->should_load_classic_editor_assets()) {
                return;
            }

            $this->enqueue_shared_editor_assets();

            wp_enqueue_script(
                'tinypress-classic-shortlink-button',
                TINYPRESS_PLUGIN_URL . 'assets/admin/js/classic-shortlink-button.js',
                array( 'jquery', 'tinypress-shortlink-ui' ),
                TINYPRESS_PLUGIN_VERSION,
                true
            );

            wp_localize_script('tinypress-classic-shortlink-button', 'tinypressGutenberg', $this->get_localize_data());
        }

        /**
         * Add TinyMCE plugin for Classic Editor.
         *
         * @param array $plugins
         * @return array
         */
        public function register_classic_editor_plugin($plugins)
        {
            if ($this->should_load_classic_editor_assets()) {
                $plugins['tinypress_shortlink'] = TINYPRESS_PLUGIN_URL . 'assets/admin/js/classic-shortlink-tinymce.js';
            }

            return $plugins;
        }

        /**
         * Add TinyMCE toolbar button for Classic Editor.
         *
         * @param array $buttons
         * @return array
         */
        public function register_classic_editor_button($buttons)
        {
            if ($this->should_load_classic_editor_assets()) {
                $buttons[] = 'tinypress_shortlink';
            }

            return $buttons;
        }

        /**
         * Enqueue scripts and styles shared by block and classic editors.
         */
        private function enqueue_shared_editor_assets()
        {
            wp_register_script(
                'tinypress-shortlink-ui',
                TINYPRESS_PLUGIN_URL . 'assets/admin/js/components/shortlink-ui.js',
                array( 'jquery', 'wp-i18n' ),
                TINYPRESS_PLUGIN_VERSION,
                true
            );

            wp_register_style(
                'tinypress-shortlink-editor-style',
                TINYPRESS_PLUGIN_URL . 'assets/admin/css/gutenberg-shortlink.css',
                array( 'dashicons' ),
                TINYPRESS_PLUGIN_VERSION
            );

            wp_enqueue_script('tinypress-shortlink-ui');
            wp_enqueue_style('tinypress-shortlink-editor-style');
            wp_localize_script('tinypress-shortlink-ui', 'tinypressGutenberg', $this->get_localize_data());
        }

        /**
         * Get localized data for all editor integrations.
         *
         * @return array
         */
        private function get_localize_data()
        {
            return array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('tinypress_gutenberg_nonce'),
                'canCreateShortlinks' => $this->current_user_can_create(),
                'i18n' => array(
                    'shortlink' => __('Shortlink', 'tinypress'),
                    'searchPlaceholder' => __('Search shortlinks...', 'tinypress'),
                    'insertShortlink' => __('Insert Shortlink', 'tinypress'),
                    'createNew' => __('Create New Shortlink', 'tinypress'),
                    'options' => __('Options', 'tinypress'),
                    'openInNewTab' => __('Open in New Tab', 'tinypress'),
                    'nofollow' => __('Nofollow', 'tinypress'),
                    'sponsoredLink' => __('Sponsored Link', 'tinypress'),
                    'label' => __('Label', 'tinypress'),
                    'labelPlaceholder' => __('Optional shortlink label', 'tinypress'),
                    'targetUrl' => __('Target URL', 'tinypress'),
                    'create' => __('Create', 'tinypress'),
                    'cancel' => __('Cancel', 'tinypress'),
                    'close' => __('Close', 'tinypress'),
                    'creating' => __('Creating...', 'tinypress'),
                    'created' => __('Shortlink created successfully.', 'tinypress'),
                    'error' => __('Error', 'tinypress'),
                    'invalidUrl' => __('Please enter a valid URL', 'tinypress'),
                    'noResults' => __('No shortlinks found', 'tinypress'),
                )
            );
        }

        /**
         * Check whether the current post edit screen is using the Classic Editor.
         *
         * @return bool
         */
        private function should_load_classic_editor_assets()
        {
            if (! current_user_can('edit_posts') || ! function_exists('get_current_screen')) {
                return false;
            }

            $screen = get_current_screen();

            if (! $screen || ! in_array($screen->base, array( 'post', 'post-new' ), true)) {
                return false;
            }

            if (empty($screen->post_type) || ! post_type_supports($screen->post_type, 'editor')) {
                return false;
            }

            if ('tinypress_link' === $screen->post_type) {
                return false;
            }

            if (method_exists($screen, 'is_block_editor') && $screen->is_block_editor()) {
                return false;
            }

            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen detection.
            $post_id = isset($_GET['post']) ? absint(wp_unslash($_GET['post'])) : 0;

            if ($post_id && function_exists('use_block_editor_for_post')) {
                $post = get_post($post_id);
                return $post ? ! use_block_editor_for_post($post) : false;
            }

            if (function_exists('use_block_editor_for_post_type')) {
                return ! use_block_editor_for_post_type($screen->post_type);
            }

            return true;
        }

        /**
         * AJAX: Search shortlinks
         */
        public function search_shortlinks()
        {
            check_ajax_referer('tinypress_gutenberg_nonce', 'nonce');

            if (! current_user_can('edit_posts')) {
                wp_send_json_error(array( 'message' => __('Permission denied', 'tinypress') ));
            }

            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- $_POST['search'] is sanitized below
            $search_term = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
            $page = isset($_POST['page']) ? absint($_POST['page']) : 1;
            $per_page = 10;

            if (empty($search_term)) {
                wp_send_json_error(array( 'message' => __('Search term is required', 'tinypress') ));
            }

            $query = $this->query_shortlinks($search_term, $page, $per_page);
            $results = array();

            foreach ($query['ids'] as $post_id) {
                $post = get_post($post_id);
                $tiny_slug = get_post_meta($post_id, 'tiny_slug', true);
                $target_url = get_post_meta($post_id, 'target_url', true);
                $shortlink_url = $this->get_shortlink_url($tiny_slug);

                if (! empty($tiny_slug)) {
                    $results[] = array(
                        'id' => $post_id,
                        'title' => $post->post_title ? $post->post_title : sprintf(__('Link - %s', 'tinypress'), $post_id),
                        'slug' => $tiny_slug,
                        'url' => $shortlink_url,
                        'target_url' => $target_url,
                    );
                }
            }

            wp_send_json_success(array(
                'results' => $results,
                'pagination' => array(
                    'total' => $query['total'],
                    'pages' => $query['pages'],
                    'current' => $page,
                    'has_more' => $page < $query['pages'],
                )
            ));
        }

        /**
         * AJAX: Create shortlink
         */
        public function create_shortlink()
        {
            check_ajax_referer('tinypress_gutenberg_nonce', 'nonce');

            if (! $this->current_user_can_create()) {
                wp_send_json_error(array( 'message' => __('Permission denied', 'tinypress') ));
            }

            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- URL is validated by tinypress_create_shorten_url().
            $target_url = isset($_POST['target_url']) ? wp_unslash($_POST['target_url']) : '';
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Label is sanitized below.
            $label = isset($_POST['label']) ? sanitize_text_field(wp_unslash($_POST['label'])) : '';

            if (empty(trim((string) $target_url))) {
                wp_send_json_error(array( 'message' => __('Target URL is required', 'tinypress') ));
            }

            $result = tinypress_create_shorten_url(array(
                'target_url' => $target_url,
                'post_title' => $label,
            ));

            if (is_wp_error($result)) {
                wp_send_json_error(array( 'message' => $result->get_error_message() ));
            }

            $shortlink_url = esc_url_raw($result);
            $tiny_slug = $this->get_slug_from_shortlink_url($shortlink_url);
            $post_id = $tiny_slug ? tinypress()->tiny_slug_to_post_id($tiny_slug) : 0;
            $post = $post_id ? get_post($post_id) : null;

            wp_send_json_success(array(
                'id' => $post_id,
                'title' => $post ? $post->post_title : $shortlink_url,
                'slug' => $tiny_slug,
                'url' => $shortlink_url,
                'target_url' => esc_url_raw($target_url),
                'message' => __('Shortlink created successfully.', 'tinypress'),
            ));
        }

        /**
         * AJAX: Get shortlink details
         */
        public function get_shortlink()
        {
            check_ajax_referer('tinypress_gutenberg_nonce', 'nonce');

            if (! current_user_can('edit_posts')) {
                wp_send_json_error(array( 'message' => __('Permission denied', 'tinypress') ));
            }

            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- $_POST['id'] is sanitized below
            $post_id = isset($_POST['id']) ? absint($_POST['id']) : 0;

            if (empty($post_id)) {
                wp_send_json_error(array( 'message' => __('Invalid shortlink ID', 'tinypress') ));
            }

            $post = get_post($post_id);

            if (! $post || $post->post_type !== 'tinypress_link') {
                wp_send_json_error(array( 'message' => __('Shortlink not found', 'tinypress') ));
            }

            $tiny_slug = get_post_meta($post_id, 'tiny_slug', true);
            $target_url = get_post_meta($post_id, 'target_url', true);
            $shortlink_url = $this->get_shortlink_url($tiny_slug);

            wp_send_json_success(array(
                'id' => $post_id,
                'title' => $post->post_title,
                'slug' => $tiny_slug,
                'url' => $shortlink_url,
                'target_url' => $target_url,
            ));
        }

        /**
         * Get shortlink URL from slug
         *
         * @param string $tiny_slug
         * @return string
         */
        private function get_shortlink_url($tiny_slug)
        {
            $prefix = '';
            $prefix_settings = function_exists('tinypress_get_link_prefix_settings')
                ? tinypress_get_link_prefix_settings()
                : array(
                    'enabled' => get_option('tinypress_link_prefix'),
                    'slug'    => get_option('tinypress_link_prefix_slug', 'go'),
                );
            $prefix_enabled = $prefix_settings['enabled'];
            $prefix_slug = $prefix_settings['slug'];
            
            if ('1' == $prefix_enabled) {
                $prefix = $prefix_slug . '/';
            }

            $shortlink_url = site_url('/' . $prefix . $tiny_slug);

            return $shortlink_url;
        }

        /**
         * Search shortlinks by title, slug, or target URL.
         *
         * @param string $search_term
         * @param int    $page
         * @param int    $per_page
         * @return array
         */
        private function query_shortlinks($search_term, $page, $per_page)
        {
            global $wpdb;

            $like = '%' . $wpdb->esc_like($search_term) . '%';
            $offset = max(0, ($page - 1) * $per_page);

            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Search needs an OR across post title and shortlink meta.
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT p.ID
                FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->postmeta} pm
                    ON p.ID = pm.post_id
                    AND pm.meta_key IN ('tiny_slug', 'target_url')
                WHERE p.post_type = %s
                    AND p.post_status = %s
                    AND (
                        p.post_title LIKE %s
                        OR pm.meta_value LIKE %s
                    )
                ORDER BY p.post_date DESC
                LIMIT %d OFFSET %d",
                'tinypress_link',
                'publish',
                $like,
                $like,
                $per_page,
                $offset
            ));

            $total = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT p.ID)
                FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->postmeta} pm
                    ON p.ID = pm.post_id
                    AND pm.meta_key IN ('tiny_slug', 'target_url')
                WHERE p.post_type = %s
                    AND p.post_status = %s
                    AND (
                        p.post_title LIKE %s
                        OR pm.meta_value LIKE %s
                    )",
                'tinypress_link',
                'publish',
                $like,
                $like
            ));
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

            return array(
                'ids' => array_map('absint', $ids),
                'total' => $total,
                'pages' => $per_page > 0 ? (int) ceil($total / $per_page) : 0,
            );
        }

        /**
         * Extract the tiny slug from a generated shortlink URL.
         *
         * @param string $shortlink_url
         * @return string
         */
        private function get_slug_from_shortlink_url($shortlink_url)
        {
            $path = trim((string) wp_parse_url($shortlink_url, PHP_URL_PATH), '/');

            if (empty($path)) {
                return '';
            }

            $parts = explode('/', $path);
            return sanitize_text_field(end($parts));
        }

        /**
         * Check if current user can create shortlinks
         *
         * @return bool
         */
        private function current_user_can_create()
        {
            if (! defined('PUBLISHPRESS_SHORTLINKS_PRO_VERSION')) {
                $user = wp_get_current_user();
                if (! $user || ! $user->exists()) {
                    return false;
                }
                $allowed = array( 'administrator', 'editor', 'author' );
                return ! empty(array_intersect((array) $user->roles, $allowed));
            }

            // For Pro version, check role-based access
            $user = wp_get_current_user();
            if (! $user || ! $user->exists()) {
                return false;
            }

            if (in_array('administrator', (array) $user->roles, true)) {
                return true;
            }

            $allowed_roles = get_option('tinypress_role_create', array());
            if (empty($allowed_roles) || ! is_array($allowed_roles)) {
                return true;
            }

            return ! empty(array_intersect((array) $user->roles, $allowed_roles));
        }

        /**
         * Get instance
         *
         * @return TINYPRESS_Gutenberg_Shortlink|null
         */
        public static function instance()
        {
            if (is_null(self::$_instance)) {
                self::$_instance = new self();
            }

            return self::$_instance;
        }
    }
    // phpcs:enable PSR1.Classes.ClassDeclaration.MissingNamespace, Squiz.Classes.ValidClassName.NotCamelCaps, PSR1.Methods.CamelCapsMethodName.NotCamelCaps, PSR2.Classes.PropertyDeclaration.Underscore
}

// Initialize the class
TINYPRESS_Gutenberg_Shortlink::instance();
