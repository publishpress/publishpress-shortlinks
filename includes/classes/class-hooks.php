<?php

/**
 * Class Hooks
 *
 * @author Pluginbazar
 */

use WPDK\Utils;

defined('ABSPATH') || exit;

if (! class_exists('TINYPRESS_Hooks')) {
    /**
     * Class TINYPRESS_Hooks
     *
     * Note: This class uses WordPress naming conventions instead of strict PSR-1/PSR-2 standards.
     */
    // phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace, Squiz.Classes.ValidClassName.NotCamelCaps, PSR1.Methods.CamelCapsMethodName.NotCamelCaps, PSR2.Classes.PropertyDeclaration.Underscore
    class TINYPRESS_Hooks
    {
        private const CAP_VIEW      = 'tinypress_view_shortlinks';
        private const CAP_MENU      = 'tinypress_access_shortlinks_menu';
        private const CAP_CREATE    = 'tinypress_create_shortlinks';
        private const CAP_EDIT      = 'tinypress_edit_shortlinks';
        private const CAP_DELETE    = 'tinypress_delete_shortlinks';
        private const CAP_ANALYTICS = 'tinypress_view_shortlink_analytics';
        private const CAP_SETTINGS  = 'tinypress_manage_shortlink_settings';

        protected static $_instance = null;

        private static $_filtering_caps = false;

        /**
         * TINYPRESS_Hooks constructor.
         */
        public function __construct()
        {

            add_action('init', array( $this, 'register_everything' ));
            add_action('admin_menu', array( $this, 'links_log' ));
            add_filter('post_updated_messages', array( $this, 'change_url_update_message' ));

            add_action('admin_menu', array( $this, 'reorder_submenu' ), 998);
            add_action('admin_menu', array( $this, 'enforce_role_view_access' ), 9999);
            add_action('admin_menu', array( $this, 'enforce_role_create_access' ), 9999);
            add_action('admin_menu', array( $this, 'enforce_role_settings_access' ), 9999);
            add_filter('user_has_cap', array( $this, 'filter_view_capabilities' ), 10, 4);
            add_action('admin_init', array( $this, 'block_direct_access' ));
            add_action('admin_init', array( $this, 'block_create_direct_access' ));
            add_action('admin_init', array( $this, 'block_settings_direct_access' ));

            add_action('admin_bar_menu', array( $this, 'add_admin_bar_menu' ), 999);
            add_action('admin_footer', array( $this, 'render_admin_modal' ));
            add_action('wp_ajax_tinypress_popup_create_url', array( $this, 'tinypress_popup_create_url' ));
            add_action('wp_ajax_tinypress_reset_analytics', array( $this, 'tinypress_reset_analytics' ));
        }

        public function tinypress_popup_create_url()
        {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- $_POST['url_data'] is parsed and sanitized via parse_str() then validated per field
            $_url_data = isset($_POST['url_data']) ? wp_unslash($_POST['url_data']) : null;

            parse_str($_url_data, $url_data);

            $nonce = isset($url_data['tinypress_create_nonce']) ? sanitize_text_field($url_data['tinypress_create_nonce']) : '';

            if (empty($nonce) || ! wp_verify_nonce($nonce, 'tinypress_popup_create_url')) {
                wp_send_json_error(array( 'message' => esc_html__('Security check failed.', 'tinypress') ));
            }

            if (! self::current_user_can_create()) {
                wp_send_json_error(array( 'message' => esc_html__('You do not have permission to create shortlinks.', 'tinypress') ));
            }

            $long_url  = Utils::get_args_option('long_url', $url_data);
            $tiny_slug = Utils::get_args_option('tiny_slug', $url_data, tinypress_create_url_slug());

            if (empty($long_url)) {
                wp_send_json_error(array( 'message' => esc_html__('Invalid or empty URL', 'tinypress') ));
            }

            $validated_long_url = function_exists('tinypress_validate_target_url') ? tinypress_validate_target_url($long_url) : esc_url_raw($long_url);

            if (is_wp_error($validated_long_url)) {
                wp_send_json_error(array( 'message' => $validated_long_url->get_error_message() ));
            }

            $url_args = array(
                'target_url' => $validated_long_url,
                'tiny_slug'  => $tiny_slug,
            );
            $tiny_url = tinypress_create_shorten_url($url_args);

            if (is_wp_error($tiny_url)) {
                wp_send_json_error(array( 'message' => $tiny_url->get_error_message() ));
            }

            wp_send_json_success(
                array(
                    'tiny_url' => $tiny_url,
                    'long_url' => $validated_long_url,
                    'message'  => esc_html__('Shortlink created successfully.', 'tinypress')
                )
            );
        }

        public function tinypress_reset_analytics()
        {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- $_POST['nonce'] is validated by wp_verify_nonce()
            if (! isset($_POST['nonce']) || ! wp_verify_nonce($_POST['nonce'], 'tinypress_reset_analytics_nonce')) {
                wp_send_json_error(esc_html__('Invalid nonce verification.', 'tinypress'));
            }

            if (! current_user_can(self::CAP_ANALYTICS)) {
                wp_send_json_error(esc_html__('You do not have permission to reset analytics.', 'tinypress'));
            }

            $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
            $period  = isset($_POST['period']) ? sanitize_text_field($_POST['period']) : 'today';

            if (! $post_id) {
                wp_send_json_error(esc_html__('Invalid post ID.', 'tinypress'));
            }

            $link = get_post($post_id);

            if (! $link || 'tinypress_link' !== $link->post_type) {
                wp_send_json_error(esc_html__('Invalid shortlink ID.', 'tinypress'));
            }

            if (! current_user_can('edit_post', $post_id)) {
                wp_send_json_error(esc_html__('You do not have permission to reset analytics for this shortlink.', 'tinypress'));
            }

            global $wpdb;

            $date_condition = '';
            switch ($period) {
                case 'today':
                    $date_condition = "AND DATE(datetime) = CURDATE()";
                    break;
                case 'last_7_days':
                    $date_condition = "AND datetime >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
                    break;
                case 'last_1_month':
                    $date_condition = "AND datetime >= DATE_SUB(NOW(), INTERVAL 1 MONTH)";
                    break;
                case 'last_1_year':
                    $date_condition = "AND datetime >= DATE_SUB(NOW(), INTERVAL 1 YEAR)";
                    break;
                default:
                    $date_condition = "AND DATE(datetime) = CURDATE()";
            }

            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom table; TINYPRESS_TABLE_REPORTS is a safe constant; $date_condition is a hardcoded string from switch above
            $result = $wpdb->query($wpdb->prepare(
                "DELETE FROM " . TINYPRESS_TABLE_REPORTS . " WHERE post_id = %d " . $date_condition,
                $post_id
            ));
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

            if ($result !== false) {
                wp_send_json_success(esc_html__('Analytics reset successfully.', 'tinypress'));
            } else {
                wp_send_json_error(esc_html__('Failed to reset analytics.', 'tinypress'));
            }
        }

        public function render_admin_modal()
        {

            if (! self::current_user_can_create()) {
                return;
            }

            include_once TINYPRESS_PLUGIN_DIR . 'templates/admin-modal-new-link.php';
        }

        public function add_admin_bar_menu(WP_Admin_Bar $admin_bar)
        {
            if (! self::current_user_can_view() || ! self::current_user_can_create()) {
                return;
            }

            $settings = get_option('tinypress_settings', array());
            $hide_modal_opener = is_array($settings) && ! empty($settings['tinypress_hide_modal_opener']);

            if (! $hide_modal_opener) {
                $admin_bar->add_menu(array(
                    'id'    => 'tinypress',
                    'title' => esc_html__('Shorten', 'tinypress'),
                    'href'  => '#',
                    'meta'  => array(
                        'class' => 'tinypress-admin-bar-icon',
                    ),
                ));
            }
        }

        /**
         * Update post update message
         *
         * @param $messages
         *
         * @return mixed
         */
        public function change_url_update_message($messages)
        {

            $tinypress_messages = Utils::get_args_option('post', $messages);
            $tinypress_messages = array_map(function ($message) {
                return str_replace('Post', 'Shortlinks', $message);
            }, $tinypress_messages);

            $messages['tinypress_link'] = $tinypress_messages;

            return $messages;
        }


        /**
         * Register Post Types
         */
        public function register_everything()
        {

            global $tinypress_wpdk;

            $tinypress_wpdk->utils()->register_post_type('tinypress_link', array(
                'singular'            => esc_html__('Shortlink', 'tinypress'),
                'plural'              => esc_html__('Shortlinks', 'tinypress'),
                'labels'              => array(
                    'menu_name' => esc_html__('Shortlinks', 'tinypress'),
                    'all_items' => esc_html__('All Shortlinks', 'tinypress'),
                    'add_new' => esc_html__('Add Shortlink', 'tinypress'),
                    'add_new_item' => esc_html__('Add Shortlink', 'tinypress'),
                    'search_items' => esc_html__('Search Shortlinks', 'tinypress'),
                ),
                'menu_icon'           => 'dashicons-admin-links',
                'supports'            => array( '' ),
                'public'              => false,
                'publicly_queryable'  => false,
                'exclude_from_search' => true,
                'capability_type'     => 'tinypress_link',
                'capabilities'        => self::get_post_type_capabilities(),
                'map_meta_cap'        => false,
            ));

            $tinypress_wpdk->utils()->register_taxonomy(
                'tinypress_link_cat',
                'tinypress_link',
                apply_filters(
                    'TINYPRESS/Filters/link_cat_args',
                    array(
                        'singular'     => esc_html__('Category', 'tinypress'),
                        'plural'       => esc_html__('Categories', 'tinypress'),
                        'hierarchical' => true,
                    )
                )
            );

            // phpcs:disable Squiz.PHP.CommentedOutCode.Found -- Intentionally kept for potential future tags taxonomy feature
            //          $tinypress_wpdk->utils()->register_taxonomy( 'tinypress_link_tags', 'tinypress_link',
            //              apply_filters( 'TINYPRESS/Filters/link_tags_args',
            //                  array(
            //                      'singular' => esc_html__( 'Tag', 'tinypress' ),
            //                      'plural'   => esc_html__( 'Tags', 'tinypress' ),
            //                  )
            //              )
            //          );
            // phpcs:enable Squiz.PHP.CommentedOutCode.Found
        }


        /**
         * Adds a submenu page under a custom post type parent.
         */
        public function links_log()
        {
            add_submenu_page(
                'edit.php?post_type=tinypress_link',
                esc_html__('Logs', 'tinypress'),
                esc_html__('Logs', 'tinypress'),
                self::CAP_ANALYTICS,
                'tinypress-logs',
                array( $this, 'render_menu_logs' )
            );
        }


        /**
         * Render logs menu
         */
        public function render_menu_logs()
        {

            if (! class_exists('WP_List_Table_Logs')) {
                require_once 'class-table-logs.php';
            }

            $table_logs = new WP_List_Table_Logs();
            $clear_logs_url = wp_nonce_url(add_query_arg(array( 'action' => 'clear_logs' )), 'tinypress_clear_logs');

            echo '<div class="wrap">';
            $log_message = get_transient('tinypress_log_message');
            if ($log_message) {
                $notice_type = isset($log_message['type']) ? $log_message['type'] : 'success';
                echo '<div class="notice notice-' . esc_attr($notice_type) . ' is-dismissible"><p>' . esc_html($log_message['message']) . '</p></div>';
                delete_transient('tinypress_log_message');
            }
            echo '<h2 class="report-table">' . esc_html__('All Logs', 'tinypress') . ' <a href="' . esc_url($clear_logs_url) . '" class="page-title-action" onclick="return confirm(\'' . esc_js(__('Are you sure you want to clear all logs?', 'tinypress')) . '\');">' . esc_html__('Clear Logs', 'tinypress') . '</a></h2>';

            echo '<form method="post">';
            echo '<input type="hidden" name="post_type" value="tinypress_link" />';
            echo '<input type="hidden" name="page" value="tinypress-logs" />';
            wp_nonce_field('tinypress_logs_nonce', 'tinypress_logs_nonce');
            $table_logs->prepare_items();
            $table_logs->display();
            echo '</form>';

            echo '</div>';
        }


        /**
         * Check if a user has access for a given role setting key.
         *
         * @param string       $setting_key   The option key for the role setting
         * @param WP_User|null $user          User to check. Defaults to current user.
         * @param array        $default_roles Roles used when the setting has not been saved yet.
         * @return bool
         */
        public static function user_has_role_access($setting_key, $user = null, $default_roles = array())
        {
            if (! $user) {
                $user = wp_get_current_user();
            }

            if (! $user || ! $user->exists()) {
                return false;
            }

            // Admins always have access.
            if (self::user_is_administrator($user)) {
                return true;
            }

            $settings = get_option('tinypress_settings', array());
            if (! is_array($settings)) {
                $settings = array();
            }

            $has_setting   = array_key_exists($setting_key, $settings);
            $setting_value = $has_setting ? $settings[ $setting_key ] : $default_roles;
            $allowed_roles = self::normalize_role_list($setting_value);

            // Preserve the field defaults for older installs that saved disabled Pro fields as empty values.
            if (empty($allowed_roles) && (! $has_setting || '' === $setting_value || null === $setting_value)) {
                $allowed_roles = self::normalize_role_list($default_roles);
            }

            if (empty($allowed_roles)) {
                return false;
            }

            $user_roles = (array) $user->roles;

            return ! empty(array_intersect($user_roles, $allowed_roles));
        }

        /**
         * Check if the current user can view shortlinks.
         *
         * @return bool
         */
        public static function current_user_can_view()
        {
            return self::user_has_role_access('tinypress_role_view', null, self::get_all_role_keys());
        }

        /**
         * Check if the current user can create/edit shortlinks.
         *
         * @return bool
         */
        public static function current_user_can_create()
        {
            return self::user_can_create(wp_get_current_user());
        }

        /**
         * Check if a user can create/edit shortlinks.
         *
         * @param WP_User|null $user User to check.
         * @return bool
         */
        public static function user_can_create($user = null)
        {
            if (! defined('PUBLISHPRESS_SHORTLINKS_PRO_VERSION')) {
                return self::user_has_primitive_cap($user, 'edit_posts');
            }
            return self::user_has_role_access('tinypress_role_create', $user, array( 'administrator', 'editor' ));
        }

        /**
         * Check if the current user can control settings.
         *
         * @return bool
         */
        public static function current_user_can_settings()
        {
            return self::user_can_settings(wp_get_current_user());
        }

        /**
         * Check if a user can control settings.
         *
         * @param WP_User|null $user User to check.
         * @return bool
         */
        public static function user_can_settings($user = null)
        {
            if (! defined('PUBLISHPRESS_SHORTLINKS_PRO_VERSION')) {
                return self::user_has_primitive_cap($user, 'manage_options');
            }
            return self::user_has_role_access('tinypress_role_edit', $user, array( 'administrator', 'editor' ));
        }

        /**
         * Check if a user can see analytics.
         *
         * @param WP_User|null $user User to check.
         * @return bool
         */
        public static function user_can_analytics($user = null)
        {
            if (! defined('PUBLISHPRESS_SHORTLINKS_PRO_VERSION')) {
                return self::user_has_primitive_cap($user, 'edit_posts');
            }

            return self::user_has_role_access('tinypress_role_analytics', $user, array( 'administrator', 'editor' ));
        }

        /**
         * Check if a user can access any Shortlinks admin menu.
         *
         * @param WP_User|null $user User to check.
         * @return bool
         */
        public static function user_can_access_menu($user = null)
        {
            if (! defined('PUBLISHPRESS_SHORTLINKS_PRO_VERSION')) {
                return self::user_has_primitive_cap($user, 'edit_posts');
            }

            return self::user_has_role_access('tinypress_role_view', $user, self::get_all_role_keys())
                || self::user_can_create($user)
                || self::user_can_analytics($user)
                || self::user_can_settings($user);
        }

        public function reorder_submenu()
        {
            global $submenu;

            $parent = 'edit.php?post_type=tinypress_link';

            if (empty($submenu[ $parent ])) {
                return;
            }

            $settings_item = null;
            $settings_key  = null;

            foreach ($submenu[ $parent ] as $key => $item) {
                if (isset($item[2]) && $item[2] === 'settings') {
                    $settings_item = $item;
                    $settings_key  = $key;
                    break;
                }
            }

            if ($settings_item) {
                unset($submenu[ $parent ][ $settings_key ]);
                $submenu[ $parent ][] = $settings_item;
            }
        }

        /**
         * Enforce "Who Can View Shortlinks" role restriction.
         * Removes only the All Shortlinks listing item for users whose role is not in the allowed list.
         */
        public function enforce_role_view_access()
        {
            if (! self::current_user_can_view()) {
                remove_submenu_page('edit.php?post_type=tinypress_link', 'edit.php?post_type=tinypress_link');
            }
        }

        /**
         * Enforce "Who Can Create/Edit Shortlinks" restriction.
         * Removes the "Add New" submenu for restricted roles.
         */
        public function enforce_role_create_access()
        {
            if (! self::current_user_can_create()) {
                remove_submenu_page('edit.php?post_type=tinypress_link', 'post-new.php?post_type=tinypress_link');
            }
        }

        /**
         * Enforce "Who Can Control Settings" restriction.
         * Removes the Settings submenu for restricted roles.
         */
        public function enforce_role_settings_access()
        {
            if (! self::current_user_can_settings()) {
                remove_submenu_page('edit.php?post_type=tinypress_link', 'settings');
            }
        }

        /**
         * Grant shortlinks-specific capabilities from the role management settings.
         *
         * @param array   $allcaps All capabilities of the user
         * @param array   $caps    Required capabilities
         * @param array   $args    Arguments
         * @param WP_User $user    The user object
         * @return array
         */
        public function filter_view_capabilities($allcaps, $caps, $args, $user)
        {
            // Prevent recursion if Utils::get_option triggers current_user_can
            if (self::$_filtering_caps) {
                return $allcaps;
            }

            if (! $user || ! $user->exists()) {
                return $allcaps;
            }

            $managed_caps = self::get_managed_capabilities();
            $requested    = array_intersect((array) $caps, $managed_caps);

            if (empty($requested)) {
                return $allcaps;
            }

            self::$_filtering_caps = true;

            foreach ($requested as $cap) {
                switch ($cap) {
                    case self::CAP_MENU:
                        $allcaps[ $cap ] = self::user_can_access_menu($user);
                        break;

                    case self::CAP_VIEW:
                        $allcaps[ $cap ] = self::user_has_role_access('tinypress_role_view', $user, self::get_all_role_keys());
                        break;

                    case self::CAP_ANALYTICS:
                        $allcaps[ $cap ] = self::user_can_analytics($user);
                        break;

                    case self::CAP_SETTINGS:
                        $allcaps[ $cap ] = self::user_can_settings($user);
                        break;

                    case self::CAP_CREATE:
                    case self::CAP_EDIT:
                        $allcaps[ $cap ] = self::user_can_create($user);
                        break;

                    case self::CAP_DELETE:
                        $allcaps[ $cap ] = self::user_can_delete($user);
                        break;
                }
            }

            self::$_filtering_caps = false;

            return $allcaps;
        }

        /**
         * Block direct URL access to the All Shortlinks listing for restricted users.
         */
        public function block_direct_access()
        {
            if (! $this->is_tinypress_list_screen()) {
                return;
            }

            $user = wp_get_current_user();
            if (! $user || ! $user->exists()) {
                return;
            }

            if (in_array('administrator', (array) $user->roles, true)) {
                return;
            }

            if (! self::current_user_can_view()) {
                wp_die(
                    esc_html__('Sorry, you are not allowed to access shortlinks.', 'tinypress'),
                    esc_html__('Access Denied', 'tinypress'),
                    array( 'response' => 403 )
                );
            }
        }

        /**
         * Block direct URL access to create/edit tinypress_link for restricted roles.
         */
        public function block_create_direct_access()
        {
            if (self::current_user_can_create()) {
                return;
            }

            global $pagenow;

            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- No form submission; checking admin URL parameters for access control
            if ($pagenow === 'post-new.php' && isset($_GET['post_type']) && $_GET['post_type'] === 'tinypress_link') {
                wp_die(
                    esc_html__('Sorry, you are not allowed to create shortlinks.', 'tinypress'),
                    esc_html__('Access Denied', 'tinypress'),
                    array( 'response' => 403 )
                );
            }

            // phpcs:disable WordPress.Security.NonceVerification.Recommended -- No form submission; checking admin URL parameters for access control
            if ($pagenow === 'post.php' && ! empty($_GET['post'])) {
                $post_type = get_post_type(absint($_GET['post']));
                if ($post_type === 'tinypress_link') {
                    wp_die(
                        esc_html__('Sorry, you are not allowed to edit shortlinks.', 'tinypress'),
                        esc_html__('Access Denied', 'tinypress'),
                        array( 'response' => 403 )
                    );
                }
            }
            // phpcs:enable WordPress.Security.NonceVerification.Recommended
        }

        /**
         * Block direct URL access to settings page for restricted roles.
         */
        public function block_settings_direct_access()
        {
            if (self::current_user_can_settings()) {
                return;
            }

            // phpcs:disable WordPress.Security.NonceVerification.Recommended -- No form submission; checking admin URL parameters for access control
            if (isset($_GET['post_type']) && $_GET['post_type'] === 'tinypress_link' && isset($_GET['page']) && $_GET['page'] === 'settings') {
                wp_die(
                    esc_html__('Sorry, you are not allowed to access shortlink settings.', 'tinypress'),
                    esc_html__('Access Denied', 'tinypress'),
                    array( 'response' => 403 )
                );
            }
            // phpcs:enable WordPress.Security.NonceVerification.Recommended
        }

        /**
         * Check if the current admin page is a tinypress_link screen.
         *
         * @return bool
         */
        private function is_tinypress_screen()
        {
            global $pagenow, $typenow;

            // Check query params for post_type
            $post_type = '';
            // phpcs:disable WordPress.Security.NonceVerification.Recommended -- No form submission; reading admin URL parameters for screen detection
            if (! empty($_GET['post_type'])) {
                $post_type = sanitize_text_field(wp_unslash($_GET['post_type']));
            } elseif (! empty($_GET['post'])) {
                $post_type = get_post_type(absint($_GET['post']));
            } elseif (! empty($typenow)) {
                $post_type = $typenow;
            }

            if ($post_type === 'tinypress_link') {
                return true;
            }

            // Check for settings/logs pages
            if (! empty($_GET['page'])) {
                $page = sanitize_text_field(wp_unslash($_GET['page']));
                if (in_array($page, array( 'settings', 'tinypress-logs' ), true)) {
                    // Only if we're under the tinypress parent
                    if (isset($_GET['post_type']) && $_GET['post_type'] === 'tinypress_link') {
                        return true;
                    }
                }
            }
            // phpcs:enable WordPress.Security.NonceVerification.Recommended

            return false;
        }

        /**
         * Check if the current admin page is the All Shortlinks listing.
         *
         * @return bool
         */
        private function is_tinypress_list_screen()
        {
            global $pagenow;

            // phpcs:disable WordPress.Security.NonceVerification.Recommended -- No form submission; reading admin URL parameters for screen detection
            $is_list_screen = 'edit.php' === $pagenow
                && isset($_GET['post_type'])
                && 'tinypress_link' === sanitize_text_field(wp_unslash($_GET['post_type']))
                && empty($_GET['page']);
            // phpcs:enable WordPress.Security.NonceVerification.Recommended

            return $is_list_screen;
        }

        /**
         * Capabilities used by the shortlinks post type and related menus.
         *
         * @return array
         */
        private static function get_post_type_capabilities()
        {
            return array(
                'edit_post'              => self::CAP_EDIT,
                'read_post'              => self::CAP_VIEW,
                'delete_post'            => self::CAP_DELETE,
                'edit_posts'             => self::CAP_MENU,
                'edit_others_posts'      => self::CAP_EDIT,
                'publish_posts'          => self::CAP_CREATE,
                'read_private_posts'     => self::CAP_VIEW,
                'delete_posts'           => self::CAP_DELETE,
                'delete_private_posts'   => self::CAP_DELETE,
                'delete_published_posts' => self::CAP_DELETE,
                'delete_others_posts'    => self::CAP_DELETE,
                'edit_private_posts'     => self::CAP_EDIT,
                'edit_published_posts'   => self::CAP_EDIT,
                'create_posts'           => self::CAP_CREATE,
            );
        }

        /**
         * Return all dynamic capabilities managed by role settings.
         *
         * @return array
         */
        private static function get_managed_capabilities()
        {
            return array(
                self::CAP_VIEW,
                self::CAP_MENU,
                self::CAP_CREATE,
                self::CAP_EDIT,
                self::CAP_DELETE,
                self::CAP_ANALYTICS,
                self::CAP_SETTINGS,
            );
        }

        /**
         * Get the currently registered role keys.
         *
         * @return array
         */
        private static function get_all_role_keys()
        {
            if (! function_exists('wp_roles')) {
                return array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' );
            }

            return array_keys(wp_roles()->roles);
        }

        /**
         * Normalize saved role settings from checkbox arrays, imports, or legacy shapes.
         *
         * @param mixed $roles Saved roles.
         * @return array
         */
        private static function normalize_role_list($roles)
        {
            if (is_string($roles)) {
                $roles = false !== strpos($roles, ',') ? explode(',', $roles) : array( $roles );
            }

            if (! is_array($roles)) {
                return array();
            }

            $normalized = array();

            foreach ($roles as $key => $value) {
                if (is_string($value) && '' !== $value && '0' !== $value && 'false' !== strtolower($value)) {
                    $normalized[] = sanitize_key($value);
                }

                if (! is_int($key) && ! empty($value) && '0' !== (string) $value && 'false' !== strtolower((string) $value)) {
                    $normalized[] = sanitize_key($key);
                }
            }

            return array_values(array_unique(array_filter($normalized)));
        }

        /**
         * Check a native WordPress primitive capability without consulting Shortlinks role settings.
         *
         * @param WP_User|null $user User to check.
         * @param string       $cap  Primitive capability name.
         * @return bool
         */
        private static function user_has_primitive_cap($user, $cap)
        {
            if (! $user || ! $user->exists()) {
                return false;
            }

            if (self::user_is_administrator($user)) {
                return true;
            }

            return ! empty($user->allcaps[ $cap ]);
        }

        /**
         * Check if a user can delete shortlinks.
         *
         * @param WP_User|null $user User to check.
         * @return bool
         */
        private static function user_can_delete($user = null)
        {
            if (! defined('PUBLISHPRESS_SHORTLINKS_PRO_VERSION')) {
                return self::user_has_primitive_cap($user, 'delete_posts');
            }

            return self::user_can_create($user);
        }

        /**
         * Check if a user should always be allowed through Shortlinks role gates.
         *
         * @param WP_User $user User to check.
         * @return bool
         */
        private static function user_is_administrator($user)
        {
            if (! $user || ! $user->exists()) {
                return false;
            }

            return in_array('administrator', (array) $user->roles, true)
                || (function_exists('is_super_admin') && is_super_admin($user->ID));
        }

        /**
         * @return TINYPRESS_Hooks
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
