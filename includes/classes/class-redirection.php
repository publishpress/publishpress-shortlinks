<?php

/**
 * Class Redirection
 *
 * @author Pluginbazar
 */

use WPDK\Utils;

defined('ABSPATH') || exit;

if (! class_exists('TINYPRESS_Redirection')) {
    /**
     * Class TINYPRESS_Redirection
     *
     * Note: This class uses WordPress naming conventions for compatibility and backwards compatibility.
     */
    // phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace, Squiz.Classes.ValidClassName.NotCamelCaps, PSR1.Methods.CamelCapsMethodName.NotCamelCaps, PSR2.Classes.PropertyDeclaration.Underscore
    class TINYPRESS_Redirection
    {
        protected static $_instance = null;

        private $visitor_preview_enabled = false;

        private $non_public_notice_post = null;

        private $non_public_notice_rendered = false;

        private $shortlink_force_404 = false;

        /**
         * Flag to prevent redirection_controller from running twice
         *
         * @var bool
         */
        private $redirection_done = false;

        /**
         * TINYPRESS_Redirection constructor.
         */
        public function __construct()
        {
            // General redirection plugin protection: prevent WP from setting 404 for valid shortlinks.
            add_filter('pre_handle_404', array( $this, 'prevent_shortlink_404' ), 10, 2);

            // RankMath compatibility
            add_filter('rank_math/redirection/pre_search', array( $this, 'rankmath_pre_search_bypass' ), 10, 3);
            add_filter('rank_math/redirection/fallback_exclude_locations', array( $this, 'rankmath_exclude_shortlinks' ), 10, 1);
            add_action('wp', array( $this, 'redirection_controller' ), 5);
            // Yoast SEO compatibility
            add_filter('wpseo_redirect_bypass_redirect', array( $this, 'yoast_bypass_shortlink_redirect' ), 10, 1);
            // Redirection plugin compatibility
            add_filter('redirection_url_target', array( $this, 'redirection_plugin_bypass' ), 10, 2);

            add_filter('wp_title', array( $this, 'fix_shortlink_title' ), 10, 2);
            // Main redirection controller
            add_action('template_redirect', array( $this, 'redirection_controller' ), 0);
            add_action('pre_get_posts', array( $this, 'tinypress_filter_shortlink_preview_visibility' ));
            add_action('wp_footer', array( $this, 'inject_reload_detection' ));

            $preview_arg = defined('RVY_PREVIEW_ARG') ? sanitize_key(RVY_PREVIEW_ARG) : 'rv_preview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $is_visitor_preview = ! is_admin() && ! empty($_GET['tinypress_visitor']) && (! empty($_GET['rv_preview']) || ! empty($_GET['preview'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

            $visitor_preview_enabled = $this->get_settings_value('tinypress_revision_visitor_access', '1');

            if ($visitor_preview_enabled) {
                $this->visitor_preview_enabled = true;

                add_action('init', array( $this, 'inject_visitor_preview_capability' ), 1);
                add_action('init', array( $this, 'bust_cache_for_visitor_preview' ), 1);

                add_filter('revisionary_private_type_use_preview_url', array( $this, 'enable_private_type_frontend_preview' ), 10, 2);

                // Remove Elementor's blocking hook at very high priority so we can render elementor templates for visitors
                add_action('template_redirect', array( $this, 'prevent_elementor_homepage_redirect' ), -10000);

                add_filter('posts_request', array( $this, 'visitor_revision_sql_rewrite' ), 999);
                add_filter('posts_results', array( $this, 'visitor_revision_inject_post' ), 5, 2);
                add_action('pre_get_posts', array( $this, 'visitor_revision_fix_query' ), 1);
                add_filter('redirect_canonical', array( $this, 'visitor_revision_block_canonical' ), 10, 2);
                add_filter('get_post_metadata', array( $this, 'visitor_revision_fallback_metadata' ), 10, 5);
                add_filter('the_author', array( $this, 'visitor_revision_author' ), 20);
                add_action('wp_head', array( $this, 'visitor_revision_hide_toolbar' ));
                add_action('wp_head', array( $this, 'visitor_revision_elementor_support' ), 15);
                nocache_headers();
            }
        }

        private function get_settings_value($key, $default = null)
        {
            $settings = get_option('tinypress_settings', array());
            if (is_array($settings) && array_key_exists($key, $settings)) {
                return $settings[$key];
            }

            return Utils::get_option($key, $default);
        }

        /**
         * Resolve a dropdown setting with global fallback
         *
         * @param string $setting_key The per-link meta key
         * @param int $link_id The link post ID
         * @param string $global_key The global settings key
         * @param mixed $system_default The system default if global is also not set
         * @return mixed The resolved value
         */
        private function resolve_dropdown_setting($setting_key, $link_id, $global_key, $system_default)
        {
            $link_value = Utils::get_meta($setting_key, $link_id);

            if ($link_value !== null && $link_value !== '') {
                return $link_value;
            }

            $global_value = $this->get_settings_value($global_key, null);
            if ($global_value !== null && $global_value !== '') {
                return $global_value;
            }

            return $system_default;
        }

        /**
         * Resolve a toggle setting with global fallback
         *
         * @param string $setting_key The per-link toggle meta key
         * @param string $use_global_key The per-link "use global" checkbox meta key
         * @param int $link_id The link post ID
         * @param string $global_key The global settings key
         * @param mixed $system_default The system default if global is also not set
         * @return mixed The resolved value
         */
        private function resolve_toggle_setting($setting_key, $use_global_key, $link_id, $global_key, $system_default)
        {
            $use_global = Utils::get_meta($use_global_key, $link_id);

            $is_using_global = false;
            if (is_array($use_global) && in_array('1', $use_global)) {
                $is_using_global = true;
            } elseif ($use_global === '1' || $use_global === 1 || $use_global === true) {
                $is_using_global = true;
            } elseif ($use_global === null) {
                $is_using_global = true;
            }

            if ($is_using_global) {
                $global_value = $this->get_settings_value($global_key, $system_default);
                return $global_value ? '1' : '';
            }

            return Utils::get_meta($setting_key, $link_id);
        }

        /**
         * Get the expiration date for a link, checking global settings if needed.
         *
         * @param int $link_id The tinypress_link post ID.
         * @return string The expiration date, or empty string if not set.
         */
        private function get_expiration_date($link_id)
        {
            $use_global = Utils::get_meta('enable_expiration_use_global', $link_id);

            $is_using_global = false;
            if (is_array($use_global) && in_array('1', $use_global)) {
                $is_using_global = true;
            } elseif ($use_global === '1' || $use_global === 1 || $use_global === true) {
                $is_using_global = true;
            } elseif ($use_global === null) {
                $is_using_global = true;
            }

            if ($is_using_global) {
                return $this->get_settings_value('tinypress_global_expiration_date', '');
            }

            return Utils::get_meta('expiration_date', $link_id);
        }

        /**
         * Get the expiration time for a link, checking global settings if needed.
         *
         * @param int $link_id The tinypress_link post ID.
         * @return string The expiration time, or empty string if not set.
         */
        private function get_expiration_time($link_id)
        {
            $use_global = Utils::get_meta('enable_expiration_use_global', $link_id);

            $is_using_global = false;
            if (is_array($use_global) && in_array('1', $use_global)) {
                $is_using_global = true;
            } elseif ($use_global === '1' || $use_global === 1 || $use_global === true) {
                $is_using_global = true;
            } elseif ($use_global === null) {
                $is_using_global = true;
            }

            if ($is_using_global) {
                return $this->get_settings_value('tinypress_global_expiration_time', '');
            }

            return Utils::get_meta('expiration_time', $link_id);
        }

        /**
         * Build a manual preview URL for revisions when rvy_preview_url() fails.
         *
         * @param int $revision_id The revision post ID
         * @return string The constructed preview URL, or empty string on error
         */
        private function build_revision_preview_url($revision_id)
        {
            $revision = get_post($revision_id);
            if (! $revision) {
                $this->debug_log('build_revision_preview_url: revision post not found', array(
                    'revision_id' => $revision_id,
                ));
                return '';
            }

            $post_type = $revision->post_type;

            // Skip if not a revision post
            if (!function_exists('rvy_in_revision_workflow') || !rvy_in_revision_workflow($revision_id)) {
                $this->debug_log('build_revision_preview_url: not in revision workflow', array(
                    'revision_id' => $revision_id,
                    'post_type' => $post_type,
                ));
                return '';
            }

            $this->debug_log('build_revision_preview_url: building URL', array(
                'revision_id' => $revision_id,
                'post_type' => $post_type,
                'revision_title' => $revision->post_title,
            ));

            // Build the preview URL manually with required parameters
            $base_url = home_url('/');
            $preview_url = add_query_arg(
                array(
                    $post_type       => sanitize_title($revision->post_title),
                    'rv_preview'     => '1',
                    'page_id'        => $revision_id,
                    'post_type'      => $post_type,
                    'nc'             => md5(wp_rand()),
                ),
                $base_url
            );

            $this->debug_log('build_revision_preview_url: URL built', array(
                'preview_url' => $preview_url,
            ));

            return $preview_url;
        }

        /**
         * Helper method to log debug information
         *
         * @param string $message Debug message
         * @param array $data Additional data to log
         * @return void
         */
        private function debug_log($message, $data = array())
        {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                $log_message = '[TinyPress Shortlinks] ' . $message;
                if (!empty($data)) {
                    $log_message .= ' | ' . wp_json_encode($data);
                }
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging only when WP_DEBUG is enabled.
                error_log($log_message);
            }
        }

        /**
         * Get the post or revision ID requested by a visitor preview URL.
         *
         * @return int Requested post ID.
         */
        private function get_visitor_preview_post_id_from_request()
        {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only front-end preview request.
            if (! empty($_GET['page_id'])) {
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only front-end preview request.
                return absint(wp_unslash($_GET['page_id']));
            }

            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only front-end preview request.
            if (! empty($_GET['p'])) {
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only front-end preview request.
                return absint(wp_unslash($_GET['p']));
            }

            return 0;
        }

        /**
         * Resolve the current request path to a shortlink ID.
         *
         * @return int Shortlink post ID.
         */
        private function get_current_request_shortlink_id()
        {
            $uri = trim($this->get_request_uri(), '/');

            if (empty($uri)) {
                return 0;
            }

            $link_prefix      = Utils::get_option('tinypress_link_prefix');
            $link_prefix_slug = Utils::get_option('tinypress_link_prefix_slug', 'go');

            if ('1' == $link_prefix) {
                if ($uri !== $link_prefix_slug && strpos($uri, $link_prefix_slug . '/') !== 0) {
                    return 0;
                }

                $tiny_slug = preg_replace('#^' . preg_quote($link_prefix_slug, '#') . '/?#', '', $uri);
            } else {
                $tiny_slug = $uri;
            }

            $tiny_slug_parts = explode('?', $tiny_slug);
            $tiny_slug       = $tiny_slug_parts[0] ?? '';

            if (empty($tiny_slug)) {
                return 0;
            }

            return absint(tinypress()->tiny_slug_to_post_id($tiny_slug));
        }

        /**
         * Get post ID from a URL by permalink or query args.
         *
         * @param string $url URL to inspect.
         * @return int Post ID.
         */
        private function get_post_id_from_url($url)
        {
            if (empty($url)) {
                return 0;
            }

            // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.url_to_postid_url_to_postid -- Not a VIP environment; url_to_postid is standard WP core function.
            $post_id = absint(url_to_postid($url));

            if ($post_id) {
                return $post_id;
            }

            $url_parts = wp_parse_url($url);
            if (empty($url_parts['query'])) {
                return 0;
            }

            parse_str($url_parts['query'], $query_vars);

            if (! empty($query_vars['page_id'])) {
                return absint($query_vars['page_id']);
            }

            if (! empty($query_vars['p'])) {
                return absint($query_vars['p']);
            }

            return 0;
        }

        /**
         * Get statuses allowed for front-end shortlink previews.
         *
         * @return array Allowed statuses.
         */
        private function get_allowed_preview_statuses()
        {
            $allowed_statuses = $this->get_settings_value('tinypress_allowed_post_statuses', array());

            if (! is_array($allowed_statuses) || empty($allowed_statuses)) {
                return array();
            }

            if (in_array('draft-revision', $allowed_statuses, true)) {
                $all_revision_statuses = array( 'draft-revision', 'pending-revision', 'future-revision', 'revision-deferred', 'revision-needs-work', 'revision-rejected' );
                $allowed_statuses      = array_unique(array_merge($allowed_statuses, $all_revision_statuses));
            }

            return array_map('sanitize_key', $allowed_statuses);
        }

        /**
         * Check whether the requested post status is allowed for visitor previews.
         *
         * @param int $post_id Post or revision ID.
         * @return bool True if allowed.
         */
        private function is_preview_post_status_allowed($post_id)
        {
            $post = get_post($post_id);

            if (! $post) {
                return false;
            }

            if (function_exists('rvy_in_revision_workflow') && rvy_in_revision_workflow($post_id) && ! defined('PUBLISHPRESS_STATUSES_PRO_VERSION')) {
                return '1' == $this->get_settings_value('tinypress_revision_visitor_access', '1');
            }

            $status = $post->post_status;

            if (function_exists('rvy_in_revision_workflow') && rvy_in_revision_workflow($post_id) && ! empty($post->post_mime_type)) {
                $status = $post->post_mime_type;
            }

            return in_array(sanitize_key($status), $this->get_allowed_preview_statuses(), true);
        }

        /**
         * Create a signed visitor preview token for a shortlink/post pair.
         *
         * @param int $link_id Shortlink ID.
         * @param int $post_id Post or revision ID.
         * @return string Signed token.
         */
        private function create_visitor_preview_token($link_id, $post_id)
        {
            return wp_hash('tinypress_visitor_preview|' . absint($link_id) . '|' . absint($post_id));
        }

        /**
         * Check whether a shortlink record can currently be used for previews.
         *
         * @param int $link_id Shortlink ID.
         * @return bool True if active and not expired.
         */
        private function is_shortlink_record_active_for_preview($link_id)
        {
            if ('tinypress_link' !== get_post_type($link_id)) {
                return true;
            }

            if ('1' != Utils::get_meta('link_status', $link_id, '1')) {
                return false;
            }

            $enable_expiration = $this->resolve_toggle_setting(
                'enable_expiration',
                'enable_expiration_use_global',
                $link_id,
                'tinypress_global_enable_expiration',
                false
            );

            $expiration_date = $this->get_expiration_date($link_id);

            if ('1' != $enable_expiration || empty($expiration_date)) {
                return true;
            }

            $expiration_time = $this->get_expiration_time($link_id);

            if (! empty($expiration_time)) {
                $expiration_timestamp = DateTime::createFromFormat('d-m-Y g:i A', $expiration_date . ' ' . $expiration_time);
            } elseif (strpos($expiration_date, ' ') !== false) {
                $expiration_timestamp = DateTime::createFromFormat('d-m-Y H:i', $expiration_date);
            } else {
                $expiration_timestamp = DateTime::createFromFormat('d-m-Y H:i:s', $expiration_date . ' 23:59:59');
            }

            if (! $expiration_timestamp) {
                return true;
            }

            return new DateTime(current_time('Y-m-d H:i:s')) <= $expiration_timestamp;
        }

        /**
         * Check whether a shortlink record is allowed to preview a post/revision.
         *
         * @param int $link_id Shortlink ID.
         * @param int $post_id Post or revision ID.
         * @return bool True if the shortlink owns this preview.
         */
        private function shortlink_allows_preview_post($link_id, $post_id)
        {
            $link_id = absint($link_id);
            $post_id = absint($post_id);

            if (! $link_id || ! $post_id) {
                return false;
            }

            if (! $this->is_shortlink_record_active_for_preview($link_id)) {
                return false;
            }

            if ('tinypress_link' !== get_post_type($link_id)) {
                return $link_id === $post_id && function_exists('rvy_in_revision_workflow') && rvy_in_revision_workflow($post_id);
            }

            $source_post_id = absint(get_post_meta($link_id, 'source_post_id', true));

            if ($source_post_id && $source_post_id === $post_id) {
                return true;
            }

            $target_post_id = $this->get_post_id_from_url((string) Utils::get_meta('target_url', $link_id));

            return $target_post_id && $target_post_id === $post_id;
        }

        /**
         * Validate a visitor preview request before broadening query visibility.
         *
         * @param int $post_id Optional post/revision ID.
         * @return bool True when the preview is backed by a valid shortlink.
         */
        private function is_valid_visitor_preview_request($post_id = 0)
        {
            $post_id = $post_id ? absint($post_id) : $this->get_visitor_preview_post_id_from_request();

            if (! $post_id || ! $this->is_preview_post_status_allowed($post_id)) {
                return false;
            }

            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only signed front-end preview request.
            $request_link_id = ! empty($_GET['tinypress_link']) ? absint(wp_unslash($_GET['tinypress_link'])) : 0;
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only signed front-end preview request.
            $request_token = ! empty($_GET['tinypress_preview_token']) ? sanitize_text_field(wp_unslash($_GET['tinypress_preview_token'])) : '';

            if ($request_link_id && $request_token) {
                $expected_token = $this->create_visitor_preview_token($request_link_id, $post_id);

                return hash_equals($expected_token, $request_token) && $this->shortlink_allows_preview_post($request_link_id, $post_id);
            }

            $current_link_id = $this->get_current_request_shortlink_id();

            return $current_link_id && $this->shortlink_allows_preview_post($current_link_id, $post_id);
        }

        /**
         * Validate a visitor preview request for revision-specific hooks.
         *
         * @param int $post_id Optional revision post ID.
         * @return bool True when the request is for a valid shortlink-backed revision.
         */
        private function is_valid_revision_preview_request($post_id = 0)
        {
            $post_id = $post_id ? absint($post_id) : $this->get_visitor_preview_post_id_from_request();

            if (! $post_id || ! function_exists('rvy_in_revision_workflow') || ! rvy_in_revision_workflow($post_id)) {
                return false;
            }

            return $this->is_valid_visitor_preview_request($post_id);
        }

        /**
         * Add signed visitor preview args to a URL.
         *
         * @param string $url URL to sign.
         * @param int    $link_id Shortlink ID.
         * @param int    $post_id Post or revision ID.
         * @return string Signed preview URL.
         */
        private function add_visitor_preview_auth_args($url, $link_id, $post_id)
        {
            return add_query_arg(
                array(
                    'tinypress_visitor'       => '1',
                    'tinypress_link'          => absint($link_id),
                    'tinypress_preview_token' => $this->create_visitor_preview_token($link_id, $post_id),
                ),
                $url
            );
        }

        public function prevent_elementor_homepage_redirect()
        {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query flag used for visitor previews.
            if (empty($_GET['tinypress_visitor']) || ! $this->is_valid_revision_preview_request()) {
                return;
            }

            global $wp_filter;

            if (!isset($wp_filter['template_redirect'])) {
                return;
            }

            foreach ($wp_filter['template_redirect']->callbacks as $priority => $callbacks) {
                foreach ($callbacks as $hook_name => $callback_item) {
                    $callback = $callback_item['function'];

                    if (is_array($callback)) {
                        $obj = $callback[0];
                        $method = $callback[1] ?? '';

                        $is_source_local = (is_object($obj) && get_class($obj) === 'Elementor\TemplateLibrary\Source_Local') ||
                                         (is_string($obj) && $obj === 'Elementor\TemplateLibrary\Source_Local');

                        if ($is_source_local && $method === 'block_template_frontend') {
                            remove_action('template_redirect', $callback, $priority);
                            return;
                        }
                    }
                }
            }
        }

        /**
         * Bust cache for visitor previews to ensure they see the true live state.
         * Some cache plugins (WP Rocket, Cloudflare, etc) may cache preview URLs,
         * so we aggressively prevent caching to ensure visitors see actual content.
         *
         * @return void
         */
        public function bust_cache_for_visitor_preview()
        {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query flag used for visitor previews.
            if (empty($_GET['tinypress_visitor']) || ! $this->is_valid_revision_preview_request()) {
                return;
            }

            // Prevent WP Rocket from caching this page
            if (! defined('DONOTCACHEPAGE')) {
                define('DONOTCACHEPAGE', true);
            }

            // Prevent SG Optimizer from caching
            if (! defined('SG_CACHE_DO_NOT_CACHE')) {
                define('SG_CACHE_DO_NOT_CACHE', true);
            }

            // Set headers to prevent browser and intermediary caching
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, post-check=0, pre-check=0', true);
            header('Pragma: no-cache', true);
            header('Expires: ' . gmdate('D, d M Y H:i:s \G\M\T', time() - 86400), true);

            // Add timestamp to prevent any client-side caching
            header('X-Cache-Busted: ' . microtime(true), true);
        }

        /**
         * Enable frontend preview URLs for non-public post types
         * when accessed via TinyPress visitor shortlinks.
         *
         * PublishPress Revisions normally blocks frontend previews for non-public post types,
         * returning admin revision comparison URLs instead. This filter tells it
         * to allow frontend previews when visitors access via our shortlinks.
         *
         * @param bool $use_preview_url Current filter value
         * @param object $revision The revision post object
         * @return bool True to use frontend preview URL, false for admin URL
         */
        public function enable_private_type_frontend_preview($use_preview_url, $revision)
        {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query flag used for visitor previews.
            if (!empty($_GET['tinypress_visitor']) && ! empty($revision->ID) && $this->is_valid_revision_preview_request($revision->ID)) {
                return true;
            }

            return $use_preview_url;
        }

        /**
         * Inject preview_others_revisions capability for visitors accessing revision shortlinks.
         *
         * @return void
         */
        public function inject_visitor_preview_capability()
        {
            global $current_user;

            $has_visitor_flag = !empty($_GET['tinypress_visitor']) || !empty($_REQUEST['tinypress_visitor']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $has_preview_param = !empty($_GET['rv_preview']) || !empty($_GET['preview']) || !empty($_REQUEST['rv_preview']) || !empty($_REQUEST['preview']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

            if (!$has_visitor_flag || !$has_preview_param) {
                return;
            }

            if (! $this->is_valid_revision_preview_request()) {
                return;
            }

            $current_user = wp_get_current_user();

            if (0 !== $current_user->ID) {
                return;
            }

            if (!isset($current_user->allcaps) || !is_array($current_user->allcaps)) {
                $current_user->allcaps = array();
            }

            $current_user->allcaps['preview_others_revisions'] = 1;
        }

        /**
         * Do the redirection
         *
         * @param $link_id
         *
         * @return void
         */
        public function do_redirection($link_id)
        {

            // Hook for Pro to execute before redirection tracking
            do_action('tinypress_before_redirect_track', $link_id);

            $tags       = array();
            $target_url = Utils::get_meta('target_url', $link_id);

            $is_revision_redirect = false;
            $revision_post_id    = 0;

            if ('tinypress_link' == get_post_type($link_id)) {
                // Case 1: $link_id is a tinypress_link entry — check if it's a revision link
                $is_revision_link = get_post_meta($link_id, 'is_revision_link', true);
                if (! $is_revision_link && function_exists('rvy_in_revision_workflow')) {
                    $source_post_id_check = absint(get_post_meta($link_id, 'source_post_id', true));
                    if ($source_post_id_check && rvy_in_revision_workflow($source_post_id_check)) {
                        $is_revision_link = '1';
                        update_post_meta($link_id, 'is_revision_link', '1');
                    }
                }
                if ($is_revision_link) {
                    $revision_post_id = absint(get_post_meta($link_id, 'source_post_id', true));
                }
            } elseif (function_exists('rvy_in_revision_workflow') && rvy_in_revision_workflow($link_id)) {
                // Case 2: $link_id is a revision post directly (no tinypress_link entry)
                $revision_post_id = $link_id;
            }

            if ($revision_post_id && function_exists('rvy_preview_url')) {
                $revision_post = get_post($revision_post_id);

                if ($revision_post) {
                    $can_view_revision = false;
                    if (is_user_logged_in()) {
                        $current_user_id = get_current_user_id();
                        $parent_post_id = wp_get_post_parent_id($revision_post_id);
                        $check_post_id = $parent_post_id ? $parent_post_id : $revision_post_id;
                        if ($current_user_id == $revision_post->post_author || current_user_can('edit_post', $check_post_id)) {
                            $can_view_revision = true;
                        }
                    }

                    if (! $can_view_revision) {
                        $visitor_access = Utils::get_option('tinypress_revision_visitor_access', '1');

                        if ('1' != $visitor_access) {
                            $this->set_shortlink_404();
                            return false;
                        }

                        $has_revision_statuses = defined('PUBLISHPRESS_STATUSES_PRO_VERSION');

                        if ($has_revision_statuses) {
                            $revision_status = $revision_post->post_mime_type;
                            if (empty($revision_status)) {
                                $revision_status = get_post_status($revision_post_id);
                            }

                            $allowed_statuses = $this->get_settings_value('tinypress_allowed_post_statuses', array());

                            if (! is_array($allowed_statuses) || empty($allowed_statuses)) {
                                $allowed_statuses = array();
                            }

                            if (! in_array($revision_status, $allowed_statuses)) {
                                $this->set_shortlink_404();
                                return false;
                            }
                        }
                        // If PP Statuses Pro is NOT active, visitor_access
                        // is the sole determinant — already checked above, so allow through.
                    }

                    $this->debug_log('REVISION DETECTED', array(
                        'revision_post_id' => $revision_post_id,
                        'revision_post_type' => $revision_post->post_type,
                        'is_logged_in' => is_user_logged_in(),
                        'stored_target_url' => $target_url,
                    ));

                    if (is_user_logged_in()) {
                        $preview_url = rvy_preview_url($revision_post_id);
                        $this->debug_log('LOGGED-IN USER: rvy_preview_url result', array(
                            'preview_url' => $preview_url,
                            'is_empty' => empty($preview_url),
                        ));

                        if (! empty($preview_url)) {
                            $target_url = $preview_url;
                            $is_revision_redirect = true;
                        } else {
                            // Fallback for non-public post types
                            $preview_url = $this->build_revision_preview_url($revision_post_id);
                            $this->debug_log('LOGGED-IN USER: manual preview URL fallback', array(
                                'preview_url' => $preview_url,
                                'is_empty' => empty($preview_url),
                            ));

                            if (! empty($preview_url)) {
                                $target_url = $preview_url;
                                $is_revision_redirect = true;
                            }
                        }
                    } else {
                        $preview_url = rvy_preview_url($revision_post_id);

                        if (! empty($preview_url)) {
                            $target_url = $preview_url;
                            if (! is_user_logged_in()) {
                                $target_url = $this->add_visitor_preview_auth_args($target_url, $link_id, $revision_post_id);
                            }
                            $is_revision_redirect = true;
                        } else {
                            $preview_url = $this->build_revision_preview_url($revision_post_id);

                            if (! empty($preview_url)) {
                                $target_url = $preview_url;
                                if (! is_user_logged_in()) {
                                    $target_url = $this->add_visitor_preview_auth_args($target_url, $link_id, $revision_post_id);
                                }
                                $is_revision_redirect = true;
                            }
                        }
                    }
                }
            }

            $post_to_check = $link_id;
            $is_tinypress_link = ('tinypress_link' == get_post_type($link_id));

            if (! $is_revision_redirect && ! empty($target_url) && $is_tinypress_link) {
                // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.url_to_postid_url_to_postid -- Not a VIP environment; url_to_postid is standard WP core function
                $extracted_post_id = url_to_postid($target_url);

                // If url_to_postid fails, try parsing query string for ?p= or ?page_id= format
                if (! $extracted_post_id) {
                    $url_parts = wp_parse_url($target_url);
                    if (isset($url_parts['query'])) {
                        parse_str($url_parts['query'], $query_vars);
                        if (isset($query_vars['p'])) {
                            $extracted_post_id = intval($query_vars['p']);
                        } elseif (isset($query_vars['page_id'])) {
                            $extracted_post_id = intval($query_vars['page_id']);
                        }
                    }
                }

                if (! $extracted_post_id) {
                    $source_post_id = absint(get_post_meta($link_id, 'source_post_id', true));
                    if ($source_post_id) {
                        $extracted_post_id = $source_post_id;
                    }
                }

                if ($extracted_post_id) {
                    $post_to_check = $extracted_post_id;
                }
            }

            $should_check_status = false;

            if (! $is_revision_redirect) {
                if ($is_tinypress_link && $post_to_check != $link_id) {
                    // tinypress_link entry pointing to a source post
                    $should_check_status = true;
                } elseif (! $is_tinypress_link && empty($target_url)) {
                    // Native post accessed directly via shortlink slug
                    $should_check_status = true;
                }
            }

            if ($should_check_status) {
                $post_status = get_post_status($post_to_check);
                $post_object = get_post($post_to_check);

                if (! $post_object) {
                    $this->debug_log('STATUS CHECK: Post object not found', array('post_id' => $post_to_check));
                } else {
                    $can_view_post = false;
                    if (is_user_logged_in()) {
                        $current_user_id = get_current_user_id();
                        if ($current_user_id == $post_object->post_author || current_user_can('edit_post', $post_to_check)) {
                            $can_view_post = true;
                        }
                    }

                    if (! $can_view_post) {
                        $allowed_statuses = $this->get_settings_value('tinypress_allowed_post_statuses', array());

                        if (! is_array($allowed_statuses) || empty($allowed_statuses)) {
                            $allowed_statuses = array();
                        }

                        if (in_array('draft-revision', $allowed_statuses)) {
                            $all_revision_statuses = array( 'draft-revision', 'pending-revision', 'future-revision', 'revision-deferred', 'revision-needs-work', 'revision-rejected' );
                            $allowed_statuses = array_unique(array_merge($allowed_statuses, $all_revision_statuses));
                        }

                        $is_status_allowed = in_array($post_status, $allowed_statuses);

                        if (! $is_status_allowed) {
                            $this->set_shortlink_404();
                            return false;
                        }
                    }

                    if ($post_status !== 'publish') {
                        $this->display_non_published_post($post_to_check);
                        die();
                    }
                }

                if (empty($target_url) && ! $is_tinypress_link) {
                    $target_url = get_permalink($link_id);
                }
            }

            $redirection_method = $this->resolve_dropdown_setting(
                'redirection_method',
                $link_id,
                'tinypress_global_redirection_method',
                302
            );

            $no_follow = $this->resolve_toggle_setting(
                'redirection_no_follow',
                'redirection_no_follow_use_global',
                $link_id,
                'tinypress_global_no_follow',
                true
            );

            $sponsored = $this->resolve_toggle_setting(
                'redirection_sponsored',
                'redirection_sponsored_use_global',
                $link_id,
                'tinypress_global_sponsored',
                false
            );

            $parameter_forwarding = $this->resolve_toggle_setting(
                'redirection_parameter_forwarding',
                'redirection_parameter_forwarding_use_global',
                $link_id,
                'tinypress_global_parameter_forwarding',
                false
            );

            if ('1' == $parameter_forwarding) {
                $parameters = wp_unslash($_GET); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Front-end redirect; forwarding URL query parameters to target

                if (isset($parameters['password'])) {
                    unset($parameters['password']);
                }

                if (! empty($parameters)) {
                    $target_url = $target_url . '?' . http_build_query($parameters);
                }
            }

            if ('1' == $no_follow) {
                $tags[] = 'noindex';
                $tags[] = 'nofollow';
            }

            if ('1' == $sponsored) {
                $tags[] = 'sponsored';
            }

            if (! empty($tags)) {
                header('X-Robots-Tag: ' . implode(', ', $tags), true);
            }

            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Cache-Control: post-check=0, pre-check=0', false);
            header('Pragma: no-cache');
            header('Expires: Mon, 10 Oct 1975 08:09:15 GMT');
            header('X-Redirect-Powered-By: TinyPress ' . TINYPRESS_PLUGIN_VERSION . ' https://pluginbazar.com');

            // Hook for Pro to execute after redirection headers
            do_action('tinypress_after_redirect_headers', $link_id, $target_url, $redirection_method);

            $this->debug_log('FINAL REDIRECT', array(
                'link_id' => $link_id,
                'target_url' => $target_url,
                'redirection_method' => $redirection_method,
                'is_revision_redirect' => $is_revision_redirect,
                'is_user_logged_in' => is_user_logged_in(),
            ));

            header('Location: ' . esc_url_raw($target_url), true, $redirection_method);

            die();
        }


        /**
         * Display non-published post directly without redirecting
         *
         * @param int $link_id
         * @return void
         */
        protected function display_non_published_post($link_id)
        {
            global $wp_query, $post;

            // Get the post
            $post = get_post($link_id);

            if (! $post) {
                wp_die(esc_html__('Post not found.', 'tinypress'));
            }

            if ($this->should_show_non_public_notice($post)) {
                $this->non_public_notice_post = $post;
                $this->enqueue_non_public_notice_assets();
                add_action('wp_body_open', array( $this, 'render_non_public_notice' ), 1);
            }

            // Set up the query to display this post
            $wp_query->is_single = true;
            $wp_query->is_singular = true;
            $wp_query->is_404 = false;
            $wp_query->queried_object = $post;
            $wp_query->queried_object_id = $post->ID;
            $wp_query->posts = array( $post );
            $wp_query->post = $post;
            $wp_query->post_count = 1;

            setup_postdata($post);

            status_header(200);
            $template = get_query_template('single');
            if (! $template && function_exists('get_single_template')) {
                $template = get_single_template();
            }

            if (! $template && function_exists('get_singular_template')) {
                $template = get_singular_template();
            }

            if (! $template && function_exists('get_index_template')) {
                $template = get_index_template();
            }

            if ($template) {
                include($template);
            } else {
                wp_die(esc_html($post->post_title), esc_html($post->post_title), array( 'response' => 200 ));
            }
        }

        private function set_shortlink_404()
        {
            global $wp_query, $post;

            if ($wp_query instanceof WP_Query) {
                $wp_query->set_404();
                $wp_query->posts             = array();
                $wp_query->post              = null;
                $wp_query->post_count        = 0;
                $wp_query->queried_object    = null;
                $wp_query->queried_object_id = 0;
            }

            $post = null;
            $this->shortlink_force_404 = true;

            status_header(404);
            nocache_headers();
        }

        private function should_show_non_public_notice($post)
        {
            if (! $post instanceof WP_Post) {
                return false;
            }

            $post_status = get_post_status($post);

            if ('publish' === $post_status) {
                return false;
            }

            $enabled = $this->get_settings_value('tinypress_non_public_notice_enabled', '1');

            if ('1' !== (string) $enabled) {
                return false;
            }

            $enabled_statuses = $this->get_settings_value('tinypress_non_public_notice_statuses', array());

            if (! is_array($enabled_statuses)) {
                $enabled_statuses = array_filter((array) $enabled_statuses);
            }

            if (empty($enabled_statuses) && function_exists('tinypress_get_non_public_notice_default_statuses')) {
                $enabled_statuses = tinypress_get_non_public_notice_default_statuses();
            }

            return in_array($post_status, $enabled_statuses, true);
        }

        private function enqueue_non_public_notice_assets()
        {
            wp_enqueue_style(
                'tinypress-frontend',
                TINYPRESS_PLUGIN_URL . 'assets/frontend/css/frontend.css',
                array(),
                TINYPRESS_PLUGIN_VERSION
            );
        }

        public function render_non_public_notice()
        {
            if ($this->non_public_notice_rendered || ! $this->non_public_notice_post) {
                return;
            }

            $this->non_public_notice_rendered = true;

            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML is escaped by get_non_public_notice_html().
            echo $this->get_non_public_notice_html();
        }

        private function get_non_public_notice_html()
        {
            $post = $this->non_public_notice_post;
            $status = get_post_status($post);
            $message = $this->get_non_public_notice_message($post);
            $color = $this->get_non_public_notice_color($status);

            return sprintf(
                '<div id="tinypress-non-public-notice" class="tinypress-non-public-notice tinypress-non-public-notice-%1$s" style="--tinypress-non-public-notice-bg:%2$s;" role="status"><div class="tinypress-non-public-notice__inner">%3$s</div></div>',
                esc_attr(sanitize_html_class($status)),
                esc_attr($color),
                wp_kses_post($message)
            );
        }

        private function get_non_public_notice_message($post)
        {
            $status = get_post_status($post);
            $messages = $this->get_settings_value('tinypress_non_public_status_messages', array());
            $default_messages = function_exists('tinypress_get_non_public_notice_default_messages')
                ? tinypress_get_non_public_notice_default_messages()
                : array();

            if (! is_array($messages)) {
                $messages = array();
            }

            $message = isset($messages[$status]) && '' !== trim($messages[$status])
                ? $messages[$status]
                : (isset($default_messages[$status]) ? $default_messages[$status] : esc_html__('This post is in {status} status. It is not visible to the public.', 'tinypress'));

            $date_format = get_option('date_format');
            $time_format = get_option('time_format');
            $timestamp = get_post_time('U', false, $post);
            $date = $timestamp ? date_i18n($date_format . ' ' . $time_format, $timestamp) : '';
            $status_label = function_exists('tinypress_get_post_status_display_label')
                ? tinypress_get_post_status_display_label($status)
                : ucfirst(str_replace(array( '-', '_' ), ' ', $status));

            $replacements = array(
                '{status}' => esc_html($status_label),
                '{date}'   => esc_html($date),
                '{title}'  => esc_html(get_the_title($post)),
            );

            $message = strtr($message, $replacements);

            return apply_filters('tinypress_non_public_notice_message', $message, $post, $status);
        }

        private function get_non_public_notice_color($status)
        {
            $colors = array(
                'draft'   => '#999999',
                'pending' => '#35b194',
                'private' => '#635b93',
                'future'  => '#799999',
            );

            $color = isset($colors[$status]) ? $colors[$status] : '#35b194';

            return apply_filters('tinypress_non_public_notice_color', $color, $status, $this->non_public_notice_post);
        }


        /**
         * Display a brief notice page before redirecting expired links.
         *
         * @param string $redirect_url The URL to redirect to.
         * @return void
         */
        protected function display_expired_notice_page($redirect_url, $notice_title = '', $notice_message = '', $cta_text = '')
        {
            status_header(200);
            $safe_url = esc_url($redirect_url);

            if ($notice_title === '') {
                $notice_title = esc_html__('This link has expired', 'tinypress');
            }

            if ($notice_message === '') {
                $notice_message = esc_html__('You will be redirected shortly.', 'tinypress');
            }

            if ($cta_text === '') {
                $cta_text = esc_html__('Click here if you are not redirected', 'tinypress');
            }

            $allowed_html = wp_kses_allowed_html('post');
            foreach ($allowed_html as $tag => $attrs) {
                if (! is_array($attrs)) {
                    $attrs = array();
                }
                $attrs['style'] = true;
                $allowed_html[$tag] = $attrs;
            }
            ?>
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="utf-8">
                <meta name="robots" content="noindex, nofollow">
                <title><?php esc_html_e('Link Expired', 'tinypress'); ?></title>
                <meta http-equiv="refresh" content="3;url=<?php echo esc_url($safe_url); ?>">
                <style>
                    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; background: #f0f0f1; color: #3c434a; }
                    .notice-box { text-align: center; background: #fff; padding: 40px 50px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,.1); max-width: 480px; }
                    .notice-title { font-size: 22px; font-weight: 600; margin: 0 0 16px; color: #1e1e1e; }
                    .notice-message { font-size: 14px; color: #646970; margin: 0 0 24px; line-height: 1.6; }
                    .notice-box a { display: inline-block; padding: 10px 20px; background-color: #2271b1; color: #fff; text-decoration: none; font-weight: 500; border-radius: 4px; margin-top: 8px; }
                    .notice-box a:hover { background-color: #135e96; }
                </style>
            </head>
            <body>
                <div class="notice-box">
                    <div class="notice-title"><?php echo wp_kses($notice_title, $allowed_html); ?></div>
                    <div class="notice-message"><?php echo wp_kses($notice_message, $allowed_html); ?></div>
                    <a href="<?php echo esc_url($safe_url); ?>"><?php echo esc_html($cta_text); ?></a>
                </div>
            </body>
            </html>
            <?php
        }


        /**
         * Track the redirection
         *
         * @param $link_id
         *
         * @return void
         */
        public function track_redirection($link_id)
        {

            global $wpdb;

            if (is_user_logged_in()) {
                $current_user_id = get_current_user_id();
                $post = get_post($link_id);

                if ($post && ($current_user_id == $post->post_author || current_user_can('edit_post', $link_id))) {
                    return;
                }
            }

            $get_ip_address = tinypress_get_ip_address();
            $curr_user_id   = is_user_logged_in() ? get_current_user_id() : 0;

            // Prevent duplicate tracking: check if this IP already tracked this link in the last 60 seconds
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom table; TINYPRESS_TABLE_REPORTS is a safe constant; time-sensitive query not suitable for caching
            $recent_track = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM " . TINYPRESS_TABLE_REPORTS . "
				WHERE post_id = %d 
				AND user_ip = %s 
				AND datetime > DATE_SUB(NOW(), INTERVAL 60 SECOND)",
                $link_id,
                $get_ip_address
            ));
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

            if ($recent_track > 0) {
                return;
            }

            $location_info  = array(
                'geoplugin_city'          => null,
                'geoplugin_region'        => null,
                'geoplugin_regionName'    => null,
                'geoplugin_countryCode'   => null,
                'geoplugin_countryName'   => null,
                'geoplugin_continentName' => null,
                'geoplugin_latitude'      => null,
                'geoplugin_longitude'     => null,
            );

            // Try to get geolocation data, but don't fail if it's unavailable
            // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get -- Not a VIP environment; standard WP function is appropriate
            $response = wp_remote_get('https://www.geoplugin.net/json.gp?ip=' . urlencode($get_ip_address));

            if (! is_wp_error($response) && 200 === wp_remote_retrieve_response_code($response)) {
                $get_user_data = wp_remote_retrieve_body($response);
                if ($get_user_data) {
                    $user_data     = json_decode($get_user_data, true);
                    $location_keys = array_keys($location_info);
                    $location_info = array_merge($location_info, array_intersect_key($user_data, array_flip($location_keys)));
                }
            }

            $location_info['user_agent'] = function_exists('wp_get_user_agent') ? sanitize_text_field(wp_get_user_agent()) : '';

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table insert for tracking; no caching needed for write operations
            $wpdb->insert(
                TINYPRESS_TABLE_REPORTS,
                array(
                    'user_id'       => $curr_user_id,
                    'post_id'       => $link_id,
                    'user_ip'       => $get_ip_address,
                    'user_location' => wp_json_encode($location_info),
                ),
                array( '%d', '%d', '%s', '%s' )
            );

            do_action('tinypress_after_redirect_track', $link_id);
        }


        /**
         * Check security protocols for the redirection
         *
         * @param $link_id
         *
         * @return void
         */
        public function check_protection($link_id)
        {

            $current_url          = site_url($this->get_request_uri());

            $password_protection  = $this->resolve_toggle_setting(
                'password_protection',
                'password_protection_use_global',
                $link_id,
                'tinypress_global_password_protection',
                false
            );

            $link_password = Utils::get_meta('link_password', $link_id);
            if (empty($link_password)) {
                $link_password = $this->get_settings_value('tinypress_global_link_password', '');
            }

            $enable_expiration = $this->resolve_toggle_setting(
                'enable_expiration',
                'enable_expiration_use_global',
                $link_id,
                'tinypress_global_enable_expiration',
                false
            );

            $expiration_date      = $this->get_expiration_date($link_id);
            $link_status          = Utils::get_meta('link_status', $link_id, '1');
            $password_check_nonce = wp_create_nonce('password_check');

            if (wp_parse_url($current_url, PHP_URL_QUERY)) {
                $password_checked_url = $current_url . '&password=' . $password_check_nonce;
            } else {
                $password_checked_url = $current_url . '?password=' . $password_check_nonce;
            }

            if ('tinypress_link' == get_post_type($link_id)) {
                // Check if the link status is enabled
                if ('1' != $link_status) {
                    wp_die(esc_html__('This link is not active.', 'tinypress'));
                }

                // Check if the link is expired or not
                if ('1' == $enable_expiration && ! empty($expiration_date)) {
                    $expiration_time = $this->get_expiration_time($link_id);

                    if (! empty($expiration_time)) {
                        $expiration_timestamp = DateTime::createFromFormat('d-m-Y g:i A', $expiration_date . ' ' . $expiration_time);
                    } elseif (strpos($expiration_date, ' ') !== false) {
                        $expiration_timestamp = DateTime::createFromFormat('d-m-Y H:i', $expiration_date);
                    } else {
                        $expiration_timestamp = DateTime::createFromFormat('d-m-Y H:i:s', $expiration_date . ' 23:59:59');
                    }

                    $now = new DateTime(current_time('Y-m-d H:i:s'));

                    if ($expiration_timestamp && $now > $expiration_timestamp) {
                        $expired_redirect_url = apply_filters('tinypress_link_expired_redirect', '', $link_id);

                        if (! empty($expired_redirect_url)) {
                            $show_notice = apply_filters('tinypress_link_expired_show_notice', false, $link_id);

                            if ($show_notice) {
                                $notice_title = apply_filters('tinypress_link_expired_notice_title', '', $link_id);
                                $notice_message = apply_filters('tinypress_link_expired_notice_message', '', $link_id);
                                $cta_text = apply_filters('tinypress_link_expired_notice_cta_text', '', $link_id);

                                $this->display_expired_notice_page($expired_redirect_url, $notice_title, $notice_message, $cta_text);
                                die();
                            }

                            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
                            header('Location: ' . esc_url_raw($expired_redirect_url), true, 302);
                            die();
                        }

                        wp_die(esc_html__('This link is expired.', 'tinypress'));
                    }
                }
            }

            // If password protection is not enabled, redirect directly
            if ('1' != $password_protection) {
                return $this->redirect_url($link_id);
            }

            // Password protection is enabled — check if password was submitted
            $error_message = '';
            if (isset($_POST['tinypress_password']) && isset($_POST['tinypress_pw_nonce'])) {
                $submitted_nonce = sanitize_text_field(wp_unslash($_POST['tinypress_pw_nonce']));
                $submitted_password = sanitize_text_field(wp_unslash($_POST['tinypress_password']));

                if (wp_verify_nonce($submitted_nonce, 'tinypress_password_check_' . $link_id) && hash_equals((string) $link_password, (string) $submitted_password)) {
                    return $this->redirect_url($link_id);
                } else {
                    $error_message = esc_html__('Incorrect password. Please try again.', 'tinypress');
                }
            }

            // Show password form
            status_header(200);
            $form_nonce = wp_create_nonce('tinypress_password_check_' . $link_id);
            $css_url = plugin_dir_url(TINYPRESS_FILE) . 'assets/admin/css/style.css';
            ?>
            <!DOCTYPE html>
            <html <?php language_attributes(); ?>>
            <head>
                <meta charset="<?php bloginfo('charset'); ?>">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <meta name="robots" content="noindex, nofollow">
                <title><?php esc_html_e('Password Protected Link', 'tinypress'); ?></title>
                <link rel="stylesheet" href="<?php echo esc_url($css_url); ?>">
            </head>
            <body class="tinypress-password-page">
                <div class="tinypress-password-form">
                    <h2><?php esc_html_e('Password Protected Link', 'tinypress'); ?></h2>
                    <?php if (! empty($error_message)) : ?>
                        <div class="tinypress-password-error"><?php echo esc_html($error_message); ?></div>
                    <?php endif; ?>
                    <form method="post" action="">
                        <input type="hidden" name="tinypress_pw_nonce" value="<?php echo esc_attr($form_nonce); ?>">
                        <label for="tinypress_password"><?php esc_html_e('Enter password to continue:', 'tinypress'); ?></label>
                        <input type="password" name="tinypress_password" id="tinypress_password" required autofocus>
                        <button type="submit"><?php esc_html_e('Submit', 'tinypress'); ?></button>
                    </form>
                </div>
            </body>
            </html>
            <?php

            die();
        }


        /**
         * Redirect to target URL
         *
         * @return void
         */
        public function redirect_url($link_id)
        {

            // Fire action before tracking to allow auto-list links to be created first
            do_action('tinypress_before_redirect_track', $link_id);

            // After the action, check if a tinypress_link entry was created for this post
            // If so, use that ID for tracking instead of the source post ID
            $tracking_id = $link_id;
            if (get_post_type($link_id) !== 'tinypress_link') {
                global $wpdb;
                // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cross-table join lookup; not cacheable via standard WP functions
                $tinypress_link_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT pm.post_id FROM {$wpdb->postmeta} pm
					INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
					WHERE pm.meta_key = %s
					AND pm.meta_value = %d
					AND p.post_type = %s",
                    'source_post_id',
                    $link_id,
                    'tinypress_link'
                ));
                // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                if ($tinypress_link_id) {
                    $tracking_id = (int) $tinypress_link_id;
                }
            }

            $this->track_redirection($tracking_id);

            return $this->do_redirection($link_id);
        }


        /**
         * Redirection Controller
         *
         * @return void
         */
        public function redirection_controller()
        {
            // Guard: only run once
            if ($this->redirection_done) {
                return;
            }

            if (is_admin()) {
                return;
            }

            $link_prefix      = Utils::get_option('tinypress_link_prefix');
            $link_prefix_slug = Utils::get_option('tinypress_link_prefix_slug', 'go');
            $tiny_slug_1      = trim($this->get_request_uri(), '/');

            $is_prefix_request = ('1' == $link_prefix && strpos($tiny_slug_1, $link_prefix_slug) !== false);

            if (! $is_prefix_request && (is_single() || is_archive())) {
                if ('1' == $link_prefix) {
                    return;
                }

                global $post;
                if ($post) {
                    $resolved_post_type = get_post_type($post->ID);
                    if (
                        'tinypress_link' !== $resolved_post_type &&
                        (!function_exists('rvy_in_revision_workflow') || !rvy_in_revision_workflow($post->ID))
                    ) {
                        return;
                    }
                }
                // If $post is null, continue — this is likely a shortlink where
                // prevent_shortlink_404 cleared the 404 but WP has no real post object.
            }

            $tiny_slug_2 = ('1' == $link_prefix) ? str_replace($link_prefix_slug . '/', '', $tiny_slug_1) : $tiny_slug_1;
            $tiny_slug_3 = explode('?', $tiny_slug_2);
            $tiny_slug_4 = $tiny_slug_3[0] ?? '';
            $link_id     = tinypress()->tiny_slug_to_post_id($tiny_slug_4);

            if (! empty($link_id) && $link_id !== 0) {
                $is_shortlink_request = false;
                $is_definite_shortlink = false;

                if ('1' == $link_prefix) {
                    $is_shortlink_request = (strpos($tiny_slug_1, $link_prefix_slug) !== false);
                    $is_definite_shortlink = $is_shortlink_request;
                } else {
                    $resolved_post_type = get_post_type($link_id);
                    if ('tinypress_link' === $resolved_post_type) {
                        $is_shortlink_request = true;
                        $is_definite_shortlink = true;
                    } elseif (function_exists('rvy_in_revision_workflow') && rvy_in_revision_workflow($link_id)) {
                        $is_shortlink_request = true;
                        $is_definite_shortlink = true;
                    } else {
                        $is_shortlink_request = is_404() || $this->is_shortlink_request();
                    }
                }

                if ($is_shortlink_request && ($is_definite_shortlink || ! is_page($tiny_slug_4))) {
                    if ('1' == $link_prefix && strpos($tiny_slug_1, $link_prefix_slug) === false) {
                        wp_die(esc_html__('This link is not containing the right prefix slug.', 'tinypress'));
                    }

                    $this->redirection_done = true;
                    return $this->check_protection($link_id);
                }
            }
        }


        /**
         * Filters the main query to determine post visibility for TinyPress shortlink previews.
         *
         * @param WP_Query $query
         * @return void
         */
        public function tinypress_filter_shortlink_preview_visibility($query)
        {
            if (! $query->is_main_query()) {
                return;
            }

            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Front-end query; checking URL parameter for preview mode detection
            if (! isset($_GET['preview']) || $_GET['preview'] !== 'true') {
                return;
            }

            if (is_user_logged_in() && current_user_can('edit_posts')) {
                $query->set('post_status', 'any');
                return;
            }

            if (! $this->is_valid_visitor_preview_request()) {
                return;
            }

            $post_statuses = $this->get_allowed_preview_statuses();
            $post_id       = $this->get_visitor_preview_post_id_from_request();

            if ($post_id && wp_is_post_revision($post_id)) {
                $post_statuses = array_unique(array_merge(array( 'publish', 'inherit' ), $post_statuses));
            }

            $query->set('post_status', $post_statuses);
        }


        /**
         * Return request URI from SERVER
         *
         * @return string
         */
        protected function get_request_uri()
        {

            $current_url = isset($_SERVER ['SCRIPT_URI']) ? sanitize_text_field($_SERVER ['SCRIPT_URI']) : '';

            if (empty($current_url)) {
                $current_url = isset($_SERVER ['REQUEST_URI']) ? sanitize_text_field($_SERVER ['REQUEST_URI']) : '';
            }

            return str_replace(site_url(), '', $current_url);
        }

        /**
         * Check if the current request is a valid TinyPress shortlink.
         * Used by RankMath compatibility and general 404 prevention.
         *
         * @param string $uri The URI to check
         * @return bool True if this is a valid shortlink
         */
        public function is_shortlink_request($uri = '')
        {
            if (empty($uri)) {
                $uri = trim($this->get_request_uri(), '/');
            }

            $link_prefix      = Utils::get_option('tinypress_link_prefix');
            $link_prefix_slug = Utils::get_option('tinypress_link_prefix_slug', 'go');

            // Extract the slug from the URI
            if ('1' == $link_prefix) {
                if (strpos($uri, $link_prefix_slug) !== 0 && strpos($uri, $link_prefix_slug . '/') !== 0) {
                    return false;
                }
                $tiny_slug = str_replace($link_prefix_slug . '/', '', $uri);
            } else {
                $tiny_slug = $uri;
            }

            $tiny_slug_parts = explode('?', $tiny_slug);
            $tiny_slug = $tiny_slug_parts[0] ?? '';

            $link_id = tinypress()->tiny_slug_to_post_id($tiny_slug);

            return ! empty($link_id) && $link_id !== 0;
        }

        /**
         * Check if the current request resolves to a dedicated shortlink post
         * (tinypress_link or PP Revisions post). This is a stricter check than
         * is_shortlink_request() — it excludes regular posts that just happen
         * to have a tiny_slug meta.
         *
         * Used by prevent_shortlink_404() to avoid interfering with normal WP
         * routing for regular posts.
         *
         * @return bool True if this resolves to a tinypress_link or revision post
         */
        protected function is_dedicated_shortlink_request()
        {
            $uri = trim($this->get_request_uri(), '/');

            $link_prefix      = Utils::get_option('tinypress_link_prefix');
            $link_prefix_slug = Utils::get_option('tinypress_link_prefix_slug', 'go');

            if ('1' == $link_prefix) {
                return $this->is_shortlink_request($uri);
            }

            $tiny_slug_parts = explode('?', $uri);
            $tiny_slug = $tiny_slug_parts[0] ?? '';

            $link_id = tinypress()->tiny_slug_to_post_id($tiny_slug);

            if (empty($link_id) || $link_id === 0) {
                return false;
            }

            if ('tinypress_link' === get_post_type($link_id)) {
                return true;
            }

            if (function_exists('rvy_in_revision_workflow') && rvy_in_revision_workflow($link_id)) {
                return true;
            }

            return false;
        }

        /**
         * RankMath compatibility: Prevent RankMath's Redirector from matching
         * shortlink URLs in its redirection database.
         *
         * Hooked to 'rank_math/redirection/pre_search'. If the current URI is
         * a valid shortlink, we return false to signal "no match". RankMath's
         * pre_filter() treats non-null non-array returns as "no match found",
         * but from_cache/everything may still match. The primary defense is
         * running redirection_controller on 'wp' priority 5 (before RankMath's
         * priority 11), so our wp_redirect+exit fires first.
         *
         * @param mixed  $pre      Default null.
         * @param string $uri      The request URI.
         * @param string $full_uri The full request URI with query string.
         * @return mixed
         */
        public function rankmath_pre_search_bypass($pre, $uri, $full_uri)
        {
            if ($this->is_shortlink_request()) {
                return false;
            }

            return $pre;
        }

        /**
         * RankMath compatibility: Add current shortlink to RankMath's fallback exclusion list.
         * This prevents RankMath from redirecting valid shortlinks to homepage.
         *
         * @param array $exclude_locations Array of locations to exclude from fallback redirect
         * @return array Modified exclusion list
         */
        public function rankmath_exclude_shortlinks($exclude_locations)
        {
            $uri = trim($this->get_request_uri(), '/');

            if ($this->is_shortlink_request($uri)) {
                $exclude_locations[] = $uri;
            }

            return $exclude_locations;
        }

        /**
         * General protection: Prevent WordPress from setting 404 status for valid shortlinks.
         *
         * @param bool     $preempt  Whether to short-circuit default 404 handling.
         * @param WP_Query $wp_query The WP_Query object.
         * @return bool True to prevent 404, original value otherwise.
         */
        public function prevent_shortlink_404($preempt, $wp_query)
        {
            if ($this->is_shortlink_request()) {
                // Tell WordPress this is NOT a 404 — we will handle it via redirection_controller
                $wp_query->is_404 = false;
                status_header(200);
                return true;
            }

            return $preempt;
        }

        /**
         * Yoast SEO compatibility: Bypass Yoast's redirect for valid shortlinks.
         *
         * @param bool $bypass Whether to bypass the redirect.
         * @return bool True to bypass, original value otherwise.
         */
        public function yoast_bypass_shortlink_redirect($bypass)
        {
            if ($this->is_dedicated_shortlink_request()) {
                return true;
            }

            return $bypass;
        }

        /**
         * Redirection plugin compatibility: Cancel redirect for valid shortlinks.
         *
         * @param string $target The redirect target URL.
         * @param string $url    The requested URL.
         * @return string Empty string to cancel redirect, original target otherwise.
         */
        public function redirection_plugin_bypass($target, $url)
        {
            if ($this->is_dedicated_shortlink_request()) {
                return false;
            }

            return $target;
        }

        /**
         * Fix page title for valid shortlinks to prevent "Page not found" from showing.
         *
         * @param string $title The page title
         * @param string $sep The separator
         * @return string Modified title
         */
        public function fix_shortlink_title($title, $sep = '')
        {
            if (! is_404()) {
                return $title;
            }

            if ($this->shortlink_force_404) {
                return $title;
            }

            $uri = trim($this->get_request_uri(), '/');

            if ($this->is_shortlink_request($uri)) {
                return get_bloginfo('name');
            }

            return $title;
        }

        /**
         * Inject JavaScript to detect and reload when landing on a post ID URL
         * This handles first-visit cases where the tinypress_link entry is being created
         * while the page is loading, causing a temporary redirect to the post ID.
         *
         * @return void
         */
        public function inject_reload_detection()
        {
            if (! is_404()) {
                return;
            }

            $tiny_slug_1 = trim($this->get_request_uri(), '/');
            $link_prefix = Utils::get_option('tinypress_link_prefix');
            $link_prefix_slug = Utils::get_option('tinypress_link_prefix_slug', 'go');

            // Only inject on shortlink URLs
            if ('1' == $link_prefix && strpos($tiny_slug_1, $link_prefix_slug) === false) {
                return;
            }
            ?>
            <script>
            (function() {
                var urlParams = new URLSearchParams(window.location.search);
                var postId = urlParams.get('p');
                
                // If we have a ?p=postid parameter, reload after 1.5 seconds to let the
                // tinypress_link entry be fully created, then try again
                if (postId && !sessionStorage.getItem('tinypress_reload_attempted')) {
                    sessionStorage.setItem('tinypress_reload_attempted', 'true');
                    setTimeout(function() {
                        window.location.reload();
                    }, 1500);
                }
            })();
            </script>
            <?php
        }

        /**
         * Rewrite SQL query to load WP core revision post type for visitor preview.
         * Mirrors what PP Revisions' flt_view_revision does for logged-in users,
         * but without the capability check.
         *
         * @param string $request SQL query string
         * @return string Modified SQL query string
         */
        public function visitor_revision_sql_rewrite($request)
        {
            global $wpdb;

            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query flag used for visitor previews.
            if (empty($_GET['tinypress_visitor'])) {
                return $request;
            }

            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $revision_id = ! empty($_GET['page_id']) ? absint($_GET['page_id']) : (! empty($_GET['p']) ? absint($_GET['p']) : 0);

            if (! $revision_id) {
                return $request;
            }

            if (! $this->is_valid_revision_preview_request($revision_id)) {
                return $request;
            }

            $post = get_post($revision_id);
            if (! $post) {
                return $request;
            }

            if ('revision' === $post->post_type) {
                $pub_post = get_post($post->post_parent);
                if (! $pub_post) {
                    return $request;
                }
                $post_type_to_rewrite = $pub_post->post_type;
            } else {
                $post_type_to_rewrite = $post->post_type;
            }

            // Strategy 1: Replace hardcoded post_type values
            $request = str_replace("post_type = 'post'", "post_type = 'revision'", $request);

            if ('revision' === $post->post_type && !empty($pub_post)) {
                $request = str_replace("post_type = '{$pub_post->post_type}'", "post_type = 'revision'", $request);
            }

            // Strategy 2: Use regex to handle variations with spaces/tabs/newlines
            $request = preg_replace(
                "/post_type\s*=\s*['\"]post['\"]/i",
                "post_type = 'revision'",
                $request
            );

            if ('revision' === $post->post_type && !empty($pub_post)) {
                $request = preg_replace(
                    "/post_type\s*=\s*['\"]" . preg_quote($pub_post->post_type) . "['\"]([^)])/i",
                    "post_type = 'revision'$1",
                    $request
                );
            }

            // Strategy 3: Handle table-prefixed references
            $request = str_replace(
                "{$wpdb->posts}.post_type = 'post'",
                "{$wpdb->posts}.post_type = 'revision'",
                $request
            );

            return $request;
        }

        /**
         * Inject revision post into main query results for visitor preview.
         * When WP main query returns empty (non-published status), fetch the post
         * directly from DB and fix all query flags so the correct single/page
         * template loads — enabling Elementor and other page builders.
         *
         * @param array    $posts Array of post objects
         * @param WP_Query $query The WP_Query instance
         * @return array Modified array of post objects
         */
        public function visitor_revision_inject_post($posts, $query)
        {
            if (! $query->is_main_query()) {
                return $posts;
            }

            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query flag used for visitor previews.
            if (empty($_GET['tinypress_visitor'])) {
                return $posts;
            }

            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $revision_id = ! empty($_GET['page_id']) ? absint($_GET['page_id']) : (! empty($_GET['p']) ? absint($_GET['p']) : 0);

            if (! $revision_id) {
                return $posts;
            }

            if (! $this->is_valid_revision_preview_request($revision_id)) {
                return $posts;
            }

            if (! empty($posts) && isset($posts[0]->ID) && (int) $posts[0]->ID === $revision_id) {
                $posts[0]->post_status = 'publish';
                wp_cache_set($posts[0]->ID, $posts[0], 'posts');

                global $post;
                $post = $posts[0];
                setup_postdata($post);

                remove_filter('posts_results', array( $this, 'visitor_revision_inject_post' ), 5);
                return $posts;
            }

            global $wpdb;
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct fetch to prevent main query 404; caching not reliable for this request-scoped preview.
            $post = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM $wpdb->posts WHERE ID = %d", $revision_id)
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

            if (! $post) {
                return $posts;
            }

            // Set status to publish so WordPress renders the correct template
            $post->post_status = 'publish';
            wp_cache_set($post->ID, $post, 'posts');

            // Fix WP_Query flags so it doesn't 404
            $query->is_404       = false;
            $query->is_singular  = true;
            $query->is_single    = ('page' !== $post->post_type);
            $query->is_page      = ('page' === $post->post_type);
            $query->found_posts  = 1;
            $query->post_count   = 1;
            $query->post         = $post;
            $query->posts        = array( $post );
            $query->queried_object    = $post;
            $query->queried_object_id = $post->ID;

            status_header(200);

            $post = $query->post;
            setup_postdata($post);

            // Only run once
            remove_filter('posts_results', array( $this, 'visitor_revision_inject_post' ), 5);

            return array( $post );
        }

        /**
         * Fix the main WP_Query for visitor revision preview.
         * The preview URL uses the published page's slug path, so WP resolves it
         * to the parent page. Force WP to query by page_id/p instead.
         *
         * @param WP_Query $query The WP_Query instance
         * @return void
         */
        public function visitor_revision_fix_query($query)
        {
            if (! $query->is_main_query()) {
                return;
            }

            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query flag used for visitor previews.
            if (empty($_GET['tinypress_visitor'])) {
                return;
            }

            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $revision_id = ! empty($_GET['page_id']) ? absint($_GET['page_id']) : (! empty($_GET['p']) ? absint($_GET['p']) : 0);

            if (! $revision_id) {
                return;
            }

            if (! $this->is_valid_revision_preview_request($revision_id)) {
                return;
            }

            // Clear slug-based query vars so WP doesn't resolve to the published parent
            $query->set('pagename', '');
            $query->set('name', '');
            $query->set('page_id', $revision_id);

            // Allow non-published statuses in the query
            $query->set('post_status', array_unique(array_merge(array( 'publish', 'inherit' ), $this->get_allowed_preview_statuses())));

            // Only run once
            remove_action('pre_get_posts', array( $this, 'visitor_revision_fix_query' ), 1);
        }

        /**
         * Prevent WordPress from redirecting visitor away from the preview URL.
         *
         * @param string $redirect_url The redirect URL
         * @param string $requested_url The requested URL
         * @return false|string False to block redirect, or the URL
         */
        public function visitor_revision_block_canonical($redirect_url, $requested_url)
        {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query flag used for visitor previews.
            if (empty($_GET['tinypress_visitor']) || ! $this->is_valid_revision_preview_request()) {
                return $redirect_url;
            }

            return false;
        }

        /**
         * Hide PP Revisions admin toolbar for visitor preview via CSS.
         *
         * @return void
         */
        public function visitor_revision_hide_toolbar()
        {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query flag used for visitor previews.
            if (empty($_GET['tinypress_visitor']) || ! $this->is_valid_revision_preview_request()) {
                return;
            }

            echo '<style>#pp_revisions_top_bar,.rvy-preview-linkbar,.rvy-creation-bar{display:none!important}</style>' . "\n";
        }

        /**
         * Support Elementor revision previews by ensuring CSS files are accessible.
         * Elementor stores CSS in post meta (_elementor_css) and loads it dynamically.
         * This ensures the fallback metadata filter works properly with Elementor.
         *
         * @return void
         */
        public function visitor_revision_elementor_support()
        {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query flag used for visitor previews.
            if (empty($_GET['tinypress_visitor']) || ! $this->is_valid_revision_preview_request()) {
                return;
            }

            if (!defined('ELEMENTOR_VERSION')) {
                return;
            }

            echo '<!-- Elementor revision preview via tinypress shortlink -->' . "\n";
        }

        /**
         * Display the published post author instead of revision creator.
         * When previewing a revision, show the author of the published post,
         * matching the behavior of PP Revisions display.
         *
         * @param string $author The author display name
         * @return string The corrected author name
         */
        public function visitor_revision_author($author)
        {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query flag used for visitor previews.
            if (empty($_GET['tinypress_visitor'])) {
                return $author;
            }

            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $revision_id = !empty($_GET['page_id']) ? absint($_GET['page_id']) : (!empty($_GET['p']) ? absint($_GET['p']) : 0);

            if (!$revision_id) {
                return $author;
            }

            if (! $this->is_valid_revision_preview_request($revision_id)) {
                return $author;
            }

            $revision = wp_get_post_revision($revision_id);
            if (!$revision) {
                return $author;
            }

            // Get the published post
            $published_post = get_post($revision->post_parent);
            if (!$published_post) {
                return $author;
            }

            // Get the published post's author
            $published_author = get_the_author_meta('display_name', $published_post->post_author);

            return $published_author ? $published_author : $author;
        }

        /**
         * Fallback to published post metadata when revision lacks metadata..
         *
         * @param mixed  $meta_val    The metadata value
         * @param int    $object_id   The post/object ID
         * @param string $meta_key    The metadata key
         * @param bool   $single      Whether to return single value
         * @param string $meta_type   The metadata type (post, user, etc.)
         *
         * @return mixed The metadata value (original or fallback)
         */
        public function visitor_revision_fallback_metadata($meta_val, $object_id, $meta_key, $single, $meta_type)
        {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query flag used for visitor previews.
            if (empty($_GET['tinypress_visitor'])) {
                return $meta_val;
            }

            static $busy;

            if (isset($busy) && $busy) {
                return $meta_val;
            }

            $busy = true;

            if (wp_is_post_revision($object_id)) {
                if (! $this->is_valid_revision_preview_request($object_id)) {
                    $busy = false;
                    return $meta_val;
                }

                $unfiltered_meta_val = get_post_meta($object_id, $meta_key, $single);

                if (in_array($unfiltered_meta_val, array(null, array()), true)) {
                    $published_post_id = get_post_field('post_parent', $object_id);
                    if ($published_post_id) {
                        $published_meta_val = get_post_meta($published_post_id, $meta_key, $single);
                        if (null !== $published_meta_val) {
                            $meta_val = $published_meta_val;
                        }
                    }
                }
            }

            $busy = false;

            return $meta_val;
        }

        /**
         * @return TINYPRESS_Redirection
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
