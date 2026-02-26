<?php
/**
 * Class Hooks
 *
 * @author Pluginbazar
 */

use WPDK\Utils;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TINYPRESS_Hooks' ) ) {
	/**
	 * Class TINYPRESS_Hooks
	 */
	class TINYPRESS_Hooks {

		protected static $_instance = null;

		private static $_filtering_caps = false;

		/**
		 * TINYPRESS_Hooks constructor.
		 */
		function __construct() {

			add_action( 'init', array( $this, 'register_everything' ) );
			add_action( 'admin_menu', array( $this, 'links_log' ) );
			add_filter( 'post_updated_messages', array( $this, 'change_url_update_message' ) );

			add_action( 'admin_menu', array( $this, 'reorder_submenu' ), 998 );
			add_action( 'admin_menu', array( $this, 'enforce_role_view_access' ), 9999 );
			add_filter( 'user_has_cap', array( $this, 'filter_view_capabilities' ), 10, 4 );
			add_action( 'admin_init', array( $this, 'block_direct_access' ) );

			add_action( 'admin_bar_menu', array( $this, 'add_admin_bar_menu' ), 999 );
			add_action( 'admin_footer', array( $this, 'render_admin_modal' ) );
			add_action( 'wp_ajax_tinypress_popup_create_url', array( $this, 'tinypress_popup_create_url' ) );
			add_action( 'wp_ajax_tinypress_reset_analytics', array( $this, 'tinypress_reset_analytics' ) );
		}

		public function tinypress_popup_create_url() {

			if ( ! self::current_user_can_create() ) {
				wp_send_json_error( array( 'message' => esc_html__( 'You do not have permission to create shortlinks.', 'tinypress' ) ) );
			}

			$_url_data = isset( $_POST['url_data'] ) ? wp_unslash( $_POST['url_data'] ) : null;

			parse_str( $_url_data, $url_data );

			$long_url  = Utils::get_args_option( 'long_url', $url_data );
			$tiny_slug = Utils::get_args_option( 'tiny_slug', $url_data, tinypress_create_url_slug() );

			if ( empty( $long_url ) ) {
				wp_send_json_error( array( 'message' => esc_html__( 'Invalid or empty URL', 'tinypress' ) ) );
			}

			$url_args = array(
				'target_url' => $long_url,
				'tiny_slug'  => $tiny_slug,
			);
			$tiny_url = tinypress_create_shorten_url( $url_args );

			if ( is_wp_error( $tiny_url ) ) {
				wp_send_json_error( array( 'message' => $tiny_url->get_error_message() ) );
			}

			wp_send_json_success(
				array(
					'tiny_url' => $tiny_url,
					'long_url' => $long_url,
					'message'  => esc_html__( 'Shortlink created successfully.', 'tinypress' )
				)
			);
		}

		public function tinypress_reset_analytics() {
			if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'tinypress_reset_analytics_nonce' ) ) {
				wp_send_json_error( esc_html__( 'Invalid nonce verification.', 'tinypress' ) );
			}

			if ( ! current_user_can( 'edit_posts' ) ) {
				wp_send_json_error( esc_html__( 'You do not have permission to reset analytics.', 'tinypress' ) );
			}

			$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
			$period  = isset( $_POST['period'] ) ? sanitize_text_field( $_POST['period'] ) : 'today';

			if ( ! $post_id ) {
				wp_send_json_error( esc_html__( 'Invalid post ID.', 'tinypress' ) );
			}

			global $wpdb;

			$date_condition = '';
			switch ( $period ) {
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

			$result = $wpdb->query( $wpdb->prepare(
				"DELETE FROM " . TINYPRESS_TABLE_REPORTS . " WHERE post_id = %d " . $date_condition,
				$post_id
			) );

			if ( $result !== false ) {
				wp_send_json_success( esc_html__( 'Analytics reset successfully.', 'tinypress' ) );
			} else {
				wp_send_json_error( esc_html__( 'Failed to reset analytics.', 'tinypress' ) );
			}
		}

		public function render_admin_modal() {

        	if ( ! self::current_user_can_create() ) {
				return;
			}

			include_once TINYPRESS_PLUGIN_DIR . 'templates/admin-modal-new-link.php';
		}

		public function add_admin_bar_menu( WP_Admin_Bar $admin_bar ) {

			$hide_modal_opener = (bool) Utils::get_option( 'tinypress_hide_modal_opener' );

			if ( ! self::current_user_can_view() || ! self::current_user_can_create() ) {
				return;
			}

			if ( $hide_modal_opener !== true ) {
				$admin_bar->add_menu( array(
					'id'    => 'tinypress',
					'title' => esc_html__( 'Shorten', 'tinypress' ),
					'href'  => '#',
					'meta'  => array(
						'class' => 'tinypress-admin-bar-icon',
					),
				) );
			}
		}

		/**
		 * Update post update message
		 *
		 * @param $messages
		 *
		 * @return mixed
		 */
		function change_url_update_message( $messages ) {

			$tinypress_messages = Utils::get_args_option( 'post', $messages );
			$tinypress_messages = array_map( function ( $message ) {
				return str_replace( 'Post', 'Shortlinks', $message );
			}, $tinypress_messages );

			$messages['tinypress_link'] = $tinypress_messages;

			return $messages;
		}


		/**
		 * Register Post Types
		 */
		function register_everything() {

			global $tinypress_wpdk;

			$tinypress_wpdk->utils()->register_post_type( 'tinypress_link', array(
				'singular'            => esc_html__( 'Shortlinks', 'tinypress' ),
				'plural'              => esc_html__( 'All Shortlinks', 'tinypress' ),
				'labels'              => array(
					'menu_name' => esc_html__( 'Shortlinks', 'tinypress' ),
				),
				'menu_icon'           => 'dashicons-admin-links',
				'supports'            => array( '' ),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
			) );

			$tinypress_wpdk->utils()->register_taxonomy( 'tinypress_link_cat', 'tinypress_link',
				apply_filters( 'TINYPRESS/Filters/link_cat_args',
					array(
						'singular'     => esc_html__( 'Category', 'tinypress' ),
						'plural'       => esc_html__( 'Categories', 'tinypress' ),
						'hierarchical' => true,
					)
				)
			);

//			$tinypress_wpdk->utils()->register_taxonomy( 'tinypress_link_tags', 'tinypress_link',
//				apply_filters( 'TINYPRESS/Filters/link_tags_args',
//					array(
//						'singular' => esc_html__( 'Tag', 'tinypress' ),
//						'plural'   => esc_html__( 'Tags', 'tinypress' ),
//					)
//				)
//			);
		}


		/**
		 * Adds a submenu page under a custom post type parent.
		 */
		function links_log() {
			add_submenu_page( 'edit.php?post_type=tinypress_link',
				esc_html__( 'Logs', 'tinypress' ), esc_html__( 'Logs', 'tinypress' ), 'edit_posts', 'tinypress-logs',
				array( $this, 'render_menu_logs' )
			);
		}


		/**
		 * Render logs menu
		 */
		function render_menu_logs() {

			if ( ! class_exists( 'WP_List_Table_Logs' ) ) {
				require_once 'class-table-logs.php';
			}

			$table_logs = new WP_List_Table_Logs();
			$clear_logs_url = wp_nonce_url( add_query_arg( array( 'action' => 'clear_logs' ) ), 'tinypress_clear_logs' );

			echo '<div class="wrap">';
            $log_message = get_transient( 'tinypress_log_message' );
            if ( $log_message ) {
                $notice_type = isset( $log_message['type'] ) ? $log_message['type'] : 'success';
                echo '<div class="notice notice-' . esc_attr( $notice_type ) . ' is-dismissible"><p>' . esc_html( $log_message['message'] ) . '</p></div>';
                delete_transient( 'tinypress_log_message' );
            }
			echo '<h2 class="report-table">' . esc_html__( 'All Logs', 'tinypress' ) . ' <a href="' . esc_url( $clear_logs_url ) . '" class="page-title-action" onclick="return confirm(\'' . esc_js( __( 'Are you sure you want to clear all logs?', 'tinypress' ) ) . '\');">' . esc_html__( 'Clear Logs', 'tinypress' ) . '</a></h2>';

			echo '<form method="post">';
			echo '<input type="hidden" name="post_type" value="tinypress_link" />';
			echo '<input type="hidden" name="page" value="tinypress-logs" />';
			wp_nonce_field( 'tinypress_logs_nonce', 'tinypress_logs_nonce' );
			$table_logs->prepare_items();
			$table_logs->display();
			echo '</form>';

			echo '</div>';
		}


		/**
		 * Check if a user has access for a given role setting key.
		 *
		 * @param string  $setting_key The option key for the role setting
		 * @param WP_User|null $user   User to check. Defaults to current user.
		 * @return bool
		 */
		public static function user_has_role_access( $setting_key, $user = null ) {
			if ( ! $user ) {
				$user = wp_get_current_user();
			}

			if ( ! $user || ! $user->exists() ) {
				return false;
			}

			// Admins always have access
			if ( in_array( 'administrator', (array) $user->roles, true ) ) {
				return true;
			}

			$allowed_roles = Utils::get_option( $setting_key, array() );

			// If no setting saved yet (empty string or empty array from default), allow all
			if ( empty( $allowed_roles ) || ! is_array( $allowed_roles ) ) {
				return true;
			}

			$user_roles = (array) $user->roles;

			return ! empty( array_intersect( $user_roles, $allowed_roles ) );
		}

		/**
		 * Check if the current user can view shortlinks.
		 *
		 * @return bool
		 */
		public static function current_user_can_view() {
			return self::user_has_role_access( 'tinypress_role_view' );
		}

		/**
		 * Check if the current user can create/edit shortlinks.
		 *
		 * @return bool
		 */
		public static function current_user_can_create() {
			if ( ! defined( 'PUBLISHPRESS_SHORTLINKS_PRO_VERSION' ) ) {
				return true;
			}
			return self::user_has_role_access( 'tinypress_role_create' );
		}

		/**
		 * Check if the current user can control settings.
		 *
		 * @return bool
		 */
		public static function current_user_can_settings() {
			if ( ! defined( 'PUBLISHPRESS_SHORTLINKS_PRO_VERSION' ) ) {
				return true;
			}
			return self::user_has_role_access( 'tinypress_role_edit' );
		}

		function reorder_submenu() {
			global $submenu;

			$parent = 'edit.php?post_type=tinypress_link';

			if ( empty( $submenu[ $parent ] ) ) {
				return;
			}

			$settings_item = null;
			$settings_key  = null;

			foreach ( $submenu[ $parent ] as $key => $item ) {
				if ( isset( $item[2] ) && $item[2] === 'settings' ) {
					$settings_item = $item;
					$settings_key  = $key;
					break;
				}
			}

			if ( $settings_item ) {
				unset( $submenu[ $parent ][ $settings_key ] );
				$submenu[ $parent ][] = $settings_item;
			}
		}

		/**
		 * Enforce "Who Can View Shortlinks" role restriction.
		 * Removes the Shortlinks menu for users whose role is not in the allowed list.
		 */
		function enforce_role_view_access() {
			if ( ! self::current_user_can_view() ) {
				remove_menu_page( 'edit.php?post_type=tinypress_link' );
			}
		}

		/**
		 * Filter user capabilities for tinypress_link post type based on view role setting.
		 * Since the post type uses capability_type 'post', we filter edit_posts etc.
		 * only on tinypress_link screens.
		 *
		 * @param array   $allcaps All capabilities of the user
		 * @param array   $caps    Required capabilities
		 * @param array   $args    Arguments
		 * @param WP_User $user    The user object
		 * @return array
		 */
		function filter_view_capabilities( $allcaps, $caps, $args, $user ) {
			// Prevent recursion if Utils::get_option triggers current_user_can
			if ( self::$_filtering_caps ) {
				return $allcaps;
			}

			if ( ! is_admin() || ! $user || ! $user->exists() ) {
				return $allcaps;
			}

			// Only act on tinypress_link screens
			if ( ! $this->is_tinypress_screen() ) {
				return $allcaps;
			}

			// Admins always have access
			if ( in_array( 'administrator', (array) $user->roles, true ) ) {
				return $allcaps;
			}

			self::$_filtering_caps = true;

			// Check view access
			if ( ! self::user_has_role_access( 'tinypress_role_view', $user ) ) {
				$allcaps['edit_posts']    = false;
				$allcaps['publish_posts'] = false;
				$allcaps['delete_posts']  = false;
				$allcaps['read']          = false;
			}

			self::$_filtering_caps = false;

			return $allcaps;
		}

		/**
		 * Block direct URL access to tinypress_link screens for restricted users.
		 */
		function block_direct_access() {
			if ( ! $this->is_tinypress_screen() ) {
				return;
			}

			$user = wp_get_current_user();
			if ( ! $user || ! $user->exists() ) {
				return;
			}

			if ( in_array( 'administrator', (array) $user->roles, true ) ) {
				return;
			}

			if ( ! self::current_user_can_view() ) {
				wp_die(
					esc_html__( 'Sorry, you are not allowed to access shortlinks.', 'tinypress' ),
					esc_html__( 'Access Denied', 'tinypress' ),
					array( 'response' => 403 )
				);
			}
		}

		/**
		 * Check if the current admin page is a tinypress_link screen.
		 *
		 * @return bool
		 */
		private function is_tinypress_screen() {
			global $pagenow, $typenow;

			// Check query params for post_type
			$post_type = '';
			if ( ! empty( $_GET['post_type'] ) ) {
				$post_type = sanitize_text_field( wp_unslash( $_GET['post_type'] ) );
			} elseif ( ! empty( $_GET['post'] ) ) {
				$post_type = get_post_type( absint( $_GET['post'] ) );
			} elseif ( ! empty( $typenow ) ) {
				$post_type = $typenow;
			}

			if ( $post_type === 'tinypress_link' ) {
				return true;
			}

			// Check for settings/logs pages
			if ( ! empty( $_GET['page'] ) ) {
				$page = sanitize_text_field( wp_unslash( $_GET['page'] ) );
				if ( in_array( $page, array( 'settings', 'tinypress-logs' ), true ) ) {
					// Only if we're under the tinypress parent
					if ( isset( $_GET['post_type'] ) && $_GET['post_type'] === 'tinypress_link' ) {
						return true;
					}
				}
			}

			return false;
		}

		/**
		 * @return TINYPRESS_Hooks
		 */
		public static function instance() {

			if ( is_null( self::$_instance ) ) {
				self::$_instance = new self();
			}

			return self::$_instance;
		}
	}
}