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


		/**
		 * TINYPRESS_Hooks constructor.
		 */
		function __construct() {

			add_action( 'init', array( $this, 'register_everything' ) );
			add_action( 'admin_menu', array( $this, 'links_log' ) );
			add_filter( 'post_updated_messages', array( $this, 'change_url_update_message' ) );


			add_action( 'admin_bar_menu', array( $this, 'add_admin_bar_menu' ), 999 );
			add_action( 'admin_footer', array( $this, 'render_admin_modal' ) );
			add_action( 'wp_ajax_tinypress_popup_create_url', array( $this, 'tinypress_popup_create_url' ) );
		}

		public function tinypress_popup_create_url() {
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
					'message'  => esc_html__( 'Tinyurl created successfully.', 'tinypress' )
				)
			);
		}

		public function render_admin_modal() {

			include_once TINYPRESS_PLUGIN_DIR . 'templates/admin-modal-new-link.php';
		}

		public function add_admin_bar_menu( WP_Admin_Bar $admin_bar ) {

			$hide_modal_opener = (bool) Utils::get_option( 'tinypress_hide_modal_opener' );

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

			$post_messages = Utils::get_args_option( 'post', $messages );
			$post_messages = array_map( function ( $message ) {
				return str_replace( 'Post', 'Shortlinks', $message );
			}, $post_messages );

			$messages['post'] = $post_messages;

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
				esc_html__( 'Logs', 'tinypress' ), esc_html__( 'Logs', 'tinypress' ), 'manage_options', 'tinypress-logs',
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

			echo '<div class="wrap">';
			echo '<h2 class="report-table">' . esc_html__( 'All Logs', 'tinypress' ) . '</h2>';

			$table_logs->prepare_items();
			$table_logs->display();

			echo '</div>';
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