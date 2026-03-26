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

        /**
         * TINYPRESS_Redirection constructor.
         */
        public function __construct()
        {
            // General redirection plugin protection: prevent WP from setting 404 for valid shortlinks.
            add_filter('pre_handle_404', array( $this, 'prevent_shortlink_404' ), 10, 2);

            // RankMath compatibility
            add_filter('rank_math/redirection/fallback_exclude_locations', array( $this, 'rankmath_exclude_shortlinks' ), 10, 1);
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
            if ($is_visitor_preview) {
                add_action('init', array( $this, 'inject_visitor_preview_capability' ), 0);
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
                    $log_message .= ' | ' . json_encode($data);
                }
                error_log($log_message);
            }
        }

        public function prevent_elementor_homepage_redirect()
        {
            if (empty($_GET['tinypress_visitor'])) {
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
            if (empty($_GET['tinypress_visitor'])) {
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
            if (!empty($_GET['tinypress_visitor'])) {
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
                    if (is_user_logged_in()) {
                        $preview_url = rvy_preview_url($revision_post_id);
                        if (! empty($preview_url)) {
                            $target_url = $preview_url;
                            $is_revision_redirect = true;
                        }
                    } else {
                        $visitor_access = Utils::get_option('tinypress_revision_visitor_access', '1');

                        if ('1' == $visitor_access) {
                            $this->display_non_published_post($revision_post_id);
                            die();
                        } else {
                            global $wp_query;
                            $wp_query->set_404();
                            status_header(404);
                            nocache_headers();
                            $template_404 = get_query_template('404');
                            if ($template_404) {
                                include($template_404);
                            }
                            die();
                        }
                    }
                }
            }

            $post_to_check = $link_id;

            if (! $is_revision_redirect && ! empty($target_url) && 'tinypress_link' == get_post_type($link_id)) {
                // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.url_to_postid_url_to_postid -- Not a VIP environment; url_to_postid is standard WP core function
                $extracted_post_id = url_to_postid($target_url);
                
                // If url_to_postid fails, try parsing query string for ?p= format
                if (! $extracted_post_id) {
                    $url_parts = wp_parse_url($target_url);
                    if (isset($url_parts['query'])) {
                        parse_str($url_parts['query'], $query_vars);
                        if (isset($query_vars['p'])) {
                            $extracted_post_id = intval($query_vars['p']);
                        }
                    }
                }
                
                if ($extracted_post_id) {
                    $post_to_check = $extracted_post_id;
                }
            }
            
            if (! $is_revision_redirect && (( empty($target_url) && 'tinypress_link' != get_post_type($link_id) ) || $post_to_check != $link_id)) {
                $post_status = get_post_status($post_to_check);
                $post_object = get_post($post_to_check);
                
                if (! $post_object) {
                } else {
                    $can_view_post = false;
                    if (is_user_logged_in()) {
                        $current_user_id = get_current_user_id();
                        if ($current_user_id == $post_object->post_author || current_user_can('edit_post', $post_to_check)) {
                            $can_view_post = true;
                        }
                    }
                    
                    if (! $can_view_post) {
                        $allowed_statuses = Utils::get_option('tinypress_allowed_post_statuses');
                        
                        if (! is_array($allowed_statuses) || empty($allowed_statuses)) {
                            $allowed_statuses = array();
                        }

                        if (in_array('draft-revision', $allowed_statuses)) {
                            $all_revision_statuses = array( 'draft-revision', 'pending-revision', 'future-revision', 'revision-deferred', 'revision-needs-work', 'revision-rejected' );
                            $allowed_statuses = array_unique(array_merge($allowed_statuses, $all_revision_statuses));
                        }

                        if (! in_array($post_status, $allowed_statuses)) {
                            global $wp_query; // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.VariableRedeclaration -- Must re-declare to access in this scope
                            $wp_query->set_404();
                            status_header(404);
                            nocache_headers();
                            $template_404 = get_query_template('404');
                            if ($template_404) {
                                include($template_404);
                            }
                            die();
                        }
                    }
                    
                    if ($post_status !== 'publish') {
                        $this->display_non_published_post($post_to_check);
                        die();
                    }
                }
                
                if (empty($target_url) && 'tinypress_link' != get_post_type($link_id)) {
                    $target_url = get_permalink($link_id);
                }
            }

            $redirection_method   = Utils::get_meta('redirection_method', $link_id);
            $redirection_method   = $redirection_method ? $redirection_method : 302;
            $no_follow            = Utils::get_meta('redirection_no_follow', $link_id);
            $sponsored            = Utils::get_meta('redirection_sponsored', $link_id);
            $parameter_forwarding = Utils::get_meta('redirection_parameter_forwarding', $link_id);

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
            $single_template = get_query_template('single');
            if ($single_template) {
                include($single_template);
            } else {
                // Fallback: display basic post content if no single template found
                wp_die(esc_html($post->post_title), esc_html($post->post_title), array( 'response' => 200 ));
            }
        }


        /**
         * Display a brief notice page before redirecting expired links.
         *
         * @param string $redirect_url The URL to redirect to.
         * @return void
         */
        protected function display_expired_notice_page($redirect_url)
        {
            status_header(200);
            $safe_url = esc_url($redirect_url);
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
                    .notice-box h1 { font-size: 22px; margin: 0 0 12px; }
                    .notice-box p { font-size: 14px; color: #646970; margin: 0 0 20px; }
                    .notice-box a { color: #2271b1; text-decoration: none; font-weight: 500; }
                    .notice-box a:hover { text-decoration: underline; }
                </style>
            </head>
            <body>
                <div class="notice-box">
                    <h1><?php esc_html_e('This link has expired', 'tinypress'); ?></h1>
                    <p><?php esc_html_e('You will be redirected shortly.', 'tinypress'); ?></p>
                    <a href="<?php echo esc_url($safe_url); ?>"><?php esc_html_e('Click here if you are not redirected', 'tinypress'); ?></a>
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
                
                if ($post && ( $current_user_id == $post->post_author || current_user_can('edit_post', $link_id) )) {
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
            $password_protection  = Utils::get_meta('password_protection', $link_id);
            $link_password        = Utils::get_meta('link_password', $link_id);
            $expiration_date      = Utils::get_meta('expiration_date', $link_id);
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
                if (! empty($expiration_date)) {
                    $expiration_time = Utils::get_meta('expiration_time', $link_id);

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
                            $per_link_notice = Utils::get_meta('expired_show_notice', $link_id);
                            $show_notice = ! empty($per_link_notice) ? $per_link_notice : Utils::get_option('tinypress_expired_show_notice', false);

                            if ($show_notice) {
                                $this->display_expired_notice_page($expired_redirect_url);
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
                $this->redirect_url($link_id);
            }

            // Password protection is enabled — check if password was submitted
            $error_message = '';
            if (isset($_POST['tinypress_password']) && isset($_POST['tinypress_pw_nonce'])) {
                $submitted_nonce = sanitize_text_field(wp_unslash($_POST['tinypress_pw_nonce']));
                $submitted_password = sanitize_text_field(wp_unslash($_POST['tinypress_password']));
                
                if (wp_verify_nonce($submitted_nonce, 'tinypress_password_check_' . $link_id) && $submitted_password === $link_password) {
                    $this->redirect_url($link_id);
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

            $this->do_redirection($link_id);
        }


        /**
         * Redirection Controller
         *
         * @return void
         */
        public function redirection_controller()
        {

            $link_prefix      = Utils::get_option('tinypress_link_prefix');
            $link_prefix_slug = Utils::get_option('tinypress_link_prefix_slug', 'go');
            $tiny_slug_1      = trim($this->get_request_uri(), '/');

            $is_prefix_request = ( '1' == $link_prefix && strpos($tiny_slug_1, $link_prefix_slug) !== false );

            if (! $is_prefix_request && ( is_single() || is_archive() )) {
                if ('1' == $link_prefix) {
                    return;
                }
                
                global $post;
                if (!$post) {
                    return;
                }
                
                $resolved_post_type = get_post_type($post->ID);
                if ('tinypress_link' !== $resolved_post_type && 
                    (!function_exists('rvy_in_revision_workflow') || !rvy_in_revision_workflow($post->ID))) {
                    return;
                }
            }

            $tiny_slug_2 = ( '1' == $link_prefix ) ? str_replace($link_prefix_slug . '/', '', $tiny_slug_1) : $tiny_slug_1;
            $tiny_slug_3 = explode('?', $tiny_slug_2);
            $tiny_slug_4 = $tiny_slug_3[0] ?? '';
            $link_id     = tinypress()->tiny_slug_to_post_id($tiny_slug_4);

            if (! empty($link_id) && $link_id !== 0) {
                $is_shortlink_request = false;
                $is_definite_shortlink = false;
                
                if ('1' == $link_prefix) {
                    $is_shortlink_request = ( strpos($tiny_slug_1, $link_prefix_slug) !== false );
                } else {
                    $resolved_post_type = get_post_type($link_id);
                    if ('tinypress_link' === $resolved_post_type) {
                        $is_shortlink_request = true;
                    } elseif (function_exists('rvy_in_revision_workflow') && rvy_in_revision_workflow($link_id)) {
                        $is_shortlink_request = true;
                    } else {
                        $is_shortlink_request = is_404();
                    }
                }
                
                if ($is_shortlink_request && ($is_definite_shortlink || ! is_page($tiny_slug_4))) {
                    if ('1' == $link_prefix && strpos($tiny_slug_1, $link_prefix_slug) === false) {
                        wp_die(esc_html__('This link is not containing the right prefix slug.', 'tinypress'));
                    }

                    $this->check_protection($link_id);
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
            
            $allowed_statuses = Utils::get_option('tinypress_allowed_post_statuses');

            if (! is_array($allowed_statuses) || empty($allowed_statuses)) {
                $allowed_statuses = array();
            }

            if (in_array('draft-revision', $allowed_statuses)) {
                $all_revision_statuses = array( 'draft-revision', 'pending-revision', 'future-revision', 'revision-deferred', 'revision-needs-work', 'revision-rejected' );
                $allowed_statuses = array_unique(array_merge($allowed_statuses, $all_revision_statuses));
            }
            
            if (is_user_logged_in() && current_user_can('edit_posts')) {
                $query->set('post_status', 'any');
            } else {
                $query->set('post_status', $allowed_statuses);
            }
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
         * This filter runs during wp() before template_redirect, so ALL redirect plugins
         * (RankMath, Yoast, Redirection, AIOSEO, SEOPress, etc.) will see a non-404 state.
         *
         * @param bool     $preempt  Whether to short-circuit default 404 handling.
         * @param WP_Query $wp_query The WP_Query object.
         * @return bool True to prevent 404, original value otherwise.
         */
        public function prevent_shortlink_404($preempt, $wp_query)
        {
            if ($this->is_dedicated_shortlink_request()) {
                // Tell WordPress this is NOT a 404 — we will handle it in template_redirect
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
            
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $revision_id = ! empty($_GET['page_id']) ? absint($_GET['page_id']) : (! empty($_GET['p']) ? absint($_GET['p']) : 0);

            if (! $revision_id) {
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

            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $revision_id = ! empty($_GET['page_id']) ? absint($_GET['page_id']) : (! empty($_GET['p']) ? absint($_GET['p']) : 0);

            if (! $revision_id) {
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
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $post = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM $wpdb->posts WHERE ID = %d", $revision_id)
            );

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

            global $post;
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

            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $revision_id = ! empty($_GET['page_id']) ? absint($_GET['page_id']) : (! empty($_GET['p']) ? absint($_GET['p']) : 0);

            if (! $revision_id) {
                return;
            }

            // Clear slug-based query vars so WP doesn't resolve to the published parent
            $query->set('pagename', '');
            $query->set('name', '');
            $query->set('page_id', $revision_id);

            // Allow non-published statuses in the query
            $query->set('post_status', array( 'publish', 'pending', 'draft', 'future', 'inherit', 'private' ));

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
            return false;
        }

        /**
         * Hide PP Revisions admin toolbar for visitor preview via CSS.
         *
         * @return void
         */
        public function visitor_revision_hide_toolbar()
        {
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
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $revision_id = !empty($_GET['page_id']) ? absint($_GET['page_id']) : (!empty($_GET['p']) ? absint($_GET['p']) : 0);

            if (!$revision_id) {
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
            static $busy;

            if (!empty($busy) || 'post' !== $meta_type) {
                return $meta_val;
            }

            $busy = true;

            if (wp_is_post_revision($object_id)) {
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
